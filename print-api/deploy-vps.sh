#!/usr/bin/env bash
# deploy-vps.sh
# Sync the print-api plugin to the live WordPress site via SSH/rsync.
#
# Usage:
#   ./deploy-vps.sh                    # uses defaults below
#   VPS_USER=admin VPS_HOST=1.2.3.4 ./deploy-vps.sh
#
# Prerequisites:
#   - SSH key auth set up for VPS_USER@VPS_HOST  (or you will be prompted for a password)
#   - rsync installed locally and on the VPS

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────
# Override any of these with environment variables before running:
#   VPS_USER=myuser VPS_HOST=1.2.3.4 ./deploy-vps.sh

VPS_USER="${VPS_USER:-admin}"
VPS_HOST="${VPS_HOST:-YOUR_VPS_IP_OR_DOMAIN}"
VPS_PORT="${VPS_PORT:-22}"

# Absolute path to the plugins folder on the VPS.
# Matches the ISPConfig web root for client2/web13.
REMOTE_PLUGIN_DIR="${REMOTE_PLUGIN_DIR:-/var/www/clients/client2/web13/web/wp-content/plugins/print-api}"

SRC="$(cd "$(dirname "$0")" && pwd)/"

echo "──────────────────────────────────────────"
echo " Print API — VPS Deploy"
echo "──────────────────────────────────────────"
echo " Source : $SRC"
echo " Target : ${VPS_USER}@${VPS_HOST}:${REMOTE_PLUGIN_DIR}"
echo "──────────────────────────────────────────"

rsync -avz --delete \
  -e "ssh -p ${VPS_PORT}" \
  --exclude='.git' \
  --exclude='deploy-*.sh' \
  --exclude='*.md' \
  "$SRC" \
  "${VPS_USER}@${VPS_HOST}:${REMOTE_PLUGIN_DIR}"

echo ""
echo "Done. Plugin files synced to ${VPS_HOST}."
echo "If this is the first deploy, activate the plugin in WP Admin → Plugins."
