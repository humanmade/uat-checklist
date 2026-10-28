<?php
/**
 * The design tokens every plugin stylesheet reads, as `--hm-uat-*` custom properties resolved from the active
 * theme's theme.json.
 *
 * A token takes its value from the theme where theme.json has one, and from its fallback otherwise. Two filters
 * change them: `hm_uat_checklist_style_fallbacks` for the values used when the theme is silent, and
 * `hm_uat_checklist_style_tokens` for the final set.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Styles;

defined( 'ABSPATH' ) || exit;

const HANDLE = 'uat-checklist-tokens';

/**
 * Every element the plugin's stylesheets style from the top: the tokens are declared on each of them.
 */
const SCOPE = '.uat-checklist,.uat-panel,.uat-panel-toggle,.uat-report,#wp-admin-bar-uat-checklist,.uat-guide,.uat-overview,.uat-fixed';

/**
 * Prefix of every token's custom property.
 */
const PROPERTY_PREFIX = '--hm-uat-';

/**
 * Spacing tokens, smallest first, matched by position to the theme's spacing sizes, each with the most it may
 * take. A theme's spacing is sized for page layout, and the panel is 30rem wide.
 */
const SPACING_STEPS = [
	'space-xs'   => '0.375rem',
	'space-s'    => '0.75rem',
	'space-m'    => '1.125rem',
	'space-l'    => '1.5rem',
	'space-xl'   => '2.25rem',
	'space-2-xl' => '3rem',
	'space-4-xl' => '4.5rem',
];

/**
 * Bounds the theme's body text size is held to, for the same reason.
 */
const FONT_SIZE_MIN = '0.875rem';
const FONT_SIZE_MAX = '1rem';

/**
 * Palette slugs tried, in order, for the Pass and Fail colours.
 */
const PASS_SLUGS = [ 'success', 'green' ];
const FAIL_SLUGS = [ 'error', 'danger', 'red' ];

/**
 * CSS-wide keywords, which a custom property cannot pass on as a value.
 */
const UNUSABLE_VALUES = [ '', 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ];

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	// Before Blocks\register_blocks() at the default priority, which makes each block stylesheet depend on it.
	add_action( 'init', __NAMESPACE__ . '\\register', 9 );
	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\add_token_styles', 1 );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\add_token_styles', 1 );
	add_action( 'enqueue_block_assets', __NAMESPACE__ . '\\add_token_styles', 1 );
}

/**
 * Registers the token stylesheet, which has no file: its rules are added inline once a page loads styles.
 *
 * @return void
 */
function register(): void {
	wp_register_style( HANDLE, false, [], null );
}

/**
 * Adds the token rules to the token stylesheet, once per request.
 *
 * @return void
 */
function add_token_styles(): void {
	if ( ! empty( wp_styles()->get_data( HANDLE, 'after' ) ) ) {
		return;
	}

	wp_add_inline_style( HANDLE, stylesheet() );
}

/**
 * Returns the token declarations and the pop-out window's hiding rule.
 *
 * @return string Stylesheet.
 */
function stylesheet(): string {
	$declarations = '';

	foreach ( tokens() as $name => $value ) {
		$declarations .= PROPERTY_PREFIX . $name . ':' . $value . ';';
	}

	$css = ':where(' . SCOPE . '){' . $declarations . '}';

	/**
	 * Filters the site chrome hidden in the checklist's pop-out window.
	 *
	 * @param string[] $selectors Selectors of the site header, footer and anything else to hide.
	 */
	$hidden = (array) apply_filters(
		'hm_uat_checklist_window_hidden_selectors',
		[ '.wp-site-blocks > header', '.wp-site-blocks > footer' ]
	);
	$hidden = array_filter( array_map( 'strval', $hidden ) );

	if ( $hidden !== [] ) {
		$css .= '.uat-window :is(' . implode( ',', $hidden ) . '){display:none}';
	}

	// Values come from theme.json and filters, never from a request; this stops one closing the style element.
	return str_replace( '</', '<\/', $css );
}

/**
 * Returns every token's value, keyed by name without its prefix.
 *
 * @return array<string, string> Tokens.
 */
function tokens(): array {
	$styles = (array) wp_get_global_styles();
	$tokens = fallbacks();

	$from_theme = [
		'color-accent'           => first_value(
			$styles,
			[ 'elements.button.color.background', 'elements.link.color.text' ]
		),
		'color-on-accent'        => first_value( $styles, [ 'elements.button.color.text' ] ),
		'color-text'             => first_value( $styles, [ 'color.text' ] ),
		'color-background'       => first_value( $styles, [ 'color.background' ] ),
		'color-link'             => first_value( $styles, [ 'elements.link.color.text' ] ),
		'color-link-hover'       => first_value( $styles, [ 'elements.link.:hover.color.text' ] ),
		'color-pass'             => palette_color( PASS_SLUGS ),
		'color-fail'             => palette_color( FAIL_SLUGS ),
		'font-family'            => first_value( $styles, [ 'typography.fontFamily' ] ),
		'font-family-heading'    => first_value(
			$styles,
			[ 'elements.heading.typography.fontFamily', 'typography.fontFamily' ]
		),
		'font-size'              => first_value( $styles, [ 'typography.fontSize' ] ),
		'font-weight-regular'    => first_value( $styles, [ 'typography.fontWeight' ] ),
		'font-weight-medium'     => first_value( $styles, [ 'elements.button.typography.fontWeight' ] ),
		'font-weight-heading'    => first_value( $styles, [ 'elements.heading.typography.fontWeight' ] ),
		'line-height'            => first_value( $styles, [ 'typography.lineHeight' ] ),
		'line-height-heading'    => first_value( $styles, [ 'elements.heading.typography.lineHeight' ] ),
		'letter-spacing'         => first_value( $styles, [ 'typography.letterSpacing' ] ),
		'letter-spacing-heading' => first_value( $styles, [ 'elements.heading.typography.letterSpacing' ] ),
		'radius-control'         => first_value( $styles, [ 'elements.button.border.radius' ] ),
		'content-size'           => to_css( [], wp_get_global_settings( [ 'layout', 'contentSize' ] ) ),
	] + spacing_sizes();

	if ( $from_theme['font-size'] !== '' ) {
		$from_theme['font-size'] = sprintf(
			'clamp(%1$s, %2$s, %3$s)',
			FONT_SIZE_MIN,
			$from_theme['font-size'],
			FONT_SIZE_MAX
		);
	}

	foreach ( $from_theme as $name => $value ) {
		if ( $value !== '' ) {
			$tokens[ $name ] = $value;
		}
	}

	/**
	 * Filters the final token values, after the theme's have replaced the fallbacks.
	 *
	 * @param array<string, string> $tokens Token values keyed by name, without the `--hm-uat-` prefix.
	 * @param array<string, mixed>  $styles The theme's merged global styles.
	 */
	$tokens = (array) apply_filters( 'hm_uat_checklist_style_tokens', $tokens, $styles );

	return array_filter(
		array_map( __NAMESPACE__ . '\\sanitize_value', $tokens ),
		static fn ( string $value ): bool => $value !== ''
	);
}

/**
 * Returns the value each token takes when the theme does not set it, and every token derived from another.
 *
 * @return array<string, string> Fallbacks keyed by token name.
 */
function fallbacks(): array {
	$mix = static fn ( string $color, int $percent, string $with ): string => sprintf(
		'color-mix(in srgb, var(%1$s%2$s) %3$d%%, var(%1$s%4$s))',
		PROPERTY_PREFIX,
		$color,
		$percent,
		$with
	);

	$fallbacks = [
		'color-accent'             => '#2271b1',
		'color-on-accent'          => '#ffffff',
		'color-text'               => '#1e1e1e',
		'color-background'         => '#ffffff',
		'color-link'               => 'var(--hm-uat-color-accent)',
		'color-link-hover'         => $mix( 'color-link', 80, 'color-text' ),
		'color-link-active'        => $mix( 'color-link', 65, 'color-text' ),
		'color-pass'               => '#008a20',
		'color-fail'               => '#d63638',
		'color-on-status'          => '#ffffff',
		'color-accent-hover'       => $mix( 'color-accent', 85, 'color-text' ),
		'color-accent-active'      => $mix( 'color-accent', 70, 'color-text' ),
		'color-accent-tint'        => $mix( 'color-accent', 10, 'color-background' ),
		'color-accent-tint-subtle' => $mix( 'color-accent', 5, 'color-background' ),
		'color-pass-tint'          => $mix( 'color-pass', 10, 'color-background' ),
		'color-fail-tint'          => $mix( 'color-fail', 8, 'color-background' ),
		'color-text-muted'         => $mix( 'color-text', 78, 'color-background' ),
		'color-text-subtle'        => $mix( 'color-text', 68, 'color-background' ),
		'color-border'             => $mix( 'color-text', 28, 'color-background' ),
		'color-border-subtle'      => $mix( 'color-text', 14, 'color-background' ),
		'color-border-hover'       => $mix( 'color-text', 50, 'color-background' ),
		'color-surface'            => $mix( 'color-text', 3, 'color-background' ),
		'color-surface-strong'     => $mix( 'color-text', 7, 'color-background' ),
		'color-shadow'             => 'color-mix(in srgb, var(--hm-uat-color-text) 12%, transparent)',
		'font-family'              => 'inherit',
		'font-family-heading'      => 'var(--hm-uat-font-family)',
		'font-size'                => '1rem',
		'font-size-s'              => 'calc(var(--hm-uat-font-size) * 0.875)',
		'font-size-l'              => 'calc(var(--hm-uat-font-size) * 1.125)',
		'font-size-heading-xs'     => 'calc(var(--hm-uat-font-size) * 1.25)',
		'font-size-heading-s'      => 'calc(var(--hm-uat-font-size) * 1.5)',
		'font-weight-regular'      => '400',
		'font-weight-medium'       => '600',
		'font-weight-heading'      => '600',
		'line-height'              => '1.5',
		'line-height-s'            => '1.4',
		'line-height-heading'      => '1.25',
		'letter-spacing'           => 'normal',
		'letter-spacing-heading'   => 'normal',
		'radius-control'           => '4px',
		// Fields and cards follow the button's corners, held back from a pill shape.
		'radius'                   => 'min(var(--hm-uat-radius-control), 0.5rem)',
		'content-size'             => '40em',
		'measure'                  => 'min(var(--hm-uat-content-size), 40em)',
		'space-xs'                 => '0.25rem',
		'space-s'                  => '0.5rem',
		'space-m'                  => '0.75rem',
		'space-l'                  => '1rem',
		'space-xl'                 => '1.5rem',
		'space-2-xl'               => '2rem',
		'space-4-xl'               => '3rem',
	];

	/**
	 * Filters the values tokens take when the active theme's theme.json does not set them.
	 *
	 * @param array<string, string> $fallbacks Token values keyed by name, without the `--hm-uat-` prefix.
	 */
	return (array) apply_filters( 'hm_uat_checklist_style_fallbacks', $fallbacks );
}

/**
 * Returns the first of several global styles paths that holds a usable value, as CSS, or ''.
 *
 * @param array<string, mixed> $styles Global styles.
 * @param string[]             $paths  Dot-separated paths, such as `elements.button.color.background`.
 * @return string CSS value.
 */
function first_value( array $styles, array $paths ): string {
	foreach ( $paths as $path ) {
		$value = to_css( $styles, value_at( $styles, $path ) );

		if ( $value !== '' ) {
			return $value;
		}
	}

	return '';
}

/**
 * Returns the value at a dot-separated path in a nested array, or null.
 *
 * @param array<string, mixed> $data Nested array.
 * @param string               $path Path.
 * @return mixed Value.
 */
function value_at( array $data, string $path ): mixed {
	foreach ( explode( '.', $path ) as $key ) {
		if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
			return null;
		}

		$data = $data[ $key ];
	}

	return $data;
}

/**
 * Converts a theme.json style value to CSS: a `var:preset|color|x` reference to its custom property, a
 * `{ "ref": "styles.…" }` object to the value it points at, and a CSS-wide keyword to ''.
 *
 * @param array<string, mixed> $styles Global styles, which a `ref` is resolved against.
 * @param mixed                $value  Style value.
 * @return string CSS value.
 */
function to_css( array $styles, mixed $value ): string {
	if ( is_array( $value ) && isset( $value['ref'] ) && is_string( $value['ref'] ) ) {
		// A ref names a path from the root of theme.json, which starts at `styles.`; one level only, as core does.
		$target = value_at( $styles, (string) preg_replace( '/^styles\./', '', $value['ref'] ) );

		return is_string( $target ) ? to_css( [], $target ) : '';
	}

	if ( ! is_string( $value ) || in_array( strtolower( trim( $value ) ), UNUSABLE_VALUES, true ) ) {
		return '';
	}

	if ( str_starts_with( $value, 'var:' ) ) {
		return 'var(--wp--' . str_replace( '|', '--', substr( $value, 4 ) ) . ')';
	}

	return trim( $value );
}

/**
 * Returns the custom property of the first theme or user palette colour with one of the slugs, or ''.
 *
 * @param string[] $slugs Palette slugs, in order of preference.
 * @return string CSS value.
 */
function palette_color( array $slugs ): string {
	$palettes = (array) wp_get_global_settings( [ 'color', 'palette' ] );
	$defined  = [];

	// Core's default palette is left out: a theme that disables it does not print its custom properties.
	foreach ( [ 'theme', 'custom' ] as $origin ) {
		foreach ( (array) ( $palettes[ $origin ] ?? [] ) as $color ) {
			$defined[] = (string) ( $color['slug'] ?? '' );
		}
	}

	foreach ( $slugs as $slug ) {
		if ( in_array( $slug, $defined, true ) ) {
			return 'var(--wp--preset--color--' . $slug . ')';
		}
	}

	return '';
}

/**
 * Returns the spacing tokens the theme's spacing sizes supply, matched by position, smallest first.
 *
 * @return array<string, string> Spacing tokens.
 */
function spacing_sizes(): array {
	$sizes   = (array) wp_get_global_settings( [ 'spacing', 'spacingSizes' ] );
	$presets = ! empty( $sizes['theme'] ) ? (array) $sizes['theme'] : (array) ( $sizes['default'] ?? [] );
	$tokens  = [];

	$steps = array_keys( SPACING_STEPS );

	foreach ( array_values( $presets ) as $index => $preset ) {
		$slug = (string) ( $preset['slug'] ?? '' );

		if ( isset( $steps[ $index ] ) && $slug !== '' ) {
			$name            = $steps[ $index ];
			$tokens[ $name ] = 'min(var(--wp--preset--spacing--'
				. sanitize_key( $slug )
				. '), '
				. SPACING_STEPS[ $name ]
				. ')';
		}
	}

	return $tokens;
}

/**
 * Returns a token value with anything that could end its declaration removed.
 *
 * @param mixed $value Token value.
 * @return string CSS value.
 */
function sanitize_value( mixed $value ): string {
	return is_scalar( $value ) ? trim( str_replace( [ ';', '{', '}' ], '', (string) $value ) ) : '';
}
