<?php
/**
 * Plugin Name:  Print API
 * Plugin URI:   https://example.com/print-api
 * Description:  Secure PDF download via short-lived single-use tokens.
 *               Exposes two REST endpoints:
 *                 POST /wp-json/print-api/v1/token  – issue a token for a book
 *                 GET  /wp-json/print-api/v1/pdf    – exchange token for PDF part URLs
 * Version:      1.0.0
 * Author:       Your Name
 * License:      GPL-2.0-or-later
 * Text Domain:  print-api
 *
 * @package PrintAPI
 */

// ─── Safety: prevent direct file access ────────────────────────────────────
// WordPress defines ABSPATH when it loads. Anyone hitting this file directly
// from a browser would not have ABSPATH set, so we bail out early.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ──────────────────────────────────────────────────────────────
// PRINT_API_VERSION  – bump this when you release a new version
// PRINT_API_DIR      – absolute filesystem path to this plugin's folder (with trailing slash)
// PRINT_API_URL      – public URL to this plugin's folder (with trailing slash)
// PRINT_API_REQUIRE_LOGIN – set to true to require the user to be logged in
//                           before they can request a token
define( 'PRINT_API_VERSION',       '1.0.0' );
define( 'PRINT_API_DIR',           plugin_dir_path( __FILE__ ) );
define( 'PRINT_API_URL',           plugin_dir_url( __FILE__ ) );
define( 'PRINT_API_REQUIRE_LOGIN', false );   // ← change to true for auth-gated downloads

// ─── Load class files ────────────────────────────────────────────────────────
// require_once makes sure each file is included exactly once even if something
// tries to load it again.
require_once PRINT_API_DIR . 'includes/class-token-manager.php';
require_once PRINT_API_DIR . 'includes/class-pdf-resolver.php';
require_once PRINT_API_DIR . 'includes/class-rest-api.php';

// ─── Bootstrap REST API ──────────────────────────────────────────────────────
// 'rest_api_init' fires after WordPress has loaded its REST infrastructure.
// We register our routes inside this hook so they're available at the right time.
add_action( 'rest_api_init', array( 'Print_API_Rest', 'register_routes' ) );

// ─── Enqueue frontend script ─────────────────────────────────────────────────
// 'wp_enqueue_scripts' fires on every front-end page load.
// We attach our JS and inject the nonce so the browser can authenticate requests.
add_action( 'wp_enqueue_scripts', 'print_api_enqueue_scripts' );

/**
 * Enqueue the frontend JavaScript and inject the WP REST nonce.
 *
 * wp_localize_script() is the WordPress-approved way to pass PHP values to JS.
 * It outputs a small <script> block before our JS file that sets a global
 * variable (here: window.printApiConfig).
 *
 * The nonce is a short-lived, user-specific token WordPress generates for CSRF
 * protection. The browser sends it back as the X-WP-Nonce header and WordPress
 * validates it automatically before our REST handler runs.
 */
function print_api_enqueue_scripts() {
	wp_enqueue_script(
		'print-api-frontend',                          // handle (unique ID)
		PRINT_API_URL . 'assets/js/frontend.js',       // URL to the JS file
		array(),                                        // dependencies (none)
		PRINT_API_VERSION,                              // version → cache-busting
		true                                            // load in footer (best practice)
	);

	// Inject nonce and REST root URL so the JS file has everything it needs.
	wp_localize_script(
		'print-api-frontend',   // must match the handle above
		'printApiConfig',        // name of the JS global object
		array(
			// wp_create_nonce('wp_rest') generates a standard WP REST nonce.
			// WordPress verifies this automatically when it sees X-WP-Nonce header.
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			// rest_url() gives the correct REST root even on non-standard installs.
			'restUrl' => rest_url( 'print-api/v1' ),
		)
	);
}

// ─── Activation hook ─────────────────────────────────────────────────────────
// Runs once when an admin clicks "Activate" in WP Admin → Plugins.
// We use it to create the uploads sub-directory and protect it with .htaccess.
register_activation_hook( __FILE__, 'print_api_activate' );

/**
 * Plugin activation: create uploads directory and protect it.
 *
 * wp_upload_dir() returns an array with 'basedir' (filesystem path) and
 * 'baseurl' (public URL). We create our sub-directory there so WordPress
 * handles permissions and the directory lives in the standard uploads location.
 */
function print_api_activate() {
	$upload  = wp_upload_dir();
	$pdf_dir = $upload['basedir'] . '/print-api';

	// wp_mkdir_p() creates the directory recursively, like `mkdir -p`.
	if ( ! file_exists( $pdf_dir ) ) {
		wp_mkdir_p( $pdf_dir );
	}

	// Drop an .htaccess file so Apache denies direct HTTP access to the PDFs.
	// Even if someone guesses the URL they cannot download without a valid token.
	$htaccess = $pdf_dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		// "Deny from all" blocks every direct HTTP request to this directory.
		file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" ); // phpcs:ignore
	}
}
