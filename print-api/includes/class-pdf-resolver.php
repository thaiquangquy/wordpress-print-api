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
 *   wp-content/uploads/private/books/{book_id}/{slug}-part1.pdf
 *   wp-content/uploads/private/books/{book_id}/{slug}-part2.pdf
 *   wp-content/uploads/private/books/{book_id}/{slug}-partN.pdf
 *
 * Example: cyberthrone-coloring-book-for-kids-part1.pdf
 *
 * Any number of parts is supported. Parts must follow the {slug}-partN.pdf
 * pattern with no gaps. The slug is auto-discovered from the filesystem —
 * no config required. The plugin counts how many exist automatically.
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
 * 2. Drop in:            {slug}-part1.pdf, {slug}-part2.pdf, … (any count)
 *    and optionally:     {slug}-light.pdf
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
	 * Discover the slug prefix used by a book's PDF files.
	 *
	 * Globs for the first `*-part1.pdf` in the book directory and extracts
	 * the prefix (everything before "part1.pdf").  Returns an empty string
	 * when no prefixed file is found so callers degrade gracefully.
	 *
	 * @param  string $dir  Absolute path to the book's directory.
	 * @return string       Prefix including trailing dash, e.g. "cyberthrone-coloring-book-for-kids-".
	 */
	private static function get_slug_prefix( $dir ) {
		$matches = glob( trailingslashit( $dir ) . '*-part1.pdf' );
		if ( empty( $matches ) ) {
			return '';
		}
		$filename = basename( $matches[0] );
		return substr( $filename, 0, -strlen( 'part1.pdf' ) );
	}

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

		$prefix = self::get_slug_prefix( $dir );
		$count  = 0;
		while ( file_exists( $dir . '/' . $prefix . 'part' . ( $count + 1 ) . '.pdf' ) ) {
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
		$dir    = trailingslashit( $upload['basedir'] ) . 'private/books/' . $book_id;
		$prefix = self::get_slug_prefix( $dir );
		$path   = $dir . '/' . $prefix . 'part' . $part_num . '.pdf';

		return file_exists( $path ) ? $path : false;
	}

	/**
	 * Return the absolute filesystem path for a book's light.pdf.
	 *
	 * @param  int        $book_id
	 * @return string|false  Absolute path on success, false if the file doesn't exist.
	 */
	public static function get_light_path( $book_id ) {
		$book_id = (int) $book_id;
		$upload  = wp_upload_dir();
		$dir     = trailingslashit( $upload['basedir'] ) . 'private/books/' . $book_id;

		// Try slug-prefixed light file first: {slug}-light.pdf
		$matches = glob( trailingslashit( $dir ) . '*-light.pdf' );
		if ( ! empty( $matches ) ) {
			return $matches[0];
		}

		// Fallback to bare light.pdf for backwards compatibility during transition.
		$path = $dir . '/light.pdf';
		return file_exists( $path ) ? $path : false;
	}
}
