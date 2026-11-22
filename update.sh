#!/usr/bin/env bash
set -Eeuo pipefail

info() { printf '[INFO] %s\n' "$*"; }
die() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
trap 'die "Pembaruan berhenti pada baris $LINENO."' ERR

(($# == 0)) || die 'Penggunaan: sudo bash ./update.sh'
[[ $EUID -eq 0 ]] || die 'Jalankan sebagai root.'
[[ -r /etc/os-release && -d /run/systemd/system ]] || die 'Diperlukan Ubuntu 22.04 LTS dengan systemd.'
# shellcheck disable=SC1091
. /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 22.04 && "${VERSION_CODENAME:-}" == jammy ]] ||
    die 'Updater hanya mendukung Ubuntu 22.04 LTS (Jammy).'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# Direktori rilis diganti saat aktivasi. Jalankan updater dari luar direktori
# tersebut agar setiap proses selalu memiliki working directory yang valid.
cd /
# shellcheck source=scripts/web-deploy.bash
source "$SCRIPT_DIR/scripts/web-deploy.bash"
[[ -f /etc/unbound-web/.env && -f "$WEB_APP_DIR/public/index.php" ]] ||
    die 'Web Manager belum terpasang. Jalankan install.sh untuk pemasangan awal.'
[[ -x /usr/bin/git ]] || die 'Git belum tersedia pada instalasi ini.'
exec 9>/run/lock/unbound-web-install.lock
flock -n 9 || die 'Instalasi atau pembaruan lain sedang berjalan.'

info 'Menarik branch main dari repository resmi.'
web_pull_main
web_stage_release
# Load the candidate's migrations so the installed version cannot lag behind.
source "$WEB_TXN/release/scripts/web-migrate.bash"
db_name="$(php8.4 -r 'require $argv[1]; echo app_env("DB_NAME", "unbound_web");' -- "$WEB_TXN/release/app/bootstrap.php")"
web_apply_schema "$WEB_TXN/release/database/schema.sql" "$db_name"
web_activate_release
web_check_deployed
info 'Pembaruan selesai. Konfigurasi dan data aplikasi dipertahankan. Muat ulang halaman.'
