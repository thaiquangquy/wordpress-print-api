<?php
/**
 * REST API — Route Registration and Request Handlers
 *
 * This class wires together the Token Manager and PDF Resolver and exposes
 * them as two WordPress REST API endpoints.
 *
 * Endpoints
 * ─────────
 *   POST  /wp-json/print-api/v1/token
 *         Body (JSON): { "book_id": 123 }
 *         Headers:     X-WP-Nonce: <nonce>
 *         Response:    { "token": "<64-char-hex>" }
 *
 *   GET   /wp-json/print-api/v1/pdf?token=<64-char-hex>
 *         Response:    { "parts": ["url1", "url2", "url3"] }
 *
 * WordPress REST API primer (for first-timers)
 * ─────────────────────────────────────────────
 * register_rest_route() tells WordPress "when a request matches this
 * namespace+path+method combo, call this function and return its result".
 *
 * Nonce / CSRF protection
 * ─────────────────────────
 * WordPress's REST API checks the X-WP-Nonce header automatically for any
 * request that needs authentication. Our token endpoint uses a custom nonce
 * check via verify_nonce() so it works even for non-logged-in users (the
 * standard REST nonce requires a logged-in cookie).
 *
 * Error responses
 * ───────────────
 * We return WP_Error objects, which WordPress converts to a JSON error
 * response with an HTTP status code automatically.
 *
 * @package PrintAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Print_API_Rest {

	/**
	 * Namespace for all routes in this plugin.
	 * The full base URL becomes: /wp-json/print-api/v1/
	 */
	const NAMESPACE = 'print-api/v1';

	/**
	 * Register the two REST routes.
	 *
	 * Called on the 'rest_api_init' hook (see print-api.php).
	 */
	public static function register_routes() {

		// ── Route 1: Issue a token ────────────────────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,   // = 'POST'
				'callback'            => array( __CLASS__, 'handle_token_request' ),

				// permission_callback runs BEFORE the main callback.
				// Returning true means "allow this request through".
				// We do our real auth check (nonce + optional login) inside
				// handle_token_request() so we can return a detailed error.
				'permission_callback' => '__return_true',

				// args defines and validates request parameters.
				// WordPress will automatically reject requests missing required
				// params or failing the 'validate_callback'.
				'args'                => array(
					'book_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',   // absint = absolute integer (always ≥ 0)
						'description'       => 'The numeric ID of the book to download.',
					),
				),
			)
		);

		// ── Route 2: Exchange a token for PDF URLs ────────────────────────────
		register_rest_route(
			self::NAMESPACE,
			'/pdf',
			array(
				'methods'             => WP_REST_Server::READABLE,   // = 'GET'
				'callback'            => array( __CLASS__, 'handle_pdf_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'The one-time token issued by the /token endpoint.',
					),
				),
			)
		);

		// ── Route 3: Helper — return a fresh nonce for browser clients ────────
		// Some front-end setups need to fetch a nonce via JS before they have
		// one. This tiny GET endpoint returns a fresh nonce without requiring a
		// full page reload. It's optional; you can remove it if you don't need it.
		register_rest_route(
			self::NAMESPACE,
			'/nonce',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_nonce_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	// ════════════════════════════════════════════════════════════════════════
	// Handler: POST /wp-json/print-api/v1/token
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Issue a one-time download token for the requested book.
	 *
	 * Request flow:
	 *   1. Verify the WP REST nonce (CSRF protection).
	 *   2. (Optional) Require the user to be logged in.
	 *   3. Generate and return a token.
	 *
	 * @param  WP_REST_Request $request  Full request object (params already validated by 'args').
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_token_request( WP_REST_Request $request ) {

		// ── Step 1: Verify nonce ──────────────────────────────────────────────
		// WordPress sends the nonce in the X-WP-Nonce header for REST requests.
		// wp_verify_nonce() returns false (bad), 1 (valid, fresh), or 2 (valid, aging).
		// We accept both 1 and 2 (truthy). "wp_rest" is the standard nonce action
		// used by WordPress's own REST API infrastructure.
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			// Return a 403 Forbidden with a machine-readable error code.
			return new WP_Error(
				'invalid_nonce',
				'CSRF token missing or invalid. Please reload the page and try again.',
				array( 'status' => 403 )
			);
		}

		// ── Step 2: Optional login check ─────────────────────────────────────
		// Controlled by the PRINT_API_REQUIRE_LOGIN constant in print-api.php.
		// Set it to true if only registered users should be allowed to download.
		if ( PRINT_API_REQUIRE_LOGIN && ! is_user_logged_in() ) {
			return new WP_Error(
				'login_required',
				'You must be logged in to download this book.',
				array( 'status' => 401 )
			);
		}

		// ── Step 3: Generate token ────────────────────────────────────────────
		// book_id was already validated and sanitized by the 'args' definition above.
		$book_id = $request->get_param( 'book_id' );
		$token   = Print_API_Token_Manager::generate( $book_id );

		// WP_REST_Response( data, status_code ) wraps our array in a proper JSON response.
		return new WP_REST_Response(
			array( 'token' => $token ),
			200
		);
	}

	// ════════════════════════════════════════════════════════════════════════
	// Handler: GET /wp-json/print-api/v1/pdf?token=…
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Exchange a one-time token for an array of 3 PDF part URLs.
	 *
	 * After this call the token is permanently invalidated — a second call
	 * with the same token will always receive a 401 error.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_pdf_request( WP_REST_Request $request ) {

		$token = $request->get_param( 'token' );

		// ── Consume the token ─────────────────────────────────────────────────
		// consume() deletes the transient and returns the book_id, or false if
		// the token was invalid, expired, or already used.
		$book_id = Print_API_Token_Manager::consume( $token );

		if ( false === $book_id ) {
			// 401 Unauthorized — the token didn't match anything valid.
			return new WP_Error(
				'invalid_token',
				'Token is invalid, expired, or has already been used.',
				array( 'status' => 401 )
			);
		}

		// ── Resolve PDF parts ─────────────────────────────────────────────────
		$parts = Print_API_PDF_Resolver::get_parts( $book_id );

		if ( false === $parts ) {
			// The token was valid but there are no PDF files for this book.
			// This is a server configuration issue, not the client's fault → 404.
			return new WP_Error(
				'book_not_found',
				'PDF files for this book could not be located on the server.',
				array( 'status' => 404 )
			);
		}

		// ── Return URLs ───────────────────────────────────────────────────────
		return new WP_REST_Response(
			array(
				'parts'   => $parts,    // Array of 3 URL strings
				'book_id' => $book_id,  // Echo back so client can double-check
			),
			200
		);
	}

	// ════════════════════════════════════════════════════════════════════════
	// Handler: GET /wp-json/print-api/v1/nonce
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Return a fresh WP REST nonce.
	 *
	 * Useful for single-page apps that need a nonce before the first page load
	 * injects one via wp_localize_script.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_nonce_request( WP_REST_Request $request ) {
		return new WP_REST_Response(
			array( 'nonce' => wp_create_nonce( 'wp_rest' ) ),
			200
		);
	}
}
