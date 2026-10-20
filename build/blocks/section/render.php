<?php
/**
 * Server render for a checklist section: its title, then its steps as an ordered list.
 *
 * @package HM_UAT_Checklist
 *
 * @var array     $attributes Block attributes.
 * @var string    $content    Rendered steps.
 * @var \WP_Block $block      Block instance.
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Blocks;

defined( 'ABSPATH' ) || exit;

// Repeats the file-level @var: PHPStan attaches that tag to the next statement only.
/** @var array<string, mixed> $attributes */
$section_title = trim( (string) ( $attributes['title'] ?? '' ) );
$heading       = $section_title !== ''
	? '<h3 class="uat-checklist__section-title">' . esc_html( $section_title ) . '</h3>'
	: '';

// Repeats the file-level @var: PHPStan attaches that tag to the next statement only.
/** @var string $content */
printf(
	'<section %1$s>%2$s<ol class="uat-checklist__steps">%3$s</ol></section>',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes every value it emits.
	get_block_wrapper_attributes( [ 'class' => 'uat-checklist__section' ] ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_html().
	$heading,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the steps' own render.php output, escaped there.
	$content
);
