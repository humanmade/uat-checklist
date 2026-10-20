<?php
/**
 * Registers the checklist blocks, finds their steps in a checklist's content, and renders a checklist on its own.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Blocks;

use HM\UAT_Checklist;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Styles;
use WP_Block;
use WP_Block_Type;
use WP_Post;

defined( 'ABSPATH' ) || exit;

const CHECKLIST_BLOCK = 'uat-checklist/checklist';
const SECTION_BLOCK   = 'uat-checklist/section';
const STEP_BLOCK      = 'uat-checklist/step';
const PARAGRAPH_BLOCK = 'core/paragraph';

/**
 * Query variable, and its value, that shows a page without the site header and footer for the pop-out window, and
 * the variable naming the checklist the pop-out opens.
 */
const VIEW_PARAM      = 'uat-view';
const WINDOW_VIEW     = 'window';
const WINDOW_CLASS    = 'uat-window';
const CHECKLIST_PARAM = 'uat-checklist';

/*
 * Window names the two links open into. One name each for every page, so a tester has one checklist window and
 * one instructions window, reused as they move between pages, never one of each per page.
 */
const CHECKLIST_WINDOW    = 'uat-checklist';
const INSTRUCTIONS_WINDOW = 'uat-instructions';

/**
 * Fragment on the "Open in new window" link that tells the pop-out to open its panel. Mirrored in
 * src/lib/checklist.js as WINDOW_HASH.
 */
const WINDOW_HASH = 'uat-checklist';

/**
 * Block directories under `blocks/`, registered from their compiled copies in `build/blocks/`.
 */
const BLOCKS = [ 'checklist', 'section', 'step' ];

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register_blocks' );
	add_filter( 'body_class', __NAMESPACE__ . '\\add_window_class' );
	// After Yoast, which sets the title at 15 and so stops core's document_title_parts filter from running.
	add_filter( 'pre_get_document_title', __NAMESPACE__ . '\\title_window', 20 );
	// phpcs:ignore WordPressVIPMinimum.UserExperience.AdminBarRemoval.RemovalDetected -- the bar is dropped for every role in the checklist's pop-out window only; every other request keeps it.
	add_filter( 'show_admin_bar', __NAMESPACE__ . '\\hide_admin_bar_in_window' );
}

/**
 * Turns the admin bar off in the pop-out window view, leaving every other request as it was.
 *
 * VIP's debug mode forces the admin bar back on at PHP_INT_MAX
 * (`mu-plugins/000-debug/debug-mode.php`), so while it is on the bar still shows.
 *
 * @param bool $show Whether the admin bar shows.
 * @return bool Whether the admin bar shows.
 */
function hide_admin_bar_in_window( bool $show ): bool {
	return $show && ! is_window_view();
}

/**
 * Returns whether the request asks for the pop-out window view.
 *
 * @return bool Whether this is the window view.
 */
function is_window_view(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only display switch; it changes nothing.
	return isset( $_GET[ VIEW_PARAM ] ) && sanitize_key( wp_unslash( $_GET[ VIEW_PARAM ] ) ) === WINDOW_VIEW;
}

/**
 * Returns the checklist the pop-out window was opened for, or 0 outside the window view.
 *
 * @return int Checklist ID.
 */
function requested_checklist(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only display switch; it changes nothing.
	return is_window_view() && isset( $_GET[ CHECKLIST_PARAM ] )
		? absint( wp_unslash( $_GET[ CHECKLIST_PARAM ] ) )
		: 0;
}

/**
 * Adds the class the checklist stylesheet hides the site header and footer by, in the window view.
 *
 * @param string[] $classes Body classes.
 * @return string[] Body classes.
 */
function add_window_class( array $classes ): array {
	if ( is_window_view() ) {
		$classes[] = WINDOW_CLASS;
	}

	return $classes;
}

/**
 * Returns the ID of the step a checklist opens at: the first whose latest result is Fixed, so a retest comes before
 * new work, else the first with no result, else ''.
 *
 * Decided once per checklist from its step IDs, and each step compares its own, because a checklist's inner blocks
 * can be rendered more than once and the first step to ask is then not the first in the checklist.
 *
 * @param int $checklist_id Checklist.
 * @return string Step ID.
 */
function step_to_open( int $checklist_id ): string {
	static $open = [];

	if ( ! isset( $open[ $checklist_id ] ) ) {
		$step_ids = step_ids_in( parse_blocks( get_post_field( 'post_content', $checklist_id ) ) );
		$latest   = Results\get_checklist_results( $checklist_id );
		$retest   = array_filter(
			$step_ids,
			static fn ( string $id ): bool => ( $latest[ $id ] ?? null )?->post_status === Results\FIXED_STATUS
		);
		$untested = array_filter( $step_ids, static fn ( string $id ): bool => ! isset( $latest[ $id ] ) );

		$open[ $checklist_id ] = (string) ( reset( $retest ) ?: reset( $untested ) ?: '' );
	}

	return $open[ $checklist_id ];
}

/**
 * Returns a checklist's title as plain text.
 *
 * The title is decoded because get_the_title() texturizes, turning `&` and `'` into entities that a plain-text consumer
 * such as the panel's textContent or an email would show literally. Escape the result wherever it goes into HTML.
 *
 * @param WP_Post|int $checklist Checklist.
 * @return string Title.
 */
function checklist_title( WP_Post|int $checklist ): string {
	return html_entity_decode(
		trim( wp_strip_all_tags( get_the_title( $checklist ) ) ),
		ENT_QUOTES | ENT_HTML5,
		'UTF-8'
	);
}

/**
 * Titles the pop-out window after its checklist, so it is easy to find among the tester's windows.
 *
 * @param string $title Title so far, from an earlier filter such as Yoast's.
 * @return string The checklist's title in the pop-out view, otherwise the title it was given.
 */
function title_window( string $title ): string {
	$checklist_id = requested_checklist();

	if ( $checklist_id === 0 || ! Checklists\is_published_checklist( $checklist_id ) ) {
		return $title;
	}

	// Escaped here because core returns a pre_get_document_title value as it is, and the title is plain text.
	return esc_html(
		sprintf(
			/* translators: %s: the checklist's title. */
			__( 'Testing checklist: %s', 'uat-checklist' ),
			checklist_title( $checklist_id )
		)
	);
}

/**
 * Renders a checklist's inner blocks as its intro, the paragraphs above its first section, and its body, the rest.
 *
 * Empty paragraphs are left out, so the template's unfilled intro paragraph renders nothing.
 *
 * @param WP_Block $block Checklist block.
 * @return array{intro: string, body: string} Rendered HTML.
 */
function render_checklist_parts( WP_Block $block ): array {
	$parts    = [
		'intro' => '',
		'body'  => '',
	];
	$in_intro = true;

	foreach ( $block->inner_blocks as $inner ) {
		$is_paragraph = $inner->name === PARAGRAPH_BLOCK;
		$in_intro     = $in_intro && $is_paragraph;
		$html         = $inner->render();

		if ( $is_paragraph && trim( wp_strip_all_tags( $html ) ) === '' ) {
			continue;
		}

		$parts[ $in_intro ? 'intro' : 'body' ] .= $html;
	}

	return $parts;
}

/**
 * Returns the step IDs in a parsed block tree, in document order.
 *
 * A step is required unless its `required` attribute is false, so a page built before the attribute counts
 * every step as required.
 *
 * @param array<int, array<string, mixed>> $blocks        Parsed blocks.
 * @param bool                             $required_only Whether to leave out the steps marked nice to have.
 * @return string[] Step IDs.
 */
function step_ids_in( array $blocks, bool $required_only = false ): array {
	$ids = [];

	foreach ( $blocks as $block ) {
		$is_step = $block['blockName'] === STEP_BLOCK && ( $block['attrs']['stepId'] ?? '' ) !== '';

		if ( $is_step && ( ! $required_only || ( $block['attrs']['required'] ?? true ) !== false ) ) {
			$ids[] = (string) $block['attrs']['stepId'];
		}

		$ids = array_merge( $ids, step_ids_in( $block['innerBlocks'] ?? [], $required_only ) );
	}

	return $ids;
}

/**
 * Registers each block whose compiled metadata exists; nothing registers before the first `npm run build`.
 *
 * @return void
 */
function register_blocks(): void {
	foreach ( BLOCKS as $slug ) {
		$metadata_path = UAT_Checklist\PATH . "build/blocks/{$slug}";

		if ( file_exists( $metadata_path . '/block.json' ) ) {
			$block_type = register_block_type_from_metadata( $metadata_path );

			if ( $block_type instanceof WP_Block_Type ) {
				version_styles( $block_type, $metadata_path . '/index.asset.php' );
				depend_on_tokens( $block_type );
			}
		}
	}
}

/**
 * Versions a block's stylesheets with its build hash.
 *
 * Core versions them with block.json's fixed `version`, so a browser keeps serving the old CSS after a build.
 * The hash in `index.asset.php` changes whenever the block's script or styles do.
 *
 * @param WP_Block_Type $block_type Registered block.
 * @param string        $asset_path Path to the block's `index.asset.php`.
 * @return void
 */
function version_styles( WP_Block_Type $block_type, string $asset_path ): void {
	if ( ! file_exists( $asset_path ) ) {
		return;
	}

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- this plugin's own build manifest, existence checked above.
	$asset = require $asset_path;

	foreach ( style_handles( $block_type ) as $handle ) {
		if ( isset( wp_styles()->registered[ $handle ] ) ) {
			wp_styles()->registered[ $handle ]->ver = $asset['version'];
		}
	}
}

/**
 * Makes a block's stylesheets load the design tokens first, on the front end and in the editor canvas alike.
 *
 * @param WP_Block_Type $block_type Registered block.
 * @return void
 */
function depend_on_tokens( WP_Block_Type $block_type ): void {
	foreach ( style_handles( $block_type ) as $handle ) {
		$style = wp_styles()->registered[ $handle ] ?? null;

		if ( $style !== null && ! in_array( Styles\HANDLE, $style->deps, true ) ) {
			$style->deps[] = Styles\HANDLE;
		}
	}
}

/**
 * Returns every stylesheet handle a block registers: front end, view and editor, each once.
 *
 * @param WP_Block_Type $block_type Registered block.
 * @return string[] Style handles.
 */
function style_handles( WP_Block_Type $block_type ): array {
	return array_unique(
		array_merge(
			$block_type->style_handles,
			$block_type->view_style_handles,
			$block_type->editor_style_handles
		)
	);
}

/**
 * Returns the parsed step block with the given ID from a checklist's content, or null when it has none.
 *
 * @param WP_Post $checklist Checklist.
 * @param string  $step_id   Step ID.
 * @return array<string, mixed>|null Parsed block.
 */
function find_step( WP_Post $checklist, string $step_id ): ?array {
	return find_block_in(
		parse_blocks( $checklist->post_content ),
		static fn ( array $block ): bool => $block['blockName'] === STEP_BLOCK
			&& ( $block['attrs']['stepId'] ?? '' ) === $step_id
	);
}

/**
 * Returns the parsed Testing checklist block from a checklist's content, or null when it has none.
 *
 * @param WP_Post $checklist Checklist.
 * @return array<string, mixed>|null Parsed block.
 */
function find_checklist_block( WP_Post $checklist ): ?array {
	return find_block_in(
		parse_blocks( $checklist->post_content ),
		static fn ( array $block ): bool => $block['blockName'] === CHECKLIST_BLOCK
	);
}

/**
 * Searches a parsed block tree, depth first, for the first block the callback accepts.
 *
 * @param array<int, array<string, mixed>> $blocks  Parsed blocks.
 * @param callable                         $matches Receives a parsed block, returns whether it is the one.
 * @return array<string, mixed>|null Parsed block.
 */
function find_block_in( array $blocks, callable $matches ): ?array {
	foreach ( $blocks as $block ) {
		if ( $matches( $block ) ) {
			return $block;
		}

		$found = find_block_in( $block['innerBlocks'] ?? [], $matches );

		if ( $found !== null ) {
			return $found;
		}
	}

	return null;
}

/**
 * Returns a checklist rendered for the current user, or '' when its content holds no Testing checklist block.
 *
 * @param WP_Post $checklist Checklist.
 * @return string Checklist markup.
 */
function render_checklist( WP_Post $checklist ): string {
	$block = find_checklist_block( $checklist );

	if ( $block === null ) {
		return '';
	}

	// The renderers read the checklist from the global post: get_the_ID() and the postId context.
	global $post;
	$previous = $post;
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below; render_block() takes the checklist from here.
	$post = $checklist;
	setup_postdata( $checklist );

	$html = render_block( $block );

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the value saved above.
	$post = $previous;
	wp_reset_postdata();

	return $html;
}
