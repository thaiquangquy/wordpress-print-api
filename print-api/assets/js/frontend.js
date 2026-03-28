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
 * 1. User clicks a "Download" button that has data-book-id="123".
 * 2. POST /token  → server returns { token: "<book-token>" }   (one-time)
 * 3. GET  /pdf?token=<book-token>
 *         → server returns { parts: ["<part1-token>", "<part2-token>", "<part3-token>"] }
 *            Each part token is also one-time. No file URLs are ever sent.
 * 4. GET  /download?token=<part-token>  (once per part)
 *         → server streams the PDF bytes directly.
 *
 * Because the server streams bytes through /download, there is no persistent
 * URL for the PDF files — every download requires a fresh one-time token.
 */

(function () {
  'use strict';

  // ── Utility: request a one-time token from the server ──────────────────────
  /**
   * @param {number} bookId
   * @returns {Promise<string>} resolves to the token string
   */
  async function requestToken(bookId) {
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

        body: JSON.stringify({ book_id: bookId }),
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
   * @param {number} bookId
   */
  async function downloadViaAppDeepLink(bookId) {
    const token = await requestToken(bookId);

    // Redirect the browser to a custom URL scheme.
    // The native app intercepts this and handles the download.
    window.location.href = `cyberthrone://print?token=${token}`;
  }

  // ── Flow B: Full browser download (3-step) ────────────────────────────────
  /**
   * Use this for a pure-web flow where the browser itself downloads the PDFs.
   *
   * Step 1: Request a book token.
   * Step 2: Exchange it for 3 per-part tokens (no file URLs are returned).
   * Step 3: Navigate to /download?token=<part-token> for each part —
   *         the server streams the bytes and the browser saves the file.
   *
   * NOTE: data.parts now contains one-time tokens, NOT file URLs.
   *       Opening /download?token=… triggers the actual byte-stream download.
   *
   * @param {number} bookId
   * @returns {Promise<void>}
   */
  async function downloadViaBrowser(bookId) {
    const bookToken = await requestToken(bookId);

    // Exchange book token for per-part tokens.
    const response = await fetch(
      printApiConfig.restUrl + '/pdf?token=' + encodeURIComponent(bookToken)
    );

    if (!response.ok) {
      const err = await response.json().catch(() => ({}));
      throw new Error(err.message || 'Failed to exchange token for PDF parts.');
    }

    const data = await response.json();
    // data.parts = ["<part1-token>", "<part2-token>", "<part3-token>"]
    // Each token is one-time — navigate to /download to consume it and get the bytes.
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
  // To add a download button to any page or post, give it the attribute:
  //   data-print-book="123"
  //
  // Example:
  //   <button data-print-book="123">Download Book</button>
  //
  // Change DOWNLOAD_FLOW below to switch between the two flows.

  const DOWNLOAD_FLOW = 'deeplink';   // 'deeplink' | 'browser'

  document.addEventListener('DOMContentLoaded', function () {
    // Find every button/element that has a data-print-book attribute.
    const buttons = document.querySelectorAll('[data-print-book]');

    buttons.forEach(function (button) {
      button.addEventListener('click', async function () {
        const bookId = parseInt(button.dataset.printBook, 10);

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
            await downloadViaAppDeepLink(bookId);
          } else {
            const parts = await downloadViaBrowser(bookId);
            // In browser flow, open each PDF part in a new tab.
            parts.forEach((url) => window.open(url, '_blank'));
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
