<?php
/**
 * Server render for the testing checklist: its header, its intro, its sections, then the testers' additional
 * comments.
 *
 * Rendered into the Testing checklist panel over REST, for a logged-in member of the site only. The global post is
 * the checklist; see Blocks\render_checklist().
 *
 * @package HM_UAT_Checklist
 *
 * @var array     $attributes Block attributes.
 * @var \WP_Block $block      Block instance.
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Blocks;

use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Results;

defined( 'ABSPATH' ) || exit;

if ( ! Results\can_record() ) {
	return;
}

/** @var \WP_Block $block */
$checklist_id  = (int) get_the_ID();
$step_ids      = step_ids_in( $block->parsed_block['innerBlocks'] ?? [] );
$required_ids  = step_ids_in( $block->parsed_block['innerBlocks'] ?? [], true );
$total         = count( $step_ids );
$required      = count( $required_ids );
$done          = Results\count_done( $checklist_id, $step_ids );
$required_done = Results\count_done( $checklist_id, $required_ids );
$instructions  = Checklists\instructions_url( $checklist_id );

// The required steps are the goal, so the count leads with them and the meter fills with them. A checklist with
// none keeps the single count.
$count = $required > 0
	? sprintf(
		/* translators: 1: required steps with a result, 2: required steps, 3: steps with a result, 4: steps. */
		esc_html__( '%1$s of %2$s required done, %3$s of %4$s in all', 'uat-checklist' ),
		'<span class="uat-checklist__required-done">' . (int) $required_done . '</span>',
		'<span class="uat-checklist__required-total">' . (int) $required . '</span>',
		'<span class="uat-checklist__done">' . (int) $done . '</span>',
		'<span class="uat-checklist__total">' . (int) $total . '</span>'
	)
	: sprintf(
		/* translators: 1: number of steps with a result, 2: number of steps. */
		esc_html__( '%1$s of %2$s done', 'uat-checklist' ),
		'<span class="uat-checklist__done">' . (int) $done . '</span>',
		'<span class="uat-checklist__total">' . (int) $total . '</span>'
	);

// The instructions link only when the checklist names a published page. The pop-out link is hidden in the window
// view by blocks/checklist/style.scss. src/lib/checklist.js opens the instructions in a window of their own.
$links = $instructions !== ''
	? sprintf(
		'<a class="uat-checklist__page" href="%1$s" target="%2$s">%3$s'
			. '<span class="screen-reader-text"> %4$s</span></a>',
		esc_url( $instructions ),
		esc_attr( INSTRUCTIONS_WINDOW ),
		esc_html__( 'Open the instructions', 'uat-checklist' ),
		esc_html__( '(opens in a new window)', 'uat-checklist' )
	)
	: '';

$links .= sprintf(
	'<a class="uat-checklist__window" href="%1$s" target="%2$s">%3$s</a>',
	esc_url( Checklists\window_url( $checklist_id ) ),
	esc_attr( CHECKLIST_WINDOW ),
	esc_html__( 'Open in new window', 'uat-checklist' )
);

// The meter repeats the count for sighted testers; the status line is what a screen reader announces.
$progress = sprintf(
	'<div class="uat-checklist__meta">'
		. '<p class="uat-checklist__progress" role="status">%1$s</p>'
		. '<span class="uat-checklist__links">%2$s</span></div>'
		. '<progress class="uat-checklist__meter" value="%3$d" max="%4$d" aria-hidden="true"></progress>',
	$count,
	$links,
	(int) ( $required > 0 ? $required_done : $done ),
	(int) ( $required > 0 ? $required : $total )
);

$user_id   = get_current_user_id();
$own       = Results\get_note( $checklist_id, $user_id );
$all_notes = Results\get_checklist_notes( $checklist_id );
$others    = '';

foreach ( $all_notes as $note ) {
	if ( (int) $note->post_author === $user_id ) {
		continue;
	}

	$others .= sprintf(
		'<li class="uat-checklist__note">'
			. '<p class="uat-checklist__note-meta">%1$s · %2$s</p>'
			. '<p class="uat-checklist__note-text">%3$s</p></li>',
		esc_html( get_the_author_meta( 'display_name', (int) $note->post_author ) ),
		esc_html( Results\saved_at( $note ) ),
		nl2br( esc_html( $note->post_content ) )
	);
}

if ( $others !== '' ) {
	$others = sprintf(
		'<p class="uat-checklist__label">%1$s</p><ul class="uat-checklist__notes-list">%2$s</ul>',
		esc_html__( 'Other testers', 'uat-checklist' ),
		$others
	);
}

$notes_id = 'uat-notes-' . $checklist_id;

$notes = sprintf(
	'<details class="uat-checklist__notes"><summary class="uat-checklist__notes-summary">%1$s%2$s</summary>'
		. '<div class="uat-checklist__notes-body">%3$s'
		. '<form class="uat-checklist__notes-form" data-checklist-id="%4$d" hidden>'
		. '<label class="uat-checklist__label" for="%5$s">%6$s</label>'
		. '<textarea class="uat-checklist__comment" id="%5$s" name="comment" rows="4" maxlength="%7$d"'
		. ' aria-describedby="%5$s-hint">%8$s</textarea>'
		. '<p class="uat-checklist__hint" id="%5$s-hint">%9$s</p>'
		. '<div class="uat-checklist__actions">'
		. '<button type="submit" class="uat-checklist__save wp-element-button">%10$s</button>'
		. '<p class="uat-checklist__status" role="status">%11$s</p></div>'
		. '</form><noscript><p class="uat-checklist__hint">%12$s</p></noscript></div></details>',
	esc_html__( 'Additional comments', 'uat-checklist' ),
	$all_notes === [] ? '' : '<span class="uat-checklist__notes-count">' . count( $all_notes ) . '</span>',
	$others,
	$checklist_id,
	esc_attr( $notes_id ),
	esc_html__( 'Your comments', 'uat-checklist' ),
	Results\COMMENT_MAX_LENGTH,
	esc_textarea( $own->post_content ?? '' ),
	esc_html__( 'For anything that does not fit a single step. Other testers can read it.', 'uat-checklist' ),
	esc_html__( 'Save comments', 'uat-checklist' ),
	$own ? esc_html( Results\describe_note( $own ) ) : '',
	esc_html__( 'Turn on JavaScript to save a comment.', 'uat-checklist' )
);

// Rendered again from the block rather than taken from $content, which runs the intro into the sections.
$parts = render_checklist_parts( $block );
$intro = $parts['intro'] === '' ? '' : '<div class="uat-checklist__description">' . $parts['intro'] . '</div>';

printf(
	'<div %1$s>'
		. '<div class="uat-checklist__header"><h2 class="uat-checklist__title">%2$s</h2>%3$s</div>'
		. '%7$s<p class="uat-checklist__intro">%4$s</p><div class="uat-checklist__sections">%5$s</div>%6$s</div>',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes every value it emits.
	get_block_wrapper_attributes(
		[
			'class'             => 'uat-checklist',
			'data-checklist-id' => (string) $checklist_id,
		]
	),
	esc_html( checklist_title( $checklist_id ) ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html__(), esc_url(), esc_attr() and integer casts.
	$progress,
	esc_html__( 'Open a step, mark it Pass or Fail, then save it. A Fail needs a comment saying what went wrong. Every tester sees the latest result on each step.', 'uat-checklist' ),
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the inner blocks' own render output, escaped there.
	$parts['body'],
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html(), esc_attr(), esc_textarea() and integer values.
	$notes,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the intro paragraphs' render output, as core prints it.
	$intro
);
