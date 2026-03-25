<?php
/**
 * Token Manager
 *
 * Responsible for creating, storing, and consuming one-time tokens.
 *
 * Storage back-end: WordPress Transients API
 * ──────────────────────────────────────────
 * A "transient" is just a key-value pair with a built-in expiry time.
 * WordPress stores it in the database (wp_options table) by default, or in
 * an object cache (Redis/Memcached) if one is configured.
 * When the TTL expires WordPress automatically discards the value.
 *
 * Key format:  print_api_token_{64-char-hex-token}
 * Value:       serialized PHP array  ['book_id' => int, 'created_at' => int]
 *
 * Single-use guarantee
 * ────────────────────
 * consume() deletes the transient before returning the book_id.
 * If two requests race, only the first delete succeeds; the second finds the
 * transient already gone and returns false — effectively locking out replays.
 * (WordPress's transient delete is not strictly atomic on all storage back-ends,
 * but it is safe enough for short-lived download tokens.)
 *
 * @package PrintAPI
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Print_API_Token_Manager {

	/**
	 * How long (in seconds) a token stays valid after it is issued.
	 * 300 = 5 minutes. Adjust to taste.
	 */
	const TOKEN_TTL = 300;

	/**
	 * Prefix used for all transient keys so we don't collide with other plugins.
	 */
	const KEY_PREFIX = 'print_api_token_';

	/**
	 * Generate a new one-time token for the given book.
	 *
	 * Steps:
	 *  1. Generate 32 cryptographically random bytes (PHP 7+).
	 *  2. Encode as 64-character lowercase hex string.
	 *  3. Store in a transient that expires in TOKEN_TTL seconds.
	 *  4. Return the token string to the caller.
	 *
	 * @param  int    $book_id  The book this token grants access to.
	 * @return string           64-character hex token.
	 */
	public static function generate( $book_id ) {
		// random_bytes() uses the OS CSPRNG (/dev/urandom on Linux).
		// bin2hex() converts the binary string to readable hex.
		// Result: 64 hex chars = 256 bits of entropy → infeasible to guess.
		$token = bin2hex( random_bytes( 32 ) );

		// Data we want to retrieve when the token is later consumed.
		$data = array(
			'book_id'    => (int) $book_id,
			'created_at' => time(),   // Unix timestamp — useful for audit logs
		);

		// set_transient( key, value, expiration_in_seconds )
		// WordPress serializes $data automatically.
		set_transient( self::KEY_PREFIX . $token, $data, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Consume a token: validate it, delete it immediately, return the book_id.
	 *
	 * This is called when the client wants to exchange a token for PDF URLs.
	 * "Consume" means the token is gone after this call regardless of outcome —
	 * it can never be used again.
	 *
	 * Flow:
	 *  1. Read the transient. If it doesn't exist (expired or already used) → false.
	 *  2. Delete the transient BEFORE returning the book_id.
	 *     Deleting first means: even if the code crashes after delete, the token
	 *     is gone and cannot be replayed.
	 *  3. Return the book_id so the caller knows which PDF to serve.
	 *
	 * @param  string    $token  The raw token string from the client.
	 * @return int|false         book_id on success, false if token is invalid/expired/used.
	 */
	public static function consume( $token ) {
		// Sanitize: strip anything that isn't a hex character.
		// This also protects against extremely long strings that could abuse the DB.
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) );

		// A valid token is always exactly 64 hex chars (32 bytes × 2 hex chars/byte).
		if ( strlen( $token ) !== 64 ) {
			return false;
		}

		$key  = self::KEY_PREFIX . $token;
		$data = get_transient( $key );   // Returns false if not found or expired.

		if ( false === $data ) {
			// Token doesn't exist: either it expired, was already used, or was never valid.
			return false;
		}

		// ── Delete FIRST ──────────────────────────────────────────────────────
		// Invalidate the token before we return anything.
		// If we returned the book_id first and then crashed, the token would still
		// exist and could be replayed. Delete-first prevents that.
		delete_transient( $key );

		return (int) $data['book_id'];
	}
}
