<?php
/**
 * Issue reports: the submit route and what it stores, the status routes, and the guard that keeps a report a record
 * through core's own update, trash and bulk edit code.
 *
 * A screenshot that arrives is checked through a real HTTP request to the site from inside wp-env (`http://wordpress/`,
 * the web container): wp_handle_upload() refuses any file PHP did not receive in a POST, which no test running in the
 * CLI can fake, so the accepted file, the wrong type and the stored attachment cannot be reached by dispatching a
 * request object.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Reports;
use HM\UAT_Checklist\Screenshots;

echo "\nIssue reports\n";

/**
 * Returns a 1×1 PNG.
 *
 * @return string Image bytes.
 */
function hm_test_report_png(): string {
	$image = imagecreatetruecolor( 1, 1 );
	ob_start();
	imagepng( $image );

	return (string) ob_get_clean();
}

/**
 * Returns the ID of every report on the site, trashed ones included, so a check can see that a request made none.
 *
 * @return int[] IDs.
 */
function hm_test_report_ids(): array {
	return array_map(
		'intval',
		get_posts(
			[
				'post_type'   => Reports\POST_TYPE,
				'post_status' => [ ...Reports\STATUSES, 'trash' ],
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		)
	);
}

/**
 * Returns a report as `Reports\submit()` stores one, with the date set to some seconds ago.
 *
 * @param int    $author      User who sent it.
 * @param string $status      Post status.
 * @param string $description What the tester typed.
 * @param int    $age         Seconds since it was sent.
 * @return WP_Post Report.
 */
function hm_test_make_report( int $author, string $status = Reports\STATUS_PENDING, string $description = 'It is broken', int $age = 0 ): WP_Post {
	$gmt       = gmdate( 'Y-m-d H:i:s', time() - $age );
	$report_id = wp_insert_post(
		wp_slash(
			[
				'post_type'     => Reports\POST_TYPE,
				'post_status'   => $status,
				'post_author'   => $author,
				'post_title'    => Reports\title_from( $description ),
				'post_content'  => Reports\encode_for_storage( $description ),
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
				'meta_input'    => [
					Reports\META_PAGE_URL   => home_url( '/a-page/' ),
					Reports\META_PAGE_ID    => 0,
					Reports\META_PAGE_TITLE => 'A page',
				],
			]
		),
		true
	);

	if ( is_wp_error( $report_id ) ) {
		throw new RuntimeException( $report_id->get_error_message() );
	}

	return get_post( $report_id );
}

/**
 * Returns a report as it is stored now, read past the cache.
 *
 * @param int $report_id Report ID.
 * @return WP_Post|null Report.
 */
function hm_test_stored_report( int $report_id ): ?WP_Post {
	clean_post_cache( $report_id );

	return get_post( $report_id );
}

/**
 * Runs a callback as a new user of a role, then deletes every report the user sent, trashed ones too, and the user.
 *
 * @param string   $role     Role.
 * @param callable $callback Receives the user ID.
 * @param string   $email    Email address, or '' for none.
 * @return mixed What the callback returned.
 */
function hm_test_as_reporter( string $role, callable $callback, string $email = '' ) {
	return hm_test_as_user(
		$role,
		static function ( int $user_id ) use ( $callback ) {
			try {
				return $callback( $user_id );
			} finally {
				$sent = get_posts(
					[
						'post_type'   => Reports\POST_TYPE,
						'post_status' => [ ...Reports\STATUSES, 'trash' ],
						'author'      => $user_id,
						'numberposts' => -1,
						'fields'      => 'ids',
					]
				);

				foreach ( $sent as $report_id ) {
					wp_delete_post( (int) $report_id, true );
				}
			}
		},
		$email
	);
}

/**
 * Returns a fresh address at the staff domain, which makes its user a UAT manager.
 *
 * @return string Address.
 */
function hm_test_report_staff_email(): string {
	return 'uat-' . wp_generate_password( 8, false ) . '@humanmade.com';
}

/**
 * Sends a report to the submit route as the current user.
 *
 * @param array<string, mixed> $params Fields, over the ones that make a valid report.
 * @param array<string, mixed> $files  The request's file parameters.
 * @return WP_REST_Response Response.
 */
function hm_test_submit_report( array $params = [], array $files = [] ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/uat-checklist/v1/reports' );

	foreach (
		$params + [
			'description' => 'The save button does nothing.',
			'page_url'    => home_url( '/a-page/' ),
		] as $key => $value
	) {
		$request->set_param( $key, $value );
	}

	$request->set_file_params( $files );

	return rest_get_server()->dispatch( $request );
}

/**
 * Sends a report to the site over HTTP as a user, with an image or other file as the screenshot.
 *
 * @param int                   $user_id User sending it, who is the current user.
 * @param string                $name    File name the browser sent.
 * @param string                $type    Media type the browser claimed.
 * @param string                $bytes   File contents.
 * @return array{0: int, 1: array<string, mixed>} The HTTP status and the decoded body.
 */
function hm_test_post_report_over_http( int $user_id, string $name, string $type, string $bytes ): array {
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
			'description' => 'The screenshot shows it.',
			'page_url'    => home_url( '/a-page/' ),
		] as $field => $value
	) {
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"\r\n\r\n{$value}\r\n";
	}

	$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . Screenshots\FIELD . "\"; filename=\"{$name}\"\r\n"
		. "Content-Type: {$type}\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";

	$response = wp_remote_post(
		'http://wordpress/?rest_route=/uat-checklist/v1/reports',
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
 * Returns the address scheme://host[:port] of the site's home.
 *
 * @return string Origin.
 */
function hm_test_report_origin(): string {
	$port = wp_parse_url( home_url(), PHP_URL_PORT );

	return wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( $port ? ':' . $port : '' );
}

/**
 * Returns the code a request's own validation gave an argument, which core wraps in `rest_invalid_param`.
 *
 * @param WP_REST_Response $response Response.
 * @param string           $param    Argument name.
 * @return string|null Code.
 */
function hm_test_invalid_param_code( WP_REST_Response $response, string $param ): ?string {
	$data = (array) $response->get_data();

	return $data['data']['details'][ $param ]['code'] ?? null;
}

/**
 * Runs a callback with the daily report limit raised, so a block of submits is not stopped by it.
 *
 * @param callable $callback Code to run.
 * @return mixed What it returned.
 */
function hm_test_with_many_reports( callable $callback ) {
	return hm_test_with_filter( 'hm_uat_checklist_report_daily_limit', static fn (): int => 1000, $callback );
}

/**
 * Runs a callback with wp_die() throwing, so a refusal can be read, and returns the message wp_die() was given.
 *
 * @param callable $callback Code that may call wp_die().
 * @return string|null The message, or null when wp_die() was not called.
 */
function hm_test_dying( callable $callback ): ?string {
	$handler = static function (): callable {
		return static function ( $message ): void {
			throw new RuntimeException( is_wp_error( $message ) ? $message->get_error_message() : (string) $message );
		};
	};

	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );

	try {
		$callback();

		return null;
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	}
}

echo "\nSubmitting a report\n";

$reports_before = hm_test_report_ids();

wp_set_current_user( 0 );
$anonymous = hm_test_submit_report();
check( 'a visitor who is not logged in is refused', $anonymous->get_status(), 401 );
check( 'and no report is made', hm_test_report_ids(), $reports_before );

hm_test_with_many_reports(
	static function (): void {
		hm_test_as_reporter(
			'subscriber',
			static function ( int $tester ): void {
				$sent = hm_test_submit_report(
					[
						'description' => "  The save button\r\ndoes nothing.  ",
						'page_url'    => home_url( '/a-page/' ),
						'page_id'     => 12,
						'page_title'  => 'A <b>page</b>',
					]
				);
				check( 'a Subscriber can send a report', $sent->get_status(), 201 );
				check( 'the response is the ID and Pending', array_keys( (array) $sent->get_data() ), [ 'id', 'status' ] );
				check( 'the status in the response is pending', $sent->get_data()['status'] ?? null, 'pending' );

				$report = hm_test_stored_report( (int) ( $sent->get_data()['id'] ?? 0 ) );
				check( 'it is stored as a report', $report?->post_type, Reports\POST_TYPE );
				check( 'as Pending', $report?->post_status, 'pending' );
				check( 'its author is the tester', (int) $report?->post_author, $tester );
				check( 'its dates are both set', $report?->post_date_gmt === '0000-00-00 00:00:00', false );
				check(
					'its description loses outer whitespace and keeps one line ending',
					$report ? Reports\describe( $report )['description'] : null,
					"The save button\ndoes nothing."
				);
				check( 'its title is the description on one line', $report?->post_title, 'The save button does nothing.' );
				check( 'it stores the page address', get_post_meta( (int) $report?->ID, Reports\META_PAGE_URL, true ), home_url( '/a-page/' ) );
				check( 'the page ID', get_post_meta( (int) $report?->ID, Reports\META_PAGE_ID, true ), '12' );
				check( 'and the page title without its markup', get_post_meta( (int) $report?->ID, Reports\META_PAGE_TITLE, true ), 'A page' );
				check( 'a report with no screenshot has none', $report ? Reports\screenshot_of( $report ) : false, null );

				$long = hm_test_submit_report( [ 'description' => str_repeat( 'word ', 40 ) ] );
				check(
					'a long description is cut for the title at 60 characters, with an ellipsis',
					hm_test_stored_report( (int) ( $long->get_data()['id'] ?? 0 ) )?->post_title,
					rtrim( substr( str_repeat( 'word ', 40 ), 0, 60 ) ) . '…'
				);

				check( 'a report with no description is refused', hm_test_submit_report( [ 'description' => null ] )->get_status(), 400 );

				$missing = new WP_REST_Request( 'POST', '/uat-checklist/v1/reports' );
				$missing->set_param( 'page_url', home_url( '/a-page/' ) );
				$missing_response = rest_get_server()->dispatch( $missing );
				check( 'one sent without the description field is refused', $missing_response->get_status(), 400 );
				check( 'for the missing field', $missing_response->get_data()['code'] ?? null, 'rest_missing_callback_param' );

				$empty = hm_test_submit_report( [ 'description' => '' ] );
				check( 'an empty description is refused', hm_test_invalid_param_code( $empty, 'description' ), 'uat_checklist_report_empty' );
				check(
					'so is one of only whitespace',
					hm_test_invalid_param_code( hm_test_submit_report( [ 'description' => " \r\n\t " ] ), 'description' ),
					'uat_checklist_report_empty'
				);
				check(
					'a description that is not text is refused',
					hm_test_submit_report( [ 'description' => [ 'a' ] ] )->get_status(),
					400
				);

				$exact = hm_test_submit_report( [ 'description' => str_repeat( 'x', Reports\DESCRIPTION_MAX_LENGTH ) ] );
				check( 'a description of 5000 characters is accepted', $exact->get_status(), 201 );
				$over = hm_test_submit_report( [ 'description' => str_repeat( 'x', Reports\DESCRIPTION_MAX_LENGTH + 1 ) ] );
				check( 'one of 5001 is refused', hm_test_invalid_param_code( $over, 'description' ), 'uat_checklist_report_too_long' );
				check( 'with a 400', $over->get_status(), 400 );

				$multibyte = hm_test_submit_report( [ 'description' => str_repeat( '日', Reports\DESCRIPTION_MAX_LENGTH ) ] );
				check( 'it counts characters, not bytes', $multibyte->get_status(), 201 );

				// A multipart body ends every line in CRLF, which would count twice. 999 lines of 4 characters and a last
				// of 5 is 5000 characters once cleaned and 5999 as sent.
				$crlf = str_repeat( "abcd\r\n", 999 ) . 'abcde';
				$ok   = hm_test_submit_report( [ 'description' => $crlf ] );
				check( 'a description of 5000 characters with CRLF line endings is accepted', $ok->get_status(), 201 );
				$stored = hm_test_stored_report( (int) ( $ok->get_data()['id'] ?? 0 ) );
				check(
					'and stored with plain line endings',
					$stored ? [ str_contains( $stored->post_content, "\r" ), mb_strlen( Reports\describe( $stored )['description'] ) ] : null,
					[ false, 5000 ]
				);
				$crlf_over = hm_test_submit_report( [ 'description' => $crlf . 'f' ] );
				check( 'one more character and it is refused', hm_test_invalid_param_code( $crlf_over, 'description' ), 'uat_checklist_report_too_long' );

				$origin = hm_test_report_origin();

				foreach (
					[
						'another host'                    => 'https://evil.example/a-page/',
						'a subdomain of the host'         => str_replace( '://', '://www.', $origin ) . '/a-page/',
						'another port'                    => 'http://localhost:3035/a-page/',
						'the host as a prefix of another' => $origin . '.evil.example/a-page/',
						'no scheme'                       => 'localhost:3034/a-page/',
						'a scheme-relative address'       => '//' . wp_parse_url( home_url(), PHP_URL_HOST ) . ':3034/a-page/',
						'a path only'                     => '/a-page/',
						'a backslash after the host'      => $origin . '\\@evil.example/',
						'a backslash in the path'         => $origin . '/a\\b',
						'a javascript address'            => 'javascript:alert(1)',
						'a data address'                  => 'data:text/html,hi',
						'an ftp address'                  => 'ftp://localhost:3034/a-page/',
						'an empty address'                => '',
					] as $label => $address
				) {
					$refused = hm_test_submit_report( [ 'page_url' => $address ] );
					check( "a page address with {$label} is refused", $refused->get_status(), 400 );
				}

				check(
					'one at another host names the page address as the cause',
					hm_test_invalid_param_code( hm_test_submit_report( [ 'page_url' => 'https://evil.example/' ] ), 'page_url' ),
					'uat_checklist_page_url_foreign'
				);

				$other_scheme = hm_test_submit_report( [ 'page_url' => 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ':3034/x/' ] );
				check( 'the same host and port on another scheme is accepted', $other_scheme->get_status(), 201 );

				$canonical = hm_test_submit_report(
					[ 'page_url' => str_replace( '://', '://user:secret@', $origin ) . '/some/page/?token=abc&x=1#frag' ]
				);
				check( 'an address with credentials, a query string and a fragment is accepted', $canonical->get_status(), 201 );
				check(
					'and stored as the scheme, host and path alone',
					get_post_meta( (int) ( $canonical->get_data()['id'] ?? 0 ), Reports\META_PAGE_URL, true ),
					$origin . '/some/page/'
				);

				$bare = hm_test_submit_report( [ 'page_url' => $origin ] );
				check(
					'an address with no path is stored with the root',
					get_post_meta( (int) ( $bare->get_data()['id'] ?? 0 ), Reports\META_PAGE_URL, true ),
					$origin . '/'
				);

				check( 'a page ID that is not a number is refused', hm_test_submit_report( [ 'page_id' => 'abc' ] )->get_status(), 400 );
				check( 'a negative page ID is refused', hm_test_submit_report( [ 'page_id' => -1 ] )->get_status(), 400 );

				$titled = hm_test_submit_report( [ 'page_title' => str_repeat( 'é', 250 ) ] );
				check(
					'a page title is cut to 200 characters',
					mb_strlen( (string) get_post_meta( (int) ( $titled->get_data()['id'] ?? 0 ), Reports\META_PAGE_TITLE, true ) ),
					Reports\PAGE_TITLE_MAX_LENGTH
				);

				$defaults = new WP_REST_Request( 'POST', '/uat-checklist/v1/reports' );
				$defaults->set_param( 'description', 'No page details.' );
				$defaults->set_param( 'page_url', home_url( '/a-page/' ) );
				$defaulted = rest_get_server()->dispatch( $defaults );
				$made      = (int) ( $defaulted->get_data()['id'] ?? 0 );
				check(
					'the page ID and title are optional',
					[ $defaulted->get_status(), get_post_meta( $made, Reports\META_PAGE_ID, true ), get_post_meta( $made, Reports\META_PAGE_TITLE, true ) ],
					[ 201, '0', '' ]
				);
			}
		);
	}
);

echo "\nThe description is stored encoded and read back as typed\n";

hm_test_with_many_reports(
	static function (): void {
		$typed = [
			'markup and an ampersand' => '<script>alert(1)</script> <b>bold</b> Tom & Jerry <button>Go</button>',
			'a typed entity'          => 'Shows &amp; and &lt;b&gt; literally, and &#039;',
			'quotes and a backslash'  => "It's \"fine\" in C:\\temp\\new",
			'text outside ASCII'      => 'café 日本語 😀',
		];

		foreach ( [ 'subscriber', 'editor' ] as $role ) {
			hm_test_as_reporter(
				$role,
				static function ( int $tester ) use ( $role, $typed ): void {
					$unfiltered = $role === 'editor';
					check(
						"a {$role} " . ( $unfiltered ? 'has' : 'lacks' ) . ' unfiltered_html',
						[ current_user_can( 'unfiltered_html' ), (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ],
						[ $unfiltered, ! $unfiltered ]
					);

					foreach ( $typed as $label => $text ) {
						$sent = hm_test_submit_report( [ 'description' => $text ] );
						$post = hm_test_stored_report( (int) ( $sent->get_data()['id'] ?? 0 ) );

						check( "{$role}: {$label} is accepted", $sent->get_status(), 201 );
						check(
							"{$role}: {$label} is read back as typed",
							$post ? Reports\describe( $post )['description'] : null,
							$text
						);
						check(
							"{$role}: {$label} is stored with its markup as text",
							$post?->post_content,
							htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
						);
					}

					$markup = hm_test_stored_report( (int) ( hm_test_submit_report( [ 'description' => $typed['markup and an ampersand'] ] )->get_data()['id'] ?? 0 ) );
					check(
						"{$role}: nothing stored can run as markup",
						[
							str_contains( (string) $markup?->post_content, '<' ),
							str_contains( (string) $markup?->post_title, '<' ),
						],
						[ false, false ]
					);
				}
			);
		}

		hm_test_as_user(
			'subscriber',
			static function () use ( $typed ): void {
				$raw = $typed['markup and an ampersand'];
				check(
					'the raw text would not survive kses, which is why it is stored encoded',
					wp_filter_post_kses( wp_slash( $raw ) ) === wp_slash( $raw ),
					false
				);
			}
		);
	}
);

echo "\nA screenshot sent with a report\n";

hm_test_with_many_reports(
	static function (): void {
		hm_test_as_reporter(
			'subscriber',
			static function ( int $tester ): void {
				$before = hm_test_report_ids();

				$fake = [
					Screenshots\FIELD => [
						'name'     => 'shot.png',
						'type'     => 'image/png',
						'tmp_name' => wp_tempnam( 'shot.png' ),
						'error'    => UPLOAD_ERR_OK,
						'size'     => 10,
					],
				];
				$failed = hm_test_submit_report( [], $fake );
				wp_delete_file( $fake[ Screenshots\FIELD ]['tmp_name'] );
				check( 'a file that did not arrive as an upload is refused', $failed->get_data()['code'] ?? null, 'uat_checklist_screenshot_refused' );
				check( 'with a 400', $failed->get_status(), 400 );
				check( 'a refused screenshot leaves no report behind', hm_test_report_ids(), $before );

				hm_test_with_filter(
					'hm_uat_checklist_screenshot_max_bytes',
					static fn (): int => 100,
					static function () use ( $before ): void {
						$big = hm_test_submit_report(
							[],
							[
								Screenshots\FIELD => [
									'name'     => 'big.png',
									'type'     => 'image/png',
									'tmp_name' => '/nonexistent',
									'error'    => UPLOAD_ERR_OK,
									'size'     => 101,
								],
							]
						);
						check( 'a screenshot over the size limit is refused', $big->get_data()['code'] ?? null, 'uat_checklist_screenshot_refused' );
						check( 'and leaves no report behind', hm_test_report_ids(), $before );
					}
				);

				$accepted = hm_test_post_report_over_http( $tester, 'shot.png', 'image/png', hm_test_report_png() );
				$report   = hm_test_stored_report( (int) ( $accepted[1]['id'] ?? 0 ) );
				$shot     = $report ? Screenshots\of( $report ) : null;
				check( 'an image sent over HTTP is accepted', $accepted[0], 201 );
				check( 'the report is Pending', $report?->post_status, 'pending' );
				check( 'the image is attached to the report', $shot?->post_parent, $report?->ID );
				check( 'as an image', $shot?->post_mime_type, 'image/png' );
				check( 'by the tester', (int) $shot?->post_author, $tester );
				check( 'and its file is stored', $shot ? is_file( (string) get_attached_file( $shot->ID ) ) : null, true );
				check( 'it counts toward the tester\'s screenshots for the day', Screenshots\uploaded_today( $tester ), 1 );

				$described = $report ? Reports\describe( $report ) : null;
				check( 'the report describes its screenshot', $described['screenshot']['mime'] ?? null, 'image/png' );

				$count = count( hm_test_report_ids() );
				$text  = hm_test_post_report_over_http( $tester, 'notes.png', 'image/png', 'this is not an image' );
				check( 'a text file named as a PNG is refused', $text[0], 400 );
				check( 'with the screenshot as the cause', $text[1]['code'] ?? null, 'uat_checklist_screenshot_refused' );

				$script = hm_test_post_report_over_http( $tester, 'shell.php', 'image/png', '<?php echo 1;' );
				check( 'a PHP file is refused', $script[0], 400 );
				check( 'and no report was made by either', count( hm_test_report_ids() ), $count );

				hm_test_with_filter(
					'hm_uat_checklist_screenshot_daily_limit',
					static fn (): int => 1,
					static function (): void {
						$limited = hm_test_submit_report(
							[],
							[
								Screenshots\FIELD => [
									'name'     => 'a.png',
									'type'     => 'image/png',
									'tmp_name' => '/nonexistent',
									'error'    => UPLOAD_ERR_OK,
									'size'     => 1,
								],
							]
						);
						check(
							'the screenshot limit is shared with the screenshots on results, and counts the one on the report',
							[ $limited->get_status(), $limited->get_data()['code'] ?? null ],
							[ 429, 'hm_uat_checklist_screenshot_limited' ]
						);
					}
				);

				$kept = $report ? $report->ID : 0;
				wp_delete_post( $kept, true );
				check( 'deleting a report deletes its screenshot', $shot ? get_post( $shot->ID ) : 'no screenshot', null );
				check( 'and its file', $shot ? is_file( (string) get_attached_file( $shot->ID ) ) : 'no screenshot', false );
			}
		);
	}
);

echo "\nThe daily limit\n";

check( 'a tester can send 20 reports a day', Reports\daily_limit(), Reports\DAILY_LIMIT );

hm_test_as_reporter(
	'subscriber',
	static function ( int $tester ): void {
		$sent = [];

		for ( $i = 0; $i < Reports\DAILY_LIMIT; $i++ ) {
			$sent[] = hm_test_make_report( $tester );
		}

		check( 'the reports sent in the last day are counted', Reports\sent_today( $tester ), Reports\DAILY_LIMIT );

		$limited = hm_test_submit_report();
		check( 'a report past the limit is refused', $limited->get_status(), 429 );
		check( 'for the limit', $limited->get_data()['code'] ?? null, 'hm_uat_checklist_report_limited' );
		check( 'and makes none', Reports\sent_today( $tester ), Reports\DAILY_LIMIT );

		wp_trash_post( $sent[0]->ID );
		check(
			'deleting a report does not free a place',
			hm_test_submit_report()->get_data()['code'] ?? null,
			'hm_uat_checklist_report_limited'
		);

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- backdates a test fixture.
		$wpdb->update( $wpdb->posts, [ 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ], [ 'ID' => $sent[1]->ID ] );
		// The count is a WP_Query, whose result the request caches until a post changes.
		clean_post_cache( $sent[1]->ID );
		check( 'a report from more than a day ago is not counted', Reports\sent_today( $tester ), Reports\DAILY_LIMIT - 1 );

		$freed = hm_test_submit_report();
		check( 'so a place is free again', $freed->get_status(), 201 );
	}
);

hm_test_as_reporter(
	'subscriber',
	static function (): void {
		hm_test_as_reporter(
			'contributor',
			static function ( int $other ): void {
				$sent = [];

				for ( $i = 0; $i < Reports\DAILY_LIMIT; $i++ ) {
					$sent[] = hm_test_make_report( $other );
				}

				check( 'the limit is each tester\'s own', hm_test_submit_report()->get_status(), 429 );
			}
		);

		check( 'another tester is not held to it', hm_test_submit_report()->get_status(), 201 );
	}
);

hm_test_as_reporter(
	'subscriber',
	static function ( int $tester ): void {
		$lock = 'user_' . $tester;
		wp_cache_add( $lock, 1, Reports\LOCK_GROUP, Reports\LOCK_TTL );
		$busy = hm_test_submit_report();
		wp_cache_delete( $lock, Reports\LOCK_GROUP );
		check(
			'a report sent while the tester\'s last is still saving is refused',
			[ $busy->get_status(), $busy->get_data()['code'] ?? null ],
			[ 429, 'hm_uat_checklist_report_busy' ]
		);

		hm_test_submit_report();
		check( 'the lock is released after a report is saved', wp_cache_get( $lock, Reports\LOCK_GROUP ), false );
	}
);

echo "\nChanging a report's status\n";

hm_test_as_reporter(
	'subscriber',
	static function ( int $tester ): void {
		$report  = hm_test_make_report( $tester, 'pending', 'Status test <b>report</b>' );
		$trashed = hm_test_make_report( $tester );
		wp_trash_post( $trashed->ID );
		$status = static function ( int $id, string $to ): WP_REST_Response {
			return hm_test_rest( 'POST', "/uat-checklist/v1/reports/{$id}/status", [ 'status' => $to ] );
		};
		$bulk   = static function ( array $ids, string $to ): WP_REST_Response {
			return hm_test_rest( 'POST', '/uat-checklist/v1/reports/status', [ 'ids' => $ids, 'status' => $to ] );
		};

		hm_test_as_user(
			'contributor',
			static function () use ( $report, $status, $bulk ): void {
				$refused = $status( $report->ID, 'uat-done' );
				check( 'a tester who is not a manager is refused', [ $refused->get_status(), $refused->get_data()['code'] ?? null ], [ 403, 'rest_forbidden' ] );
				check( 'the report is unchanged', hm_test_stored_report( $report->ID )?->post_status, 'pending' );
				check( 'and so is a bulk change refused', $bulk( [ $report->ID ], 'uat-done' )->get_status(), 403 );
				check( 'and nothing moved', hm_test_stored_report( $report->ID )?->post_status, 'pending' );
			}
		);

		wp_set_current_user( 0 );
		check( 'a visitor who is not logged in is refused', $status( $report->ID, 'uat-done' )->get_status(), 401 );

		hm_test_as_user(
			'editor',
			static function ( int $manager ) use ( $report, $trashed, $tester, $status, $bulk ): void {
				$before = $report->to_array();

				foreach ( [ 'uat-investigating', 'uat-done', 'pending' ] as $to ) {
					$moved = $status( $report->ID, $to );
					check( "a manager can move a report to {$to}", [ $moved->get_status(), $moved->get_data()['status'] ?? null ], [ 200, $to ] );
					check( "and it is {$to} in the database", hm_test_stored_report( $report->ID )?->post_status, $to );
				}

				$after = hm_test_stored_report( $report->ID )?->to_array() ?? [];
				check(
					'the moves changed nothing else: not the title, content, author or dates',
					array_intersect_key( $after, array_flip( [ 'post_title', 'post_content', 'post_author', 'post_date', 'post_date_gmt' ] ) ),
					array_intersect_key( $before, array_flip( [ 'post_title', 'post_content', 'post_author', 'post_date', 'post_date_gmt' ] ) )
				);
				check( 'the report is still the tester\'s, not the manager\'s', (int) ( $after['post_author'] ?? 0 ), $tester );
				check( 'the response describes the report as typed', $status( $report->ID, 'pending' )->get_data()['description'] ?? null, 'Status test <b>report</b>' );

				$same = $status( $report->ID, 'pending' );
				check( 'moving a report to the status it has is accepted', $same->get_status(), 200 );

				check( 'a status that is not one of the three is refused', $status( $report->ID, 'publish' )->get_status(), 400 );
				check( 'so is trash', $status( $report->ID, 'trash' )->get_status(), 400 );
				check( 'and an empty status', $status( $report->ID, '' )->get_status(), 400 );
				check( 'the report is unmoved by them', hm_test_stored_report( $report->ID )?->post_status, 'pending' );

				$no_status = rest_get_server()->dispatch( new WP_REST_Request( 'POST', "/uat-checklist/v1/reports/{$report->ID}/status" ) );
				check( 'a request with no status is refused', $no_status->get_status(), 400 );
				check( 'an ID of 0 is refused', $status( 0, 'uat-done' )->get_status(), 400 );
				check( 'an ID with no report is not found', $status( 999999999, 'uat-done' )->get_status(), 404 );

				hm_test_with_page(
					[ 'post_title' => 'Not a report' ],
					static function ( int $page_id ) use ( $status ): void {
						check( 'the ID of a page is not found', $status( $page_id, 'uat-done' )->get_status(), 404 );
						check( 'and its status is unchanged', get_post_status( $page_id ), 'publish' );
					}
				);

				check( 'a report in the trash is not found', $status( $trashed->ID, 'uat-done' )->get_status(), 404 );
				$direct = Reports\set_status( get_post( $trashed->ID ), 'uat-done' );
				check( 'and set_status() refuses it as unavailable', [ hm_test_error_code( $direct ), $direct->get_error_data()['status'] ?? null ], [ 'uat_checklist_report_unavailable', 409 ] );
				check( 'it stays in the trash', get_post_status( $trashed->ID ), 'trash' );

				hm_test_with_page(
					[],
					static function ( int $page_id ): void {
						$page = Reports\set_status( get_post( $page_id ), 'uat-done' );
						check(
							'set_status() is not for other posts',
							[ hm_test_error_code( $page ), is_wp_error( $page ) ? $page->get_error_data()['status'] : null ],
							[ 'uat_checklist_not_a_report', 404 ]
						);
					}
				);

				$moved = $bulk( [ $report->ID, $report->ID ], 'uat-investigating' );
				check( 'an ID named twice in a bulk change is moved once', $moved->get_data()['updated'] ?? null, [ $report->ID ] );
			},
			hm_test_report_staff_email()
		);
	}
);

echo "\nChanging several reports' status\n";

hm_test_as_reporter(
	'subscriber',
	static function ( int $tester ): void {
		$first   = hm_test_make_report( $tester );
		$second  = hm_test_make_report( $tester, 'uat-investigating' );
		$trashed = hm_test_make_report( $tester );
		wp_trash_post( $trashed->ID );
		$bulk = static function ( array $ids, string $to ): WP_REST_Response {
			return hm_test_rest(
				'POST',
				'/uat-checklist/v1/reports/status',
				[
					'ids'    => $ids,
					'status' => $to,
				]
			);
		};

		hm_test_as_user(
			'editor',
			static function () use ( $first, $second, $trashed, $bulk ): void {
				hm_test_with_page(
					[ 'post_title' => 'Not a report' ],
					static function ( int $page_id ) use ( $first, $second, $trashed, $bulk ): void {
						$moved = $bulk( [ $first->ID, $second->ID, 999999999, $page_id, $trashed->ID ], 'uat-done' );
						$data  = (array) $moved->get_data();
						check( 'a bulk change with some IDs that cannot move is a 200', $moved->get_status(), 200 );
						check( 'it lists the reports it moved', $data['updated'] ?? null, [ $first->ID, $second->ID ] );
						check(
							'and each one it could not, with its reason',
							array_map( static fn ( array $failure ): array => [ $failure['id'], $failure['code'] ], (array) ( $data['failed'] ?? [] ) ),
							[
								[ 999999999, 'uat_checklist_report_not_found' ],
								[ $page_id, 'uat_checklist_report_not_found' ],
								[ $trashed->ID, 'uat_checklist_report_not_found' ],
							]
						);
						check(
							'every failure has a message',
							array_map( static fn ( array $failure ): bool => $failure['message'] !== '', (array) ( $data['failed'] ?? [] ) ),
							[ true, true, true ]
						);
						check(
							'the reports it moved are done in the database',
							[ hm_test_stored_report( $first->ID )?->post_status, hm_test_stored_report( $second->ID )?->post_status ],
							[ 'uat-done', 'uat-done' ]
						);
						check( 'a page it named is untouched', get_post_status( $page_id ), 'publish' );
						check( 'and a trashed report stays trashed', get_post_status( $trashed->ID ), 'trash' );

						$none = $bulk( [ $first->ID ], 'uat-investigating' );
						check( 'a bulk change of one is the same shape', $none->get_data(), [ 'updated' => [ $first->ID ], 'failed' => [] ] );
					}
				);

				check( 'a bulk change with no IDs is refused', $bulk( [], 'uat-done' )->get_status(), 400 );
				check( 'a bulk change with an ID that is not a number is refused', $bulk( [ 'a' ], 'uat-done' )->get_status(), 400 );
				check( 'a bulk change with an ID of 0 is refused', $bulk( [ 0 ], 'uat-done' )->get_status(), 400 );
				check( 'a bulk change with an invalid status is refused', $bulk( [ $first->ID ], 'publish' )->get_status(), 400 );

				$fifty = range( 900000001, 900000050 );
				$ok    = $bulk( $fifty, 'uat-done' );
				check( 'a bulk change of 50 IDs is accepted', $ok->get_status(), 200 );
				check( 'and none of the 50 is a report', count( $ok->get_data()['failed'] ?? [] ), 50 );
				check( 'one of 51 is refused', $bulk( range( 900000001, 900000051 ), 'uat-done' )->get_status(), 400 );

				$missing = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/uat-checklist/v1/reports/status' ) );
				check( 'one with no IDs and no status is refused', $missing->get_status(), 400 );
			},
			'uat-' . wp_generate_password( 8, false ) . '@humanmade.com'
		);
	}
);

echo "\nA report is a record\n";

hm_test_as_reporter(
	'contributor',
	static function ( int $tester ): void {
		$report = hm_test_make_report( $tester, 'uat-investigating', 'Original description' );
		$id     = $report->ID;

		foreach ( [ 'edit_post', 'delete_post', 'publish_post' ] as $cap ) {
			check( "the tester who sent a report cannot {$cap} it", current_user_can( $cap, $id ), false );
		}

		check( 'but can read it', current_user_can( 'read_post', $id ), true );

		hm_test_as_user(
			'subscriber',
			static function () use ( $id ): void {
				foreach ( [ 'edit_post', 'delete_post', 'publish_post' ] as $cap ) {
					check( "a Subscriber cannot {$cap} a report", current_user_can( $cap, $id ), false );
				}
			}
		);

		hm_test_as_user(
			'administrator',
			static function () use ( $id ): void {
				check( 'an administrator with no staff email cannot edit a report', current_user_can( 'edit_post', $id ), false );
			}
		);

		hm_test_as_user(
			'editor',
			static function ( int $manager ) use ( $id, $tester ): void {
				check( 'a manager can edit a report', current_user_can( 'edit_post', $id ), true );
				check( 'and delete it', current_user_can( 'delete_post', $id ), true );

				$stored = hm_test_stored_report( $id )?->to_array() ?? [];
				$kept   = array_intersect_key( $stored, array_flip( [ 'post_title', 'post_content', 'post_author', 'post_date', 'post_date_gmt', 'post_name' ] ) );

				foreach ( [ 'draft', 'publish', 'private', 'future', 'pending', 'inherit', 'auto-draft', 'not-a-status' ] as $status ) {
					wp_update_post(
						[
							'ID'            => $id,
							'post_title'    => 'Changed title',
							'post_content'  => 'Changed <b>content</b>',
							'post_author'   => $manager,
							'post_name'     => 'changed-slug',
							'post_date'     => '2001-01-01 00:00:00',
							'post_date_gmt' => '2001-01-01 00:00:00',
							'edit_date'     => true,
							'post_status'   => $status,
						],
						true
					);
					$after = hm_test_stored_report( $id )?->to_array() ?? [];
					check(
						"updating a report as {$status} keeps its title, content, author, dates and slug",
						array_intersect_key( $after, $kept ),
						$kept
					);

					$expected = $status === 'pending' ? 'pending' : 'uat-investigating';
					check( "and refuses {$status} as its status", $after['post_status'] ?? null, $expected );
					wp_update_post( [ 'ID' => $id, 'post_status' => 'uat-investigating' ] );
				}

				wp_update_post(
					[
						'ID'          => $id,
						'post_title'  => 'Changed title',
						'post_status' => 'uat-done',
					]
				);
				$done = hm_test_stored_report( $id );
				check( 'a status of the workflow is allowed through core\'s update', $done?->post_status, 'uat-done' );
				check( 'with the title still unchanged', $done?->post_title, $kept['post_title'] );

				wp_update_post( [ 'ID' => $id, 'post_status' => 'uat-investigating' ] );

				require_once ABSPATH . 'wp-admin/includes/post.php';

				$bulk   = bulk_edit_posts(
					[
						'post_type'   => Reports\POST_TYPE,
						'post'        => [ $id ],
						'post_author' => $manager,
						'_status'     => 'publish',
						'post_title'  => 'Bulk title',
					]
				);
				$bulked = hm_test_stored_report( $id );
				check(
					'core\'s bulk edit, which names a new author and publishing, changes neither',
					[ $bulked?->post_status, (int) $bulked?->post_author, $bulked?->post_title ],
					[ 'uat-investigating', $tester, $kept['post_title'] ]
				);
				check( 'and did not skip the report as an error', is_array( $bulk ) && isset( $bulk['updated'] ), true );

				// What the list's Quick Edit posts. Its row is hidden from the screen, so this is a crafted request.
				require_once ABSPATH . 'wp-admin/includes/ajax-actions.php';
				$_POST = [
					'_inline_edit' => wp_create_nonce( 'inlineeditnonce' ),
					'post_ID'      => (string) $id,
					'post_type'    => Reports\POST_TYPE,
					'screen'       => 'edit-' . Reports\POST_TYPE,
					'post_view'    => 'list',
					'_status'      => 'publish',
					'post_title'   => 'Quick title',
					'post_name'    => 'quick-slug',
					'post_author'  => (string) $manager,
					'mm'           => '01',
					'jj'           => '01',
					'aa'           => '2001',
					'hh'           => '00',
					'mn'           => '00',
					'ss'           => '00',
				];

				// check_ajax_referer() reads the nonce from $_REQUEST, which core builds once at the start of a request.
				$request = $_REQUEST;
				$_REQUEST = $_POST;

				try {
					ob_start();
					$ended = hm_test_dying( 'wp_ajax_inline_save' );
				} finally {
					ob_end_clean();
					$_POST    = [];
					$_REQUEST = $request;
				}

				$inline = hm_test_stored_report( $id );
				check(
					'core\'s Quick Edit save, crafted to publish the report with a new title, slug, date and author, changes none of them',
					[ $inline?->post_status, (int) $inline?->post_author, $inline?->post_title, $inline?->post_name, $inline?->post_date ],
					[ 'uat-investigating', $tester, $kept['post_title'], $kept['post_name'], $kept['post_date'] ]
				);
				check( 'and ran to its end', $ended, '' );

				foreach ( [ 'uat-investigating', 'uat-done', 'pending' ] as $from ) {
					wp_update_post( [ 'ID' => $id, 'post_status' => $from ] );
					wp_trash_post( $id );
					$in_trash = hm_test_stored_report( $id )?->post_status;
					wp_untrash_post( $id );
					check(
						"a report that was {$from} is trashed, and restored as {$from}, not left as a draft",
						[ $in_trash, hm_test_stored_report( $id )?->post_status ],
						[ 'trash', $from ]
					);
				}

				check( 'a restored report can be moved again', Reports\set_status( get_post( $id ), 'uat-done' ), true );
				check( 'and is found again', Reports\find_report( $id )?->post_status, 'uat-done' );
			},
			hm_test_report_staff_email()
		);

		hm_test_as_user(
			'contributor',
			static function ( int $other ) use ( $id, $tester ): void {
				$status = hm_test_stored_report( $id )?->post_status;
				$result = bulk_edit_posts(
					[
						'post_type'   => Reports\POST_TYPE,
						'post'        => [ $id ],
						'post_author' => $other,
						'_status'     => 'publish',
					]
				);
				check( 'core\'s bulk edit skips the report for a tester', is_array( $result ) ? $result['skipped'] : $result, [ $id ] );
				check( 'and changes nothing', [ hm_test_stored_report( $id )?->post_status, (int) hm_test_stored_report( $id )?->post_author ], [ $status, $tester ] );
			}
		);
	}
);

echo "\nA report's screenshot and slug\n";

hm_test_as_reporter(
	'author',
	static function ( int $author ): void {
		$report = hm_test_make_report( $author );
		$shot   = wp_insert_attachment(
			[
				'post_title'     => 'Screenshot',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
				'post_author'    => $author,
			],
			false,
			$report->ID
		);
		$other  = wp_insert_attachment(
			[
				'post_title'     => 'Elsewhere',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
				'post_author'    => $author,
			],
			false,
			0
		);

		try {
			check(
				'an Author-role reporter cannot edit or delete the screenshot on their report',
				[ user_can( $author, 'edit_post', $shot ), user_can( $author, 'delete_post', $shot ) ],
				[ false, false ]
			);
			check(
				'but can still do both to an attachment that is not on a report',
				[ user_can( $author, 'edit_post', $other ), user_can( $author, 'delete_post', $other ) ],
				[ true, true ]
			);

			hm_test_as_user(
				'editor',
				static function ( int $editor ) use ( $shot ): void {
					check(
						'an Editor who is not a manager cannot edit or delete it either',
						[ user_can( $editor, 'edit_post', $shot ), user_can( $editor, 'delete_post', $shot ) ],
						[ false, false ]
					);
				}
			);

			hm_test_as_user(
				'editor',
				static function ( int $manager ) use ( $shot ): void {
					check(
						'a manager can',
						[ user_can( $manager, 'edit_post', $shot ), user_can( $manager, 'delete_post', $shot ) ],
						[ true, true ]
					);
				},
				hm_test_report_staff_email()
			);

			check( 'a Pending report has no slug', hm_test_stored_report( $report->ID )?->post_name, '' );
			check( 'its first move succeeds', Reports\set_status( $report, 'uat-investigating' ), true );
			check( 'and gives it its ID as the slug, not one made from the title', hm_test_stored_report( $report->ID )?->post_name, (string) $report->ID );
		} finally {
			wp_delete_attachment( $shot, true );
			wp_delete_attachment( $other, true );
		}
	}
);
