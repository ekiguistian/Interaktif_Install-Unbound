#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME='Unbound DNS Web Manager'
APP_DIR='/opt/unbound-web'
ENV_DIR='/etc/unbound-web'
ENV_FILE="$ENV_DIR/.env"
WEB_USER='www-data'
MYSQL_DB='unbound_web'
MYSQL_USER='unbound_web'
MYSQL_PASSWORD=''
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

red=$'\033[31m'; green=$'\033[32m'; blue=$'\033[34m'; reset=$'\033[0m'
if [[ ! -t 1 || "${TERM:-}" == dumb || -n "${NO_COLOR:-}" ]]; then red=''; green=''; blue=''; reset=''; fi
info() { printf '%s[INFO]%s %s\n' "$green" "$reset" "$*"; }
step() { printf '\n%s[PROSES]%s %s\n' "$blue" "$reset" "$*"; }
die() { printf '%s[ERROR]%s %s\n' "$red" "$reset" "$*" >&2; exit 1; }
on_error() { local code=$? line=${BASH_LINENO[0]:-unknown}; die "Proses gagal pada baris $line (exit $code). Periksa pesan di atas."; }
trap on_error ERR

if [[ -t 1 && "${TERM:-}" != dumb ]] && command -v clear >/dev/null 2>&1; then clear || true; fi
printf '%s============================================================%s\n' "$blue" "$reset"
printf '%s%s%s\n' "$blue" "$APP_NAME" "$reset"
printf '%s============================================================%s\n' "$blue" "$reset"

[[ $EUID -eq 0 ]] || die 'Jalankan sebagai root: sudo bash ./install.sh'
[[ -f "$SCRIPT_DIR/public/index.php" && -f "$SCRIPT_DIR/app/bootstrap.php" ]] || die 'Jalankan installer dari folder repository lengkap.'
[[ -r /etc/os-release ]] || die '/etc/os-release tidak ditemukan.'
# shellcheck disable=SC1091
. /etc/os-release
OS_ID="${ID:-}"
OS_CODENAME="${VERSION_CODENAME:-}"
info "Sistem operasi: ${PRETTY_NAME:-$OS_ID} | Kernel: $(uname -r) | Arsitektur: $(uname -m)"
[[ "$OS_ID" == ubuntu && "${VERSION_ID:-}" == 22.04 && "$OS_CODENAME" == jammy ]] || die "OS tidak didukung: ${PRETTY_NAME:-$OS_ID}. Aplikasi ini hanya mendukung Ubuntu Server 22.04 LTS (Jammy)."
[[ "$(dpkg --print-architecture)" == amd64 || "$(dpkg --print-architecture)" == arm64 ]] || die 'Arsitektur yang didukung: amd64 dan arm64.'
command -v apt-get >/dev/null 2>&1 || die 'APT tidak tersedia.'
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

step 'Memperbarui indeks APT dan memasang alat dasar.'
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl gnupg lsb-release sudo nginx openssl dnsperf dnsutils ufw cron logrotate software-properties-common git

if [[ "$OS_ID" == ubuntu ]] && ! apt-cache show dnsperf >/dev/null 2>&1; then
    apt-get install -y --no-install-recommends software-properties-common
    add-apt-repository --yes universe
    apt-get update
fi
apt-cache show dnsperf >/dev/null 2>&1 || die 'Paket dnsperf tidak tersedia pada repository aktif.'

if ! grep -Rqs 'ondrej/php' /etc/apt/sources.list.d /etc/apt/sources.list 2>/dev/null; then
    step 'Menambahkan PPA ondrej/php untuk PHP 8.4 pada Ubuntu 22.04.'
    add-apt-repository --yes ppa:ondrej/php
    apt-get update
fi
apt-cache show php8.4-fpm >/dev/null 2>&1 || die 'Paket PHP 8.4 tidak tersedia dari repository yang aktif.'

if [[ "$MYSQL_SERVER_PRESENT" != yes ]]; then
    step 'Menyiapkan repository MySQL Community 8.4 LTS.'
    mysql_key_tmp="$(mktemp /tmp/mysql-keyring.XXXXXX.gpg)"
    curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 | gpg --dearmor --yes -o "$mysql_key_tmp"
    install -o root -g root -m 0644 "$mysql_key_tmp" /usr/share/keyrings/mysql-apt.gpg
    rm -f -- "$mysql_key_tmp"
    printf 'deb [signed-by=/usr/share/keyrings/mysql-apt.gpg] https://repo.mysql.com/apt/%s/ %s mysql-8.4-lts\n' \
        "$OS_ID" "$OS_CODENAME" > /etc/apt/sources.list.d/mysql.list
    apt-get update
    apt-cache show mysql-server >/dev/null 2>&1 || die 'Paket MySQL Server tidak ditemukan pada repository resmi.'
else
    info 'MySQL Server sudah ditemukan. Repository dan database yang ada tidak diganti.'
fi

step 'Memasang PHP 8.4, Nginx, MySQL Server, dnsperf, Git, dan build dependencies Unbound.'
packages=(php8.4-fpm php8.4-mysql php8.4-cli php8.4-curl php8.4-mbstring php8.4-xml dnsperf dnsutils nginx ufw sudo cron git)
packages+=(logrotate)
packages+=(build-essential libssl-dev libexpat1-dev libevent-dev bison flex wget)
[[ "$MYSQL_SERVER_PRESENT" == yes ]] || packages+=(mysql-server)
apt-get install -y --no-install-recommends "${packages[@]}"
php8.4 -r 'exit(PHP_VERSION_ID >= 80400 && extension_loaded("pdo_mysql") && extension_loaded("curl") && extension_loaded("mbstring") && extension_loaded("xml") ? 0 : 1);' || die 'PHP 8.4 atau extension wajib belum siap.'
command -v dnsperf >/dev/null 2>&1 || die 'dnsperf belum tersedia setelah pemasangan.'
ufw allow 8000/tcp comment 'Unbound Web Manager'

step 'Menyiapkan direktori aplikasi dan database.'
install -d -o root -g root -m 0755 "$APP_DIR" "$APP_DIR/app" "$APP_DIR/public" "$APP_DIR/bin" "$APP_DIR/database"
install -d -o root -g "$WEB_USER" -m 0750 "$ENV_DIR"
install -d -o "$WEB_USER" -g "$WEB_USER" -m 0750 /var/lib/unbound-web
install -d -o root -g "$WEB_USER" -m 0750 /var/log/unbound-web
if [[ ! -e /var/log/unbound-web/worker.log ]]; then install -o "$WEB_USER" -g "$WEB_USER" -m 0640 /dev/null /var/log/unbound-web/worker.log; fi
cp -a "$SCRIPT_DIR/app/." "$APP_DIR/app/"
cp -a "$SCRIPT_DIR/public/." "$APP_DIR/public/"
cp -a "$SCRIPT_DIR/bin/." "$APP_DIR/bin/"
cp -a "$SCRIPT_DIR/database/." "$APP_DIR/database/"
install -o root -g root -m 0644 "$SCRIPT_DIR/.env.example" "$APP_DIR/.env.example"
chown -R root:root "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0755 {} +
find "$APP_DIR" -type f -exec chmod 0644 {} +
install -o root -g root -m 0750 "$SCRIPT_DIR/scripts/manage.sh" /usr/local/sbin/unbound-web-manage

systemctl enable --now mysql
if [[ -r "$ENV_FILE" ]]; then
    MYSQL_PASSWORD="$(sed -n 's/^DB_PASS=//p' "$ENV_FILE" | head -n 1 | tr -d '\r\n\"')"
    [[ "$MYSQL_PASSWORD" =~ ^[A-Fa-f0-9]{32,128}$ ]] || die "DB_PASS yang sudah ada di $ENV_FILE bukan kata sandi hex yang dibuat installer. Database tidak diubah."
else
    MYSQL_PASSWORD="$(openssl rand -hex 32)"
fi
mysql --protocol=socket --user=root <<SQL
CREATE DATABASE IF NOT EXISTS \`$MYSQL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '$MYSQL_USER'@'127.0.0.1' IDENTIFIED BY '$MYSQL_PASSWORD';
ALTER USER '$MYSQL_USER'@'127.0.0.1' IDENTIFIED BY '$MYSQL_PASSWORD';
GRANT ALL PRIVILEGES ON \`$MYSQL_DB\`.* TO '$MYSQL_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
mysql --protocol=socket --user=root "$MYSQL_DB" < "$APP_DIR/database/schema.sql"
while IFS='|' read -r column definition; do
    [[ -n "$column" ]] || continue
    exists="$(mysql --protocol=socket --user=root --batch --skip-column-names "$MYSQL_DB" -e \
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$MYSQL_DB' AND TABLE_NAME='metrics' AND COLUMN_NAME='$column'")"
    if [[ "$exists" == 0 ]]; then
        mysql --protocol=socket --user=root "$MYSQL_DB" -e "ALTER TABLE metrics ADD COLUMN $column $definition"
    fi
done <<'METRIC_COLUMNS'
query_types_json|LONGTEXT NULL
response_codes_json|LONGTEXT NULL
recursion_avg_ms|DECIMAL(14,4) NOT NULL DEFAULT 0
cpu_percent|DECIMAL(7,3) NOT NULL DEFAULT 0
load_1m|DECIMAL(9,3) NOT NULL DEFAULT 0
load_5m|DECIMAL(9,3) NOT NULL DEFAULT 0
load_15m|DECIMAL(9,3) NOT NULL DEFAULT 0
memory_used_mb|BIGINT UNSIGNED NOT NULL DEFAULT 0
memory_total_mb|BIGINT UNSIGNED NOT NULL DEFAULT 0
disk_used_gb|DECIMAL(12,3) NOT NULL DEFAULT 0
disk_total_gb|DECIMAL(12,3) NOT NULL DEFAULT 0
uptime_seconds|BIGINT UNSIGNED NOT NULL DEFAULT 0
METRIC_COLUMNS

if [[ -r "$SCRIPT_DIR/src/dnsperf-domains.txt" ]]; then
    seed_sql="$(mktemp /tmp/unbound-web-seed.XXXXXX.sql)"
    while read -r domain query_type; do
        [[ "$domain" =~ ^([A-Za-z0-9-]+\.)+[A-Za-z]{2,63}$ ]] || continue
        [[ "$query_type" =~ ^(A|AAAA|MX|TXT|NS|CNAME)$ ]] || continue
        printf "INSERT IGNORE INTO test_domains (domain, query_type) VALUES ('%s','%s');\n" "${domain,,}" "$query_type" >> "$seed_sql"
    done < "$SCRIPT_DIR/src/dnsperf-domains.txt"
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

systemctl enable --now php8.4-fpm nginx
systemctl enable --now cron

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
systemctl reload nginx

step 'Menjalankan pemeriksaan akhir.'
php-fpm8.4 -t
systemctl is-active --quiet mysql
systemctl is-active --quiet nginx
systemctl is-active --quiet php8.4-fpm
systemctl is-active --quiet cron

SERVER_IP="$(hostname -I | awk '{for (i=1;i<=NF;i++) if ($i !~ /^127\./ && $i !~ /^fe80:/) {print $i; exit}}')"
[[ -n "$SERVER_IP" ]] || SERVER_IP='<IP-SERVER>'

printf '\n%s============================================================%s\n' "$green" "$reset"
printf '%s %s%s\n' "$APP_NAME" ''
printf '%s============================================================%s\n' "$green" "$reset"
printf 'Direktori aplikasi : %s\n' "$APP_DIR"
printf 'Document root      : %s/public\n' "$APP_DIR"
printf 'File .env          : %s\n' "$ENV_FILE"
printf 'URL aplikasi       : http://%s:8000\n' "$SERVER_IP"
printf '\nSalin template dan atur permission file konfigurasi:\n'
printf '  install -d -o root -g www-data -m 0750 %s\n' "$ENV_DIR"
printf '  cp %s/.env.example %s\n' "$APP_DIR" "$ENV_FILE"
printf '  nano %s\n' "$ENV_FILE"
printf '  chown root:www-data %s && chmod 0640 %s\n' "$ENV_FILE" "$ENV_FILE"
printf '\nIsi %s/.env.example:\n' "$APP_DIR"
cat "$APP_DIR/.env.example"
printf '\nNilai DB_PASS untuk diisi pada file .env:\nDB_PASS=%s\n' "$MYSQL_PASSWORD"
printf 'Isi ADMIN_USER, ADMIN_PASS dengan password panjang, serta NET_INTERFACE dengan interface server.\n'
printf 'Setelah .env disimpan, buka URL aplikasi dan login menggunakan kredensial admin tersebut.\n'
printf 'Unbound dan Zabbix Agent belum dipasang. Keduanya dikelola dari aplikasi web.\n'
