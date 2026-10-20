<?php
/**
 * Loads the plugin's translations. Checklist content is written in the site's own language, so only the
 * plugin's interface strings are translated here.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\I18n;

use HM\UAT_Checklist;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	// Before init, where the result post type's labels and the block metadata are translated.
	add_action( 'after_setup_theme', __NAMESPACE__ . '\\load_textdomain' );
}

/**
 * Registers languages/ as the home of the plugin's catalogs, for PHP strings and script translations alike.
 *
 * A language pack under wp-content/languages/plugins/ is loaded in preference.
 *
 * @return void
 */
function load_textdomain(): void {
	load_plugin_textdomain(
		'uat-checklist',
		false,
		dirname( plugin_basename( UAT_Checklist\FILE ) ) . '/languages'
	);
}
