<?php
/**
 * The design tokens: reading theme.json values, their fallbacks, both filters, and what reaches the stylesheet.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Styles;

echo "\ntheme.json values become CSS\n";
check(
	'a preset reference becomes its custom property',
	Styles\to_css( [], 'var:preset|color|primary' ),
	'var(--wp--preset--color--primary)'
);
check( 'a CSS-wide keyword is unusable', Styles\to_css( [], 'inherit' ), '' );
check( 'a non-string is unusable', Styles\to_css( [], 12 ), '' );
check(
	'a ref resolves against the global styles',
	Styles\to_css( [ 'color' => [ 'text' => '#111111' ] ], [ 'ref' => 'styles.color.text' ] ),
	'#111111'
);
check(
	'a ref to a preset resolves to the preset',
	Styles\to_css( [ 'color' => [ 'text' => 'var:preset|color|contrast' ] ], [ 'ref' => 'styles.color.text' ] ),
	'var(--wp--preset--color--contrast)'
);
check(
	'the first usable path wins',
	Styles\first_value(
		[
			'a' => [ 'b' => 'inherit' ],
			'c' => '#222',
		],
		[ 'a.b', 'c' ]
	),
	'#222'
);

echo "\nEvery token has a value\n";
$tokens = Styles\tokens();
$required_tokens = [
	'color-accent',
	'color-on-accent',
	'color-text',
	'color-background',
	'color-pass',
	'color-fail',
	'font-size',
	'radius',
	'space-s',
	'measure',
];
foreach ( $required_tokens as $name ) {
	check( "{$name} is set", ( $tokens[ $name ] ?? '' ) !== '', true );
}

$spacing = (array) wp_get_global_settings( [ 'spacing', 'spacingSizes' ] );
if ( ! empty( $spacing['theme'] ) || ! empty( $spacing['default'] ) ) {
	check(
		'space-xs comes from the theme\'s spacing scale',
		str_starts_with( $tokens['space-xs'], 'min(var(--wp--preset--spacing--' ),
		true
	);
	check(
		'space-xs is capped for the panel',
		str_ends_with( $tokens['space-xs'], ', ' . Styles\SPACING_STEPS['space-xs'] . ')' ),
		true
	);
}

if ( Styles\first_value( (array) wp_get_global_styles(), [ 'typography.fontSize' ] ) !== '' ) {
	check(
		'the theme\'s body size is held within the panel\'s bounds',
		str_starts_with( $tokens['font-size'], 'clamp(' . Styles\FONT_SIZE_MIN . ', ' ),
		true
	);
}

echo "\nThe filters change the tokens\n";
$from_fallback = hm_test_with_filter(
	'hm_uat_checklist_style_fallbacks',
	static fn ( array $fallbacks ): array => [ 'color-on-status' => '#000000' ] + $fallbacks,
	static fn (): array => Styles\tokens()
);
check( 'a fallback the theme does not set is used', $from_fallback['color-on-status'] ?? null, '#000000' );

$overridden = hm_test_with_filter(
	'hm_uat_checklist_style_tokens',
	static fn ( array $tokens ): array => [ 'color-accent' => 'rebeccapurple' ] + $tokens,
	static fn (): array => Styles\tokens()
);
check( 'the tokens filter has the last word', $overridden['color-accent'] ?? null, 'rebeccapurple' );

echo "\nThe stylesheet\n";
$css = Styles\stylesheet();
check( 'declares the tokens at zero specificity', str_starts_with( $css, ':where(' ), true );
check( 'declares the accent', str_contains( $css, '--hm-uat-color-accent:' ), true );
check(
	'hides the site header in the pop-out window by default',
	str_contains( $css, '.uat-window :is(.wp-site-blocks > header' ),
	true
);

$injected = hm_test_with_filter(
	'hm_uat_checklist_style_tokens',
	static fn ( array $tokens ): array => [ 'x' => 'red;}body{display:none' ] + $tokens,
	static fn (): string => Styles\stylesheet()
);
check( 'a token value cannot close its rule', str_contains( $injected, '}body{' ), false );

$closing = hm_test_with_filter(
	'hm_uat_checklist_style_tokens',
	static fn ( array $tokens ): array => [ 'x' => 'url(</style><script>)' ] + $tokens,
	static fn (): string => Styles\stylesheet()
);
check( 'a token value cannot close the style element', str_contains( $closing, '</style' ), false );

$no_chrome = hm_test_with_filter(
	'hm_uat_checklist_window_hidden_selectors',
	static fn (): array => [],
	static fn (): string => Styles\stylesheet()
);
check( 'an empty selector list hides nothing', str_contains( $no_chrome, '.uat-window' ), false );

echo "\nBlock stylesheets load the tokens first\n";
$block = WP_Block_Type_Registry::get_instance()->get_registered( 'uat-checklist/checklist' );
check( 'the checklist block is registered (needs `npm run build`)', $block instanceof WP_Block_Type, true );
if ( $block instanceof WP_Block_Type ) {
	foreach ( array_unique( array_merge( $block->view_style_handles, $block->editor_style_handles ) ) as $handle ) {
		check(
			"{$handle} depends on the tokens",
			in_array( Styles\HANDLE, wp_styles()->registered[ $handle ]->deps ?? [], true ),
			true
		);
	}
}
