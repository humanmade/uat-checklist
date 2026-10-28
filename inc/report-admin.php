<?php
/**
 * The wp-admin screens for issue reports: list columns, status views, the edit screen and a read-only view.
 *
 * A report is a record: a triager changes its status and nothing else. The row actions, the bulk action and the edit
 * screen's select send the change to the REST API from `src/report-status.js`, and none posts to `post.php`, whose save
 * would make the saving user the report's author. Anyone else who holds Access\CAP_READ sees the same details of
 * every report on a page with no controls.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Report_Admin;

use HM\UAT_Checklist;
use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Reports;
use HM\UAT_Checklist\REST;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Prefix of the bulk action that moves reports to a status; the status follows it.
 */
const BULK_PREFIX = 'uat_report_mark_';

/**
 * Handle of the script that changes a report's status from the list and the edit screen.
 */
const HANDLE = 'uat-checklist-report-status';

/**
 * Slug of the read-only report page, which has no menu entry.
 */
const VIEW_PAGE = 'uat-report-view';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_filter( 'manage_' . Reports\POST_TYPE . '_posts_columns', __NAMESPACE__ . '\\columns' );
	add_action( 'manage_' . Reports\POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\\render_column', 10, 2 );
	add_filter( 'manage_edit-' . Reports\POST_TYPE . '_sortable_columns', __NAMESPACE__ . '\\sortable_columns' );
	add_filter( 'list_table_primary_column', __NAMESPACE__ . '\\primary_column', 10, 2 );
	add_filter( 'quick_edit_enabled_for_post_type', __NAMESPACE__ . '\\disable_quick_edit', 10, 2 );
	add_filter( 'post_row_actions', __NAMESPACE__ . '\\row_actions', 10, 2 );
	add_filter( 'bulk_actions-edit-' . Reports\POST_TYPE, __NAMESPACE__ . '\\bulk_actions' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\list_every_status' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\encode_search' );
	add_filter( 'the_posts', __NAMESPACE__ . '\\prime_list', 10, 2 );
	add_filter( 'wp_untrash_post_status', __NAMESPACE__ . '\\restore_status', 10, 3 );
	add_action( 'load-post.php', __NAMESPACE__ . '\\lock_edit_screen' );
	add_action( 'add_meta_boxes_' . Reports\POST_TYPE, __NAMESPACE__ . '\\add_meta_boxes' );
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_view_page' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\style_screens' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_script' );
}

/**
 * Styles the reports list, the edit screen and the read-only page, on those screens only.
 *
 * @return void
 */
function style_screens(): void {
	$screen = get_current_screen()?->id;

	if (
		$screen !== 'edit-' . Reports\POST_TYPE
		&& $screen !== Reports\POST_TYPE
		&& $screen !== get_plugin_page_hookname( VIEW_PAGE, '' )
	) {
		return;
	}

	$css = <<<'CSS'
		.column-uat_screenshot {
			width: 160px;
		}

		.uat-report-admin__excerpt {
			margin: 4px 0 0;
		}

		.uat-report-admin__screenshot {
			margin: 0;
		}

		.uat-report-admin__screenshot img {
			display: block;
			box-sizing: border-box;
			max-width: 100%;
			height: auto;
			border: 1px solid color-mix( in srgb, currentColor 20%, transparent );
		}

		.uat-report-admin__meta {
			display: grid;
			grid-template-columns: max-content 1fr;
			gap: 4px 16px;
			margin: 0 0 16px;
		}

		.uat-report-admin__meta dt {
			font-weight: 600;
		}

		.uat-report-admin__meta dd {
			margin: 0;
			overflow-wrap: anywhere;
		}

		.uat-report-admin__description {
			margin-top: 0;
		}

		.uat-report-admin__status {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 8px;
		}

		.uat-report-admin__mark[aria-disabled="true"] {
			opacity: 0.6;
			cursor: default;
		}
		CSS;

	wp_add_inline_style( 'common', $css );
}

/**
 * Returns whether the status script is built, which the row actions, bulk action and status box depend on.
 *
 * @return bool Whether it is.
 */
function script_built(): bool {
	return file_exists( UAT_Checklist\PATH . 'build/report-status.asset.php' );
}

/**
 * Enqueues the script that changes a report's status, on the reports list and edit screen, for a user who can triage.
 *
 * @return void
 */
function enqueue_script(): void {
	$screen = get_current_screen()?->id;

	if (
		( $screen !== 'edit-' . Reports\POST_TYPE && $screen !== Reports\POST_TYPE )
		|| ! Reports\can_triage()
		|| ! script_built()
	) {
		return;
	}

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- this plugin's own build manifest, existence checked above.
	$asset = require UAT_Checklist\PATH . 'build/report-status.asset.php';

	wp_enqueue_script(
		HANDLE,
		plugins_url( 'build/report-status.js', UAT_Checklist\FILE ),
		$asset['dependencies'],
		$asset['version'],
		[
			'in_footer' => true,
			'strategy'  => 'defer',
		]
	);

	wp_set_script_translations( HANDLE, 'uat-checklist' );

	$statuses = [];
	$marks    = [];

	foreach ( Reports\STATUSES as $status ) {
		$statuses[ $status ] = Reports\status_label( $status );
		$marks[ $status ]    = mark_label( $status );
	}

	wp_add_inline_script(
		HANDLE,
		'window.hmUatReportAdmin = ' . wp_json_encode(
			[
				'route'      => '/' . REST\REST_NAMESPACE . '/reports',
				'bulkPrefix' => BULK_PREFIX,
				'statuses'   => $statuses,
				'marks'      => $marks,
			]
		) . ';',
		'before'
	);
}

/**
 * Returns the report's details, worked out once for the request, since each column reads them.
 *
 * Only the screenshot cell and the details box ask for the screenshot, as reading it costs file lookups.
 *
 * @param WP_Post $report          Report.
 * @param bool    $with_screenshot Whether to include the screenshot's file details.
 * @return array<string, mixed> What `Reports\describe()` returns.
 */
function details( WP_Post $report, bool $with_screenshot = false ): array {
	static $cache = [];

	return $cache[ $report->ID . ( $with_screenshot ? ':screenshot' : '' ) ] ??= Reports\describe( $report, $with_screenshot );
}

/**
 * Warms the caches for the reports on the list's page, so each row's details cost the same few queries.
 *
 * @param WP_Post[] $posts Posts the list's query found.
 * @param WP_Query  $query The query.
 * @return WP_Post[] The posts, unchanged.
 */
function prime_list( array $posts, WP_Query $query ): array {
	if ( is_admin() && $query->is_main_query() && $query->get( 'post_type' ) === Reports\POST_TYPE ) {
		Reports\prime( $posts );
	}

	return $posts;
}

/**
 * Returns a report's status as the label a triager sees, whether it is one of the three or in the trash.
 *
 * @param WP_Post $report Report.
 * @return string Plain text.
 */
function status_text( WP_Post $report ): string {
	return in_array( $report->post_status, Reports\STATUSES, true )
		? Reports\status_label( $report->post_status )
		: (string) get_post_status_object( $report->post_status )?->label;
}

/**
 * Returns when a report was made, in the site's date and time formats.
 *
 * @param WP_Post $report Report.
 * @return string Date and time.
 */
function reported_at( WP_Post $report ): string {
	return (string) wp_date(
		get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
		(int) get_post_timestamp( $report )
	);
}

/**
 * Returns the stand-in for a cell with nothing to show: a dash that a screen reader announces as "None".
 *
 * @return string Escaped markup.
 */
function empty_cell(): string {
	return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">'
		. esc_html__( 'None', 'uat-checklist' )
		. '</span>';
}

/**
 * Returns a report's screenshot as an image at the given size, or '' when it has none.
 *
 * @param WP_Post $report Report.
 * @param string  $size   Registered image size, such as `thumbnail` or `large`.
 * @return string Escaped markup.
 */
function screenshot_image( WP_Post $report, string $size ): string {
	$details = details( $report, true );

	if ( $details['screenshot'] === null ) {
		return '';
	}

	return wp_get_attachment_image(
		(int) $details['screenshot']['id'],
		$size,
		false,
		[
			'alt'     => sprintf(
				/* translators: %s: name of the tester who attached the screenshot. */
				__( 'Screenshot from %s', 'uat-checklist' ),
				(string) $details['reporter']['name']
			),
			'loading' => 'lazy',
		]
	);
}

/**
 * Returns the list table columns.
 *
 * Author and date are replaced by the Reporter and Reported columns, which read the same facts from the report's
 * details and show the date a report was made rather than "Last Modified".
 *
 * @param array<string, string> $columns Default columns.
 * @return array<string, string> Columns.
 */
function columns( array $columns ): array {
	return [
		'cb'             => $columns['cb'] ?? '',
		'uat_report'     => __( 'Report', 'uat-checklist' ),
		'uat_reporter'   => __( 'Reporter', 'uat-checklist' ),
		'uat_page'       => __( 'Page', 'uat-checklist' ),
		'uat_status'     => __( 'Status', 'uat-checklist' ),
		'uat_screenshot' => __( 'Screenshot', 'uat-checklist' ),
		'uat_date'       => __( 'Reported', 'uat-checklist' ),
	];
}

/**
 * Makes the Reported column sortable. Status is not: its three values sort alphabetically, not in the order a report
 * moves through them.
 *
 * @param array<string, mixed> $columns Sortable columns.
 * @return array<string, mixed> Sortable columns.
 */
function sortable_columns( array $columns ): array {
	$columns['uat_date'] = [ 'date', true ];

	return $columns;
}

/**
 * Makes Report the reports list's primary column, so core puts the row actions under it.
 *
 * @param string $column    Default primary column.
 * @param string $screen_id Screen the list is on.
 * @return string Primary column.
 */
function primary_column( string $column, string $screen_id ): string {
	return $screen_id === 'edit-' . Reports\POST_TYPE ? 'uat_report' : $column;
}

/**
 * Turns Quick Edit off for reports, which would let a triager change the title, slug and date of a record.
 *
 * @param bool   $enabled   Whether Quick Edit is on.
 * @param string $post_type Post type of the list.
 * @return bool Whether it is on.
 */
function disable_quick_edit( bool $enabled, string $post_type ): bool {
	return $post_type === Reports\POST_TYPE ? false : $enabled;
}

/**
 * Prints one cell of the reports list.
 *
 * @param string $column  Column key.
 * @param int    $post_id Report ID.
 * @return void
 */
function render_column( string $column, int $post_id ): void {
	$report = get_post( $post_id );

	if ( ! $report instanceof WP_Post ) {
		return;
	}

	switch ( $column ) {
		case 'uat_report':
			render_report_cell( $report );
			break;

		case 'uat_reporter':
			render_reporter_cell( $report );
			break;

		case 'uat_page':
			render_page_cell( $report );
			break;

		case 'uat_status':
			echo esc_html( status_text( $report ) );
			break;

		case 'uat_screenshot':
			render_screenshot_cell( $report );
			break;

		case 'uat_date':
			echo esc_html( reported_at( $report ) );
			break;
	}
}

/**
 * Prints the Report cell: the title, linked to the edit screen for a user who can edit the report and to the
 * read-only page for anyone else, and the start of the description.
 *
 * @param WP_Post $report Report.
 * @return void
 */
function render_report_cell( WP_Post $report ): void {
	$title = get_the_title( $report );
	// Core gives no edit link to a user who cannot edit the report, who gets the read-only page instead.
	$link = get_edit_post_link( $report ) ?? view_url( $report->ID );

	printf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( $link ), esc_html( $title ) );

	// Escaped before trimming, because wp_trim_words() strips any tag-like text a tester typed. Its `&hellip;` is the only
	// entity it can add, so what is printed is safe.
	printf(
		'<p class="uat-report-admin__excerpt">%s</p>',
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by escape_text() before it is trimmed.
		wp_trim_words( Reports\escape_text( (string) details( $report )['description'] ), 20 )
	);
}

/**
 * Prints the Reporter cell: the name of the user who made the report.
 *
 * @param WP_Post $report Report.
 * @return void
 */
function render_reporter_cell( WP_Post $report ): void {
	$name = (string) details( $report )['reporter']['name'];

	if ( $name === '' ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by empty_cell() from esc_html__().
		echo empty_cell();
		return;
	}

	echo esc_html( $name );
}

/**
 * Prints the Page cell: the page the report was made on, linked to it.
 *
 * @param WP_Post $report Report.
 * @return void
 */
function render_page_cell( WP_Post $report ): void {
	$page  = details( $report )['page'];
	$url   = (string) $page['url'];
	$title = (string) $page['title'];

	if ( $url === '' ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by empty_cell() from esc_html__().
		echo empty_cell();
		return;
	}

	// phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped -- escape_text() is this plugin's escaper for a tester's text.
	printf( '<a href="%1$s">%2$s</a>', esc_url( $url ), Reports\escape_text( $title === '' ? $url : $title ) );
}

/**
 * Prints the Screenshot cell: a thumbnail linked to the full file in a new tab.
 *
 * @param WP_Post $report Report.
 * @return void
 */
function render_screenshot_cell( WP_Post $report ): void {
	$image = screenshot_image( $report, 'thumbnail' );

	if ( $image === '' ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by empty_cell() from esc_html__().
		echo empty_cell();
		return;
	}

	printf(
		'<a class="uat-report-admin__screenshot" href="%1$s" target="_blank" rel="noopener">%2$s<span class="screen-reader-text"> %3$s</span></a>',
		esc_url( (string) details( $report, true )['screenshot']['url'] ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by wp_get_attachment_image().
		$image,
		esc_html__( '(opens in a new tab)', 'uat-checklist' )
	);
}

/**
 * Lists every report that is not in the trash under All, whichever statuses core would otherwise include.
 *
 * Core's All view takes the registered statuses that opt in to the admin's All list, so a status registered
 * without that flag would be missing from it.
 *
 * @param WP_Query $query Query being prepared.
 * @return void
 */
function list_every_status( WP_Query $query ): void {
	if (
		! is_admin()
		|| ! $query->is_main_query()
		|| $query->get( 'post_type' ) !== Reports\POST_TYPE
		|| $query->get( 'post_status' ) !== ''
	) {
		return;
	}

	$query->set( 'post_status', Reports\STATUSES );
}

/**
 * Searches the reports list for the term as it is stored, which is HTML-encoded.
 *
 * A term holding `&`, `<`, `>` or a quote would otherwise match nothing, as the stored text has them as entities.
 *
 * @param WP_Query $query Query being prepared.
 * @return void
 */
function encode_search( WP_Query $query ): void {
	$term = $query->get( 's' );

	if (
		! is_admin()
		|| ! $query->is_main_query()
		|| $query->get( 'post_type' ) !== Reports\POST_TYPE
		|| ! is_string( $term )
		|| $term === ''
	) {
		return;
	}

	$query->set( 's', Reports\encode_for_storage( $term ) );
}

/**
 * Restores a report from the trash to the status it had, where core would restore it as a draft.
 *
 * A draft is none of the three statuses, so a restored report would have no way back into the workflow.
 *
 * @param string $new_status      Status core will give the restored post.
 * @param int    $post_id         Post being restored.
 * @param string $previous_status Status it had before it was trashed.
 * @return string Status.
 */
function restore_status( string $new_status, int $post_id, string $previous_status ): string {
	return get_post_type( $post_id ) === Reports\POST_TYPE && in_array( $previous_status, Reports\STATUSES, true )
		? $previous_status
		: $new_status;
}

/**
 * Returns the label of the action that moves a report to a status.
 *
 * @param string $status One of `Reports\STATUSES`.
 * @return string Plain text.
 */
function mark_label( string $status ): string {
	return sprintf(
		/* translators: %s: a report status, such as Investigating. */
		__( 'Mark as %s', 'uat-checklist' ),
		Reports\status_label( $status )
	);
}

/**
 * Returns the address of the read-only page for a report.
 *
 * @param int $report_id Report.
 * @return string URL.
 */
function view_url( int $report_id ): string {
	return add_query_arg(
		[
			'page'   => VIEW_PAGE,
			'report' => $report_id,
		],
		admin_url( 'admin.php' )
	);
}

/**
 * Returns a button for each status a report is not in, which `src/report-status.js` turns into a status change.
 *
 * @param int    $report_id Report.
 * @param string $current   The report's status.
 * @return string Escaped markup.
 */
function mark_buttons( int $report_id, string $current ): string {
	$buttons = [];

	foreach ( Reports\STATUSES as $status ) {
		if ( $status !== $current ) {
			$buttons[] = sprintf(
				'<button type="button" class="button-link uat-report-admin__mark" data-uat-report-id="%1$d" data-uat-report-status="%2$s">%3$s</button>',
				$report_id,
				esc_attr( $status ),
				esc_html( mark_label( $status ) )
			);
		}
	}

	return implode( ' | ', $buttons );
}

/**
 * Adds the buttons that move a report to another status, for a user who can triage reports.
 *
 * @param array<string, string> $actions Row actions.
 * @param WP_Post               $post    Post the row is for.
 * @return array<string, string> Row actions.
 */
function row_actions( array $actions, WP_Post $post ): array {
	if (
		$post->post_type !== Reports\POST_TYPE
		|| ! Reports\can_triage()
		|| ! script_built()
		|| ! in_array( $post->post_status, Reports\STATUSES, true )
	) {
		return $actions;
	}

	$actions['uat_report_status'] = mark_buttons( $post->ID, $post->post_status );

	return $actions;
}

/**
 * Swaps the bulk Edit action for one move per status, outside the trash view, for a user who can triage reports.
 *
 * Bulk Edit would change the title, author and date of every report it was used on.
 *
 * @param array<string, string> $actions Bulk actions.
 * @return array<string, string> Bulk actions.
 */
function bulk_actions( array $actions ): array {
	unset( $actions['edit'] );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter in a GET query string; it changes nothing.
	$in_trash = isset( $_GET['post_status'] ) && sanitize_key( wp_unslash( $_GET['post_status'] ) ) === 'trash';

	if ( $in_trash || ! Reports\can_triage() || ! script_built() ) {
		return $actions;
	}

	foreach ( Reports\STATUSES as $status ) {
		$actions[ BULK_PREFIX . $status ] = mark_label( $status );
	}

	return $actions;
}

/**
 * On a report's edit screen, removes the title and editor and refuses a save, so the record cannot be changed there.
 *
 * A save through core would make the saving user the report's author, as the form has no author field.
 *
 * @return void
 */
function lock_edit_screen(): void {
	if ( get_current_screen()?->post_type !== Reports\POST_TYPE ) {
		return;
	}

	if ( isset( $_SERVER['REQUEST_METHOD'] ) && sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) === 'POST' ) {
		wp_die( esc_html__( 'A report cannot be edited. Only its status can change.', 'uat-checklist' ), '', [ 'back_link' => true ] );
	}

	// Without editor support the screen is the classic one, not the block editor.
	foreach ( [ 'title', 'editor', 'author' ] as $feature ) {
		remove_post_type_support( Reports\POST_TYPE, $feature );
	}
}

/**
 * Replaces the edit screen's boxes with the report's details and its status.
 *
 * @return void
 */
function add_meta_boxes(): void {
	// Publish holds the date and the Update button, and Slug the report's address: neither may change.
	remove_meta_box( 'submitdiv', Reports\POST_TYPE, 'side' );
	remove_meta_box( 'slugdiv', Reports\POST_TYPE, 'normal' );

	add_meta_box(
		'uat-report-details',
		__( 'Report details', 'uat-checklist' ),
		__NAMESPACE__ . '\\render_details_box',
		Reports\POST_TYPE,
		'normal',
		'high'
	);

	add_meta_box(
		'uat-report-status',
		__( 'Status', 'uat-checklist' ),
		__NAMESPACE__ . '\\render_status_box',
		Reports\POST_TYPE,
		'side',
		'high'
	);
}

/**
 * Prints the report, read-only: what the tester wrote, who and where, when, its status and the screenshot.
 *
 * @param WP_Post $report Report being shown.
 * @return void
 */
function render_details_box( WP_Post $report ): void {
	$details    = details( $report, true );
	$image      = screenshot_image( $report, 'large' );
	$page_url   = (string) $details['page']['url'];
	$page_title = (string) $details['page']['title'];
	?>
	<div class="uat-report-admin">
		<p class="uat-report-admin__description"><?php echo nl2br( Reports\escape_text( (string) $details['description'] ) ); // phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped -- escape_text() is this plugin's escaper for a tester's text. ?></p>
		<dl class="uat-report-admin__meta">
			<dt><?php esc_html_e( 'Reporter', 'uat-checklist' ); ?></dt>
			<dd><?php echo esc_html( (string) $details['reporter']['name'] ); ?></dd>
			<dt><?php esc_html_e( 'Page', 'uat-checklist' ); ?></dt>
			<dd>
				<?php if ( $page_url === '' ) : ?>
					<?php esc_html_e( 'Not recorded', 'uat-checklist' ); ?>
				<?php else : ?>
					<a href="<?php echo esc_url( $page_url ); ?>"><?php echo Reports\escape_text( $page_title === '' ? $page_url : $page_title ); // phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped -- escape_text() is this plugin's escaper for a tester's text. ?></a>
				<?php endif; ?>
			</dd>
			<dt><?php esc_html_e( 'Reported', 'uat-checklist' ); ?></dt>
			<dd><?php echo esc_html( reported_at( $report ) ); ?></dd>
			<dt><?php esc_html_e( 'Status', 'uat-checklist' ); ?></dt>
			<dd class="uat-report-admin__current"><?php echo esc_html( status_text( $report ) ); ?></dd>
		</dl>
		<?php if ( $image !== '' ) : ?>
			<figure class="uat-report-admin__screenshot">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by wp_get_attachment_image().
				echo $image;
				?>
				<figcaption>
					<a href="<?php echo esc_url( (string) $details['screenshot']['url'] ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open full size', 'uat-checklist' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'uat-checklist' ); ?></span>
					</a>
				</figcaption>
			</figure>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Prints the status control: a select and a button that `src/report-status.js` sends to the REST API.
 *
 * Only a user who can triage reaches the edit screen, so there is no read-only form of this box.
 *
 * @param WP_Post $report Report being shown.
 * @return void
 */
function render_status_box( WP_Post $report ): void {
	if ( ! script_built() ) {
		return;
	}

	?>
	<div class="uat-report-admin__status">
		<label class="screen-reader-text" for="uat-report-status-select"><?php esc_html_e( 'Status', 'uat-checklist' ); ?></label>
		<select id="uat-report-status-select">
			<?php foreach ( Reports\STATUSES as $status ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>"<?php selected( $report->post_status, $status ); ?>>
					<?php echo esc_html( Reports\status_label( $status ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button type="button" class="button button-primary" id="uat-report-status-update" data-uat-report-id="<?php echo esc_attr( (string) $report->ID ); ?>">
			<?php esc_html_e( 'Update status', 'uat-checklist' ); ?>
		</button>
	</div>
	<?php
}

/**
 * Registers the read-only report page with no menu parent, so it is reachable by its address and no menu shows it.
 *
 * @return void
 */
function add_view_page(): void {
	add_submenu_page(
		'',
		__( 'Report', 'uat-checklist' ),
		'',
		Access\CAP_READ,
		VIEW_PAGE,
		__NAMESPACE__ . '\\render_view_page'
	);
}

/**
 * Prints the read-only page: the report's details with no control to change anything.
 *
 * @return void
 */
function render_view_page(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only page; it changes nothing.
	$report = Reports\find_report( isset( $_GET['report'] ) ? absint( wp_unslash( $_GET['report'] ) ) : 0 );

	if ( $report === null ) {
		wp_die(
			esc_html__( 'That report cannot be found.', 'uat-checklist' ),
			'',
			[
				'response'  => 404,
				'back_link' => true,
			]
		);
	}
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( get_the_title( $report ) ); ?></h1>
		<hr class="wp-header-end">
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle"><?php esc_html_e( 'Report details', 'uat-checklist' ); ?></h2>
			</div>
			<div class="inside">
				<?php render_details_box( $report ); ?>
			</div>
		</div>
		<p>
			<a href="<?php echo esc_url( add_query_arg( 'post_type', Reports\POST_TYPE, admin_url( 'edit.php' ) ) ); ?>">
				<?php esc_html_e( 'Back to reports', 'uat-checklist' ); ?>
			</a>
		</p>
	</div>
	<?php
}
