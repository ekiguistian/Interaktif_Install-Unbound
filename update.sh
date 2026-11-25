#!/usr/bin/env bash
set -Eeuo pipefail

source_dir=$(cd -- "$(dirname -- "$0")" && pwd -P)
exec /usr/bin/bash "$source_dir/install.sh" --update </dev/null

# System ini di buat dan hasil ide oleh Eki Guistian, Anda boleh memakai dan memdifikasi dengan syarat tetap membuka code ke public dan mencatumkan sumber dari Eki guistian. sesuai aturan opensource
