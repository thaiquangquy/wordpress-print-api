# Integration Guide — Cyberthrone Print API

This guide covers everything needed to integrate with the print-api plugin on two sides: the **Electron desktop app** (cyberthrone) and the **WordPress site** (browser/theme).

---

## How it works

```
Browser (logged-in user)
  │
  ├─ 1. POST /token  { book_id }  ← requires nonce + WP session
  │      ↳ user meta reset to "no"
  │      ↳ returns { token }
  │
  ├─ 2. fires  cyberthrone://print?token=<token>
  │
  └─ 3. polls GET /install-status  (every ~1 s, up to ~8 s)
         ↳ "yes"  → app opened, continue normally
         ↳ "no" after timeout → show "app not installed" error

Electron app (no WP session needed)
  │
  ├─ 4. POST /mark-installed  { token }   ← NEW — call this first
  │      ↳ sets user meta to "yes"
  │      ↳ returns { success: true }
  │
  ├─ 5. POST /pdf  { token }
  │      ↳ returns { parts: [...tokens], part_count: N, book_id: N }
  │
  └─ 6. POST /download  { token }  (once per part token)
         ↳ streams PDF bytes
```

All endpoints live at: `{SITE_URL}/wp-json/print-api/v1/`

---

## Electron app

### Step 1 — Register the custom URL scheme

In your `package.json` / `electron-builder` config:

```json
{
  "protocols": [
    {
      "name": "Cyberthrone Print",
      "schemes": ["cyberthrone"]
    }
  ]
}
```

In `main.js`, handle the protocol and extract the token:

```js
app.setAsDefaultProtocolClient('cyberthrone');

// macOS — fired when already running
app.on('open-url', (event, url) => {
  event.preventDefault();
  handleDeepLink(url);
});

// Windows / Linux — token arrives in argv when app is launched fresh
const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', (_event, argv) => {
    const url = argv.find(a => a.startsWith('cyberthrone://'));
    if (url) handleDeepLink(url);
  });

  app.whenReady().then(() => {
    // Windows: deep link arrives as the last argv on cold start
    const url = process.argv.find(a => a.startsWith('cyberthrone://'));
    if (url) handleDeepLink(url);
  });
}
```

### Step 2 — Parse the deep link

```js
function handleDeepLink(url) {
  // url = "cyberthrone://print?token=<64-char-hex>"
  const { searchParams } = new URL(url);
  const token = searchParams.get('token');
  if (token) startPrintFlow(token);
}
```

### Step 3 — Call `/mark-installed` BEFORE `/pdf`

This is the critical new step. The WordPress site is polling for this signal.

```js
const SITE = 'https://your-wordpress-site.com';

async function startPrintFlow(token) {
  // ── 1. Signal that the app is installed and was allowed to open ──────────
  try {
    const markRes = await fetch(`${SITE}/wp-json/print-api/v1/mark-installed`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    });

    if (!markRes.ok) {
      // Token may have expired (> 5 min since the user clicked)
      // or already been consumed. Show a friendly error.
      showError('Session expired. Please click the print button again.');
      return;
    }
  } catch (err) {
    // Network error — still try to proceed; /pdf will catch it if the token is bad
    console.warn('mark-installed failed:', err);
  }

  // ── 2. Exchange book token for part tokens ────────────────────────────────
  const pdfRes = await fetch(`${SITE}/wp-json/print-api/v1/pdf`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token }),
  });

  if (!pdfRes.ok) {
    showError('Could not start download. Please try again.');
    return;
  }

  const { parts, part_count } = await pdfRes.json();

  // ── 3. Download each part ─────────────────────────────────────────────────
  for (let i = 0; i < part_count; i++) {
    await downloadPart(parts[i], i + 1, part_count);
  }
}

async function downloadPart(token, partNum, total) {
  const res = await fetch(`${SITE}/wp-json/print-api/v1/download`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token }),
  });

  if (!res.ok) {
    showError(`Failed to download part ${partNum}.`);
    return;
  }

  const buffer = await res.arrayBuffer();
  // Save buffer to disk, pass to printer, etc.
  savePart(buffer, partNum);
}
```

### Important notes for the Electron side

| Point | Detail |
|-------|--------|
| **Call `/mark-installed` first** | Before `/pdf`. The browser is waiting on this signal for up to ~8 seconds. |
| **Token TTL is 5 minutes** | If the user takes longer than 5 min to confirm the OS prompt, the token is expired. Return a clear error. |
| **`/mark-installed` is non-destructive** | It uses `peek()` — the token is left intact for `/pdf` to consume. |
| **No auth headers needed** | The app has no WordPress session. All three app-side endpoints (`/mark-installed`, `/pdf`, `/download`) accept the token directly. |
| **CORS** | If the Electron app makes requests from a renderer process (`BrowserWindow`), WordPress will accept them. From the main process (Node.js `fetch`/`axios`), no CORS headers are sent — that's fine. |

---

## WordPress site (browser / theme)

### Step 1 — Add a `/install-status` endpoint

Add this to your theme's `functions.php` or a small companion plugin. It lets the browser poll to know whether the Electron app responded.

```php
add_action( 'rest_api_init', function () {
    register_rest_route( 'print-api/v1', '/install-status', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            if ( ! is_user_logged_in() ) {
                return new WP_Error( 'not_logged_in', '', [ 'status' => 401 ] );
            }
            $value = get_user_meta( get_current_user_id(), 'installed_cyberthrone_software', true );
            return rest_ensure_response( [ 'installed' => ( $value === 'yes' ) ] );
        },
    ] );
} );
```

> This is kept separate from the print-api plugin intentionally — it's a thin read-only view of user meta that your theme controls.

### Step 2 — Update the print button handler

The existing `printApiConfig` object (injected by the plugin via `wp_localize_script`) already contains `nonce` and `restUrl`. Extend your button click handler:

```js
document.querySelectorAll('[data-print-book]').forEach(btn => {
  btn.addEventListener('click', async () => {
    const bookId = parseInt(btn.dataset.printBook, 10);
    if (!bookId) return;

    btn.disabled = true;
    btn.textContent = 'Opening app…';

    // ── 1. Get a book token (resets installed flag to "no") ───────────────
    let token;
    try {
      const res = await fetch(`${printApiConfig.restUrl}/token`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': printApiConfig.nonce,
        },
        body: JSON.stringify({ book_id: bookId }),
      });

      if (!res.ok) {
        const err = await res.json();
        throw new Error(err.message || 'Could not start download.');
      }

      ({ token } = await res.json());
    } catch (err) {
      showPrintError(btn, err.message);
      return;
    }

    // ── 2. Fire the deep link ─────────────────────────────────────────────
    window.location.href = `cyberthrone://print?token=${token}`;

    // ── 3. Poll for the app's response ───────────────────────────────────
    // The Electron app calls /mark-installed which flips the flag to "yes".
    // We poll for up to TIMEOUT_MS, checking every INTERVAL_MS.
    const TIMEOUT_MS  = 8000;
    const INTERVAL_MS = 1000;
    const deadline    = Date.now() + TIMEOUT_MS;

    const installed = await new Promise(resolve => {
      const check = async () => {
        try {
          const res  = await fetch(`${printApiConfig.restUrl}/install-status`);
          const data = await res.json();
          if (data.installed) return resolve(true);
        } catch (_) { /* network hiccup — keep polling */ }

        if (Date.now() >= deadline) return resolve(false);
        setTimeout(check, INTERVAL_MS);
      };
      setTimeout(check, INTERVAL_MS); // first check after 1 s
    });

    if (!installed) {
      showPrintError(
        btn,
        'The Cyberthrone app did not respond. Please make sure it is installed and try again.'
      );
      return;
    }

    // App confirmed open — restore button
    btn.disabled  = false;
    btn.textContent = 'Print';
  });
});

function showPrintError(btn, message) {
  btn.disabled  = false;
  btn.textContent = 'Print';
  // Replace with whatever UI pattern your theme uses
  alert(message);
}
```

### Step 3 — Nonce refresh (long-lived pages)

WP REST nonces expire after 24 hours. For pages that stay open a long time, refresh the nonce before using it:

```js
async function getFreshNonce() {
  const res  = await fetch(`${printApiConfig.restUrl}/nonce`);
  const data = await res.json();
  return data.nonce;
}

// Use getFreshNonce() instead of printApiConfig.nonce if the page
// has been open for more than a few hours.
```

---

## Timing diagram

```
t=0s    User clicks Print
        Browser → POST /token → { token }
        Browser fires cyberthrone://print?token=<token>
        OS shows "Allow cyberthrone to open?" prompt

t=1s    Browser polls GET /install-status → { installed: false }

t=2s    User clicks "Allow" in OS prompt
        Electron app opens / gets focus
        App → POST /mark-installed { token } → { success: true }
             (user meta flips to "yes")
        App → POST /pdf { token } → { parts, part_count }
        App → POST /download { token } × N (streams PDF)

t=2s    Browser polls GET /install-status → { installed: true }
        Browser hides loading state, shows success

────────── unhappy path ──────────

t=8s    Browser still polling, installed still false
        Browser shows: "App not installed or permission denied"
        Token expires at t=300s (irrelevant — flow already ended)
```

---

## API Reference

All endpoints: `POST {SITE_URL}/wp-json/print-api/v1/<endpoint>`
All request bodies are JSON (`Content-Type: application/json`).

---

### POST `/token`

Issues a one-time book token. Resets the `installed_cyberthrone_software` flag to `"no"`.

**Headers:**
| Header | Value |
|--------|-------|
| `X-WP-Nonce` | Fresh nonce from `GET /nonce` |
| `Content-Type` | `application/json` |

**Body:**
```json
{ "book_id": 1, "light_book": false }
```
`light_book` is optional (default `false`). Set to `true` to serve `light.pdf` instead of the full parts.

**Response `200`:**
```json
{ "token": "<64-char-hex>" }
```

---

### GET `/nonce`

Returns a fresh WP REST nonce. No auth required.

**Response `200`:**
```json
{ "nonce": "abc123def456" }
```

---

### POST `/mark-installed`

Called by the Electron app immediately after the deep link opens it. Uses `peek()` — the book token is **not consumed** and remains valid for the subsequent `/pdf` call.

Flips `installed_cyberthrone_software` user meta to `"yes"` for the user who originally requested the token, so the browser polling `/install-status` can detect that the app opened successfully.

**Headers:**
| Header | Value |
|--------|-------|
| `Content-Type` | `application/json` |

**Body:**
```json
{ "token": "<book-token>" }
```

**Response `200`:**
```json
{ "success": true }
```

**Response `401`** — token invalid, expired, or already consumed by `/pdf`:
```json
{ "code": "invalid_token", "message": "Token is invalid or expired." }
```

> Call this **before** `/pdf`. The browser polls `/install-status` for up to ~8 seconds waiting for this signal.

---

### POST `/pdf`

Exchanges a book token for one-time per-part tokens. Consumes the book token (it cannot be reused after this call).

**Headers:**
| Header | Value |
|--------|-------|
| `Content-Type` | `application/json` |

**Body (normal):**
```json
{ "token": "<book-token>" }
```

**Body (dev master token, `WP_DEBUG` only):**
```json
{ "token": "<master-token>", "book_id": 1, "light_book": false }
```

**Response `200`:**
```json
{
  "parts": ["<part1-token>", "<part2-token>"],
  "part_count": 2,
  "book_id": 1
}
```

---

### POST `/download`

Consumes a part token and streams the PDF bytes. The real file path is never exposed to the client.

**Headers:**
| Header | Value |
|--------|-------|
| `Content-Type` | `application/json` |

**Body:**
```json
{ "token": "<part-token>" }
```

**Response `200`:** Raw PDF bytes (`Content-Type: application/pdf`).

---

### GET `/install-status` *(theme-side, not in plugin)*

Read-only endpoint added to your theme's `functions.php`. Returns the current value of `installed_cyberthrone_software` for the logged-in user.

**Response `200`:**
```json
{ "installed": true }
```

---

### GET `/debug/book/{book_id}` *(WP_DEBUG only)*

Shows the expected filesystem paths and which part files were found on disk. Returns `403` in production.

**Response `200`:**
```json
{
  "book_id": 1,
  "expected_dir": "/var/www/.../uploads/private/books/1",
  "dir_exists": true,
  "part_count": 2,
  "files": {
    "part1": { "expected_path": "/.../part1.pdf", "exists": true },
    "part2": { "expected_path": "/.../part2.pdf", "exists": true },
    "part3": { "expected_path": "/.../part3.pdf", "exists": false }
  }
}
```

---

## Error reference

| Endpoint | HTTP | Meaning | What to do |
|----------|------|---------|------------|
| `POST /token` | 401 | Not logged in | Redirect to login page |
| `POST /token` | 403 | Bad or missing nonce | Reload page to get fresh nonce |
| `POST /token` | 429 | Rate limited | Wait ~60 s and retry |
| `POST /mark-installed` | 401 | Token invalid, expired, or already consumed by `/pdf` | Show "session expired, click print again" |
| `POST /pdf` | 401 | Token invalid/expired/used | Show "session expired, click print again" |
| `POST /pdf` | 404 | No PDF files found on server | Contact site admin |
| `POST /download` | 401 | Part token invalid/expired/used | Restart print flow |
| `POST /download` | 404 | PDF file missing | Contact site admin |

---

## Security notes

- **Tokens are single-use and expire in 5 minutes.** The book token is consumed by `/pdf`, not by `/mark-installed` (which only peeks). Part tokens are consumed by `/download`.
- **No sensitive data is ever sent to the client.** File paths live server-side only; the client only receives opaque tokens.
- **`/mark-installed` requires a valid, unexpired book token.** An attacker cannot flip someone's user meta without first obtaining a token issued to that user, which requires their WordPress session and a CSRF nonce.
- **`/install-status` is read-only and scoped to the current user.** It reveals nothing beyond a boolean for the authenticated user's own meta.
