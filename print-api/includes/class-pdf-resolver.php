<?php
/**
 * PDF Resolver
 *
 * Maps a book_id (and optionally a part number) to filesystem paths.
 *
 * Directory convention
 * ────────────────────
 * PDFs live inside the WordPress uploads directory under a sub-folder:
 *
 *   wp-content/uploads/private/books/{book_id}/part1.pdf
 *   wp-content/uploads/private/books/{book_id}/part2.pdf
 *   wp-content/uploads/private/books/{book_id}/partN.pdf
 *
 * Any number of parts is supported. Parts must be named part1.pdf, part2.pdf,
 * … partN.pdf with no gaps. The plugin counts how many exist automatically.
 *
 * This folder is created on plugin activation and protected by .htaccess so
 * that direct HTTP downloads are blocked — files can only be accessed via the
 * signed token flow provided by this plugin.
 *
 * IMPORTANT: This class deliberately does NOT return public URLs for the PDF
 * files. The download flow streams file bytes through the /download endpoint
 * so the real file paths are never exposed to clients.
 *
 * How to add a new book
 * ─────────────────────
 * 1. Create the folder:  wp-content/uploads/private/books/42/
 * 2. Drop in:            part1.pdf, part2.pdf, … partN.pdf  (any count)
 * That's it. No code change required.
 *
 * Extending this class
 * ─────────────────────
 * If your PDF parts are stored somewhere else (S3, external CDN, etc.) you
 * only need to change get_part_path() to return the correct local path (or
 * adapt handle_download_request() to redirect to a short-lived signed URL).
 *
 * @package PrintAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Print_API_PDF_Resolver {

	/**
	 * Count how many consecutive part files exist for the given book.
	 *
	 * Counts part1.pdf, part2.pdf, … stopping at the first missing number.
	 * Returns 0 if the book directory doesn't exist or part1.pdf is missing.
	 *
	 * Used by the /pdf endpoint to know how many part tokens to mint, and by
	 * the debug endpoint to report the book's state.
	 *
	 * @param  int  $book_id
	 * @return int  Number of consecutive parts found (0 if book not found).
	 */
	public static function get_part_count( $book_id ) {
		$book_id = (int) $book_id;
		$upload  = wp_upload_dir();
		$dir     = trailingslashit( $upload['basedir'] ) . 'private/books/' . $book_id;

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$count = 0;
		while ( file_exists( $dir . '/part' . ( $count + 1 ) . '.pdf' ) ) {
			$count++;
		}

		return $count;
	}

	/**
	 * Return the absolute filesystem path for a single PDF part.
	 *
	 * This path is used only server-side to stream the file bytes.
	 * It is never sent to the client.
	 *
	 * @param  int        $book_id   The book.
	 * @param  int        $part_num  Part number (1-N).
	 * @return string|false          Absolute path on success, false if the file
	 *                               doesn't exist or part_num is less than 1.
	 */
	public static function get_part_path( $book_id, $part_num ) {
		$book_id  = (int) $book_id;
		$part_num = (int) $part_num;

		if ( $part_num < 1 ) {
			return false;
		}

		$upload = wp_upload_dir();
		$path   = trailingslashit( $upload['basedir'] )
		          . 'private/books/' . $book_id
		          . '/part' . $part_num . '.pdf';

		return file_exists( $path ) ? $path : false;
	}
}
