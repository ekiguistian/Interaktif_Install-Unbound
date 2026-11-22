#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME='Instalasi Unbound DNS Server Interaktif'
OUTPUT_DIR="$(pwd)"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
CONF_TMP=''
BLOCKLIST_CONF_BACKUP=''
BLOCKLIST_SOURCES_BACKUP=''
ZABBIX_CONF_BACKUP=''
ZABBIX_SUDOERS_BACKUP=''
UNBOUND_CONFIG_BACKUP=''
UNBOUND_CONFIG_EXISTED='no'
APT_INDEX_UPDATED='no'
RESOLV_CONF_BACKUP=''

trap 'if [[ -n "${CONF_TMP:-}" && -f "$CONF_TMP" ]]; then rm -f -- "$CONF_TMP"; fi' EXIT
on_error() {
    local code=$? line="${BASH_LINENO[0]:-unknown}"
    error "Proses berhenti pada baris $line (exit $code). Periksa pesan di atas sebelum menjalankan ulang."
    exit "$code"
}
trap on_error ERR

info() { printf '\n%s\n' "$*"; }
warn() { printf 'PERINGATAN: %s\n' "$*" >&2; }
error() { printf 'ERROR: %s\n' "$*" >&2; }

run_as_root() {
    if (( EUID == 0 )); then
        "$@"
    elif command -v sudo >/dev/null 2>&1; then
        sudo "$@"
    else
        error 'Perlu akses root atau sudo untuk memasang dependensi dan mengatur UFW.'
        return 1
    fi
}

refresh_apt_indexes() {
    if ! command -v apt-get >/dev/null 2>&1; then
        error 'Package manager APT tidak ditemukan. Installer ini ditujukan untuk Ubuntu dan Debian.'
        return 1
    fi
    info 'Memperbarui indeks paket APT...'
    run_as_root apt-get update
    APT_INDEX_UPDATED='yes'
}

install_packages() {
    local packages=("$@")
    if [[ "$APT_INDEX_UPDATED" != yes ]]; then refresh_apt_indexes; fi
    run_as_root apt-get install -y "${packages[@]}"
}

enable_official_zabbix_repository() {
    local repository_package_url='' package_tmp
    case "${ID:-}:${VERSION_ID:-}" in
        ubuntu:24.04) repository_package_url='https://repo.zabbix.com/zabbix/8.0/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest+ubuntu24.04_all.deb' ;;
        ubuntu:26.04) repository_package_url='https://repo.zabbix.com/zabbix/8.0/release/ubuntu/pool/main/z/zabbix-release/zabbix-release_latest+ubuntu26.04_all.deb' ;;
        debian:12) repository_package_url='https://repo.zabbix.com/zabbix/8.0/release/debian/pool/main/z/zabbix-release/zabbix-release_latest_8.0+debian12_all.deb' ;;
        debian:13) repository_package_url='https://repo.zabbix.com/zabbix/8.0/release/debian/pool/main/z/zabbix-release/zabbix-release_latest_8.0+debian13_all.deb' ;;
        *)
            error "Repository Zabbix resmi belum disiapkan otomatis untuk ${PRETTY_NAME:-OS ini}."
            return 1
            ;;
    esac

    if ! command -v curl >/dev/null 2>&1; then
        install_packages curl ca-certificates || return 1
    fi
    package_tmp="$(mktemp "${TMPDIR:-/tmp}/zabbix-release.XXXXXX.deb")"
    info 'Paket Zabbix Agent tidak tersedia di APT sistem; memasang konfigurasi repository resmi Zabbix.'
    if ! curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
        "$repository_package_url" --output "$package_tmp"; then
        rm -f -- "$package_tmp"
        error 'Gagal mengunduh paket repository resmi Zabbix melalui HTTPS.'
        return 1
    fi
    if ! run_as_root dpkg -i "$package_tmp"; then
        rm -f -- "$package_tmp"
        error 'Gagal memasang konfigurasi repository resmi Zabbix.'
        return 1
    fi
    rm -f -- "$package_tmp"
    APT_INDEX_UPDATED='no'
    refresh_apt_indexes || return 1
}

install_dependencies() {
    local missing=() zabbix_was_installed=no

    if ! command -v apt-get >/dev/null 2>&1; then
        error 'APT tidak tersedia; gunakan Ubuntu Server atau Debian yang didukung.'
        return 1
    fi
    if (( EUID != 0 )); then
        if ! command -v sudo >/dev/null 2>&1; then
            error 'sudo belum tersedia dan pengguna saat ini bukan root. Jalankan sekali sebagai root agar sudo dapat dipasang.'
            return 1
        fi
        info 'Memvalidasi hak sudo sebelum memeriksa dependensi...'
        sudo -v || { error 'Hak sudo tidak tersedia. Jalankan sebagai root atau pengguna sudo.'; return 1; }
    fi
    if command -v zabbix_agentd >/dev/null 2>&1 || [[ -x /usr/sbin/zabbix_agentd ]]; then
        zabbix_was_installed=yes
    fi

    refresh_apt_indexes || return 1

    # Zabbix Agent is mandatory. Ubuntu packages it in Universe on supported releases.
    if [[ "${ID:-}" == ubuntu ]] && ! apt-cache show zabbix-agent >/dev/null 2>&1; then
        info 'Paket zabbix-agent belum terlihat di APT; mengaktifkan komponen Ubuntu Universe.'
        if ! command -v add-apt-repository >/dev/null 2>&1; then
            install_packages software-properties-common || warn 'Komponen Universe tidak dapat diaktifkan; installer akan mencoba repository resmi Zabbix.'
        fi
        if command -v add-apt-repository >/dev/null 2>&1 && run_as_root add-apt-repository --yes universe; then
            APT_INDEX_UPDATED='no'
            refresh_apt_indexes || return 1
        else
            warn 'Universe tidak tersedia atau gagal diperbarui; installer akan mencoba repository resmi Zabbix.'
        fi
    fi
    if ! apt-cache show zabbix-agent >/dev/null 2>&1; then
        enable_official_zabbix_repository || return 1
    fi
    if ! apt-cache show zabbix-agent >/dev/null 2>&1; then
        error 'Paket zabbix-agent tetap tidak tersedia setelah repository resmi diperiksa.'
        return 1
    fi

    if ! command -v unbound-checkconf >/dev/null 2>&1 ||
        ! command -v unbound-anchor >/dev/null 2>&1 ||
        ! command -v unbound-control-setup >/dev/null 2>&1; then missing+=(unbound); fi
    command -v python3 >/dev/null 2>&1 || missing+=(python3)
    command -v ufw >/dev/null 2>&1 || missing+=(ufw)
    command -v dig >/dev/null 2>&1 || missing+=(dnsutils)
    command -v sudo >/dev/null 2>&1 || missing+=(sudo)
    command -v git >/dev/null 2>&1 || missing+=(git)
    command -v curl >/dev/null 2>&1 || missing+=(curl)
    [[ -x /usr/sbin/zabbix_agentd ]] || command -v zabbix_agentd >/dev/null 2>&1 || missing+=(zabbix-agent)
    [[ -s /usr/share/dns/root.hints || -s /usr/share/unbound/root.hints ]] || missing+=(dns-root-data)

    if ((${#missing[@]})); then
        info "Dependensi wajib yang akan dipasang: ${missing[*]}"
        install_packages "${missing[@]}" || return 1
    else
        info 'Semua dependensi wajib sudah tersedia.'
    fi

    command -v unbound-checkconf >/dev/null 2>&1 || { error 'Unbound belum berhasil dipasang.'; return 1; }
    command -v unbound-anchor >/dev/null 2>&1 || { error 'unbound-anchor belum tersedia.'; return 1; }
    command -v unbound-control-setup >/dev/null 2>&1 || { error 'unbound-control-setup belum tersedia.'; return 1; }
    command -v python3 >/dev/null 2>&1 || { error 'Python 3 belum berhasil dipasang.'; return 1; }
    command -v ufw >/dev/null 2>&1 || { error 'UFW belum berhasil dipasang.'; return 1; }
    command -v dig >/dev/null 2>&1 || { error 'dig dari paket dnsutils belum tersedia.'; return 1; }
    command -v sudo >/dev/null 2>&1 || { error 'sudo belum berhasil dipasang.'; return 1; }
    command -v git >/dev/null 2>&1 || { error 'git belum berhasil dipasang.'; return 1; }
    command -v curl >/dev/null 2>&1 || { error 'curl belum berhasil dipasang.'; return 1; }
    [[ -x /usr/sbin/zabbix_agentd || $(command -v zabbix_agentd 2>/dev/null || true) ]] || { error 'Zabbix Agent belum berhasil dipasang.'; return 1; }
    [[ -s /usr/share/dns/root.hints || -s /usr/share/unbound/root.hints ]] || { error 'File root hints dari dns-root-data tidak ditemukan.'; return 1; }

    # A newly installed agent must not run with its package-default localhost settings.
    if [[ "$zabbix_was_installed" == no ]]; then
        run_as_root systemctl disable --now zabbix-agent >/dev/null 2>&1 || true
    fi
    info 'Pemeriksaan seluruh dependensi wajib selesai.'
}

check_supported_os() {
    local os_id os_version
    if [[ ! -r /etc/os-release ]]; then
        error 'File /etc/os-release tidak ditemukan.'
        exit 1
    fi
    # shellcheck disable=SC1091
    . /etc/os-release
    os_id="${ID:-}"
    os_version="${VERSION_ID:-}"
    if ! command -v systemctl >/dev/null 2>&1; then
        error 'systemctl tidak ditemukan; installer memerlukan systemd.'
        exit 1
    fi
    case "$os_id" in
        ubuntu)
            if ! dpkg --compare-versions "$os_version" ge '24.04'; then
                error "OS anda dibawah minimum spesifikasi: Ubuntu $os_version. Perbarui terlebih dahulu ke Ubuntu Server 24.04 atau lebih baru."
                exit 1
            fi
            ;;
        debian)
            if ! dpkg --compare-versions "$os_version" ge '12'; then
                error "OS anda dibawah minimum spesifikasi: Debian $os_version. Perbarui terlebih dahulu ke Debian 12 atau lebih baru."
                exit 1
            fi
            ;;
        *)
        error "OS anda dibawah minimum spesifikasi atau belum didukung ('$os_id'). Perbarui terlebih dahulu atau gunakan Ubuntu Server 24.04+ atau Debian 12+."
            exit 1
            ;;
    esac
    printf 'OS terdeteksi: %s %s\n' "$PRETTY_NAME" "$os_version"
}

check_minimum_host() {
    local cpu_count memory_kb memory_mb var_free_kb minimum_disk_kb=1048576
    cpu_count="$(getconf _NPROCESSORS_ONLN 2>/dev/null || awk '/^processor[[:space:]]*:/ { count++ } END { print count+0 }' /proc/cpuinfo)"
    memory_kb="$(awk '/^MemTotal:/ { print $2; exit }' /proc/meminfo)"
    var_free_kb="$(df -Pk /var | awk 'NR == 2 { print $4 }')"

    if [[ ! "$cpu_count" =~ ^[0-9]+$ || ! "$memory_kb" =~ ^[0-9]+$ || ! "$var_free_kb" =~ ^[0-9]+$ ]]; then
        error 'Tidak dapat membaca jumlah CPU, RAM, atau ruang kosong /var untuk pemeriksaan minimum.'
        return 1
    fi
    memory_mb=$((memory_kb / 1024))

    printf 'Resource host terdeteksi: CPU %s core, RAM %s MB, ruang kosong /var %s MB.\n' \
        "$cpu_count" "$memory_mb" "$((var_free_kb / 1024))"

    if (( cpu_count < 1 || memory_mb < 512 || var_free_kb < minimum_disk_kb )); then
        error 'Spesifikasi sistem di bawah minimum. Perbarui terlebih dahulu: minimal 1 CPU, RAM 512 MB, dan ruang kosong 1 GB pada /var.'
        return 1
    fi
}

ask_choice() {
    local prompt="$1"; shift
    local allowed=("$@") answer choice
    while true; do
        read -r -p "$prompt " answer
        for choice in "${allowed[@]}"; do
            [[ "$answer" == "$choice" ]] && { printf '%s' "$answer"; return; }
        done
        printf 'Pilihan tidak valid. Pilih: %s\n' "${allowed[*]}" >&2
    done
}

ask_default() {
    local prompt="$1" default="$2" answer
    read -r -p "$prompt [default: $default]: " answer
    printf '%s' "${answer:-$default}"
}

ask_integer() {
    local prompt="$1" minimum="$2" maximum="$3" default="${4:-}" answer number
    while true; do
        if [[ -n "$default" ]]; then
            read -r -p "$prompt [$default]: " answer
            answer="${answer:-$default}"
        else
            read -r -p "$prompt: " answer
        fi
        if [[ "$answer" =~ ^[0-9]+$ ]]; then
            number=$((10#$answer))
            if ((number >= minimum && number <= maximum)); then
                printf '%s' "$number"
                return
            fi
        fi
        printf 'Masukkan angka %s sampai %s.\n' "$minimum" "$maximum" >&2
    done
}

valid_ipv4() {
    local ip="$1" octet
    local -a parts
    IFS='.' read -r -a parts <<< "$ip"
    ((${#parts[@]} == 4)) || return 1
    for octet in "${parts[@]}"; do
        [[ "$octet" =~ ^[0-9]{1,3}$ ]] || return 1
        ((10#$octet <= 255)) || return 1
    done
}

valid_ip() {
    python3 -c 'import ipaddress,sys; ipaddress.ip_address(sys.argv[1])' "$1" >/dev/null 2>&1
}

valid_ip_or_cidr() {
    python3 -c 'import ipaddress,sys; ipaddress.ip_network(sys.argv[1], strict=False)' "$1" >/dev/null 2>&1
}

ask_interfaces() {
    local raw item value valid
    local -a items
    INTERFACE_IPS=()
    while true; do
        raw="$(ask_default 'IP Interface IPv4/IPv6 (pisahkan beberapa dengan koma)' '127.0.0.1,10.10.10.10')"
        raw="${raw//;/,}"
        IFS=',' read -r -a items <<< "$raw"
        valid=1
        INTERFACE_IPS=()
        for item in "${items[@]}"; do
            value="$(xargs <<< "$item")"
            if ! valid_ip "$value"; then valid=0; break; fi
            [[ " ${INTERFACE_IPS[*]} " == *" $value "* ]] || INTERFACE_IPS+=("$value")
        done
        if [[ " ${INTERFACE_IPS[*]} " != *' 127.0.0.1 '* ]]; then
            INTERFACE_IPS=(127.0.0.1 "${INTERFACE_IPS[@]}")
        fi
        if ((valid)) && ((${#INTERFACE_IPS[@]} > 0)); then return; fi
        printf 'Masukkan satu atau beberapa alamat IPv4/IPv6 yang valid.\n' >&2
    done
}

ask_access_networks() {
    local raw item value network valid
    local -a items parts
    ACCESS_NETWORKS=()
    while true; do
        raw="$(ask_default 'IP Allow akses / CIDR (pisahkan beberapa dengan koma)' '0.0.0.0/0')"
        raw="${raw//;/,}"
        IFS=',' read -r -a items <<< "$raw"
        valid=1
        ACCESS_NETWORKS=()
        for item in "${items[@]}"; do
            value="$(xargs <<< "$item")"
            if ! network="$(python3 -c 'import ipaddress,sys; print(ipaddress.ip_network(sys.argv[1], strict=False))' "$value" 2>/dev/null)"; then
                valid=0
                break
            fi
            [[ " ${ACCESS_NETWORKS[*]} " == *" $network "* ]] || ACCESS_NETWORKS+=("$network")
        done
        if ((valid)) && ((${#ACCESS_NETWORKS[@]} > 0)); then return; fi
        printf 'IP/CIDR tidak valid. Contoh: 10.10.10.0/24 atau 2001:db8::/64.\n' >&2
    done
}

ask_domain() {
    local name candidate label
    local -a labels
    while true; do
        name="$(ask_default 'IN PTR / nama host' 'resolver1.nexan.id')"
        candidate="${name%.}"
        if [[ ${#candidate} -le 253 && "$candidate" == *.* && "$candidate" != .* && "$candidate" != *. ]]; then
            IFS='.' read -r -a labels <<< "$candidate"
            local valid=1
            for label in "${labels[@]}"; do
                if ((${#label} > 63)) || [[ ! "$label" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$ ]]; then
                    valid=0
                    break
                fi
            done
            if ((valid)); then
                PTR_NAME="${candidate,,}."
                return
            fi
        fi
        printf 'Nama DNS tidak valid. Contoh: resolver1.nexan.id\n' >&2
    done
}

ask_upstreams() {
    local count index answer default_ip
    local -a defaults=('1.1.1.1' '8.8.8.8')
    UPSTREAM_IPS=()
    [[ "$UPSTREAM_MODE" == 'Root only' ]] && return

    count="$(ask_integer 'Jumlah upstream DNS' 1 32 2)"
    for ((index=0; index<count; index++)); do
        default_ip=''
        ((index < 2)) && default_ip="${defaults[$index]}"
        while true; do
            if [[ -n "$default_ip" ]]; then
                answer="$(ask_default "IP upstream #$((index + 1))" "$default_ip")"
            else
                read -r -p "IP upstream #$((index + 1)): " answer
            fi
            if valid_ip "$answer"; then
                UPSTREAM_IPS+=("$answer")
                [[ "$answer" == *:* ]] && IPV6_ENABLED='yes'
                break
            fi
            printf 'IP upstream harus berupa IPv4 atau IPv6 yang valid.\n' >&2
        done
    done
}

ask_urls() {
    local label="$1" count index url
    local -n target_array="$2"
    target_array=()
    count="$(ask_integer "Jumlah link sumber $label" 1 100)"
    for ((index=1; index<=count; index++)); do
        while true; do
            read -r -p "Link $label #$index (https://): " url
            if [[ "$url" =~ ^https://[^[:space:]]+$ ]]; then
                target_array+=("$url")
                break
            fi
            printf 'Link sumber harus menggunakan https:// dan tidak boleh berisi spasi.\n' >&2
        done
    done
}

ensure_ipv4() {
    local answer
    while true; do
        read -r -p "$1: " answer
        if valid_ipv4 "$answer"; then
            printf '%s' "$answer"
            return
        fi
        printf 'Masukkan alamat IPv4 yang valid.\n' >&2
    done
}

ask_zabbix() {
    local answer
    answer="$(ask_choice 'Gunakan Zabbix untuk monitoring? (1=Ya, 2=Tidak)' 1 2)"
    if [[ "$answer" == 1 ]]; then
        USE_ZABBIX='yes'
        while true; do
            read -r -p 'Hostname agent Zabbix: ' ZABBIX_HOSTNAME
            if [[ "$ZABBIX_HOSTNAME" =~ ^[A-Za-z0-9._-]{1,128}$ ]]; then break; fi
            printf 'Hostname hanya boleh berisi huruf, angka, titik, garis bawah, dan strip.\n' >&2
        done
        ZABBIX_IP="$(ensure_ipv4 'IP server Zabbix')"
    else
        USE_ZABBIX='no'
        ZABBIX_HOSTNAME=''
        ZABBIX_IP=''
    fi
}

ask_blocklist() {
    local answer
    answer="$(ask_choice 'Gunakan blocklist dan whitelist? (1=Ya, 2=Tidak)' 1 2)"
    if [[ "$answer" == 1 ]]; then
        USE_BLOCKLIST='yes'
        ask_urls 'blocklist' BLOCKLIST_URLS
        ask_urls 'whitelist' WHITELIST_URLS
        REDIRECT_IP="$(ensure_ipv4 'IP redirect untuk domain blocklist')"
    else
        USE_BLOCKLIST='no'
        BLOCKLIST_URLS=()
        WHITELIST_URLS=()
        REDIRECT_IP=''
    fi
}

collect_specification() {
    local choice
    info 'SPESIFIKASI SERVER'
    printf '  1. LXC Proxmox\n  2. Virtual Machine Host\n'
    choice="$(ask_choice 'Pilih jenis server (1-2)' 1 2)"
    SERVER_TYPE='LXC Proxmox'
    [[ "$choice" == 2 ]] && SERVER_TYPE='Virtual Machine Host'

    IPV6_ENABLED='no'
    ask_interfaces
    for choice in "${INTERFACE_IPS[@]}"; do
        [[ "$choice" == *:* ]] && IPV6_ENABLED='yes'
    done
    ask_access_networks
    CPU_CORES="$(ask_integer 'Berapa Core CPU Server' 1 256)"
    RAM_MB="$(ask_integer 'Berapa RAM (MB)' 256 1048576)"
    DISK_MB="$(ask_integer 'Berapa Disk (MB)' 1 2147483647)"

    printf 'Perkiraan Perangkat:\n  1. 1000-5000\n  2. 6000-12000\n  3. Di atas 12000\n'
    DEVICE_CHOICE="$(ask_choice 'Pilih perkiraan perangkat (1-3)' 1 2 3)"
    case "$DEVICE_CHOICE" in
        1) DEVICE_TIER='1000-5000'; OUTGOING_RANGE=$((CPU_CORES * 240)); ((OUTGOING_RANGE < 960)) && OUTGOING_RANGE=960; QUERIES_PER_THREAD=512; INFRA_HOSTS=50000 ;;
        2) DEVICE_TIER='6000-12000'; OUTGOING_RANGE=$((CPU_CORES * 512)); ((OUTGOING_RANGE < 2048)) && OUTGOING_RANGE=2048; QUERIES_PER_THREAD=1024; INFRA_HOSTS=100000 ;;
        3) DEVICE_TIER='Di atas 12000'; OUTGOING_RANGE=$((CPU_CORES * 1024)); ((OUTGOING_RANGE < 4096)) && OUTGOING_RANGE=4096; QUERIES_PER_THREAD=2048; INFRA_HOSTS=200000 ;;
    esac
    ((OUTGOING_RANGE > 65536)) && OUTGOING_RANGE=65536

    printf 'Target cache:\n  1. Target hit 99.9%%\n  2. Cache semaksimal perangkat\n'
    choice="$(ask_choice 'Pilih target cache (1-2)' 1 2)"
    if [[ "$choice" == 1 ]]; then
        CACHE_PROFILE='99.9%'
        MSG_CACHE_MB=$((RAM_MB / 128 * 16))
        RRSET_CACHE_MB=$((RAM_MB * 3 / 128 * 16))
        KEY_CACHE_MB=$((RAM_MB / 512 * 16))
    else
        CACHE_PROFILE='Semaksimal perangkat'
        MSG_CACHE_MB=$((RAM_MB / 100 * 16))
        RRSET_CACHE_MB=$((RAM_MB * 3 / 100 * 16))
        KEY_CACHE_MB=$((RAM_MB / 400 * 16))
    fi
    ((MSG_CACHE_MB < 16)) && MSG_CACHE_MB=16
    ((RRSET_CACHE_MB < 16)) && RRSET_CACHE_MB=16
    ((KEY_CACHE_MB < 16)) && KEY_CACHE_MB=16

    printf 'Mode upstream:\n  1. Root saja\n  2. Forwarder saja\n  3. Root + forwarder fallback\n'
    choice="$(ask_choice 'Pilih mode upstream (1-3)' 1 2 3)"
    case "$choice" in
        1) UPSTREAM_MODE='Root only' ;;
        2) UPSTREAM_MODE='Forwarders only' ;;
        3) UPSTREAM_MODE='Root + forwarders' ;;
    esac
    ask_upstreams

    printf 'Logging:\n  1. Enable log\n  2. Disable log\n'
    choice="$(ask_choice 'Pilih logging (1-2)' 1 2)"
    LOG_VALUE='yes'
    [[ "$choice" == 2 ]] && LOG_VALUE='no'

    ask_domain
    PTR_IP=''
    for choice in "${INTERFACE_IPS[@]}"; do
        if [[ "$choice" != 127.* && "$choice" != ::1 ]]; then PTR_IP="$choice"; break; fi
    done
    if [[ -z "$PTR_IP" ]]; then
        error 'Tambahkan IP Interface non-loopback untuk membuat record IN PTR.'
        return 1
    fi

    ask_blocklist
    if ! ask_zabbix; then return 1; fi

    SSH_SOURCE=''
    while true; do
        read -r -p 'Sumber IP/CIDR untuk SSH (kosong = izinkan dari mana saja): ' SSH_SOURCE
        if [[ -z "$SSH_SOURCE" ]] || valid_ip_or_cidr "$SSH_SOURCE"; then break; fi
        printf 'Alamat IP/CIDR sumber tidak valid.\n' >&2
    done
}

show_review() {
    local ip
    printf '\nRINGKASAN - %s\n' "$APP_NAME"
    printf 'Jenis server       : %s\n' "$SERVER_TYPE"
    printf 'CPU / RAM / Disk   : %s core / %s MB / %s MB\n' "$CPU_CORES" "$RAM_MB" "$DISK_MB"
    printf 'Perkiraan perangkat: %s\n' "$DEVICE_TIER"
    printf 'Profil cache       : %s (%sm / %sm / %sm)\n' "$CACHE_PROFILE" "$MSG_CACHE_MB" "$RRSET_CACHE_MB" "$KEY_CACHE_MB"
    printf 'IP Interface       : %s\n' "${INTERFACE_IPS[*]}"
    printf 'IP Allow akses     : %s\n' "${ACCESS_NETWORKS[*]}"
    printf 'IN PTR             : %s -> %s\n' "$PTR_IP" "$PTR_NAME"
    printf 'Mode upstream      : %s\n' "$UPSTREAM_MODE"
    if ((${#UPSTREAM_IPS[@]})); then printf 'Upstream           : %s\n' "${UPSTREAM_IPS[*]}"; else printf 'Upstream           : Root hints\n'; fi
    printf 'Logging            : %s\n' "$LOG_VALUE"
    printf 'Blocklist+whitelist: %s\n' "$USE_BLOCKLIST"
    if [[ "$USE_BLOCKLIST" == yes ]]; then
        printf '  Link blocklist   : %s\n' "${#BLOCKLIST_URLS[@]}"
        printf '  Link whitelist   : %s\n' "${#WHITELIST_URLS[@]}"
        printf '  Redirect IP      : %s\n' "$REDIRECT_IP"
    fi
    printf 'Monitoring Zabbix  : %s\n' "$USE_ZABBIX"
    if [[ "$USE_ZABBIX" == yes ]]; then printf '  Hostname / IP    : %s / %s\n' "$ZABBIX_HOSTNAME" "$ZABBIX_IP"; fi
    printf 'Firewall UFW       : allow in/out DNS 53 and HTTPS 443; SSH 22 source: %s\n' "${SSH_SOURCE:-anywhere}"
    if [[ "$USE_ZABBIX" == yes ]]; then printf '                     plus Zabbix 10050 in and 10051 out to %s\n' "$ZABBIX_IP"; fi

    if [[ " ${ACCESS_NETWORKS[*]} " == *' 0.0.0.0/0 '* || " ${ACCESS_NETWORKS[*]} " == *' ::/0 '* ]]; then
        warn 'CIDR /0 membuka recursion untuk semua alamat pada versinya. Batasi ke subnet tepercaya bila resolver dapat diakses dari Internet.'
    fi
    if [[ -z "$SSH_SOURCE" ]]; then warn 'SSH port 22 akan dibuka dari semua sumber.'; fi
    warn 'UFW akan di-reset, incoming dan outgoing akan default deny, lalu hanya port yang diringkas di atas yang diizinkan.'
    warn 'Pastikan akses SSH dan sumber IP sudah benar sebelum melanjutkan; aturan UFW lama akan dihapus.'
    printf 'Target cache adalah profil sizing, bukan jaminan cache hit rate.\n'
}

backup_and_replace() {
    local source="$1" destination="$2" backup
    if [[ -e "$destination" ]]; then
        backup="${destination}.bak.$(date +%Y%m%d-%H%M%S)"
        [[ -e "$backup" ]] && backup="${backup}.$$"
        cp -a -- "$destination" "$backup"
        printf 'Backup file lama: %s\n' "$backup"
    fi
    mv -f -- "$source" "$destination"
}

backup_system_file() {
    local path="$1" backup
    if run_as_root test -e "$path" || run_as_root test -L "$path"; then
        backup="${path}.bak.$(date +%Y%m%d-%H%M%S)"
        run_as_root cp -a -- "$path" "$backup"
        printf '%s' "$backup"
    fi
    return 0
}

restore_system_file() {
    local path="$1" backup="${2:-}"
    if [[ -n "$backup" ]]; then
        run_as_root rm -f -- "$path"
        run_as_root cp -a -- "$backup" "$path"
        printf 'File sistem dipulihkan dari %s\n' "$backup" >&2
    else
        run_as_root rm -f -- "$path"
    fi
}

prepare_unbound_runtime_files() {
    local hints_source='' anchor_status file
    if ! getent passwd unbound >/dev/null 2>&1; then
        error 'Akun service unbound tidak ditemukan setelah pemasangan paket.'
        return 1
    fi
    run_as_root install -d -o unbound -g unbound -m 755 /var/lib/unbound

    if [[ ! -s /var/lib/unbound/root.hints ]]; then
        if [[ -s /usr/share/dns/root.hints ]]; then
            hints_source=/usr/share/dns/root.hints
        elif [[ -s /usr/share/unbound/root.hints ]]; then
            hints_source=/usr/share/unbound/root.hints
        fi
        if [[ -z "$hints_source" ]]; then
            error 'Root hints tidak ditemukan pada lokasi paket dns-root-data.'
            return 1
        fi
        run_as_root install -o root -g unbound -m 644 "$hints_source" /var/lib/unbound/root.hints
    fi
    run_as_root chown root:unbound /var/lib/unbound/root.hints
    run_as_root chmod 644 /var/lib/unbound/root.hints

    if [[ ! -s /var/lib/unbound/root.key ]]; then
        anchor_status=0
        run_as_root unbound-anchor -a /var/lib/unbound/root.key || anchor_status=$?
        if ((anchor_status > 1)); then
            error "unbound-anchor gagal membuat trust anchor (exit $anchor_status)."
            return 1
        fi
    fi
    run_as_root chown unbound:unbound /var/lib/unbound/root.key
    run_as_root chmod 640 /var/lib/unbound/root.key

    if [[ ! -s /etc/unbound/unbound_server.key || ! -s /etc/unbound/unbound_server.pem ||
          ! -s /etc/unbound/unbound_control.key || ! -s /etc/unbound/unbound_control.pem ]]; then
        run_as_root unbound-control-setup -d /etc/unbound
    fi
    for file in unbound_server.key unbound_control.key; do
        run_as_root chown root:unbound "/etc/unbound/$file"
        run_as_root chmod 640 "/etc/unbound/$file"
    done
    for file in unbound_server.pem unbound_control.pem; do
        run_as_root chown root:unbound "/etc/unbound/$file"
        run_as_root chmod 644 "/etc/unbound/$file"
    done
}

deploy_unbound_configuration() {
    local system_config=/etc/unbound/unbound.conf backup='' existed='no'
    generate_unbound_conf

    if ! run_as_root unbound-checkconf "$OUTPUT_DIR/unbound.conf"; then
        error 'unbound-checkconf menolak konfigurasi hasil generate. File sistem belum diubah.'
        return 1
    fi

    if run_as_root test -e "$system_config"; then
        existed='yes'
        backup="$(backup_system_file "$system_config")"
    fi
    UNBOUND_CONFIG_BACKUP="$backup"
    UNBOUND_CONFIG_EXISTED="$existed"
    if ! run_as_root install -o root -g unbound -m 644 "$OUTPUT_DIR/unbound.conf" "$system_config"; then
        error 'Gagal memasang konfigurasi ke /etc/unbound/unbound.conf.'
        rollback_unbound_install "$system_config" "$backup" "$existed"
        return 1
    fi

    if ! run_as_root unbound-checkconf "$system_config"; then
        error 'Konfigurasi terpasang gagal divalidasi; memulihkan konfigurasi sebelumnya.'
        rollback_unbound_install "$system_config" "$backup" "$existed"
        return 1
    fi

    if [[ "$USE_BLOCKLIST" == yes ]]; then
        if ! run_as_root python3 /usr/local/bin/autoupdate-blocklist.py --no-reload; then
            error 'Pembaruan awal blocklist/whitelist gagal; memulihkan konfigurasi Unbound.'
            rollback_unbound_install "$system_config" "$backup" "$existed"
            return 1
        fi
        if ! run_as_root unbound-checkconf "$system_config"; then
            error 'Konfigurasi blocklist gagal divalidasi; memulihkan konfigurasi Unbound.'
            rollback_unbound_install "$system_config" "$backup" "$existed"
            return 1
        fi
    fi

    if ! run_as_root systemctl enable unbound || ! run_as_root systemctl restart unbound; then
        error 'Unbound gagal diaktifkan atau direstart; memulihkan konfigurasi sebelumnya.'
        rollback_unbound_install "$system_config" "$backup" "$existed"
        return 1
    fi
    if ! run_as_root systemctl is-active --quiet unbound; then
        error 'Service Unbound belum aktif; memulihkan konfigurasi sebelumnya.'
        rollback_unbound_install "$system_config" "$backup" "$existed"
        return 1
    fi

    printf 'Unbound aktif dan konfigurasi lolos unbound-checkconf.\n'
}

start_zabbix_agent() {
    if ! run_as_root systemctl enable zabbix-agent || ! run_as_root systemctl restart zabbix-agent; then
        error 'Zabbix agent gagal diaktifkan atau direstart.'
        restore_system_file /etc/zabbix/zabbix_agentd.conf "$ZABBIX_CONF_BACKUP"
        restore_system_file /etc/sudoers.d/unbound-zabbix "$ZABBIX_SUDOERS_BACKUP"
        return 1
    fi
    if ! run_as_root systemctl is-active --quiet zabbix-agent; then
        error 'Service Zabbix agent belum aktif.'
        restore_system_file /etc/zabbix/zabbix_agentd.conf "$ZABBIX_CONF_BACKUP"
        restore_system_file /etc/sudoers.d/unbound-zabbix "$ZABBIX_SUDOERS_BACKUP"
        return 1
    fi
    printf 'Zabbix agent aktif dan dikonfigurasi untuk statistik Unbound.\n'
}

install_blocklist_timer() {
    local service_tmp timer_tmp service_path timer_path
    service_path=/etc/systemd/system/unbound-blocklist-update.service
    timer_path=/etc/systemd/system/unbound-blocklist-update.timer
    service_tmp="$(mktemp "$OUTPUT_DIR/.unbound-blocklist-update.service.XXXXXX")"
    timer_tmp="$(mktemp "$OUTPUT_DIR/.unbound-blocklist-update.timer.XXXXXX")"
    cat > "$service_tmp" <<'EOF'
[Unit]
Description=Update Unbound blocklist and whitelist
After=network-online.target unbound.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/bin/autoupdate-blocklist.py
EOF
    cat > "$timer_tmp" <<'EOF'
[Unit]
Description=Daily Unbound blocklist update

[Timer]
OnCalendar=daily
OnBootSec=15min
RandomizedDelaySec=30min
Persistent=true
Unit=unbound-blocklist-update.service

[Install]
WantedBy=timers.target
EOF
    backup_system_file "$service_path" >/dev/null
    backup_system_file "$timer_path" >/dev/null
    run_as_root install -m 644 "$service_tmp" "$service_path"
    run_as_root install -m 644 "$timer_tmp" "$timer_path"
    rm -f -- "$service_tmp" "$timer_tmp"
    run_as_root systemctl daemon-reload
    run_as_root systemctl enable --now unbound-blocklist-update.timer
    run_as_root systemctl is-active --quiet unbound-blocklist-update.timer
    printf 'Timer blocklist aktif: pembaruan harian dan 15 menit setelah boot.\n'
}

generate_unbound_conf() {
    local ip network slab=1 log_value
    CONF_TMP="$(mktemp "$OUTPUT_DIR/.unbound.conf.XXXXXX")"
    log_value="$LOG_VALUE"
    {
        cat <<EOF
# $APP_NAME
# Server: $SERVER_TYPE | CPU: $CPU_CORES | RAM: $RAM_MB MB | Disk: $DISK_MB MB
# Estimated devices: $DEVICE_TIER | Cache profile: $CACHE_PROFILE
server:
    verbosity: 0
    num-threads: $CPU_CORES

    # NETWORK
EOF
        for ip in "${INTERFACE_IPS[@]}"; do printf '    interface: %s\n' "$ip"; done
        cat <<'EOF'
    port: 53
    do-ip4: yes
    do-ip6: $IPV6_ENABLED
    prefer-ip6: no
    do-udp: yes
    do-tcp: yes

    # ACCESS CONTROL
    access-control: 127.0.0.0/8 allow
EOF
        if [[ "$IPV6_ENABLED" == yes ]]; then printf '    access-control: ::1 allow\n'; fi
        for network in "${ACCESS_NETWORKS[@]}"; do printf '    access-control: %s allow\n' "$network"; done
        while ((slab * 2 <= CPU_CORES)); do slab=$((slab * 2)); done
        cat <<EOF

    # THREAD & SOCKET
    so-reuseport: yes
    so-rcvbuf: 4m
    so-sndbuf: 4m

    # MEMORY CACHE
    msg-cache-size: ${MSG_CACHE_MB}m
    rrset-cache-size: ${RRSET_CACHE_MB}m
    key-cache-size: ${KEY_CACHE_MB}m
    msg-cache-slabs: $slab
    rrset-cache-slabs: $slab
    infra-cache-slabs: $slab
    key-cache-slabs: $slab

    # CACHE TTL & PREFETCH
    cache-min-ttl: 60
    cache-max-ttl: 86400
    prefetch: yes
    prefetch-key: yes

    # QUERY PERFORMANCE
    outgoing-range: $OUTGOING_RANGE
    num-queries-per-thread: $QUERIES_PER_THREAD
    infra-cache-numhosts: $INFRA_HOSTS
    jostle-timeout: 2000

    # SERVE EXPIRED
    serve-expired: yes
    serve-expired-ttl: 86400
    serve-expired-reply-ttl: 30

    # DNS OPTIMIZATION & DNSSEC
    edns-buffer-size: 1232
    minimal-responses: yes
    rrset-roundrobin: yes
    qname-minimisation: yes
    qname-minimisation-strict: no
    module-config: "validator iterator"
    harden-dnssec-stripped: yes
    harden-glue: yes
    hide-identity: yes
    hide-version: yes

    # REVERSE DNS
EOF
        printf '    local-data-ptr: "%s %s"\n' "$PTR_IP" "$PTR_NAME"
        if [[ "$UPSTREAM_MODE" != 'Forwarders only' ]]; then
            cat <<'EOF'
    # Root servers discover public TLDs automatically.
    root-hints: "/var/lib/unbound/root.hints"
EOF
        fi
        cat <<EOF
    auto-trust-anchor-file: "/var/lib/unbound/root.key"

    # LOGGING
    use-syslog: $log_value
    log-queries: $log_value
    log-replies: $log_value
    log-tag-queryreply: $log_value
EOF
        if [[ "$USE_BLOCKLIST" == yes ]]; then
            printf '\n    # Generated by the blocklist updater\n    include: "/etc/unbound/blocklist/blocklist.conf"\n'
        else
            printf '\n    # include: "/etc/unbound/blocklist/blocklist.conf"\n'
        fi
        if [[ "$UPSTREAM_MODE" != 'Root only' ]]; then
            printf '\n# FORWARD DNS\nforward-zone:\n    name: "."\n'
            if [[ "$UPSTREAM_MODE" == 'Root + forwarders' ]]; then
                printf '    forward-first: yes\n'
            else
                printf '    forward-first: no\n'
            fi
            for ip in "${UPSTREAM_IPS[@]}"; do printf '    forward-addr: %s\n' "$ip"; done
        fi
        cat <<'EOF'

# REMOTE CONTROL
remote-control:
    control-enable: yes
    control-interface: 127.0.0.1
    control-use-cert: yes
    server-key-file: "/etc/unbound/unbound_server.key"
    server-cert-file: "/etc/unbound/unbound_server.pem"
    control-key-file: "/etc/unbound/unbound_control.key"
    control-cert-file: "/etc/unbound/unbound_control.pem"

# PRIVATE TLD / custom zone example:
# Public TLDs (including .id) are resolved automatically in root mode.
# For a private delegated zone, add a forward-zone block, for example:
# forward-zone:
#     name: "corp."
#     forward-addr: 10.10.10.53
EOF
    } > "$CONF_TMP"

    backup_and_replace "$CONF_TMP" "$OUTPUT_DIR/unbound.conf"
    CONF_TMP=''
    printf 'Konfigurasi Unbound dibuat: %s/unbound.conf\n' "$OUTPUT_DIR"
}

write_blocklist_settings() {
    local settings_tmp settings_file
    settings_file="$OUTPUT_DIR/blocklist-sources.json"
    settings_tmp="$(mktemp "$OUTPUT_DIR/.blocklist-sources.XXXXXX")"
    python3 - "$settings_tmp" "$REDIRECT_IP" "${#BLOCKLIST_URLS[@]}" "${BLOCKLIST_URLS[@]}" "${#WHITELIST_URLS[@]}" "${WHITELIST_URLS[@]}" <<'PY'
import json
import sys

output = sys.argv[1]
redirect_ip = sys.argv[2]
index = 3
block_count = int(sys.argv[index])
index += 1
block_sources = sys.argv[index:index + block_count]
index += block_count
white_count = int(sys.argv[index])
index += 1
white_sources = sys.argv[index:index + white_count]

with open(output, "w", encoding="utf-8") as stream:
    json.dump({
        "blocklist_sources": block_sources,
        "whitelist_sources": white_sources,
        "redirect_ip": redirect_ip,
    }, stream, indent=2)
    stream.write("\n")
PY
    backup_and_replace "$settings_tmp" "$settings_file"

    if [[ -f "$SCRIPT_DIR/src/autoupdate-blocklist.py" ]]; then
        BLOCKLIST_SOURCES_BACKUP="$(backup_system_file /etc/unbound/blocklist/sources.json)"
        BLOCKLIST_CONF_BACKUP="$(backup_system_file /etc/unbound/blocklist/blocklist.conf)"
        if run_as_root install -d -m 755 /etc/unbound/blocklist /usr/local/bin &&
            run_as_root install -m 644 "$settings_file" /etc/unbound/blocklist/sources.json &&
            run_as_root install -m 755 "$SCRIPT_DIR/src/autoupdate-blocklist.py" /usr/local/bin/autoupdate-blocklist.py; then
            if ! run_as_root test -e /etc/unbound/blocklist/blocklist.conf; then
                run_as_root install -m 644 /dev/null /etc/unbound/blocklist/blocklist.conf
            fi
            printf 'Updater dan sumber blocklist dipasang ke /usr/local/bin dan /etc/unbound/blocklist.\n'
            printf 'Jalankan pembaruan dengan: sudo python3 /usr/local/bin/autoupdate-blocklist.py\n'
        else
            error 'Gagal memasang file blocklist ke /etc. Konfigurasi aktif tidak akan diganti.'
            printf 'Sumber lokal: %s\n' "$settings_file"
            return 1
        fi
    else
        error 'Updater src/autoupdate-blocklist.py tidak ditemukan di samping aplikasi.'
        printf 'Sumber blocklist tetap tersimpan lokal: %s\n' "$settings_file"
        return 1
    fi
}

rollback_unbound_install() {
    local system_config="$1" backup="${2:-}" existed="${3:-no}"
    restore_system_file "$system_config" "$backup"
    if [[ "$USE_BLOCKLIST" == yes ]]; then
        restore_system_file /etc/unbound/blocklist/blocklist.conf "$BLOCKLIST_CONF_BACKUP"
        restore_system_file /etc/unbound/blocklist/sources.json "$BLOCKLIST_SOURCES_BACKUP"
    fi
    if [[ "$existed" == yes ]]; then
        run_as_root systemctl restart unbound || true
    else
        run_as_root systemctl stop unbound || true
    fi
}

write_zabbix_config() {
    local file_tmp destination sudoers_tmp control_path sudo_path awk_path
    destination="$OUTPUT_DIR/zabbix_agentd.conf"
    file_tmp="$(mktemp "$OUTPUT_DIR/.zabbix_agentd.conf.XXXXXX")"
    control_path="$(command -v unbound-control)"
    sudo_path="$(command -v sudo)"
    awk_path="$(command -v awk)"
    cat > "$file_tmp" <<EOF
# Generated by $APP_NAME
LogType=system
Server=$ZABBIX_IP
ServerActive=$ZABBIX_IP
Hostname=$ZABBIX_HOSTNAME
EOF
    printf 'UserParameter=unbound.stat[*],%s -n %s stats_noreset | %s -F= -v key="$1" '\''$1 == key { print $2 }'\''\n' \
        "$sudo_path" "$control_path" "$awk_path" >> "$file_tmp"
    backup_and_replace "$file_tmp" "$destination"
    printf 'Konfigurasi Zabbix agent dibuat: %s\n' "$destination"

    ZABBIX_CONF_BACKUP="$(backup_system_file /etc/zabbix/zabbix_agentd.conf)"
    if ! run_as_root install -d -m 755 /etc/zabbix ||
        ! run_as_root install -m 644 "$destination" /etc/zabbix/zabbix_agentd.conf; then
        error 'Gagal memasang konfigurasi Zabbix agent.'
        restore_system_file /etc/zabbix/zabbix_agentd.conf "$ZABBIX_CONF_BACKUP"
        return 1
    fi

    sudoers_tmp="$(mktemp "$OUTPUT_DIR/.unbound-zabbix-sudoers.XXXXXX")"
    printf 'zabbix ALL=(root) NOPASSWD: %s stats_noreset\n' "$control_path" > "$sudoers_tmp"
    ZABBIX_SUDOERS_BACKUP="$(backup_system_file /etc/sudoers.d/unbound-zabbix)"
    if ! run_as_root install -d -m 755 /etc/sudoers.d ||
        ! run_as_root install -m 440 "$sudoers_tmp" /etc/sudoers.d/unbound-zabbix ||
        ! run_as_root visudo -cf /etc/sudoers.d/unbound-zabbix; then
        rm -f -- "$sudoers_tmp"
        error 'Konfigurasi sudo terbatas untuk statistik Unbound gagal divalidasi.'
        restore_system_file /etc/sudoers.d/unbound-zabbix "$ZABBIX_SUDOERS_BACKUP"
        restore_system_file /etc/zabbix/zabbix_agentd.conf "$ZABBIX_CONF_BACKUP"
        return 1
    fi
    rm -f -- "$sudoers_tmp"

    if ! run_as_root zabbix_agentd --test-config -c /etc/zabbix/zabbix_agentd.conf; then
        error 'Konfigurasi Zabbix agent tidak lolos validasi.'
        restore_system_file /etc/sudoers.d/unbound-zabbix "$ZABBIX_SUDOERS_BACKUP"
        restore_system_file /etc/zabbix/zabbix_agentd.conf "$ZABBIX_CONF_BACKUP"
        return 1
    fi
    printf 'UserParameter unbound.stat[*] disiapkan; TCP 10050/10051 akan dibuka hanya untuk IP Zabbix.\n'
}

enable_ufw_ipv6() {
    local file=/etc/default/ufw
    if run_as_root test -f "$file"; then
        local backup="${file}.bak.$(date +%Y%m%d-%H%M%S)"
        if ! run_as_root test -e "$backup"; then run_as_root cp -a "$file" "$backup"; fi
        if run_as_root grep -q '^IPV6=' "$file"; then
            run_as_root sed -i 's/^IPV6=.*/IPV6=yes/' "$file"
        else
            run_as_root bash -c 'printf "\nIPV6=yes\n" >> /etc/default/ufw'
        fi
    fi
}

apply_firewall() {
    local protocol ip interface_output
    info 'Menerapkan UFW: reset aturan lama, deny incoming/outgoing, lalu allow port terpilih.'
    enable_ufw_ipv6
    run_as_root ufw --force reset
    run_as_root ufw default deny incoming
    run_as_root ufw default deny outgoing

    for protocol in udp tcp; do
        run_as_root ufw allow in "53/$protocol"
        run_as_root ufw allow out "53/$protocol"
    done
    run_as_root ufw allow in 443/tcp
    run_as_root ufw allow out 443/tcp

    if [[ -z "$SSH_SOURCE" ]]; then
        run_as_root ufw allow in 22/tcp
        run_as_root ufw allow out 22/tcp
    else
        run_as_root ufw allow in from "$SSH_SOURCE" to any port 22 proto tcp
        run_as_root ufw allow out to "$SSH_SOURCE" port 22 proto tcp
    fi

    if [[ "$USE_ZABBIX" == yes ]]; then
        run_as_root ufw allow in from "$ZABBIX_IP" to any port 10050 proto tcp
        run_as_root ufw allow out to "$ZABBIX_IP" port 10051 proto tcp
    fi

    run_as_root ufw --force enable
    run_as_root ufw status verbose

    if ! run_as_root systemctl is-active --quiet unbound; then
        error 'Service Unbound tidak aktif setelah UFW diterapkan.'
        return 1
    fi
    local dns_output dnssec_output
    dns_output="$(dig @127.0.0.1 example.com A +time=5 +tries=1 +noall +comments +answer 2>&1)" || true
    if [[ "$dns_output" != *'status: NOERROR'* || "$dns_output" != *'example.com.'* ]]; then
        error 'Unbound belum berhasil menyelesaikan query Internet melalui 127.0.0.1 setelah UFW aktif; konfigurasi Unbound sebelumnya dipulihkan.'
        printf '%s\n' "$dns_output" >&2
        rollback_unbound_install /etc/unbound/unbound.conf "$UNBOUND_CONFIG_BACKUP" "$UNBOUND_CONFIG_EXISTED"
        return 1
    fi
    dnssec_output="$(dig @127.0.0.1 cloudflare.com A +dnssec +time=5 +tries=1 +noall +comments +answer 2>&1)" || true
    if ! grep -qiE 'flags:.*\bad\b' <<< "$dnssec_output"; then
        error 'Query Internet dengan validasi DNSSEC gagal setelah UFW aktif; konfigurasi Unbound sebelumnya dipulihkan.'
        printf '%s\n' "$dnssec_output" >&2
        rollback_unbound_install /etc/unbound/unbound.conf "$UNBOUND_CONFIG_BACKUP" "$UNBOUND_CONFIG_EXISTED"
        return 1
    fi
    for ip in "${INTERFACE_IPS[@]}"; do
        [[ "$ip" == 127.0.0.1 || "$ip" == ::1 ]] && continue
        interface_output="$(dig "@$ip" example.com A +time=5 +tries=1 +noall +comments +answer 2>&1)" || true
        if [[ "$interface_output" != *'status: NOERROR'* || "$interface_output" != *'example.com.'* ]]; then
            error "Unbound gagal menjawab melalui interface $ip; periksa alamat IP dan firewall. Konfigurasi Unbound sebelumnya dipulihkan."
            printf '%s\n' "$interface_output" >&2
            rollback_unbound_install /etc/unbound/unbound.conf "$UNBOUND_CONFIG_BACKUP" "$UNBOUND_CONFIG_EXISTED"
            return 1
        fi
    done
    if [[ "$USE_ZABBIX" == yes ]] && ! run_as_root systemctl is-active --quiet zabbix-agent; then
        error 'Zabbix agent tidak aktif setelah firewall diterapkan.'
        return 1
    fi
    printf 'Unbound berhasil resolve ke Internet; DNSSEC dan semua IP interface berhasil diperiksa setelah UFW aktif.\n'
}

configure_vm_system_resolver() {
    local resolver_file=/etc/resolv.conf resolver_tmp system_dns_output
    resolver_tmp="$(mktemp "$OUTPUT_DIR/.resolv.conf.XXXXXX")"
    printf '# Managed by %s\nnameserver 127.0.0.1\noptions timeout:2 attempts:2\n' "$APP_NAME" > "$resolver_tmp"
    chmod 644 "$resolver_tmp"

    RESOLV_CONF_BACKUP="$(backup_system_file "$resolver_file")"
    if ! run_as_root cp --remove-destination -- "$resolver_tmp" "$resolver_file"; then
        rm -f -- "$resolver_tmp"
        error 'Gagal mengarahkan resolver VM ke 127.0.0.1.'
        restore_system_file "$resolver_file" "$RESOLV_CONF_BACKUP"
        return 1
    fi
    rm -f -- "$resolver_tmp"

    system_dns_output="$(dig example.com A +time=5 +tries=1 +noall +comments +answer 2>&1)" || true
    if [[ "$system_dns_output" != *'status: NOERROR'* || "$system_dns_output" != *'example.com.'* ]] ||
        ! grep -qE '^[[:space:]]*nameserver[[:space:]]+127\.0\.0\.1([[:space:]]|$)' "$resolver_file"; then
        error 'Resolver host gagal setelah diarahkan ke 127.0.0.1; memulihkan resolver sebelumnya.'
        printf '%s\n' "$system_dns_output" >&2
        restore_system_file "$resolver_file" "$RESOLV_CONF_BACKUP"
        return 1
    fi

    printf 'Resolver VM sekarang menggunakan 127.0.0.1; query Internet berhasil.\n'
    if [[ -n "$RESOLV_CONF_BACKUP" ]]; then printf 'Cadangan resolver sebelumnya: %s\n' "$RESOLV_CONF_BACKUP"; fi
}

show_proxmox_dns_recommendation() {
    local ip
    printf '\nSemua pemeriksaan selesai. Resolver LXC tidak diubah otomatis.\n'
    printf 'Setelah status instalasi ini berhasil, atur DNS container dari Proxmox ke IP interface berikut:\n'
    for ip in "${INTERFACE_IPS[@]}"; do
        [[ "$ip" == 127.0.0.1 || "$ip" == ::1 ]] && continue
        printf '  %s\n' "$ip"
    done
}

main() {
    local main_choice review_choice
    printf '============================================================\n'
    printf ' %s\n' "$APP_NAME"
    printf '============================================================\n'
    printf 'Tahap 1: validasi OS dan resource minimum host sebelum perubahan dilakukan.\n'
    check_supported_os
    check_minimum_host
    printf '\nTahap 2: perbarui indeks APT, lalu periksa dan pasang dependensi wajib (Unbound, Python 3, curl, git, UFW, dnsutils, sudo, Zabbix Agent, dan root hints).\n'
    install_dependencies

    while true; do
        printf '\nMENU UTAMA\n  1. Generate unbound.conf\n  2. Batal - Keluar\n'
        main_choice="$(ask_choice 'Pilih menu (1-2)' 1 2)"
        [[ "$main_choice" == 2 ]] && { printf 'Aplikasi ditutup.\n'; return; }

        if ! collect_specification; then
            printf '\nInput spesifikasi akan diulang.\n'
            continue
        fi
        show_review
        printf '\nInformasi sudah akurat dengan server dan pengguna?\n  1. Lanjutkan generate dan konfigurasi UFW\n  2. Cek ulang spesifikasi\n'
        review_choice="$(ask_choice 'Pilih tindakan (1-2)' 1 2)"
        [[ "$review_choice" == 2 ]] && continue

        prepare_unbound_runtime_files
        if [[ "$USE_BLOCKLIST" == yes ]]; then write_blocklist_settings; fi
        deploy_unbound_configuration
        if [[ "$USE_ZABBIX" == yes ]]; then
            write_zabbix_config
            start_zabbix_agent
        fi
        apply_firewall
        if [[ "$USE_BLOCKLIST" == yes ]]; then install_blocklist_timer; fi
        if [[ "$SERVER_TYPE" == 'Virtual Machine Host' ]]; then
            configure_vm_system_resolver
        else
            show_proxmox_dns_recommendation
        fi
        printf '\nInstalasi selesai.\n'
        printf 'File lokal: %s/unbound.conf\n' "$OUTPUT_DIR"
        printf 'File sistem: /etc/unbound/unbound.conf\n'
        printf 'Service Unbound aktif; DNS dan DNSSEC telah diperiksa.\n'
        if [[ "$USE_BLOCKLIST" == yes ]]; then printf 'Blocklist/whitelist aktif dan pembaruan terjadwal harian.\n'; fi
        if [[ "$USE_ZABBIX" == yes ]]; then printf 'Zabbix agent aktif dengan item custom unbound.stat[*].\n'; fi
        break
    done
}

main "$@"
