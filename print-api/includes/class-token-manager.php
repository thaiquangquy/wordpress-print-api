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
 * Value:       serialized PHP array  ['book_id' => int, 'part' => int|null, 'created_at' => int]
 *
 * Token types
 * ───────────
 * Book token  — issued by /token endpoint, no 'part' key in data.
 *               Consumed by /pdf to mint per-part tokens.
 * Part token  — issued by /pdf for each PDF part (1, 2, 3), has 'part' key.
 *               Consumed by /download to stream the actual file bytes.
 *
 * Single-use guarantee
 * ────────────────────
 * consume() deletes the transient before returning the data.
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
	 * Prefix for the short-lived consume-lock transients.
	 * A lock is set for 10 seconds when a token is being consumed so that a
	 * second concurrent request racing on the same token is rejected immediately
	 * rather than proceeding past the get_transient() call.
	 */
	const LOCK_PREFIX = 'print_api_lock_';

	/**
	 * Generate a new one-time token for the given book (and optionally a specific part).
	 *
	 * Steps:
	 *  1. Generate 32 cryptographically random bytes (PHP 7+).
	 *  2. Encode as 64-character lowercase hex string.
	 *  3. Store in a transient that expires in TOKEN_TTL seconds.
	 *  4. Return the token string to the caller.
	 *
	 * @param  int      $book_id  The book this token grants access to.
	 * @param  int|null $part     If set, this is a part token locked to a specific
	 *                            part number (1–N). Omit for a book-level token.
	 * @param  bool     $light    If true, the token grants access to light.pdf only.
	 * @return string             64-character hex token.
	 */
	public static function generate( $book_id, $part = null, $light = false, $user_id = 0 ) {
		// random_bytes() uses the OS CSPRNG (/dev/urandom on Linux).
		// bin2hex() converts the binary string to readable hex.
		// Result: 64 hex chars = 256 bits of entropy → infeasible to guess.
		$token = bin2hex( random_bytes( 32 ) );

		// Data we want to retrieve when the token is later consumed.
		$data = array(
			'book_id'    => (int) $book_id,
			'created_at' => time(),   // Unix timestamp — useful for audit logs
		);

		// Book tokens carry the user_id so /mark-installed can update user meta
		// without needing a WordPress session from the Electron app.
		if ( $user_id ) {
			$data['user_id'] = (int) $user_id;
		}

		// Part tokens carry the part number so the download endpoint knows
		// exactly which file to stream without any client-supplied parameters.
		if ( null !== $part ) {
			$data['part'] = (int) $part;
		}

		// Light tokens grant access to light.pdf instead of the regular parts.
		if ( $light ) {
			$data['light'] = true;
		}

		// set_transient( key, value, expiration_in_seconds )
		// WordPress serializes $data automatically.
		set_transient( self::KEY_PREFIX . $token, $data, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Consume a token: validate it, delete it immediately, return the stored data.
	 *
	 * This is called when the client wants to exchange a token for the next step.
	 * "Consume" means the token is gone after this call regardless of outcome —
	 * it can never be used again.
	 *
	 * Flow:
	 *  1. Read the transient. If it doesn't exist (expired or already used) → false.
	 *  2. Delete the transient BEFORE returning the data.
	 *     Deleting first means: even if the code crashes after delete, the token
	 *     is gone and cannot be replayed.
	 *  3. Return the full data array so the caller can inspect book_id and part.
	 *
	 * @param  string      $token  The raw token string from the client.
	 * @return array|false         Data array on success:
	 *                               ['book_id' => int, 'created_at' => int]           (book token)
	 *                               ['book_id' => int, 'part' => int, 'created_at' => int] (part token)
	 *                             false if token is invalid/expired/used.
	 */
	public static function consume( $token ) {
		// Sanitize: strip anything that isn't a hex character.
		// This also protects against extremely long strings that could abuse the DB.
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) );

		// A valid token is always exactly 64 hex chars (32 bytes × 2 hex chars/byte).
		if ( strlen( $token ) !== 64 ) {
			return false;
		}

		// ── Concurrency lock ──────────────────────────────────────────────────
		// get_transient() + delete_transient() are two separate DB round-trips.
		// Under heavy concurrency, two requests could both read the same token
		// before either deletes it. The lock transient closes that window:
		// only the request that successfully writes the lock proceeds; any other
		// concurrent request for the same token finds the lock and returns false.
		//
		// Note: set_transient() is not a true atomic test-and-set on the default
		// DB backend, but the lock window (10 s) is far shorter than the token
		// TTL (300 s), making a successful double-consume extremely unlikely.
		$lock_key = self::LOCK_PREFIX . $token;
		if ( get_transient( $lock_key ) ) {
			return false;  // Another request is already consuming this token.
		}
		set_transient( $lock_key, 1, 10 );  // Hold the lock for 10 seconds.

		$key  = self::KEY_PREFIX . $token;
		$data = get_transient( $key );   // Returns false if not found or expired.

		if ( false === $data ) {
			// Token doesn't exist: either it expired, was already used, or was never valid.
			delete_transient( $lock_key );
			return false;
		}

		// ── Delete FIRST ──────────────────────────────────────────────────────
		// Invalidate the token before we return anything.
		// If we returned the data first and then crashed, the token would still
		// exist and could be replayed. Delete-first prevents that.
		delete_transient( $key );
		// Lock expires naturally after 10 s — no need to delete it manually.
		// Keeping it alive prevents any late-arriving duplicate request from
		// re-reading the (now deleted) token key and getting a false "not found".

		return $data;
	}

	/**
	 * Peek at a token's data without consuming it.
	 *
	 * Used by /mark-installed so the Electron app can identify the WordPress
	 * user without holding a session cookie. The token is left intact so the
	 * subsequent /pdf call can still consume it normally.
	 *
	 * @param  string      $token  The raw token string from the client.
	 * @return array|false         Data array on success, false if invalid/expired.
	 */
	public static function peek( $token ) {
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) );

		if ( strlen( $token ) !== 64 ) {
			return false;
		}

		$data = get_transient( self::KEY_PREFIX . $token );

		return false !== $data ? $data : false;
	}
}
