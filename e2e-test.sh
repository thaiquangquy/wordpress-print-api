#!/usr/bin/env bash
# e2e-test.sh
# End-to-end test for the print-api plugin using the dev master token.
#
# Flow:
#   1. POST /pdf       with dev master token + book_id → get per-part tokens
#   2. POST /download  for each part token             → download + verify PDF
#   3. Verify each part token is one-time (replay returns 401)
#
# Usage:
#   bash e2e-test.sh [BASE_URL] [BOOK_ID] [MASTER_TOKEN]
#
# Examples:
#   bash e2e-test.sh http://mysite.local 456 my-dev-token
#   BASE_URL=http://mysite.local BOOK_ID=456 MASTER_TOKEN=my-dev-token bash e2e-test.sh
#
# Requirements: curl, jq
# Note: WP_DEBUG must be true and PRINT_API_DEV_MASTER_TOKEN must be set in wp-config.php

set -euo pipefail

# ── Dependency check ──────────────────────────────────────────────────────────
for cmd in curl jq; do
    if ! command -v "$cmd" &>/dev/null; then
        echo "Error: '$cmd' is required but not installed." >&2
        exit 1
    fi
done

# ── Config ────────────────────────────────────────────────────────────────────
BASE_URL="${1:-${BASE_URL:-http://mysite.local}}"
BOOK_ID="${2:-${BOOK_ID:-456}}"
MASTER_TOKEN="${3:-${MASTER_TOKEN:-my-dev-token}}"
API="${BASE_URL}/wp-json/print-api/v1"
OUT_DIR="$(mktemp -d)"
PASS=0
FAIL=0

# ── WSL2 / .local DNS workaround ─────────────────────────────────────────────
# In WSL2, *.local domains resolve to 127.0.0.1 (WSL loopback), not Windows host.
# Auto-detect Windows host IP and pass it to curl via --resolve.
CURL_OPTS="-s"
if [[ "$BASE_URL" == *".local"* ]]; then
    HOST_IP=$(ip route show default 2>/dev/null | awk '{print $3}' | head -1)
    if [[ -n "$HOST_IP" ]]; then
        HOST_NAME=$(echo "$BASE_URL" | sed 's|https\?://||' | cut -d/ -f1)
        PORT=80; [[ "$BASE_URL" == https://* ]] && PORT=443
        CURL_OPTS="$CURL_OPTS --resolve ${HOST_NAME}:${PORT}:${HOST_IP}"
    fi
fi

# ── Helpers ───────────────────────────────────────────────────────────────────
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

ok()   { echo -e "${GREEN}  PASS${NC}  $*"; PASS=$(( PASS + 1 )); }
fail() { echo -e "${RED}  FAIL${NC}  $*"; FAIL=$(( FAIL + 1 )); }
info() { echo -e "${YELLOW}  ----${NC}  $*"; }

assert_http() {
    local label="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then ok "$label → HTTP $actual"
    else fail "$label → expected HTTP $expected, got $actual"; fi
}

json_get()        { printf '%s' "$1" | jq -r "${2} // empty"; }
json_array_item() { printf '%s' "$1" | jq -r ".[$2]"; }

echo ""
echo "========================================"
echo " Print-API E2E Test"
echo "========================================"
echo "  Base URL     : $BASE_URL"
echo "  Book ID      : $BOOK_ID"
echo "  Master token : ${MASTER_TOKEN:0:6}…"
echo "  Output dir   : $OUT_DIR"
echo "========================================"
echo ""

# ── Step 1: POST /pdf with dev master token ───────────────────────────────────
info "Step 1 — POST /pdf  (master token, book_id=$BOOK_ID)"

PDF_RESP=$(curl $CURL_OPTS -w "\n%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    -d "{\"token\": \"${MASTER_TOKEN}\", \"book_id\": ${BOOK_ID}}" \
    "${API}/pdf")

PDF_STATUS=$(echo "$PDF_RESP" | tail -1)
PDF_BODY=$(echo "$PDF_RESP"   | head -1)

assert_http "/pdf" 200 "$PDF_STATUS"

if [[ "$PDF_STATUS" != "200" ]]; then
    echo "  Response: $PDF_BODY"
    echo ""
    echo "========================================"
    echo -e " ${RED}Cannot continue — check MASTER_TOKEN, BOOK_ID, and that WP_DEBUG=true${NC}"
    echo "========================================"
    exit 1
fi

PARTS=$(json_get "$PDF_BODY" '.parts')
PART_COUNT=$(json_get "$PDF_BODY" '.part_count')

if [[ -n "$PART_COUNT" && "$PART_COUNT" -gt 0 ]]; then
    ok "part_count = $PART_COUNT"
else
    fail "part_count is 0 or missing — no PDF files found for book $BOOK_ID"
    echo "  Response: $PDF_BODY"
    exit 1
fi

# Verify master token is reusable (unlike normal book tokens)
info "  Verifying master token is reusable (replay should succeed)"
REPLAY_STATUS=$(curl $CURL_OPTS -o /dev/null -w "%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    -d "{\"token\": \"${MASTER_TOKEN}\", \"book_id\": ${BOOK_ID}}" \
    "${API}/pdf")

if [[ "$REPLAY_STATUS" == "200" ]]; then
    ok "master token replay accepted (reusable as expected)"
else
    fail "master token replay returned $REPLAY_STATUS — expected 200"
fi

echo ""

# ── Step 2: Download each part ────────────────────────────────────────────────
info "Step 2 — POST /download  (${PART_COUNT} part(s))"
echo ""

for i in $(seq 0 $(( PART_COUNT - 1 ))); do
    PART_NUM=$(( i + 1 ))
    PART_TOKEN=$(json_array_item "$PARTS" "$i")
    OUT_FILE="${OUT_DIR}/part${PART_NUM}.pdf"

    info "  Part $PART_NUM — token: ${PART_TOKEN:0:12}…"

    DL_STATUS=$(curl $CURL_OPTS -o "$OUT_FILE" -w "%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        -d "{\"token\": \"${PART_TOKEN}\"}" \
        "${API}/download")

    assert_http "  /download part $PART_NUM" 200 "$DL_STATUS"

    if [[ "$DL_STATUS" == "200" ]]; then
        FILE_SIZE=$(wc -c < "$OUT_FILE")
        if [[ "$FILE_SIZE" -gt 0 ]]; then
            ok "  part${PART_NUM}.pdf: ${FILE_SIZE} bytes"
        else
            fail "  part${PART_NUM}.pdf: empty file"
        fi

        PDF_MAGIC=$(head -c 5 "$OUT_FILE" 2>/dev/null || true)
        if [[ "$PDF_MAGIC" == "%PDF-" ]]; then
            ok "  part${PART_NUM}.pdf: valid PDF header (%PDF-)"
        else
            fail "  part${PART_NUM}.pdf: invalid header (got: '$PDF_MAGIC')"
        fi
    fi

    # Part token must be one-time — replay must return 401
    PART_REPLAY=$(curl $CURL_OPTS -o /dev/null -w "%{http_code}" \
        -X POST \
        -H "Content-Type: application/json" \
        -d "{\"token\": \"${PART_TOKEN}\"}" \
        "${API}/download")

    if [[ "$PART_REPLAY" == "401" ]]; then
        ok "  part${PART_NUM} token replay rejected (401)"
    else
        fail "  part${PART_NUM} token replay returned $PART_REPLAY — expected 401"
    fi

    echo ""
done

# ── Summary ───────────────────────────────────────────────────────────────────
TOTAL=$(( PASS + FAIL ))
echo "========================================"
echo -e " Results: ${GREEN}${PASS} passed${NC}  ${RED}${FAIL} failed${NC}  (${TOTAL} total)"
echo " Downloaded PDFs: $OUT_DIR"
echo "========================================"
echo ""

if [[ "$FAIL" -gt 0 ]]; then exit 1; fi
