#!/usr/bin/env bash
# deploy-local.sh
# Copy the print-api plugin to the Local WP site on Windows (via WSL2 mount).

set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
DEST="/mnt/c/Users/thaiq/Local Sites/mysite/app/public/wp-content/plugins/print-api"

echo "Source : $SRC"
echo "Dest   : $DEST"

rsync -av --delete \
  --exclude='.git' \
  --exclude='deploy-local.sh' \
  --exclude='*.md' \
  "$SRC/" "$DEST/"

echo "Done."
