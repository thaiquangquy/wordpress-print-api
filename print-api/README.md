# Print API — WordPress Plugin

Secure PDF download via short-lived, single-use tokens.

## How It Works

```
Browser                          WordPress Plugin                    Disk
───────                          ────────────────                    ────
[Click Download]
    │
    ├─ POST /wp-json/print-api/v1/token ──────────────────────────►
    │    body: { book_id: 123 }          verify nonce
    │    header: X-WP-Nonce: …           generate token (5 min TTL)
    │                                    store in WP Transient
    │◄─ { token: "a3f9…" } ─────────────────────────────────────────
    │
    ├─ redirect: app://print?token=a3f9…   (deep-link to native app)
    │         OR
    ├─ GET /wp-json/print-api/v1/pdf?token=a3f9… ────────────────►
    │                                    validate token
    │                                    DELETE token (now invalid)
    │                                    resolve book → 3 file paths ──► part1.pdf
    │◄─ { parts: ["url1","url2","url3"] } ───────────────────────────    part2.pdf
                                                                         part3.pdf
```

**Token properties:**
- 256-bit cryptographic entropy (`random_bytes(32)`)
- 5-minute expiry (WP Transient TTL)
- Single-use — deleted on first exchange, replay always returns 401

---

## File Structure

```
print-api/
├── print-api.php                   # Plugin header, constants, hooks
├── includes/
│   ├── class-token-manager.php     # Token generate / consume (WP Transients)
│   ├── class-rest-api.php          # REST route registration + handlers
│   └── class-pdf-resolver.php      # Maps book_id → 3 PDF file URLs
└── assets/
    └── js/
        └── frontend.js             # Browser-side fetch + button wiring
```

---

## REST Endpoints

### `POST /wp-json/print-api/v1/token`

Issue a one-time download token.

**Headers**
```
Content-Type: application/json
X-WP-Nonce: <nonce>
```

**Body**
```json
{ "book_id": 123 }
```

**Response 200**
```json
{ "token": "a3f9c2…" }
```

**Response 403** — missing or bad nonce
```json
{ "code": "invalid_nonce", "message": "CSRF token missing or invalid…" }
```

---

### `GET /wp-json/print-api/v1/pdf?token=<token>`

Exchange a token for 3 PDF part URLs. The token is invalidated immediately.

**Response 200**
```json
{
  "parts": [
    "https://example.com/wp-content/uploads/print-api/book_123/part1.pdf",
    "https://example.com/wp-content/uploads/print-api/book_123/part2.pdf",
    "https://example.com/wp-content/uploads/print-api/book_123/part3.pdf"
  ],
  "book_id": 123
}
```

**Response 401** — token invalid, expired, or already used
```json
{ "code": "invalid_token", "message": "Token is invalid, expired, or has already been used." }
```

**Response 404** — token valid but PDF files missing on disk
```json
{ "code": "book_not_found", "message": "PDF files for this book could not be located on the server." }
```

---

### `GET /wp-json/print-api/v1/nonce`

Return a fresh WP REST nonce. Useful for SPAs that need a nonce before the first page load.

**Response 200**
```json
{ "nonce": "abc123…" }
```

---

## Configuration

All configuration is in `print-api.php` via constants:

| Constant | Default | Description |
|---|---|---|
| `PRINT_API_REQUIRE_LOGIN` | `false` | Set to `true` to require a logged-in WordPress session before a token can be issued |
| `Print_API_Token_Manager::TOKEN_TTL` | `300` | Token lifetime in seconds (change in `class-token-manager.php`) |

---

## PDF File Layout

PDFs must be placed in the WordPress uploads directory:

```
wp-content/uploads/print-api/
└── book_{id}/
    ├── part1.pdf
    ├── part2.pdf
    └── part3.pdf
```

The plugin creates this directory and a blocking `.htaccess` on activation, so files are **not** directly downloadable via HTTP — they can only be accessed through the token flow.

To add a new book just create the folder and drop in 3 PDF parts. No code change required.

---

## Frontend Usage

### Add a download button

Add `data-print-book="<book_id>"` to any element:

```html
<button data-print-book="123">Download Book</button>
```

The bundled `frontend.js` finds these automatically and handles the full token flow on click.

### Choose the download flow

In `assets/js/frontend.js`, change this line:

```js
const DOWNLOAD_FLOW = 'deeplink';   // 'deeplink' | 'browser'
```

| Value | Behaviour |
|---|---|
| `'deeplink'` | Redirects to `app://print?token=…` — native app handles the download |
| `'browser'`  | Exchanges token in the browser and opens each PDF part in a new tab |

### Manual fetch (custom UI)

```js
// Nonce and REST URL are injected by WordPress via wp_localize_script()
const res = await fetch('/wp-json/print-api/v1/token', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': printApiConfig.nonce,
  },
  body: JSON.stringify({ book_id: 123 }),
});
const { token } = await res.json();

// Option A — deep link to native app
window.location.href = `app://print?token=${token}`;

// Option B — exchange for PDF URLs in the browser
const pdfRes = await fetch(`/wp-json/print-api/v1/pdf?token=${token}`);
const { parts } = await pdfRes.json();
// parts = ["https://.../part1.pdf", "https://.../part2.pdf", "https://.../part3.pdf"]
```

---

## Local Testing (LocalWP on Windows + WSL2)

This guide assumes LocalWP runs on **Windows** and you run `curl` commands from **WSL2**.
`mysite.local` is registered in the Windows hosts file but not in WSL2, so you need
to route requests through the Windows host IP.

### 1. Install LocalWP and create a site

Download from https://localwp.com/, create a new site named `mysite` so the URL is `mysite.local`.

### 2. Copy the plugin from WSL2 to LocalWP

LocalWP stores its files under `C:\Users\<you>\Local Sites\`. From WSL2 that path is
`/mnt/c/Users/<you>/Local Sites/`. Adjust the username in the path:

```bash
cp -r /path/to/print-api "/mnt/c/Users/<you>/Local Sites/mysite/app/public/wp-content/plugins/"
```

### 3. Activate in WP Admin

Go to **WP Admin → Plugins** and click **Activate** next to "Print API".

Also confirm pretty permalinks are enabled — without them the REST API returns 404:
**WP Admin → Settings → Permalinks → select "Post name" → Save Changes**

### 4. Upload PDF files

The plugin expects files named exactly `part1.pdf`, `part2.pdf`, `part3.pdf` inside
a folder named `book_{id}`. Create the folder and drop in the files from Windows Explorer:

```
C:\Users\<you>\Local Sites\mysite\app\public\wp-content\uploads\print-api\book_123\part1.pdf
C:\Users\<you>\Local Sites\mysite\app\public\wp-content\uploads\print-api\book_123\part2.pdf
C:\Users\<you>\Local Sites\mysite\app\public\wp-content\uploads\print-api\book_123\part3.pdf
```

Or create placeholder files from WSL2 for a quick test:

```bash
DIR="/mnt/c/Users/<you>/Local Sites/mysite/app/public/wp-content/uploads/print-api/book_123"
mkdir -p "$DIR"
for i in 1 2 3; do
  echo "%PDF-1.4 placeholder" > "$DIR/part${i}.pdf"
done
```

### 5. Find the Windows host IP from WSL2

WSL2 cannot resolve `mysite.local` directly. Use curl's `--resolve` flag to point
the hostname at the Windows gateway IP so requests reach LocalWP:

```bash
# Get the Windows host IP (this is the gateway WSL2 uses to reach Windows)
WINDOWS_IP=$(ip route show | grep default | awk '{print $3}')
echo "Windows IP: $WINDOWS_IP"
# Typically something like 172.18.80.1

# Verify the site is reachable
curl -s --resolve "mysite.local:80:$WINDOWS_IP" 'http://mysite.local/wp-json/' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print('REST OK:', d['name'])"
# Expected: REST OK: mysite
```

If you get a JSON parse error instead of "REST OK", pretty permalinks are not enabled
(see step 3).

### 6. Set the RESOLVE helper variable

Put this at the top of your shell session so every curl command below uses it automatically:

```bash
WINDOWS_IP=$(ip route show | grep default | awk '{print $3}')
RESOLVE="--resolve mysite.local:80:$WINDOWS_IP"
```

### 7. Get a nonce

No login is required because `PRINT_API_REQUIRE_LOGIN` is `false` by default.
WordPress generates a valid nonce for anonymous users. The only rule is that the nonce
request and the token request must happen in the **same user context** — here both are
anonymous (no session cookie), so WordPress sees `user_id=0` for both and verification passes.

```bash
NONCE=$(curl -s $RESOLVE 'http://mysite.local/wp-json/print-api/v1/nonce' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['nonce'])")
echo "Nonce: [$NONCE]"
# Expected: Nonce: [a1b2c3d4e5]
```

### 8. Get a token

```bash
TOKEN=$(curl -s $RESOLVE \
  -X POST 'http://mysite.local/wp-json/print-api/v1/token' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $NONCE" \
  -d '{"book_id": 123}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
echo "Token: [$TOKEN]"
# Expected: Token: [64-char hex string]
```

### 9. Exchange token for PDF URLs

```bash
# First call — succeeds and returns 3 URLs
curl -s $RESOLVE "http://mysite.local/wp-json/print-api/v1/pdf?token=$TOKEN" \
  | python3 -m json.tool
```

Expected:
```json
{
    "parts": [
        "http://mysite.local/wp-content/uploads/print-api/book_123/part1.pdf",
        "http://mysite.local/wp-content/uploads/print-api/book_123/part2.pdf",
        "http://mysite.local/wp-content/uploads/print-api/book_123/part3.pdf"
    ],
    "book_id": 123
}
```

```bash
# Second call with the same token — must be rejected (single-use guarantee)
curl -s $RESOLVE "http://mysite.local/wp-json/print-api/v1/pdf?token=$TOKEN" \
  | python3 -m json.tool
```

Expected:
```json
{
    "code": "invalid_token",
    "message": "Token is invalid, expired, or has already been used.",
    "data": {"status": 401}
}
```

### 10. Debug: find out what path the plugin expects

If you get `book_not_found`, enable WP_DEBUG in `wp-config.php` and call the debug endpoint
to see the exact filesystem path and which files are missing:

```bash
# 1. In wp-config.php, temporarily change:  define('WP_DEBUG', false)  →  define('WP_DEBUG', true)

# 2. Call the debug endpoint
curl -s $RESOLVE "http://mysite.local/wp-json/print-api/v1/debug/book/123" \
  | python3 -m json.tool
# Returns: expected_dir, dir_exists, and per-file exists flags

# 3. Revert WP_DEBUG back to false when done
```

### 11. Test the frontend button

Add this HTML to any WordPress page (use the HTML block in Gutenberg):

```html
<button data-print-book="123">Download Book</button>
```

Open the page in the browser, open **DevTools → Network tab**, click the button, and
watch two requests fire in sequence: `POST /token` then `GET /pdf?token=…`.

---

## VPS Deployment

### Prerequisites on the VPS

- WordPress installed (e.g., at `/var/www/html`)
- PHP 7.4+ (for `random_bytes`)
- WP-CLI installed (`wp --info` should work)
- SSH access

### 1. Upload the plugin

Run this from your local machine:

```bash
rsync -avz ./print-api/ user@your-vps:/var/www/html/wp-content/plugins/print-api/
```

### 2. Upload PDF files

```bash
rsync -avz ./pdfs/book_123/ user@your-vps:/var/www/html/wp-content/uploads/print-api/book_123/
```

The directory structure on the VPS must be:
```
/var/www/html/wp-content/uploads/print-api/book_123/part1.pdf
/var/www/html/wp-content/uploads/print-api/book_123/part2.pdf
/var/www/html/wp-content/uploads/print-api/book_123/part3.pdf
```

### 3. Activate the plugin

SSH into the VPS and run:

```bash
ssh user@your-vps
wp plugin activate print-api --path=/var/www/html
```

Activation also creates the uploads directory and `.htaccess` protection automatically.

### 4. Verify with curl

Replace `mysite.local` with your actual domain:

```bash
# Get nonce (anonymous — no login needed when PRINT_API_REQUIRE_LOGIN=false)
NONCE=$(curl -s 'https://example.com/wp-json/print-api/v1/nonce' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['nonce'])")
echo "Nonce: [$NONCE]"

# Get token (same anonymous context — no session cookie)
TOKEN=$(curl -s \
  -X POST 'https://example.com/wp-json/print-api/v1/token' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $NONCE" \
  -d '{"book_id": 123}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
echo "Token: [$TOKEN]"

# Exchange token for PDF URLs
curl "https://example.com/wp-json/print-api/v1/pdf?token=$TOKEN"
```

### 5. Nginx — allow REST API (if blocked)

If you use Nginx and the REST API returns 404, add this inside your `server {}` block:

```nginx
location /wp-json/ {
    try_files $uri $uri/ /index.php?$args;
}
```

Then reload: `sudo nginx -s reload`

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `curl: Failed to connect` from WSL2 | `mysite.local` not in WSL2 hosts | Use `--resolve mysite.local:80:$(ip route show \| grep default \| awk '{print $3}')` |
| JSON parse error on REST check | Pretty permalinks disabled | WP Admin → Settings → Permalinks → select "Post name" → Save |
| `$NONCE` is empty | REST API returned an error instead of JSON | Run `curl -s $RESOLVE http://mysite.local/wp-json/print-api/v1/nonce` raw to see the actual error |
| 403 `invalid_nonce` | Nonce fetched while logged in but token called without cookies (user context mismatch) | Fetch nonce and call `/token` without any session cookie — both anonymous |
| 403 `invalid_nonce` | Nonce older than 12 hours | Re-fetch nonce and use immediately |
| 404 `book_not_found` | Files don't exist at the expected path | Enable `WP_DEBUG=true`, call `/debug/book/123` to see exact expected paths and which files are missing |
| 404 `book_not_found` | Files named `part_1.pdf` instead of `part1.pdf` | Rename to `part1.pdf`, `part2.pdf`, `part3.pdf` (no underscore between "part" and the number) |
| 404 on any `/wp-json/…` route | Pretty permalinks disabled | WP Admin → Settings → Permalinks → save any option except "Plain" |
| 401 `invalid_token` on first use | Token TTL elapsed (>5 min between steps) | Re-run from the nonce step; or increase `TOKEN_TTL` in `class-token-manager.php` |
| Plugin not showing in WP Admin | Wrong folder name | Folder inside `plugins/` must be named `print-api` and contain `print-api.php` |
| Direct PDF URL returns 403 | `.htaccess` blocking direct access | This is expected — files must be accessed through the token flow, not directly |
