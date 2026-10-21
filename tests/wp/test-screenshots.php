<?php
/**
 * A tester's screenshot on a result: a file over the size limit is refused, a JPEG loses its metadata, a tester's
 * uploads are capped per day, a stored one is found and shown from its result, alone or primed for a list, and it is
 * deleted with the result and with the result's checklist.
 *
 * Whether a file is an image is checked in tests/e2e/screenshots.spec.js instead: wp_handle_upload() refuses any file
 * that did not arrive in an HTTP POST before it looks at the type, so only a real upload reaches that check.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Screenshots;

require_once ABSPATH . 'wp-admin/includes/file.php';

echo "\nScreenshots\n";

// A 1×1 PNG.
const HM_TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

/**
 * Returns an upload, as PHP describes one, of a temporary file holding the given bytes.
 *
 * @param string $name  File name the browser sent.
 * @param string $type  Media type the browser claimed.
 * @param string $bytes File contents.
 * @return array<string, mixed> Upload.
 */
function hm_test_upload( string $name, string $type, string $bytes ): array {
	$path = wp_tempnam( $name );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture in the temp directory.
	file_put_contents( $path, $bytes );

	return [
		'name'     => $name,
		'type'     => $type,
		'tmp_name' => $path,
		'error'    => UPLOAD_ERR_OK,
		'size'     => strlen( $bytes ),
	];
}

/**
 * Returns a PNG written into the uploads directory, as Screenshots\store() returns a stored file.
 *
 * @return array{file: string, url: string, type: string} The stored file.
 */
function hm_test_stored_png(): array {
	$stored = wp_upload_bits( 'uat-test-shot.png', null, (string) base64_decode( HM_TEST_PNG ) );

	return [
		'file' => $stored['file'],
		'url'  => $stored['url'],
		'type' => 'image/png',
	];
}

/**
 * Returns a 4×2 JPEG whose EXIF says to turn it a quarter clockwise, written into the uploads directory.
 *
 * GD writes no EXIF, so the block is spliced in after the start-of-image marker: a little-endian TIFF header and one
 * IFD entry, Orientation (0x0112) = 6.
 *
 * @return string Path.
 */
function hm_test_jpeg_with_exif(): string {
	$image = imagecreatetruecolor( 4, 2 );
	ob_start();
	imagejpeg( $image );
	$jpeg = (string) ob_get_clean();

	$tiff    = "II\x2A\x00" . pack( 'V', 8 ) . pack( 'v', 1 ) . pack( 'vvVv', 0x0112, 3, 1, 6 ) . "\x00\x00" . pack( 'V', 0 );
	$payload = "Exif\x00\x00" . $tiff;
	$segment = "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	$stored  = wp_upload_bits( 'uat-test-photo.jpg', null, substr( $jpeg, 0, 2 ) . $segment . substr( $jpeg, 2 ) );

	return $stored['file'];
}

(
	static function (): void {
		check(
			'a screenshot can be up to 20 MB, or the site\'s upload limit when that is lower',
			Screenshots\max_bytes(),
			min( 20 * MB_IN_BYTES, wp_max_upload_size() )
		);

		$small = static fn (): int => 100;
		hm_test_with_filter(
			'hm_uat_checklist_screenshot_max_bytes',
			$small,
			static function (): void {
				$over         = hm_test_upload( 'big.png', 'image/png', (string) base64_decode( HM_TEST_PNG ) );
				$over['size'] = 101;
				$refusal      = Screenshots\store( $over );
				check(
					'an image larger than the filtered limit is refused',
					hm_test_error_code( $refusal ),
					'uat_checklist_screenshot_refused'
				);
				check(
					'and refused for its size',
					is_wp_error( $refusal ) && str_contains( $refusal->get_error_message(), size_format( 100 ) ),
					true
				);
			}
		);

		$photo = hm_test_jpeg_with_exif();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture.
		$before = (string) file_get_contents( $photo );
		$strip  = Screenshots\strip_metadata( $photo );
		clearstatcache();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture.
		$after = (string) file_get_contents( $photo );
		$size  = getimagesize( $photo );
		check( 'the fixture carries EXIF', str_contains( $before, "Exif\x00\x00" ), true );
		check( 'a JPEG is rewritten without its EXIF', [ $strip, str_contains( $after, "Exif\x00\x00" ) ], [ true, false ] );
		check( 'and turned upright first, as its EXIF said', $size ? [ $size[0], $size[1] ] : $size, [ 2, 4 ] );
		wp_delete_file( $photo );

		// What Performance Lab's Modern Image Formats does. Core applies it even to a save that names its type.
		$converted = hm_test_with_filter(
			'image_editor_output_format',
			static fn (): array => [ 'image/jpeg' => 'image/webp' ],
			static function (): array {
				$photo = hm_test_jpeg_with_exif();
				$strip = Screenshots\strip_metadata( $photo );
				clearstatcache();
				$webp = preg_replace( '/\.jpg$/', '.webp', $photo );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture.
				$kept = str_contains( (string) file_get_contents( $photo ), "Exif\x00\x00" );
				$made = file_exists( $webp );
				wp_delete_file( $photo );
				wp_delete_file( $webp );

				return [ $strip, $kept, $made ];
			}
		);
		check(
			'a site converting JPEGs to WebP still has the JPEG itself rewritten without EXIF, and no WebP left',
			$converted,
			[ true, false, false ]
		);

		check(
			'no file chosen is no screenshot, not an error',
			Screenshots\chosen_file( [ Screenshots\FIELD => [ 'error' => UPLOAD_ERR_NO_FILE ] ] ),
			null
		);

		$step_id = wp_generate_uuid4();

		hm_test_with_page(
			[
				'post_type'    => Checklists\POST_TYPE,
				'post_title'   => 'Screenshot test checklist',
				'post_content' => hm_test_checklist_markup( $step_id ),
			],
			static function ( int $checklist_id ) use ( $step_id ): void {
				hm_test_as_user(
					'subscriber',
					static function ( int $tester ) use ( $checklist_id, $step_id ): void {
						$kept    = Results\save( $checklist_id, $tester, $step_id, 'Add the block', 'fail', 'Broken.' );
						$deleted = Results\save( $checklist_id, $tester, $step_id, 'Add the block', 'fail', 'Again.' );
						$plain   = Results\save( $checklist_id, $tester, $step_id, 'Add the block', 'pass', '' );

						foreach ( [ $kept, $deleted ] as $result ) {
							Screenshots\attach( hm_test_stored_png(), $result );
						}

						check( 'a tester\'s screenshots today are counted', Screenshots\uploaded_today( $tester ), 2 );

						global $wpdb;
						$older = Screenshots\of( $deleted );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- backdates a test fixture.
						$wpdb->update(
							$wpdb->posts,
							[ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ],
							[ 'ID' => $older->ID ?? 0 ]
						);
						check( 'one from more than a day ago is not', Screenshots\uploaded_today( $tester ), 1 );

						$refused = hm_test_with_filter(
							'hm_uat_checklist_screenshot_daily_limit',
							static fn (): int => 1,
							static function () use ( $checklist_id, $step_id ): WP_REST_Response {
								$request = new WP_REST_Request( 'POST', '/uat-checklist/v1/results' );
								$request->set_param( 'checklist_id', $checklist_id );
								$request->set_param( 'step_id', $step_id );
								$request->set_param( 'status', 'pass' );
								$request->set_param( 'comment', '' );
								// Never read: the limit refuses the request before the file is looked at.
								$request->set_file_params( [ Screenshots\FIELD => hm_test_upload( 'a.png', 'image/png', 'x' ) ] );

								return rest_get_server()->dispatch( $request );
							}
						);
						check(
							'a tester at the day\'s limit is refused another screenshot',
							[ $refused->get_status(), $refused->as_error()?->get_error_code() ],
							[ 429, 'hm_uat_checklist_screenshot_limited' ]
						);

						Screenshots\prime( [ $kept->ID, $plain->ID ] );
						$queries = $wpdb->num_queries;
						$primed  = [ Screenshots\of( $kept )?->ID, Screenshots\of( $plain ) ];
						check(
							'primed results find their screenshots with no further query',
							[ $primed, $wpdb->num_queries - $queries ],
							[ [ Screenshots\of( $kept )?->ID, null ], 0 ]
						);

						// A result with two images must not crowd out another listed result's older one.
						$crowded = Results\save( $checklist_id, $tester, $step_id, 'Add the block', 'fail', 'Two shots.' );
						$older   = Results\save( $checklist_id, $tester, $step_id, 'Add the block', 'fail', 'One shot.' );
						Screenshots\attach( hm_test_stored_png(), $older );
						$hour_ago = time() - HOUR_IN_SECONDS;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- backdates a test fixture.
						$wpdb->update(
							$wpdb->posts,
							[
								'post_date'     => wp_date( 'Y-m-d H:i:s', $hour_ago ),
								'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $hour_ago ),
							],
							[ 'ID' => Screenshots\of( $older )?->ID ?? 0 ]
						);
						clean_post_cache( Screenshots\of( $older )?->ID ?? 0 );
						Screenshots\attach( hm_test_stored_png(), $crowded );
						Screenshots\attach( hm_test_stored_png(), $crowded );
						Screenshots\prime( [ $crowded->ID, $older->ID ] );
						check(
							'a primed result is found even when another listed one has two images',
							Screenshots\of( $older ) instanceof WP_Post,
							true
						);

						$late = Results\save( $checklist_id, $tester, $step_id, 'Add the block', 'fail', 'Later.' );
						Screenshots\prime( [ $late->ID ] );
						Screenshots\attach( hm_test_stored_png(), $late );
						check( 'a screenshot attached after priming is still found', Screenshots\of( $late ) instanceof WP_Post, true );

						$screenshot = Screenshots\of( $kept );
						check( 'a stored screenshot is found from its result', $screenshot instanceof WP_Post, true );
						check( 'it is an image', $screenshot ? $screenshot->post_mime_type : '', 'image/png' );
						check( 'it belongs to the tester', $screenshot ? (int) $screenshot->post_author : 0, $tester );
						check(
							'it is shown linked to the full image',
							str_contains( Screenshots\markup( $kept, 'medium' ), (string) wp_get_attachment_url( $screenshot->ID ?? 0 ) ),
							true
						);
						check( 'a result without one shows nothing', Screenshots\markup( $plain, 'medium' ), '' );

						$gone = Screenshots\of( $deleted );
						$file = $gone ? (string) get_attached_file( $gone->ID ) : '';
						wp_delete_post( $deleted->ID, true );
						check( 'deleting a result for good deletes its screenshot', get_post( $gone->ID ?? 0 ), null );
						check( 'and its file', $file !== '' && ! file_exists( $file ), true );

						$kept_id = $screenshot->ID ?? 0;
						wp_delete_post( $checklist_id, true );
						check( 'deleting the checklist for good deletes its results\' screenshots', get_post( $kept_id ), null );
					}
				);
			}
		);
	}
)();
