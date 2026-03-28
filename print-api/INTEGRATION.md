# Print API — Integration Guide

This document covers two integration surfaces:

1. **WordPress** — how a site admin adds PDF book files and places download buttons on pages/posts.
2. **Electron client** — how the desktop app registers the `cyberthrone://` URL scheme and performs the full authenticated download flow.

---

## Table of Contents

- [Architecture overview](#architecture-overview)
- [API reference](#api-reference)
- [Part 1 — WordPress admin integration](#part-1--wordpress-admin-integration)
  - [1.1 Install and activate the plugin](#11-install-and-activate-the-plugin)
  - [1.2 Upload book files](#12-upload-book-files)
  - [1.3 Add a download button to a page or post](#13-add-a-download-button-to-a-page-or-post)
  - [1.4 Configure login requirement](#14-configure-login-requirement)
  - [1.5 Verify with the debug endpoint](#15-verify-with-the-debug-endpoint)
- [Part 2 — Electron client integration](#part-2--electron-client-integration)
  - [2.1 Register the custom URL scheme](#21-register-the-custom-url-scheme)
  - [2.2 Handle the deep-link in the main process](#22-handle-the-deep-link-in-the-main-process)
  - [2.3 Exchange the book token for part tokens](#23-exchange-the-book-token-for-part-tokens)
  - [2.4 Download each part](#24-download-each-part)
  - [2.5 Complete main-process example](#25-complete-main-process-example)
  - [2.6 Renderer-process helper (optional)](#26-renderer-process-helper-optional)
- [Token lifecycle and expiry](#token-lifecycle-and-expiry)
- [Error reference](#error-reference)

---

## Architecture overview

The full download flow spans three systems:

```
WordPress (browser page)          WordPress REST API          Electron app
─────────────────────────         ──────────────────          ────────────
User clicks Download button
        │
        ▼
POST /token  ──────────────────►  issues one-time
{book_id, X-WP-Nonce}             book token (5 min TTL)
        │
        ◄──────────────────────  { token: "<book-token>" }
        │
window.location = cyberthrone://print?token=<book-token>
        │
        │  (OS hands the URL to the registered handler)
        │
        └─────────────────────────────────────────────────►  app wakes up
                                                              parses token
                                                                  │
                                                GET /pdf?token=<book-token>
                                                                  │──────►
                                                                  │  consumes book token
                                                                  │  returns N part tokens
                                                                  ◄──────
                                                                  │
                                              for each part token:
                                              GET /download?token=<part-token>
                                                                  │──────►
                                                                  │  consumes part token
                                                                  │  streams PDF bytes
                                                                  ◄──────
                                                                  │
                                                             save part1.pdf … partN.pdf
```

**Key security properties:**

- The book token is one-time and expires in 5 minutes.
- Each part token is also one-time and expires in 5 minutes.
- The actual PDF file paths are never sent to any client. The server streams bytes directly.
- A captured token or URL cannot be replayed after it is consumed or after the TTL.

---

## API reference

Base URL: `https://<your-wordpress-site>/wp-json/print-api/v1`

### `POST /token`

Issues a one-time book-level download token. Requires the user to be logged in (configurable).

**Request headers**

| Header | Value |
|--------|-------|
| `Content-Type` | `application/json` |
| `X-WP-Nonce` | WordPress REST nonce (injected by the plugin as `printApiConfig.nonce`) |

**Request body**

```json
{ "book_id": 42 }
```

**Response `200`**

```json
{ "token": "a3f8...64hexchars" }
```

**Error responses**

| HTTP | Code | Meaning |
|------|------|---------|
| 403 | `invalid_nonce` | Missing or invalid `X-WP-Nonce` header |
| 401 | `login_required` | `PRINT_API_REQUIRE_LOGIN` is `true` and user is not logged in |

---

### `GET /pdf?token=<book-token>`

Consumes the book token and returns one one-time download token per PDF part.

**Query parameters**

| Parameter | Description |
|-----------|-------------|
| `token` | The book token returned by `POST /token` |

**Response `200`**

```json
{
  "book_id": 42,
  "part_count": 3,
  "parts": [
    "b1c2...64hexchars",
    "d3e4...64hexchars",
    "f5a6...64hexchars"
  ]
}
```

`parts` is an array of `part_count` one-time tokens. `parts[0]` is for part 1, `parts[1]` for part 2, and so on.

**Error responses**

| HTTP | Code | Meaning |
|------|------|---------|
| 401 | `invalid_token` | Token is invalid, expired, already used, or is a part token (not a book token) |
| 404 | `book_not_found` | No PDF files found on disk for this book ID |

---

### `GET /download?token=<part-token>`

Consumes a part token and streams the PDF bytes directly. No redirect — the response body is the file.

**Query parameters**

| Parameter | Description |
|-----------|-------------|
| `token` | A part token from the `parts` array returned by `GET /pdf` |

**Response `200`**

Raw PDF bytes with headers:

```
Content-Type: application/pdf
Content-Disposition: attachment; filename="book_42_part1.pdf"
Content-Length: <bytes>
Cache-Control: no-store, no-cache, must-revalidate
```

**Error responses**

| HTTP | Code | Meaning |
|------|------|---------|
| 401 | `invalid_token` | Token is invalid, expired, already used, or is a book token (not a part token) |
| 404 | `book_not_found` | Part file missing on disk (server configuration issue) |

---

### `GET /nonce`

Returns a fresh WordPress REST nonce. Useful for single-page apps that need a nonce before any page has loaded.

**Response `200`**

```json
{ "nonce": "abc123..." }
```

---

### `GET /debug/book/{book_id}`

Returns the expected file paths and existence status for a book. **Only available when `WP_DEBUG = true` in `wp-config.php`.** Returns `403` in production.

**Response `200`**

```json
{
  "book_id": 42,
  "expected_dir": "/var/www/html/wp-content/uploads/print-api/book_42",
  "dir_exists": true,
  "part_count": 3,
  "files": {
    "part1": { "expected_path": "…/part1.pdf", "exists": true },
    "part2": { "expected_path": "…/part2.pdf", "exists": true },
    "part3": { "expected_path": "…/part3.pdf", "exists": true },
    "part4": { "expected_path": "…/part4.pdf", "exists": false }
  },
  "uploads_base": "/var/www/html/wp-content/uploads"
}
```

The response always includes the first missing part so you can see exactly where the sequence stops.

---

## Part 1 — WordPress admin integration

### 1.1 Install and activate the plugin

1. Copy the `print-api` folder to `wp-content/plugins/print-api/`.
2. In **WP Admin → Plugins**, find **Print API** and click **Activate**.

On activation the plugin creates `wp-content/uploads/print-api/` and writes an `.htaccess` file that blocks all direct HTTP access to that directory.

---

### 1.2 Upload book files

For each book, create a numbered subdirectory and drop in the PDF parts:

```
wp-content/uploads/print-api/
└── book_42/
    ├── part1.pdf
    ├── part2.pdf
    └── part3.pdf        ← any number of parts is supported
```

**Rules:**

- The directory name must be `book_` followed by the book's numeric WordPress post/product ID (or any integer you choose to use as a stable identifier).
- Parts must be named `part1.pdf`, `part2.pdf`, … `partN.pdf` with no gaps. The plugin counts them automatically by walking the sequence until the first missing file.
- There is no upper limit on the number of parts.

---

### 1.3 Add a download button to a page or post

The plugin automatically wires up any HTML element that has a `data-print-book` attribute. No shortcode or custom block is required.

#### Option A — Block editor (Gutenberg)

1. Insert a **Buttons** block (or any block that produces a `<button>` or `<a>` tag).
2. Switch the block to **Edit as HTML** (three-dot menu → **Edit as HTML**).
3. Add `data-print-book="42"` to the element, replacing `42` with the real book ID:

```html
<button class="wp-block-button__link" data-print-book="42">
  Download Book
</button>
```

#### Option B — Classic editor / raw HTML block

Paste the following HTML anywhere in the page content:

```html
<button data-print-book="42">Download Book</button>
```

#### Option C — Theme template or custom HTML widget

```html
<!-- Trigger the deep-link flow for book ID 42 -->
<button data-print-book="42" class="download-btn">
  Download Book
</button>
```

**How it works at runtime:**

1. The visitor (who must be logged in if `PRINT_API_REQUIRE_LOGIN` is `true`) clicks the button.
2. The plugin's JavaScript calls `POST /token` with the book ID and the WP REST nonce.
3. On success it redirects the browser to `cyberthrone://print?token=<book-token>`.
4. The OS launches (or focuses) the registered Electron app and passes the URL to it.

The button is automatically disabled while the request is in flight to prevent double-clicks.

#### Finding the book ID

The book ID is the WordPress post or WooCommerce product ID shown in the URL bar when you edit the item:

```
/wp-admin/post.php?post=42&action=edit
                        ^^
                    this is the book_id
```

---

### 1.4 Configure login requirement

Open `print-api.php` and change the constant near the top:

```php
// true  → only logged-in users can request a token (recommended for paid content)
// false → any visitor can request a token
define( 'PRINT_API_REQUIRE_LOGIN', true );
```

---

### 1.5 Verify with the debug endpoint

Enable `WP_DEBUG` temporarily in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
```

Then visit:

```
https://<site>/wp-json/print-api/v1/debug/book/42
```

The response shows whether the directory and each part file exist. Turn `WP_DEBUG` off again when done.

---

## Part 2 — Electron client integration

### 2.1 Register the custom URL scheme

The `cyberthrone://` scheme must be registered with the OS before any `BrowserWindow` is created — i.e., at the very top of `main.js` before `app.whenReady()`.

```js
// main.js
const { app } = require('electron');

// Register cyberthrone:// as a single-instance protocol handler.
// Must be called before app.whenReady().
if (process.defaultApp) {
  // Development: argv[2] holds the URL when launched via `electron .`
  if (process.argv.length >= 2) {
    app.setAsDefaultProtocolClient('cyberthrone', process.execPath, [
      path.resolve(process.argv[1]),
    ]);
  }
} else {
  // Production packaged app
  app.setAsDefaultProtocolClient('cyberthrone');
}
```

#### macOS / Linux — enforce single instance

On macOS and Linux, a second app launch fires `open-url` on the existing instance. Use `app.requestSingleInstanceLock()` to forward it:

```js
const gotLock = app.requestSingleInstanceLock();

if (!gotLock) {
  app.quit();
} else {
  // Windows / Linux: second instance passes the URL in argv
  app.on('second-instance', (_event, argv) => {
    const url = argv.find((arg) => arg.startsWith('cyberthrone://'));
    if (url) handleDeepLink(url);
  });

  // macOS: OS fires open-url on the existing instance
  app.on('open-url', (_event, url) => {
    handleDeepLink(url);
  });
}
```

#### Windows — read from argv on first launch

On Windows the URL is passed as a command-line argument when the app is not already running:

```js
app.whenReady().then(() => {
  // Check if the app was launched via a cyberthrone:// link
  const url = process.argv.find((arg) => arg.startsWith('cyberthrone://'));
  if (url) handleDeepLink(url);
});
```

---

### 2.2 Handle the deep-link in the main process

The URL arriving from the browser looks like:

```
cyberthrone://print?token=a3f8c2...64hexchars
```

Parse it and kick off the download:

```js
const { URL } = require('url');

/**
 * Entry point for every cyberthrone:// deep link.
 * @param {string} rawUrl - e.g. "cyberthrone://print?token=a3f8..."
 */
async function handleDeepLink(rawUrl) {
  let token;
  try {
    const parsed = new URL(rawUrl);
    token = parsed.searchParams.get('token');
  } catch {
    console.error('Print API: malformed deep-link URL', rawUrl);
    return;
  }

  if (!token || !/^[a-f0-9]{64}$/.test(token)) {
    console.error('Print API: invalid or missing token in deep link');
    return;
  }

  try {
    await downloadBook(token);
  } catch (err) {
    console.error('Print API: download failed', err.message);
    // Show an error dialog or notify the renderer process here
  }
}
```

---

### 2.3 Exchange the book token for part tokens

Call `GET /pdf?token=<book-token>`. This consumes the book token and returns an array of one-time part tokens plus the total part count.

```js
const https = require('https');   // or use node-fetch / axios

const WP_REST_BASE = 'https://<your-wordpress-site>/wp-json/print-api/v1';

/**
 * Exchange a book token for per-part download tokens.
 * @param {string} bookToken
 * @returns {Promise<{ bookId: number, partCount: number, partTokens: string[] }>}
 */
async function getPartTokens(bookToken) {
  const url = `${WP_REST_BASE}/pdf?token=${encodeURIComponent(bookToken)}`;
  const res = await fetch(url);

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message || `GET /pdf failed with HTTP ${res.status}`);
  }

  const data = await res.json();
  // data = { book_id: 42, part_count: 3, parts: ["token1", "token2", "token3"] }
  return {
    bookId: data.book_id,
    partCount: data.part_count,
    partTokens: data.parts,
  };
}
```

> **Important:** Call this only once per book token. The book token is consumed on the first call. If the call fails (network error, etc.) the token is gone and the user must click the download button again on the WordPress page.

---

### 2.4 Download each part

Call `GET /download?token=<part-token>` for each entry in `partTokens`. The response body is the raw PDF bytes.

```js
const fs = require('fs');
const path = require('path');
const { app } = require('electron');

/**
 * Download a single PDF part to disk.
 * @param {string} partToken  - One-time part token from GET /pdf
 * @param {number} bookId
 * @param {number} partNumber - 1-based index
 * @param {string} destDir    - Directory to save the file in
 * @returns {Promise<string>} Absolute path to the saved file
 */
async function downloadPart(partToken, bookId, partNumber, destDir) {
  const url = `${WP_REST_BASE}/download?token=${encodeURIComponent(partToken)}`;
  const res = await fetch(url);

  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(
      body.message || `GET /download failed with HTTP ${res.status} for part ${partNumber}`
    );
  }

  const filename = `book_${bookId}_part${partNumber}.pdf`;
  const filePath = path.join(destDir, filename);

  // Stream the response body to a file
  const buffer = Buffer.from(await res.arrayBuffer());
  fs.writeFileSync(filePath, buffer);

  return filePath;
}
```

> **Important:** Each part token is also one-time. Do not retry a failed `GET /download` with the same token — it will return `401`. If a part download fails, the user must restart the flow from the WordPress download button.

---

### 2.5 Complete main-process example

```js
const path = require('path');
const fs   = require('fs');
const { app, dialog } = require('electron');

const WP_REST_BASE = 'https://<your-wordpress-site>/wp-json/print-api/v1';

/**
 * Full download flow triggered by a cyberthrone:// deep link.
 * @param {string} bookToken
 */
async function downloadBook(bookToken) {
  // Step 1: exchange book token → part tokens
  const { bookId, partCount, partTokens } = await getPartTokens(bookToken);

  // Destination folder — e.g. ~/Downloads/book_42/
  const destDir = path.join(app.getPath('downloads'), `book_${bookId}`);
  fs.mkdirSync(destDir, { recursive: true });

  // Step 2: download each part sequentially
  // (run in parallel if your server can handle concurrent streaming)
  const savedPaths = [];
  for (let i = 0; i < partCount; i++) {
    const filePath = await downloadPart(
      partTokens[i],
      bookId,
      i + 1,          // part numbers are 1-based
      destDir
    );
    savedPaths.push(filePath);
    console.log(`Downloaded part ${i + 1}/${partCount}: ${filePath}`);
  }

  // Notify the user
  dialog.showMessageBox({
    type: 'info',
    title: 'Download complete',
    message: `Book ${bookId} downloaded (${partCount} part${partCount !== 1 ? 's' : ''})`,
    detail: `Saved to: ${destDir}`,
  });

  return savedPaths;
}

// ── Helpers (same as sections 2.3 and 2.4 above) ────────────────────────────

async function getPartTokens(bookToken) {
  const url = `${WP_REST_BASE}/pdf?token=${encodeURIComponent(bookToken)}`;
  const res = await fetch(url);
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message || `GET /pdf failed: HTTP ${res.status}`);
  }
  const data = await res.json();
  return { bookId: data.book_id, partCount: data.part_count, partTokens: data.parts };
}

async function downloadPart(partToken, bookId, partNumber, destDir) {
  const url = `${WP_REST_BASE}/download?token=${encodeURIComponent(partToken)}`;
  const res = await fetch(url);
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw new Error(body.message || `GET /download failed: HTTP ${res.status} (part ${partNumber})`);
  }
  const filename = `book_${bookId}_part${partNumber}.pdf`;
  const filePath = path.join(destDir, filename);
  fs.writeFileSync(filePath, Buffer.from(await res.arrayBuffer()));
  return filePath;
}
```

---

### 2.6 Renderer-process helper (optional)

If you need to show download progress in a UI, forward status updates from the main process to the renderer via `ipcMain` / `ipcRenderer`:

**Main process**

```js
const { ipcMain } = require('electron');

// Replace the console.log in downloadBook with:
mainWindow.webContents.send('download-progress', {
  bookId,
  part: i + 1,
  total: partCount,
  filePath,
});
```

**Renderer process (preload or renderer script)**

```js
const { ipcRenderer } = require('electron');

ipcRenderer.on('download-progress', (_event, { bookId, part, total, filePath }) => {
  console.log(`Book ${bookId}: part ${part}/${total} saved to ${filePath}`);
  // Update a progress bar, list item, etc.
});
```

---

## Token lifecycle and expiry

| Token type | Issued by | Consumed by | TTL | One-time? |
|------------|-----------|-------------|-----|-----------|
| Book token | `POST /token` | `GET /pdf` | 5 min | Yes |
| Part token | `GET /pdf` | `GET /download` | 5 min | Yes |

**TTL is shared, not sequential.** All part tokens are minted at the same moment `GET /pdf` is called. If the Electron app takes longer than 5 minutes to download all parts (e.g. large files on a slow connection), later part tokens will be expired. For books with many large parts, increase `TOKEN_TTL` in `class-token-manager.php`:

```php
const TOKEN_TTL = 900;  // 15 minutes
```

---

## Error reference

All error responses follow the WordPress REST API format:

```json
{
  "code":    "invalid_token",
  "message": "Token is invalid, expired, or has already been used.",
  "data":    { "status": 401 }
}
```

| Code | HTTP | Where it can appear | Meaning |
|------|------|---------------------|---------|
| `invalid_nonce` | 403 | `POST /token` | `X-WP-Nonce` header is missing or invalid. Reload the WordPress page to get a fresh nonce. |
| `login_required` | 401 | `POST /token` | User is not logged in and `PRINT_API_REQUIRE_LOGIN` is `true`. |
| `invalid_token` | 401 | `GET /pdf`, `GET /download` | Token is wrong, expired, already used, or is the wrong type (book vs part). |
| `book_not_found` | 404 | `GET /pdf`, `GET /download` | No PDF files found on disk. Check the uploads directory structure. |
| `debug_disabled` | 403 | `GET /debug/book/{id}` | Debug endpoint requires `WP_DEBUG = true` in `wp-config.php`. |
