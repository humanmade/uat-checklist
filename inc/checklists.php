<?php
/**
 * Checklists: the private `uat_checklist` post type the panel lists, each holding one Testing checklist block, and
 * the optional page that holds its instructions.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Checklists;

use HM\UAT_Checklist;
use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Overview;
use HM\UAT_Checklist\Results;
use WP_Error;
use WP_Query;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

const POST_TYPE = 'uat_checklist';

const REST_BASE = 'uat-checklists';

/**
 * Post meta holding the ID of the page a checklist's instructions are on, 0 for none.
 */
const INSTRUCTIONS_META = '_uat_instructions_page';

const EDITOR_HANDLE = 'uat-checklist-checklist-editor';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register_post_type_and_meta' );
	add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\\restrict_rest', 10, 3 );
	add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor' );
}

/**
 * Registers the checklist post type, edited in the block editor and never shown on the front end, and its meta.
 *
 * @return void
 */
function register_post_type_and_meta(): void {
	register_post_type(
		POST_TYPE,
		[
			'labels'          => [
				'name'               => __( 'Checklists', 'uat-checklist' ),
				'singular_name'      => __( 'Checklist', 'uat-checklist' ),
				'menu_name'          => __( 'UAT', 'uat-checklist' ),
				'all_items'          => __( 'Checklists', 'uat-checklist' ),
				'add_new_item'       => __( 'Add checklist', 'uat-checklist' ),
				'edit_item'          => __( 'Edit checklist', 'uat-checklist' ),
				'new_item'           => __( 'New checklist', 'uat-checklist' ),
				'search_items'       => __( 'Search checklists', 'uat-checklist' ),
				'not_found'          => __( 'No checklists yet.', 'uat-checklist' ),
				'not_found_in_trash' => __( 'No checklists in the trash.', 'uat-checklist' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => Overview\PAGE_SLUG,
			// The block editor reads and saves through REST; restrict_rest() keeps it to members of the site.
			'show_in_rest'    => true,
			'rest_base'       => REST_BASE,
			'supports'        => [ 'title', 'editor', 'custom-fields', 'revisions' ],
			'rewrite'         => false,
			'query_var'       => false,
			// Testers only read; writing a checklist takes the UAT manager capability, whatever the user's role.
			'capabilities'    => array_fill_keys(
				[
					'edit_posts',
					'edit_others_posts',
					'edit_published_posts',
					'edit_private_posts',
					'publish_posts',
					'read_private_posts',
					'delete_posts',
					'delete_others_posts',
					'delete_published_posts',
					'delete_private_posts',
					'create_posts',
				],
				Access\CAP_MANAGE
			),
			'map_meta_cap'    => true,
			'template'        => [ [ Blocks\CHECKLIST_BLOCK ] ],
			'template_lock'   => 'insert',
		]
	);

	register_post_meta(
		POST_TYPE,
		INSTRUCTIONS_META,
		[
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => __NAMESPACE__ . '\\can_edit_meta',
		]
	);
}

/**
 * Returns whether the current user may set a checklist's instructions page: whoever may edit the checklist.
 *
 * @param bool   $allowed  Whether the user may edit the meta so far.
 * @param string $meta_key Meta key.
 * @param int    $post_id  Checklist ID.
 * @return bool Whether they may.
 */
function can_edit_meta( bool $allowed, string $meta_key, int $post_id ): bool {
	return current_user_can( 'edit_post', $post_id );
}

/**
 * Refuses core's checklist routes to anyone who is not a logged-in member of the site. Core would otherwise serve a
 * published checklist to any visitor, since it checks only the post status.
 *
 * @param WP_HTTP_Response|WP_Error|mixed $response Response so far, usually null.
 * @param array<string, mixed>            $handler  Route handler.
 * @param WP_REST_Request                 $request  Request.
 * @return WP_HTTP_Response|WP_Error|mixed Response, or the refusal.
 */
function restrict_rest( mixed $response, array $handler, WP_REST_Request $request ): mixed {
	if ( ! str_starts_with( $request->get_route(), '/wp/v2/' . REST_BASE ) || Results\can_record() ) {
		return $response;
	}

	return new WP_Error(
		'rest_forbidden',
		__( 'Sorry, you are not allowed to read checklists.', 'uat-checklist' ),
		[ 'status' => rest_authorization_required_code() ]
	);
}

/**
 * Enqueues the instructions page setting in the editor, on checklists only.
 *
 * @return void
 */
function enqueue_editor(): void {
	if (
		get_current_screen()?->post_type !== POST_TYPE
		|| ! file_exists( UAT_Checklist\PATH . 'build/checklist-editor.asset.php' )
	) {
		return;
	}

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- this plugin's own build manifest, existence checked above.
	$asset = require UAT_Checklist\PATH . 'build/checklist-editor.asset.php';

	wp_enqueue_script(
		EDITOR_HANDLE,
		plugins_url( 'build/checklist-editor.js', UAT_Checklist\FILE ),
		$asset['dependencies'],
		$asset['version'],
		true
	);
	wp_set_script_translations( EDITOR_HANDLE, 'uat-checklist' );
}

/**
 * Returns whether a post is a published checklist.
 *
 * @param mixed $post Post or post ID.
 * @return bool Whether it is.
 */
function is_published_checklist( mixed $post ): bool {
	$post = get_post( $post );

	return $post !== null && $post->post_type === POST_TYPE && $post->post_status === 'publish';
}

/**
 * Returns the ID of a checklist's instructions page while that page is published, or 0.
 *
 * @param int $checklist_id Checklist ID.
 * @return int Page ID.
 */
function instructions_page_id( int $checklist_id ): int {
	$page_id = (int) get_post_meta( $checklist_id, INSTRUCTIONS_META, true );

	return $page_id > 0 && get_post_status( $page_id ) === 'publish' ? $page_id : 0;
}

/**
 * Returns the address of a checklist's instructions page, or '' when it has none.
 *
 * @param int $checklist_id Checklist ID.
 * @return string URL.
 */
function instructions_url( int $checklist_id ): string {
	$page_id = instructions_page_id( $checklist_id );

	return $page_id > 0 ? (string) get_permalink( $page_id ) : '';
}

/**
 * Returns the address the checklist's pop-out window opens: its instructions page, or the home page when it has
 * none, in the window view with the checklist named.
 *
 * @param int $checklist_id Checklist ID.
 * @return string URL.
 */
function window_url( int $checklist_id ): string {
	$base = instructions_url( $checklist_id );

	return add_query_arg(
		[
			Blocks\VIEW_PARAM      => Blocks\WINDOW_VIEW,
			Blocks\CHECKLIST_PARAM => $checklist_id,
		],
		$base !== '' ? $base : home_url( '/' )
	) . '#' . Blocks\WINDOW_HASH;
}

/**
 * Returns the published checklist whose instructions are on a page, or 0 when none names it.
 *
 * @param int $page_id Page ID.
 * @return int Checklist ID.
 */
function checklist_for_page( int $page_id ): int {
	if ( $page_id <= 0 ) {
		return 0;
	}

	$query = new WP_Query(
		[
			'post_type'              => POST_TYPE,
			'post_status'            => 'publish',
			'meta_key'               => INSTRUCTIONS_META,
			'meta_value'             => (string) $page_id, // phpcs:ignore HM.Performance.SlowMetaQuery.slow_query_meta_value -- the meta_key index narrows it to one row per checklist.
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]
	);

	return (int) ( $query->posts[0] ?? 0 );
}
