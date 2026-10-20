<?php
/**
 * Asserts the five places carrying the plugin version agree.
 *
 * The release workflow compares the tag against the `Version:` header alone, so the header
 * can be bumped correctly while the constant, the stable tag and the changelog stay behind
 * and the release still goes out. See AGENTS.md: "Versioning".
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$pass = 0;
$fail = 0;

function check( string $label, $actual, $expected ): void {
	global $pass, $fail;

	if ( $actual === $expected ) {
		++$pass;
		printf( "  ok    %s\n", $label );
		return;
	}

	++$fail;
	printf(
		"  FAIL  %s\n        expected %s, got %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

/**
 * First capture of a pattern, or null when it does not match.
 *
 * Returning null rather than '' keeps a missing line distinguishable from an empty one, so
 * a renamed header is reported as absent instead of as a mismatch against every other file.
 */
function first_match( string $pattern, string $subject ): ?string {
	return preg_match( $pattern, $subject, $m ) === 1 ? $m[1] : null;
}

$plugin = (string) file_get_contents( "{$root}/uat-checklist.php" );
$readme = (string) file_get_contents( "{$root}/readme.txt" );

/**
 * package.json carries a version only because npm-package-json-lint's `require-version`
 * demands one, and this package is private and never published. Left unchecked it is a
 * number with no consumer, which is exactly the kind that drifts.
 *
 * @var array{version?: string} $package
 */
$package = (array) json_decode( (string) file_get_contents( "{$root}/package.json" ), true );

$header    = first_match( '/^ \* Version: +(.+)$/m', $plugin );
$constant  = first_match( "/^const VERSION = '(.+)';$/m", $plugin );
$stable    = first_match( '/^Stable tag: (.+)$/m', $readme );
$changelog = first_match( '/^== Changelog ==\s*\R+= (.+) =$/m', $readme );
$manifest  = $package['version'] ?? null;

echo "Every location is present\n";
check( 'the plugin header carries a version', $header !== null, true );
check( 'the VERSION constant is declared', $constant !== null, true );
check( 'readme.txt carries a stable tag', $stable !== null, true );
check( 'the changelog opens with an entry', $changelog !== null, true );
check( 'package.json carries a version', $manifest !== null, true );

echo "\nThey agree\n";
// Compared against the header rather than pairwise, so a single wrong file is named once
// instead of turning up in four failures.
check( 'the VERSION constant matches the header', $constant, $header );
check( 'the stable tag matches the header', $stable, $header );
check( 'the newest changelog entry matches the header', $changelog, $header );
check( 'the package.json version matches the header', $manifest, $header );

echo "\nThe version is well formed\n";
// A stable tag WordPress cannot parse silently pins the wrong release, and the release
// workflow's `${VERSION#v}` comparison assumes no leading v here.
check( 'it is a bare x.y.z', preg_match( '/^\d+\.\d+\.\d+$/', (string) $header ) === 1, true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
