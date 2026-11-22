#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR='/opt/unbound-web'
CONF_FILE='/etc/unbound/unbound.conf'
BLOCKLIST_CONF='/etc/unbound/unbound.conf.d/90-unbound-web-blocklist.conf'
CRON_FILE='/etc/cron.d/unbound-web'
CUSTOM_CRON_FILE='/etc/cron.d/unbound-web-managed'
LOG_DIR='/var/log/unbound-web'

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

resolver_state_dir='/var/lib/unbound-web/root-state'
resolver_state_file="$resolver_state_dir/resolver-state"
resolver_backup_file="$resolver_state_dir/resolv.conf.backup"
wizard_pending_file="$resolver_state_dir/wizard-pending"

rollback_resolver() {
    [[ -r "$resolver_state_file" ]] || { info 'Tidak ada kondisi resolver sementara untuk dipulihkan.'; return 0; }
    # This file is root-owned and only contains shell-escaped values captured by this script.
    # shellcheck disable=SC1090
    . "$resolver_state_file"
    rm -f /etc/resolv.conf
    if [[ "${resolver_was_symlink:-no}" == yes && -n "${resolver_link_target:-}" ]]; then
        ln -s -- "$resolver_link_target" /etc/resolv.conf
    elif [[ "${resolver_had_file:-no}" == yes && -s "$resolver_backup_file" ]]; then
        install -o root -g root -m 0644 "$resolver_backup_file" /etc/resolv.conf
    fi
    if [[ "${resolver_was_enabled:-no}" == yes ]]; then systemctl enable systemd-resolved >/dev/null 2>&1 || true; fi
    if [[ "${resolver_was_active:-no}" == yes ]]; then systemctl start systemd-resolved >/dev/null 2>&1 || true; fi
    rm -f "$resolver_state_file"
    info 'Resolver sistem dan status systemd-resolved dipulihkan.'
}

prepare_resolver() {
    install -d -o root -g root -m 0700 "$resolver_state_dir"
    install -o root -g root -m 0600 /dev/null "$wizard_pending_file"
    local was_active=no was_enabled=no was_symlink=no link_target='' had_file=no upstream=''
    if [[ -r "$resolver_state_file" ]]; then
        # Resume an interrupted wizard without replacing its original resolver backup.
        # shellcheck disable=SC1090
        . "$resolver_state_file"
        was_active="${resolver_was_active:-no}"
        upstream="${resolver_upstream:-}"
    else
        systemctl is-active --quiet systemd-resolved && was_active=yes || true
        systemctl is-enabled --quiet systemd-resolved && was_enabled=yes || true
        if [[ -L /etc/resolv.conf ]]; then
            was_symlink=yes
            link_target="$(readlink /etc/resolv.conf)"
        fi
        if [[ -e /etc/resolv.conf ]]; then
            had_file=yes
            cp -L -- /etc/resolv.conf "$resolver_backup_file"
            chmod 0600 "$resolver_backup_file"
        fi
        upstream="$(awk '/^nameserver[[:space:]]+/ && $2 !~ /^127\./ && $2 != "::1" {print $2; exit}' \
            /etc/resolv.conf 2>/dev/null || true)"
        if [[ -z "$upstream" && "$was_active" == yes ]]; then
            upstream="$(awk '/^nameserver[[:space:]]+/ && $2 !~ /^127\./ && $2 != "::1" {print $2; exit}' \
                /run/systemd/resolve/resolv.conf 2>/dev/null || true)"
        fi
        {
            printf 'resolver_was_active=%q\n' "$was_active"
            printf 'resolver_was_enabled=%q\n' "$was_enabled"
            printf 'resolver_was_symlink=%q\n' "$was_symlink"
            printf 'resolver_link_target=%q\n' "$link_target"
            printf 'resolver_had_file=%q\n' "$had_file"
            printf 'resolver_upstream=%q\n' "$upstream"
        } > "$resolver_state_file"
        chmod 0600 "$resolver_state_file"
    fi

    printf 'Listener port 53 sebelum perubahan:\n'
    ss -lntup 2>/dev/null | awk 'NR==1 || /:53([[:space:]]|$)/' || true
    if systemctl is-active --quiet systemd-resolved 2>/dev/null; then
        info 'Menghentikan systemd-resolved agar Unbound dapat memakai port 53.'
        systemctl stop systemd-resolved
        systemctl disable systemd-resolved >/dev/null 2>&1 || true
    fi
    if ss -H -lntu 2>/dev/null | grep -Eq '(^|[[:space:]])[^[:space:]]*:53([[:space:]]|$)'; then
        fail 'Port 53 masih digunakan proses lain. Hentikan service tersebut sebelum melanjutkan.'
    fi

    if [[ -z "$upstream" ]]; then
        upstream="$(awk '/^nameserver[[:space:]]+/ && $2 !~ /^127\./ && $2 != "::1" {print $2; exit}' "$resolver_backup_file" 2>/dev/null || true)"
    fi
    [[ -n "$upstream" ]] || upstream='1.1.1.1'
    rm -f /etc/resolv.conf
    printf 'nameserver %s\noptions timeout:2 attempts:2\n' "$upstream" > /etc/resolv.conf
    chmod 0644 /etc/resolv.conf
    getent ahosts nlnetlabs.nl >/dev/null || fail 'Resolusi internet dengan DNS upstream gagal. Resolver sebelumnya akan dipulihkan.'
    info "Resolver sementara memakai $upstream dan akses DNS internet berhasil."
}

download_source() {
    local source_dir='/usr/local/src/unbound' archive temp_dir entry
    install -d -o root -g root -m 0755 /usr/local/src
    archive="$(mktemp /tmp/unbound-latest.XXXXXX.tar.gz)"
    temp_dir="$(mktemp -d /usr/local/src/unbound-build.XXXXXX)"
    curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        'https://nlnetlabs.nl/downloads/unbound/unbound-latest.tar.gz' --output "$archive"
    [[ -s "$archive" ]] || fail 'Arsip source Unbound kosong.'
    tar -tzf "$archive" > "$temp_dir.archive-list"
    while IFS= read -r entry; do
        [[ "$entry" != /* && ! "$entry" =~ (^|/)\.\.(/|$) ]] || fail 'Arsip source berisi path yang tidak aman.'
    done < "$temp_dir.archive-list"
    rm -f "$temp_dir.archive-list"
    tar -xzf "$archive" --strip-components=1 -C "$temp_dir"
    [[ -x "$temp_dir/configure" ]] || fail 'Script configure tidak ditemukan dalam arsip.'
    if [[ -d "$source_dir" ]]; then mv -- "$source_dir" "${source_dir}.backup.$(date +%Y%m%d%H%M%S)"; fi
    mv -- "$temp_dir" "$source_dir"
    rm -f -- "$archive"
    info "Source NLnet Labs diekstrak ke $source_dir."
}

compile_source() {
    local source_dir='/usr/local/src/unbound' cores
    [[ -x "$source_dir/configure" ]] || fail 'Source belum diunduh. Jalankan tahap download terlebih dahulu.'
    cores="$(nproc 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null || printf '1')"
    [[ "$cores" =~ ^[1-9][0-9]*$ ]] || cores=1
    info "Build Unbound memakai $cores core CPU."
    (
        cd -- "$source_dir"
        ./configure --with-libevent --with-pthreads --with-ssl --sysconfdir=/etc/unbound --prefix=/usr/local
        info 'Konfigurasi build selesai.'
        make -j "$cores"
        make install
    )
    ldconfig
    for tool in unbound unbound-control unbound-checkconf unbound-anchor unbound-control-setup; do
        [[ -x "/usr/local/sbin/$tool" ]] || fail "Binary hasil build tidak ditemukan: /usr/local/sbin/$tool"
    done
    info "Build selesai: $(/usr/local/sbin/unbound -V 2>&1 | head -n 1)"
}

valid_root_hints() {
    grep -Eiq '^\.[[:space:]]+([0-9]+[[:space:]]+)?(IN[[:space:]]+)?NS[[:space:]]+[^[:space:]]+' "$1"
}

postinstall_unbound() {
    local hints_tmp anchor_status=0 interfaces='127.0.0.1' acls='127.0.0.0/8'
    getent group unbound >/dev/null || groupadd --system unbound
    id unbound >/dev/null 2>&1 || useradd --system --gid unbound --home-dir /var/lib/unbound --no-create-home --shell /usr/sbin/nologin unbound
    install -d -o root -g unbound -m 0750 /etc/unbound /etc/unbound/unbound.conf.d
    install -d -o unbound -g unbound -m 0750 /var/lib/unbound
    hints_tmp="$(mktemp /etc/unbound/root.hints.XXXXXX)"
    if ! curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        'https://www.internic.net/domain/named.root' --output "$hints_tmp"; then
        rm -f "$hints_tmp"; fail 'Unduhan root.hints gagal.'
    fi
    valid_root_hints "$hints_tmp" || { rm -f "$hints_tmp"; fail 'File root.hints tidak valid.'; }
    chown root:unbound "$hints_tmp"
    chmod 0640 "$hints_tmp"
    mv -f -- "$hints_tmp" /etc/unbound/root.hints
    [[ -x /usr/local/sbin/unbound-anchor ]] || fail 'Binary /usr/local/sbin/unbound-anchor tidak tersedia setelah build.'
    /usr/local/sbin/unbound-anchor -a /var/lib/unbound/root.key || anchor_status=$?
    # Status 1 is expected when the built-in or certificate-updated trust anchor is used.
    ((anchor_status <= 1)) && [[ -s /var/lib/unbound/root.key ]] ||
        fail 'unbound-anchor tidak menghasilkan trust anchor DNSSEC.'
    chown unbound:unbound /var/lib/unbound/root.key
    chmod 0640 /var/lib/unbound/root.key
    /usr/local/sbin/unbound-control-setup -d /etc/unbound
    chown unbound:unbound /etc/unbound/unbound_server.key /etc/unbound/unbound_server.pem
    chmod 0640 /etc/unbound/unbound_server.key
    chmod 0644 /etc/unbound/unbound_server.pem
    chown root:unbound /etc/unbound/unbound_control.key /etc/unbound/unbound_control.pem
    chmod 0640 /etc/unbound/unbound_control.key /etc/unbound/unbound_control.pem
    if [[ -n "$(ip -6 -o address show dev lo scope host)" ]]; then
        interfaces+=',::1'
        acls+=',::1/128'
    fi
    make_config "$interfaces" '53' "$acls" '2' '128m' '256m' 'yes' '60' '86400' '2' 'yes'
    cat > /etc/systemd/system/unbound.service <<'UNIT'
[Unit]
Description=Unbound DNS resolver
Documentation=https://unbound.docs.nlnetlabs.nl/
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=unbound
Group=unbound
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
RuntimeDirectory=unbound
RuntimeDirectoryMode=0750
ExecStart=/usr/local/sbin/unbound -d -c /etc/unbound/unbound.conf
ExecReload=/usr/local/sbin/unbound-control -c /etc/unbound/unbound.conf reload
Restart=on-failure
RestartSec=2s
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=/var/lib/unbound /run/unbound

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    info 'Root hints, DNSSEC trust anchor, TLS control keys, konfigurasi dasar, dan unit systemd siap.'
}

verify_unbound() (
    local dns_test='' dnssec_test='' attempt
    [[ -r "$resolver_state_file" ]] || fail 'Mulai pemeriksaan dari tahap persiapan wizard.'
    /usr/local/sbin/unbound-checkconf "$CONF_FILE"
    trap 'if [[ -r "$resolver_state_file" ]]; then systemctl disable --now unbound >/dev/null 2>&1 || true; fi' EXIT
    systemctl enable --now unbound
    for attempt in {1..6}; do
        dns_test="$(dig @127.0.0.1 nlnetlabs.nl +time=3 +tries=1 +short)" || dns_test=''
        [[ -n "$dns_test" ]] && break
        sleep 1
    done
    /usr/local/sbin/unbound-control -c "$CONF_FILE" status
    [[ -n "$dns_test" ]] || fail 'Uji resolusi lokal gagal.'
    dnssec_test="$(dig @127.0.0.1 nlnetlabs.nl +dnssec +time=5 +tries=2)"
    grep -Eq 'flags:.*[[:space:]]ad[[:space:];]' <<< "$dnssec_test" || fail 'Validasi DNSSEC tidak menghasilkan bit AD.'
    printf 'nameserver 127.0.0.1\noptions timeout:2 attempts:2\n' > /etc/resolv.conf
    chmod 0644 /etc/resolv.conf
    getent ahosts nlnetlabs.nl >/dev/null || fail 'Unbound menjawab query lokal, tetapi resolver sistem gagal.'
    rm -f "$resolver_state_file" "$wizard_pending_file"
    info 'Unbound, query DNS lokal, DNSSEC, dan resolver sistem berhasil diverifikasi.'
)

make_config() {
    local interfaces_csv="$1" port="$2" acl_csv="$3" threads="$4" msg_cache="$5" rrset_cache="$6" prefetch="$7" min_ttl="$8" max_ttl="$9" slabs="${10}" harden="${11}" value
    local -a interfaces=() acls=()
    IFS=',' read -r -a interfaces <<< "$interfaces_csv"
    IFS=',' read -r -a acls <<< "$acl_csv"
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
    [[ "$harden" == yes || "$harden" == no ]] || fail 'harden-dnssec-stripped hanya menerima yes atau no.'

    local temp_file backup_file
    install -d -o root -g unbound -m 0750 /etc/unbound /etc/unbound/unbound.conf.d
    install -d -o unbound -g unbound -m 0750 /var/lib/unbound
    temp_file="$(mktemp /etc/unbound/unbound.conf.XXXXXX)"
    {
        cat <<'CONF'
server:
    verbosity: 0
    username: "unbound"
    chroot: ""
    directory: "/etc/unbound"
    pidfile: "/run/unbound/unbound.pid"
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
    prefetch-key: yes
    statistics-cumulative: yes
    extended-statistics: yes
    qname-minimisation: yes
    harden-dnssec-stripped: $harden
    hide-identity: yes
    hide-version: yes
    minimal-responses: yes
    edns-buffer-size: 1232
    root-hints: "/etc/unbound/root.hints"
CONF
        if ! grep -RqsE '^[[:space:]]*auto-trust-anchor-file:' /etc/unbound/unbound.conf.d --include='*.conf' 2>/dev/null; then
            printf '    auto-trust-anchor-file: "/var/lib/unbound/root.key"\n'
        fi
        printf '    include: "/etc/unbound/unbound.conf.d/*.conf"\n'
        cat <<'CONF'

remote-control:
    control-enable: yes
    control-interface: 127.0.0.1
    control-use-cert: yes
    server-key-file: "/etc/unbound/unbound_server.key"
    server-cert-file: "/etc/unbound/unbound_server.pem"
    control-key-file: "/etc/unbound/unbound_control.key"
    control-cert-file: "/etc/unbound/unbound_control.pem"
CONF
    } > "$temp_file"

    if ! /usr/local/sbin/unbound-checkconf "$temp_file"; then
        rm -f -- "$temp_file"
        fail 'unbound-checkconf menolak konfigurasi. File aktif tidak diubah.'
    fi
    if [[ -e "$CONF_FILE" ]]; then
        backup_file="$CONF_FILE.web-backup.$(date +%Y%m%d%H%M%S)"
        cp -a -- "$CONF_FILE" "$backup_file"
        info "Cadangan konfigurasi dibuat: $backup_file"
    fi
    install -o root -g unbound -m 0640 "$temp_file" "$CONF_FILE"
    rm -f -- "$temp_file"
    /usr/local/sbin/unbound-checkconf "$CONF_FILE"
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
    [[ -x /usr/local/sbin/unbound-checkconf && -f "$CONF_FILE" ]] || fail 'Unbound belum terpasang.'
    [[ -s /etc/unbound/root.hints ]] || fail 'Root hints aktif tidak ditemukan; tidak ada file untuk dipulihkan.'
    temp="$(mktemp /etc/unbound/root.hints.XXXXXX)"
    backup="$(mktemp /etc/unbound/root.hints.previous.XXXXXX)"
    if ! curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 'https://www.internic.net/domain/named.root' --output "$temp"; then
        rm -f "$temp" "$backup"; fail 'Unduhan root.hints gagal.'
    fi
    valid_root_hints "$temp" || { rm -f "$temp" "$backup"; fail 'File root.hints tidak valid.'; }
    if ! cp -a /etc/unbound/root.hints "$backup"; then
        rm -f "$temp" "$backup"; fail 'Cadangan root.hints tidak dapat dibuat.'
    fi
    chown root:unbound "$temp"
    chmod 0640 "$temp"
    mv -f -- "$temp" /etc/unbound/root.hints
    if ! /usr/local/sbin/unbound-checkconf "$CONF_FILE" || ! systemctl reload unbound; then
        mv -f -- "$backup" /etc/unbound/root.hints
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
    local dir='/etc/unbound/unbound.conf.d' check='/usr/local/sbin/unbound-checkconf'
    local target="$dir/99-unbound-web-logging.conf"
    [[ "$queries" == yes || "$queries" == no ]] || fail 'Nilai log query tidak valid.'
    [[ "$replies" == yes || "$replies" == no ]] || fail 'Nilai log reply tidak valid.'
    [[ -x "$check" && -f "$CONF_FILE" && -d "$dir" ]] || fail 'Selesaikan instalasi Unbound terlebih dahulu.'
    lock_unbound_config
    if [[ "$queries" == yes || "$replies" == yes ]]; then tag=yes; fi
    temp="$(mktemp "$dir/.logging.XXXXXX")"
    printf '    log-queries: %s\n    log-replies: %s\n    log-tag-queryreply: %s\n' "$queries" "$replies" "$tag" > "$temp"
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

case "$action" in
    service-status)
        if [[ -x /usr/local/sbin/unbound && ! -e "$wizard_pending_file" ]]; then
            unbound_state="$(service_state unbound /usr/local/sbin/unbound)"
            unbound_version="$(/usr/local/sbin/unbound -V 2>&1 | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n 1 || true)"
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
        [[ -x /usr/local/sbin/unbound-control ]] || fail 'Unbound dari source belum terpasang.'
        /usr/local/sbin/unbound-control -c "$CONF_FILE" stats_noreset
        ;;
    wizard-step-1)
        if [[ -x /usr/local/sbin/unbound ]] && systemctl is-active --quiet unbound; then
            fail 'Unbound sudah aktif. Kelola konfigurasi dari Settings; wizard tidak perlu dijalankan ulang.'
        fi
        printf 'OS: %s\n' "${PRETTY_NAME:-unknown}"
        printf 'Arsitektur: %s | CPU: %s core\n' "$(dpkg --print-architecture)" "$(getconf _NPROCESSORS_ONLN)"
        printf 'RAM: %s MB\n' "$(awk '/^MemTotal:/ {print int($2/1024)}' /proc/meminfo)"
        printf 'Ruang kosong /var: %s MB\n' "$(df -Pm /var | awk 'NR==2 {print $4}')"
        command -v apt-get >/dev/null && command -v systemctl >/dev/null && command -v curl >/dev/null && command -v make >/dev/null || fail 'Dependensi build belum tersedia. Jalankan install.sh terlebih dahulu.'
        prepare_resolver
        info 'Persiapan sistem selesai.'
        ;;
    wizard-step-2)
        download_source
        ;;
    wizard-step-3)
        compile_source
        ;;
    wizard-step-4)
        postinstall_unbound
        ;;
    wizard-step-5)
        verify_unbound
        ;;
    wizard-rollback)
        rollback_resolver
        ;;
    apply-config)
        (($# == 11)) || fail 'Parameter konfigurasi tidak lengkap.'
        lock_unbound_config
        prior_config="$(mktemp /etc/unbound/unbound.conf.previous.XXXXXX)"
        if [[ -f "$CONF_FILE" ]]; then
            cp -a -- "$CONF_FILE" "$prior_config"
        else
            rm -f -- "$prior_config"
            prior_config=''
        fi
        make_config "$@"
        if ! systemctl reload unbound; then
            if [[ -n "$prior_config" ]]; then
                install -o root -g unbound -m 0640 "$prior_config" "$CONF_FILE"
                systemctl reload unbound || true
            fi
            [[ -n "$prior_config" ]] && rm -f -- "$prior_config"
            fail 'Reload gagal. Konfigurasi sebelumnya dipulihkan.'
        fi
        [[ -n "$prior_config" ]] && rm -f -- "$prior_config"
        systemctl is-active --quiet unbound || fail 'Service Unbound tidak aktif setelah reload.'
        info 'Konfigurasi divalidasi dan service berhasil di-reload.'
        ;;
    view-config)
        [[ -r "$CONF_FILE" ]] || fail 'unbound.conf belum tersedia.'
        cat -- "$CONF_FILE"
        ;;
    unbound-logging-status)
        [[ -x /usr/local/sbin/unbound-checkconf && -f "$CONF_FILE" ]] || fail 'Unbound belum terpasang.'
        queries="$(/usr/local/sbin/unbound-checkconf -o log-queries "$CONF_FILE")"
        replies="$(/usr/local/sbin/unbound-checkconf -o log-replies "$CONF_FILE")"
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
