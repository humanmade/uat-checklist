<?php
/**
 * A tester's screenshot on a result: sent with Save result, stored as an image attachment whose parent is the
 * result, shown beside the result's comment, and deleted with the result.
 *
 * Testers are often Subscribers, who cannot use the Media Library, so the upload is taken here rather than by core's
 * media route, and only by the panel's result route, under its rate limit. Only images are accepted, checked by
 * their content as well as their name. Each is capped in size, a tester's uploads are capped per day, and a JPEG's
 * metadata is stripped, since a phone photo carries where it was taken and the file's address is public.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Screenshots;

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
 * Counts the screenshots a user has uploaded to results in the last day.
 *
 * Counted from the attachments themselves, as the rate limit counts results, so nothing extra is stored.
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
			INNER JOIN {$wpdb->posts} AS result ON result.ID = screenshot.post_parent
			WHERE screenshot.post_type = 'attachment' AND screenshot.post_author = %d
			AND screenshot.post_date_gmt >= %s AND result.post_type = %s",
			$user_id,
			gmdate( 'Y-m-d H:i:s', time() - DAY_WINDOW ),
			Results\POST_TYPE
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
 * Moves an uploaded screenshot into the uploads directory, refusing anything but an image within the size limit.
 *
 * Done before the result is saved, so a refused file stores nothing.
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

	if ( $upload['type'] === 'image/jpeg' && is_wp_error( strip_metadata( $upload['file'] ) ) ) {
		wp_delete_file( $upload['file'] );

		return refused( __( 'The screenshot could not be processed. Try a PNG instead.', 'uat-checklist' ) );
	}

	return $upload;
}

/**
 * Rewrites a JPEG in place without its metadata, turned upright first since the turn is part of what is removed.
 *
 * A phone photo's EXIF holds where it was taken, and the file's address opens without logging in. GD is used
 * because it writes no EXIF: Imagick, core's first choice, keeps it when it saves an image at its own size.
 * Refused rather than kept whenever the rewrite cannot be made as it should, so a photo's location is never
 * published by default and a photo is never stored sideways. PNG, GIF and WebP are left as they are: re-encoding
 * would drop a GIF's or WebP's animation, and screenshots in them carry none.
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
 * Records a stored screenshot as an image attachment of a result, with its smaller sizes made.
 *
 * @param array{file: string, url: string, type: string} $upload The stored file.
 * @param WP_Post                                         $result Result.
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
 * Finds the screenshots of many results in one query, so of() then reads each from the request's cache.
 *
 * For a list of results, which would otherwise query once a row.
 *
 * @param int[] $result_ids Result IDs.
 * @return void
 */
function prime( array $result_ids ): void {
	if ( $result_ids === [] ) {
		return;
	}

	$found = array_fill_keys( $result_ids, 0 );
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
		if ( $attachment instanceof WP_Post && ( $found[ $attachment->post_parent ] ?? null ) === 0 ) {
			$found[ $attachment->post_parent ] = $attachment->ID;
		}
	}

	foreach ( $found as $result_id => $attachment_id ) {
		wp_cache_set( (string) $result_id, $attachment_id, CACHE_GROUP );
	}
}

/**
 * Returns a result's screenshot, or null when it has none.
 *
 * @param WP_Post $result Result.
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
			'alt'     => sprintf(
				/* translators: %s: name of the tester who attached the screenshot. */
				__( 'Screenshot from %s', 'uat-checklist' ),
				get_the_author_meta( 'display_name', (int) $result->post_author )
			),
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
 * Deletes a result's screenshots, and their files, when the result is deleted for good.
 *
 * Without this, core would keep the attachments and move them to the checklist, leaving files no one can reach
 * from a result.
 *
 * @param int     $post_id Post being deleted.
 * @param WP_Post $post    Post being deleted.
 * @return void
 */
function delete_with_result( int $post_id, WP_Post $post ): void {
	if ( $post->post_type !== Results\POST_TYPE ) {
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
