<?php
/**
 * The issue reports' wp-admin screens: the list's columns, row actions, bulk actions and views, searching for text
 * that is stored encoded, the locked edit screen, the read-only page a tester opens, and the script that changes a
 * status.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Report_Admin;
use HM\UAT_Checklist\Reports;
use HM\UAT_Checklist\Screenshots;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

echo "\nReport admin screens\n";

/**
 * Returns a report stored as Reports\submit() stores one.
 *
 * @param int                  $author      User who sent it, or 0 for none.
 * @param string               $status      Post status.
 * @param string               $description What the tester typed.
 * @param array<string, mixed> $meta        Meta to store over the page's address and title.
 * @return WP_Post Report.
 */
function hm_test_admin_report( int $author, string $status, string $description, array $meta = [] ): WP_Post {
	$report_id = wp_insert_post(
		wp_slash(
			[
				'post_type'     => Reports\POST_TYPE,
				'post_status'   => $status,
				'post_author'   => $author,
				'post_title'    => Reports\title_from( $description ),
				'post_content'  => Reports\encode_for_storage( $description ),
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
				'meta_input'    => $meta + [
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
 * Returns what a list table cell prints for a report.
 *
 * @param string $column Column key.
 * @param int    $id     Report ID.
 * @return string HTML.
 */
function hm_test_admin_cell( string $column, int $id ): string {
	ob_start();
	do_action( 'manage_' . Reports\POST_TYPE . '_posts_custom_column', $column, $id );

	return (string) ob_get_clean();
}

/**
 * Runs a callback on an admin screen, then puts the screen globals back as they were.
 *
 * @param string   $screen   Screen ID, such as `edit-uat_report`.
 * @param callable $callback Code to run.
 * @return mixed What it returned.
 */
function hm_test_on_screen( string $screen, callable $callback ) {
	$saved = [];

	foreach ( [ 'current_screen', 'typenow', 'taxnow', 'hook_suffix' ] as $name ) {
		$saved[ $name ] = array_key_exists( $name, $GLOBALS ) ? [ $GLOBALS[ $name ] ] : null;
	}

	set_current_screen( $screen );

	try {
		return $callback();
	} finally {
		foreach ( $saved as $name => $value ) {
			if ( $value === null ) {
				unset( $GLOBALS[ $name ] );
				continue;
			}

			$GLOBALS[ $name ] = $value[0];
		}
	}
}

/**
 * Returns the IDs a query finds when it is the screen's main query, as core's edit.php runs it.
 *
 * @param array<string, mixed> $args Query arguments.
 * @return int[] IDs, in order.
 */
function hm_test_admin_list( array $args ): array {
	$previous = $GLOBALS['wp_the_query'];
	$query    = new WP_Query();

	$GLOBALS['wp_the_query'] = $query;

	try {
		$query->query(
			$args + [
				'post_type'      => Reports\POST_TYPE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);
	} finally {
		$GLOBALS['wp_the_query'] = $previous;
	}

	return array_map( 'intval', $query->posts );
}

/**
 * Registers the read-only report page for the current user, runs a callback, then puts the menu globals back.
 *
 * @param callable $callback Code to run.
 * @return mixed What it returned.
 */
function hm_test_with_view_page( callable $callback ) {
	$names = [ 'menu', 'submenu', '_registered_pages', '_wp_submenu_nopriv', '_wp_menu_nopriv', 'admin_page_hooks', 'pagenow', 'plugin_page', 'parent_file' ];
	$saved = [];

	foreach ( $names as $name ) {
		$saved[ $name ] = array_key_exists( $name, $GLOBALS ) ? [ $GLOBALS[ $name ] ] : null;
	}

	// Core builds these as arrays while it builds the admin menu, which a CLI run has not done.
	foreach ( [ 'menu', 'submenu', '_registered_pages', '_wp_submenu_nopriv', '_wp_menu_nopriv', 'admin_page_hooks' ] as $name ) {
		$GLOBALS[ $name ] = $GLOBALS[ $name ] ?? [];
	}

	$GLOBALS['pagenow']     = 'admin.php';
	$GLOBALS['plugin_page'] = Report_Admin\VIEW_PAGE;
	unset( $GLOBALS['parent_file'] );

	Report_Admin\add_view_page();

	try {
		return $callback( get_plugin_page_hookname( Report_Admin\VIEW_PAGE, '' ) );
	} finally {
		remove_all_actions( get_plugin_page_hookname( Report_Admin\VIEW_PAGE, '' ) );

		foreach ( $saved as $name => $value ) {
			if ( $value === null ) {
				unset( $GLOBALS[ $name ] );
				continue;
			}

			$GLOBALS[ $name ] = $value[0];
		}
	}
}

/**
 * Returns the page a user would be shown at the read-only report address.
 *
 * @param string $hook      Hook the page is registered on.
 * @param int    $report_id Report ID, or 0 for none named.
 * @return string HTML.
 */
function hm_test_view_page_html( string $hook, int $report_id ): string {
	$previous = $_GET;

	$_GET['report'] = (string) $report_id;

	try {
		ob_start();
		do_action( $hook );

		return (string) ob_get_clean();
	} finally {
		$_GET = $previous;
	}
}

/**
 * Runs a callback with wp_die() throwing, so a refusal can be read, and returns the message wp_die() was given.
 *
 * @param callable $callback Code that may call wp_die().
 * @return string|null The message, or null when wp_die() was not called.
 */
function hm_test_admin_dying( callable $callback ): ?string {
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

/**
 * Returns a fresh address at the staff domain, which makes its user a UAT manager.
 *
 * @return string Address.
 */
function hm_test_admin_staff_email(): string {
	return 'uat-' . wp_generate_password( 8, false ) . '@humanmade.com';
}

$typed   = 'Click <button> & save, it\'s "broken"';
$created = [];

try {
	hm_test_as_user(
		'subscriber',
		static function ( int $tester ) use ( $typed, &$created ): void {
			$tester_name = (string) get_userdata( $tester )?->display_name;
			$pending     = hm_test_admin_report( $tester, 'pending', $typed );
			$investigated = hm_test_admin_report( $tester, 'uat-investigating', 'Looking into it', [ Reports\META_PAGE_TITLE => '' ] );
			$done        = hm_test_admin_report( $tester, 'uat-done', 'All fixed', [ Reports\META_PAGE_URL => '' ] );
			$trashed     = hm_test_admin_report( $tester, 'pending', 'Thrown away' );
			$orphan      = hm_test_admin_report( 0, 'pending', 'From a user who is gone' );
			$created     = [ $pending->ID, $investigated->ID, $done->ID, $trashed->ID, $orphan->ID ];

			wp_trash_post( $trashed->ID );

			$png    = imagecreatetruecolor( 1, 1 );
			ob_start();
			imagepng( $png );
			$stored = wp_upload_bits( 'uat-test-report-admin.png', null, (string) ob_get_clean() );
			$shot   = Screenshots\attach(
				[
					'file' => $stored['file'],
					'url'  => $stored['url'],
					'type' => 'image/png',
				],
				$pending
			);

			check(
				'the columns replace the author and date with the report\'s own',
				array_keys( (array) apply_filters( 'manage_' . Reports\POST_TYPE . '_posts_columns', [ 'cb' => '<input>', 'title' => 'Title', 'author' => 'Author', 'date' => 'Date' ] ) ),
				[ 'cb', 'uat_report', 'uat_reporter', 'uat_page', 'uat_status', 'uat_screenshot', 'uat_date' ]
			);
			check(
				'only the date sorts',
				apply_filters( 'manage_edit-' . Reports\POST_TYPE . '_sortable_columns', [] ),
				[ 'uat_date' => [ 'date', true ] ]
			);
			check(
				'the Report column is the primary one on the reports list and no other',
				[ apply_filters( 'list_table_primary_column', 'title', 'edit-' . Reports\POST_TYPE ), apply_filters( 'list_table_primary_column', 'title', 'edit-page' ) ],
				[ 'uat_report', 'title' ]
			);
			check(
				'Quick Edit is off for reports and on for pages',
				[ apply_filters( 'quick_edit_enabled_for_post_type', true, Reports\POST_TYPE ), apply_filters( 'quick_edit_enabled_for_post_type', true, 'page' ) ],
				[ false, true ]
			);

			hm_test_as_user(
				'editor',
				static function () use ( $tester, $tester_name, $typed, $pending, $investigated, $done, $trashed, $orphan, $shot ): void {
					$report = hm_test_admin_cell( 'uat_report', $pending->ID );
					check( 'the Report cell links a manager to the edit screen', str_contains( $report, 'post.php?post=' . $pending->ID ), true );
					check( 'it shows the text the tester typed as text, not markup', [ str_contains( $report, '&lt;button&gt;' ), str_contains( $report, '<button' ) ], [ true, false ] );
					check( 'and the start of the description', str_contains( $report, 'uat-report-admin__excerpt' ), true );

					check( 'the Reporter cell is the tester\'s name', hm_test_admin_cell( 'uat_reporter', $pending->ID ), esc_html( $tester_name ) );
					check( 'and for a report whose user is gone, a dash read aloud as None', str_contains( hm_test_admin_cell( 'uat_reporter', $orphan->ID ), 'screen-reader-text">None' ), true );

					check(
						'the Page cell links the page by its title',
						hm_test_admin_cell( 'uat_page', $pending->ID ),
						'<a href="' . esc_url( home_url( '/a-page/' ) ) . '">A page</a>'
					);
					check(
						'or by its address when it has no title',
						hm_test_admin_cell( 'uat_page', $investigated->ID ),
						'<a href="' . esc_url( home_url( '/a-page/' ) ) . '">' . esc_html( home_url( '/a-page/' ) ) . '</a>'
					);
					check( 'and None when it has no address', str_contains( hm_test_admin_cell( 'uat_page', $done->ID ), 'screen-reader-text">None' ), true );

					check(
						'the Status cell has the label of each status, and of the trash',
						[
							hm_test_admin_cell( 'uat_status', $pending->ID ),
							hm_test_admin_cell( 'uat_status', $investigated->ID ),
							hm_test_admin_cell( 'uat_status', $done->ID ),
							hm_test_admin_cell( 'uat_status', $trashed->ID ),
						],
						[ 'Pending', 'Investigating', 'Done', 'Trash' ]
					);

					$cell = hm_test_admin_cell( 'uat_screenshot', $pending->ID );
					check(
						'the Screenshot cell is a thumbnail opening the file in a new tab',
						[ str_contains( $cell, '<img' ), str_contains( $cell, 'target="_blank"' ), str_contains( $cell, 'rel="noopener"' ), str_contains( $cell, wp_get_attachment_url( (int) $shot ) ) ],
						[ true, true, true, true ]
					);
					check( 'with the tester\'s name as its alt text', str_contains( $cell, 'alt="Screenshot from ' . esc_attr( $tester_name ) . '"' ), true );
					check( 'and None when there is no screenshot', str_contains( hm_test_admin_cell( 'uat_screenshot', $investigated->ID ), 'screen-reader-text">None' ), true );

					check(
						'the Reported cell is the date in the site\'s format',
						hm_test_admin_cell( 'uat_date', $pending->ID ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_post_timestamp( $pending ) ) )
					);
					check( 'a column that is not the report\'s prints nothing', hm_test_admin_cell( 'title', $pending->ID ), '' );

					$actions = Report_Admin\row_actions( [ 'trash' => 'Trash' ], $pending );
					check( 'a manager gets the other two statuses as row actions', array_keys( $actions ), [ 'trash', 'uat_report_status' ] );
					check(
						'as buttons for the two it is not in',
						[
							str_contains( $actions['uat_report_status'], 'data-uat-report-status="uat-investigating"' ),
							str_contains( $actions['uat_report_status'], 'data-uat-report-status="uat-done"' ),
							str_contains( $actions['uat_report_status'], 'data-uat-report-status="pending"' ),
							str_contains( $actions['uat_report_status'], 'data-uat-report-id="' . $pending->ID . '"' ),
						],
						[ true, true, false, true ]
					);
					check( 'a trashed report gets none', array_keys( Report_Admin\row_actions( [], get_post( $trashed->ID ) ) ), [] );
					hm_test_with_page(
						[ 'post_title' => 'A page' ],
						static function ( int $page_id ): void {
							check( 'nor does a page', Report_Admin\row_actions( [], get_post( $page_id ) ), [] );
						}
					);

					$bulk = (array) apply_filters( 'bulk_actions-edit-' . Reports\POST_TYPE, [ 'edit' => 'Edit', 'trash' => 'Move to Trash' ] );
					check(
						'a manager\'s bulk actions swap Edit for a move to each status',
						array_keys( $bulk ),
						[ 'trash', 'uat_report_mark_pending', 'uat_report_mark_uat-investigating', 'uat_report_mark_uat-done' ]
					);
					check( 'labelled with the status', $bulk['uat_report_mark_uat-done'] ?? null, 'Mark as Done' );

					$previous = $_GET;
					$_GET['post_status'] = 'trash';
					check(
						'the trash view has no moves, and no Edit',
						array_keys( (array) apply_filters( 'bulk_actions-edit-' . Reports\POST_TYPE, [ 'edit' => 'Edit', 'untrash' => 'Restore' ] ) ),
						[ 'untrash' ]
					);
					$_GET = $previous;

					ob_start();
					Report_Admin\render_status_box( $pending );
					$box = (string) ob_get_clean();
					check( 'the status box is a select of the three statuses', preg_match_all( '/<option value="([^"]+)"/', $box, $options ) ? $options[1] : [], [ 'pending', 'uat-investigating', 'uat-done' ] );
					check( 'with the report\'s own selected', (bool) preg_match( '/<option value="pending" selected=\'selected\'>/', $box ), true );
					check( 'and a button that names the report', str_contains( $box, 'id="uat-report-status-update" data-uat-report-id="' . $pending->ID . '"' ), true );

					ob_start();
					Report_Admin\render_details_box( $pending );
					$details = (string) ob_get_clean();
					check(
						'the details box shows the description as text, with the page and the screenshot',
						[
							str_contains( $details, 'Click &lt;button&gt; &amp; save, it&#039;s &quot;broken&quot;' ),
							str_contains( $details, home_url( '/a-page/' ) ),
							str_contains( $details, '<img' ),
							str_contains( $details, 'Open full size' ),
						],
						[ true, true, true, true ]
					);
					check( 'and no control', [ str_contains( $details, '<select' ), str_contains( $details, '<button' ), str_contains( $details, '<input' ) ], [ false, false, false ] );

					$boxes_before = $GLOBALS['wp_meta_boxes'] ?? null;
					Report_Admin\add_meta_boxes();
					$boxes = $GLOBALS['wp_meta_boxes'][ Reports\POST_TYPE ] ?? [];
					check(
						'the edit screen has the details and status boxes, and no Publish or Slug box',
						[
							isset( $boxes['normal']['high']['uat-report-details'] ),
							isset( $boxes['side']['high']['uat-report-status'] ),
							$boxes['side']['core']['submitdiv'] ?? $boxes['side']['high']['submitdiv'] ?? false,
							$boxes['normal']['core']['slugdiv'] ?? false,
						],
						[ true, true, false, false ]
					);

					if ( $boxes_before === null ) {
						unset( $GLOBALS['wp_meta_boxes'][ Reports\POST_TYPE ] );
					} else {
						$GLOBALS['wp_meta_boxes'] = $boxes_before;
					}

					hm_test_on_screen(
						'edit-' . Reports\POST_TYPE,
						static function () use ( $pending, $investigated, $done ): void {
							check( 'the screen is the reports list', get_current_screen()?->post_type, Reports\POST_TYPE );

							$all = hm_test_admin_list( [] );
							check(
								'All lists every report but the trashed one',
								[ in_array( $pending->ID, $all, true ), in_array( $investigated->ID, $all, true ), in_array( $done->ID, $all, true ) ],
								[ true, true, true ]
							);
							check(
								'a status view lists only that status',
								array_values( array_intersect( hm_test_admin_list( [ 'post_status' => 'uat-investigating' ] ), [ $pending->ID, $investigated->ID, $done->ID ] ) ),
								[ $investigated->ID ]
							);
							check( 'the trash view lists the trashed report', count( hm_test_admin_list( [ 'post_status' => 'trash' ] ) ) >= 1, true );

							// What wp_edit_posts_query() sets for the list table to read.
							$had_stati                    = array_key_exists( 'avail_post_stati', $GLOBALS ) ? [ $GLOBALS['avail_post_stati'] ] : null;
							$GLOBALS['avail_post_stati'] = get_available_post_statuses( Reports\POST_TYPE );

							try {
								$table = _get_list_table( 'WP_Posts_List_Table', [ 'screen' => 'edit-' . Reports\POST_TYPE ] );
								ob_start();
								$table->views();
								$views = (string) ob_get_clean();
							} finally {
								if ( $had_stati === null ) {
									unset( $GLOBALS['avail_post_stati'] );
								} else {
									$GLOBALS['avail_post_stati'] = $had_stati[0];
								}
							}

							check(
								'the status views link each status and the trash',
								[
									str_contains( $views, 'post_status=pending' ),
									str_contains( $views, 'post_status=uat-investigating' ),
									str_contains( $views, 'post_status=uat-done' ),
									str_contains( $views, 'post_status=trash' ),
								],
								[ true, true, true, true ]
							);
							check(
								'labelled Pending, Investigating and Done',
								[ str_contains( $views, 'Pending' ), str_contains( $views, 'Investigating' ), str_contains( $views, 'Done' ) ],
								[ true, true, true ]
							);
						}
					);
				},
				hm_test_admin_staff_email()
			);

			hm_test_as_user(
				'contributor',
				static function () use ( $pending ): void {
					check( 'a tester\'s Report cell links to the read-only page', (bool) preg_match( '/page=uat-report-view(&#038;|&amp;|&)report=' . $pending->ID . '/', hm_test_admin_cell( 'uat_report', $pending->ID ) ), true );
					check( 'and gets no row action', Report_Admin\row_actions( [], $pending ), [] );
					check(
						'nor any bulk move',
						array_keys( (array) apply_filters( 'bulk_actions-edit-' . Reports\POST_TYPE, [ 'edit' => 'Edit', 'trash' => 'Trash' ] ) ),
						[ 'trash' ]
					);
				}
			);

			$plain = wp_insert_post(
				[
					'post_type'   => 'page',
					'post_status' => 'pending',
					'post_title'  => 'A pending page',
				]
			);
			wp_trash_post( $plain );
			wp_untrash_post( $plain );
			check( 'a page that was pending is restored as a draft, as core does', get_post_status( $plain ), 'draft' );
			wp_delete_post( $plain, true );
		}
	);

	echo "\nSearching the reports list\n";

	hm_test_as_user(
		'subscriber',
		static function ( int $tester ) use ( &$created ): void {
			$button  = hm_test_admin_report( $tester, 'pending', 'Click <button> & save, it\'s "broken"' );
			$quote   = hm_test_admin_report( $tester, 'uat-done', 'Say "hello" to <em>everyone</em>' );
			$other   = hm_test_admin_report( $tester, 'pending', 'Nothing to see' );
			$created = array_merge( $created, [ $button->ID, $quote->ID, $other->ID ] );

			hm_test_on_screen(
				'edit-' . Reports\POST_TYPE,
				static function () use ( $button, $quote, $other ): void {
					$search = static fn ( string $term ): array => array_values(
						array_intersect( hm_test_admin_list( [ 's' => $term ] ), [ $button->ID, $quote->ID, $other->ID ] )
					);

					check( 'a search for <button> & finds the report that has them', $search( '<button> &' ), [ $button->ID ] );
					check( 'a search for a tag alone', $search( '<em>' ), [ $quote->ID ] );
					check( 'a search for text with quotes', $search( 'it\'s "broken"' ), [ $button->ID ] );
					check( 'a search for double quotes', $search( '"hello"' ), [ $quote->ID ] );
					check( 'a search for plain text', $search( 'Nothing' ), [ $other->ID ] );
					check( 'a search for text no report has', $search( '<blink>' ), [] );

					$hook = 'HM\\UAT_Checklist\\Report_Admin\\encode_search';
					remove_action( 'pre_get_posts', $hook );

					try {
						check( 'without the encoding, a search for <button> would find nothing, which is why it is applied', $search( '<button> &' ), [] );
					} finally {
						add_action( 'pre_get_posts', $hook );
					}

					$page_search = new WP_Query(
						[
							'post_type'   => 'page',
							's'           => 'Tom & Jerry',
							'fields'      => 'ids',
							'post_status' => 'any',
						]
					);
					check( 'the term is left as typed for other queries than the reports list', $page_search->get( 's' ), 'Tom & Jerry' );

					$secondary = new WP_Query(
						[
							'post_type' => Reports\POST_TYPE,
							's'         => 'Tom & Jerry',
							'fields'    => 'ids',
						]
					);
					check( 'and for a query that is not the main one', $secondary->get( 's' ), 'Tom & Jerry' );
				}
			);

			$front = new WP_Query(
				[
					'post_type' => Reports\POST_TYPE,
					's'         => 'Tom & Jerry',
					'fields'    => 'ids',
				]
			);
			check( 'and outside the admin', $front->get( 's' ), 'Tom & Jerry' );
		}
	);

	echo "\nThe edit screen is locked\n";

	check(
		'the lock is hooked to the edit screen',
		has_action( 'load-post.php', 'HM\\UAT_Checklist\\Report_Admin\\lock_edit_screen' ),
		10
	);

	$supports = [ 'title', 'editor', 'author' ];
	$method   = $_SERVER['REQUEST_METHOD'] ?? null;

	try {
		foreach ( [ [ 'a manager', 'editor', hm_test_admin_staff_email() ], [ 'a tester', 'contributor', '' ] ] as [ $who, $role, $email ] ) {
			hm_test_as_user(
				$role,
				static function () use ( $who, $supports ): void {
					hm_test_on_screen(
						Reports\POST_TYPE,
						static function () use ( $who, $supports ): void {
							check( "the edit screen of {$who} is a report's", get_current_screen()?->post_type, Reports\POST_TYPE );

							$_SERVER['REQUEST_METHOD'] = 'POST';
							$died                      = hm_test_admin_dying( 'HM\\UAT_Checklist\\Report_Admin\\lock_edit_screen' );
							check( "a save from {$who} is refused", $died, 'A report cannot be edited. Only its status can change.' );
							check(
								'and the report type still supports its fields',
								array_map( static fn ( string $feature ): bool => post_type_supports( Reports\POST_TYPE, $feature ), $supports ),
								[ true, true, true ]
							);

							$_SERVER['REQUEST_METHOD'] = 'GET';

							try {
								$opened = hm_test_admin_dying( 'HM\\UAT_Checklist\\Report_Admin\\lock_edit_screen' );
								check( "opening the screen as {$who} is not refused", $opened, null );
								check(
									'and removes the title, editor and author from it',
									array_map( static fn ( string $feature ): bool => post_type_supports( Reports\POST_TYPE, $feature ), $supports ),
									[ false, false, false ]
								);
							} finally {
								add_post_type_support( Reports\POST_TYPE, $supports );
							}
						}
					);
				},
				$email
			);
		}

		hm_test_on_screen(
			'page',
			static function (): void {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				check( 'a save of a page is not refused', hm_test_admin_dying( 'HM\\UAT_Checklist\\Report_Admin\\lock_edit_screen' ), null );
			}
		);

		check(
			'the report type supports its fields again after the checks',
			array_map( static fn ( string $feature ): bool => post_type_supports( Reports\POST_TYPE, $feature ), $supports ),
			[ true, true, true ]
		);
	} finally {
		if ( $method === null ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $method;
		}
	}

	echo "\nThe read-only page\n";

	hm_test_as_user(
		'subscriber',
		static function ( int $tester ) use ( &$created ): void {
			$report = hm_test_admin_report( $tester, 'pending', 'Click <button> & save' );
			$gone   = hm_test_admin_report( $tester, 'pending', 'Deleted' );
			wp_trash_post( $gone->ID );
			$created = array_merge( $created, [ $report->ID, $gone->ID ] );

			$png    = imagecreatetruecolor( 1, 1 );
			ob_start();
			imagepng( $png );
			$stored = wp_upload_bits( 'uat-test-report-view.png', null, (string) ob_get_clean() );
			$shot   = Screenshots\attach(
				[
					'file' => $stored['file'],
					'url'  => $stored['url'],
					'type' => 'image/png',
				],
				$report
			);

			hm_test_as_user(
				'contributor',
				static function () use ( $report, $gone, $shot ): void {
					hm_test_with_view_page(
						static function ( string $hook ) use ( $report, $gone, $shot ): void {
							check( 'the page is registered for a tester', ! empty( $GLOBALS['_registered_pages'][ $hook ] ), true );
							check( 'with no menu entry', isset( $GLOBALS['submenu'][ Report_Admin\VIEW_PAGE ] ), false );
							check( 'and a tester can open it', user_can_access_admin_page(), true );
							check( 'at the read-only address', (bool) preg_match( '/page=uat-report-view/', Report_Admin\view_url( $report->ID ) ), true );

							$html = hm_test_view_page_html( $hook, $report->ID );
							check( 'it shows the description as text', str_contains( $html, 'Click &lt;button&gt; &amp; save' ), true );
							check( 'and the screenshot', [ str_contains( $html, '<img' ), str_contains( $html, wp_get_attachment_url( (int) $shot ) ) ], [ true, true ] );
							check( 'and the page the report came from', str_contains( $html, home_url( '/a-page/' ) ), true );
							check(
								'it has no status control and no button',
								[
									str_contains( $html, '<select' ),
									str_contains( $html, '<button' ),
									str_contains( $html, '<input' ),
									str_contains( $html, '<form' ),
									str_contains( $html, 'uat-report-status' ),
									str_contains( $html, 'data-uat-report-id' ),
								],
								[ false, false, false, false, false, false ]
							);

							check(
								'a report that is not there is a 404',
								hm_test_admin_dying( static fn () => hm_test_view_page_html( $hook, 999999999 ) ),
								'That report cannot be found.'
							);
							check(
								'so is one in the trash',
								hm_test_admin_dying( static fn () => hm_test_view_page_html( $hook, $gone->ID ) ),
								'That report cannot be found.'
							);
							check(
								'and an address that names no report',
								hm_test_admin_dying( static fn () => hm_test_view_page_html( $hook, 0 ) ),
								'That report cannot be found.'
							);
						}
					);
				}
			);

			hm_test_as_user(
				'subscriber',
				static function (): void {
					hm_test_with_view_page(
						static function (): void {
							check( 'a Subscriber, who cannot read results, cannot open it', user_can_access_admin_page(), false );
						}
					);
				}
			);
		}
	);

	echo "\nThe status script\n";

	$handle = Report_Admin\HANDLE;
	$asset  = require dirname( __DIR__, 2 ) . '/build/report-admin.asset.php';
	$enqueue = static function ( string $screen ) use ( $handle ): array {
		hm_test_on_screen(
			$screen,
			static function (): void {
				Report_Admin\enqueue_script();
			}
		);

		$state = [
			wp_script_is( $handle, 'enqueued' ),
			(array) ( wp_scripts()->registered[ $handle ]->deps ?? [] ),
			(array) wp_scripts()->get_data( $handle, 'before' ),
			wp_scripts()->get_data( $handle, 'strategy' ),
			wp_scripts()->get_data( $handle, 'group' ),
		];

		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );

		return $state;
	};

	hm_test_as_user(
		'editor',
		static function () use ( $enqueue, $asset ): void {
			foreach ( [ 'edit-' . Reports\POST_TYPE, Reports\POST_TYPE ] as $screen ) {
				[ $queued, $deps, $before, $strategy, $group ] = $enqueue( $screen );

				check( "the script is enqueued for a manager on {$screen}", $queued, true );
				check( 'it depends on what the build says', $deps, $asset['dependencies'] );
				check( 'including wp-a11y, which announces a change', in_array( 'wp-a11y', $deps, true ), true );
				check( 'it loads deferred, in the footer', [ $strategy, $group ], [ 'defer', 1 ] );

				$inline = implode( '', $before );
				$data   = preg_match( '/^window\.hmUatReportAdmin = (\{.*\});$/s', $inline, $match ) ? json_decode( $match[1], true ) : null;
				check( 'it defines window.hmUatReportAdmin before the script', is_array( $data ), true );
				check(
					'with the route, the bulk prefix, the statuses and the labels of the moves',
					$data,
					[
						'route'      => '/uat-checklist/v1/reports',
						'bulkPrefix' => 'uat_report_mark_',
						'statuses'   => [
							'pending'           => 'Pending',
							'uat-investigating' => 'Investigating',
							'uat-done'          => 'Done',
						],
						'marks'      => [
							'pending'           => 'Mark as Pending',
							'uat-investigating' => 'Mark as Investigating',
							'uat-done'          => 'Mark as Done',
						],
					]
				);
			}

			check( 'it is not enqueued on the pages list', $enqueue( 'edit-page' )[0], false );
			check( 'nor on the dashboard', $enqueue( 'dashboard' )[0], false );
		},
		hm_test_admin_staff_email()
	);

	hm_test_as_user(
		'contributor',
		static function () use ( $enqueue ): void {
			check( 'it is not enqueued for a tester, who has nothing to change', $enqueue( 'edit-' . Reports\POST_TYPE )[0], false );
		}
	);
} finally {
	foreach ( $created as $report_id ) {
		wp_delete_post( $report_id, true );
	}
}
