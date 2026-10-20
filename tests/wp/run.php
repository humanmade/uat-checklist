<?php
/**
 * Runs every tests/wp/test-*.php inside wp-env, through `npm run test:wp`.
 *
 * No `declare( strict_types=1 )`: `wp eval-file` evals this file, and PHP fatals on the
 * declaration anywhere but a file's first statement. See AGENTS.md, "Three kinds of test".
 */

require_once __DIR__ . '/helpers.php';

echo "The plugin is active\n";
$active = function_exists( 'HM\\UAT_Checklist\\Styles\\tokens' );
check( 'uat-checklist is active', $active, true );

// Every check below calls the plugin's functions; without them the run is meaningless.
if ( ! $active ) {
	printf( "\n%d passed, %d failed\n", $GLOBALS['hm_test']['pass'], $GLOBALS['hm_test']['fail'] );
	exit( 1 );
}

$files = glob( __DIR__ . '/test-*.php' );
sort( $files );

foreach ( $files as $file ) {
	// One file's crash is one failure, not the end of the run, so the report still names everything else.
	try {
		require $file;
	} catch ( Throwable $e ) {
		check(
			basename( $file ) . ' ran to the end',
			get_class( $e ) . ': ' . $e->getMessage(),
			'no exception'
		);
	}
}

printf( "\n%d passed, %d failed\n", $GLOBALS['hm_test']['pass'], $GLOBALS['hm_test']['fail'] );
exit( $GLOBALS['hm_test']['fail'] === 0 ? 0 : 1 );
