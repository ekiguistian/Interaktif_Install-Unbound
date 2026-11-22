# Shared by install.sh and update.sh. Both entry points hold the deployment lock.
WEB_APP_DIR='/opt/unbound-web'
WEB_HELPER='/usr/local/sbin/unbound-web-manage'
WEB_CHECKOUT='/var/lib/unbound-web-updates/checkout'
WEB_REMOTE='https://github.com/ekiguistian/Interaktif_Install-Unbound.git'
WEB_TXN=''
WEB_HELPER_TEMP=''
WEB_ACTIVATED=no
WEB_HELPER_CHANGED=no
WEB_REVISION=''

web_git() {
    GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0 \
        /usr/bin/git -c core.hooksPath=/dev/null -c protocol.allow=never \
        -c protocol.https.allow=always -c fetch.recurseSubmodules=false -c http.followRedirects=false "$@"
}

web_pull_main() {
    local store="${WEB_CHECKOUT%/*}"
    [[ ! -L "$store" && ! -L "$WEB_CHECKOUT" && ! -L "$WEB_CHECKOUT/.git" ]] ||
        die 'Lokasi Git updater tidak valid.'
    install -d -o root -g root -m 0700 "$store"
    if [[ ! -d "$WEB_CHECKOUT/.git" ]]; then
        web_git clone --branch main --single-branch --no-tags "$WEB_REMOTE" "$WEB_CHECKOUT"
    fi
    [[ "$(web_git -C "$WEB_CHECKOUT" branch --show-current)" == main ]] ||
        die 'Checkout updater harus menggunakan branch main.'
    [[ -z "$(web_git -C "$WEB_CHECKOUT" status --porcelain)" ]] ||
        die 'Checkout updater memiliki perubahan lokal.'
    web_git -C "$WEB_CHECKOUT" pull --ff-only --no-rebase --no-tags "$WEB_REMOTE" main
    WEB_REVISION="$(web_git -C "$WEB_CHECKOUT" rev-parse --verify HEAD)"
    # A local commit that is ahead of main may still produce a successful git pull.
    [[ "$WEB_REVISION" == "$(web_git -C "$WEB_CHECKOUT" rev-parse --verify FETCH_HEAD)" ]] ||
        die 'Checkout updater berbeda dari branch main pada GitHub.'
}

web_finish_deploy() {
    local status=$?
    trap - EXIT
    if [[ -n "$WEB_HELPER_TEMP" ]]; then rm -f -- "$WEB_HELPER_TEMP" || true; fi
    if [[ -n "$WEB_TXN" ]]; then
        if ((status != 0)); then
            if [[ "$WEB_ACTIVATED" == yes ]] && ! mv -T -- "$WEB_APP_DIR" "$WEB_TXN/failed"; then
                printf '[ERROR] Gagal mengganti kembali web root. Cadangan: %s\n' "$WEB_TXN" >&2
                return "$status"
            fi
            if [[ -d "$WEB_TXN/previous" ]] && ! mv -T -- "$WEB_TXN/previous" "$WEB_APP_DIR"; then
                printf '[ERROR] Gagal memulihkan web root. Cadangan: %s\n' "$WEB_TXN" >&2
                return "$status"
            fi
            if [[ "$WEB_HELPER_CHANGED" == yes ]]; then
                if [[ -e "$WEB_TXN/helper" || -L "$WEB_TXN/helper" ]]; then
                    if ! cp -aT -- "$WEB_TXN/helper" "$WEB_HELPER"; then
                        printf '[ERROR] Gagal memulihkan helper. Cadangan: %s\n' "$WEB_TXN" >&2
                        return "$status"
                    fi
                else
                    rm -f -- "$WEB_HELPER" || return "$status"
                fi
            fi
        fi
        rm -rf -- "$WEB_TXN"
    fi
    return "$status"
}

web_stage_release() {
    WEB_TXN="$(mktemp -d /opt/.unbound-web-release.XXXXXX)"
    trap web_finish_deploy EXIT
    mkdir "$WEB_TXN/release"
    web_git -C "$WEB_CHECKOUT" archive "$WEB_REVISION" | tar -x --no-same-owner -C "$WEB_TXN/release"
    local release="$WEB_TXN/release"
    [[ -f "$release/public/index.php" && -f "$release/app/bootstrap.php" &&
       -f "$release/update.sh" && -f "$release/scripts/manage.sh" &&
       -f "$release/scripts/web-migrate.bash" && -f "$release/database/schema.sql" ]] ||
        die 'File aplikasi dari Git belum lengkap.'
    php8.4 -l "$release/public/index.php" >/dev/null
    bash -n "$release/update.sh"
    bash -n "$release/scripts/manage.sh"
    bash -n "$release/scripts/web-migrate.bash"
    printf '%s\n' "$WEB_REVISION" > "$release/revision"
    find "$release" -type d -exec chmod 0755 {} +
    find "$release" -type f -exec chmod a+r,go-w {} +
}

web_activate_release() {
    [[ ! -L "$WEB_APP_DIR" ]] || die 'Web root harus berupa direktori aplikasi biasa.'
    if [[ -e "$WEB_HELPER" || -L "$WEB_HELPER" ]]; then
        cp -aT -- "$WEB_HELPER" "$WEB_TXN/helper"
    fi
    if [[ -d "$WEB_APP_DIR" ]]; then mv -T -- "$WEB_APP_DIR" "$WEB_TXN/previous"; fi
    mv -T -- "$WEB_TXN/release" "$WEB_APP_DIR"
    WEB_ACTIVATED=yes
    WEB_HELPER_TEMP="$(mktemp "${WEB_HELPER}.XXXXXX")"
    install -o root -g root -m 0750 "$WEB_APP_DIR/scripts/manage.sh" "$WEB_HELPER_TEMP"
    mv -fT -- "$WEB_HELPER_TEMP" "$WEB_HELPER"
    WEB_HELPER_CHANGED=yes
    WEB_HELPER_TEMP=''
}

web_check_deployed() {
    local url='http://127.0.0.1:8000' headers served trial
    nginx -t
    php-fpm8.4 -t
    cmp -s -- "$WEB_HELPER" "$WEB_APP_DIR/scripts/manage.sh" || die 'Helper terpasang tidak cocok dengan source web.'
    sudo -u www-data /usr/bin/sudo -n "$WEB_HELPER" service-status >/dev/null ||
        die 'Aplikasi tidak dapat menjalankan helper sistem.'
    curl -fsS --noproxy '*' --max-time 10 "$url/login" -o /dev/null || die 'Halaman login gagal dibuka.'
    for trial in 1 2 3; do
        if headers="$(curl -fsS --noproxy '*' --max-time 5 -D - "$url/api/metrics" -o /dev/null)"; then
            served="$(awk -F ': *' 'tolower($1)=="x-unbound-web-revision" {sub(/\r$/, "", $2); print $2}' <<< "$headers")"
            if [[ "$served" == "$WEB_REVISION" ]]; then
                info "Nginx menyajikan commit ${WEB_REVISION:0:12} dari $WEB_APP_DIR/public."
                return
            fi
        fi
        if ((trial < 3)); then sleep 1; fi
    done
    die "API atau commit $WEB_REVISION belum tersedia lewat Nginx dari $WEB_APP_DIR/public."
}
