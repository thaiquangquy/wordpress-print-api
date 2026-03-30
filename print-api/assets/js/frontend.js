/**
 * Print API — Frontend JavaScript
 *
 * This file is enqueued by print-api.php on every front-end page.
 * WordPress also injects a small <script> block BEFORE this file runs:
 *
 *   <script>
 *     var printApiConfig = {
 *       "nonce":   "abc123...",           // WP REST nonce for CSRF protection
 *       "restUrl": "https://…/wp-json/print-api/v1"
 *     };
 *   </script>
 *
 * So window.printApiConfig is always available here.
 *
 * How this works (3-step protocol)
 * ──────────────────────────────────
 * 1. User clicks a "Download" button that has data-print-book="123".
 * 2. POST /token  → server returns { token: "<book-token>" }   (one-time)
 * 3. GET  /pdf?token=<book-token>
 *         → server returns { parts: ["<part1-token>", …, "<partN-token>"] }
 *            Each part token is also one-time. No file URLs are ever sent.
 * 4. GET  /download?token=<part-token>  (once per part)
 *         → server streams the PDF bytes directly.
 *
 * Light-book variant
 * ───────────────────
 * Add data-light-book="true" alongside data-print-book to request only light.pdf
 * (a single-part download served from books/{id}/light.pdf on the server).
 * The token chain is the same; the server simply serves a different file.
 *
 * Because the server streams bytes through /download, there is no persistent
 * URL for the PDF files — every download requires a fresh one-time token.
 */

(function () {
  'use strict';

  // ── Utility: request a one-time token from the server ──────────────────────
  /**
   * @param {number}  bookId
   * @param {boolean} [lightBook=false]  When true, the server will issue a token
   *                                     that resolves to light.pdf instead of the
   *                                     regular part files.
   * @returns {Promise<string>} resolves to the token string
   */
  async function requestToken(bookId, lightBook = false) {
    const body = { book_id: bookId };
    if (lightBook) {
      body.light_book = true;
    }

    const response = await fetch(
      printApiConfig.restUrl + '/token',   // e.g. https://example.com/wp-json/print-api/v1/token
      {
        method: 'POST',

        headers: {
          'Content-Type': 'application/json',

          // X-WP-Nonce is the WordPress-standard CSRF header for REST API calls.
          // WordPress verifies this before our PHP handler runs.
          // The value comes from the PHP side via wp_localize_script().
          'X-WP-Nonce': printApiConfig.nonce,
        },

        body: JSON.stringify(body),
      }
    );

    if (!response.ok) {
      // Parse the WP_Error JSON body for a useful message.
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || 'Failed to obtain download token.');
    }

    const data = await response.json();
    return data.token;   // 64-character hex string
  }

  // ── Flow A: Deep-link into a native app ────────────────────────────────────
  /**
   * Use this when your PDF viewer is a native app that registers the
   * "cyberthrone://" URL scheme.  The app receives the token and must then call
   * GET /wp-json/print-api/v1/pdf?token=… itself to get the download URLs.
   *
   * @param {number}  bookId
   * @param {boolean} [lightBook=false]
   */
  async function downloadViaAppDeepLink(bookId, lightBook = false) {
    const token = await requestToken(bookId, lightBook);

    // Redirect the browser to a custom URL scheme.
    // The native app intercepts this and handles the download.
    window.location.href = `cyberthrone://print?token=${token}`;
  }

  // ── Flow B: Full browser download (3-step) ────────────────────────────────
  /**
   * Use this for a pure-web flow where the browser itself downloads the PDFs.
   *
   * Step 1: Request a book token (with optional light_book flag).
   * Step 2: Exchange it for per-part tokens (no file URLs are returned).
   *         For a light-book token the server returns exactly one part token
   *         that resolves to light.pdf.
   * Step 3: Navigate to /download?token=<part-token> for each part —
   *         the server streams the bytes and the browser saves the file.
   *
   * @param {number}  bookId
   * @param {boolean} [lightBook=false]
   * @returns {Promise<void>}
   */
  async function downloadViaBrowser(bookId, lightBook = false) {
    const bookToken = await requestToken(bookId, lightBook);

    // Exchange book token for per-part tokens.
    const response = await fetch(
      printApiConfig.restUrl + '/pdf?token=' + encodeURIComponent(bookToken)
    );

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || 'Failed to exchange token for PDF parts.');
    }

    const data = await response.json();
    // data.parts = ["<token>"]           for a light book  (1 part → light.pdf)
    // data.parts = ["<t1>", "<t2>", …]  for a regular book (N parts)
    data.parts.forEach(function (partToken) {
      // Opening in a new tab triggers the browser's "save file" prompt because
      // the server sends Content-Disposition: attachment.
      window.open(
        printApiConfig.restUrl + '/download?token=' + encodeURIComponent(partToken),
        '_blank'
      );
    });
  }

  // ── Wire up buttons on the page ────────────────────────────────────────────
  //
  // Standard download button (all parts):
  //   <button data-print-book="123">Download Book</button>
  //
  // Light-book download button (light.pdf only):
  //   <button data-print-book="123" data-light-book="true">Download Light Version</button>
  //
  // Change DOWNLOAD_FLOW below to switch between the two flows.

  const DOWNLOAD_FLOW = 'deeplink';   // 'deeplink' | 'browser'

  document.addEventListener('DOMContentLoaded', function () {
    // Find every button/element that has a data-print-book attribute.
    const buttons = document.querySelectorAll('[data-print-book]');

    buttons.forEach(function (button) {
      button.addEventListener('click', async function () {
        const bookId    = parseInt(button.dataset.printBook, 10);
        const lightBook = button.dataset.lightBook === 'true';

        if (!bookId || bookId < 1) {
          console.error('Print API: invalid book ID on button', button);
          return;
        }

        // Disable button while request is in flight to prevent double-clicks.
        button.disabled = true;
        const originalText = button.textContent;
        button.textContent = 'Preparing download…';

        try {
          if (DOWNLOAD_FLOW === 'deeplink') {
            await downloadViaAppDeepLink(bookId, lightBook);
          } else {
            await downloadViaBrowser(bookId, lightBook);
          }
        } catch (error) {
          console.error('Print API error:', error);
          alert('Download failed: ' + error.message);
        } finally {
          // Re-enable button.
          button.disabled = false;
          button.textContent = originalText;
        }
      });
    });
  });

})();
