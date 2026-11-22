#!/usr/bin/env bash
set -uo pipefail

APP_NAME='Uninstalasi Interaktif - Clear OS'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/src/terminal_ui.bash"
FAILED_STEPS=()
FALLBACK_DNS=()
RESOLV_BACKUP=''
UFW_DEFAULT_BACKUP=''

info() { ui_info "$@"; }
process() { ui_process "$@"; }
warn() { ui_warn "$@"; }
error() { ui_error "$@"; }

run_root() {
    if (( EUID == 0 )); then
        "$@"
    else
        sudo "$@"
    fi
}

run_step() {
    local label="$1"; shift
    process "Tahap: $label"
    if "$@"; then
        info "Selesai: $label"
    else
        local status=$?
        printf '%s[GAGAL, exit %s] %s%s\n' "$UI_RED" "$status" "$label" "$UI_RESET" >&2
        FAILED_STEPS+=("$label")
        return "$status"
    fi
}

valid_ipv4() {
    local value="$1" octet
    local -a octets=()
    [[ "$value" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
    [[ "$value" != 0.0.0.0 ]] || return 1
    IFS='.' read -r -a octets <<< "$value"
    for octet in "${octets[@]}"; do
        (( 10#$octet <= 255 )) || return 1
    done
}

discover_backups() {
    local -a matches=()
    shopt -s nullglob
    matches=(/etc/resolv.conf.bak.*)
    shopt -u nullglob
    if ((${#matches[@]})); then RESOLV_BACKUP="${matches[-1]}"; fi

    matches=()
    shopt -s nullglob
    matches=(/etc/default/ufw.bak.*)
    shopt -u nullglob
    if ((${#matches[@]})); then UFW_DEFAULT_BACKUP="${matches[-1]}"; fi
}

uses_local_resolver() {
    [[ -r /etc/resolv.conf ]] &&
        grep -Eq '^[[:space:]]*nameserver[[:space:]]+(127\.0\.0\.1|::1)([[:space:]]|$)' /etc/resolv.conf
}

read_fallback_dns() {
    local input address
    local -a addresses=()
    while true; do
        IFS= read -r -p "${UI_BLUE}Masukkan DNS IPv4 pengganti, pisahkan dengan koma (contoh 192.168.1.1,192.168.1.2): ${UI_RESET}" input || return 1
        addresses=()
        IFS=',' read -r -a addresses <<< "$input"
        ((${#addresses[@]})) || { warn 'Masukkan minimal satu alamat IPv4.'; continue; }
        local valid=yes
        for address in "${addresses[@]}"; do
            address="${address//[[:space:]]/}"
            if ! valid_ipv4 "$address"; then valid=no; break; fi
        done
        if [[ "$valid" == yes ]]; then
            FALLBACK_DNS=()
            for address in "${addresses[@]}"; do
                FALLBACK_DNS+=("${address//[[:space:]]/}")
            done
            return 0
        fi
        warn 'Alamat tidak valid. Gunakan alamat IPv4 yang dipisahkan koma.'
    done
}

write_fallback_resolver() {
    local temp_file address
    temp_file="$(mktemp /tmp/unbound-resolv.conf.XXXXXX)" || return 1
    for address in "${FALLBACK_DNS[@]}"; do printf 'nameserver %s\n' "$address" >> "$temp_file"; done
    if ! run_root cp --remove-destination -- "$temp_file" /etc/resolv.conf ||
        ! run_root chmod 644 /etc/resolv.conf; then
        rm -f -- "$temp_file"
        return 1
    fi
    rm -f -- "$temp_file"
    printf 'Resolver diatur ke: %s\n' "${FALLBACK_DNS[*]}"
}

restore_resolver() {
    if [[ -n "$RESOLV_BACKUP" ]]; then
        printf 'Memulihkan /etc/resolv.conf dari %s\n' "$RESOLV_BACKUP"
        run_root cp --archive --remove-destination -- "$RESOLV_BACKUP" /etc/resolv.conf
    elif ((${#FALLBACK_DNS[@]})); then
        write_fallback_resolver
    else
        printf 'Tidak ada perubahan resolver yang diperlukan.\n'
    fi
}

restore_ufw_defaults_file() {
    if [[ -n "$UFW_DEFAULT_BACKUP" ]]; then
        printf 'Memulihkan /etc/default/ufw dari %s\n' "$UFW_DEFAULT_BACKUP"
        run_root cp --archive --remove-destination -- "$UFW_DEFAULT_BACKUP" /etc/default/ufw || return 1
        run_root rm -f -- "$UFW_DEFAULT_BACKUP"
    else
        printf 'Cadangan /etc/default/ufw tidak ditemukan.\n'
    fi
}

stop_service() {
    local service="$1"
    if ! command -v systemctl >/dev/null 2>&1; then
        printf 'systemctl tidak tersedia.\n' >&2
        return 1
    fi
    if ! run_root systemctl cat "$service" >/dev/null 2>&1; then
        printf '%s tidak ditemukan; dilewati.\n' "$service"
        return 0
    fi
    run_root systemctl disable --now "$service"
}

purge_stack_packages() {
    local package status
    local -a packages=()
    for package in unbound unbound-anchor zabbix-agent dns-root-data dnsperf; do
        status="$(dpkg-query -W -f='${db:Status-Status}' "$package" 2>/dev/null || true)"
        if [[ "$status" == installed ]]; then packages+=("$package"); fi
    done
    if ((${#packages[@]} == 0)); then
        printf 'Paket Unbound/Zabbix yang dikelola tidak ditemukan.\n'
        return 0
    fi
    local log_file status
    printf 'Paket yang akan di-purge: %s\n' "${packages[*]}"
    log_file="$(mktemp "${TMPDIR:-/tmp}/unbound-apt-purge.XXXXXX")"
    if ui_run_with_spinner 'Menghapus paket layanan DNS dan monitoring' "$log_file" tail \
        run_root apt-get purge -y -- "${packages[@]}"; then
        status=0
    else
        status=$?
    fi
    rm -f -- "$log_file"
    return "$status"
}

reset_ufw() {
    local result=0
    if ! command -v ufw >/dev/null 2>&1; then
        printf 'UFW tidak tersedia; dilewati.\n'
        return 0
    fi
    run_root ufw --force disable || result=1
    run_root ufw --force reset || result=1
    restore_ufw_defaults_file || result=1
    return "$result"
}

remove_managed_files() {
    local path result=0
    local -a local_files=() resolver_backups=() ufw_backups=() system_files=(
        /etc/unbound
        /var/lib/unbound
        /var/cache/unbound
        /var/log/unbound
        /var/log/unbound-localzone.log
        /etc/sudoers.d/unbound-zabbix
        /etc/systemd/system/unbound-blocklist-update.service
        /etc/systemd/system/unbound-blocklist-update.timer
        /usr/local/bin/autoupdate-blocklist.py
        /usr/local/bin/domain-blocklist.txt
        /usr/local/bin/last-temp-final.txt
        /usr/local/bin/last-verified-whitelist.txt
    )

    for path in "${system_files[@]}"; do
        if [[ -e "$path" || -L "$path" ]]; then
            printf 'Hapus %s\n' "$path"
            run_root rm -rf -- "$path" || result=1
        fi
    done

    for path in /etc/zabbix/zabbix_agentd.conf /etc/zabbix/zabbix_agentd.conf.d; do
        if [[ -e "$path" || -L "$path" ]]; then
            printf 'Hapus %s\n' "$path"
            run_root rm -rf -- "$path" || result=1
        fi
    done

    if [[ -d /etc/zabbix ]]; then
        local -a zabbix_backups=()
        shopt -s nullglob
        zabbix_backups=(/etc/zabbix/zabbix_agentd.conf.bak.*)
        shopt -u nullglob
        for path in "${zabbix_backups[@]}"; do run_root rm -f -- "$path" || result=1; done
    fi

    shopt -s nullglob
    local_files=(
        "$SCRIPT_DIR/unbound.conf" "$SCRIPT_DIR/unbound.conf.bak."*
        "$SCRIPT_DIR/blocklist-sources.json" "$SCRIPT_DIR/blocklist-sources.json.bak."*
        "$SCRIPT_DIR/zabbix_agentd.conf" "$SCRIPT_DIR/zabbix_agentd.conf.bak."*
        "$SCRIPT_DIR/dnsperf-results"
        "$SCRIPT_DIR/.unbound-installer-rollback"
    )
    shopt -u nullglob
    for path in "${local_files[@]}"; do
        [[ -e "$path" || -L "$path" ]] || continue
        printf 'Hapus file hasil lokal %s\n' "$path"
        rm -rf -- "$path" || result=1
    done

    shopt -s nullglob
    resolver_backups=(/etc/resolv.conf.bak.*)
    ufw_backups=(/etc/default/ufw.bak.*)
    shopt -u nullglob
    for path in "${resolver_backups[@]}" "${ufw_backups[@]}"; do
        run_root rm -f -- "$path" || result=1
    done
    run_root systemctl daemon-reload || result=1
    return "$result"
}

show_package_status() {
    local package status
    for package in unbound unbound-anchor zabbix-agent dns-root-data dnsperf; do
        status="$(dpkg-query -W -f='${db:Status-Status}' "$package" 2>/dev/null || true)"
        [[ -n "$status" ]] || status='tidak terpasang'
        if [[ "$status" == installed ]]; then
            printf '  %-16s %s%s%s\n' "$package" "$UI_RED" "$status" "$UI_RESET"
        else
            printf '  %-16s %s%s%s\n' "$package" "$UI_GREEN" "$status" "$UI_RESET"
        fi
    done
}

show_system_result() {
    local service path
    ui_process 'HASIL SISTEM SETELAH UNINSTALL'
    if [[ -r /etc/os-release ]]; then
        . /etc/os-release
        printf 'OS                : %s%s%s\n' "$UI_GREEN" "${PRETTY_NAME:-tidak diketahui}" "$UI_RESET"
    fi
    printf '%sPaket layanan:%s\n' "$UI_BLUE" "$UI_RESET"
    show_package_status
    for service in unbound unbound-resolvconf.service zabbix-agent unbound-blocklist-update.timer; do
        if systemctl is-active --quiet "$service" 2>/dev/null; then
            printf 'Service %-27s %saktif%s\n' "$service" "$UI_RED" "$UI_RESET"
        else
            printf 'Service %-27s %stidak aktif%s\n' "$service" "$UI_GREEN" "$UI_RESET"
        fi
    done
    printf '%sFile konfigurasi:%s\n' "$UI_BLUE" "$UI_RESET"
    for path in /etc/unbound /var/lib/unbound /var/cache/unbound /var/log/unbound-localzone.log \
        /etc/zabbix/zabbix_agentd.conf /etc/sudoers.d/unbound-zabbix \
        /usr/local/bin/autoupdate-blocklist.py /usr/local/bin/domain-blocklist.txt \
        /usr/local/bin/last-temp-final.txt /usr/local/bin/last-verified-whitelist.txt \
        /etc/systemd/system/unbound-blocklist-update.service \
        /etc/systemd/system/unbound-blocklist-update.timer "$SCRIPT_DIR/dnsperf-results"; do
        if [[ -e "$path" || -L "$path" ]]; then
            printf '  %sMASIH ADA%s  %s\n' "$UI_RED" "$UI_RESET" "$path"
        else
            printf '  %sTERHAPUS%s   %s\n' "$UI_GREEN" "$UI_RESET" "$path"
        fi
    done
    if command -v ufw >/dev/null 2>&1; then
        printf 'Firewall UFW      : '
        run_root ufw status 2>&1 | sed -n '1,2p'
    else
        printf 'Firewall UFW      : paket tidak tersedia\n'
    fi
    printf 'Resolver           :\n'
    if [[ -r /etc/resolv.conf ]]; then sed -n '/^[[:space:]]*nameserver[[:space:]]/p' /etc/resolv.conf; else printf '  /etc/resolv.conf tidak dapat dibaca\n'; fi
    if command -v getent >/dev/null 2>&1; then
        printf 'Tes DNS            : '
        if getent ahosts cloudflare.com >/dev/null 2>&1; then
            printf '%sberhasil resolve cloudflare.com%s\n' "$UI_GREEN" "$UI_RESET"
        else
            printf '%sbelum berhasil resolve cloudflare.com%s\n' "$UI_RED" "$UI_RESET"
        fi
    fi
    printf 'Dependensi umum    : sudo, Python 3, curl, Git, dnsutils, dan UFW dipertahankan.\n'
    if ((${#FAILED_STEPS[@]})); then
        printf '\nTahap yang gagal:\n'
        printf '  - %s\n' "${FAILED_STEPS[@]}"
    else
        info 'Semua tahap uninstall selesai.'
    fi
}

main() {
    local confirmation
    ui_init
    ui_header "$APP_NAME"
    if (( EUID != 0 )); then
        if ! command -v sudo >/dev/null 2>&1; then
            error 'Jalankan sebagai root. sudo tidak tersedia.'
            exit 1
        fi
        sudo -v || { error 'Hak sudo tidak tersedia.'; exit 1; }
    fi
    if ! command -v apt-get >/dev/null 2>&1 || ! command -v dpkg-query >/dev/null 2>&1; then
        error 'Installer ini memerlukan APT dan dpkg (Ubuntu/Debian).'
        exit 1
    fi

    discover_backups
    process 'Script akan menjalankan tahapan berikut secara berurutan:'
    printf '  1. Menghentikan Unbound, Zabbix Agent, dan timer blocklist.\n'
    printf '  2. Memulihkan resolver lama dari cadangan, bila tersedia.\n'
    printf '  3. Menonaktifkan dan mereset aturan UFW.\n'
    printf '  4. Purge paket Unbound, unbound-anchor, Zabbix Agent, root hints, dan dnsperf.\n'
    printf '  5. Menghapus konfigurasi/data DNS, integrasi Zabbix, file timer, dan hasil lokal termasuk log dnsperf.\n'
    printf '  6. Menampilkan status paket, service, firewall, resolver, dan tes DNS.\n'
    printf '\nPaket umum sudo, Python 3, curl, Git, dnsutils, dan UFW dipertahankan.\n'
    printf 'Aturan UFW yang ada akan direset dan UFW dinonaktifkan.\n'
    if [[ -n "$RESOLV_BACKUP" ]]; then
        printf 'Cadangan resolver yang akan dipakai: %s\n' "$RESOLV_BACKUP"
    elif uses_local_resolver; then
        printf 'Resolver saat ini menunjuk ke localhost dan cadangan tidak ditemukan.\n'
    else
        printf 'Cadangan resolver tidak ditemukan; resolver yang tidak menunjuk localhost akan dibiarkan.\n'
    fi
    printf '\nAksi ini menghapus konfigurasi dan data Unbound secara permanen.\n'
    IFS= read -r -p "${UI_BLUE}Ketik HAPUS untuk melanjutkan, atau tekan Enter untuk batal: ${UI_RESET}" confirmation || exit 1
    [[ "$confirmation" == HAPUS ]] || { printf 'Uninstall dibatalkan; sistem tidak diubah.\n'; exit 0; }

    if [[ -z "$RESOLV_BACKUP" ]] && uses_local_resolver; then
        if ! read_fallback_dns; then
            error 'DNS pengganti tidak tersedia; uninstall dibatalkan sebelum perubahan dilakukan.'
            exit 1
        fi
        if ! run_step 'Mengatur resolver DNS pengganti' write_fallback_resolver; then
            error 'Resolver belum berhasil dipulihkan; uninstall dihentikan sebelum service dihapus.'
            exit 1
        fi
    elif [[ -n "$RESOLV_BACKUP" ]]; then
        if ! run_step 'Memulihkan resolver sistem sebelumnya' restore_resolver; then
            error 'Resolver sebelumnya belum berhasil dipulihkan; uninstall dihentikan sebelum service dihapus.'
            exit 1
        fi
    fi

    run_step 'Menghentikan service Unbound' stop_service unbound
    run_step 'Menghentikan service resolver Unbound' stop_service unbound-resolvconf.service
    run_step 'Menghentikan Zabbix Agent' stop_service zabbix-agent
    run_step 'Menghentikan timer pembaruan blocklist' stop_service unbound-blocklist-update.timer
    run_step 'Menonaktifkan dan mereset UFW' reset_ufw
    run_step 'Menghapus paket layanan DNS dan monitoring' purge_stack_packages
    run_step 'Menghapus konfigurasi, data, dan file hasil installer' remove_managed_files

    show_system_result
    ((${#FAILED_STEPS[@]} == 0))
}

main "$@"
