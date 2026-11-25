#!/usr/bin/env bash
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

die() { echo "$*" >&2; exit 1; }
stage='Pemeriksaan awal'
step() { stage=$1; printf '\n%s\n' "$stage"; }
trap 'code=$?; printf "Installer gagal pada tahap %s, baris %s (exit %s). Periksa pesan error di atas.\n" "$stage" "$LINENO" "$code" >&2; exit "$code"' ERR
[[ $EUID = 0 ]] || die 'Jalankan lewat sudo bash install.sh.'
source /etc/os-release
[[ ${ID:-} = ubuntu ]] || die 'Installer membutuhkan Ubuntu Server. Ubuntu 22.04 LTS didukung.'
[[ -d /run/systemd/system ]] || die 'Systemd belum aktif. Jalankan pada Ubuntu Server atau aktifkan systemd di WSL terlebih dahulu.'
src=$(cd -- "$(dirname -- "$0")" && pwd -P)
dest=/opt/www/unbound-web
conf=/etc/unbound-web
[[ $src != "$dest" ]] || die 'Jalankan installer dari checkout proyek.'
for file in app/bootstrap.php app/functions.php app/filters.php app/domains.php bin/manage.py bin/monitor.py bin/worker.php bin/seed-domains.php bin/seed-filters.php bin/record-config.php bin/release-store.php bin/update.py bin/zabbix-metrics.py database/schema.sql public/index.php src/dnsperf-domains.txt; do
    [[ -f $src/$file ]] || die "Source proyek tidak lengkap. File $file tidak ditemukan. Clone repositori sebelum menjalankan installer."
done
exec 9>/run/unbound-web-install.lock
flock -n 9 || die 'Installer lain sedang berjalan.'
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT

step "Menyiapkan repositori Ubuntu ${VERSION_ID:-}"
apt=(apt-get -o DPkg::Lock::Timeout=180 -o Acquire::Retries=3)
"${apt[@]}" update
"${apt[@]}" install -y ca-certificates gnupg software-properties-common
add-apt-repository -y -n universe
"${apt[@]}" update
php_packages=(php8.4-fpm php8.4-cli php8.4-mysql)
has_candidate() {
    local candidate
    candidate=$(LC_ALL=C apt-cache policy "$1" | awk '/Candidate:/ {print $2; exit}')
    [[ -n $candidate && $candidate != '(none)' ]]
}
for package in "${php_packages[@]}"; do
    if ! has_candidate "$package"; then
        add-apt-repository -y -n ppa:ondrej/php
        "${apt[@]}" update
        break
    fi
done
for package in "${php_packages[@]}" dnsperf; do
    has_candidate "$package" || die "Paket $package belum tersedia. Periksa repository Ubuntu ${VERSION_CODENAME:-}, universe, dan PPA ondrej/php."
done
step 'Memasang MySQL, PHP 8.4, Nginx, dan dependensi panel'
"${apt[@]}" install -y mysql-server mysql-client nginx "${php_packages[@]}" \
    openssl sudo cron curl rsync python3 dnsperf iproute2 git
php8.4 -r 'exit(PHP_VERSION_ID >= 80400 && extension_loaded("pdo_mysql") ? 0 : 1);' || die 'PHP 8.4 atau extension pdo_mysql belum siap.'

host=${WEB_HOST:-}
if [[ -z $host && -s $conf/web-host ]]; then
    host=$(cat "$conf/web-host")
fi
if [[ -z $host && -f /etc/nginx/sites-available/unbound-web ]]; then
    host=$(awk '$1 == "server_name" {gsub(/;/, "", $2); if ($2 != "_") print $2; exit}' /etc/nginx/sites-available/unbound-web)
fi
if [[ -z $host ]]; then
    host=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<NF; i++) if ($i=="src") {print $(i+1); exit}}') || host=''
fi
if [[ -z $host ]]; then
    host=$(ip -4 -o addr show scope global | awk 'NR==1 {split($4,address,"/"); print address[1]}')
fi
san=$(python3 - "$host" <<'PY'
import ipaddress, re, sys
host = sys.argv[1]
try:
    ipaddress.IPv4Address(host)
    print('IP')
except ValueError:
    if len(host) > 253 or not re.fullmatch(r'[A-Za-z0-9.-]+', host) or all(c in '0123456789.' for c in host):
        sys.exit('Atur WEB_HOST ke alamat IPv4 atau nama DNS yang valid.')
    if any(not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label) for label in host.split('.')):
        sys.exit('Nama DNS WEB_HOST tidak valid.')
    print('DNS')
PY
)

step 'Memeriksa akses MySQL lokal'
systemctl enable --now mysql
mysql=(mysql --protocol=socket --user=root --connect-timeout=10)
"${mysql[@]}" -N -B -e 'SELECT VERSION()' || die 'Login root MySQL gagal. Jika memakai password root, siapkan /root/.my.cnf dengan bagian [client], user=root, dan password yang sesuai (chmod 600).'
"${mysql[@]}" -N -B -e "SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci'" > "$tmp/collation"
[[ -s $tmp/collation ]] || die 'Schema panel membutuhkan MySQL 8.0 atau lebih baru.'

step 'Menyalin aplikasi dan menyiapkan akun layanan'
id unbound-web >/dev/null 2>&1 || useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin unbound-web
install -d -m 0750 -o root -g unbound-web "$conf"
install -d -m 0750 -o unbound-web -g unbound-web /var/lib/unbound-web
install -d -m 0750 -o root -g unbound-web /var/lib/unbound-web-filters
if [[ -d /etc/unbound && ! -L /etc/unbound/blocklist ]]; then
    if [[ ! -d /etc/unbound/blocklist ]]; then install -d -m 0755 /etc/unbound/blocklist; fi
    printf '# Filter Unbound Web Manager belum aktif.\n' > "$tmp/empty-filter"
    for list in blocklist whitelist; do
        filter_file=/etc/unbound/blocklist/$list.conf
        if [[ ! -e $filter_file && ! -L $filter_file ]]; then
            install -m 0644 "$tmp/empty-filter" "$filter_file"
        fi
    done
fi
install -d -m 0700 -o unbound-web -g unbound-web /var/lib/php/unbound-web-sessions
install -d -m 0755 "$dest"
for folder in app bin database public src; do
    install -d -m 0755 "$dest/$folder"
    rsync -rptgo --delete --chown=root:root --chmod=D755,F644 "$src/$folder/" "$dest/$folder/"
done
install -m 0755 -o root -g root "$src/bin/manage.py" /usr/local/sbin/unbound-web-manage
cat > "$tmp/sudoers" <<'SUDOERS'
unbound-web ALL=(root) NOPASSWD: /usr/local/sbin/unbound-web-manage
Cmnd_Alias UNBOUND_WEB_METRICS = /usr/local/sbin/unbound-web-manage stats, /usr/local/sbin/unbound-web-manage stats-live, /usr/local/sbin/unbound-web-manage service-status
Defaults!UNBOUND_WEB_METRICS !log_allowed, !pam_session
SUDOERS
visudo -cf "$tmp/sudoers"
install -m 0440 "$tmp/sudoers" /etc/sudoers.d/unbound-web

if [[ ! -e $conf/.env ]]; then
    python3 - > "$tmp/env" <<'PY'
import secrets, string
chars = [secrets.choice(string.ascii_uppercase), secrets.choice("!@%+_:.-")]
chars.extend(secrets.choice(string.ascii_lowercase) for _ in range(6))
secrets.SystemRandom().shuffle(chars)
print("DB_HOST=127.0.0.1\nDB_NAME=unbound_web\nDB_USER=unbound_web_" + secrets.token_hex(4))
print("DB_PASS=" + secrets.token_hex(24))
print("ADMIN_USER=admin\nADMIN_PASS=" + "".join(chars))
PY
    install -m 0640 -o root -g unbound-web "$tmp/env" "$conf/.env"
fi
chown root:unbound-web "$conf/.env"
chmod 0640 "$conf/.env"
step 'Menyiapkan database dan migrasi'
# Read values with the same parser as the runtime. Never source .env as shell code.
php8.4 -r 'require $argv[1]; foreach (["DB_NAME","DB_USER","DB_HOST","DB_PASS","ADMIN_USER","ADMIN_PASS"] as $key) {
    $v = app_env($key); if ($v === "" || strpbrk($v, "\r\n") !== false) exit(1); echo $v, "\n";
}' "$dest/app/bootstrap.php" > "$tmp/settings"
mapfile -t settings < "$tmp/settings"
[[ ${#settings[@]} = 6 ]] || die 'File .env tidak memuat enam nilai kredensial yang diperlukan.'
db_name=${settings[0]}
db_user=${settings[1]}
db_host=${settings[2]}
db_pass=${settings[3]}
[[ $db_name =~ ^[a-zA-Z0-9_]{1,64}$ && $db_user =~ ^[a-zA-Z0-9_]{1,32}$ ]] || die 'Nama database atau pengguna tidak valid.'
case "${db_name,,}" in mysql|sys|information_schema|performance_schema) die 'Gunakan DB khusus panel.';; esac
[[ $db_host = 127.0.0.1 || $db_host = localhost ]] || die 'Installer ini memakai MySQL lokal.'
# The identifier alphabet is restricted above. Quote password literals with backslash escaping disabled.
sql_pass=${db_pass//\'/\'\'}
"${mysql[@]}" <<SQL
SET SESSION sql_mode='NO_BACKSLASH_ESCAPES';
CREATE DATABASE IF NOT EXISTS \`$db_name\` CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS '$db_user'@'$db_host' IDENTIFIED BY '$sql_pass';
GRANT SELECT,INSERT,UPDATE,DELETE ON \`$db_name\`.* TO '$db_user'@'$db_host';
SQL
"${mysql[@]}" "$db_name" < "$dest/database/schema.sql"
# Older deployments may predate individual Linux metrics.
while IFS='|' read -r column definition; do
    exists=$("${mysql[@]}" -N -B "$db_name" -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='metrics' AND column_name='$column'")
    if [[ $exists = 0 ]]; then
        "${mysql[@]}" "$db_name" -e "ALTER TABLE metrics ADD COLUMN $column $definition"
    fi
done <<'COLUMNS'
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
sample_json|JSON NULL
COLUMNS
settings_size=$("${mysql[@]}" -N -B "$db_name" -e "SELECT character_maximum_length FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='app_settings' AND column_name='setting_value'")
if [[ $settings_size -lt 2048 ]]; then
    "${mysql[@]}" "$db_name" -e 'ALTER TABLE app_settings MODIFY setting_value VARCHAR(2048) NOT NULL'
fi
runuser -u unbound-web -- php8.4 -r 'require $argv[1]; db()->query("SELECT id FROM metrics LIMIT 1");' "$dest/app/bootstrap.php"
runuser -u unbound-web -- php8.4 "$dest/bin/seed-domains.php"
runuser -u unbound-web -- php8.4 "$dest/bin/seed-filters.php"

step 'Menyiapkan PHP-FPM dan cron'
pool=/etc/php/8.4/fpm/pool.d/unbound-web.conf
if [[ -f $pool ]]; then cp -p "$pool" "$tmp/previous-pool"; fi
cat > "$tmp/pool" <<'POOL'
[unbound-web]
user = unbound-web
group = unbound-web
listen = /run/php/unbound-web.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
php_admin_value[session.save_path] = /var/lib/php/unbound-web-sessions
pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
POOL
install -m 0644 "$tmp/pool" "$pool"
if ! php-fpm8.4 -t; then
    if [[ -f $tmp/previous-pool ]]; then cp -p "$tmp/previous-pool" "$pool"; else unlink "$pool"; fi
    die 'Konfigurasi PHP-FPM ditolak. Periksa error php-fpm8.4 di atas.'
fi
# sudo helpers inherit the FPM mount namespace. Keep ProtectSystem=full and allow
# only the configuration directories managed by the panel to be writable by root.
install -d -m 0755 /etc/systemd/system/php8.4-fpm.service.d
cat > "$tmp/fpm-access" <<'UNIT'
[Service]
ReadWritePaths=-/etc/unbound -/etc/cron.d -/etc/zabbix
UNIT
install -m 0644 "$tmp/fpm-access" /etc/systemd/system/php8.4-fpm.service.d/unbound-web.conf
systemctl daemon-reload
systemctl enable --now php8.4-fpm cron
# A reload does not recreate the service's filesystem namespace.
systemctl restart php8.4-fpm
for attempt in {1..20}; do
    [[ ! -S /run/php/unbound-web.sock ]] || break
    sleep 1
done
[[ -S /run/php/unbound-web.sock ]] || die 'Socket PHP-FPM belum tersedia. Cek journalctl -u php8.4-fpm -n 50.'
# Transfer existing worker samples from older deployments.
for file in worker.lock cpu.sample system-status.json; do
    path="/var/lib/unbound-web/$file"
    if [[ -f $path && ! -L $path ]]; then chown -h unbound-web:unbound-web "$path"; fi
done
if [[ -f /etc/cron.d/unbound-web ]]; then unlink /etc/cron.d/unbound-web; fi
cat > "$tmp/monitor-unit" <<'UNIT'
[Unit]
Description=Unbound Web Manager live monitoring (1 second)
After=network.target mysql.service unbound.service

[Service]
Type=simple
User=unbound-web
Group=unbound-web
ExecStart=/usr/bin/python3 -I /opt/www/unbound-web/bin/monitor.py
Restart=always
RestartSec=1
KillMode=control-group
TimeoutStopSec=25
ProtectSystem=full
ProtectHome=true
PrivateTmp=true
UMask=0027

[Install]
WantedBy=multi-user.target
UNIT
install -m 0644 "$tmp/monitor-unit" /etc/systemd/system/unbound-web-monitor.service
cat > "$tmp/update-unit" <<'UNIT'
[Unit]
Description=Update Unbound Web Manager from GitHub main
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 -I /opt/www/unbound-web/bin/update.py install
TimeoutStartSec=33min
UMask=0077
UNIT
install -m 0644 "$tmp/update-unit" /etc/systemd/system/unbound-web-update.service
systemctl daemon-reload
systemctl enable unbound-web-monitor
systemctl restart unbound-web-monitor

step "Menyiapkan HTTPS untuk $host"
install -d -m 0700 "$conf/tls"
cert="$conf/tls/panel.crt"
key="$conf/tls/panel.key"
verify=(-verify_hostname "$host")
[[ $san != IP ]] || verify=(-verify_ip "$host")
if [[ ! -s $cert || ! -s $key ]] || ! openssl verify -CAfile "$cert" "${verify[@]}" "$cert" >/dev/null 2>&1; then
    openssl req -x509 -newkey rsa:3072 -nodes -days 365 -subj "/CN=$host" \
        -addext "subjectAltName=$san:$host" -keyout "$tmp/key" -out "$tmp/crt"
    install -m 0600 "$tmp/key" "$key"
    install -m 0644 "$tmp/crt" "$cert"
fi
site=/etc/nginx/sites-available/unbound-web
link=/etc/nginx/sites-enabled/unbound-web
[[ ! -d $link ]] || die "$link berupa direktori. Nginx membutuhkan file konfigurasi atau symlink."
if [[ -e $link || -L $link ]]; then cp -a "$link" "$tmp/previous-link"; fi
if [[ -f $site ]]; then cp -p "$site" "$tmp/previous-site"; fi
cat > "$tmp/site" <<NGINX
server {
    listen 443 ssl;
    server_name $host;
    ssl_certificate $cert;
    ssl_certificate_key $key;
    root $dest/public;
    client_max_body_size 2m;

    location ^~ /assets/ { try_files \$uri =404; }
    location ~ /\. { deny all; }
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $dest/public/index.php;
        fastcgi_param HTTPS \$https if_not_empty;
        fastcgi_read_timeout 1250s;
        fastcgi_buffering off;
        fastcgi_pass unix:/run/php/unbound-web.sock;
    }
}
NGINX
install -m 0644 "$tmp/site" "$site"
ln -sfn "$site" "$link"
if ! nginx -t; then
    unlink "$link"
    if [[ -e $tmp/previous-link || -L $tmp/previous-link ]]; then cp -a "$tmp/previous-link" "$link"; fi
    if [[ -f $tmp/previous-site ]]; then cp -p "$tmp/previous-site" "$site"; else unlink "$site"; fi
    die 'Konfigurasi Nginx ditolak.'
fi
systemctl enable --now nginx
systemctl reload nginx
for unit in mysql php8.4-fpm nginx cron unbound-web-monitor; do
    systemctl is-active --quiet "$unit" || die "Layanan $unit belum aktif. Cek journalctl -u $unit -n 50."
done
step 'Memeriksa akses HTTPS dan halaman login'
curl --fail --silent --show-error --retry 5 --retry-connrefused --connect-timeout 5 --max-time 30 --cacert "$cert" \
    --noproxy '*' --resolve "$host:443:127.0.0.1" "https://$host/login" -o "$tmp/login"
grep -q 'name="password"' "$tmp/login" || die 'Halaman login panel belum dapat diverifikasi.'
printf '%s\n' "$host" > "$tmp/web-host"
install -m 0640 -o root -g unbound-web "$tmp/web-host" "$conf/web-host"
if [[ -d $src/.git ]] && revision=$(git -c "safe.directory=$src" -C "$src" rev-parse --verify HEAD) && git -c "safe.directory=$src" -C "$src" diff-index --quiet HEAD --; then
    printf '%s\n' "$revision" > "$tmp/installed-commit"
    install -m 0640 -o root -g unbound-web "$tmp/installed-commit" "$conf/installed-commit"
    if version=$(/usr/bin/python3 -I "$src/bin/update.py" version "$src" "$revision" 2>/dev/null); then
        runuser -u unbound-web -- php8.4 "$dest/bin/release-store.php" record-install "$revision" "$version"
        printf '{"commit":"%s","version":"%s"}\n' "$revision" "$version" > "$tmp/installed-release"
        install -m 0640 -o root -g unbound-web "$tmp/installed-release" "$conf/installed-release.json"
    else
        runuser -u unbound-web -- php8.4 "$dest/bin/release-store.php" forget-installed
    fi
else
    runuser -u unbound-web -- php8.4 "$dest/bin/release-store.php" forget-installed
    if [[ -f $conf/installed-commit ]]; then unlink "$conf/installed-commit"; fi
fi
if [[ ${UNBOUND_WEB_QUIET_CREDENTIALS:-0} = 1 ]]; then
    printf '\nPanel siap di https://%s/\n' "$host"
else
    printf '\nURL       https://%s/\nUsername  %s\nPassword  %s\n' "$host" "${settings[4]}" "${settings[5]}"
fi

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
