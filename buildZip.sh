#!/usr/bin/env bash
# buildZip.sh
# Package the print-api plugin into a distributable zip from the repo root.

set -euo pipefail

cd "$(dirname "$0")"

zip -r print-api.zip print-api/ \
  --exclude "*.git*" \
  --exclude "*.md" \
  --exclude "*.sh"

echo "Built: print-api.zip"
