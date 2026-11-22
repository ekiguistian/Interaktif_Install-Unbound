#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME='Uji Server Unbound dengan dnsperf'
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
DOMAIN_SOURCE="$SCRIPT_DIR/src/dnsperf-domains.txt"
RESULT_DIR="$SCRIPT_DIR/dnsperf-results"
SERVER_IP='127.0.0.1'
SERVER_PORT=53

info() { printf '\n%s\n' "$*"; }
warn() { printf 'PERINGATAN: %s\n' "$*" >&2; }
error() { printf 'ERROR: %s\n' "$*" >&2; }

run_as_root() {
    if (( EUID == 0 )); then "$@"; else sudo "$@"; fi
}

install_missing_packages() {
    local -a missing=()
    local os_id=''
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
    run_as_root apt-get update
    if [[ " ${missing[*]} " == *' dnsperf '* ]]; then
        [[ -r /etc/os-release ]] && . /etc/os-release
        os_id="${ID:-}"
        if [[ "$os_id" == ubuntu ]] && ! apt-cache show dnsperf >/dev/null 2>&1; then
            info 'Paket dnsperf belum terlihat di APT Ubuntu; mengaktifkan Universe.'
            if ! command -v add-apt-repository >/dev/null 2>&1; then
                run_as_root apt-get install -y --no-upgrade software-properties-common
            fi
            command -v add-apt-repository >/dev/null 2>&1 || {
                error 'add-apt-repository tidak tersedia untuk mengaktifkan Ubuntu Universe.'
                return 1
            }
            run_as_root add-apt-repository --yes universe
            run_as_root apt-get update
        fi
        if ! apt-cache show dnsperf >/dev/null 2>&1; then
            error 'Paket dnsperf tidak tersedia pada repository APT yang aktif.'
            return 1
        fi
    fi
    run_as_root apt-get install -y --no-upgrade "${missing[@]}"
    command -v dig >/dev/null 2>&1 || { error 'dig belum tersedia setelah pemasangan dnsutils.'; return 1; }
    command -v dnsperf >/dev/null 2>&1 || { error 'dnsperf belum tersedia setelah pemasangan.'; return 1; }
}

ask_integer() {
    local prompt="$1" default="$2" minimum="$3" maximum="$4" value
    while true; do
        printf '%s [%s]: ' "$prompt" "$default"
        IFS= read -r value || return 1
        [[ -n "$value" ]] || value="$default"
        if [[ "$value" =~ ^[0-9]+$ ]] && ((value >= minimum && value <= maximum)); then
            printf '%s' "$value"
            return 0
        fi
        warn "Masukkan angka antara $minimum dan $maximum."
    done
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
    printf 'Batas beban          : %s query/detik, 1 client, 1 thread\n' "$MAX_QPS"
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
    local test_status=0 dns_output dnssec_output
    printf '============================================================\n'
    printf ' %s\n' "$APP_NAME"
    printf '============================================================\n'
    printf 'Pengujian berjalan dari server yang sama ke %s:%s.\n' "$SERVER_IP" "$SERVER_PORT"
    printf 'Daftar query berisi 100 domain umum dengan tipe record A.\n'
    printf 'Tahap 1: memeriksa dan memasang dnsperf/dig bila belum tersedia.\n'
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
    printf '\nPemeriksaan awal DNS: berhasil resolve cloudflare.com melalui %s.\n' "$SERVER_IP"

    dnssec_output="$(dig +time=3 +tries=1 +dnssec @"$SERVER_IP" cloudflare.com A +noall +comments +answer 2>&1)" || true
    if grep -qiE 'flags:.*\bad\b' <<< "$dnssec_output"; then
        printf 'Pemeriksaan DNSSEC: flag AD terlihat.\n'
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

    printf '\nPeringatan: dnsperf memberi beban tambahan ke CPU server. Beban dibatasi 1 client dan 1 thread.\n'
    if grep -Eq '^[[:space:]]*log-(queries|replies):[[:space:]]*yes' /etc/unbound/unbound.conf 2>/dev/null; then
        warn 'Log query/reply aktif. Pencatatan log dapat menurunkan hasil benchmark; hasil ini mengukur konfigurasi yang sedang berjalan.'
    fi
    DURATION="$(ask_integer 'Durasi pengujian dalam detik (5-300)' 10 5 300)"
    MAX_QPS="$(ask_integer 'Batas query per detik (1-2000)' 50 1 2000)"

    printf '\nTahap 2: pemanasan cache menggunakan 100 domain satu kali.\n'
    if ! dnsperf -f inet -s "$SERVER_IP" -p "$SERVER_PORT" -d "$query_file" -n 1 -c 1 -T 1 -Q "$MAX_QPS" 2>&1 | tee "$WARMUP_LOG"; then
        error 'Pemanasan cache gagal. Periksa output di atas.'
        return 1
    fi

    printf '\nTahap 3: pengukuran dnsperf selama %s detik.\n' "$DURATION"
    if dnsperf -f inet -s "$SERVER_IP" -p "$SERVER_PORT" -d "$query_file" \
        -l "$DURATION" -c 1 -T 1 -Q "$MAX_QPS" -S 2 2>&1 | tee "$RUN_LOG"; then
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
