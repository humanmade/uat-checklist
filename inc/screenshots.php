<?php
/**
 * A tester's screenshot on a result or an issue report: sent with Save result or the report form, stored as an image
 * attachment whose parent is the result or report, shown beside a result's comment, and deleted with its parent.
 *
 * Testers are often Subscribers, who cannot use the Media Library, so the upload is taken here rather than by core's
 * media route, and only by the panel's result and report routes, under their rate limits. Only images are accepted, checked by
 * their content as well as their name. Each is capped in size and in dimensions, a tester's uploads are capped per day,
 * and the metadata of every type is stripped, since a phone photo carries where it was taken and the file's address is
 * public.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Screenshots;

use HM\UAT_Checklist\Reports;
use HM\UAT_Checklist\Results;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Name of the file field in the step form and in the request.
 */
const FIELD = 'screenshot';

/**
 * The image types a tester may upload, in the form wp_handle_upload() takes.
 */
const MIMES = [
	'jpg|jpeg|jpe' => 'image/jpeg',
	'png'          => 'image/png',
	'gif'          => 'image/gif',
	'webp'         => 'image/webp',
];

/**
 * Largest screenshot accepted, in bytes, unless the site's upload limit is lower.
 */
const MAX_BYTES = 20 * MB_IN_BYTES;

/**
 * Longest side accepted, in pixels, so a small file cannot claim dimensions that exhaust memory when it is decoded.
 */
const MAX_DIMENSION = 10000;

/**
 * Most pixels in all accepted, width times height.
 */
const MAX_PIXELS = 50000000;

/**
 * PNG chunks that carry metadata and no picture, which are dropped. Every other chunk is kept.
 */
const PNG_METADATA_CHUNKS = [ 'eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME' ];

/**
 * GIF application extensions that are kept, because they hold the loop count of an animation.
 */
const GIF_KEPT_APPLICATIONS = [ 'NETSCAPE2.0', 'ANIMEXTS1.0' ];

/**
 * Most screenshots one tester can upload in DAY_WINDOW.
 */
const DAILY_LIMIT = 50;

/**
 * Period DAILY_LIMIT is counted over, in seconds.
 */
const DAY_WINDOW = DAY_IN_SECONDS;

/**
 * Cache group for each result's screenshot ID, for the request only.
 */
const CACHE_GROUP = 'hm_uat_checklist_screenshots';

/**
 * Meta key on a screenshot copied from an earlier result, holding the attachment it was copied from.
 */
const COPIED_FROM = '_uat_copied_from';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	// Per request: a persistent cache would need clearing on every attachment change anywhere on the site.
	wp_cache_add_non_persistent_groups( CACHE_GROUP );
	add_action( 'before_delete_post', __NAMESPACE__ . '\\delete_with_result', 10, 2 );
}

/**
 * Returns the largest screenshot accepted, in bytes.
 *
 * @return int Bytes.
 */
function max_bytes(): int {
	/**
	 * Filters the largest screenshot a tester can upload. The site's upload limit still applies when it is lower.
	 *
	 * @param int $bytes Bytes. Default 20 MB.
	 */
	$bytes = (int) apply_filters( 'hm_uat_checklist_screenshot_max_bytes', MAX_BYTES );

	return min( max( 0, $bytes ), wp_max_upload_size() );
}

/**
 * Returns the longest side a screenshot can have, in pixels.
 *
 * @return int Pixels.
 */
function max_dimension(): int {
	/**
	 * Filters the longest side a screenshot can have, in pixels.
	 *
	 * @param int $pixels Pixels. Default 10000.
	 */
	return max( 0, (int) apply_filters( 'hm_uat_checklist_screenshot_max_dimension', MAX_DIMENSION ) );
}

/**
 * Returns the most pixels, width times height, a screenshot can have.
 *
 * @return int Pixels.
 */
function max_pixels(): int {
	/**
	 * Filters the most pixels, width times height, a screenshot can have.
	 *
	 * @param int $pixels Pixels. Default 50 million.
	 */
	return max( 0, (int) apply_filters( 'hm_uat_checklist_screenshot_max_pixels', MAX_PIXELS ) );
}

/**
 * Returns how many screenshots one tester can upload in a day.
 *
 * @return int Screenshots.
 */
function daily_limit(): int {
	/**
	 * Filters how many screenshots one tester can upload in a day.
	 *
	 * @param int $limit Screenshots. Default 50.
	 */
	return max( 0, (int) apply_filters( 'hm_uat_checklist_screenshot_daily_limit', DAILY_LIMIT ) );
}

/**
 * Counts the screenshots a user has uploaded to results and reports in the last day.
 *
 * Counted from the attachments themselves, as the rate limits count results and reports. A screenshot carried over to a
 * new result is not counted: the tester chose no file.
 *
 * @param int $user_id User ID.
 * @return int Screenshots.
 */
function uploaded_today( int $user_id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query cannot filter by the parent's post type, and the answer changes with every upload.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} AS screenshot
			INNER JOIN {$wpdb->posts} AS owner ON owner.ID = screenshot.post_parent
			LEFT JOIN {$wpdb->postmeta} AS copied ON copied.post_id = screenshot.ID AND copied.meta_key = %s
			WHERE screenshot.post_type = 'attachment' AND screenshot.post_author = %d
			AND screenshot.post_date_gmt >= %s AND owner.post_type IN ( %s, %s ) AND copied.meta_id IS NULL",
			COPIED_FROM,
			$user_id,
			gmdate( 'Y-m-d H:i:s', time() - DAY_WINDOW ),
			Results\POST_TYPE,
			Reports\POST_TYPE
		)
	);
}

/**
 * Returns the file in a request's screenshot field, or null when none was chosen.
 *
 * @param array<string, mixed> $files The request's file parameters.
 * @return array<string, mixed>|null The file, as PHP describes an upload.
 */
function chosen_file( array $files ): ?array {
	$file = $files[ FIELD ] ?? null;

	return is_array( $file ) && ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_NO_FILE ? $file : null;
}

/**
 * Moves an uploaded screenshot into the uploads directory, refusing anything but an image within the size limits.
 *
 * Done before the result is saved, so a refused file stores nothing. The dimensions are read from the temporary file
 * first, since decoding comes after the move and a small file can declare an image too large to decode.
 *
 * @param array<string, mixed> $file The upload, as PHP describes it.
 * @return array{file: string, url: string, type: string}|WP_Error The stored file, or why it was refused.
 */
function store( array $file ): array|WP_Error {
	if ( (int) ( $file['size'] ?? 0 ) > max_bytes() ) {
		return refused(
			sprintf(
				/* translators: %s: the largest file size the site accepts, such as 2 MB. */
				__( 'The screenshot is larger than %s. Choose a smaller image.', 'uat-checklist' ),
				size_format( max_bytes() )
			)
		);
	}

	$within = check_dimensions( (string) ( $file['tmp_name'] ?? '' ) );

	if ( is_wp_error( $within ) ) {
		return $within;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$upload = wp_handle_upload(
		$file,
		[
			'test_form' => false,
			'mimes'     => MIMES,
		]
	);

	if ( isset( $upload['error'] ) ) {
		return refused( (string) $upload['error'] );
	}

	$stripped = $upload['type'] === 'image/jpeg'
		? strip_metadata( $upload['file'] )
		: strip_container_metadata( $upload['file'], $upload['type'] );

	if ( is_wp_error( $stripped ) ) {
		wp_delete_file( $upload['file'] );

		return refused( __( 'The screenshot could not be processed. Try saving it again as a PNG.', 'uat-checklist' ) );
	}

	return $upload;
}

/**
 * Checks an image's dimensions, read from its header, are within the limits.
 *
 * @param string $path Image file, not yet decoded.
 * @return true|WP_Error True when within them, or why not.
 */
function check_dimensions( string $path ): true|WP_Error {
	// A file that is not an image warns; it is refused below all the same.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$size = @getimagesize( $path );

	if ( $size === false || $size[0] < 1 || $size[1] < 1 ) {
		return refused( __( 'The screenshot is not an image that can be read. Choose a PNG, JPEG, GIF or WebP.', 'uat-checklist' ) );
	}

	if ( max( $size[0], $size[1] ) > max_dimension() || $size[0] * $size[1] > max_pixels() ) {
		return refused(
			sprintf(
				/* translators: 1: the longest side allowed, in pixels. 2: the most pixels allowed in all. */
				__( 'The screenshot is more than %1$s pixels on a side or %2$s pixels in all. Choose a smaller image.', 'uat-checklist' ),
				number_format_i18n( max_dimension() ),
				number_format_i18n( max_pixels() )
			)
		);
	}

	return true;
}

/**
 * Rewrites a JPEG in place without its metadata, turned upright first since the turn is part of what is removed.
 *
 * A phone photo's EXIF holds where it was taken, and the file's address opens without logging in. GD is used
 * because it writes no EXIF: Imagick, core's first choice, keeps it when it saves an image at its own size.
 * Refused rather than kept whenever the rewrite cannot be made as it should, so a photo's location is never
 * published by default and a photo is never stored sideways. The other types are not re-encoded, which would flatten
 * an animation: see strip_container_metadata().
 *
 * @param string $path JPEG file.
 * @return true|WP_Error True once rewritten, or why it could not be.
 */
function strip_metadata( string $path ): true|WP_Error {
	// Core reads the turn with exif_read_data() and skips it silently without this extension. Once the EXIF is gone,
	// nothing would say to turn a portrait photo.
	if ( ! extension_loaded( 'exif' ) ) {
		return new WP_Error( 'uat_checklist_no_exif', 'PHP\'s exif extension is needed to turn a JPEG upright.' );
	}

	add_filter( 'wp_image_editors', __NAMESPACE__ . '\\gd_only' );

	try {
		$editor = wp_get_image_editor( $path );
	} finally {
		remove_filter( 'wp_image_editors', __NAMESPACE__ . '\\gd_only' );
	}

	if ( is_wp_error( $editor ) ) {
		return $editor;
	}

	$turned = $editor->maybe_exif_rotate();

	if ( is_wp_error( $turned ) ) {
		return $turned;
	}

	// Core converts on save whatever this filter maps, even a save that names its type: a JPEG-to-WebP plugin would
	// write a WebP beside the JPEG and leave the JPEG, EXIF and all, as the file attached. Last, so it has the final say.
	add_filter( 'image_editor_output_format', __NAMESPACE__ . '\\no_conversion', PHP_INT_MAX );

	try {
		$saved = $editor->save( $path, 'image/jpeg' );
	} finally {
		remove_filter( 'image_editor_output_format', __NAMESPACE__ . '\\no_conversion', PHP_INT_MAX );
	}

	return is_wp_error( $saved ) ? $saved : true;
}

/**
 * Rewrites a PNG, GIF or WebP in place without its metadata, keeping the picture and any animation as they were.
 *
 * Each file's blocks are copied as they are and the metadata ones left out. Refused when the file is not laid out as
 * its type says, since the input is the tester's.
 *
 * @param string $path File.
 * @param string $type Its media type.
 * @return true|WP_Error True once rewritten, or why it could not be.
 */
function strip_container_metadata( string $path, string $type ): true|WP_Error {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file this request has just stored.
	$bytes = (string) file_get_contents( $path );
	$clean = match ( $type ) {
		'image/png'  => strip_png( $bytes ),
		'image/gif'  => strip_gif( $bytes ),
		'image/webp' => strip_webp( $bytes ),
		default      => null,
	};

	if ( $clean === null ) {
		return new WP_Error( 'uat_checklist_malformed_image', 'The image is not laid out as its type says.' );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a file this request has just stored.
	if ( $clean !== $bytes && file_put_contents( $path, $clean ) === false ) {
		return new WP_Error( 'uat_checklist_not_rewritten', 'The image could not be written without its metadata.' );
	}

	return true;
}

/**
 * Returns a PNG without its text, EXIF and time chunks, or null when it is not a whole PNG.
 *
 * Whatever follows the end chunk is dropped too.
 *
 * @param string $bytes PNG file.
 * @return string|null PNG file, or null when truncated or out of order.
 */
function strip_png( string $bytes ): ?string {
	$length = strlen( $bytes );

	if ( ! str_starts_with( $bytes, "\x89PNG\r\n\x1A\n" ) ) {
		return null;
	}

	$kept = [ substr( $bytes, 0, 8 ) ];
	$pos  = 8;

	// Each chunk is a length, a type, that many bytes, and a CRC.
	while ( $pos + 12 <= $length ) {
		$size  = (int) ( unpack( 'N', $bytes, $pos )[1] ?? 0 );
		$type  = substr( $bytes, $pos + 4, 4 );
		$chunk = $pos + 12 + $size;

		if ( $chunk > $length || ( $pos === 8 && $type !== 'IHDR' ) ) {
			return null;
		}

		if ( ! in_array( $type, PNG_METADATA_CHUNKS, true ) ) {
			$kept[] = substr( $bytes, $pos, $chunk - $pos );
		}

		if ( $type === 'IEND' ) {
			return implode( '', $kept );
		}

		$pos = $chunk;
	}

	return null;
}

/**
 * Returns a WebP without its EXIF and XMP chunks, or null when it is not a whole WebP.
 *
 * The RIFF size is rewritten, and the extended header's EXIF and XMP flags cleared. Whatever follows the RIFF is dropped.
 *
 * @param string $bytes WebP file.
 * @return string|null WebP file, or null when truncated or out of order.
 */
function strip_webp( string $bytes ): ?string {
	$length = strlen( $bytes );

	if ( $length < 12 || ! str_starts_with( $bytes, 'RIFF' ) || substr( $bytes, 8, 4 ) !== 'WEBP' ) {
		return null;
	}

	$end  = 8 + (int) ( unpack( 'V', $bytes, 4 )[1] ?? 0 );
	$pos  = 12;
	$kept = [];

	if ( $end > $length ) {
		return null;
	}

	// Each chunk is a type, a length, that many bytes, and one more when the length is odd.
	while ( $pos < $end ) {
		$size  = (int) ( unpack( 'V', $bytes, $pos + 4 )[1] ?? 0 );
		$chunk = $pos + 8 + $size + ( $size & 1 );

		if ( $pos + 8 > $end || $chunk > $end ) {
			return null;
		}

		$type = substr( $bytes, $pos, 4 );

		if ( $type === 'VP8X' ) {
			if ( $size < 10 ) {
				return null;
			}

			// The flags byte: 0x08 says EXIF follows and 0x04 XMP.
			$kept[] = substr( $bytes, $pos, 8 ) . chr( ord( $bytes[ $pos + 8 ] ) & ~0x0C ) . substr( $bytes, $pos + 9, $chunk - $pos - 9 );
		} elseif ( $type !== 'EXIF' && $type !== 'XMP ' ) {
			$kept[] = substr( $bytes, $pos, $chunk - $pos );
		}

		$pos = $chunk;
	}

	if ( $kept === [] ) {
		return null;
	}

	$body = implode( '', $kept );

	return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;
}

/**
 * Returns a GIF without its comments and its application extensions other than the loop count, or null when it is not a
 * whole GIF.
 *
 * Whatever follows the trailer is dropped too.
 *
 * @param string $bytes GIF file.
 * @return string|null GIF file, or null when truncated or out of order.
 */
function strip_gif( string $bytes ): ?string {
	$length = strlen( $bytes );

	if ( $length < 13 || ! in_array( substr( $bytes, 0, 6 ), [ 'GIF87a', 'GIF89a' ], true ) ) {
		return null;
	}

	// The header, the screen descriptor and the global colour table.
	$pos = 13 + gif_color_table_size( ord( $bytes[10] ) );

	if ( $pos > $length ) {
		return null;
	}

	$kept = [ substr( $bytes, 0, $pos ) ];

	while ( $pos < $length ) {
		$block = $bytes[ $pos ];

		if ( $block === ';' ) {
			$kept[] = ';';

			return implode( '', $kept );
		}

		if ( $block === '!' && $pos + 2 <= $length ) {
			// An extension: a label, then data blocks up to an empty one.
			$end = gif_skip_data_blocks( $bytes, $pos + 2 );

			if ( $end === null ) {
				return null;
			}

			if ( ! gif_extension_is_metadata( $bytes, $pos ) ) {
				$kept[] = substr( $bytes, $pos, $end - $pos );
			}
		} elseif ( $block === ',' && $pos + 10 <= $length ) {
			// An image: its descriptor, local colour table, code size, then data blocks.
			$end = gif_skip_data_blocks( $bytes, $pos + 11 + gif_color_table_size( ord( $bytes[ $pos + 9 ] ) ) );

			if ( $end === null ) {
				return null;
			}

			$kept[] = substr( $bytes, $pos, $end - $pos );
		} else {
			return null;
		}

		$pos = $end;
	}

	return null;
}

/**
 * Returns the size of a GIF colour table, in bytes, from the packed flags of the descriptor that holds it.
 *
 * @param int $packed The descriptor's packed byte.
 * @return int Bytes, 0 when there is no table.
 */
function gif_color_table_size( int $packed ): int {
	return ( $packed & 0x80 ) === 0 ? 0 : 3 * ( 2 ** ( ( $packed & 0x07 ) + 1 ) );
}

/**
 * Returns where the data blocks starting at an offset end, or null when they run past the file.
 *
 * Each block is a length byte and that many bytes; an empty one ends them.
 *
 * @param string $bytes GIF file.
 * @param int    $pos   Offset of the first block.
 * @return int|null Offset after the empty block.
 */
function gif_skip_data_blocks( string $bytes, int $pos ): ?int {
	$length = strlen( $bytes );

	while ( $pos < $length ) {
		$size = ord( $bytes[ $pos ] );
		$pos += 1 + $size;

		if ( $size === 0 ) {
			return $pos;
		}
	}

	return null;
}

/**
 * Tells whether the GIF extension at an offset is one to drop: a comment, or an application's other than the loop count.
 *
 * @param string $bytes GIF file.
 * @param int    $pos   Offset of the extension's introducer.
 * @return bool True to drop it.
 */
function gif_extension_is_metadata( string $bytes, int $pos ): bool {
	$label = ord( $bytes[ $pos + 1 ] );

	if ( $label === 0xFE ) {
		return true;
	}

	// The application's first block is 11 bytes: its name and its authentication code.
	return $label === 0xFF
		&& ( ord( $bytes[ $pos + 2 ] ?? "\0" ) !== 11 || ! in_array( substr( $bytes, $pos + 3, 11 ), GIF_KEPT_APPLICATIONS, true ) );
}

/**
 * Maps no image type to another, for strip_metadata()'s save.
 *
 * @return array<string, string> No conversions.
 */
function no_conversion(): array {
	return [];
}

/**
 * Limits image editing to GD, for strip_metadata().
 *
 * @param string[] $editors Editor class names, in order of preference.
 * @return string[] GD's alone.
 */
function gd_only( array $editors ): array {
	return array_values( array_intersect( $editors, [ 'WP_Image_Editor_GD' ] ) );
}

/**
 * Records a stored screenshot as an image attachment of a result or report, with its smaller sizes made.
 *
 * @param array{file: string, url: string, type: string} $upload The stored file.
 * @param WP_Post                                         $result Result or report.
 * @return int|WP_Error Attachment ID.
 */
function attach( array $upload, WP_Post $result ): int|WP_Error {
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => $upload['type'],
			'post_title'     => sanitize_text_field( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => (int) $result->post_author,
		],
		$upload['file'],
		$result->ID,
		true
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
	wp_cache_delete( (string) $result->ID, CACHE_GROUP );

	return $attachment_id;
}

/**
 * Gives a new result a copy of an earlier result's screenshot, for a save that kept it rather than choosing another.
 *
 * A copy of the file, not a second attachment of it, because each result deletes its screenshot's file with it. The
 * caller checks the earlier result is still the step's latest.
 *
 * @param WP_Post $source Earlier result.
 * @param WP_Post $result New result.
 * @return int|WP_Error|null The copy's attachment ID, or null when the earlier result has no screenshot.
 */
function carry_over( WP_Post $source, WP_Post $result ): int|WP_Error|null {
	$screenshot = of( $source );

	if ( $screenshot === null ) {
		return null;
	}

	$path    = (string) get_attached_file( $screenshot->ID );
	$uploads = wp_upload_dir();

	if ( ! is_file( $path ) || $uploads['error'] !== false ) {
		return new WP_Error( 'uat_checklist_screenshot_not_copied', 'The earlier screenshot\'s file could not be read.' );
	}

	$name = wp_unique_filename( $uploads['path'], wp_basename( $path ) );
	$copy = trailingslashit( $uploads['path'] ) . $name;

	if ( ! copy( $path, $copy ) ) {
		return new WP_Error( 'uat_checklist_screenshot_not_copied', 'The earlier screenshot could not be copied.' );
	}

	$attachment_id = attach(
		[
			'file' => $copy,
			'url'  => trailingslashit( $uploads['url'] ) . $name,
			'type' => $screenshot->post_mime_type,
		],
		$result
	);

	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $copy );

		return $attachment_id;
	}

	add_post_meta( $attachment_id, COPIED_FROM, $screenshot->ID, true );

	return $attachment_id;
}

/**
 * Returns the address of a result's screenshot at the panel's preview size, or '' when it has none.
 *
 * @param WP_Post $result Result.
 * @return string URL.
 */
function preview_url( WP_Post $result ): string {
	$screenshot = of( $result );

	return $screenshot === null ? '' : (string) wp_get_attachment_image_url( $screenshot->ID, 'large' );
}

/**
 * Returns the alternative text for a result's screenshot, naming the tester who attached it.
 *
 * @param WP_Post $result Result.
 * @return string Plain text.
 */
function alt_text( WP_Post $result ): string {
	return sprintf(
		/* translators: %s: name of the tester who attached the screenshot. */
		__( 'Screenshot from %s', 'uat-checklist' ),
		get_the_author_meta( 'display_name', (int) $result->post_author )
	);
}

/**
 * Finds the screenshots of many results or reports in one query, so of() then reads each from the request's cache.
 *
 * For a list of them, which would otherwise query once a row. An attachment by anyone but its parent's author is passed
 * over, as of() does, in PHP since the authors differ by parent.
 *
 * @param int[] $result_ids Result or report IDs.
 * @return void
 */
function prime( array $result_ids ): void {
	if ( $result_ids === [] ) {
		return;
	}

	$found = array_fill_keys( $result_ids, 0 );

	// The authors to match, which a list's callers have mostly loaded already.
	_prime_post_caches( $result_ids, false, false );

	// Newest first, as of() takes the newest, so the first one seen for a result is the one kept.
	$query = new WP_Query(
		[
			'post_type'       => 'attachment',
			'post_status'     => 'inherit',
			'post_mime_type'  => 'image',
			'post_parent__in' => $result_ids,
			// Every one: a result with two images would otherwise use another's place, and that one would be cached
			// as having none. Bounded by the results asked for, a page of the list.
			'posts_per_page'  => -1,
			'orderby'         => 'date',
			'order'           => 'DESC',
			'no_found_rows'   => true,
		]
	);

	foreach ( $query->posts as $attachment ) {
		if ( ! $attachment instanceof WP_Post || ( $found[ $attachment->post_parent ] ?? null ) !== 0 ) {
			continue;
		}

		$parent = get_post( $attachment->post_parent );

		if ( $parent instanceof WP_Post && (int) $parent->post_author === (int) $attachment->post_author ) {
			$found[ $attachment->post_parent ] = $attachment->ID;
		}
	}

	foreach ( $found as $result_id => $attachment_id ) {
		wp_cache_set( (string) $result_id, $attachment_id, CACHE_GROUP );
	}
}

/**
 * Returns a result's or report's screenshot, or null when it has none.
 *
 * Only an attachment by the result's own author counts: core lets any user move an attachment of their own under
 * another's post, and that must not replace the screenshot the author sent. attach() gives each the post's author.
 *
 * @param WP_Post $result Result or report.
 * @return WP_Post|null Attachment.
 */
function of( WP_Post $result ): ?WP_Post {
	$cached = wp_cache_get( (string) $result->ID, CACHE_GROUP );

	if ( is_int( $cached ) ) {
		$attachment = $cached === 0 ? null : get_post( $cached );

		return $attachment instanceof WP_Post ? $attachment : null;
	}

	$attachments = get_children(
		[
			'post_parent'    => $result->ID,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'author__in'     => [ (int) $result->post_author ],
			'numberposts'    => 1,
		]
	);
	$attachment  = reset( $attachments );

	return $attachment instanceof WP_Post ? $attachment : null;
}

/**
 * Returns a result's screenshot at the given size, linked to the full image in a new tab, or '' when it has none.
 *
 * @param WP_Post $result Result.
 * @param string  $size   Registered image size, such as `thumbnail` or `medium`.
 * @return string Escaped markup.
 */
function markup( WP_Post $result, string $size ): string {
	$screenshot = of( $result );

	if ( $screenshot === null ) {
		return '';
	}

	$image = wp_get_attachment_image(
		$screenshot->ID,
		$size,
		false,
		[
			'alt'     => alt_text( $result ),
			'loading' => 'lazy',
		]
	);

	if ( $image === '' ) {
		return '';
	}

	return sprintf(
		'<figure class="uat-checklist__figure uat-checklist__screenshot"><a href="%1$s" target="_blank" rel="noopener">%2$s'
			. '<span class="screen-reader-text"> %3$s</span></a></figure>',
		esc_url( (string) wp_get_attachment_url( $screenshot->ID ) ),
		$image,
		esc_html__( '(opens in a new tab)', 'uat-checklist' )
	);
}

/**
 * Deletes a result's or report's screenshots, and their files, when it is deleted for good.
 *
 * Without this, core would keep the attachments and move them to the checklist, leaving files no one can reach
 * from a result or report.
 *
 * @param int     $post_id Post being deleted.
 * @param WP_Post $post    Post being deleted.
 * @return void
 */
function delete_with_result( int $post_id, WP_Post $post ): void {
	if ( ! in_array( $post->post_type, [ Results\POST_TYPE, Reports\POST_TYPE ], true ) ) {
		return;
	}

	$attachment_ids = get_children(
		[
			'post_parent' => $post_id,
			'post_type'   => 'attachment',
			'fields'      => 'ids',
		]
	);

	foreach ( $attachment_ids as $attachment_id ) {
		wp_delete_attachment( (int) $attachment_id, true );
	}

	wp_cache_delete( (string) $post_id, CACHE_GROUP );
}

/**
 * Returns a refusal of the screenshot, which the panel shows in the step's status line.
 *
 * @param string $message What was wrong with the file.
 * @return WP_Error Error with a 400 status.
 */
function refused( string $message ): WP_Error {
	return new WP_Error( 'uat_checklist_screenshot_refused', $message, [ 'status' => 400 ] );
}
