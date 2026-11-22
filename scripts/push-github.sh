#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

remote_url="${1:-$(git remote get-url origin 2>/dev/null || true)}"
commit_message="${2:-Update Unbound DNS Web Manager}"
if [[ ! "$remote_url" =~ ^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(\.git)?$ ]]; then
    printf 'Penggunaan: bash scripts/push-github.sh https://github.com/OWNER/REPOSITORY.git [PESAN-COMMIT]\n' >&2
    exit 2
fi

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    git init -b main
fi

git add -A
if ! git diff --cached --quiet; then
    git commit -m "$commit_message"
fi
git branch -M main

push_remote='origin'
if ! git remote get-url origin >/dev/null 2>&1; then
    git remote add origin "$remote_url"
elif [[ "$(git remote get-url origin)" != "$remote_url" ]]; then
    push_remote='github-publish'
    if git remote get-url "$push_remote" >/dev/null 2>&1; then
        git remote set-url "$push_remote" "$remote_url"
    else
        git remote add "$push_remote" "$remote_url"
    fi
fi

printf 'Push branch main ke %s (%s)\n' "$push_remote" "$remote_url"
git push -u "$push_remote" main
