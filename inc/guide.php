<?php
/**
 * The layout of a UAT page's own content: numbered steps, and screenshots the text wraps round.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Guide;

use HM\UAT_Checklist;
use HM\UAT_Checklist\Styles;

defined( 'ABSPATH' ) || exit;

const HANDLE = 'uat-checklist-guide';

/**
 * Hooks the stylesheet onto the front end.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
}

/**
 * Enqueues the guide stylesheet on every front-end page. It styles nothing but its own `uat-` classes, which only
 * pages built for testing carry.
 *
 * @return void
 */
function enqueue(): void {
	if ( ! file_exists( UAT_Checklist\PATH . 'build/guide.asset.php' ) ) {
		return;
	}

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- this plugin's own build manifest, existence checked above.
	$asset = require UAT_Checklist\PATH . 'build/guide.asset.php';

	wp_enqueue_style(
		HANDLE,
		plugins_url( 'build/guide.css', UAT_Checklist\FILE ),
		[ Styles\HANDLE ],
		$asset['version']
	);
}
