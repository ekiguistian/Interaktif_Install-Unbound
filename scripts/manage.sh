#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR='/opt/unbound-web'
CONF_FILE='/etc/unbound/unbound.conf'
MANAGED_CONF='/etc/unbound/unbound.conf.d/50-unbound-web.conf'
BLOCKLIST_CONF='/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf'
CRON_FILE='/etc/cron.d/unbound-web'
CUSTOM_CRON_FILE='/etc/cron.d/unbound-web-managed'
LOG_DIR='/var/log/unbound-web'
UNBOUND_BIN='/usr/sbin/unbound'
UNBOUND_CHECKCONF='/usr/sbin/unbound-checkconf'
UNBOUND_CONTROL='/usr/sbin/unbound-control'
UNBOUND_ANCHOR='/usr/sbin/unbound-anchor'
UNBOUND_CONTROL_SETUP='/usr/sbin/unbound-control-setup'
ROOT_HINTS_FILE='/var/lib/unbound/root.hints'

info() { printf '[INFO] %s\n' "$*"; }
fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }
[[ $EUID -eq 0 ]] || fail 'Management helper harus dijalankan melalui sudo.'
[[ -r /etc/os-release ]] || fail 'Informasi sistem operasi tidak ditemukan.'
# shellcheck disable=SC1091
. /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 22.04 && "${VERSION_CODENAME:-}" == jammy ]] ||
    fail "Web Manager hanya mendukung Ubuntu Server 22.04 LTS (Jammy). Terdeteksi: ${PRETTY_NAME:-tidak diketahui}."
[[ -d /run/systemd/system ]] || fail 'Jalankan di Ubuntu dengan systemd aktif.'
action="${1:-}"
shift || true

# Read literal arguments sent by the parent through the service's input.
if [[ "${UNBOUND_WEB_SYSTEMD_ARGS:-}" == 1 && -z "${SUDO_USER:-}" ]]; then
    mapfile -d '' -t service_args
    set -- "${service_args[@]}"
    exec </dev/null
fi

# sudo inherits the PHP-FPM service's read-only mounts. Keep that sandbox
# and let systemd start the allowlisted system changes independently.
case "$action" in
    wizard-step-[1-2]|wizard-rollback|app-update|resolver-verify|resolver-settings-status)
        (($# == 0)) || fail 'Aksi ini tidak menerima argumen.'
        ;;
esac
case "$action" in
    wizard-step-[1-2]|wizard-rollback|app-update|apply-config|system-dns-status|system-dns-set|unbound-logging-set|resolver-verify|update-blocklists|update-root-hints|\
    blocklist-schedule|zabbix-install|zabbix-config|zabbix-service|firewall-enable|firewall-disable|firewall-add|\
    firewall-update|firewall-delete|cron-custom-save|cron-custom-delete)
        if [[ "${SUDO_USER:-}" == www-data ]]; then
            exec /usr/bin/systemd-run --system --no-ask-password --quiet --wait --pipe --collect --working-directory=/ \
                --service-type=exec --setenv=SUDO_USER= --setenv=UNBOUND_WEB_SYSTEMD_ARGS=1 \
                -- /usr/local/sbin/unbound-web-manage "$action" \
                < <(if (($#)); then printf '%s\0' "$@"; fi)
        fi
        ;;
esac

valid_uint() { [[ "$1" =~ ^[1-9][0-9]{0,8}$ ]]; }
valid_port() { valid_uint "$1" && ((10#$1 >= 1 && 10#$1 <= 65535)); }
valid_ip() { php8.4 -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP) === false ? 1 : 0);' -- "$1" >/dev/null 2>&1; }
valid_cidr() { php8.4 -r '$p=explode("/",$argv[1]); if(count($p)>2||filter_var($p[0],FILTER_VALIDATE_IP)===false) exit(1); if(isset($p[1])) { $m=str_contains($p[0],":")?128:32; if(!ctype_digit($p[1])||(int)$p[1]>$m) exit(1); }' -- "$1" >/dev/null 2>&1; }
valid_host() { [[ "$1" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ && "$1" != *..* ]]; }
valid_domain() { [[ "$1" =~ ^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$ ]]; }
valid_private_ipv4() {
    valid_ip "$1" || return 1
    local first second
    IFS=. read -r first second _ <<< "$1"
    ((first == 127 || first == 10 || (first == 172 && second >= 16 && second <= 31) || (first == 192 && second == 168)))
}
valid_cache() { [[ "$1" =~ ^[0-9]{1,6}[kKmMgG]?$ ]]; }
valid_cron_field() {
    local token="$1" maximum="$2" part base step start end number
    local -a parts=()
    [[ "$token" =~ ^[0-9*/,-]{1,32}$ ]] || return 1
    IFS=',' read -r -a parts <<< "$token"
    for part in "${parts[@]}"; do
        base="$part"; step=''
        if [[ "$part" == */* ]]; then base="${part%%/*}"; step="${part#*/}"; [[ "$step" =~ ^[1-9][0-9]*$ ]] && ((10#$step <= maximum)) || return 1; fi
        if [[ "$base" == '*' ]]; then continue; fi
        if [[ "$base" =~ ^([0-9]+)-([0-9]+)$ ]]; then
            start="${BASH_REMATCH[1]}"; end="${BASH_REMATCH[2]}"
            ((10#$start <= maximum && 10#$end <= maximum && 10#$start <= 10#$end)) || return 1
        elif [[ "$base" =~ ^[0-9]+$ ]]; then
            number="$base"; ((10#$number <= maximum)) || return 1
        else
            return 1
        fi
    done
}

service_state() {
    local service="$1" binary="$2"
    if ! command -v "$binary" >/dev/null 2>&1; then
        printf 'not-installed'
    elif systemctl is-active --quiet "$service" 2>/dev/null; then
        printf 'active'
    else
        printf 'inactive'
    fi
}

wizard_state_dir='/var/lib/unbound-web/root-state'
wizard_pending_file="$wizard_state_dir/wizard-pending"
system_dns_file='/etc/resolv.conf'

system_dns_writable() {
    [[ -r "$system_dns_file" ]] || return 1
    if findmnt -rn --mountpoint "$system_dns_file" >/dev/null 2>&1; then return 1; fi
    local options
    options="$(findmnt -rn --target "${system_dns_file%/*}" -o VFS-OPTIONS)" || return 1
    [[ ",$options," != *,ro,* ]]
}

system_dns_ready() {
    [[ ! -e "$wizard_pending_file" ]] && systemctl is-active --quiet unbound
}

system_dns_status() {
    if ! system_dns_ready; then
        printf 'not-ready\n'
    elif system_dns_writable; then
        printf 'ready\n'
    else
        printf 'read-only\n'
    fi
    if [[ -r "$system_dns_file" ]]; then
        awk '$1 == "nameserver" && NF >= 2 {print $2}' "$system_dns_file"
    fi
}

system_dns_set() (
    (($# >= 1 && $# <= 3)) || fail 'Masukkan satu sampai tiga alamat IP DNS.'
    system_dns_ready || fail 'Selesaikan wizard Unbound sebelum mengubah DNS sistem.'
    system_dns_writable || fail 'File DNS dikelola host atau bersifat read-only.'
    lock_unbound_config
    local server answer reachable=no workdir changed=no complete=no
    for server in "$@"; do valid_ip "$server" || fail 'Alamat DNS harus berupa IPv4 atau IPv6.'; done
    for server in "$@"; do
        answer="$(dig "@$server" nlnetlabs.nl. A +time=3 +tries=1 +short)" || continue
        if [[ -n "$answer" ]]; then reachable=yes; break; fi
    done
    [[ "$reachable" == yes ]] || fail 'Alamat DNS yang dimasukkan belum dapat menjawab query Internet.'
    workdir="$(mktemp -d "${system_dns_file%/*}/.unbound-web-dns.XXXXXX")"
    trap '
        status=$?
        if [[ "$changed" == yes && "$complete" == no ]]; then
            if ! mv -fT -- "$workdir/previous" "$system_dns_file"; then
                printf "[ERROR] Cadangan DNS tersimpan di %s\n" "$workdir/previous" >&2
                exit 1
            fi
            info "Setelan DNS sebelumnya dipulihkan."
        fi
        rm -f -- "$workdir/previous" "$workdir/next"
        rmdir -- "$workdir"
        exit "$status"
    ' EXIT
    cp -aT -- "$system_dns_file" "$workdir/previous"
    {
        printf 'nameserver %s\n' "$@"
        awk '$1 != "nameserver"' "$system_dns_file"
    } > "$workdir/next"
    chmod 0644 "$workdir/next"
    mv -fT -- "$workdir/next" "$system_dns_file" || fail 'File DNS tidak dapat diganti. Setelan lama tetap digunakan.'
    changed=yes
    timeout 15 getent ahosts nlnetlabs.nl >/dev/null ||
        fail 'Pemeriksaan DNS sistem gagal setelah perubahan.'
    complete=yes
    info 'Alamat DNS sistem disimpan.'
)

prepare_wizard() {
    local command
    for command in systemctl; do
        command -v "$command" >/dev/null 2>&1 || fail "Dependensi belum tersedia: $command. Jalankan install.sh terlebih dahulu."
        info "Dependensi tersedia: $command"
    done
    install -d -o root -g root -m 0700 "$wizard_state_dir"
    install -o root -g root -m 0600 /dev/null "$wizard_pending_file"
    if unbound_tools_ready; then
        info "Paket Unbound tersedia: $($UNBOUND_BIN -V 2>&1 | head -n 1)."
    else
        fail 'Paket Unbound belum lengkap. Jalankan kembali install.sh dari terminal.'
    fi
    info 'Konfigurasi DNS sistem dipertahankan.'
}

unbound_tools_ready() {
    local tool
    [[ "$(dpkg-query -W -f='${db:Status-Status}' unbound 2>/dev/null || true)" == installed ]] || return 1
    [[ "$(dpkg-query -W -f='${db:Status-Status}' unbound-anchor 2>/dev/null || true)" == installed ]] || return 1
    for tool in unbound unbound-control unbound-checkconf unbound-anchor unbound-control-setup; do
        [[ -x "/usr/sbin/$tool" ]] || return 1
    done
}

valid_root_hints() {
    grep -Eiq '^\.[[:space:]]+([0-9]+[[:space:]]+)?(IN[[:space:]]+)?NS[[:space:]]+[^[:space:]]+' "$1"
}

ensure_root_hints() {
    local hints_tmp
    install -d -o unbound -g unbound -m 0750 /var/lib/unbound
    if [[ -s "$ROOT_HINTS_FILE" ]] && valid_root_hints "$ROOT_HINTS_FILE"; then
        chown root:unbound "$ROOT_HINTS_FILE"
        chmod 0640 "$ROOT_HINTS_FILE"
        info 'Root hints yang valid sudah tersedia; unduhan dilewati.'
        return
    fi
    hints_tmp="$(mktemp /var/lib/unbound/root.hints.XXXXXX)"
    if ! curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        'https://www.internic.net/domain/named.root' --output "$hints_tmp"; then
        rm -f -- "$hints_tmp"
        fail 'Unduhan root.hints gagal.'
    fi
    valid_root_hints "$hints_tmp" || { rm -f -- "$hints_tmp"; fail 'File root.hints tidak valid.'; }
    chown root:unbound "$hints_tmp"
    chmod 0640 "$hints_tmp"
    mv -f -- "$hints_tmp" "$ROOT_HINTS_FILE"
    info 'Root hints terbaru berhasil dipasang.'
}

ensure_dnssec_anchor() {
    local anchor_status=0
    install -d -o unbound -g unbound -m 0750 /var/lib/unbound
    if [[ -s /var/lib/unbound/root.key ]]; then
        chown unbound:unbound /var/lib/unbound/root.key
        chmod 0640 /var/lib/unbound/root.key
        info 'Trust anchor DNSSEC sudah tersedia; bootstrap dilewati.'
        return
    fi
    "$UNBOUND_ANCHOR" -a /var/lib/unbound/root.key || anchor_status=$?
    ((anchor_status <= 1)) && [[ -s /var/lib/unbound/root.key ]] ||
        fail 'unbound-anchor tidak menghasilkan trust anchor DNSSEC.'
    chown unbound:unbound /var/lib/unbound/root.key
    chmod 0640 /var/lib/unbound/root.key
    info 'Trust anchor DNSSEC siap.'
}

ensure_control_keys() {
    if [[ ! -s /etc/unbound/unbound_server.key || ! -s /etc/unbound/unbound_server.pem ||
          ! -s /etc/unbound/unbound_control.key || ! -s /etc/unbound/unbound_control.pem ]]; then
        "$UNBOUND_CONTROL_SETUP" -d /etc/unbound
    fi
    chown unbound:unbound /etc/unbound/unbound_server.key /etc/unbound/unbound_server.pem \
        /etc/unbound/unbound_control.key /etc/unbound/unbound_control.pem
    chmod 0640 /etc/unbound/unbound_server.key /etc/unbound/unbound_server.pem \
        /etc/unbound/unbound_control.key /etc/unbound/unbound_control.pem
}

postinstall_unbound() {
    unbound_tools_ready || fail 'Paket Unbound belum lengkap. Jalankan kembali install.sh dari terminal.'
    "$UNBOUND_CHECKCONF" "$CONF_FILE"
    if getent passwd unbound >/dev/null; then
        ensure_control_keys
    fi
    systemctl enable unbound >/dev/null
    if ! systemctl is-active --quiet unbound; then
        systemctl reset-failed unbound >/dev/null 2>&1 || true
    fi
    if ! systemctl start unbound; then
        systemctl status --no-pager unbound || true
        fail 'Service Unbound bawaan Ubuntu gagal dijalankan. Periksa status di atas.'
    fi
    systemctl is-active --quiet unbound || fail 'Service Unbound belum aktif.'
    rm -f -- "$wizard_pending_file"
    info 'Service Unbound bawaan Ubuntu sudah aktif. Lanjutkan konfigurasi dari Settings.'
}

make_config() {
    local interfaces_csv="$1" port="$2" acl_csv="$3" threads="$4" msg_cache="$5" rrset_cache="$6" prefetch="$7" min_ttl="$8" max_ttl="$9" slabs="${10}"
    local dnssec="${11}" root_hints="${12}" upstream_mode="${13}" forwarders_csv="${14}" value module_config
    local -a interfaces=() acls=() forwarders=()
    IFS=',' read -r -a interfaces <<< "$interfaces_csv"
    IFS=',' read -r -a acls <<< "$acl_csv"
    if [[ -n "$forwarders_csv" ]]; then IFS=',' read -r -a forwarders <<< "$forwarders_csv"; fi
    ((${#interfaces[@]} > 0 && ${#acls[@]} > 0)) || fail 'Interface dan access-control wajib diisi.'
    for value in "${interfaces[@]}"; do valid_ip "$value" || fail "Alamat interface tidak valid: $value"; done
    for value in "${acls[@]}"; do valid_cidr "$value" || fail "Access-control tidak valid: $value"; done
    valid_port "$port" || fail 'Port harus bernilai 1 sampai 65535.'
    valid_uint "$threads" && ((10#$threads <= 256)) || fail 'num-threads harus 1 sampai 256.'
    valid_cache "$msg_cache" || fail 'msg-cache-size tidak valid.'
    valid_cache "$rrset_cache" || fail 'rrset-cache-size tidak valid.'
    [[ "$prefetch" == yes || "$prefetch" == no ]] || fail 'prefetch hanya menerima yes atau no.'
    valid_uint "$min_ttl" && ((10#$min_ttl <= 86400)) || fail 'cache-min-ttl harus 1 sampai 86400 detik.'
    valid_uint "$max_ttl" && ((10#$max_ttl <= 604800 && 10#$max_ttl >= 10#$min_ttl)) || fail 'cache-max-ttl harus sama atau lebih besar dari cache-min-ttl dan maksimal 604800 detik.'
    valid_uint "$slabs" && ((10#$slabs <= 256)) || fail 'Jumlah cache slabs harus 1 sampai 256.'
    (( (10#$slabs & (10#$slabs - 1)) == 0 )) || fail 'Jumlah cache slabs harus pangkat dua.'
    [[ "$dnssec" == yes || "$dnssec" == no ]] || fail 'Pilihan DNSSEC tidak valid.'
    [[ "$root_hints" == yes || "$root_hints" == no ]] || fail 'Pilihan root hints tidak valid.'
    [[ "$upstream_mode" == root || "$upstream_mode" == forward || "$upstream_mode" == both ]] || fail 'Mode resolver tidak valid.'
    if [[ "$upstream_mode" == forward || "$upstream_mode" == both ]]; then
        ((${#forwarders[@]} >= 1 && ${#forwarders[@]} <= 8)) || fail 'Mode forwarder memerlukan satu sampai delapan alamat upstream.'
        for value in "${forwarders[@]}"; do valid_ip "$value" || fail "Alamat upstream tidak valid: $value"; done
    fi
    [[ "$upstream_mode" != forward || "$root_hints" == no ]] || fail 'Root hints hanya digunakan pada mode Root atau Gabungan.'
    if [[ "$dnssec" == yes ]]; then module_config='validator iterator'; else module_config='iterator'; fi

    local temp_file validation_backup=''
    [[ -d /etc/unbound/unbound.conf.d ]] || fail 'Direktori konfigurasi paket Unbound tidak ditemukan. Jalankan kembali install.sh.'
    install -d -o unbound -g unbound -m 0750 /var/lib/unbound
    [[ "$root_hints" == no ]] || ensure_root_hints
    [[ "$dnssec" == no ]] || ensure_dnssec_anchor
    temp_file="$(mktemp /etc/unbound/unbound.conf.d/50-unbound-web.conf.XXXXXX)"
    {
        printf '# unbound-web-upstream-mode: %s\n' "$upstream_mode"
        cat <<'CONF'
server:
    verbosity: 0
CONF
        for value in "${interfaces[@]}"; do printf '    interface: %s\n' "$value"; done
        printf '    port: %s\n' "$port"
        cat <<'CONF'
    do-ip4: yes
CONF
        if [[ -n "$(ip -6 -o address show dev lo scope host)" ]]; then
            printf '    do-ip6: yes\n'
        else
            printf '    do-ip6: no\n'
        fi
        cat <<'CONF'
    do-udp: yes
    do-tcp: yes
CONF
        for value in "${acls[@]}"; do printf '    access-control: %s allow\n' "$value"; done
        cat <<CONF
    num-threads: $threads
    msg-cache-size: $msg_cache
    rrset-cache-size: $rrset_cache
    key-cache-size: 64m
    msg-cache-slabs: $slabs
    rrset-cache-slabs: $slabs
    infra-cache-slabs: $slabs
    key-cache-slabs: $slabs
    cache-min-ttl: $min_ttl
    cache-max-ttl: $max_ttl
    prefetch: $prefetch
    prefetch-key: $dnssec
    statistics-cumulative: yes
    extended-statistics: yes
    module-config: "$module_config"
    qname-minimisation: yes
    harden-dnssec-stripped: $dnssec
    hide-identity: yes
    hide-version: yes
    minimal-responses: yes
    edns-buffer-size: 1232
CONF
        if [[ "$root_hints" == yes && "$upstream_mode" != forward ]]; then
            printf '    root-hints: "%s"\n' "$ROOT_HINTS_FILE"
        fi
        if [[ "$upstream_mode" == forward || "$upstream_mode" == both ]]; then
            printf '\nforward-zone:\n    name: "."\n'
            if [[ "$upstream_mode" == both ]]; then
                printf '    forward-first: yes\n'
            else
                printf '    forward-first: no\n'
            fi
            for value in "${forwarders[@]}"; do printf '    forward-addr: %s\n' "$value"; done
        fi
    } > "$temp_file"

    if ! "$UNBOUND_CHECKCONF" "$temp_file"; then
        rm -f -- "$temp_file"
        fail 'unbound-checkconf menolak konfigurasi. File aktif tidak diubah.'
    fi
    if [[ -f "$MANAGED_CONF" ]]; then
        validation_backup="$(mktemp /etc/unbound/unbound.conf.d/50-unbound-web.previous.XXXXXX)"
        cp -a -- "$MANAGED_CONF" "$validation_backup"
    fi
    install -o root -g unbound -m 0640 "$temp_file" "$MANAGED_CONF"
    rm -f -- "$temp_file"
    if ! "$UNBOUND_CHECKCONF" "$CONF_FILE"; then
        if [[ -n "$validation_backup" ]]; then mv -f -- "$validation_backup" "$MANAGED_CONF"; else rm -f -- "$MANAGED_CONF"; fi
        fail 'Konfigurasi gabungan ditolak. File aplikasi sebelumnya dipulihkan.'
    fi
    [[ -z "$validation_backup" ]] || rm -f -- "$validation_backup"
}

set_schedule() {
    local schedule="$1" blocklist_line=''
    case "$schedule" in
        disabled) ;;
        daily) blocklist_line='15 3 * * * root /usr/local/sbin/unbound-web-manage update-blocklists >> /var/log/unbound-web/blocklist.log 2>&1' ;;
        weekly) blocklist_line='30 3 * * 1 root /usr/local/sbin/unbound-web-manage update-blocklists >> /var/log/unbound-web/blocklist.log 2>&1' ;;
        6h) blocklist_line='15 */6 * * * root /usr/local/sbin/unbound-web-manage update-blocklists >> /var/log/unbound-web/blocklist.log 2>&1' ;;
        12h) blocklist_line='15 */12 * * * root /usr/local/sbin/unbound-web-manage update-blocklists >> /var/log/unbound-web/blocklist.log 2>&1' ;;
        *) fail 'Jadwal tidak dikenal.' ;;
    esac
    local tmp
    tmp="$(mktemp /etc/cron.d/unbound-web.XXXXXX)"
    cat > "$tmp" <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * www-data /usr/bin/php8.4 /opt/unbound-web/bin/worker.php >>/var/log/unbound-web/worker.log 2>&1
CRON
    [[ -n "$blocklist_line" ]] && printf '%s\n' "$blocklist_line" >> "$tmp"
    install -o root -g root -m 0644 "$tmp" "$CRON_FILE"
    rm -f -- "$tmp"
    info "Jadwal blocklist: $schedule. Worker metrik tetap berjalan setiap menit."
}

save_custom_cron() {
    local id="$1" minute="$2" hour="$3" day="$4" month="$5" weekday="$6" task="$7" temp
    [[ "$id" =~ ^[A-Za-z0-9_-]{1,40}$ ]] || fail 'ID cron hanya boleh berisi huruf, angka, garis bawah, dan strip.'
    valid_cron_field "$minute" 59 || fail 'Field menit cron tidak valid.'
    valid_cron_field "$hour" 23 || fail 'Field jam cron tidak valid.'
    valid_cron_field "$day" 31 || fail 'Field hari cron tidak valid.'
    valid_cron_field "$month" 12 || fail 'Field bulan cron tidak valid.'
    valid_cron_field "$weekday" 7 || fail 'Field hari minggu cron tidak valid.'
    [[ "$task" == update-blocklists || "$task" == update-root-hints ]] || fail 'Tugas cron tidak diizinkan.'
    temp="$(mktemp /etc/cron.d/unbound-web-managed.XXXXXX)"
    if [[ -r "$CUSTOM_CRON_FILE" ]]; then
        awk -v marker="# unbound-web-id:$id" '$0==marker {getline; next} {print}' "$CUSTOM_CRON_FILE" > "$temp"
    fi
    printf '# unbound-web-id:%s\n%s %s %s %s %s root /usr/local/sbin/unbound-web-manage %s >>/var/log/unbound-web/%s.log 2>&1\n' \
        "$id" "$minute" "$hour" "$day" "$month" "$weekday" "$task" "$task" >> "$temp"
    install -o root -g root -m 0644 "$temp" "$CUSTOM_CRON_FILE"
    rm -f -- "$temp"
    info "Jadwal $id disimpan untuk tugas $task."
}

delete_custom_cron() {
    local id="$1" temp
    [[ "$id" =~ ^[A-Za-z0-9_-]{1,40}$ ]] || fail 'ID cron tidak valid.'
    [[ -r "$CUSTOM_CRON_FILE" ]] || fail 'Belum ada jadwal tambahan untuk dihapus.'
    temp="$(mktemp /etc/cron.d/unbound-web-managed.XXXXXX)"
    awk -v marker="# unbound-web-id:$id" '$0==marker {getline; found=1; next} {print} END {if(!found) exit 2}' "$CUSTOM_CRON_FILE" > "$temp" || { rm -f "$temp"; fail 'ID jadwal tidak ditemukan.'; }
    if [[ ! -s "$temp" ]]; then rm -f "$CUSTOM_CRON_FILE"; else install -o root -g root -m 0644 "$temp" "$CUSTOM_CRON_FILE"; fi
    rm -f -- "$temp"
    info "Jadwal $id dihapus."
}

update_root_hints() {
    local temp backup
    [[ -x "$UNBOUND_CHECKCONF" && -f "$CONF_FILE" ]] || fail 'Unbound belum terpasang.'
    [[ -s "$ROOT_HINTS_FILE" ]] || fail 'Root hints aktif tidak ditemukan; aktifkan dari Settings terlebih dahulu.'
    temp="$(mktemp /var/lib/unbound/root.hints.XXXXXX)"
    backup="$(mktemp /var/lib/unbound/root.hints.previous.XXXXXX)"
    if ! curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 'https://www.internic.net/domain/named.root' --output "$temp"; then
        rm -f "$temp" "$backup"; fail 'Unduhan root.hints gagal.'
    fi
    valid_root_hints "$temp" || { rm -f "$temp" "$backup"; fail 'File root.hints tidak valid.'; }
    if ! cp -a "$ROOT_HINTS_FILE" "$backup"; then
        rm -f "$temp" "$backup"; fail 'Cadangan root.hints tidak dapat dibuat.'
    fi
    chown root:unbound "$temp"
    chmod 0640 "$temp"
    mv -f -- "$temp" "$ROOT_HINTS_FILE"
    if ! "$UNBOUND_CHECKCONF" "$CONF_FILE" || ! systemctl reload unbound; then
        mv -f -- "$backup" "$ROOT_HINTS_FILE"
        systemctl reload unbound || true
        rm -f "$temp" "$backup"; fail 'Perubahan root.hints gagal divalidasi atau service gagal reload.'
    fi
    rm -f "$temp" "$backup"
    info 'Root hints diperbarui dan Unbound berhasil di-reload.'
}

configure_zabbix() {
    local server="$1" active="$2" hostname="$3" config_file service binary include_dir fresh=no
    valid_host "$server" || fail 'Alamat Zabbix Server tidak valid.'
    valid_host "$active" || fail 'Alamat Zabbix ServerActive tidak valid.'
    [[ "$hostname" =~ ^[A-Za-z0-9._-]{1,128}$ ]] || fail 'Hostname agent tidak valid.'
    if ! command -v zabbix_agent2 >/dev/null 2>&1 && ! command -v zabbix_agentd >/dev/null 2>&1; then
        fresh=yes
        apt-get update --allow-releaseinfo-change-label
        if apt-cache show zabbix-agent2 >/dev/null 2>&1; then
            apt-get install -y zabbix-agent2
        else
            apt-get install -y zabbix-agent
        fi
    fi
    if command -v zabbix_agent2 >/dev/null 2>&1; then
        config_file='/etc/zabbix/zabbix_agent2.conf'; service='zabbix-agent2'; binary='zabbix_agent2'; include_dir='/etc/zabbix/zabbix_agent2.d'
    else
        config_file='/etc/zabbix/zabbix_agentd.conf'; service='zabbix-agent'; binary='zabbix_agentd'; include_dir='/etc/zabbix/zabbix_agentd.d'
    fi
    install -d -o root -g root -m 0755 /etc/zabbix
    if [[ -f "$config_file" ]]; then
        cp -a "$config_file" "$config_file.web-backup.$(date +%Y%m%d%H%M%S)"
    fi
    local conf_tmp
    conf_tmp="$(mktemp "$config_file.XXXXXX")"
    cat > "$conf_tmp" <<CONF
PidFile=/run/zabbix/${binary}.pid
LogFile=/var/log/zabbix/${binary}.log
LogFileSize=0
Server=$server
ServerActive=$active
Hostname=$hostname
Include=${include_dir}/*.conf
CONF
    install -o root -g zabbix -m 0640 "$conf_tmp" "$config_file"
    rm -f -- "$conf_tmp"
    if [[ "$fresh" == yes ]]; then
        systemctl enable --now "$service"
        systemctl restart "$service"
    else
        systemctl try-restart "$service"
    fi
    info "Konfigurasi Zabbix Agent disimpan ($service)."
}

zabbix_unit() {
    if command -v zabbix_agent2 >/dev/null 2>&1; then
        printf 'zabbix-agent2.service\n'
    elif command -v zabbix_agentd >/dev/null 2>&1; then
        printf 'zabbix-agent.service\n'
    else
        fail 'Zabbix Agent belum terpasang.'
    fi
}

lock_unbound_config() {
    exec 9>/run/unbound-web-config.lock
    flock -n 9 || fail 'Konfigurasi Unbound sedang diubah. Coba lagi.'
}

set_unbound_logging() {
    local queries="$1" replies="$2" temp backup='' tag=no
    local dir='/etc/unbound/unbound.conf.d' check="$UNBOUND_CHECKCONF"
    local target="$dir/99-unbound-web-logging.conf"
    [[ "$queries" == yes || "$queries" == no ]] || fail 'Nilai log query tidak valid.'
    [[ "$replies" == yes || "$replies" == no ]] || fail 'Nilai log reply tidak valid.'
    [[ -x "$check" && -f "$CONF_FILE" && -d "$dir" ]] || fail 'Selesaikan instalasi Unbound terlebih dahulu.'
    lock_unbound_config
    if [[ "$queries" == yes || "$replies" == yes ]]; then tag=yes; fi
    temp="$(mktemp "$dir/.logging.XXXXXX")"
    printf 'server:\n    log-queries: %s\n    log-replies: %s\n    log-tag-queryreply: %s\n' "$queries" "$replies" "$tag" > "$temp"
    chown root:unbound "$temp"
    chmod 0640 "$temp"
    if [[ -e "$target" ]]; then
        backup="$(mktemp "$dir/.logging-backup.XXXXXX")"
        cp -a -- "$target" "$backup"
    fi
    mv -f -- "$temp" "$target"
    if [[ "$("$check" -o log-queries "$CONF_FILE")" != "$queries" || "$("$check" -o log-replies "$CONF_FILE")" != "$replies" ]] ||
        ! systemctl reload unbound; then
        if [[ -n "$backup" ]]; then mv -f -- "$backup" "$target"; else rm -f -- "$target"; fi
        systemctl reload unbound || true
        fail 'Konfigurasi log gagal diterapkan. Pengaturan sebelumnya dipulihkan.'
    fi
    if [[ -n "$backup" ]]; then rm -f -- "$backup"; fi
    info 'Pengaturan log query dan reply disimpan.'
}

resolver_settings_status() {
    local mode=root dnssec=yes root_hints=no
    [[ -r "$CONF_FILE" ]] || fail 'unbound.conf belum tersedia.'
    if [[ ! -r "$MANAGED_CONF" ]]; then
        printf '%s\n' \
            'mode=root' 'dnssec=yes' 'root_hints=no' \
            'interfaces=127.0.0.1,::1' 'port=53' \
            'access_control=127.0.0.0/8,::1/128' 'num_threads=2' \
            'msg_cache=128m' 'rrset_cache=256m' 'cache_slabs=2' \
            'cache_min_ttl=60' 'cache_max_ttl=86400' 'prefetch=yes'
        return
    fi
    mode="$(awk '/^# unbound-web-upstream-mode: (root|forward|both)$/ {print $3; exit}' "$MANAGED_CONF")"
    if [[ -z "$mode" ]]; then
        if grep -Eq '^[[:space:]]*forward-zone:' "$MANAGED_CONF"; then
            if grep -Eq '^[[:space:]]*forward-first:[[:space:]]+yes' "$MANAGED_CONF"; then mode=both; else mode=forward; fi
        else
            mode=root
        fi
    fi
    grep -Eq '^[[:space:]]*module-config:[[:space:]]+"validator iterator"' "$MANAGED_CONF" && dnssec=yes
    grep -Eq '^[[:space:]]*module-config:[[:space:]]+"iterator"' "$MANAGED_CONF" && dnssec=no
    grep -Eq '^[[:space:]]*root-hints:' "$MANAGED_CONF" && root_hints=yes
    printf 'mode=%s\ndnssec=%s\nroot_hints=%s\n' "$mode" "$dnssec" "$root_hints"
    awk '
        /^[[:space:]]*interface:[[:space:]]+/ {interfaces = interfaces sep $2; sep = ","}
        /^[[:space:]]*access-control:[[:space:]]+/ {acls = acls asep $2; asep = ","}
        /^[[:space:]]*port:[[:space:]]+/ {port = $2}
        /^[[:space:]]*num-threads:[[:space:]]+/ {threads = $2}
        /^[[:space:]]*msg-cache-size:[[:space:]]+/ {msg = $2}
        /^[[:space:]]*rrset-cache-size:[[:space:]]+/ {rrset = $2}
        /^[[:space:]]*msg-cache-slabs:[[:space:]]+/ {slabs = $2}
        /^[[:space:]]*cache-min-ttl:[[:space:]]+/ {min_ttl = $2}
        /^[[:space:]]*cache-max-ttl:[[:space:]]+/ {max_ttl = $2}
        /^[[:space:]]*prefetch:[[:space:]]+/ {prefetch = $2}
        END {
            print "interfaces=" interfaces
            print "port=" port
            print "access_control=" acls
            print "num_threads=" threads
            print "msg_cache=" msg
            print "rrset_cache=" rrset
            print "cache_slabs=" slabs
            print "cache_min_ttl=" min_ttl
            print "cache_max_ttl=" max_ttl
            print "prefetch=" prefetch
        }
    ' "$MANAGED_CONF"
    awk '/^[[:space:]]*forward-addr:[[:space:]]+/ {print "forwarder=" $2}' "$MANAGED_CONF"
}

verify_resolver() {
    local dns_test dnssec_test dnssec=no target
    [[ -x "$UNBOUND_CHECKCONF" && -f "$CONF_FILE" ]] || fail 'Unbound belum terpasang.'
    systemctl is-active --quiet unbound || fail 'Service Unbound tidak aktif.'
    "$UNBOUND_CHECKCONF" "$CONF_FILE"
    target="$(awk '/^[[:space:]]*interface:[[:space:]]+/ {gsub(/"/, "", $2); sub(/@.*/, "", $2); print $2; exit}' "$MANAGED_CONF" 2>/dev/null || true)"
    case "$target" in ''|0.0.0.0) target=127.0.0.1 ;; ::|::0) target=::1 ;; esac
    dns_test="$(dig "@$target" nlnetlabs.nl A +time=5 +tries=2)" || fail "Query DNS ke $target gagal."
    grep -q 'status: NOERROR' <<< "$dns_test" || fail 'Resolver tidak mengembalikan status NOERROR.'
    [[ "$("$UNBOUND_CHECKCONF" -o module-config "$CONF_FILE")" == *validator* ]] && dnssec=yes
    if [[ "$dnssec" == yes ]]; then
        dnssec_test="$(dig "@$target" nlnetlabs.nl A +dnssec +time=5 +tries=2)" || fail 'Query DNSSEC gagal.'
        grep -Eq 'flags:.*[[:space:]]ad[[:space:];]' <<< "$dnssec_test" || fail 'DNSSEC aktif tetapi jawaban tidak memiliki flag AD.'
    fi
    info "Konfigurasi valid, service aktif, resolusi DNS berhasil, DNSSEC: $dnssec."
}

case "$action" in
    service-status)
        if [[ -x "$UNBOUND_BIN" && ! -e "$wizard_pending_file" ]]; then
            unbound_state="$(service_state unbound "$UNBOUND_BIN")"
            unbound_version="$("$UNBOUND_BIN" -V 2>&1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n 1 || true)"
        else
            unbound_state='not-installed'; unbound_version=''
        fi
        printf 'unbound=%s\n' "$unbound_state"
        printf 'unbound_version=%s\n' "$unbound_version"
        if command -v zabbix_agent2 >/dev/null 2>&1; then
            zabbix_state="$(service_state zabbix-agent2 zabbix_agent2)"
        else
            zabbix_state="$(service_state zabbix-agent zabbix_agentd)"
        fi
        printf 'zabbix=%s\n' "$zabbix_state"
        ;;
    stats)
        [[ -x "$UNBOUND_CONTROL" ]] || fail 'Paket Unbound belum terpasang.'
        "$UNBOUND_CONTROL" -c "$CONF_FILE" stats_noreset
        ;;
    wizard-step-1)
        printf 'OS: %s\n' "${PRETTY_NAME:-unknown}"
        printf 'Arsitektur: %s | CPU: %s core\n' "$(dpkg --print-architecture)" "$(getconf _NPROCESSORS_ONLN)"
        printf 'RAM: %s MB\n' "$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo)"
        printf 'Ruang kosong /var: %s MB\n' "$(df -Pm /var | awk 'NR==2 {print $4}')"
        prepare_wizard
        info 'Persiapan sistem selesai.'
        ;;
    wizard-step-2)
        postinstall_unbound
        ;;
    wizard-rollback)
        info 'Wizard mempertahankan konfigurasi DNS sistem.'
        ;;
    app-update)
        exec /usr/bin/bash "$APP_DIR/update.sh"
        ;;
    system-dns-status)
        (($# == 0)) || fail 'Aksi ini tidak menerima argumen.'
        system_dns_status
        ;;
    system-dns-set)
        system_dns_set "$@"
        ;;
    apply-config)
        (($# == 14)) || fail 'Parameter konfigurasi tidak lengkap.'
        lock_unbound_config
        prior_config="$(mktemp /etc/unbound/unbound.conf.d/50-unbound-web.rollback.XXXXXX)"
        if [[ -f "$MANAGED_CONF" ]]; then
            cp -a -- "$MANAGED_CONF" "$prior_config"
        else
            rm -f -- "$prior_config"
            prior_config=''
        fi
        make_config "$@"
        if ! systemctl reload unbound; then
            if [[ -n "$prior_config" ]]; then
                install -o root -g unbound -m 0640 "$prior_config" "$MANAGED_CONF"
                systemctl reload unbound || true
            else
                rm -f -- "$MANAGED_CONF"
                systemctl reload unbound || true
            fi
            [[ -n "$prior_config" ]] && rm -f -- "$prior_config"
            fail 'Reload gagal. Konfigurasi sebelumnya dipulihkan.'
        fi
        [[ -n "$prior_config" ]] && rm -f -- "$prior_config"
        systemctl is-active --quiet unbound || fail 'Service Unbound tidak aktif setelah reload.'
        info 'Konfigurasi divalidasi dan service berhasil di-reload.'
        ;;
    resolver-settings-status)
        resolver_settings_status
        ;;
    resolver-verify)
        verify_resolver
        ;;
    view-config)
        [[ -r "$CONF_FILE" ]] || fail 'unbound.conf belum tersedia.'
        printf '# %s\n' "$CONF_FILE"
        cat -- "$CONF_FILE"
        for config_part in "$MANAGED_CONF" "$BLOCKLIST_CONF" /etc/unbound/unbound.conf.d/99-unbound-web-logging.conf; do
            if [[ -r "$config_part" ]]; then printf '\n# %s\n' "$config_part"; cat -- "$config_part"; fi
        done
        ;;
    unbound-logging-status)
        [[ -x "$UNBOUND_CHECKCONF" && -f "$CONF_FILE" ]] || fail 'Unbound belum terpasang.'
        queries="$("$UNBOUND_CHECKCONF" -o log-queries "$CONF_FILE")"
        replies="$("$UNBOUND_CHECKCONF" -o log-replies "$CONF_FILE")"
        printf '%s %s\n' "$queries" "$replies"
        ;;
    unbound-logging-set)
        (($# == 2)) || fail 'Pengaturan log belum lengkap.'
        set_unbound_logging "$@"
        ;;
    update-blocklists)
        lock_unbound_config
        [[ -f "$APP_DIR/bin/blocklist-sync.php" ]] || fail 'Script sinkronisasi blocklist tidak ditemukan.'
        php8.4 "$APP_DIR/bin/blocklist-sync.php"
        ;;
    update-root-hints)
        lock_unbound_config
        update_root_hints
        ;;
    blocklist-schedule)
        (($# == 1)) || fail 'Jadwal tidak lengkap.'
        set_schedule "$1"
        ;;
    zabbix-install|zabbix-config)
        (($# == 3)) || fail 'Parameter Zabbix tidak lengkap.'
        configure_zabbix "$1" "$2" "$3"
        ;;
    zabbix-service)
        (($# == 1)) || fail 'Aksi service tidak lengkap.'
        service="$(zabbix_unit)"
        case "$1" in
            start|stop|restart) systemctl "$1" "$service" ;;
            enable|disable) systemctl "$1" --now "$service" ;;
            *) fail 'Aksi service tidak diizinkan.' ;;
        esac
        ;;
    zabbix-boot-status)
        service="$(zabbix_unit)"
        systemctl is-enabled "$service" || true
        ;;
    firewall-list|firewall-enable|firewall-disable|firewall-add|firewall-update|firewall-delete)
        exec /usr/bin/php8.4 "$APP_DIR/bin/firewall.php" "${action#firewall-}" "$@"
        ;;
    cron-view)
        [[ -r "$CRON_FILE" ]] && cat "$CRON_FILE" || info 'File cron aplikasi belum ditemukan.'
        [[ -r "$CUSTOM_CRON_FILE" ]] && cat "$CUSTOM_CRON_FILE" || true
        ;;
    cron-custom-list)
        if [[ -r "$CUSTOM_CRON_FILE" ]]; then
            awk '/^# unbound-web-id:/ {id=substr($0,18); getline; if (NF>=10) print id "|" $1 "|" $2 "|" $3 "|" $4 "|" $5 "|" $8}' "$CUSTOM_CRON_FILE"
        fi
        ;;
    cron-custom-save)
        (($# == 7)) || fail 'Cron custom memerlukan ID, lima field jadwal, dan nama tugas.'
        save_custom_cron "$@"
        ;;
    cron-custom-delete)
        (($# == 1)) || fail 'ID cron tidak lengkap.'
        delete_custom_cron "$1"
        ;;
    dnsperf)
        (($# == 6)) || fail 'Parameter dnsperf tidak lengkap.'
        target="$1"; port="$2"; duration="$3"; clients="$4"; threads="$5"; qps="$6"
        valid_private_ipv4 "$target" || fail 'Target dibatasi ke IP IPv4 loopback atau private.'
        valid_port "$port" || fail 'Port target tidak valid.'
        valid_uint "$duration" && ((10#$duration >= 1 && 10#$duration <= 300)) || fail 'Durasi maksimum pengujian adalah 300 detik.'
        valid_uint "$clients" || fail 'Jumlah client harus berupa bilangan bulat positif.'
        valid_uint "$threads" || fail 'Jumlah thread harus berupa bilangan bulat positif.'
        [[ "$qps" == 0 ]] || valid_uint "$qps" || fail 'QPS harus 0 atau bilangan bulat positif.'
        php8.4 "$APP_DIR/bin/dnsperf-run.php" "$target" "$port" "$duration" "$clients" "$threads" "$qps"
        ;;
    *)
        fail 'Aksi management tidak dikenal.'
        ;;
esac
