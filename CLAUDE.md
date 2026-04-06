# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### Deploy
```bash
# Deploy to local LocalWP site (WSL2 → Windows mount)
bash print-api/deploy-local.sh

# Deploy to VPS via rsync/SSH
VPS_USER=admin VPS_HOST=1.2.3.4 bash print-api/deploy-vps.sh
```

### Build release zip
```bash
# Bump version in print-api/print-api.php first, then:
bash buildZip.sh
# Output: print-api.zip (excludes .git, *.md, *.sh)
```

Use `/build-plugin` to bump the patch version and build in one step.

## Architecture

The plugin is a pure WordPress REST API backend with a thin JS frontend. There is no build system — PHP and JS are shipped as-is.

### Token chain (the core flow)

```
Browser             → POST /token  (book_id + X-WP-Nonce)  → book token
browser deep-links  → cyberthrone://print?token=<book-token>
Electron app        → POST /pdf    (body: {token})          → N part tokens
Electron app        → POST /download (body: {token})        → PDF bytes streamed
```

Every token is 256-bit random hex, stored as a WP Transient, and deleted before the response is sent (delete-first policy). Book tokens have no `part` key; part tokens do — both `/pdf` and `/download` use this to reject wrong-type tokens with `401`.

### Class responsibilities

| Class | File | Role |
|-------|------|------|
| `Print_API_Token_Manager` | `includes/class-token-manager.php` | `generate()` / `consume()` over WP Transients; includes a 10-second lock transient to prevent concurrent double-consume |
| `Print_API_PDF_Resolver` | `includes/class-pdf-resolver.php` | Maps `(book_id, part_num)` to an absolute server-side path; never returns public URLs |
| `Print_API_Rest` | `includes/class-rest-api.php` | Registers 5 routes; all POST handlers read exclusively from `get_json_params()` — never `get_param()` |
| Frontend | `assets/js/frontend.js` | Wires `[data-print-book]` buttons; calls `POST /token` then fires the `cyberthrone://` deep link |

### POST endpoint body contract

All mutating endpoints accept JSON bodies only (`get_json_params()`):

| Endpoint | Required body fields |
|----------|----------------------|
| `POST /token` | `book_id` (int); optional `light_book` (bool) |
| `POST /pdf` | `token` (string); dev master token also requires `book_id` |
| `POST /download` | `token` (string) |

### Dev master token

Defined in `wp-config.php` as `PRINT_API_DEV_MASTER_TOKEN`. Only active when `WP_DEBUG = true`. Allows reusable calls to `POST /pdf` by passing `{ "token": "<master>", "book_id": 42 }` — the token is never consumed.

### Book file layout

```
wp-content/uploads/private/books/{book_id}/
    part1.pdf, part2.pdf … partN.pdf   ← sequential, no gaps
    light.pdf                           ← optional lightweight variant
```

Part count is determined at request time by walking the sequence until the first missing file. No config needed to add a new book.

### Version locations

The plugin version string lives in **two places** and must be kept in sync:
- Plugin header comment: `* Version: X.Y.Z` (`print-api/print-api.php` line ~10)
- Constant definition: `define( 'PRINT_API_VERSION', 'X.Y.Z' )` (~line 31)
