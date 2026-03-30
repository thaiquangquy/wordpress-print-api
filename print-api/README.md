# Print API — WordPress Plugin

Secure, token-gated PDF delivery for multi-part books. Every download requires a fresh one-time token chain; no permanent file URL is ever exposed to a client.

---

## Architecture

```
┌─────────────────────┐     ┌──────────────────────────┐     ┌──────────────────┐
│   WordPress Site    │     │   Print API REST Layer   │     │   Electron App   │
│  (browser / page)   │     │   (this plugin)          │     │  (desktop client)│
└────────┬────────────┘     └────────────┬─────────────┘     └────────┬─────────┘
         │                               │                             │
         │  1. POST /token               │                             │
         │  {book_id, X-WP-Nonce} ──────►│ verify nonce + login       │
         │◄──────────────────────────────│ issue book token (5 min)   │
         │  {token: "<book-token>"}      │                             │
         │                               │                             │
         │  2. cyberthrone://print       │                             │
         │     ?token=<book-token> ──────┼────────────────────────────►
         │     (OS deep link)            │                             │
         │                               │                             │
         │                               │  3. GET /pdf               │
         │                               │     ?token=<book-token> ◄──│
         │                               │  consume book token        │
         │                               │  count parts on disk       │
         │                               │  issue N part tokens ──────►
         │                               │  {parts:[t1,t2…tN]}        │
         │                               │                             │
         │                               │  4. GET /download          │
         │                               │     ?token=<part-token> ◄──│ (once per part)
         │                               │  consume part token        │
         │                               │  stream PDF bytes ─────────►
         │                               │                             │ save partN.pdf
```

### Components

| Component | File | Responsibility |
|-----------|------|----------------|
| Plugin bootstrap | `print-api.php` | Constants, hook registration, script enqueue, activation |
| Token Manager | `includes/class-token-manager.php` | Generate and consume one-time tokens via WP Transients |
| PDF Resolver | `includes/class-pdf-resolver.php` | Map book ID + part number to a server-side file path |
| REST API | `includes/class-rest-api.php` | Route registration and request handlers |
| Frontend script | `assets/js/frontend.js` | Button wiring, token request, deep-link dispatch |

---

## Download workflow

### Step 1 — Issue a book token (browser → WordPress)

The visitor clicks a button on a WordPress page. The bundled JavaScript calls `POST /token` with the book ID and the WordPress REST nonce. The server verifies the nonce (and optionally that the user is logged in), then creates a one-time book token stored as a short-lived transient (default 5 minutes).

### Step 2 — Hand off to the desktop app (browser → OS)

The browser is redirected to a custom URL scheme:

```
cyberthrone://print?token=<book-token>
```

The OS recognises the scheme, wakes or focuses the Electron app, and passes the URL to it. The browser's involvement ends here.

### Step 3 — Exchange the book token for part tokens (Electron → WordPress)

The app calls `GET /pdf?token=<book-token>`. The server:

1. Validates and **immediately deletes** the book token (it can never be used again).
2. Counts how many consecutive `part1.pdf … partN.pdf` files exist on disk for that book.
3. Mints one fresh one-time part token per file.
4. Returns the part token array and the part count.

The response contains tokens, **not file URLs** — the real paths never leave the server.

### Step 4 — Download each part (Electron → WordPress)

For each part token the app calls `GET /download?token=<part-token>`. The server validates and deletes the part token, then streams the raw PDF bytes directly. The app writes each stream to disk.

---

## Token design

| Property | Value |
|----------|-------|
| Entropy | 256 bits (`random_bytes(32)`) |
| Format | 64-character lowercase hex string |
| Storage | WordPress Transients (database or object cache) |
| TTL | 5 minutes (configurable) |
| Single-use | Token is deleted before the response is sent |

### Two token types

**Book token** — produced by `POST /token`, consumed by `GET /pdf`. Proves the browser session was authenticated and authorised for a specific book.

**Part token** — produced by `GET /pdf` (one per part), consumed by `GET /download`. Each token is bound server-side to a specific `(book_id, part_number)` pair. The client cannot request a different part with someone else's token.

---

## Book file layout

```
wp-content/uploads/private/books/
└── {id}/
    ├── part1.pdf
    ├── part2.pdf
    ├── partN.pdf          ← any number of parts
    └── light.pdf          ← optional; served when light_book=true
```

- The directory name is the numeric book ID.
- Parts must be named `part1.pdf`, `part2.pdf`, … with no gaps. The plugin counts them by walking the sequence until the first missing number.
- `light.pdf` is an optional lightweight variant served when the `light_book` flag is set on the token request.
- The directory is protected by `.htaccess` on activation so direct HTTP access returns 403. Files are only reachable through the streaming endpoint.

---

## REST endpoints

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| `POST` | `/wp-json/print-api/v1/token` | WP nonce + optional login | Issue a book token |
| `GET` | `/wp-json/print-api/v1/pdf` | Book token (query param) | Exchange for N part tokens |
| `GET` | `/wp-json/print-api/v1/download` | Part token (query param) | Stream PDF bytes |
| `GET` | `/wp-json/print-api/v1/nonce` | None | Fetch a fresh WP nonce |
| `GET` | `/wp-json/print-api/v1/debug/book/{id}` | `WP_DEBUG=true` | Inspect expected file paths |

---

## Configuration

| Constant / Setting | Location | Default | Effect |
|--------------------|----------|---------|--------|
| `PRINT_API_REQUIRE_LOGIN` | `print-api.php` | `true` | When `true`, only logged-in users can call `POST /token` |
| `TOKEN_TTL` | `class-token-manager.php` | `300` (5 min) | Seconds before any token expires |
| `DOWNLOAD_FLOW` | `assets/js/frontend.js` | `'deeplink'` | `'deeplink'` fires the `cyberthrone://` URL; `'browser'` downloads directly in the browser |
| `data-light-book` | HTML button attribute | _(absent)_ | Set to `"true"` on a `data-print-book` button to request `light.pdf` instead of the full parts |

---

## Edge cases

### Token consumed but network fails before app receives the response

The book token is deleted before the response is sent. If a network failure prevents the app from receiving the part tokens, the book token is gone. The user must go back to the WordPress page and click the download button again to get a new book token.

**Mitigation:** Display a clear "download failed — please try again" message in the app and guide the user back to the website.

### Part download interrupted mid-stream

A part token is consumed when `GET /download` is called. If the connection drops after the token is validated but before all bytes are sent, that part token is gone. The remaining part tokens (not yet called) are still valid.

**Mitigation:** Track which parts have been fully saved. On failure, inform the user which parts succeeded and which need to be re-downloaded — requiring a new full token flow for the failed parts only if their tokens have been consumed.

### Token TTL expires before all parts are downloaded

All part tokens are minted at the same moment (`GET /pdf`). If downloading many large parts takes longer than the TTL (default 5 minutes), later part tokens will be expired by the time they are used.

**Mitigation:** Increase `TOKEN_TTL` for books with many or large parts. As a rule of thumb, set the TTL to at least 2× the expected total download time at the slowest expected connection speed.

### Double-click or duplicate deep-link activation

If the user clicks the download button twice in quick succession, two book tokens are issued. The first `cyberthrone://` redirect will be handled by the app; the second token will expire unused after the TTL.

**Mitigation:** The frontend disables the button for the duration of the token request. On the app side, guard against processing two deep links for the same book simultaneously.

### Book token presented to `/download` (wrong endpoint)

Part tokens carry an internal `part` field; book tokens do not. Both `/pdf` and `/download` inspect this field and reject tokens of the wrong type with a `401 invalid_token` response.

### Part token presented to `/pdf` (wrong endpoint)

Same as above — `/pdf` rejects any token that contains a `part` field.

### Race condition on single-use enforcement

Two requests arriving with the same token within milliseconds could theoretically both read the transient before either deletes it. The plugin deletes the transient before returning data. On most WordPress transient back-ends (database) this is sufficient; on some object-cache back-ends it is not strictly atomic. For high-traffic production use, replace the transient store with a database row and a `SELECT … FOR UPDATE` or similar atomic operation.

### Book files added or removed between `/token` and `/pdf`

If an admin removes a book's directory between the time the book token was issued and the time the app calls `/pdf`, the server will count 0 parts and return `404 book_not_found`. The book token is still consumed.

### Nonce expiry (browser session)

WordPress REST nonces are valid for 12 hours. If a visitor leaves a page open overnight and clicks the download button the next morning, the nonce will be invalid and `POST /token` returns `403 invalid_nonce`. The page must be refreshed to get a new nonce.

---

## Security model summary

```
What is protected          How
─────────────────────────  ──────────────────────────────────────────────────────
PDF file paths             Never sent to any client; only used server-side
PDF file bytes             Only served through the streaming endpoint with a valid token
Token forgery              256-bit random token, infeasible to guess
Token replay               Deleted before the response is sent (delete-first policy)
Token theft and reuse      5-minute TTL limits the replay window
CSRF on token issuance     WordPress REST nonce required on POST /token
Unauthenticated access     PRINT_API_REQUIRE_LOGIN blocks anonymous token requests
Direct file download       .htaccess denies all direct HTTP access to the uploads folder
```

---

## File structure

```
print-api/
├── print-api.php                   # Plugin header, constants, hooks, activation
├── includes/
│   ├── class-token-manager.php     # Token generate / consume (WP Transients)
│   ├── class-rest-api.php          # REST route registration and handlers
│   └── class-pdf-resolver.php      # Resolves book_id + part number to a file path
├── assets/
│   └── js/
│       └── frontend.js             # Button wiring, token request, deep-link dispatch
├── INTEGRATION.md                  # Step-by-step guide for WordPress admins and Electron developers
└── deploy-local.sh                 # Sync plugin to a LocalWP site on Windows (WSL2)
```
