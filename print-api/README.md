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

## Local Testing (LocalWP)

### 1. Install LocalWP

Download from https://localwp.com/ and create a new site (e.g., `mysite.local`).

### 2. Copy the plugin

```bash
cp -r print-api/ ~/Local\ Sites/mysite/app/public/wp-content/plugins/
```

### 3. Activate

Go to **WP Admin → Plugins** and click **Activate** next to "Print API".

### 4. Create dummy PDF files

```bash
UPLOADS=~/Local\ Sites/mysite/app/public/wp-content/uploads

mkdir -p "$UPLOADS/print-api/book_123"

for i in 1 2 3; do
  echo "%PDF-1.4 placeholder part $i" > "$UPLOADS/print-api/book_123/part$i.pdf"
done
```

### 5. Check the REST API is reachable

Before fetching a nonce, confirm that WordPress pretty permalinks are enabled
and the REST API responds. This is the most common reason nonces come back empty.

```bash
curl -s 'http://mysite.local/wp-json/' | python3 -c "import sys,json; d=json.load(sys.stdin); print('REST OK — site:', d['name'])"
```

If you see `REST OK — site: My Site` you are good. If you get a 404 HTML page:
- Go to **WP Admin → Settings → Permalinks**
- Select any option other than "Plain" (e.g. "Post name")
- Click **Save Changes** — this regenerates the `.htaccess` rewrite rules

### 6. Get a nonce

> **Why no login step?**
> WordPress generates nonces for both logged-in and anonymous users.
> Because `PRINT_API_REQUIRE_LOGIN` is `false` (the default), an **anonymous
> nonce works fine**. Critically, the nonce and the token request must share
> the same user context — both are made here without a session cookie, so
> WordPress sees user_id=0 for both and the verification succeeds.
> Mixing a logged-in nonce with an anonymous token request is what causes
> an empty/invalid nonce.

```bash
# Step 1: fetch the raw response first — so you can see exactly what is returned
curl -s 'http://mysite.local/wp-json/print-api/v1/nonce'
# Expected output: {"nonce":"a1b2c3d4e5..."}

# Step 2: extract the value with python3 (reliable JSON parsing, no grep/cut)
NONCE=$(curl -s 'http://mysite.local/wp-json/print-api/v1/nonce' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['nonce'])")

echo "Nonce: [$NONCE]"
# If brackets show an empty value, run Step 1 above to see the raw error
```

### 7. Request a token

```bash
# Step 1: see the raw response first
curl -s \
  -X POST 'http://mysite.local/wp-json/print-api/v1/token' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $NONCE" \
  -d '{"book_id": 123}'
# Expected: {"token":"a3f9c2d1..."}

# Step 2: extract the token
TOKEN=$(curl -s \
  -X POST 'http://mysite.local/wp-json/print-api/v1/token' \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $NONCE" \
  -d '{"book_id": 123}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")

echo "Token: [$TOKEN]"
```

Expected response:
```json
{ "token": "a3f9c2d1…" }
```

### 7. Exchange token for PDF URLs

```bash
# First call — succeeds
curl "http://mysite.local/wp-json/print-api/v1/pdf?token=$TOKEN"
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
# Second call with same token — must fail
curl "http://mysite.local/wp-json/print-api/v1/pdf?token=$TOKEN"
```

Expected:
```json
{
  "code": "invalid_token",
  "message": "Token is invalid, expired, or has already been used.",
  "data": { "status": 401 }
}
```

### 8. Test the frontend button

Add this to any WordPress page/post (use the HTML block in Gutenberg):

```html
<button data-print-book="123">Download Book</button>
```

Open the page, open browser DevTools → Network tab, click the button, and watch the two requests fire in sequence.

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
| `$NONCE` is empty after extraction | REST API not reachable (404) | Enable pretty permalinks: WP Admin → Settings → Permalinks → save |
| `$NONCE` is empty after extraction | `grep/cut` pipeline silent failure | Use `python3 -c "import sys,json; print(json.load(sys.stdin)['nonce'])"` instead |
| 403 `invalid_nonce` on `/token` | Nonce user context mismatch | Fetch nonce and call `/token` with the **same** session state — either both with cookies or both without. Do **not** mix logged-in nonce with anonymous token request |
| 403 `invalid_nonce` on `/token` | Nonce expired (>12 hours old) | Re-fetch the nonce and retry immediately |
| 404 on any `/wp-json/…` route | Pretty permalinks not set | WP Admin → Settings → Permalinks → save any option other than "Plain" |
| 401 `invalid_token` on first use | Token TTL elapsed (>5 min) | Increase `TOKEN_TTL` in `class-token-manager.php` |
| 404 `book_not_found` | PDF files missing on disk | Check path: `uploads/print-api/book_{id}/part1.pdf` exists |
| Plugin not showing in WP Admin | Wrong directory name | Folder must be named `print-api` and contain `print-api.php` |
| Direct PDF URL returns 403 | `.htaccess` working correctly | This is expected — use the token flow |
