<?php
/**
 * PDF Resolver
 *
 * Maps a book_id to an array of exactly 3 PDF part URLs.
 *
 * Directory convention
 * ────────────────────
 * PDFs live inside the WordPress uploads directory under a sub-folder:
 *
 *   wp-content/uploads/print-api/book_{book_id}/part1.pdf
 *   wp-content/uploads/print-api/book_{book_id}/part2.pdf
 *   wp-content/uploads/print-api/book_{book_id}/part3.pdf
 *
 * This folder is created on plugin activation and protected by .htaccess so
 * that direct HTTP downloads are blocked — files can only be accessed via the
 * signed token flow provided by this plugin.
 *
 * How to add a new book
 * ─────────────────────
 * 1. Create the folder:  wp-content/uploads/print-api/book_42/
 * 2. Drop in:            part1.pdf, part2.pdf, part3.pdf
 * That's it. No code change required.
 *
 * Extending this class
 * ─────────────────────
 * If your PDF parts are stored somewhere else (S3, external CDN, etc.) you
 * only need to change get_parts() to return the correct URLs. Everything else
 * in the plugin stays the same.
 *
 * @package PrintAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Print_API_PDF_Resolver {

	/**
	 * Return the 3 PDF part URLs for a given book.
	 *
	 * We check that each file actually exists on disk before including it in
	 * the response — this prevents returning broken links to the client.
	 *
	 * wp_upload_dir() returns:
	 *   'basedir'  → /absolute/path/to/wp-content/uploads   (filesystem path)
	 *   'baseurl'  → https://example.com/wp-content/uploads  (public URL)
	 *
	 * We use 'basedir' for file_exists() checks and 'baseurl' for the URLs we
	 * hand back to the client.
	 *
	 * @param  int          $book_id  The book to look up.
	 * @return string[]|false         Array of 3 URLs on success, false if the
	 *                                book directory or any part file is missing.
	 */
	public static function get_parts( $book_id ) {
		$book_id = (int) $book_id;

		// wp_upload_dir() is the canonical WordPress function for locating the
		// uploads directory. It handles multi-site, custom upload paths, etc.
		$upload   = wp_upload_dir();
		$base_dir = trailingslashit( $upload['basedir'] ) . 'print-api/book_' . $book_id;
		$base_url = trailingslashit( $upload['baseurl'] ) . 'print-api/book_' . $book_id;

		// Validate: the book directory must exist.
		if ( ! is_dir( $base_dir ) ) {
			return false;
		}

		$parts = array();

		// Check all 3 part files. If any is missing we return false rather than
		// a partial list — the client expects exactly 3 parts.
		for ( $i = 1; $i <= 3; $i++ ) {
			$filename = 'part' . $i . '.pdf';
			$filepath = $base_dir . '/' . $filename;

			if ( ! file_exists( $filepath ) ) {
				// One part is missing → fail the whole request.
				return false;
			}

			// Build the public URL for this part.
			// Note: direct HTTP access is blocked by .htaccess, but we return
			// the URL anyway so that a server-side proxy or app can fetch it
			// with the correct credentials. Adjust to your architecture as needed.
			$parts[] = $base_url . '/' . $filename;
		}

		return $parts;   // Always exactly 3 elements.
	}
}
