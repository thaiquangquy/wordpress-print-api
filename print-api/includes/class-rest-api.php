<?php
/**
 * REST API — Route Registration and Request Handlers
 *
 * This class wires together the Token Manager and PDF Resolver and exposes
 * them as three WordPress REST API endpoints.
 *
 * Endpoints
 * ─────────
 *   POST  /wp-json/print-api/v1/token
 *         Body (JSON): { "book_id": 123 }
 *         Headers:     X-WP-Nonce: <nonce>
 *         Response:    { "token": "<64-char-hex>" }   ← book-level token (one-time)
 *
 *   GET   /wp-json/print-api/v1/pdf?token=<book-token>
 *         Consumes the book token.
 *         Response:    { "parts": ["<part1-token>", …, "<partN-token>"], "part_count": N }
 *         One token per part file found on disk (any count, not fixed at 3).
 *         Each part token is also one-time and expires in TOKEN_TTL seconds.
 *         NOTE: returns tokens, NOT file URLs — the actual PDF paths are
 *               never exposed to the client.
 *
 *   GET   /wp-json/print-api/v1/download?token=<part-token>
 *         Consumes the part token and streams the PDF bytes directly.
 *         The real file URL/path is kept server-side only.
 *
 * Security model
 * ──────────────
 * 1. Book token    → one-time, expires in 5 min, must be used to get part tokens.
 * 2. Part tokens   → one-time each, expire in 5 min, must be used to download.
 * 3. Download      → streams file bytes through WordPress; no permanent URL exists.
 *
 * This means a captured token or download link can only be used once and only
 * within the TTL window.  There is no permanent URL an attacker can reuse.
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
	 * Register the REST routes.
	 *
	 * Called on the 'rest_api_init' hook (see print-api.php).
	 */
	public static function register_routes() {

		// ── Route 1: Issue a book token ───────────────────────────────────────
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
					'book_id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'description'       => 'The numeric ID of the book to download.',
					),
					'light_book' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'If true, issue a light-book token (serves light.pdf instead of the full parts).',
					),
				),
			)
		);

		// ── Route 2: Exchange a book token for per-part tokens ────────────────
		register_rest_route(
			self::NAMESPACE,
			'/pdf',
			array(
				'methods'             => WP_REST_Server::READABLE,   // = 'GET'
				'callback'            => array( __CLASS__, 'handle_pdf_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'The one-time book token issued by the /token endpoint, or the dev master token.',
					),
					// book_id is only used when presenting the dev master token.
					'book_id'   => array(
						'required'          => false,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'description'       => 'Required when using the dev master token; ignored otherwise.',
					),
					'light_book' => array(
						'required'    => false,
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Only used with the dev master token to simulate a light-book request.',
					),
				),
			)
		);

		// ── Route 3: Consume a part token and stream the PDF bytes ────────────
		register_rest_route(
			self::NAMESPACE,
			'/download',
			array(
				'methods'             => WP_REST_Server::READABLE,   // = 'GET'
				'callback'            => array( __CLASS__, 'handle_download_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => 'The one-time part token issued by the /pdf endpoint.',
					),
				),
			)
		);

		// ── Route 4: Helper — return a fresh nonce for browser clients ────────
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

		// ── Route 5: Debug — show resolved PDF paths (only when WP_DEBUG=true) ─
		register_rest_route(
			self::NAMESPACE,
			'/debug/book/(?P<book_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_debug_request' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'book_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	// ════════════════════════════════════════════════════════════════════════
	// Handler: POST /wp-json/print-api/v1/token
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Issue a one-time book-level download token for the requested book.
	 *
	 * Request flow:
	 *   1. Verify the WP REST nonce (CSRF protection).
	 *   2. (Optional) Require the user to be logged in.
	 *   3. Generate and return a book token.
	 *
	 * @param  WP_REST_Request $request  Full request object (params already validated by 'args').
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_token_request( WP_REST_Request $request ) {

		// ── Step 1: Verify nonce ──────────────────────────────────────────────
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'invalid_nonce',
				'CSRF token missing or invalid. Please reload the page and try again.',
				array( 'status' => 403 )
			);
		}

		// ── Step 2: Optional login check ─────────────────────────────────────
		if ( PRINT_API_REQUIRE_LOGIN && ! is_user_logged_in() ) {
			return new WP_Error(
				'login_required',
				'You must be logged in to download this book.',
				array( 'status' => 401 )
			);
		}

		// ── Step 2b: Rate limit — max 20 token requests per user per minute ──
		// Prevents a logged-in user from flooding wp_options with transients.
		$rate_key   = 'print_api_rate_' . get_current_user_id();
		$rate_count = (int) get_transient( $rate_key );
		if ( $rate_count >= 20 ) {
			return new WP_Error(
				'rate_limited',
				'Too many token requests. Please wait a moment and try again.',
				array( 'status' => 429 )
			);
		}
		set_transient( $rate_key, $rate_count + 1, 60 );

		// ── Step 3: Generate book token ───────────────────────────────────────
		$book_id = $request->get_param( 'book_id' );
		$light   = (bool) $request->get_param( 'light_book' );
		$token   = Print_API_Token_Manager::generate( $book_id, null, $light );

		return new WP_REST_Response(
			array( 'token' => $token ),
			200
		);
	}

	// ════════════════════════════════════════════════════════════════════════
	// Handler: GET /wp-json/print-api/v1/pdf?token=…
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Exchange a one-time book token for three one-time per-part tokens.
	 *
	 * IMPORTANT: This endpoint returns tokens, NOT file URLs.
	 * The client must call /download?token=<part-token> for each part to get
	 * the actual bytes.  This ensures the real file paths are never exposed.
	 *
	 * After this call the book token is permanently invalidated.
	 * Each returned part token is itself one-time and expires in TOKEN_TTL seconds.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_pdf_request( WP_REST_Request $request ) {

		$token        = $request->get_param( 'token' );
		$master_token = PRINT_API_DEV_MASTER_TOKEN;
		$is_master    = $master_token !== ''
						&& defined( 'WP_DEBUG' ) && WP_DEBUG
						&& hash_equals( $master_token, $token );

		$is_light = false;

		if ( $is_master ) {
			// ── Dev master token path ─────────────────────────────────────────
			// Never consumed — reusable across test runs. Requires book_id param.
			$book_id  = absint( $request->get_param( 'book_id' ) );
			$is_light = (bool) $request->get_param( 'light_book' );
			if ( ! $book_id ) {
				return new WP_Error(
					'missing_book_id',
					'book_id query parameter is required when using the master token.',
					array( 'status' => 400 )
				);
			}
		} else {
			// ── Normal path: consume the one-time book token ──────────────────
			$data = Print_API_Token_Manager::consume( $token );

			if ( false === $data ) {
				return new WP_Error(
					'invalid_token',
					'Token is invalid, expired, or has already been used.',
					array( 'status' => 401 )
				);
			}

			// Reject part tokens being presented here — they belong to /download.
			if ( isset( $data['part'] ) ) {
				return new WP_Error(
					'invalid_token',
					'Token is invalid, expired, or has already been used.',
					array( 'status' => 401 )
				);
			}

			$book_id  = (int) $data['book_id'];
			$is_light = ! empty( $data['light'] );
		}

		// ── Light book: single token for light.pdf ────────────────────────────
		if ( $is_light ) {
			if ( false === Print_API_PDF_Resolver::get_light_path( $book_id ) ) {
				return new WP_Error(
					'book_not_found',
					'light.pdf for this book could not be located on the server.',
					array( 'status' => 404 )
				);
			}

			return new WP_REST_Response(
				array(
					'parts'      => array( Print_API_Token_Manager::generate( $book_id, 1, true ) ),
					'part_count' => 1,
					'book_id'    => $book_id,
				),
				200
			);
		}

		// ── Count how many parts exist for this book ──────────────────────────
		$part_count = Print_API_PDF_Resolver::get_part_count( $book_id );

		if ( 0 === $part_count ) {
			return new WP_Error(
				'book_not_found',
				'PDF files for this book could not be located on the server.',
				array( 'status' => 404 )
			);
		}

		// ── Issue one per-part token per PDF part ─────────────────────────────
		// Each token is one-time and bound to a specific (book_id, part) pair.
		// The client uses these tokens with the /download endpoint.
		$part_tokens = array();
		for ( $i = 1; $i <= $part_count; $i++ ) {
			$part_tokens[] = Print_API_Token_Manager::generate( $book_id, $i );
		}

		return new WP_REST_Response(
			array(
				'parts'      => $part_tokens,  // Array of N one-time part tokens
				'part_count' => $part_count,
				'book_id'    => $book_id,
			),
			200
		);
	}

	// ════════════════════════════════════════════════════════════════════════
	// Handler: GET /wp-json/print-api/v1/download?token=…
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Consume a one-time part token and stream the PDF bytes to the client.
	 *
	 * This is the only way to get the file bytes — no permanent URL exists.
	 * Once this endpoint is called with a valid token, the token is gone and
	 * the same token cannot be used to download the file again.
	 *
	 * Flow:
	 *  1. Consume the part token (validates + deletes atomically).
	 *  2. Resolve the absolute filesystem path for (book_id, part).
	 *  3. Send headers and stream the file bytes, then exit.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_Error  Only returned on failure — on success we exit() after streaming.
	 */
	public static function handle_download_request( WP_REST_Request $request ) {

		$token = $request->get_param( 'token' );

		// ── Consume the part token ────────────────────────────────────────────
		$data = Print_API_Token_Manager::consume( $token );

		if ( false === $data ) {
			return new WP_Error(
				'invalid_token',
				'Token is invalid, expired, or has already been used.',
				array( 'status' => 401 )
			);
		}

		// Only part tokens (which carry a 'part' key) are valid here.
		// Reject book tokens that were accidentally sent to this endpoint.
		if ( ! isset( $data['part'] ) ) {
			return new WP_Error(
				'invalid_token',
				'Token is invalid, expired, or has already been used.',
				array( 'status' => 401 )
			);
		}

		$book_id   = (int) $data['book_id'];
		$part_num  = (int) $data['part'];
		$is_light  = ! empty( $data['light'] );

		// ── Resolve file path ─────────────────────────────────────────────────
		if ( $is_light ) {
			$path = Print_API_PDF_Resolver::get_light_path( $book_id );
		} else {
			$path = Print_API_PDF_Resolver::get_part_path( $book_id, $part_num );
		}

		if ( false === $path ) {
			return new WP_Error(
				'book_not_found',
				'PDF file for this part could not be located on the server.',
				array( 'status' => 404 )
			);
		}

		// ── Stream the file ───────────────────────────────────────────────────
		// We send the bytes directly rather than redirecting to a URL.
		// This means the real file path never reaches the client.
		$filename = $is_light ? 'light.pdf' : 'book_' . $book_id . '_part' . $part_num . '.pdf';

		// Prevent any output buffering from truncating large files.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		// readfile() reads the file and writes it directly to the output buffer.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile

		// exit prevents WordPress from appending anything after the file bytes.
		exit;
	}

	// ════════════════════════════════════════════════════════════════════════
	// Handler: GET /wp-json/print-api/v1/nonce
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Return a fresh WP REST nonce.
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

	// ════════════════════════════════════════════════════════════════════════
	// Handler: GET /wp-json/print-api/v1/debug/book/{book_id}
	// ════════════════════════════════════════════════════════════════════════

	/**
	 * Show the resolved filesystem paths for a book.
	 * Only works when WP_DEBUG is true. Returns 403 in production.
	 *
	 * @param  WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_debug_request( WP_REST_Request $request ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return new WP_Error(
				'debug_disabled',
				'Debug endpoint is only available when WP_DEBUG is true in wp-config.php.',
				array( 'status' => 403 )
			);
		}

		$book_id    = $request->get_param( 'book_id' );
		$upload     = wp_upload_dir();
		$dir        = trailingslashit( $upload['basedir'] ) . 'private/books/' . $book_id;
		$part_count = Print_API_PDF_Resolver::get_part_count( $book_id );

		// Show all found parts plus the first missing one so the admin can see
		// exactly where the sequence stops.
		$files = array();
		for ( $i = 1; $i <= $part_count + 1; $i++ ) {
			$path             = $dir . '/part' . $i . '.pdf';
			$files[ 'part' . $i ] = array(
				'expected_path' => $path,
				'exists'        => file_exists( $path ),
			);
		}

		return new WP_REST_Response(
			array(
				'book_id'       => $book_id,
				'expected_dir'  => $dir,
				'dir_exists'    => is_dir( $dir ),
				'part_count'    => $part_count,
				'files'         => $files,
				'uploads_base'  => $upload['basedir'],
			),
			200
		);
	}
}
