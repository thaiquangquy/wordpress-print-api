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
 * How this works
 * ──────────────
 * 1. User clicks a "Download" button that has data-book-id="123".
 * 2. We POST to /token with that book_id and the nonce.
 * 3. Server returns { token: "…" }.
 * 4. We redirect to app://print?token=… (deep link into a native app)
 *    OR call /pdf?token=… to get the download URLs directly in the browser.
 *
 * Both flows are shown below.
 */

( function () {
  'use strict';

  // ── Utility: request a one-time token from the server ──────────────────────
  /**
   * @param {number} bookId
   * @returns {Promise<string>} resolves to the token string
   */
  async function requestToken( bookId ) {
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

        body: JSON.stringify( { book_id: bookId } ),
      }
    );

    if ( ! response.ok ) {
      // Parse the WP_Error JSON body for a useful message.
      const err = await response.json().catch( () => ({}) );
      throw new Error( err.message || 'Failed to obtain download token.' );
    }

    const data = await response.json();
    return data.token;   // 64-character hex string
  }

  // ── Flow A: Deep-link into a native app ────────────────────────────────────
  /**
   * Use this when your PDF viewer is a native app that registers the
   * "app://" URL scheme.  The app receives the token and must then call
   * GET /wp-json/print-api/v1/pdf?token=… itself to get the download URLs.
   *
   * @param {number} bookId
   */
  async function downloadViaAppDeepLink( bookId ) {
    const token = await requestToken( bookId );

    // Redirect the browser to a custom URL scheme.
    // The native app intercepts this and handles the download.
    window.location.href = `app://print?token=${ token }`;
  }

  // ── Flow B: Exchange the token in the browser, then download ──────────────
  /**
   * Use this for a pure-web flow where the browser itself downloads the PDFs.
   * After getting the 3 part URLs you can open them in new tabs, feed them to
   * a PDF merger library, etc.
   *
   * @param {number} bookId
   * @returns {Promise<string[]>} array of 3 PDF part URLs
   */
  async function downloadViaBrowser( bookId ) {
    const token = await requestToken( bookId );

    const response = await fetch(
      printApiConfig.restUrl + '/pdf?token=' + encodeURIComponent( token )
    );

    if ( ! response.ok ) {
      const err = await response.json().catch( () => ({}) );
      throw new Error( err.message || 'Failed to exchange token for PDF.' );
    }

    const data = await response.json();
    // data.parts = ["https://.../part1.pdf", "https://.../part2.pdf", "https://.../part3.pdf"]
    return data.parts;
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

  document.addEventListener( 'DOMContentLoaded', function () {
    // Find every button/element that has a data-print-book attribute.
    const buttons = document.querySelectorAll( '[data-print-book]' );

    buttons.forEach( function ( button ) {
      button.addEventListener( 'click', async function () {
        const bookId = parseInt( button.dataset.printBook, 10 );

        if ( ! bookId || bookId < 1 ) {
          console.error( 'Print API: invalid book ID on button', button );
          return;
        }

        // Disable button while request is in flight to prevent double-clicks.
        button.disabled = true;
        const originalText = button.textContent;
        button.textContent = 'Preparing download…';

        try {
          if ( DOWNLOAD_FLOW === 'deeplink' ) {
            await downloadViaAppDeepLink( bookId );
          } else {
            const parts = await downloadViaBrowser( bookId );
            // In browser flow, open each PDF part in a new tab.
            parts.forEach( ( url ) => window.open( url, '_blank' ) );
          }
        } catch ( error ) {
          console.error( 'Print API error:', error );
          alert( 'Download failed: ' + error.message );
        } finally {
          // Re-enable button.
          button.disabled = false;
          button.textContent = originalText;
        }
      } );
    } );
  } );

} )();
