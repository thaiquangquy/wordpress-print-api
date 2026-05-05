#!/usr/bin/env bash
# e2e-test.sh
# End-to-end test for the print-api plugin.
#
# Flow (master-token path — always runs):
#   1. POST /pdf       with dev master token + book_id → get per-part tokens
#   2. POST /download  for each part token             → download + verify PDF
#   3. Verify each part token is one-time (replay returns 401)
#
# Flow (authenticated path — runs when WP_USER + WP_PASS are set):
#   4. POST /mark-installed  negative case: invalid token → 401
#   5. WordPress login → GET /nonce → POST /token → book token
#   6. POST /mark-installed  with book token → 200, meta set to yes
#   7. POST /mark-installed  replay (peek is non-destructive) → still 200
#   8. POST /pdf  with same book token → 200 (token not consumed by mark-installed)
#   9. POST /mark-installed  after /pdf consumed the token → 401
#
# Usage:
#   bash e2e-test.sh [BASE_URL] [BOOK_ID] [MASTER_TOKEN]
#
# Examples:
#   bash e2e-test.sh http://mysite.local 456 my-dev-token
#   BASE_URL=http://mysite.local BOOK_ID=456 MASTER_TOKEN=my-dev-token bash e2e-test.sh
#   WP_USER=admin WP_PASS=admin bash e2e-test.sh http://mysite.local 456 my-dev-token
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
WP_USER="${WP_USER:-}"
WP_PASS="${WP_PASS:-}"
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
echo "  WP user      : ${WP_USER:-<not set — skipping auth flow>}"
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

# ── Step 3: /mark-installed — invalid token (no auth needed) ─────────────────
info "Step 3 — POST /mark-installed  (invalid token → 401)"

MI_INVALID=$(curl $CURL_OPTS -o /dev/null -w "%{http_code}" \
    -X POST \
    -H "Content-Type: application/json" \
    -d '{"token":"0000000000000000000000000000000000000000000000000000000000000000"}' \
    "${API}/mark-installed")

if [[ "$MI_INVALID" == "401" ]]; then
    ok "/mark-installed: garbage token rejected (401)"
else
    fail "/mark-installed: expected 401 for garbage token, got $MI_INVALID"
fi

echo ""

# ── Step 4: /mark-installed — full authenticated flow ─────────────────────────
if [[ -z "$WP_USER" || -z "$WP_PASS" ]]; then
    info "Step 4 — Skipping authenticated /token → /mark-installed flow"
    info "  To run: WP_USER=admin WP_PASS=admin bash e2e-test.sh ..."
    echo ""
else
    info "Step 4 — Authenticated flow  (WP_USER=$WP_USER)"
    echo ""

    COOKIE_JAR=$(mktemp)

    # 4a: Login to WordPress to get a session cookie
    info "  4a — WordPress login"
    curl $CURL_OPTS \
        -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
        -o /dev/null \
        -X POST "${BASE_URL}/wp-login.php" \
        --data-urlencode "log=${WP_USER}" \
        --data-urlencode "pwd=${WP_PASS}" \
        -d "wp-submit=Log+In&redirect_to=%2Fwp-admin%2F&testcookie=1" \
        -H "Cookie: wordpress_test_cookie=WP+Cookie+check"

    if grep -q "wordpress_logged_in" "$COOKIE_JAR" 2>/dev/null; then
        ok "  WordPress login succeeded"
    else
        fail "  WordPress login failed — check WP_USER and WP_PASS"
        rm -f "$COOKIE_JAR"
        info "  Skipping remaining authenticated tests"
        echo ""
        # Jump to summary by setting a flag
        SKIP_AUTH=1
    fi

    if [[ "${SKIP_AUTH:-0}" == "0" ]]; then

        # 4b: Fetch a fresh nonce
        info "  4b — GET /nonce"
        NONCE_RESP=$(curl $CURL_OPTS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
            "${API}/nonce")
        NONCE=$(printf '%s' "$NONCE_RESP" | jq -r '.nonce // empty')

        if [[ -n "$NONCE" ]]; then
            ok "  Got nonce: ${NONCE:0:12}…"
        else
            fail "  Failed to get nonce — response: $NONCE_RESP"
        fi

        # 4c: POST /token — issues book token and resets user meta to 'no'
        info "  4c — POST /token  (book_id=$BOOK_ID)"
        TOKEN_RESP=$(curl $CURL_OPTS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
            -w "\n%{http_code}" \
            -X POST \
            -H "Content-Type: application/json" \
            -H "X-WP-Nonce: ${NONCE}" \
            -d "{\"book_id\": ${BOOK_ID}}" \
            "${API}/token")

        TOKEN_STATUS=$(echo "$TOKEN_RESP" | tail -1)
        TOKEN_BODY=$(echo "$TOKEN_RESP"   | head -1)

        assert_http "  /token" 200 "$TOKEN_STATUS"

        BOOK_TOKEN=$(printf '%s' "$TOKEN_BODY" | jq -r '.token // empty')

        if [[ ${#BOOK_TOKEN} -eq 64 ]]; then
            ok "  Book token issued: ${BOOK_TOKEN:0:12}…"
        else
            fail "  Book token missing or wrong length — response: $TOKEN_BODY"
            rm -f "$COOKIE_JAR"
            SKIP_AUTH=1
        fi
    fi

    if [[ "${SKIP_AUTH:-0}" == "0" ]]; then

        # 4d: POST /mark-installed with the valid book token
        info "  4d — POST /mark-installed  (valid book token)"
        MI_RESP=$(curl $CURL_OPTS -w "\n%{http_code}" \
            -X POST \
            -H "Content-Type: application/json" \
            -d "{\"token\": \"${BOOK_TOKEN}\"}" \
            "${API}/mark-installed")

        MI_STATUS=$(echo "$MI_RESP" | tail -1)
        MI_BODY=$(echo "$MI_RESP"   | head -1)

        assert_http "  /mark-installed" 200 "$MI_STATUS"

        MI_SUCCESS=$(printf '%s' "$MI_BODY" | jq -r '.success // empty')
        if [[ "$MI_SUCCESS" == "true" ]]; then
            ok "  Response contains { success: true }"
        else
            fail "  Response missing success field — body: $MI_BODY"
        fi

        # 4e: Replay /mark-installed — peek is non-destructive, should still succeed
        info "  4e — POST /mark-installed replay (peek must not consume token)"
        MI_REPLAY=$(curl $CURL_OPTS -o /dev/null -w "%{http_code}" \
            -X POST \
            -H "Content-Type: application/json" \
            -d "{\"token\": \"${BOOK_TOKEN}\"}" \
            "${API}/mark-installed")

        if [[ "$MI_REPLAY" == "200" ]]; then
            ok "  /mark-installed replay accepted (200) — token still intact"
        else
            fail "  /mark-installed replay returned $MI_REPLAY — expected 200 (peek should not consume)"
        fi

        # 4f: POST /pdf with the same book token — must succeed (not consumed by mark-installed)
        info "  4f — POST /pdf  (same book token — must not be consumed yet)"
        PDF2_RESP=$(curl $CURL_OPTS -w "\n%{http_code}" \
            -X POST \
            -H "Content-Type: application/json" \
            -d "{\"token\": \"${BOOK_TOKEN}\"}" \
            "${API}/pdf")

        PDF2_STATUS=$(echo "$PDF2_RESP" | tail -1)
        PDF2_BODY=$(echo "$PDF2_RESP"   | head -1)

        assert_http "  /pdf (after mark-installed)" 200 "$PDF2_STATUS"

        PDF2_PARTS=$(printf '%s' "$PDF2_BODY" | jq -r '.part_count // empty')
        if [[ -n "$PDF2_PARTS" && "$PDF2_PARTS" -gt 0 ]]; then
            ok "  /pdf returned $PDF2_PARTS part token(s) — book token was not consumed by mark-installed"
        else
            fail "  /pdf returned unexpected body: $PDF2_BODY"
        fi

        # 4g: /mark-installed after /pdf consumed the token — must return 401
        info "  4g — POST /mark-installed after /pdf consumed token (must be 401)"
        MI_LATE=$(curl $CURL_OPTS -o /dev/null -w "%{http_code}" \
            -X POST \
            -H "Content-Type: application/json" \
            -d "{\"token\": \"${BOOK_TOKEN}\"}" \
            "${API}/mark-installed")

        if [[ "$MI_LATE" == "401" ]]; then
            ok "  /mark-installed after /pdf correctly rejected (401)"
        else
            fail "  /mark-installed after /pdf returned $MI_LATE — expected 401"
        fi

    fi

    rm -f "$COOKIE_JAR"
    echo ""
fi

# ── Summary ───────────────────────────────────────────────────────────────────
TOTAL=$(( PASS + FAIL ))
echo "========================================"
echo -e " Results: ${GREEN}${PASS} passed${NC}  ${RED}${FAIL} failed${NC}  (${TOTAL} total)"
echo " Downloaded PDFs: $OUT_DIR"
echo "========================================"
echo ""

if [[ "$FAIL" -gt 0 ]]; then exit 1; fi
