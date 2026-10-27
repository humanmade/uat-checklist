<?php
/**
 * Issue reports: the report post type, its statuses, the submit route and its screenshot.
 *
 * A report is stored in post columns. The tester is `post_author`, the description is `post_content` (entity-encoded,
 * see `describe()`), and Pending, Investigating or Done is the post status. The page the tester was on is three meta
 * keys. The screenshot is an image attachment whose parent is the report, stored by Screenshots under the same rules as
 * a result's. A report is a record: only `submit()` writes one, and only `set_status()` changes one.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Reports;

use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Overview;
use HM\UAT_Checklist\REST;
use HM\UAT_Checklist\Screenshots;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

const POST_TYPE = 'uat_report';

/**
 * Post status of a report no one has looked at. Core's own Pending, so it already has an admin status link.
 */
const STATUS_PENDING = 'pending';

const STATUS_INVESTIGATING = 'uat-investigating';

const STATUS_DONE = 'uat-done';

/**
 * Every status a report can have, in the order a report moves through them.
 */
const STATUSES = [ STATUS_PENDING, STATUS_INVESTIGATING, STATUS_DONE ];

/**
 * Meta keys for the page the tester was on when they sent the report.
 */
const META_PAGE_URL = '_uat_report_page_url';

const META_PAGE_ID = '_uat_report_page_id';

const META_PAGE_TITLE = '_uat_report_page_title';

const DESCRIPTION_MAX_LENGTH = 5000;

const PAGE_URL_MAX_LENGTH = 2000;

const PAGE_TITLE_MAX_LENGTH = 200;

/**
 * Seconds a tester's report lock is held at most, so a request that dies mid-save cannot block them for long.
 */
const LOCK_TTL = 10;

/**
 * Cache group for the per-tester report locks.
 */
const LOCK_GROUP = 'hm_uat_checklist_report_locks';

/**
 * Characters of the description kept as the report's title.
 */
const TITLE_LENGTH = 60;

/**
 * Most reports one tester can send in DAILY_WINDOW.
 */
const DAILY_LIMIT = 20;

/**
 * Period DAILY_LIMIT is counted over, in seconds.
 */
const DAILY_WINDOW = DAY_IN_SECONDS;

/**
 * Most reports one bulk status request can name.
 */
const MAX_BULK_IDS = 50;

/**
 * Reports query() returns in a page when it is not told how many.
 */
const DEFAULT_PER_PAGE = 20;

/**
 * Most reports query() returns in a page.
 */
const MAX_PER_PAGE = 50;

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register_post_type_and_statuses' );
	add_action( 'init', __NAMESPACE__ . '\\register_meta_keys' );
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
	add_filter( 'map_meta_cap', __NAMESPACE__ . '\\restrict_report_caps', 10, 4 );
	add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\\keep_report_fields', 10, 4 );
}

/**
 * Registers the admin-only report post type and its Investigating and Done statuses.
 *
 * Capabilities follow the results': anyone who holds Access\CAP_READ can read every report, a manager changes
 * their status, and nothing is created from the admin.
 *
 * @return void
 */
function register_post_type_and_statuses(): void {
	register_post_type(
		POST_TYPE,
		[
			'labels'          => [
				'name'               => __( 'UAT issue reports', 'uat-checklist' ),
				'singular_name'      => __( 'UAT issue report', 'uat-checklist' ),
				'all_items'          => __( 'Issue reports', 'uat-checklist' ),
				'edit_item'          => __( 'Edit issue report', 'uat-checklist' ),
				'view_item'          => __( 'View issue report', 'uat-checklist' ),
				'search_items'       => __( 'Search issue reports', 'uat-checklist' ),
				'not_found'          => __( 'No issue reports yet.', 'uat-checklist' ),
				'not_found_in_trash' => __( 'No issue reports in the trash.', 'uat-checklist' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => Overview\PAGE_SLUG,
			'show_in_rest'    => false,
			'supports'        => [ 'title', 'editor', 'author' ],
			'rewrite'         => false,
			'query_var'       => false,
			'map_meta_cap'    => true,
			'capability_type' => 'post',
			'capabilities'    => [
				'create_posts'           => 'do_not_allow',
				'edit_posts'             => Access\CAP_READ,
				'read_private_posts'     => Access\CAP_READ,
				'edit_others_posts'      => Access\CAP_MANAGE,
				'edit_published_posts'   => Access\CAP_MANAGE,
				'edit_private_posts'     => Access\CAP_MANAGE,
				'publish_posts'          => Access\CAP_MANAGE,
				'delete_posts'           => Access\CAP_MANAGE,
				'delete_others_posts'    => Access\CAP_MANAGE,
				'delete_published_posts' => Access\CAP_MANAGE,
				'delete_private_posts'   => Access\CAP_MANAGE,
			],
		]
	);

	$status_args = [
		'public'                    => false,
		'protected'                 => true,
		'exclude_from_search'       => true,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
	];

	register_post_status(
		STATUS_INVESTIGATING,
		$status_args + [
			'label'       => status_label( STATUS_INVESTIGATING ),
			/* translators: %s: number of reports being investigated. */
			'label_count' => _n_noop(
				'Investigating <span class="count">(%s)</span>',
				'Investigating <span class="count">(%s)</span>',
				'uat-checklist'
			),
		]
	);

	register_post_status(
		STATUS_DONE,
		$status_args + [
			'label'       => status_label( STATUS_DONE ),
			/* translators: %s: number of reports that are done. */
			'label_count' => _n_noop(
				'Done <span class="count">(%s)</span>',
				'Done <span class="count">(%s)</span>',
				'uat-checklist'
			),
		]
	);
}

/**
 * Registers the three meta keys that record the page a report was sent from, changeable by managers only.
 *
 * @return void
 */
function register_meta_keys(): void {
	$args = [
		'object_subtype' => POST_TYPE,
		'single'         => true,
		'show_in_rest'   => false,
		'auth_callback'  => __NAMESPACE__ . '\\can_triage',
	];

	register_post_meta(
		POST_TYPE,
		META_PAGE_URL,
		$args + [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
		]
	);

	register_post_meta(
		POST_TYPE,
		META_PAGE_ID,
		$args + [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		]
	);

	register_post_meta(
		POST_TYPE,
		META_PAGE_TITLE,
		$args + [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		]
	);
}

/**
 * Returns whether the current user may send a report: anyone logged in.
 *
 * @return bool Whether they may.
 */
function can_submit(): bool {
	return is_user_logged_in();
}

/**
 * Returns whether the current user may read every report and change its status.
 *
 * @return bool Whether they may.
 */
function can_triage(): bool {
	return current_user_can( Access\CAP_MANAGE );
}

/**
 * Returns the translated label for a report status, or '' for anything that is not one.
 *
 * @param string $status One of STATUSES.
 * @return string Label.
 */
function status_label( string $status ): string {
	$labels = [
		STATUS_PENDING       => __( 'Pending', 'uat-checklist' ),
		STATUS_INVESTIGATING => __( 'Investigating', 'uat-checklist' ),
		STATUS_DONE          => __( 'Done', 'uat-checklist' ),
	];

	return $labels[ $status ] ?? '';
}

/**
 * Returns a report's screenshot, or null when it has none.
 *
 * @param WP_Post $report Report.
 * @return WP_Post|null Attachment.
 */
function screenshot_of( WP_Post $report ): ?WP_Post {
	return Screenshots\of( $report );
}

/**
 * Returns the report with an ID, or null when there is none or it is not in a status a report moves through.
 *
 * The one guard for reaching a report by ID: a trashed report and any other post are null.
 *
 * @param int $report_id Post ID.
 * @return WP_Post|null Report.
 */
function find_report( int $report_id ): ?WP_Post {
	// Not get_post( 0 ), which returns the current post.
	$post = $report_id > 0 ? get_post( $report_id ) : null;

	return $post instanceof WP_Post && $post->post_type === POST_TYPE && in_array( $post->post_status, STATUSES, true )
		? $post
		: null;
}

/**
 * Warms the caches describe() reads for a list of reports, so a page of them costs a fixed number of queries.
 *
 * Optional: describe() reads the same data a report at a time without it.
 *
 * @param WP_Post[] $reports Reports.
 * @return void
 */
function prime( array $reports ): void {
	if ( $reports === [] ) {
		return;
	}

	$report_ids = array_map( fn( WP_Post $report ) => $report->ID, $reports );

	update_meta_cache( 'post', $report_ids );
	cache_users( array_map( fn( WP_Post $report ) => (int) $report->post_author, $reports ) );
	Screenshots\prime( $report_ids );

	$attachment_ids = [];

	foreach ( $reports as $report ) {
		$attachment = screenshot_of( $report );

		if ( $attachment !== null ) {
			$attachment_ids[] = $attachment->ID;
		}
	}

	update_meta_cache( 'post', $attachment_ids );
}

/**
 * Returns a report as the plain data the panel, the list and the abilities share.
 *
 * The description is what the tester typed, decoded from its stored form: escape it wherever it is printed.
 *
 * @param WP_Post $report          Report.
 * @param bool    $with_screenshot Whether to read the screenshot's details. When false, `screenshot` is null and only
 *                                 `has_screenshot` says whether there is one.
 * @return array{
 *     id: int,
 *     status: string,
 *     status_label: string,
 *     date: string,
 *     description: string,
 *     reporter: array{id: int, name: string},
 *     page: array{url: string, id: int, title: string},
 *     has_screenshot: bool,
 *     screenshot: array{id: int, url: string, mime: string, width: int, height: int, bytes: int}|null
 * } The report, with its date in ISO 8601 and UTC.
 */
function describe( WP_Post $report, bool $with_screenshot = true ): array {
	$attachment = screenshot_of( $report );
	$screenshot = null;

	if ( $with_screenshot && $attachment !== null ) {
		$metadata   = wp_get_attachment_metadata( $attachment->ID );
		$screenshot = [
			'id'     => $attachment->ID,
			'url'    => (string) wp_get_attachment_url( $attachment->ID ),
			'mime'   => $attachment->post_mime_type,
			'width'  => (int) ( $metadata['width'] ?? 0 ),
			'height' => (int) ( $metadata['height'] ?? 0 ),
			'bytes'  => (int) wp_filesize( (string) get_attached_file( $attachment->ID ) ),
		];
	}

	return [
		'id'             => $report->ID,
		'status'         => $report->post_status,
		'status_label'   => status_label( $report->post_status ),
		'date'           => (string) get_post_time( 'c', true, $report ),
		'description'    => htmlspecialchars_decode( $report->post_content, ENT_QUOTES | ENT_HTML5 ),
		'reporter'       => [
			'id'   => (int) $report->post_author,
			'name' => (string) get_the_author_meta( 'display_name', (int) $report->post_author ),
		],
		'page'           => [
			'url'   => (string) get_post_meta( $report->ID, META_PAGE_URL, true ),
			'id'    => (int) get_post_meta( $report->ID, META_PAGE_ID, true ),
			'title' => (string) get_post_meta( $report->ID, META_PAGE_TITLE, true ),
		],
		'has_screenshot' => $attachment !== null,
		'screenshot'     => $screenshot,
	];
}

/**
 * Moves a report to another status, leaving its title, content, author and date as they are.
 *
 * Refused for a report in the trash, which has no status to move from. The slug is set to the report's ID, because
 * core would otherwise make one from the encoded title when a Pending report, which has none, first moves.
 *
 * @param WP_Post $report Report.
 * @param string  $status One of STATUSES.
 * @return true|WP_Error True once saved, or why it was refused.
 */
function set_status( WP_Post $report, string $status ): true|WP_Error {
	if ( $report->post_type !== POST_TYPE ) {
		return new WP_Error(
			'uat_checklist_not_a_report',
			__( 'This is not an issue report.', 'uat-checklist' ),
			[ 'status' => 404 ]
		);
	}

	if ( ! in_array( $status, STATUSES, true ) ) {
		return new WP_Error(
			'uat_checklist_invalid_report_status',
			sprintf(
				/* translators: %s: the report statuses, such as "pending, uat-investigating, uat-done". */
				__( 'A report\'s status must be one of: %s.', 'uat-checklist' ),
				implode( ', ', STATUSES )
			),
			[ 'status' => 400 ]
		);
	}

	if ( find_report( $report->ID ) === null ) {
		return new WP_Error(
			'uat_checklist_report_unavailable',
			__( 'This report is in the trash. Restore it before changing its status.', 'uat-checklist' ),
			[ 'status' => 409 ]
		);
	}

	record_write_allowed( true );

	try {
		$updated = wp_update_post(
			[
				'ID'          => $report->ID,
				'post_name'   => (string) $report->ID,
				'post_status' => $status,
			],
			true
		);
	} finally {
		record_write_allowed( false );
	}

	return is_wp_error( $updated ) ? $updated : true;
}

/**
 * Returns whether set_status() is writing, setting it first when given a value.
 *
 * Request-scoped state for keep_report_fields(), which puts a report's fixed fields back on every other update.
 *
 * @param bool|null $allowed What to set, or null to only read.
 * @return bool Whether a write is allowed now.
 */
function record_write_allowed( ?bool $allowed = null ): bool {
	static $current = false;

	if ( $allowed !== null ) {
		$current = $allowed;
	}

	return $current;
}

/**
 * Refuses to change, delete or publish a report, or its screenshot, for anyone who cannot triage, its author included.
 *
 * The post type's `edit_posts` is the read capability so the list opens, and core maps a post's author to it, which
 * would otherwise let a tester edit their own report through Quick Edit or bulk edit. A screenshot is an attachment
 * whose author is the reporter, so core's media routes would let an Author-role reporter or any Editor delete it.
 * Reading is left alone.
 *
 * @param string[]          $caps    Primitive capabilities the meta capability needs.
 * @param string            $cap     Capability being checked.
 * @param int               $user_id User ID.
 * @param array<int, mixed> $args    Arguments of the check, the first being the post.
 * @return string[] The capabilities, or `do_not_allow`.
 */
function restrict_report_caps( array $caps, string $cap, int $user_id, array $args ): array {
	if ( ! in_array( $cap, [ 'edit_post', 'delete_post', 'publish_post' ], true ) || ! isset( $args[0] ) ) {
		return $caps;
	}

	$post  = get_post( $args[0] );
	$owner = $post instanceof WP_Post && $post->post_type === 'attachment' ? get_post_type( $post->post_parent ) : $post?->post_type;

	if ( $owner !== POST_TYPE ) {
		return $caps;
	}

	return user_can( $user_id, Access\CAP_MANAGE ) ? $caps : [ 'do_not_allow' ];
}

/**
 * Puts a report's title, content, author, date and slug back to what is stored, on every update but set_status()'s.
 *
 * Every save ends in wp_insert_post(), Quick Edit and bulk edit included, and each merges the stored post under the
 * submitted fields. The filter gets that merged row slashed, so the stored values go back slashed. Creating a report is
 * not an update, so submit() needs no exemption.
 *
 * @param array<string, mixed> $data                Slashed row about to be written.
 * @param array<string, mixed> $postarr             Sanitised arguments, including the `ID` of an update.
 * @param array<string, mixed> $unsanitized_postarr Arguments as passed.
 * @param bool                 $update              Whether an existing post is being updated.
 * @return array<string, mixed> The row.
 */
function keep_report_fields( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
	if ( ! $update || record_write_allowed() ) {
		return $data;
	}

	$stored = get_post( (int) $postarr['ID'] );

	if ( ! $stored instanceof WP_Post || $stored->post_type !== POST_TYPE ) {
		return $data;
	}

	$stored_row = $stored->to_array();

	foreach ( [ 'post_title', 'post_content', 'post_author', 'post_date', 'post_date_gmt', 'post_name' ] as $field ) {
		$data[ $field ] = wp_slash( (string) $stored_row[ $field ] );
	}

	// Trash and its restore are the only status writes besides set_status(): core never gives a report auto-draft or
	// inherit, and a restore lands on a report status through the wp_untrash_post_status filter.
	if ( ! in_array( $data['post_status'] ?? '', [ ...STATUSES, 'trash' ], true ) ) {
		$data['post_status'] = wp_slash( (string) $stored_row['post_status'] );
	}

	return $data;
}

/**
 * Finds a page of reports, newest first.
 *
 * @param array{status?: string, per_page?: int, page?: int} $args Status (one of STATUSES, or '' for all), reports
 *                                                                 per page (default 20, at most 50) and page.
 * @return array{items: WP_Post[], total: int} The page's reports, and how many match in all.
 */
function query( array $args ): array {
	$status = (string) ( $args['status'] ?? '' );
	$query  = new WP_Query(
		[
			'post_type'      => POST_TYPE,
			'post_status'    => in_array( $status, STATUSES, true ) ? [ $status ] : STATUSES,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => min( MAX_PER_PAGE, max( 1, (int) ( $args['per_page'] ?? DEFAULT_PER_PAGE ) ) ),
			'paged'          => max( 1, (int) ( $args['page'] ?? 1 ) ),
		]
	);

	return [
		'items' => array_values( array_filter( $query->posts, fn( $post ) => $post instanceof WP_Post ) ),
		'total' => $query->found_posts,
	];
}

/**
 * Returns how many reports one tester can send in a day.
 *
 * @return int Reports.
 */
function daily_limit(): int {
	/**
	 * Filters how many reports one tester can send in a day.
	 *
	 * @param int $limit Reports. Default 20.
	 */
	return max( 0, (int) apply_filters( 'hm_uat_checklist_report_daily_limit', DAILY_LIMIT ) );
}

/**
 * Counts the reports a user has sent in the last day, trashed ones included so deleting a report frees no place.
 *
 * @param int $user_id User ID.
 * @return int Reports.
 */
function sent_today( int $user_id ): int {
	$query = new WP_Query(
		[
			'post_type'      => POST_TYPE,
			'post_status'    => [ ...STATUSES, 'trash' ],
			'author'         => $user_id,
			'date_query'     => [
				[
					'column' => 'post_date_gmt',
					'after'  => gmdate( 'Y-m-d H:i:s', time() - DAILY_WINDOW ),
				],
			],
			'fields'         => 'ids',
			'posts_per_page' => 1,
		]
	);

	return $query->found_posts;
}

/**
 * Returns the port of an address, which is its scheme's default when it names none.
 *
 * @param string $url Address with an http or https scheme.
 * @return int Port.
 */
function effective_port( string $url ): int {
	$port = wp_parse_url( $url, PHP_URL_PORT );

	return is_int( $port ) ? $port : default_port( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
}

/**
 * Returns the port an http or https address uses when it names none.
 *
 * @param string $scheme `http` or `https`.
 * @return int 443 or 80.
 */
function default_port( string $scheme ): int {
	return $scheme === 'https' ? 443 : 80;
}

/**
 * Returns whether an address is on this site: http or https, at the host and port of the home or admin address.
 *
 * @param string $url Address.
 * @return bool Whether it is.
 */
function is_same_site( string $url ): bool {
	$host = wp_parse_url( $url, PHP_URL_HOST );

	// Browsers read a backslash as a slash, so "https://site.test\@evil.test" names a different host than PHP parses.
	if ( str_contains( $url, '\\' ) ) {
		return false;
	}

	if ( ! is_string( $host ) || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
		return false;
	}

	foreach ( [ home_url(), admin_url() ] as $site_url ) {
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );

		if ( is_string( $site_host ) && strtolower( $site_host ) === strtolower( $host ) && effective_port( $site_url ) === effective_port( $url ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Validates a report's page address: a string whose scheme is http or https and whose host and port are this site's.
 *
 * Checked on the value as sent, before canonical_page_url() reduces it.
 *
 * @param mixed           $value   Value sent.
 * @param WP_REST_Request $request Request.
 * @param string          $param   Argument name.
 * @return true|WP_Error True when valid, or why not.
 */
function validate_page_url( mixed $value, WP_REST_Request $request, string $param ): true|WP_Error {
	$valid = rest_validate_request_arg( $value, $request, $param );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	if ( ! is_same_site( (string) $value ) ) {
		return new WP_Error(
			'uat_checklist_page_url_foreign',
			__( 'The page address must be on this site.', 'uat-checklist' ),
			[ 'status' => 400 ]
		);
	}

	return true;
}

/**
 * Reduces a validated page address to its scheme, host, path and any port that is not the scheme's default.
 *
 * The query string, fragment and credentials are dropped because an address can carry a nonce, a reset key or a token
 * that everyone who reads the report would then see. Cut to PAGE_URL_MAX_LENGTH rather than refused.
 *
 * @param mixed $value Address that validate_page_url() accepted.
 * @return string Address.
 */
function canonical_page_url( mixed $value ): string {
	$url    = (string) $value;
	$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
	$port   = effective_port( $url );
	$path   = wp_parse_url( $url, PHP_URL_PATH );

	return mb_substr(
		esc_url_raw(
			$scheme . '://' . wp_parse_url( $url, PHP_URL_HOST )
			. ( $port === default_port( $scheme ) ? '' : ':' . $port )
			. ( is_string( $path ) ? $path : '/' )
		),
		0,
		PAGE_URL_MAX_LENGTH
	);
}

/**
 * Validates a report's description: a string of 1 to DESCRIPTION_MAX_LENGTH characters once cleaned.
 *
 * Counted after sanitize_description(), because a multipart upload's CRLF line endings would otherwise count twice.
 *
 * @param mixed           $value   Value sent.
 * @param WP_REST_Request $request Request.
 * @param string          $param   Argument name.
 * @return true|WP_Error True when valid, or why not.
 */
function validate_description( mixed $value, WP_REST_Request $request, string $param ): true|WP_Error {
	$valid = rest_validate_request_arg( $value, $request, $param );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$length = mb_strlen( sanitize_description( $value ) );

	if ( $length < 1 ) {
		return new WP_Error(
			'uat_checklist_report_empty',
			__( 'Describe the issue.', 'uat-checklist' ),
			[ 'status' => 400 ]
		);
	}

	if ( $length > DESCRIPTION_MAX_LENGTH ) {
		return new WP_Error(
			'uat_checklist_report_too_long',
			sprintf(
				/* translators: %s: the most characters a report's description can have. */
				__( 'The description can be at most %s characters.', 'uat-checklist' ),
				number_format_i18n( DESCRIPTION_MAX_LENGTH )
			),
			[ 'status' => 400 ]
		);
	}

	return true;
}

/**
 * Cleans a report's description without removing anything typed: angle brackets are kept.
 *
 * Printing is escaped wherever the description is shown, and describe() undoes the encoding it is stored in.
 *
 * @param mixed $value Description sent.
 * @return string Description with invalid UTF-8 removed, line endings as `\n` and no outer whitespace.
 */
function sanitize_description( mixed $value ): string {
	return trim( str_replace( [ "\r\n", "\r" ], "\n", wp_check_invalid_utf8( (string) $value ) ) );
}

/**
 * Cleans the title of the page a report was sent from, cut to PAGE_TITLE_MAX_LENGTH characters rather than refused.
 *
 * @param mixed $value Title sent.
 * @return string Plain text.
 */
function sanitize_page_title( mixed $value ): string {
	return mb_substr( sanitize_text_field( (string) $value ), 0, PAGE_TITLE_MAX_LENGTH );
}

/**
 * Returns text encoded for the post columns, which core's kses filters rewrite for most testers.
 *
 * Those filters strip tag-like text and re-encode ampersands. Entities pass through them untouched, so what is stored
 * decodes to exactly what was typed. A title is stored this way too, and escaping it for output does not encode it twice.
 *
 * @param string $text Text as typed.
 * @return string Encoded text.
 */
function encode_for_storage( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Returns a report's title as stored: the first TITLE_LENGTH characters of its description, on one line.
 *
 * @param string $description Description as typed.
 * @return string Encoded title.
 */
function title_from( string $description ): string {
	$line  = (string) preg_replace( '/\s+/u', ' ', $description );
	$title = mb_substr( $line, 0, TITLE_LENGTH );

	return encode_for_storage( mb_strlen( $line ) > TITLE_LENGTH ? rtrim( $title ) . '…' : $title );
}

/**
 * Registers the report routes: `POST uat-checklist/v1/reports`, which any logged-in user can send a report to, and
 * the two status routes, which managers use to change one report's status or several at once.
 *
 * @return void
 */
function register_routes(): void {
	$status_arg = [
		'type'              => 'string',
		'required'          => true,
		'enum'              => STATUSES,
		'validate_callback' => 'rest_validate_request_arg',
	];

	register_rest_route(
		REST\REST_NAMESPACE,
		'/reports/(?P<id>\d+)/status',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\update_status',
			'permission_callback' => __NAMESPACE__ . '\\can_triage',
			'args'                => [
				'id'     => [
					'type'              => 'integer',
					'required'          => true,
					'minimum'           => 1,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'absint',
				],
				'status' => $status_arg,
			],
		]
	);

	register_rest_route(
		REST\REST_NAMESPACE,
		'/reports/status',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\update_statuses',
			'permission_callback' => __NAMESPACE__ . '\\can_triage',
			'args'                => [
				'ids'    => [
					'type'              => 'array',
					'required'          => true,
					'minItems'          => 1,
					'maxItems'          => MAX_BULK_IDS,
					'items'             => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => __NAMESPACE__ . '\\sanitize_ids',
				],
				'status' => $status_arg,
			],
		]
	);

	register_rest_route(
		REST\REST_NAMESPACE,
		'/reports',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\submit',
			'permission_callback' => __NAMESPACE__ . '\\can_submit',
			'args'                => [
				'description' => [
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => __NAMESPACE__ . '\\validate_description',
					'sanitize_callback' => __NAMESPACE__ . '\\sanitize_description',
				],
				'page_url'    => [
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => __NAMESPACE__ . '\\validate_page_url',
					'sanitize_callback' => __NAMESPACE__ . '\\canonical_page_url',
				],
				'page_id'     => [
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'absint',
				],
				'page_title'  => [
					'type'              => 'string',
					'default'           => '',
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => __NAMESPACE__ . '\\sanitize_page_title',
				],
			],
		]
	);
}

/**
 * Returns the refusal for an ID that is not a report in a status a report moves through.
 *
 * @return WP_Error Error with a 404 status.
 */
function report_not_found(): WP_Error {
	return new WP_Error(
		'uat_checklist_report_not_found',
		__( 'No issue report has that ID, or it is in the trash.', 'uat-checklist' ),
		[ 'status' => 404 ]
	);
}

/**
 * Cleans a list of report IDs: whole numbers, each once, in the order sent.
 *
 * @param mixed $value IDs sent.
 * @return int[] IDs.
 */
function sanitize_ids( mixed $value ): array {
	return array_values( array_unique( array_map( 'absint', (array) $value ) ) );
}

/**
 * Moves one report to another status.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error The report as describe() returns it without the screenshot's details, or why it
 *                                   was refused.
 */
function update_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$report = find_report( (int) $request['id'] );

	if ( $report === null ) {
		return report_not_found();
	}

	$set = set_status( $report, (string) $request['status'] );

	if ( is_wp_error( $set ) ) {
		return $set;
	}

	// Read again: the post just saved is not the one in hand.
	return new WP_REST_Response( describe( find_report( $report->ID ) ?? $report, false ) );
}

/**
 * Moves several reports to another status, each on its own: one that cannot be moved does not stop the rest.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response `{ updated, failed }`: the IDs now in the status, and an `{ id, code, message }` for each
 *                          that could not be moved.
 */
function update_statuses( WP_REST_Request $request ): WP_REST_Response {
	$status  = (string) $request['status'];
	$updated = [];
	$failed  = [];

	foreach ( (array) $request['ids'] as $id ) {
		$report = find_report( (int) $id );
		$result = $report === null ? report_not_found() : set_status( $report, $status );

		if ( $result === true ) {
			$updated[] = (int) $id;
			continue;
		}

		$failed[] = [
			'id'      => (int) $id,
			'code'    => (string) $result->get_error_code(),
			'message' => $result->get_error_message(),
		];
	}

	return new WP_REST_Response(
		[
			'updated' => $updated,
			'failed'  => $failed,
		]
	);
}

/**
 * Saves the current user's report, with its screenshot when they sent one, one request at a time per tester.
 *
 * The daily caps are counted and then spent, so two parallel requests could both pass them. The lock is best effort:
 * it needs an object cache shared between requests, and with the default per-request cache it never refuses anything.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error `{ id, status }` with a 201, or why it was refused.
 */
function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$lock = 'user_' . get_current_user_id();

	if ( ! wp_cache_add( $lock, 1, LOCK_GROUP, LOCK_TTL ) ) {
		return new WP_Error(
			'hm_uat_checklist_report_busy',
			__( 'Your last report is still being saved. Try again in a few seconds.', 'uat-checklist' ),
			[ 'status' => 429 ]
		);
	}

	try {
		return save_report( $request );
	} finally {
		wp_cache_delete( $lock, LOCK_GROUP );
	}
}

/**
 * Checks the caps and the screenshot, then stores the current user's report and attaches the screenshot.
 *
 * Nothing is kept when the screenshot is refused: the file is checked and stored before the report, and the report is
 * deleted again if the file cannot be attached to it.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error `{ id, status }` with a 201, or why it was refused.
 */
function save_report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	// Already cleaned by sanitize_description(), after validate_description() checked its length.
	$description = (string) $request['description'];
	$user_id     = get_current_user_id();

	if ( sent_today( $user_id ) >= daily_limit() ) {
		return new WP_Error(
			'hm_uat_checklist_report_limited',
			sprintf(
				/* translators: %s: how many reports a tester can send in a day. */
				__( 'You have sent %s reports in the last day, the most a tester can. Send this one tomorrow.', 'uat-checklist' ),
				number_format_i18n( daily_limit() )
			),
			[ 'status' => 429 ]
		);
	}

	$file = Screenshots\chosen_file( $request->get_file_params() );

	if ( $file !== null && Screenshots\uploaded_today( $user_id ) >= Screenshots\daily_limit() ) {
		return new WP_Error(
			'hm_uat_checklist_screenshot_limited',
			sprintf(
				/* translators: %s: how many screenshots a tester can add in a day. */
				__( 'You have added %s screenshots in the last day, the most a tester can. Send the report without one, or add it tomorrow.', 'uat-checklist' ),
				number_format_i18n( Screenshots\daily_limit() )
			),
			[ 'status' => 429 ]
		);
	}

	$upload = $file === null ? null : Screenshots\store( $file );

	if ( is_wp_error( $upload ) ) {
		return $upload;
	}

	// Both dates are given: a Pending post is saved with no GMT date otherwise, and takes the date of its first
	// change of status.
	$report_id = wp_insert_post(
		wp_slash(
			[
				'post_type'     => POST_TYPE,
				'post_status'   => STATUS_PENDING,
				'post_author'   => $user_id,
				'post_title'    => title_from( $description ),
				'post_content'  => encode_for_storage( $description ),
				'post_date'     => current_time( 'mysql' ),
				'post_date_gmt' => current_time( 'mysql', true ),
				'meta_input'    => [
					META_PAGE_URL   => (string) $request['page_url'],
					META_PAGE_ID    => (int) $request['page_id'],
					META_PAGE_TITLE => (string) $request['page_title'],
				],
			]
		),
		true
	);

	if ( is_wp_error( $report_id ) ) {
		if ( $upload !== null ) {
			wp_delete_file( $upload['file'] );
		}

		$report_id->add_data( [ 'status' => 500 ] );

		return $report_id;
	}

	$report = get_post( $report_id );

	if ( $upload !== null && $report instanceof WP_Post ) {
		$attached = Screenshots\attach( $upload, $report );

		if ( is_wp_error( $attached ) ) {
			wp_delete_file( $upload['file'] );
			wp_delete_post( $report_id, true );
			$attached->add_data( [ 'status' => 500 ] );

			return $attached;
		}
	}

	return new WP_REST_Response(
		[
			'id'     => $report_id,
			'status' => STATUS_PENDING,
		],
		201
	);
}
