<?php
/**
 * The Testing checklist panel, opened from an admin bar item on the front end and in wp-admin alike.
 *
 * Where the admin bar is not showing (the pop-out window, or a tester who has
 * turned the toolbar off), src/panel.js shows a floating button instead.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Panel;

use HM\UAT_Checklist;
use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Reports;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Screenshots;
use HM\UAT_Checklist\Styles;
use WP_Admin_Bar;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Query;

defined( 'ABSPATH' ) || exit;

const HANDLE = 'uat-checklist-panel';

const REPORT_HANDLE = 'uat-checklist-report';

const NODE_ID = 'uat-checklist';

const HUB_NODE_ID = 'uat-hub';

const GUIDE_NODE_ID = 'uat-guide';

/**
 * Default slug of the optional top-level hub page that lists a site's UAT pages. Filtered by
 * `hm_uat_checklist_hub_slug`.
 */
const HUB_SLUG = 'hub';

/**
 * Default slug of the optional guide to the panel itself, a child of the hub page or, with no hub, a top-level
 * page. Filtered by `hm_uat_checklist_guide_slug`.
 */
const GUIDE_SLUG = 'using-the-checklist';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'admin_bar_menu', __NAMESPACE__ . '\\add_node', 80 );
	add_action( 'admin_bar_menu', __NAMESPACE__ . '\\add_hub_node', 81 );
	add_action( 'admin_bar_menu', __NAMESPACE__ . '\\add_guide_node', 82 );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_report' );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_report' );
	add_action( 'admin_print_styles', __NAMESPACE__ . '\\print_admin_font_faces' );
}

/**
 * Returns whether the panel loads: for a logged-in member of the site, once the build exists.
 *
 * @return bool Whether to load.
 */
function should_load(): bool {
	return Results\can_record()
		&& file_exists( UAT_Checklist\PATH . 'build/panel.asset.php' );
}

/**
 * Returns whether the Report an issue dialog loads: for anyone who may submit a report, whether or not the panel
 * loads for them, once the build exists.
 *
 * Not in the pop-out window, which cannot tell which page is under test, and not on a block editor screen the panel
 * does not load on, where the top-right button would be all there is to show and it would sit over the editor's
 * own toolbar.
 *
 * @return bool Whether to load.
 */
function report_should_load(): bool {
	return Reports\can_submit()
		&& file_exists( UAT_Checklist\PATH . 'build/report.asset.php' )
		&& ! Blocks\is_window_view()
		&& ( should_load() || ! is_editor_screen() );
}

/**
 * Returns whether this is a block editor or site editor screen, whose toolbar sits where the Report an issue
 * button would.
 *
 * @return bool Whether this is an editor screen.
 */
function is_editor_screen(): bool {
	if ( ! is_admin() ) {
		return false;
	}

	$screen = get_current_screen();

	return $screen !== null && ( $screen->is_block_editor() || $screen->base === 'site-editor' );
}

/**
 * Adds the Testing checklist item to the admin bar: the tick and a short label, with the full name in a tooltip.
 * A click opens the panel; its dropdown holds the hub page and the guide to the panel.
 *
 * @param WP_Admin_Bar $admin_bar Admin bar.
 * @return void
 */
function add_node( WP_Admin_Bar $admin_bar ): void {
	if ( ! should_load() ) {
		return;
	}

	$label = __( 'Testing checklist', 'uat-checklist' );
	/* translators: Admin bar label beside the tick icon. Keep it as short as the English. */
	$short_label = __( 'UAT', 'uat-checklist' );

	$admin_bar->add_node(
		[
			'id'    => NODE_ID,
			'title' => '<span class="ab-icon" aria-hidden="true"></span>'
				. '<span class="ab-label">' . esc_html( $short_label ) . '</span>'
				. '<span class="screen-reader-text"> ' . esc_html( $label ) . '</span>',
			'href'  => '#',
			'meta'  => [ 'title' => $label ],
		]
	);
}

/**
 * Adds a link to the site's hub page in the UAT item's dropdown, when the site has one.
 *
 * @param WP_Admin_Bar $admin_bar Admin bar.
 * @return void
 */
function add_hub_node( WP_Admin_Bar $admin_bar ): void {
	if ( should_load() ) {
		add_page_node( $admin_bar, HUB_NODE_ID, esc_html__( 'UAT hub page', 'uat-checklist' ), hub_page_id() );
	}
}

/**
 * Adds a link to the guide to the panel in the UAT item's dropdown, once that page is published.
 *
 * @param WP_Admin_Bar $admin_bar Admin bar.
 * @return void
 */
function add_guide_node( WP_Admin_Bar $admin_bar ): void {
	if ( should_load() ) {
		add_page_node(
			$admin_bar,
			GUIDE_NODE_ID,
			esc_html__( 'How to use the checklist', 'uat-checklist' ),
			guide_page_id()
		);
	}
}

/**
 * Adds a link to a page in the UAT item's dropdown, unless the page ID is 0.
 *
 * @param WP_Admin_Bar $admin_bar Admin bar.
 * @param string       $node_id   Node ID.
 * @param string       $title     Link text, already escaped.
 * @param int          $page_id   Page ID, 0 for none.
 * @return void
 */
function add_page_node( WP_Admin_Bar $admin_bar, string $node_id, string $title, int $page_id ): void {
	if ( $page_id === 0 ) {
		return;
	}

	$admin_bar->add_node(
		[
			'id'     => $node_id,
			'parent' => NODE_ID,
			'title'  => $title,
			'href'   => (string) get_permalink( $page_id ),
		]
	);
}

/**
 * Returns the ID of the published hub page at the top of the site, or 0 when there is none.
 *
 * @return int Page ID.
 */
function hub_page_id(): int {
	/**
	 * Filters the slug of the top-level hub page linked from the admin bar. An empty string turns the link off.
	 *
	 * @param string $slug Page slug.
	 */
	$slug = (string) apply_filters( 'hm_uat_checklist_hub_slug', HUB_SLUG );

	return $slug === '' ? 0 : published_page_id( $slug, 0 );
}

/**
 * Returns the ID of the published guide to the panel, under the hub page or at the top level when there is no
 * hub, or 0 when there is none.
 *
 * @return int Page ID.
 */
function guide_page_id(): int {
	/**
	 * Filters the slug of the guide to the panel linked from the admin bar. An empty string turns the link off.
	 *
	 * @param string $slug Page slug.
	 */
	$slug = (string) apply_filters( 'hm_uat_checklist_guide_slug', GUIDE_SLUG );

	return $slug === '' ? 0 : published_page_id( $slug, hub_page_id() );
}

/**
 * Returns the ID of the published page with a slug under a parent, or 0.
 *
 * @param string $slug      Page slug.
 * @param int    $parent_id Parent page ID, 0 for the top level.
 * @return int Page ID.
 */
function published_page_id( string $slug, int $parent_id ): int {
	$query = new WP_Query(
		[
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'name'                   => $slug,
			'post_parent'            => $parent_id,
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]
	);

	return (int) ( $query->posts[0] ?? 0 );
}

/**
 * Prints the theme's @font-face rules in wp-admin, so the panel and the report dialog are set in the same face as on
 * the front end.
 *
 * The rules only declare the face: the browser downloads a file when the panel's text needs it. A user who gets only
 * the report button needs them too, as the button is on screen from the start and is set in the theme's face.
 *
 * @return void
 */
function print_admin_font_faces(): void {
	if ( should_load() || report_should_load() ) {
		wp_print_font_faces();
	}
}

/**
 * Enqueues the panel script, the checklist block's stylesheet and the panel's own.
 *
 * @return void
 */
function enqueue(): void {
	if ( ! should_load() ) {
		return;
	}

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- this plugin's own build manifest, existence checked in should_load().
	$asset = require UAT_Checklist\PATH . 'build/panel.asset.php';

	wp_enqueue_script(
		HANDLE,
		plugins_url( 'build/panel.js', UAT_Checklist\FILE ),
		$asset['dependencies'],
		$asset['version'],
		[
			'in_footer' => true,
			'strategy'  => 'defer',
		]
	);

	// Block editorScripts get this call from core; a hand-enqueued script does not.
	wp_set_script_translations( HANDLE, 'uat-checklist' );

	wp_add_inline_script(
		HANDLE,
		inline_data( [ 'current' => current_checklist() ] ),
		'before'
	);

	wp_enqueue_style(
		HANDLE,
		plugins_url( 'build/panel.css', UAT_Checklist\FILE ),
		[ Styles\HANDLE, ...checklist_style_handles() ],
		$asset['version']
	);

	// The front end already has the theme's variables on :root; wp-admin does not load the theme's styles.
	$variables = is_admin() ? scoped_theme_variables() : '';

	if ( $variables !== '' ) {
		wp_add_inline_style( HANDLE, $variables );
	}
}

/**
 * Enqueues the Report an issue script and stylesheet.
 *
 * @return void
 */
function enqueue_report(): void {
	if ( ! report_should_load() ) {
		return;
	}

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- this plugin's own build manifest, existence checked in report_should_load().
	$asset = require UAT_Checklist\PATH . 'build/report.asset.php';

	wp_enqueue_script(
		REPORT_HANDLE,
		plugins_url( 'build/report.js', UAT_Checklist\FILE ),
		$asset['dependencies'],
		$asset['version'],
		[
			'in_footer' => true,
			'strategy'  => 'defer',
		]
	);

	wp_set_script_translations( REPORT_HANDLE, 'uat-checklist' );

	wp_add_inline_script( REPORT_HANDLE, inline_data( [ 'report' => report_data() ] ), 'before' );

	wp_enqueue_style(
		REPORT_HANDLE,
		plugins_url( 'build/report.css', UAT_Checklist\FILE ),
		[ Styles\HANDLE ],
		$asset['version']
	);

	// The panel's stylesheet carries these when it loads too; a user it does not load for needs them here.
	$variables = is_admin() && ! should_load() ? scoped_theme_variables() : '';

	if ( $variables !== '' ) {
		wp_add_inline_style( REPORT_HANDLE, $variables );
	}
}

/**
 * Returns the script that adds data to `window.hmUatChecklist`, which the panel and the report dialog share.
 *
 * Merged rather than assigned, so neither script's data depends on which prints first.
 *
 * @param array<string, mixed> $data Data to add.
 * @return string Inline script.
 */
function inline_data( array $data ): string {
	return 'window.hmUatChecklist = Object.assign( window.hmUatChecklist || {}, ' . wp_json_encode( $data ) . ' );';
}

/**
 * Returns what the report dialog shows and checks: the screen's page, the signed-in user and the limits.
 *
 * The address and title the report records are read from the browser when the dialog opens, which is also what it
 * shows. Only the page's ID is the server's to give, and only on the front end: a wp-admin screen is not a post.
 * `toggle` is whether the top-right button is added; the panel's footer opens the dialog wherever the panel loads.
 *
 * @return array{page: array{id: int}, user: string, toggle: bool, maxBytes: int, maxSize: string, maxLength: int, maxTitle: int} Report data.
 */
function report_data(): array {
	return [
		'page'      => [ 'id' => is_admin() ? 0 : get_queried_object_id() ],
		'user'      => wp_get_current_user()->display_name,
		'toggle'    => ! is_editor_screen(),
		'maxBytes'  => Screenshots\max_bytes(),
		'maxSize'   => size_format( Screenshots\max_bytes() ),
		'maxLength' => Reports\DESCRIPTION_MAX_LENGTH,
		'maxTitle'  => Reports\PAGE_TITLE_MAX_LENGTH,
	];
}

/**
 * Returns the checklist that belongs to this screen, which the panel opens with: the one the pop-out window was
 * opened for, or the one whose instructions page this is. 0 elsewhere, where the panel opens the tester's last.
 *
 * @return int Checklist ID.
 */
function current_checklist(): int {
	$requested = Blocks\requested_checklist();

	if ( $requested > 0 && Checklists\is_published_checklist( $requested ) ) {
		return $requested;
	}

	return ! is_admin() && is_singular() ? Checklists\checklist_for_page( get_queried_object_id() ) : 0;
}

/**
 * Returns the checklist block's registered front-end stylesheet handles.
 *
 * @return string[] Style handles.
 */
function checklist_style_handles(): array {
	$block = WP_Block_Type_Registry::get_instance()->get_registered( Blocks\CHECKLIST_BLOCK );

	return $block instanceof WP_Block_Type ? $block->view_style_handles : [];
}

/**
 * Returns the theme's `:root` CSS variables from theme.json, re-scoped to the panel so wp-admin's own screens never see them.
 *
 * The design tokens in inc/styles.php point at the theme's presets, which wp-admin does not otherwise load.
 *
 * @return string Stylesheet, or '' when the theme emits no `:root` rule.
 */
function scoped_theme_variables(): string {
	if ( ! preg_match( '/:root\{[^}]*\}/', wp_get_global_stylesheet( [ 'variables' ] ), $matches ) ) {
		return '';
	}

	return Styles\SCOPE . substr( $matches[0], strlen( ':root' ) );
}
