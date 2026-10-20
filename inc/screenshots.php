<?php
/**
 * A tester's screenshot on a result: sent with Save result, stored as an image attachment whose parent is the
 * result, shown beside the result's comment, and deleted with the result.
 *
 * Testers are often Subscribers, who cannot use the Media Library, so the upload is taken here rather than by core's
 * media route, and only by the panel's result route, under its rate limit. Only images are accepted, checked by
 * their content as well as their name.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Screenshots;

use HM\UAT_Checklist\Results;
use WP_Error;
use WP_Post;

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
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'before_delete_post', __NAMESPACE__ . '\\delete_with_result', 10, 2 );
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
	if ( (int) ( $file['size'] ?? 0 ) > wp_max_upload_size() ) {
		return refused(
			sprintf(
				/* translators: %s: the largest file size the site accepts, such as 2 MB. */
				__( 'The screenshot is larger than %s. Choose a smaller image.', 'uat-checklist' ),
				size_format( wp_max_upload_size() )
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

	return isset( $upload['error'] ) ? refused( (string) $upload['error'] ) : $upload;
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

	return $attachment_id;
}

/**
 * Returns a result's screenshot, or null when it has none.
 *
 * @param WP_Post $result Result.
 * @return WP_Post|null Attachment.
 */
function of( WP_Post $result ): ?WP_Post {
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
