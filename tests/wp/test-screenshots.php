<?php
/**
 * A tester's screenshot on a result: a file over the size limit is refused, a stored one is found and shown from its
 * result, and it is deleted with the result and with the result's checklist.
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

(
	static function (): void {
		$oversized         = hm_test_upload( 'big.png', 'image/png', (string) base64_decode( HM_TEST_PNG ) );
		$oversized['size'] = wp_max_upload_size() + 1;
		check(
			'an image larger than the upload limit is refused',
			hm_test_error_code( Screenshots\store( $oversized ) ),
			'uat_checklist_screenshot_refused'
		);
		check(
			'and refused for its size',
			str_contains( Screenshots\store( $oversized )->get_error_message(), size_format( wp_max_upload_size() ) ),
			true
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
