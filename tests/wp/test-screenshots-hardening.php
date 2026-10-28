<?php
/**
 * What keeps a screenshot trustworthy: only the result's own author's image is shown, whoever moves another image
 * under it; an image too large to decode is refused before it is stored; and PNG, GIF and WebP files lose their
 * metadata without being re-encoded.
 *
 * The strip functions are tested on bytes. Two checks send a file over HTTP (`http://wordpress/`, the web container),
 * because wp_handle_upload() refuses any file PHP did not receive in a POST: they show store() runs the strip.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Screenshots;

echo "\nScreenshots: hardening\n";

/**
 * Returns a PNG chunk: length, type, data and CRC.
 *
 * @param string $type Four-letter type.
 * @param string $data Chunk data.
 * @return string Chunk.
 */
function hm_test_shots_png_chunk( string $type, string $data ): string {
	return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
}

/**
 * Returns the data of the PNG's pixels, as a PNG stores it.
 *
 * @return string zlib stream of a 2×1 RGB image.
 */
function hm_test_shots_png_pixels(): string {
	return (string) gzcompress( "\x00" . "\x10\x20\x30\x40\x50\x60" );
}

/**
 * Returns a 2×1 PNG, with metadata chunks and a trailer or without them.
 *
 * @param bool $dirty True to add eXIf, tEXt, iTXt, zTXt and tIME chunks and bytes after the end.
 * @return string PNG file.
 */
function hm_test_shots_png( bool $dirty ): string {
	$png  = "\x89PNG\r\n\x1A\n" . hm_test_shots_png_chunk( 'IHDR', pack( 'NNCCCCC', 2, 1, 8, 2, 0, 0, 0 ) );
	$png .= hm_test_shots_png_chunk( 'gAMA', pack( 'N', 45455 ) );

	if ( $dirty ) {
		$png .= hm_test_shots_png_chunk( 'tEXt', "Comment\x00secret-text-note" );
		$png .= hm_test_shots_png_chunk( 'eXIf', "MM\x00*\x00\x00\x00\x08secret-gps" );
	}

	$png .= hm_test_shots_png_chunk( 'pHYs', pack( 'NNC', 2835, 2835, 1 ) );

	if ( $dirty ) {
		$png .= hm_test_shots_png_chunk( 'tIME', pack( 'nCCCCC', 2026, 1, 2, 3, 4, 5 ) );
	}

	$png .= hm_test_shots_png_chunk( 'IDAT', hm_test_shots_png_pixels() );

	if ( $dirty ) {
		$png .= hm_test_shots_png_chunk( 'iTXt', "XML:com.adobe.xmp\x00\x00\x00\x00\x00<secret-xmp/>" );
		$png .= hm_test_shots_png_chunk( 'zTXt', "Comment\x00\x00" . gzcompress( 'secret-ztxt' ) );
	}

	$png .= hm_test_shots_png_chunk( 'IEND', '' );

	return $dirty ? $png . 'secret-trailer' : $png;
}

/**
 * Returns a RIFF chunk: type, little-endian length, data and a pad byte when the length is odd.
 *
 * @param string $type Four-character type.
 * @param string $data Chunk data.
 * @return string Chunk.
 */
function hm_test_shots_riff_chunk( string $type, string $data ): string {
	return $type . pack( 'V', strlen( $data ) ) . $data . ( strlen( $data ) % 2 === 1 ? "\x00" : '' );
}

/**
 * Returns a 4×2 extended WebP, with EXIF and XMP chunks and their flags set, or without them.
 *
 * @param bool $dirty True to add the two chunks and set their flags in the VP8X header.
 * @return string WebP file.
 */
function hm_test_shots_webp( bool $dirty ): string {
	ob_start();
	imagewebp( imagecreatetruecolor( 4, 2 ) );
	$image = substr( (string) ob_get_clean(), 12 );
	// Alpha (0x10) is not metadata and must stay; EXIF is 0x08 and XMP 0x04.
	$flags = 0x10 | ( $dirty ? 0x0C : 0 );
	$body  = hm_test_shots_riff_chunk( 'VP8X', chr( $flags ) . "\x00\x00\x00" . "\x03\x00\x00" . "\x01\x00\x00" ) . $image;

	if ( $dirty ) {
		$body .= hm_test_shots_riff_chunk( 'EXIF', 'secret-exif-gps' );
		$body .= hm_test_shots_riff_chunk( 'XMP ', 'secret-xmp' );
	}

	return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;
}

/**
 * Returns a two-frame GIF with a loop count, with a comment and another application's extension, or without them.
 *
 * @param bool $dirty True to add a comment extension, an XMP application extension and bytes after the trailer.
 * @return string GIF file.
 */
function hm_test_shots_gif( bool $dirty ): string {
	$frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00" . "\x02\x02\x44\x01\x00";
	$loop  = "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01\x00\x00\x00";
	$gif   = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00" . $loop;

	if ( $dirty ) {
		$gif .= "\x21\xFE\x0Esecret-comment\x00";
		$gif .= "\x21\xFF\x0B" . "XMP DataXMP\x0Asecret-xmp\x00";
	}

	return $gif . $frame . $frame . ';' . ( $dirty ? 'secret-trailer' : '' );
}

/**
 * Returns a PNG whose header claims other dimensions than the 1×1 it holds, which getimagesize() reads without decoding.
 *
 * @param int $width  Declared width.
 * @param int $height Declared height.
 * @return string PNG file.
 */
function hm_test_shots_claiming_png( int $width, int $height ): string {
	return "\x89PNG\r\n\x1A\n"
		. hm_test_shots_png_chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) )
		. hm_test_shots_png_chunk( 'IDAT', (string) gzcompress( "\x00\x00\x00\x00" ) )
		. hm_test_shots_png_chunk( 'IEND', '' );
}

/**
 * Returns a JPEG whose start-of-frame header claims other dimensions than the 1×1 it holds.
 *
 * @param int $width  Declared width.
 * @param int $height Declared height.
 * @return string JPEG file.
 */
function hm_test_shots_claiming_jpeg( int $width, int $height ): string {
	ob_start();
	imagejpeg( imagecreatetruecolor( 1, 1 ) );
	$jpeg = (string) ob_get_clean();
	$sof  = (int) strpos( $jpeg, "\xFF\xC0" );

	// Marker (2), length (2), precision (1), then height and width.
	return substr( $jpeg, 0, $sof + 5 ) . pack( 'nn', $height, $width ) . substr( $jpeg, $sof + 9 );
}

/**
 * Returns an upload, as PHP describes one, of a temporary file holding the given bytes.
 *
 * @param string $name  File name the browser sent.
 * @param string $type  Media type the browser claimed.
 * @param string $bytes File contents.
 * @return array<string, mixed> Upload.
 */
function hm_test_shots_upload( string $name, string $type, string $bytes ): array {
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
 * Runs check_dimensions() on bytes written to a temporary file.
 *
 * @param string $bytes File contents.
 * @return true|WP_Error What it returned.
 */
function hm_test_shots_dimensions( string $bytes ): true|WP_Error {
	$upload = hm_test_shots_upload( 'dimensions.bin', 'application/octet-stream', $bytes );

	try {
		return Screenshots\check_dimensions( $upload['tmp_name'] );
	} finally {
		wp_delete_file( $upload['tmp_name'] );
	}
}

/**
 * Runs strip_container_metadata() on bytes written to a temporary file.
 *
 * @param string $bytes File contents.
 * @param string $type  Media type.
 * @return array{0: true|WP_Error, 1: string} What it returned and the file afterwards.
 */
function hm_test_shots_strip_file( string $bytes, string $type ): array {
	$upload = hm_test_shots_upload( 'strip.bin', $type, $bytes );

	try {
		$result = Screenshots\strip_container_metadata( $upload['tmp_name'], $type );
		clearstatcache();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture.
		return [ $result, (string) file_get_contents( $upload['tmp_name'] ) ];
	} finally {
		wp_delete_file( $upload['tmp_name'] );
	}
}

/**
 * Returns a PNG's chunk types in order, or null when a length runs past the file or a CRC is wrong.
 *
 * @param string $png PNG file.
 * @return string[]|null Types.
 */
function hm_test_shots_png_types( string $png ): ?array {
	$types = [];
	$pos   = 8;

	while ( $pos + 12 <= strlen( $png ) ) {
		$size = (int) unpack( 'N', $png, $pos )[1];
		$type = substr( $png, $pos + 4, 4 );

		if ( $pos + 12 + $size > strlen( $png ) || substr( $png, $pos + 8 + $size, 4 ) !== pack( 'N', crc32( $type . substr( $png, $pos + 8, $size ) ) ) ) {
			return null;
		}

		$types[] = $type;
		$pos    += 12 + $size;
	}

	return $pos === strlen( $png ) ? $types : null;
}

/**
 * Tells whether a strip function refuses every truncation of a file, and survives damaged copies of another.
 *
 * Warnings and notices count as failures: an out-of-range read is one. A string it returns must strip to itself.
 *
 * @param callable $strip Strip function.
 * @param string   $whole A whole file with nothing after its end.
 * @param string   $dirty A whole file with metadata.
 * @return string[] What went wrong, or an empty list.
 */
function hm_test_shots_robustness( callable $strip, string $whole, string $dirty ): array {
	$problems = [];

	set_error_handler(
		static function ( int $code, string $message ): bool {
			throw new ErrorException( $message, 0, $code );
		}
	);

	try {
		for ( $length = 0; $length < strlen( $whole ); $length++ ) {
			if ( $strip( substr( $whole, 0, $length ) ) !== null ) {
				$problems[] = "prefix of {$length} bytes was accepted";
			}
		}

		mt_srand( 20260 );

		for ( $round = 0; $round < 600; $round++ ) {
			$bytes = $dirty;

			for ( $hit = mt_rand( 1, 4 ); $hit > 0; $hit-- ) {
				$bytes[ mt_rand( 0, strlen( $bytes ) - 1 ) ] = chr( mt_rand( 0, 255 ) );
			}

			if ( mt_rand( 0, 3 ) === 0 ) {
				$bytes = substr( $bytes, 0, mt_rand( 0, strlen( $bytes ) ) );
			}

			$once = $strip( $bytes );

			if ( $once !== null && $strip( $once ) !== $once ) {
				$problems[] = "round {$round} did not strip to itself";
			}
		}
	} catch ( Throwable $e ) {
		$problems[] = get_class( $e ) . ': ' . $e->getMessage();
	} finally {
		restore_error_handler();
	}

	return $problems;
}

/**
 * Writes a PNG into the uploads directory, as Screenshots\store() returns a stored file.
 *
 * @return array{file: string, url: string, type: string} The stored file.
 */
function hm_test_shots_stored_png(): array {
	$stored = wp_upload_bits( 'uat-test-hardening.png', null, hm_test_shots_png( false ) );

	return [
		'file' => $stored['file'],
		'url'  => $stored['url'],
		'type' => 'image/png',
	];
}

/**
 * Sends a result with a file to the site over HTTP as a user, as a browser would.
 *
 * @param int    $user_id      User sending it, who is the current user.
 * @param int    $checklist_id Checklist.
 * @param string $step_id      Step.
 * @param string $name         File name the browser sent.
 * @param string $type         Media type the browser claimed.
 * @param string $bytes        File contents.
 * @return array{0: int, 1: array<string, mixed>} The HTTP status and the decoded body.
 */
function hm_test_shots_post_result( int $user_id, int $checklist_id, string $step_id, string $name, string $type, string $bytes ): array {
	$expiration = time() + HOUR_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	$cookie     = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );

	// wp_create_nonce() reads the session token from the cookie.
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;

	try {
		$nonce = wp_create_nonce( 'wp_rest' );
	} finally {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	}

	$boundary = wp_generate_password( 24, false );
	$body     = '';

	foreach (
		[
			'checklist_id' => (string) $checklist_id,
			'step_id'      => $step_id,
			'status'       => 'fail',
			'comment'      => 'It shows.',
		] as $field => $value
	) {
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"\r\n\r\n{$value}\r\n";
	}

	$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . Screenshots\FIELD . "\"; filename=\"{$name}\"\r\n"
		. "Content-Type: {$type}\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";

	$response = wp_remote_post(
		'http://wordpress/?rest_route=/uat-checklist/v1/results',
		[
			'timeout' => 30,
			'headers' => [
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				'Cookie'       => LOGGED_IN_COOKIE . '=' . $cookie,
				'X-WP-Nonce'   => $nonce,
			],
			'body'    => $body,
		]
	);

	if ( is_wp_error( $response ) ) {
		return [ 0, [ 'message' => $response->get_error_message() ] ];
	}

	return [
		(int) wp_remote_retrieve_response_code( $response ),
		(array) json_decode( wp_remote_retrieve_body( $response ), true ),
	];
}

/**
 * Backdates an attachment, so another is newer than it.
 *
 * @param int $attachment_id Attachment.
 * @param int $seconds       How long ago.
 * @return void
 */
function hm_test_shots_backdate( int $attachment_id, int $seconds ): void {
	global $wpdb;

	$then = time() - $seconds;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- backdates a test fixture.
	$wpdb->update(
		$wpdb->posts,
		[
			'post_date'     => wp_date( 'Y-m-d H:i:s', $then ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $then ),
		],
		[ 'ID' => $attachment_id ]
	);
	clean_post_cache( $attachment_id );
}

echo "\nA planted screenshot\n";

(
	static function (): void {
		$step_id = wp_generate_uuid4();

		hm_test_with_page(
			[
				'post_type'    => Checklists\POST_TYPE,
				'post_title'   => 'Planted screenshot checklist',
				'post_content' => hm_test_checklist_markup( $step_id ),
			],
			static function ( int $checklist_id ) use ( $step_id ): void {
				hm_test_as_user(
					'subscriber',
					static function ( int $owner ) use ( $checklist_id, $step_id ): void {
						$planted_ids = [];

						try {
							hm_test_as_user(
								'author',
								static function ( int $planter ) use ( $checklist_id, $step_id, $owner, &$planted_ids ): void {
									// Each is the owner's; the planter then moves one of their own attachments under it.
									$real_result   = Results\save( $checklist_id, $owner, $step_id, 'Add the block', 'fail', 'Mine.' );
									$bare_result   = Results\save( $checklist_id, $owner, $step_id, 'Add the block', 'fail', 'No shot.' );
									$second_result = Results\save( $checklist_id, $owner, $step_id, 'Add the block', 'fail', 'Both.' );

									$real = Screenshots\attach( hm_test_shots_stored_png(), $real_result );
									$kept = Screenshots\attach( hm_test_shots_stored_png(), $second_result );
									hm_test_shots_backdate( (int) $real, HOUR_IN_SECONDS );
									hm_test_shots_backdate( (int) $kept, HOUR_IN_SECONDS );

									$plant = static function ( WP_Post $result ) use ( $planter, &$planted_ids ): int {
										$file = wp_upload_bits( 'uat-test-planted.png', null, hm_test_shots_png( false ) );
										$id   = (int) wp_insert_attachment(
											[
												'post_mime_type' => 'image/png',
												'post_title'     => 'planted',
												'post_status'    => 'inherit',
												'post_author'    => $planter,
											],
											$file['file'],
											0
										);
										$planted_ids[] = $id;

										// As core's media route does for a user who may edit their own attachment.
										hm_test_rest( 'POST', "/wp/v2/media/{$id}", [ 'post' => $result->ID ] );
										clean_post_cache( $id );

										return $id;
									};

									$planted_over_real = $plant( $real_result );
									$planted_alone     = $plant( $bare_result );
									$planted_second    = $plant( $second_result );

									check(
										'the planter\'s attachments are under the results, as core\'s media route allows',
										array_map(
											static fn ( int $id ): int => (int) get_post( $id )->post_parent,
											[ $planted_over_real, $planted_alone, $planted_second ]
										),
										[ $real_result->ID, $bare_result->ID, $second_result->ID ]
									);
									check(
										'and newer than the real one',
										strtotime( get_post( $planted_over_real )->post_date_gmt ) > strtotime( get_post( (int) $real )->post_date_gmt ),
										true
									);

									check(
										'a result still shows its own author\'s screenshot, though a newer image was moved under it',
										Screenshots\of( $real_result )?->ID,
										$real
									);
									check(
										'a result with only another user\'s image under it has no screenshot',
										Screenshots\of( $bare_result ),
										null
									);
									check(
										'a result\'s own screenshot is the one shown when both are there',
										Screenshots\of( $second_result )?->ID,
										$kept
									);
									check(
										'and the planted image is not what is rendered',
										str_contains( Screenshots\markup( $real_result, 'medium' ), 'uat-test-planted' ),
										false
									);
									check( 'a result with only the planted image renders nothing', Screenshots\markup( $bare_result, 'medium' ), '' );

									foreach ( [ $real_result, $bare_result, $second_result ] as $result ) {
										wp_cache_delete( (string) $result->ID, Screenshots\CACHE_GROUP );
									}

									global $wpdb;
									Screenshots\prime( [ $real_result->ID, $bare_result->ID, $second_result->ID ] );
									$queries = $wpdb->num_queries;
									$primed  = [
										Screenshots\of( $real_result )?->ID,
										Screenshots\of( $bare_result )?->ID,
										Screenshots\of( $second_result )?->ID,
									];
									check(
										'primed in bulk, the same results are found as one at a time, with no further query',
										[ $primed, $wpdb->num_queries - $queries ],
										[ [ $real, null, $kept ], 0 ]
									);

									// A result with nothing but a planted image is cached as having none, not as unprimed.
									check(
										'and one with only the planted image is cached as having none',
										wp_cache_get( (string) $bare_result->ID, Screenshots\CACHE_GROUP ),
										0
									);

									// The copy is attached under the new result's author, which may not be the source's.
									wp_cache_delete( (string) $real_result->ID, Screenshots\CACHE_GROUP );
									wp_set_current_user( $planter );
									$own   = Results\save( $checklist_id, $planter, $step_id, 'Add the block', 'fail', 'Mine too.' );
									$copy  = Screenshots\carry_over( $real_result, $own );
									$found = Screenshots\of( $own );
									check(
										'a screenshot carried over to another tester\'s result is shown, as theirs',
										[ $found?->ID, $copy, $found ? (int) $found->post_author : 0 ],
										[ $copy, $copy, $planter ]
									);
									wp_set_current_user( $owner );

									$own_copy = Screenshots\carry_over( $second_result, Results\save( $checklist_id, $owner, $step_id, 'Add the block', 'fail', 'Again.' ) );
									check( 'and a carry-over to the same author\'s result is shown too', is_int( $own_copy ), true );
									check(
										'the source still shows its own screenshot after being copied',
										Screenshots\of( $second_result )?->ID,
										$kept
									);
									check(
										'carrying over from a result whose only image was planted copies nothing',
										Screenshots\carry_over( $bare_result, $own ),
										null
									);
								}
							);
						} finally {
							foreach ( $planted_ids as $id ) {
								wp_delete_attachment( $id, true );
							}
						}
					}
				);
			}
		);
	}
)();

echo "\nImage dimensions\n";

(
	static function (): void {
		$tiny = hm_test_shots_png( false );

		check( 'a real image within the limits is accepted', hm_test_shots_dimensions( $tiny ), true );
		check( 'a 10000 pixel side is accepted', hm_test_error_code( hm_test_shots_dimensions( hm_test_shots_claiming_png( 10000, 1 ) ) ), null );

		$wide = hm_test_shots_dimensions( hm_test_shots_claiming_png( 10001, 1 ) );
		check( 'a PNG claiming a side over 10000 pixels is refused', hm_test_error_code( $wide ), 'uat_checklist_screenshot_refused' );
		check( 'with a 400 status', is_wp_error( $wide ) ? $wide->get_error_data()['status'] ?? 0 : 0, 400 );
		check(
			'and the limits named',
			is_wp_error( $wide ) && str_contains( $wide->get_error_message(), number_format_i18n( 10000 ) ),
			true
		);

		check(
			'a PNG claiming 40 megapixels in all is accepted',
			hm_test_error_code( hm_test_shots_dimensions( hm_test_shots_claiming_png( 8000, 5000 ) ) ),
			null
		);
		check(
			'a JPEG claiming 81 megapixels, each side within the limit, is refused',
			hm_test_error_code( hm_test_shots_dimensions( hm_test_shots_claiming_jpeg( 9000, 9000 ) ) ),
			'uat_checklist_screenshot_refused'
		);
		check(
			'a JPEG claiming a side of 60000 pixels is refused',
			hm_test_error_code( hm_test_shots_dimensions( hm_test_shots_claiming_jpeg( 60000, 1 ) ) ),
			'uat_checklist_screenshot_refused'
		);

		check( 'the side limit is 10000 pixels', Screenshots\max_dimension(), 10000 );
		check( 'and the total 50 million', Screenshots\max_pixels(), 50000000 );

		hm_test_with_filter(
			'hm_uat_checklist_screenshot_max_dimension',
			static fn (): int => 1,
			static function () use ( $tiny ): void {
				check( 'the side limit can be filtered', Screenshots\max_dimension(), 1 );
				check( 'a 2×1 image is refused over a filtered side limit of 1', hm_test_error_code( hm_test_shots_dimensions( $tiny ) ), 'uat_checklist_screenshot_refused' );
				check(
					'and a 1×1 image is not',
					hm_test_error_code( hm_test_shots_dimensions( hm_test_shots_claiming_png( 1, 1 ) ) ),
					null
				);
			}
		);

		hm_test_with_filter(
			'hm_uat_checklist_screenshot_max_pixels',
			static fn (): int => 1,
			static function () use ( $tiny ): void {
				check( 'the total can be filtered', Screenshots\max_pixels(), 1 );
				check( 'a 2×1 image is refused over a filtered total of 1 pixel', hm_test_error_code( hm_test_shots_dimensions( $tiny ) ), 'uat_checklist_screenshot_refused' );
			}
		);

		foreach (
			[
				'text'               => 'not an image',
				'nothing'            => '',
				'a PNG signature'    => "\x89PNG\r\n\x1A\n",
				'a truncated header' => substr( $tiny, 0, 20 ),
				'zero dimensions'    => hm_test_shots_claiming_png( 0, 0 ),
			] as $label => $bytes
		) {
			$refusal = hm_test_shots_dimensions( $bytes );
			check(
				"a file of {$label} is refused as unreadable",
				[ hm_test_error_code( $refusal ), is_wp_error( $refusal ) ? $refusal->get_error_data()['status'] ?? 0 : 0 ],
				[ 'uat_checklist_screenshot_refused', 400 ]
			);
		}

		$missing = Screenshots\check_dimensions( wp_tempnam( 'missing' ) . '-gone' );
		check( 'a file that is not there is refused', hm_test_error_code( $missing ), 'uat_checklist_screenshot_refused' );

		$bomb    = hm_test_shots_upload( 'uat-test-bomb.png', 'image/png', hm_test_shots_claiming_png( 60000, 60000 ) );
		$refused = Screenshots\store( $bomb );
		$moved   = glob( wp_upload_dir()['path'] . '/uat-test-bomb*' );
		check(
			'store() refuses an image claiming 3.6 gigapixels for its size, not for its type',
			[ hm_test_error_code( $refused ), is_wp_error( $refused ) && str_contains( $refused->get_error_message(), 'pixels' ) ],
			[ 'uat_checklist_screenshot_refused', true ]
		);
		check( 'and moves nothing into the uploads directory', $moved, [] );
		wp_delete_file( $bomb['tmp_name'] );

		$text    = hm_test_shots_upload( 'uat-test-text.png', 'image/png', 'not an image' );
		$refused = Screenshots\store( $text );
		check(
			'store() refuses a file with no readable size before it is moved',
			[ hm_test_error_code( $refused ), is_wp_error( $refused ) && str_contains( $refused->get_error_message(), 'not an image' ) ],
			[ 'uat_checklist_screenshot_refused', true ]
		);
		wp_delete_file( $text['tmp_name'] );
	}
)();

echo "\nMetadata in PNG, GIF and WebP\n";

(
	static function (): void {
		$secrets = [ 'secret-text-note', 'secret-gps', 'secret-xmp', 'secret-trailer', 'eXIf', 'tEXt', 'iTXt', 'zTXt', 'tIME' ];
		$dirty   = hm_test_shots_png( true );
		$clean   = hm_test_shots_png( false );
		$out     = Screenshots\strip_png( $dirty );

		check( 'the PNG fixture carries every kind of metadata', array_map( static fn ( string $s ): bool => str_contains( $dirty, $s ), $secrets ), array_fill( 0, count( $secrets ), true ) );
		check( 'a PNG loses its text, EXIF and time chunks and what follows its end', $out, $clean );
		check(
			'and keeps its gamma, resolution and pixels, each with a valid CRC',
			hm_test_shots_png_types( (string) $out ),
			[ 'IHDR', 'gAMA', 'pHYs', 'IDAT', 'IEND' ]
		);
		check( 'its pixel data is the same bytes', str_contains( (string) $out, hm_test_shots_png_pixels() ), true );

		$size = getimagesizefromstring( (string) $out );
		check( 'it still reads as a 2×1 PNG', $size ? [ $size[0], $size[1], $size['mime'] ] : $size, [ 2, 1, 'image/png' ] );
		check( 'and decodes', imagecreatefromstring( (string) $out ) instanceof GdImage, true );
		check( 'a PNG with nothing to drop is returned as it was', Screenshots\strip_png( $clean ), $clean );

		$animated = "\x89PNG\r\n\x1A\n" . hm_test_shots_png_chunk( 'IHDR', pack( 'NNCCCCC', 2, 1, 8, 2, 0, 0, 0 ) )
			. hm_test_shots_png_chunk( 'acTL', pack( 'NN', 1, 0 ) )
			. hm_test_shots_png_chunk( 'tEXt', "Comment\x00secret-text-note" )
			. hm_test_shots_png_chunk( 'fcTL', str_repeat( "\x00", 26 ) )
			. hm_test_shots_png_chunk( 'iCCP', "p\x00\x00" . gzcompress( 'profile' ) )
			. hm_test_shots_png_chunk( 'sRGB', "\x00" )
			. hm_test_shots_png_chunk( 'IDAT', hm_test_shots_png_pixels() )
			. hm_test_shots_png_chunk( 'fdAT', pack( 'N', 1 ) . 'frame' )
			. hm_test_shots_png_chunk( 'IEND', '' );
		check(
			'an animated PNG keeps its animation, colour profile and frame chunks',
			hm_test_shots_png_types( (string) Screenshots\strip_png( $animated ) ),
			[ 'IHDR', 'acTL', 'fcTL', 'iCCP', 'sRGB', 'IDAT', 'fdAT', 'IEND' ]
		);

		$huge_length = "\x89PNG\r\n\x1A\n" . hm_test_shots_png_chunk( 'IHDR', pack( 'NNCCCCC', 2, 1, 8, 2, 0, 0, 0 ) ) . "\xFF\xFF\xFF\xFFIDAT";
		check( 'a PNG with a chunk length past its end is refused', Screenshots\strip_png( $huge_length . str_repeat( 'x', 64 ) ), null );
		check(
			'a PNG that does not open with its header chunk is refused',
			Screenshots\strip_png( "\x89PNG\r\n\x1A\n" . hm_test_shots_png_chunk( 'IDAT', 'x' ) . hm_test_shots_png_chunk( 'IEND', '' ) ),
			null
		);
		$long_end = "\x89PNG\r\n\x1A\n" . hm_test_shots_png_chunk( 'IHDR', pack( 'NNCCCCC', 2, 1, 8, 2, 0, 0, 0 ) ) . "\x7F\xFF\xFF\xFFIEND\x00\x00\x00\x00";
		check( 'a PNG whose end chunk claims more bytes than are left is refused', Screenshots\strip_png( $long_end ), null );
		check( 'a PNG with no end chunk is refused', Screenshots\strip_png( substr( $clean, 0, -12 ) ), null );
		check( 'a file that is not a PNG is refused', Screenshots\strip_png( 'GIF89a' . str_repeat( "\x00", 40 ) ), null );
		check(
			'a PNG\'s truncations are refused and damaged copies never throw or warn',
			hm_test_shots_robustness( Screenshots\strip_png( ... ), $clean, $dirty ),
			[]
		);

		$webp_dirty = hm_test_shots_webp( true );
		$webp_clean = hm_test_shots_webp( false );
		$webp_out   = Screenshots\strip_webp( $webp_dirty );

		check(
			'the WebP fixture carries EXIF and XMP chunks and their flags',
			[ str_contains( $webp_dirty, 'secret-exif-gps' ), str_contains( $webp_dirty, 'secret-xmp' ), ord( $webp_dirty[20] ) ],
			[ true, true, 0x1C ]
		);
		check( 'a WebP loses its EXIF and XMP chunks, their flags and keeps the rest, as an image built without them', $webp_out, $webp_clean );
		check(
			'its RIFF size matches the file',
			unpack( 'V', (string) $webp_out, 4 )[1] ?? null,
			strlen( (string) $webp_out ) - 8
		);
		check( 'its alpha flag is kept', ord( ( $webp_out ?? ' ' )[20] ) & 0x10, 0x10 );

		$size = getimagesizefromstring( (string) $webp_out );
		check( 'it still reads as a 4×2 WebP', $size ? [ $size[0], $size[1], $size['mime'] ] : $size, [ 4, 2, 'image/webp' ] );
		check( 'and decodes', imagecreatefromstring( (string) $webp_out ) instanceof GdImage, true );
		check( 'a WebP with nothing to drop is returned as it was', Screenshots\strip_webp( $webp_clean ), $webp_clean );

		$riff_long = 'RIFF' . pack( 'V', 4000 ) . substr( $webp_clean, 8 );
		check( 'a WebP whose RIFF size runs past its end is refused', Screenshots\strip_webp( $riff_long ), null );
		$chunk_long = 'RIFF' . pack( 'V', 4 + 8 ) . 'WEBP' . 'VP8 ' . pack( 'V', 4000 );
		check( 'a WebP with a chunk longer than the RIFF is refused', Screenshots\strip_webp( $chunk_long ), null );
		$short_header = 'RIFF' . pack( 'V', 4 + 8 + 4 ) . 'WEBP' . 'VP8X' . pack( 'V', 4 ) . "\x0C\x00\x00\x00";
		check( 'a WebP whose extended header is shorter than it must be is refused', Screenshots\strip_webp( $short_header ), null );
		check( 'a WebP with no chunks is refused', Screenshots\strip_webp( 'RIFF' . pack( 'V', 4 ) . 'WEBP' ), null );
		check( 'a file that is not a WebP is refused', Screenshots\strip_webp( $clean ), null );
		check(
			'a WebP\'s truncations are refused and damaged copies never throw or warn',
			hm_test_shots_robustness( Screenshots\strip_webp( ... ), $webp_clean, $webp_dirty ),
			[]
		);

		$gif_dirty = hm_test_shots_gif( true );
		$gif_clean = hm_test_shots_gif( false );
		$gif_out   = Screenshots\strip_gif( $gif_dirty );

		check(
			'the GIF fixture carries a comment, an XMP extension and bytes after its trailer',
			[ str_contains( $gif_dirty, 'secret-comment' ), str_contains( $gif_dirty, 'secret-xmp' ), str_contains( $gif_dirty, 'secret-trailer' ) ],
			[ true, true, true ]
		);
		check( 'a GIF loses its comment, other application extensions and what follows its trailer, as one built without them', $gif_out, $gif_clean );
		check(
			'an animated GIF keeps its loop block and both frames',
			[ str_contains( (string) $gif_out, "NETSCAPE2.0\x03\x01\x00\x00\x00" ), substr_count( (string) $gif_out, "\x21\xF9\x04" ), substr_count( (string) $gif_out, "\x2C\x00\x00\x00\x00\x01\x00\x01\x00" ) ],
			[ true, 2, 2 ]
		);

		$size = getimagesizefromstring( (string) $gif_out );
		check( 'it still reads as a 1×1 GIF', $size ? [ $size[0], $size[1], $size['mime'] ] : $size, [ 1, 1, 'image/gif' ] );
		check( 'and decodes', imagecreatefromstring( (string) $gif_out ) instanceof GdImage, true );
		check( 'a GIF with nothing to drop is returned as it was', Screenshots\strip_gif( $gif_clean ), $gif_clean );

		$anim_ext = str_replace( 'NETSCAPE2.0', 'ANIMEXTS1.0', $gif_clean );
		check( 'the ANIMEXTS1.0 loop block is kept too', Screenshots\strip_gif( $anim_ext ), $anim_ext );

		$no_trailer = substr( $gif_clean, 0, -1 );
		check( 'a GIF with no trailer is refused', Screenshots\strip_gif( $no_trailer ), null );
		$overrun = substr( $gif_clean, 0, 13 + 6 ) . "\x21\xFE\xFFshort\x00;";
		check( 'a GIF whose data block runs past its end is refused', Screenshots\strip_gif( $overrun ), null );
		$unknown = substr( $gif_clean, 0, 13 + 6 ) . "\x99;";
		check( 'a GIF with an unknown block is refused', Screenshots\strip_gif( $unknown ), null );
		check( 'a file that is not a GIF is refused', Screenshots\strip_gif( $clean ), null );
		check(
			'a GIF\'s truncations are refused and damaged copies never throw or warn',
			hm_test_shots_robustness( Screenshots\strip_gif( ... ), $gif_clean, $gif_dirty ),
			[]
		);

		// The same, as store() reaches them: through the file on disk.
		[ $result, $after ] = hm_test_shots_strip_file( $dirty, 'image/png' );
		check( 'a PNG file is rewritten in place without its metadata', [ $result, $after ], [ true, $clean ] );
		[ $result, $after ] = hm_test_shots_strip_file( $webp_dirty, 'image/webp' );
		check( 'a WebP file is rewritten in place without its metadata', [ $result, $after ], [ true, $webp_clean ] );
		[ $result, $after ] = hm_test_shots_strip_file( $gif_dirty, 'image/gif' );
		check( 'a GIF file is rewritten in place without its metadata', [ $result, $after ], [ true, $gif_clean ] );

		foreach ( [ 'image/png', 'image/gif', 'image/webp' ] as $type ) {
			[ $result, $after ] = hm_test_shots_strip_file( 'not an image', $type );
			check( "a damaged {$type} file is refused and left as it was", [ hm_test_error_code( $result ), $after ], [ 'uat_checklist_malformed_image', 'not an image' ] );
		}

		[ $result ] = hm_test_shots_strip_file( $clean, 'image/svg+xml' );
		check( 'a type it does not know is refused', hm_test_error_code( $result ), 'uat_checklist_malformed_image' );
	}
)();

echo "\nMetadata in files that arrive in a request\n";

(
	static function (): void {
		$step_id = wp_generate_uuid4();

		hm_test_with_page(
			[
				'post_type'    => Checklists\POST_TYPE,
				'post_title'   => 'Metadata over HTTP checklist',
				'post_content' => hm_test_checklist_markup( $step_id ),
			],
			static function ( int $checklist_id ) use ( $step_id ): void {
				hm_test_as_user(
					'subscriber',
					static function ( int $tester ) use ( $checklist_id, $step_id ): void {
						foreach (
							[
								'png'  => [ 'image/png', hm_test_shots_png( true ), hm_test_shots_png( false ) ],
								'gif'  => [ 'image/gif', hm_test_shots_gif( true ), hm_test_shots_gif( false ) ],
								'webp' => [ 'image/webp', hm_test_shots_webp( true ), hm_test_shots_webp( false ) ],
							] as $extension => [ $type, $dirty, $clean ]
						) {
							[ $status, $body ] = hm_test_shots_post_result( $tester, $checklist_id, $step_id, "uat-test-meta.{$extension}", $type, $dirty );
							$result            = get_post( (int) ( $body['id'] ?? 0 ) );
							$screenshot        = $result instanceof WP_Post ? Screenshots\of( $result ) : null;
							$file              = $screenshot ? (string) get_attached_file( $screenshot->ID ) : '';
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file this test stored.
							$stored = $file !== '' && is_file( $file ) ? (string) file_get_contents( $file ) : null;

							check( "a {$extension} sent in a request is accepted", $status, 200 );
							check( 'and stored without its metadata',$stored, $clean );
						}

						[ $status, $body ] = hm_test_shots_post_result(
							$tester,
							$checklist_id,
							$step_id,
							'uat-test-bomb.png',
							'image/png',
							hm_test_shots_claiming_png( 60000, 60000 )
						);
						check(
							'an image claiming 3.6 gigapixels is refused with a 400 and stores nothing',
							[ $status, $body['code'] ?? null, glob( wp_upload_dir()['path'] . '/uat-test-bomb*' ) ],
							[ 400, 'uat_checklist_screenshot_refused', [] ]
						);

						[ $status ] = hm_test_shots_post_result(
							$tester,
							$checklist_id,
							$step_id,
							'uat-test-bad.png',
							'image/png',
							substr( hm_test_shots_png( false ), 0, -12 )
						);
						check( 'a PNG with no end chunk is refused with a 400', $status, 400 );
						check( 'and stores nothing', glob( wp_upload_dir()['path'] . '/uat-test-bad*' ), [] );
					}
				);
			}
		);
	}
)();
