#!/usr/bin/env bash
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive
die() { echo "$*" >&2; exit 1; }
[[ $EUID = 0 ]] || die 'Jalankan lewat sudo bash install.sh.'
source /etc/os-release
[[ $ID = ubuntu && -d /run/systemd/system ]] || die 'Installer membutuhkan Ubuntu Server dengan systemd.'
src=$(cd -- "$(dirname -- "$0")" && pwd -P)
dest=/opt/www/unbound-web
conf=/etc/unbound-web
[[ $src != "$dest" ]] || die 'Jalankan installer dari checkout proyek.'
exec 9>/run/unbound-web-install.lock
flock -n 9 || die 'Installer lain sedang berjalan.'
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT

apt-get update
if ! apt-cache show php8.4-fpm >/dev/null 2>&1; then
    apt-get install -y software-properties-common
    add-apt-repository -y ppa:ondrej/php
    apt-get update
fi
apt-get install -y mysql-server nginx php8.4-fpm php8.4-cli php8.4-mysql \
    openssl sudo cron curl rsync python3 dnsperf
systemctl enable --now mysql
id unbound-web >/dev/null 2>&1 || useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin unbound-web
install -d -m 0750 -o root -g unbound-web "$conf"
install -d -m 0750 -o unbound-web -g unbound-web /var/lib/unbound-web
install -d -m 0700 -o unbound-web -g unbound-web /var/lib/php/unbound-web-sessions
install -d -m 0755 "$dest"
for folder in app bin database public src; do
    install -d -m 0755 "$dest/$folder"
    rsync -rptgo --delete --chown=root:root --chmod=D755,F644 "$src/$folder/" "$dest/$folder/"
done
install -m 0755 -o root -g root "$src/bin/manage.py" /usr/local/sbin/unbound-web-manage
printf 'unbound-web ALL=(root) NOPASSWD: /usr/local/sbin/unbound-web-manage\n' > "$tmp/sudoers"
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
# Read values with the same parser as the runtime. Never source .env as shell code.
php8.4 -r 'require $argv[1]; foreach (["DB_NAME","DB_USER","DB_HOST","DB_PASS","ADMIN_USER","ADMIN_PASS"] as $key) {
    $v = app_env($key); if ($v === "" || strpbrk($v, "\r\n") !== false) exit(1); echo $v, "\n";
}' "$dest/app/bootstrap.php" > "$tmp/settings"
mapfile -t settings < "$tmp/settings"
db_name=${settings[0]}
db_user=${settings[1]}
db_host=${settings[2]}
db_pass=${settings[3]}
[[ $db_name =~ ^[a-zA-Z0-9_]{1,64}$ && $db_user =~ ^[a-zA-Z0-9_]{1,32}$ ]] || die 'Nama database atau pengguna tidak valid.'
case "${db_name,,}" in mysql|sys|information_schema|performance_schema) die 'Gunakan DB khusus panel.';; esac
[[ $db_host = 127.0.0.1 || $db_host = localhost ]] || die 'Installer ini memakai MySQL lokal.'
mysql=(mysql --protocol=socket --user=root)
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
done < <(sed -nE 's/^    ([a-z_0-9]+) (.+),$/\1|\2/p' "$dest/database/schema.sql" | sed '/^id|/d; /^INDEX|/d; /^idx_/d; /^uptime_seconds|/q')
php8.4 "$dest/bin/seed-domains.php"
php8.4 -r 'require $argv[1]; db()->query("SELECT id FROM metrics LIMIT 1");' "$dest/app/bootstrap.php"

cat > /etc/php/8.4/fpm/pool.d/unbound-web.conf <<'POOL'
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
php-fpm8.4 -t
systemctl enable --now php8.4-fpm cron
systemctl reload php8.4-fpm
# Transfer existing worker samples from older deployments.
for file in worker.lock cpu.sample system-status.json; do
    path="/var/lib/unbound-web/$file"
    if [[ -f $path && ! -L $path ]]; then chown -h unbound-web:unbound-web "$path"; fi
done
printf '* * * * * unbound-web /usr/bin/php8.4 /opt/www/unbound-web/bin/worker.php\n' > "$tmp/cron"
install -m 0644 "$tmp/cron" /etc/cron.d/unbound-web

host=${WEB_HOST:-$(hostname -I | awk '{print $1}')}
[[ $host =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ]] || die 'Atur WEB_HOST ke alamat IPv4 atau nama DNS panel.'
san=DNS
[[ ! $host =~ ^[0-9.]+$ ]] || san=IP
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
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $dest/public/index.php;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 650s;
        fastcgi_buffering off;
        fastcgi_pass unix:/run/php/unbound-web.sock;
    }
}
NGINX
install -m 0644 "$tmp/site" "$site"
was_enabled=0
if [[ -L $link ]]; then was_enabled=1; else ln -s "$site" "$link"; fi
if ! nginx -t; then
    if [[ $was_enabled = 0 ]]; then unlink "$link"; fi
    if [[ -f $tmp/previous-site ]]; then cp -p "$tmp/previous-site" "$site"; else unlink "$site"; fi
    die 'Konfigurasi Nginx ditolak.'
fi
systemctl enable --now nginx
systemctl reload nginx
curl --fail --silent --show-error --retry 3 --retry-connrefused --cacert "$cert" \
    --noproxy '*' --resolve "$host:443:127.0.0.1" "https://$host/login" -o "$tmp/login"
grep -q 'name="password"' "$tmp/login" || die 'Halaman login panel belum dapat diverifikasi.'
printf '\nURL       https://%s/\nUsername  %s\nPassword  %s\n' "$host" "${settings[4]}" "${settings[5]}"
