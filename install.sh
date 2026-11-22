#!/usr/bin/env bash
set +x
set -Eeuo pipefail

APP_NAME='Unbound DNS Web Manager'
ENV_DIR='/etc/unbound-web'
ENV_FILE="$ENV_DIR/.env"
WEB_USER='www-data'
MYSQL_DB='unbound_web'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

red=$'\033[31m'; green=$'\033[32m'; blue=$'\033[34m'; reset=$'\033[0m'
if [[ ! -t 1 || "${TERM:-}" == dumb || -n "${NO_COLOR:-}" ]]; then red=''; green=''; blue=''; reset=''; fi
info() { printf '%s[INFO]%s %s\n' "$green" "$reset" "$*"; }
step() { printf '\n%s[PROSES]%s %s\n' "$blue" "$reset" "$*"; }
die() { printf '%s[ERROR]%s %s\n' "$red" "$reset" "$*" >&2; exit 1; }
on_error() { local code=$? line=${BASH_LINENO[0]:-unknown}; die "Proses gagal pada baris $line (exit $code). Periksa pesan di atas."; }
trap on_error ERR

# Older deployed web managers call this path. Delegate without running setup.
if (($# == 1)) && [[ "$1" == --web-update ]]; then
    [[ $EUID -eq 0 && "$SCRIPT_DIR" == /var/lib/unbound-web-updates/checkout ]] ||
        die 'Gunakan update.sh untuk memperbarui Web Manager.'
    exec /usr/bin/bash "$SCRIPT_DIR/update.sh"
fi
(($# == 0)) || die 'Penggunaan: bash ./install.sh'

if [[ -t 1 && "${TERM:-}" != dumb ]] && command -v clear >/dev/null 2>&1; then clear || true; fi
printf '%s============================================================%s\n' "$blue" "$reset"
printf '%s%s%s\n' "$blue" "$APP_NAME" "$reset"
printf '%s============================================================%s\n' "$blue" "$reset"

[[ $EUID -eq 0 ]] || die 'Jalankan sebagai root: sudo bash ./install.sh'
[[ -f "$SCRIPT_DIR/public/index.php" && -f "$SCRIPT_DIR/app/bootstrap.php" ]] || die 'Jalankan installer dari folder repository lengkap.'
# shellcheck source=scripts/web-deploy.bash
source "$SCRIPT_DIR/scripts/web-deploy.bash"
[[ -r /etc/os-release ]] || die '/etc/os-release tidak ditemukan.'
# shellcheck disable=SC1091
. /etc/os-release
OS_ID="${ID:-}"
OS_CODENAME="${VERSION_CODENAME:-}"
info "Sistem operasi: ${PRETTY_NAME:-$OS_ID} | Kernel: $(uname -r) | Arsitektur: $(uname -m)"
[[ "$OS_ID" == ubuntu && "${VERSION_ID:-}" == 22.04 && "$OS_CODENAME" == jammy ]] || die "OS tidak didukung: ${PRETTY_NAME:-$OS_ID}. Aplikasi ini hanya mendukung Ubuntu Server 22.04 LTS (Jammy)."
[[ "$(dpkg --print-architecture)" == amd64 || "$(dpkg --print-architecture)" == arm64 ]] || die 'Arsitektur yang didukung: amd64 dan arm64.'
command -v apt-get >/dev/null 2>&1 || die 'APT tidak tersedia.'
[[ -d /run/systemd/system ]] || die 'Jalankan pada Ubuntu dengan systemd aktif.'
CPU_CORES="$(getconf _NPROCESSORS_ONLN 2>/dev/null || printf '0')"
RAM_MB="$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo 2>/dev/null || printf '0')"
VAR_FREE_MB="$(df -Pm /var 2>/dev/null | awk 'NR==2 {print $4}')"
[[ "$CPU_CORES" =~ ^[0-9]+$ && "$RAM_MB" =~ ^[0-9]+$ && "$VAR_FREE_MB" =~ ^[0-9]+$ ]] || die 'Resource CPU, RAM, atau ruang kosong /var tidak dapat dibaca.'
info "Resource host: $CPU_CORES CPU, $RAM_MB MB RAM, $VAR_FREE_MB MB kosong di /var."
((CPU_CORES >= 1 && RAM_MB >= 2048 && VAR_FREE_MB >= 4096)) || die 'Minimum Web Manager: 1 CPU, RAM 2 GB, dan ruang kosong 4 GB di /var.'

mysql_version=''
if command -v mysql >/dev/null 2>&1; then mysql_version="$(mysql --version 2>/dev/null || true)"; fi
if systemctl is-active --quiet mariadb 2>/dev/null || [[ "$mysql_version" == *MariaDB* ]]; then
    die 'MariaDB terdeteksi. Installer ini membutuhkan MySQL Community dan tidak mengganti database MariaDB yang ada.'
fi
mysql_server_state="$(dpkg-query -W -f='${db:Status-Status}' mysql-server 2>/dev/null || true)"
mysql_community_state="$(dpkg-query -W -f='${db:Status-Status}' mysql-community-server 2>/dev/null || true)"
mariadb_server_state="$(dpkg-query -W -f='${db:Status-Status}' mariadb-server 2>/dev/null || true)"
[[ "$mariadb_server_state" != installed ]] || die 'MariaDB terpasang. Installer tidak akan mengganti atau mengubah database tersebut.'
MYSQL_SERVER_PRESENT='no'
if [[ "$mysql_server_state" == installed || "$mysql_community_state" == installed ]] || systemctl is-active --quiet mysql 2>/dev/null; then
    MYSQL_SERVER_PRESENT='yes'
fi

exec 9>/run/lock/unbound-web-install.lock
flock -n 9 || die 'Installer lain sedang berjalan.'

prepare_legacy_mysql_source() {
    local source_file='/etc/apt/sources.list.d/mysql.list'
    local keyring='/usr/share/keyrings/mysql-apt.gpg'
    local legacy_entry="deb [signed-by=$keyring] https://repo.mysql.com/apt/ubuntu/ jammy mysql-8.4-lts"
    local community_states source_entries

    # The former installer wrote exactly this line. Leave other APT sources alone.
    [[ -f "$source_file" && ! -L "$source_file" ]] || return 0
    source_entries="$(awk '{sub(/[[:space:]]*#.*/, ""); if (NF) {$1=$1; print}}' "$source_file")"
    [[ "$source_entries" == "$legacy_entry" ]] || return 0
    community_states="$(dpkg-query -W -f='${db:Status-Status}\n' 'mysql-community-*' 2>/dev/null || true)"
    if ! grep -qxE 'installed|unpacked|half-installed|half-configured|triggers-awaited|triggers-pending' <<< "$community_states"; then
        mv -T --backup=numbered -- "$source_file" "$source_file.unbound-web.disabled"
        info 'Repository MySQL lama dicadangkan. Instalasi baru memakai paket MySQL Ubuntu.'
        return
    fi

    step 'Memeriksa kunci repository MySQL Community yang masih digunakan.'
    command -v curl >/dev/null && command -v gpg >/dev/null && command -v gpgv >/dev/null ||
        die 'Pembaruan keyring MySQL Community membutuhkan curl, gpg, dan gpgv. Alat dari instalasi lama tidak lengkap.'
    (
        local workdir fingerprint expires pending_key=''
        workdir="$(mktemp -d)"
        trap 'if [[ -n "$pending_key" ]]; then rm -f -- "$pending_key"; fi; rm -rf -- "$workdir"' EXIT
        curl -fsSL --proto '=https' --proto-redir '=https' --max-time 60 \
            https://repo.mysql.com/apt/ubuntu/dists/jammy/InRelease -o "$workdir/InRelease"

        if [[ -r "$keyring" ]] &&
            gpgv --status-fd 1 --keyring "$keyring" "$workdir/InRelease" 2>/dev/null |
            awk '/^\[GNUPG:\] (EXPKEYSIG|EXPSIG|REVKEYSIG|BADSIG) / {bad=1}
                /^\[GNUPG:\] VALIDSIG / {valid=1} END {exit !(valid && !bad)}'; then
            exit 0
        fi

        curl -fsSL --proto '=https' --proto-redir '=https' --max-time 60 \
            https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 -o "$workdir/mysql.asc"
        fingerprint="$(gpg --homedir "$workdir" --batch --with-colons --show-keys "$workdir/mysql.asc" |
            awk -F: '$1=="pub" {primary=1} $1=="fpr" && primary {print $10; primary=0}')"
        [[ "$fingerprint" == BCA43417C3B485DD128EC6D4B7B3B788A8D3785C ]] ||
            die 'Fingerprint kunci MySQL tidak cocok dengan kunci rilis Oracle.'
        expires="$(gpg --homedir "$workdir" --batch --with-colons --show-keys "$workdir/mysql.asc" |
            awk -F: '$1=="pub" {print $7}')"
        [[ "$expires" =~ ^[0-9]+$ ]] && ((expires > $(date +%s))) || die 'Kunci rilis MySQL sudah kedaluwarsa.'
        gpg --homedir "$workdir" --batch --dearmor -o "$workdir/mysql.gpg" "$workdir/mysql.asc"
        gpgv --keyring "$workdir/mysql.gpg" "$workdir/InRelease" ||
            die 'Tanda tangan repository MySQL gagal diverifikasi.'
        pending_key="$(mktemp "${keyring}.XXXXXX")"
        install -o root -g root -m 0644 "$workdir/mysql.gpg" "$pending_key"
        mv -T -- "$pending_key" "$keyring"
        info 'Keyring MySQL Community diperbarui. Paket dan database yang ada dipertahankan.'
    )
}

use_ufw=no
prepare_legacy_mysql_source

step 'Memperbarui indeks APT dan memasang alat dasar.'
export DEBIAN_FRONTEND=noninteractive
apt-get update --allow-releaseinfo-change-label
apt-get install -y --no-install-recommends ca-certificates curl iproute2 openssl software-properties-common

if ! apt-cache show dnsperf >/dev/null 2>&1; then
    add-apt-repository --yes universe
    apt-get update
fi
apt-cache show dnsperf >/dev/null 2>&1 || die 'Paket dnsperf tidak tersedia pada repository aktif.'

if ! grep -qsE '^[[:space:]]*(deb[[:space:]]|URIs:).*https://packages\.sury\.org/php/?([[:space:]]|$)' \
    /etc/apt/sources.list /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; then
    step 'Menambahkan repository PHP Surý untuk Ubuntu 22.04.'
    keyring_dir="$(mktemp -d)"
    if ! curl -fsSL --proto '=https' --proto-redir '=https' --max-time 60 \
        https://packages.sury.org/debsuryorg-archive-keyring.deb -o "$keyring_dir/debsuryorg-archive-keyring.deb" ||
        ! dpkg -i "$keyring_dir/debsuryorg-archive-keyring.deb"; then
        rm -f -- "$keyring_dir/debsuryorg-archive-keyring.deb"
        rmdir -- "$keyring_dir"
        die 'Keyring repository PHP gagal dipasang.'
    fi
    rm -f -- "$keyring_dir/debsuryorg-archive-keyring.deb"
    rmdir -- "$keyring_dir"
    printf 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ %s main\n' \
        "$OS_CODENAME" > /etc/apt/sources.list.d/unbound-web-php.list
    apt-get update
fi
apt-cache show php8.4-fpm >/dev/null 2>&1 || die 'Paket PHP 8.4 tidak tersedia dari repository yang aktif.'

step 'Memasang PHP 8.4, Nginx, MySQL Server, dnsperf, Git, dan build dependencies Unbound.'
packages=(php8.4-fpm php8.4-mysql php8.4-cli php8.4-curl php8.4-mbstring php8.4-xml dnsperf dnsutils nginx ufw sudo cron git)
packages+=(logrotate)
packages+=(build-essential pkg-config libssl-dev libexpat1-dev libevent-dev bison flex wget)
[[ "$MYSQL_SERVER_PRESENT" == yes ]] || packages+=(mysql-server)
apt-get install -y --no-install-recommends "${packages[@]}"
if systemd-detect-virt --quiet --container && ! iptables -t filter -S >/dev/null 2>&1; then
    info 'Container tidak memiliki akses netfilter. Gunakan firewall Proxmox atau host untuk port 8000.'
else
    ufw allow 8000/tcp comment 'Unbound Web Manager'
    use_ufw=yes
fi
php8.4 -r 'exit(PHP_VERSION_ID >= 80400 && extension_loaded("pdo_mysql") && extension_loaded("curl") && extension_loaded("mbstring") && extension_loaded("xml") ? 0 : 1);' || die 'PHP 8.4 atau extension wajib belum siap.'
command -v dnsperf >/dev/null 2>&1 || die 'dnsperf belum tersedia setelah pemasangan.'

step 'Menyiapkan source dan rilis Web Manager dari GitHub.'
web_pull_main
web_stage_release

install -d -o root -g "$WEB_USER" -m 0750 "$ENV_DIR"
install -d -o "$WEB_USER" -g "$WEB_USER" -m 0750 /var/lib/unbound-web
install -d -o root -g "$WEB_USER" -m 0750 /var/log/unbound-web
if [[ ! -e /var/log/unbound-web/worker.log ]]; then install -o "$WEB_USER" -g "$WEB_USER" -m 0640 /dev/null /var/log/unbound-web/worker.log; fi

systemctl enable --now mysql
route_address() {
    ip "-$1" route get "$2" 2>/dev/null | awk 'NR==1 {
        for (i=1; i<NF; i++) {
            if ($i=="dev") device=$(i+1)
            if ($i=="src") address=$(i+1)
        }
        if (device!="" && address!="") print device, address
    }'
}
network="$(route_address 4 1.1.1.1)" || network=''
if [[ -z "$network" ]]; then network="$(route_address 6 2606:4700:4700::1111)" || network=''; fi
if [[ -z "$network" ]]; then
    network="$(ip -o addr show scope global up | awk 'NR==1 {sub(/@.*/,"",$2); sub(/\/.*/,"",$4); print $2, $4}')"
fi
[[ -n "$network" ]] || die 'Alamat IP jaringan server tidak ditemukan.'
read -r NET_INTERFACE SERVER_IP <<< "$network"
if [[ "$SERVER_IP" == *:* ]]; then SERVER_IP="[$SERVER_IP]"; fi

if [[ ! -e "$ENV_FILE" ]]; then
    MYSQL_USER="unbound_web_$(openssl rand -hex 4)"
    MYSQL_PASSWORD="$(openssl rand -hex 10)"
    ADMIN_PASSWORD="$(openssl rand -hex 24)"
    env_tmp="$(mktemp "$ENV_DIR/.env.XXXXXX")"
    (
        trap 'rm -f -- "$env_tmp"' EXIT
        printf '%s\n' 'DB_HOST=127.0.0.1' "DB_NAME=$MYSQL_DB" "DB_USER=$MYSQL_USER" \
            "DB_PASS=$MYSQL_PASSWORD" 'ADMIN_USER=admin' "ADMIN_PASS=$ADMIN_PASSWORD" "NET_INTERFACE=$NET_INTERFACE" > "$env_tmp"
        chown root:"$WEB_USER" "$env_tmp"
        chmod 0640 "$env_tmp"
        mv -T -- "$env_tmp" "$ENV_FILE"
    )
fi
[[ -f "$ENV_FILE" && ! -L "$ENV_FILE" ]] || die "File konfigurasi $ENV_FILE tidak valid."
chown root:"$WEB_USER" "$ENV_FILE"
chmod 0640 "$ENV_FILE"
config="$(php8.4 -r '
    require $argv[1];
    foreach (["DB_HOST", "DB_NAME", "DB_USER", "DB_PASS", "ADMIN_USER", "ADMIN_PASS"] as $key) {
        $value = app_env($key);
        if ($value === "" || strpbrk($value, "\r\n\0") !== false || str_starts_with($value, "replace_with_")) {
            fwrite(STDERR, "Nilai $key pada .env tidak valid.\n");
            exit(1);
        }
        echo $value, PHP_EOL;
    }
' -- "$WEB_TXN/release/app/bootstrap.php")" || die 'Kredensial aplikasi tidak dapat dibaca.'
mapfile -t settings <<< "$config"
(("${#settings[@]}" == 6)) || die 'Kredensial aplikasi tidak lengkap.'
MYSQL_HOST="${settings[0]}"
MYSQL_DB="${settings[1]}"
MYSQL_USER="${settings[2]}"
MYSQL_PASSWORD="${settings[3]}"
ADMIN_USER="${settings[4]}"
ADMIN_PASSWORD="${settings[5]}"
[[ "$MYSQL_DB" =~ ^[A-Za-z0-9_]{1,64}$ && "$MYSQL_USER" =~ ^[A-Za-z0-9_]{1,32}$ ]] || die 'Nama database atau pengguna MySQL tidak valid.'
[[ "$MYSQL_HOST" == 127.0.0.1 || "$MYSQL_HOST" == localhost ]] || die 'Installer membutuhkan MySQL lokal.'
[[ "$MYSQL_USER" != root ]] || die 'Gunakan akun MySQL khusus aplikasi.'
case "${MYSQL_DB,,}" in mysql|sys|information_schema|performance_schema) die 'Gunakan database khusus aplikasi.' ;; esac
# NO_BACKSLASH_ESCAPES plus doubled quotes preserves an existing password safely.
sql_password="${MYSQL_PASSWORD//\'/\'\'}"
mysql --protocol=socket --user=root <<SQL
SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES';
CREATE DATABASE IF NOT EXISTS \`$MYSQL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '$MYSQL_USER'@'$MYSQL_HOST' IDENTIFIED BY '$sql_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$MYSQL_DB\`.* TO '$MYSQL_USER'@'$MYSQL_HOST';
SQL
source "$WEB_TXN/release/scripts/web-migrate.bash"
web_apply_schema "$WEB_TXN/release/database/schema.sql" "$MYSQL_DB"

if [[ -r "$WEB_TXN/release/src/dnsperf-domains.txt" ]]; then
    seed_sql="$(mktemp /tmp/unbound-web-seed.XXXXXX.sql)"
    while read -r domain query_type; do
        [[ "$domain" =~ ^([A-Za-z0-9-]+\.)+[A-Za-z]{2,63}$ ]] || continue
        [[ "$query_type" =~ ^(A|AAAA|MX|TXT|NS|CNAME)$ ]] || continue
        printf "INSERT IGNORE INTO test_domains (domain, query_type) VALUES ('%s','%s');\n" "${domain,,}" "$query_type" >> "$seed_sql"
    done < "$WEB_TXN/release/src/dnsperf-domains.txt"
    mysql --protocol=socket --user=root "$MYSQL_DB" < "$seed_sql"
    rm -f -- "$seed_sql"
fi

step 'Mengatur sudo terbatas, cron worker, dan PHP-FPM.'
sudoers_tmp="$(mktemp /tmp/unbound-web-sudoers.XXXXXX)"
cat > "$sudoers_tmp" <<'SUDOERS'
Defaults:www-data !requiretty
www-data ALL=(root) NOPASSWD: /usr/local/sbin/unbound-web-manage *
SUDOERS
visudo -cf "$sudoers_tmp"
install -o root -g root -m 0440 "$sudoers_tmp" /etc/sudoers.d/unbound-web
rm -f -- "$sudoers_tmp"

cron_tmp="$(mktemp /tmp/unbound-web-cron.XXXXXX)"
cat > "$cron_tmp" <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www-data /usr/bin/php8.4 /opt/unbound-web/bin/worker.php >>/var/log/unbound-web/worker.log 2>&1
CRON
if [[ ! -e /etc/cron.d/unbound-web ]]; then install -o root -g root -m 0644 "$cron_tmp" /etc/cron.d/unbound-web; fi
rm -f -- "$cron_tmp"

cat > /etc/logrotate.d/unbound-web <<'LOGROTATE'
/var/log/unbound-web/*.log {
    daily
    rotate 7
    missingok
    notifempty
    compress
    copytruncate
}
LOGROTATE
chmod 0644 /etc/logrotate.d/unbound-web

step 'Membuat virtual host Nginx di port 8000.'
nginx_site='/etc/nginx/sites-available/unbound-web'
if [[ -e "$nginx_site" ]]; then cp -a "$nginx_site" "$nginx_site.bak.$(date +%Y%m%d%H%M%S)"; fi
cat > "$nginx_site" <<'NGINX'
server {
    listen 8000 default_server;
    listen [::]:8000 default_server;
    server_name _;
    root /opt/unbound-web/public;
    index index.php;
    server_tokens off;
    client_max_body_size 1m;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~* \.php$ {
        if ($request_uri ~* "^(.+)\.php(?:\?.*)?$") { return 301 $1$is_args$args; }
        if ($fastcgi_script_name != "/index.php") { return 404; }
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $document_root;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 3600s;
        fastcgi_buffering off;
    }

    location ~ /\. { deny all; }
    location ~* \.(?:sql|log|bak|old|ini|env|sh)$ { deny all; }
    location = /api/stream {
        add_header X-Accel-Buffering no;
        try_files $uri /index.php?$query_string;
    }
}
NGINX
ln -sfn "$nginx_site" /etc/nginx/sites-enabled/unbound-web
nginx -t
php-fpm8.4 -t
web_activate_release
systemctl enable --now php8.4-fpm nginx cron logrotate.timer
if [[ "$use_ufw" == yes ]]; then systemctl enable --now ufw; fi
systemctl reload nginx

step 'Menjalankan pemeriksaan akhir.'
units=(mysql php8.4-fpm nginx cron logrotate.timer)
if [[ "$use_ufw" == yes ]]; then units+=(ufw); fi
for unit in "${units[@]}"; do
    systemctl is-enabled --quiet "$unit" && systemctl is-active --quiet "$unit" ||
        die "Layanan $unit belum aktif atau belum diaktifkan saat boot."
done
web_check_deployed

printf '\nURL      : http://%s:8000\nUsername : %s\nPassword : %s\n' "$SERVER_IP" "$ADMIN_USER" "$ADMIN_PASSWORD"
