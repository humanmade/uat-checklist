<?php
/**
 * Plugin bootstrap registry. Add new modules to `bootstrap()` as they are created.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist;

defined( 'ABSPATH' ) || exit;

/**
 * Boots every plugin module.
 *
 * @return void
 */
function bootstrap(): void {
	I18n\bootstrap();
	Styles\bootstrap();
	Blocks\bootstrap();
	Access\bootstrap();
	Checklists\bootstrap();
	Results\bootstrap();
	Screenshots\bootstrap();
	Reports\bootstrap();
	REST\bootstrap();
	Admin\bootstrap();
	Report_Admin\bootstrap();
	Notifications\bootstrap();
	Retests\bootstrap();
	Overview\bootstrap();
	Abilities\bootstrap();
	Report_Abilities\bootstrap();
	Panel\bootstrap();
	Guide\bootstrap();
	CLI\bootstrap();
}
