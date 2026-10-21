<?php
/**
 * Plugin Name:       UAT Checklist
 * Plugin URI:        https://github.com/humanmade/uat-checklist
 * Description:       Testing checklists for user acceptance testing: testers mark each step Pass or Fail, and the results are stored on the site.
 * Version:           0.4.0
 * Requires at least: 7.1
 * Requires PHP:      8.4
 * Author:            Human Made
 * Author URI:        https://humanmade.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uat-checklist
 * Domain Path:       /languages
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist;

defined( 'ABSPATH' ) || exit;

// Asset cache-busting fallback.
const VERSION = '0.4.0';

const PATH = __DIR__ . '/';

const FILE = __FILE__;

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/styles.php';
require_once __DIR__ . '/inc/blocks.php';
require_once __DIR__ . '/inc/access.php';
require_once __DIR__ . '/inc/checklists.php';
require_once __DIR__ . '/inc/results.php';
require_once __DIR__ . '/inc/screenshots.php';
require_once __DIR__ . '/inc/progress.php';
require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/inc/notifications.php';
require_once __DIR__ . '/inc/retests.php';
require_once __DIR__ . '/inc/overview.php';
require_once __DIR__ . '/inc/abilities.php';
require_once __DIR__ . '/inc/panel.php';
require_once __DIR__ . '/inc/guide.php';
require_once __DIR__ . '/inc/cli.php';

bootstrap();
