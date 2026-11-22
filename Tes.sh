#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME='Uji Server Unbound dengan dnsperf'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/src/terminal_ui.bash"
DOMAIN_SOURCE="$SCRIPT_DIR/src/dnsperf-domains.txt"
RESULT_DIR="$SCRIPT_DIR/dnsperf-results"
SERVER_IP='127.0.0.1'
SERVER_PORT=53

info() { ui_info "$@"; }
process() { ui_process "$@"; }
warn() { ui_warn "$@"; }
error() { ui_error "$@"; }

run_as_root() {
    if (( EUID == 0 )); then "$@"; else sudo "$@"; fi
}

run_apt_with_spinner() {
    local label="$1" log_file status
    shift
    log_file="$(mktemp "${TMPDIR:-/tmp}/unbound-apt.XXXXXX")"
    if ui_run_with_spinner "$label" "$log_file" tail run_as_root "$@"; then
        status=0
    else
        status=$?
    fi
    rm -f -- "$log_file"
    return "$status"
}

install_missing_packages() {
    local -a missing=()
    command -v dig >/dev/null 2>&1 || missing+=(dnsutils)
    command -v dnsperf >/dev/null 2>&1 || missing+=(dnsperf)
    if ((${#missing[@]} == 0)); then
        printf 'dnsperf dan dig sudah terpasang.\n'
        return 0
    fi
    if ! command -v apt-get >/dev/null 2>&1; then
        error 'APT tidak tersedia; install dnsperf dan dnsutils sebelum menjalankan pengujian.'
        return 1
    fi
    if (( EUID != 0 )); then
        if ! command -v sudo >/dev/null 2>&1; then
            error 'sudo tidak ditemukan. Jalankan script sebagai root untuk memasang dependensi.'
            return 1
        fi
        sudo -v || { error 'Hak sudo tidak tersedia.'; return 1; }
    fi
    info "Dependensi yang belum tersedia: ${missing[*]}"
    run_apt_with_spinner 'Memperbarui indeks APT' apt-get update
    if [[ " ${missing[*]} " == *' dnsperf '* ]]; then
        if ! apt-cache show dnsperf >/dev/null 2>&1; then
            info 'Paket dnsperf belum terlihat di APT Ubuntu; mengaktifkan Universe.'
            if ! command -v add-apt-repository >/dev/null 2>&1; then
                run_apt_with_spinner 'Memasang dukungan repository Ubuntu' apt-get install -y --no-upgrade software-properties-common
            fi
            command -v add-apt-repository >/dev/null 2>&1 || {
                error 'add-apt-repository tidak tersedia untuk mengaktifkan Ubuntu Universe.'
                return 1
            }
            local repository_log
            repository_log="$(mktemp "${TMPDIR:-/tmp}/unbound-universe.XXXXXX")"
            if ! ui_run_with_spinner 'Mengaktifkan repository Ubuntu Universe' "$repository_log" tail \
                run_as_root add-apt-repository --yes universe; then
                rm -f -- "$repository_log"
                return 1
            fi
            rm -f -- "$repository_log"
            run_apt_with_spinner 'Memperbarui indeks APT' apt-get update
        fi
        if ! apt-cache show dnsperf >/dev/null 2>&1; then
            error 'Paket dnsperf tidak tersedia pada repository APT yang aktif.'
            return 1
        fi
    fi
    run_apt_with_spinner "Memasang dependensi: ${missing[*]}" apt-get install -y --no-upgrade "${missing[@]}"
    command -v dig >/dev/null 2>&1 || { error 'dig belum tersedia setelah pemasangan dnsutils.'; return 1; }
    command -v dnsperf >/dev/null 2>&1 || { error 'dnsperf belum tersedia setelah pemasangan.'; return 1; }
}

ask_integer() {
    local prompt="$1" default="$2" minimum="$3" maximum="$4" value
    while true; do
        IFS= read -r -p "${UI_BLUE}${prompt} [${default}]: ${UI_RESET}" value || return 1
        [[ -n "$value" ]] || value="$default"
        if [[ "$value" =~ ^[0-9]+$ ]] && ((value >= minimum && value <= maximum)); then
            printf '%s' "$value"
            return 0
        fi
        warn "Masukkan angka antara $minimum dan $maximum."
    done
}

ask_positive_integer() {
    local prompt="$1" default="$2" value
    while true; do
        IFS= read -r -p "${UI_BLUE}${prompt} [${default}]: ${UI_RESET}" value || return 1
        [[ -n "$value" ]] || value="$default"
        if [[ "$value" =~ ^[1-9][0-9]*$ ]]; then
            printf '%s' "$value"
            return 0
        fi
        warn 'Masukkan bilangan bulat lebih dari 0.'
    done
}

detect_cpu_cores() {
    local count=''
    if command -v nproc >/dev/null 2>&1; then
        count="$(nproc)"
    elif command -v getconf >/dev/null 2>&1; then
        count="$(getconf _NPROCESSORS_ONLN 2>/dev/null || true)"
    fi
    [[ "$count" =~ ^[1-9][0-9]*$ ]] || count=1
    printf '%s' "$count"
}

read_metric() {
    local label="$1"
    awk -F':[[:space:]]*' -v key="$label" '$1 == key { value = $2 } END { if (value == "") print "tidak tersedia"; else print value }' "$RUN_LOG"
}

latency_in_ms() {
    awk -v text="$1" 'BEGIN { split(text, parts, /[ ()]/); if (parts[1] ~ /^[0-9]+([.][0-9]+)?$/) printf "%.3f ms", parts[1] * 1000; else print text }'
}

show_summary() {
    printf '\n============================================================\n'
    printf ' RINGKASAN UJI DNSPERF\n'
    printf '============================================================\n'
    printf 'DNS yang diuji       : %s:%s\n' "$SERVER_IP" "$SERVER_PORT"
    printf 'Jumlah domain        : 100 domain (tipe A)\n'
    printf 'Durasi uji           : %s detik\n' "$DURATION"
    printf 'Client dnsperf       : %s\n' "$CLIENTS"
    printf 'Pasangan thread      : %s\n' "$THREADS"
    printf 'Batas query/detik    : tidak dibatasi oleh script\n'
    printf 'Query dikirim        : %s\n' "$(read_metric 'Queries sent')"
    printf 'Query selesai        : %s\n' "$(read_metric 'Queries completed')"
    printf 'Query hilang         : %s\n' "$(read_metric 'Queries lost')"
    printf 'Kecepatan rata-rata  : %s\n' "$(read_metric 'Queries per second')"
    printf 'Latensi rata-rata    : %s\n' "$(latency_in_ms "$(read_metric 'Average Latency (s)')")"
    printf 'Simpangan latensi    : %s\n' "$(latency_in_ms "$(read_metric 'Latency StdDev (s)')")"
    printf 'Hasil lengkap        : %s\n' "$RUN_LOG"
    printf 'Ringkasan Indonesia  : %s\n' "$SUMMARY_LOG"
    printf 'Pemanasan cache      : %s\n' "$WARMUP_LOG"

    local lost
    lost="$(awk -F':[[:space:]]*' '$1 == "Queries lost" { split($2, values, " "); value = values[1] } END { print value+0 }' "$RUN_LOG")"
    if ((lost > 0)); then
        warn "Ada $lost query yang tidak mendapat jawaban dalam batas waktu. Periksa log lengkap dan beban server."
    else
        printf 'Status               : Semua query yang dikirim mendapat jawaban.\n'
    fi
}

main() {
    local test_status=0 dns_output dnssec_output confirmation
    ui_init
    ui_header "$APP_NAME"
    require_ubuntu_jammy || return 1
    process "Pengujian berjalan dari server yang sama ke $SERVER_IP:$SERVER_PORT."
    process 'Daftar query berisi 100 domain umum dengan tipe record A.'
    CPU_CORES="$(detect_cpu_cores)"
    printf 'Jumlah CPU terdeteksi: %s core. Nilai awal client dan thread mengikuti jumlah ini.\n' "$CPU_CORES"
    process 'Tahap 1: memeriksa dan memasang dnsperf/dig bila belum tersedia.'
    install_missing_packages

    if [[ ! -r "$DOMAIN_SOURCE" ]]; then
        error "Daftar domain tidak ditemukan: $DOMAIN_SOURCE"
        return 1
    fi
    local domain_count
    domain_count="$(awk 'NF == 2 && $2 == "A" { count++ } END { print count+0 }' "$DOMAIN_SOURCE")"
    if [[ "$domain_count" != 100 ]]; then
        error "Daftar domain harus berisi 100 baris query tipe A; ditemukan $domain_count."
        return 1
    fi

    if ! systemctl is-active --quiet unbound; then
        error 'Service Unbound tidak aktif. Jalankan instalasi atau perbaiki service sebelum pengujian.'
        systemctl status unbound --no-pager || true
        return 1
    fi

    dns_output="$(dig +time=3 +tries=1 @"$SERVER_IP" cloudflare.com A +noall +comments +answer 2>&1)" || true
    if [[ "$dns_output" != *'status: NOERROR'* || "$dns_output" != *'cloudflare.com.'* ]]; then
        error "Unbound tidak menjawab query uji di $SERVER_IP."
        printf '%s\n' "$dns_output" >&2
        return 1
    fi
    info "Pemeriksaan awal DNS: berhasil resolve cloudflare.com melalui $SERVER_IP."

    dnssec_output="$(dig +time=3 +tries=1 +dnssec @"$SERVER_IP" cloudflare.com A +noall +comments +answer 2>&1)" || true
    if grep -qiE 'flags:.*\bad\b' <<< "$dnssec_output"; then
        info 'Pemeriksaan DNSSEC: flag AD terlihat.'
    else
        warn 'Flag AD tidak terlihat pada query DNSSEC. Periksa konfigurasi DNSSEC dan jawaban resolver.'
    fi

    mkdir -p -- "$RESULT_DIR"
    local stamp query_file
    stamp="$(date +%Y%m%d-%H%M%S)"
    query_file="$RESULT_DIR/domain-umum-100-$stamp.txt"
    WARMUP_LOG="$RESULT_DIR/pemanasan-$stamp.log"
    RUN_LOG="$RESULT_DIR/hasil-$stamp.log"
    SUMMARY_LOG="$RESULT_DIR/ringkasan-$stamp.txt"
    cp -- "$DOMAIN_SOURCE" "$query_file"

    warn 'dnsperf mengirim query tanpa batas QPS dari script. Client dan thread dapat memakai CPU server, sehingga DNS klien lain bisa melambat selama pengujian.'
    if grep -Eq '^[[:space:]]*log-(queries|replies):[[:space:]]*yes' /etc/unbound/unbound.conf 2>/dev/null; then
        warn 'Log query/reply aktif. Pencatatan log dapat menurunkan hasil benchmark; hasil ini mengukur konfigurasi yang sedang berjalan.'
    fi
    DURATION="$(ask_integer 'Durasi pengujian dalam detik (5-300)' 300 5 300)"
    CLIENTS="$(ask_positive_integer 'Jumlah client dnsperf (tanpa batas dari script)' "$CPU_CORES")"
    THREADS="$(ask_positive_integer 'Jumlah pasangan thread send/receive (tanpa batas dari script)' "$CPU_CORES")"
    printf '\nRencana uji: %s detik, %s client, %s pasangan thread, tanpa batas QPS dari script.\n' \
        "$DURATION" "$CLIENTS" "$THREADS"
    IFS= read -r -p "${UI_BLUE}Ketik MULAI untuk menjalankan, atau tekan Enter untuk batal: ${UI_RESET}" confirmation || return 1
    if [[ "$confirmation" != MULAI ]]; then
        printf 'Pengujian dibatalkan; Unbound tidak diubah.\n'
        return 0
    fi

    process 'Tahap 2: pemanasan cache menggunakan 100 domain satu kali.'
    if ! ui_run_with_spinner 'Memanaskan cache DNS' "$WARMUP_LOG" full \
        dnsperf -f inet -s "$SERVER_IP" -p "$SERVER_PORT" -d "$query_file" \
            -n 1 -c "$CLIENTS" -T "$THREADS"; then
        error 'Pemanasan cache gagal. Periksa log pemanasan.'
        return 1
    fi

    process "Tahap 3: pengukuran dnsperf selama $DURATION detik."
    if ui_run_with_spinner 'Mengukur kapasitas resolver dengan dnsperf' "$RUN_LOG" full \
        dnsperf -f inet -s "$SERVER_IP" -p "$SERVER_PORT" -d "$query_file" \
            -l "$DURATION" -c "$CLIENTS" -T "$THREADS" -S 2; then
        test_status=0
    else
        test_status=$?
    fi
    show_summary | tee "$SUMMARY_LOG"
    if ((test_status != 0)); then
        error "dnsperf keluar dengan status $test_status. Lihat log lengkap."
        return "$test_status"
    fi
}

main "$@"
