<?php
/**
 * The issue report abilities: who may run each, what list-reports, get-report and get-report-screenshot read, what
 * set-report-status writes, the input each refuses, and that none is on core's REST routes.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Abilities;
use HM\UAT_Checklist\Reports;
use HM\UAT_Checklist\Screenshots;

use const HM\UAT_Checklist\Report_Abilities\INLINE_MAX_BYTES;

echo "\nReport abilities\n";

/**
 * Runs a report ability as the current user.
 *
 * @param string $name  Ability name, without the plugin's prefix.
 * @param mixed  $input Input.
 * @return mixed Result.
 */
function hm_test_report_ability( string $name, $input = null ) {
	return wp_get_ability( Abilities\PREFIX . $name )->execute( $input );
}

/**
 * Returns a report stored as Reports\submit() stores one.
 *
 * @param int    $author      User who sent it.
 * @param string $status      Post status.
 * @param string $description What the tester typed.
 * @param int    $age         Seconds since it was sent.
 * @return WP_Post Report.
 */
function hm_test_ability_report( int $author, string $status, string $description, int $age = 0 ): WP_Post {
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
					Reports\META_PAGE_ID    => 7,
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
 * Stores a 1×1 PNG in the uploads directory and attaches it to a report.
 *
 * @param WP_Post $report Report.
 * @return int Attachment ID.
 */
function hm_test_ability_attach_png( WP_Post $report ): int {
	$image = imagecreatetruecolor( 1, 1 );
	ob_start();
	imagepng( $image );
	$stored = wp_upload_bits( 'uat-test-report-ability.png', null, (string) ob_get_clean() );

	$attached = Screenshots\attach(
		[
			'file' => $stored['file'],
			'url'  => $stored['url'],
			'type' => 'image/png',
		],
		$report
	);

	if ( is_wp_error( $attached ) ) {
		throw new RuntimeException( $attached->get_error_message() );
	}

	return $attached;
}

/**
 * Returns a fresh address at the staff domain, which makes its user a UAT manager.
 *
 * @return string Address.
 */
function hm_test_ability_staff_email(): string {
	return 'uat-' . wp_generate_password( 8, false ) . '@humanmade.com';
}

$names    = [ 'list-reports', 'get-report', 'get-report-screenshot', 'set-report-status' ];
$readonly = [ true, true, true, false ];

check(
	'all four are registered, in the checklist category, listed for MCP and kept off REST',
	array_map(
		static function ( string $name ): ?array {
			$ability = wp_get_ability( Abilities\PREFIX . $name );

			return $ability ? [ $ability->get_category(), $ability->get_meta_item( 'public' ), $ability->get_meta_item( 'show_in_rest' ) ] : null;
		},
		$names
	),
	array_fill( 0, 4, [ Abilities\CATEGORY, true, false ] )
);
check(
	'only set-report-status writes, and it is idempotent',
	array_map(
		static fn ( string $name ): array => [
			wp_get_ability( Abilities\PREFIX . $name )?->get_meta_item( 'annotations' )['readonly'] ?? null,
			wp_get_ability( Abilities\PREFIX . $name )?->get_meta_item( 'annotations' )['idempotent'] ?? null,
		],
		$names
	),
	array_map( static fn ( bool $read ): array => [ $read, true ], $readonly )
);

check(
	'each ability\'s description tells an assistant a tester\'s text is untrusted',
	array_map(
		static fn ( string $name ): bool => str_contains( (string) wp_get_ability( Abilities\PREFIX . $name )?->get_description(), 'untrusted' ),
		$names
	),
	array_fill( 0, 4, true )
);
check(
	'set-report-status says to set a status only when the person using the assistant asks',
	str_contains( (string) wp_get_ability( Abilities\PREFIX . 'set-report-status' )?->get_description(), 'only because the person using the assistant asked' ),
	true
);

foreach ( [ 'list-reports', 'get-report', 'set-report-status' ] as $name ) {
	$schema     = wp_get_ability( Abilities\PREFIX . $name )?->get_output_schema() ?? [];
	$properties = $name === 'list-reports' ? ( $schema['properties']['items']['items']['properties'] ?? [] ) : ( $schema['properties'] ?? [] );
	$last       = $name === 'list-reports' ? 'has_screenshot' : 'screenshot';

	check(
		"{$name} describes the description and page title it returns as untrusted",
		[
			str_contains( (string) ( $properties['description']['description'] ?? '' ), 'untrusted' ),
			str_contains( (string) ( $properties['page']['properties']['title']['description'] ?? '' ), 'untrusted' ),
		],
		[ true, true ]
	);
	check(
		"and {$name} still returns the same fields",
		[ array_keys( $properties ), array_keys( $properties['page']['properties'] ?? [] ) ],
		[
			[ 'id', 'status', 'status_label', 'date', 'description', 'reporter', 'page', $last ],
			[ 'url', 'id', 'title' ],
		]
	);
}

hm_test_as_user(
	'administrator',
	static function () use ( $names ): void {
		check(
			'core\'s abilities route does not serve them',
			array_map(
				static fn ( string $name ): int => hm_test_rest( 'GET', '/wp-abilities/v1/abilities/' . Abilities\PREFIX . $name )->get_status(),
				$names
			),
			array_fill( 0, 4, 404 )
		);
	}
);

hm_test_as_user(
	'subscriber',
	static function ( int $tester ): void {
		$pending       = hm_test_ability_report( $tester, 'pending', 'Pending: the <button> does nothing & stops.', 300 );
		$investigating = hm_test_ability_report( $tester, 'uat-investigating', 'Looking at it', 200 );
		$done          = hm_test_ability_report( $tester, 'uat-done', 'Done already', 100 );
		$trashed       = hm_test_ability_report( $tester, 'pending', 'Gone', 50 );
		$reports       = [ $pending->ID, $investigating->ID, $done->ID, $trashed->ID ];

		wp_trash_post( $trashed->ID );

		try {
			hm_test_as_user(
				'contributor',
				static function () use ( $pending ): void {
					foreach (
						[
							'list-reports'          => null,
							'get-report'            => [ 'report_id' => $pending->ID ],
							'get-report-screenshot' => [ 'report_id' => $pending->ID ],
							'set-report-status'     => [
								'report_id' => $pending->ID,
								'status'    => 'uat-done',
							],
						] as $name => $input
					) {
						check(
							"a tester without a staff email cannot run {$name}",
							hm_test_error_code( hm_test_report_ability( $name, $input ) ),
							'ability_invalid_permissions'
						);
					}

					clean_post_cache( $pending->ID );
					check( 'and set-report-status moved nothing', get_post( $pending->ID )?->post_status, 'pending' );
				},
				'tester-' . wp_generate_password( 8, false ) . '@example.com'
			);

			hm_test_as_user(
				'administrator',
				static function () use ( $pending ): void {
					check(
						'an administrator without a staff email cannot run them either',
						hm_test_error_code( hm_test_report_ability( 'get-report', [ 'report_id' => $pending->ID ] ) ),
						'ability_invalid_permissions'
					);
				}
			);

			wp_set_current_user( 0 );
			check(
				'nor can a visitor who is not logged in',
				hm_test_error_code( hm_test_report_ability( 'list-reports' ) ),
				'ability_invalid_permissions'
			);

			hm_test_as_user(
				'editor',
				static function () use ( $tester, $pending, $investigating, $done, $trashed, $reports ): void {
					$found = static fn ( array $items ): array => array_values( array_intersect( array_column( $items, 'id' ), $reports ) );

					$all = hm_test_report_ability( 'list-reports' );
					check( 'a manager can list the reports', is_wp_error( $all ), false );
					check(
						'newest first, a trashed report left out',
						$found( (array) ( $all['items'] ?? [] ) ),
						[ $done->ID, $investigating->ID, $pending->ID ]
					);
					check( 'it names the page and the page size', [ $all['page'] ?? null, $all['per_page'] ?? null ], [ 1, 20 ] );
					check( 'and counts every report', ( $all['total'] ?? 0 ) >= 3, true );

					$item = array_values( array_filter( (array) ( $all['items'] ?? [] ), static fn ( array $row ): bool => $row['id'] === $pending->ID ) )[0] ?? [];
					check(
						'a listed report has these fields, without the screenshot\'s details',
						array_keys( $item ),
						[ 'id', 'status', 'status_label', 'date', 'description', 'reporter', 'page', 'has_screenshot' ]
					);
					check( 'its description is plain text, as typed', $item['description'] ?? null, 'Pending: the <button> does nothing & stops.' );
					check(
						'its status and label',
						[ $item['status'] ?? null, $item['status_label'] ?? null ],
						[ 'pending', 'Pending' ]
					);
					check( 'its reporter', [ $item['reporter']['id'] ?? null, $item['reporter']['name'] ?? null ], [ $tester, get_userdata( $tester )?->display_name ] );
					check( 'its page', $item['page'] ?? null, [ 'url' => home_url( '/a-page/' ), 'id' => 7, 'title' => 'A page' ] );
					check( 'its date in ISO 8601 and UTC', (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', (string) ( $item['date'] ?? '' ) ), true );
					check( 'and that it has no screenshot', $item['has_screenshot'] ?? null, false );

					$done_only = hm_test_report_ability( 'list-reports', [ 'status' => 'uat-done' ] );
					check(
						'a status narrows the list to it',
						array_unique( array_column( (array) ( $done_only['items'] ?? [] ), 'status' ) ),
						[ 'uat-done' ]
					);
					check( 'and keeps the reports in it', $found( (array) ( $done_only['items'] ?? [] ) ), [ $done->ID ] );
					check(
						'an empty status is every status',
						$found( (array) ( hm_test_report_ability( 'list-reports', [ 'status' => '' ] )['items'] ?? [] ) ),
						[ $done->ID, $investigating->ID, $pending->ID ]
					);

					$first  = hm_test_report_ability( 'list-reports', [ 'per_page' => 1 ] );
					$second = hm_test_report_ability( 'list-reports', [ 'per_page' => 1, 'page' => 2 ] );
					check(
						'a page of one holds one report, and the next page another',
						[ count( $first['items'] ), count( $second['items'] ), $first['items'][0]['id'] === $second['items'][0]['id'], $second['page'] ],
						[ 1, 1, false, 2 ]
					);
					check( 'the total does not depend on the page', $first['total'], $second['total'] );

					foreach (
						[
							'a status that is not one of the three' => [ 'status' => 'publish' ],
							'a page size of 0'                      => [ 'per_page' => 0 ],
							'a page size of 51'                     => [ 'per_page' => 51 ],
							'a page size that is not a number'      => [ 'per_page' => 'many' ],
							'a page of 0'                           => [ 'page' => 0 ],
							'a field it does not have'              => [ 'author' => 1 ],
						] as $label => $input
					) {
						check( "list-reports refuses {$label}", hm_test_error_code( hm_test_report_ability( 'list-reports', $input ) ), 'ability_invalid_input' );
					}

					$got = hm_test_report_ability( 'get-report', [ 'report_id' => $pending->ID ] );
					check( 'get-report returns the report as the plugin describes it', $got, Reports\describe( get_post( $pending->ID ) ) );
					check( 'with no screenshot', array_key_exists( 'screenshot', $got ) ? $got['screenshot'] : 'missing', null );
					check( 'and the description as typed', $got['description'] ?? null, 'Pending: the <button> does nothing & stops.' );

					foreach (
						[
							'an ID of 0'           => [ 'report_id' => 0 ],
							'no ID'                => [],
							'an ID that is text'   => [ 'report_id' => 'one' ],
							'a field it does not have' => [
								'report_id' => $pending->ID,
								'status'    => 'pending',
							],
						] as $label => $input
					) {
						check( "get-report refuses {$label}", hm_test_error_code( hm_test_report_ability( 'get-report', $input ) ), 'ability_invalid_input' );
					}

					check(
						'an ID with no report is not found',
						hm_test_error_code( hm_test_report_ability( 'get-report', [ 'report_id' => 999999999 ] ) ),
						'uat_checklist_report_not_found'
					);
					check(
						'nor is a trashed report',
						hm_test_error_code( hm_test_report_ability( 'get-report', [ 'report_id' => $trashed->ID ] ) ),
						'uat_checklist_report_not_found'
					);

					hm_test_with_page(
						[ 'post_title' => 'Not a report' ],
						static function ( int $page_id ): void {
							check(
								'nor is a page',
								[
									hm_test_error_code( hm_test_report_ability( 'get-report', [ 'report_id' => $page_id ] ) ),
									hm_test_error_code( hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => $page_id ] ) ),
									hm_test_error_code(
										hm_test_report_ability(
											'set-report-status',
											[
												'report_id' => $page_id,
												'status'    => 'uat-done',
											]
										)
									),
								],
								array_fill( 0, 3, 'uat_checklist_report_not_found' )
							);
							check( 'and set-report-status left the page as it was', get_post_status( $page_id ), 'publish' );
						}
					);

					check(
						'a report with no screenshot has none to get',
						hm_test_error_code( hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => $pending->ID ] ) ),
						'uat_checklist_report_no_screenshot'
					);
					check(
						'get-report-screenshot refuses an ID of 0',
						hm_test_error_code( hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => 0 ] ) ),
						'ability_invalid_input'
					);
					check(
						'and an ID with no report',
						hm_test_error_code( hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => 999999999 ] ) ),
						'uat_checklist_report_not_found'
					);

					$attachment_id = hm_test_ability_attach_png( get_post( $investigating->ID ) );
					$path          = (string) get_attached_file( $attachment_id );
					$png           = (string) file_get_contents( $path );
					$shot          = hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => $investigating->ID ] );

					check( 'a report with a small screenshot returns its file as base64', $shot['data_base64'] ?? null, base64_encode( $png ) );
					check(
						'with its details',
						[ $shot['id'] ?? null, $shot['mime'] ?? null, $shot['width'] ?? null, $shot['height'] ?? null, $shot['bytes'] ?? null, $shot['url'] ?? null ],
						[ $attachment_id, 'image/png', 1, 1, strlen( $png ), wp_get_attachment_url( $attachment_id ) ]
					);
					check(
						'the report says it has a screenshot, in a list and in full',
						[
							array_column( hm_test_report_ability( 'list-reports', [ 'per_page' => 50 ] )['items'], 'has_screenshot', 'id' )[ $investigating->ID ] ?? null,
							hm_test_report_ability( 'get-report', [ 'report_id' => $investigating->ID ] )['screenshot']['id'] ?? null,
						],
						[ true, $attachment_id ]
					);

					$resize = static function ( int $bytes ) use ( $path, $png ): void {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a test fixture.
						file_put_contents( $path, $png );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- grows a test fixture to a sparse file.
						$handle = fopen( $path, 'r+' );
						ftruncate( $handle, $bytes );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the fixture.
						fclose( $handle );
						clearstatcache( true, $path );
					};

					$resize( INLINE_MAX_BYTES );
					$at_limit = hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => $investigating->ID ] );
					check(
						'a screenshot of exactly 2 MB is still returned inline',
						[ is_string( $at_limit['data_base64'] ?? null ), strlen( (string) base64_decode( (string) ( $at_limit['data_base64'] ?? '' ) ) ) ],
						[ true, INLINE_MAX_BYTES ]
					);

					$resize( INLINE_MAX_BYTES + 1 );
					$over = hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => $investigating->ID ] );
					check( 'one byte over has no data, and the output still validates', is_wp_error( $over ) ? $over->get_error_code() : ( array_key_exists( 'data_base64', $over ) ? $over['data_base64'] : 'unset' ), null );
					check( 'it still gives the size and the address', [ $over['bytes'] ?? null, ! empty( $over['url'] ) ], [ INLINE_MAX_BYTES + 1, true ] );

					wp_delete_file( $path );
					clearstatcache( true, $path );
					$gone = hm_test_report_ability( 'get-report-screenshot', [ 'report_id' => $investigating->ID ] );
					check( 'a screenshot whose file is gone has no data, and no error', is_wp_error( $gone ) ? $gone->get_error_code() : ( array_key_exists( 'data_base64', $gone ) ? $gone['data_base64'] : 'unset' ), null );

					$set = hm_test_report_ability(
						'set-report-status',
						[
							'report_id' => $pending->ID,
							'status'    => 'uat-investigating',
						]
					);
					check( 'a manager can set a status, and gets the report back', [ is_wp_error( $set ), $set['status'] ?? null ], [ false, 'uat-investigating' ] );
					clean_post_cache( $pending->ID );
					$stored = get_post( $pending->ID );
					check( 'it is stored', $stored?->post_status, 'uat-investigating' );
					check( 'and the report is otherwise the same', [ (int) $stored?->post_author, $stored?->post_content ], [ $tester, Reports\encode_for_storage( 'Pending: the <button> does nothing & stops.' ) ] );
					check( 'the result is the report as get-report returns it', $set, hm_test_report_ability( 'get-report', [ 'report_id' => $pending->ID ] ) );

					foreach ( [ 'pending', 'pending', 'uat-done' ] as $status ) {
						check(
							"set-report-status to {$status}, also the one it already has",
							hm_test_report_ability(
								'set-report-status',
								[
									'report_id' => $pending->ID,
									'status'    => $status,
								]
							)['status'] ?? null,
							$status
						);
					}

					foreach (
						[
							'a status that is not one of the three' => [
								'report_id' => $pending->ID,
								'status'    => 'publish',
							],
							'trash as a status'                     => [
								'report_id' => $pending->ID,
								'status'    => 'trash',
							],
							'no status'                             => [ 'report_id' => $pending->ID ],
							'an ID of 0'                            => [
								'report_id' => 0,
								'status'    => 'uat-done',
							],
							'no ID'                                 => [ 'status' => 'uat-done' ],
						] as $label => $input
					) {
						check( "set-report-status refuses {$label}", hm_test_error_code( hm_test_report_ability( 'set-report-status', $input ) ), 'ability_invalid_input' );
					}

					clean_post_cache( $pending->ID );
					check( 'and those left the report in the status it had', get_post( $pending->ID )?->post_status, 'uat-done' );

					$missing = hm_test_report_ability(
						'set-report-status',
						[
							'report_id' => 999999999,
							'status'    => 'uat-done',
						]
					);
					check( 'an ID with no report is not found', hm_test_error_code( $missing ), 'uat_checklist_report_not_found' );

					$in_trash = hm_test_report_ability(
						'set-report-status',
						[
							'report_id' => $trashed->ID,
							'status'    => 'uat-done',
						]
					);
					check( 'a trashed report is not found, and stays in the trash', [ hm_test_error_code( $in_trash ), get_post_status( $trashed->ID ) ], [ 'uat_checklist_report_not_found', 'trash' ] );
				},
				hm_test_ability_staff_email()
			);
		} finally {
			foreach ( $reports as $report_id ) {
				wp_delete_post( $report_id, true );
			}
		}
	}
);
