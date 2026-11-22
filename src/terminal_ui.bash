# Shared terminal styling for the three public entrypoint scripts.

UI_RESET=''
UI_GREEN=''
UI_BLUE=''
UI_RED=''
UI_BOLD=''
UI_TTY='no'

ui_init() {
    if [[ -t 1 && "${TERM:-}" != dumb ]]; then
        UI_TTY='yes'
        if [[ -z "${NO_COLOR:-}" ]]; then
            UI_RESET=$'\033[0m'
            UI_GREEN=$'\033[32m'
            UI_BLUE=$'\033[34m'
            UI_RED=$'\033[31m'
            UI_BOLD=$'\033[1m'
        fi
        if [[ -n "${TERM:-}" ]] && command -v clear >/dev/null 2>&1; then
            clear || true
        fi
    fi
}

ui_info() {
    printf '\n%s%s%s\n' "$UI_GREEN" "$*" "$UI_RESET"
}

ui_process() {
    printf '\n%s%s%s\n' "$UI_BLUE" "$*" "$UI_RESET"
}

ui_warn() {
    if [[ -t 2 && "${TERM:-}" != dumb && -z "${NO_COLOR:-}" ]]; then
        printf '%sPERINGATAN: %s%s\n' $'\033[31m' "$*" $'\033[0m' >&2
    else
        printf 'PERINGATAN: %s\n' "$*" >&2
    fi
}

ui_error() {
    if [[ -t 2 && "${TERM:-}" != dumb && -z "${NO_COLOR:-}" ]]; then
        printf '%sERROR: %s%s\n' $'\033[31m' "$*" $'\033[0m' >&2
    else
        printf 'ERROR: %s\n' "$*" >&2
    fi
}

ui_header() {
    local title="$1" os_name='Linux (informasi OS tidak tersedia)' kernel architecture
    if [[ -r /etc/os-release ]]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        os_name="${PRETTY_NAME:-${NAME:-Linux} ${VERSION_ID:-}}"
    fi
    kernel="$(uname -r 2>/dev/null || printf 'tidak tersedia')"
    architecture="$(uname -m 2>/dev/null || printf 'tidak tersedia')"

    printf '%s%s============================================================%s\n' "$UI_BLUE" "$UI_BOLD" "$UI_RESET"
    printf '%s%s %s%s\n' "$UI_BLUE" "$UI_BOLD" "$title" "$UI_RESET"
    printf '%s%s============================================================%s\n' "$UI_BLUE" "$UI_BOLD" "$UI_RESET"
    ui_info "Sistem operasi: $os_name"
    ui_info "Kernel: $kernel | Arsitektur: $architecture"
}

# Run a long command without mixing its output with the spinner. LOG_FILE keeps
# the original output; MODE is tail, full, or quiet. Failures always show the log.
ui_run_with_spinner() {
    local label="$1" log_file="$2" mode="$3"
    shift 3
    local spinner_pid='' status=0

    mkdir -p -- "${log_file%/*}" 2>/dev/null || true
    : > "$log_file" || return 1

    if [[ "$UI_TTY" == yes ]]; then
        (
            local -a frames=('⌛' '⏳')
            local index=0
            while :; do
                printf '\r%s%s %s%s' "$UI_BLUE" "${frames[index]}" "$label" "$UI_RESET"
                index=$(((index + 1) % ${#frames[@]}))
                sleep 0.35
            done
        ) &
        spinner_pid=$!
    else
        ui_process "$label"
    fi

    if "$@" >"$log_file" 2>&1; then
        status=0
    else
        status=$?
    fi

    if [[ -n "$spinner_pid" ]]; then
        kill "$spinner_pid" 2>/dev/null || true
        wait "$spinner_pid" 2>/dev/null || true
        printf '\r\033[K%s%sSelesai: %s%s\n' "$UI_GREEN" "$UI_BOLD" "$label" "$UI_RESET"
    fi

    if ((status != 0)); then
        ui_error "Proses gagal (exit $status): $label"
        cat -- "$log_file"
        return "$status"
    fi

    case "$mode" in
        full) cat -- "$log_file" ;;
        tail) tail -n 8 -- "$log_file" ;;
        quiet) ;;
        *) ui_error "Mode output tidak dikenal: $mode"; return 2 ;;
    esac
}
