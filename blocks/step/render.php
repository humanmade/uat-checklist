<?php
/**
 * Server render for one checklist step, as an accordion item.
 *
 * The summary carries the number, the step, a Required tag on a required step and a Pass, Fail or Retest badge. The
 * body carries the numbered instructions of a scenario step, the expected result or results, when the latest result
 * is Fixed a notice saying what was fixed and what failed, with the Fail's screenshot, and, for a logged-in tester,
 * the result form.
 * Every step shares one `details` name, so opening a step closes the others in
 * browsers that support exclusive accordions; older ones open them independently.
 *
 * The form is printed `hidden` and shown by src/lib/step-form.js, which the
 * panel runs, since saving goes through the REST API and a form without the
 * script would submit to nowhere.
 *
 * @package HM_UAT_Checklist
 *
 * @var array     $attributes Block attributes.
 * @var string    $content    Always empty: save() returns null.
 * @var \WP_Block $block      Block instance.
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Blocks;

use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Screenshots;
use WP_Post;

defined( 'ABSPATH' ) || exit;

$step_id = (string) ( $attributes['stepId'] ?? '' );

// Repeats the file-level @var: PHPStan attaches that tag to the next statement only.
/** @var array<string, mixed> $attributes */
$raw_step = (string) ( $attributes['step'] ?? '' );
$emphasis = [
	'strong' => [],
	'em'     => [],
	'b'      => [],
	'i'      => [],
];
$linked   = $emphasis + [ 'a' => [ 'href' => true ] ];

// A summary is itself the toggle, so a link inside it is unusable: the summary drops links, and a step that has
// any repeats in the body with them intact.
$summary_text = wp_kses( $raw_step, $emphasis );
$body_text    = wp_kses( $raw_step, $linked );
$has_links    = $body_text !== $summary_text;
$expected     = wp_kses( (string) ( $attributes['expected'] ?? '' ), $linked );
$list_items   = static fn( array $items ): string => implode(
	'',
	array_map( static fn( string $item ): string => '<li>' . wp_kses( $item, $linked ) . '</li>', $items )
);
$instructions  = $list_items( array_map( 'strval', (array) ( $attributes['instructions'] ?? [] ) ) );
$outcome_list  = array_map( 'strval', (array) ( $attributes['expectedItems'] ?? [] ) );
$outcomes      = $list_items( $outcome_list );
$label         = '<span class="uat-checklist__label">'
	. esc_html__( 'Expected result', 'uat-checklist' )
	. '</span>';
$fail_hint     = count( $outcome_list ) > 1
	? '<p class="uat-checklist__hint">'
		. esc_html__( 'If one or more expected results fail, mark the step Fail. In the comment, say which one and what happened instead.', 'uat-checklist' )
		. '</p>'
	: '';

// A screenshot of a control that is hard to find, above the instructions it illustrates. Not a link: a click
// would take the tester away from the step they are on.
$image_url = (string) ( $attributes['imageUrl'] ?? '' );
$figure    = $image_url !== ''
	? sprintf(
		'<figure class="uat-checklist__figure"><img src="%1$s" alt="%2$s" loading="lazy"></figure>',
		esc_url( $image_url ),
		esc_attr( (string) ( $attributes['imageAlt'] ?? '' ) )
	)
	: '';

// A scenario step lists several results; a single check keeps its one sentence.
$expected_markup = $outcomes !== ''
	? '<div class="uat-checklist__expected">'
		. $label
		. '<ol class="uat-checklist__list">' . $outcomes . '</ol>'
		. $fail_hint
		. '</div>'
	: '<p class="uat-checklist__expected">' . $label . ' ' . $expected . '</p>';

/** @var \WP_Block $block */
$checklist_id = (int) ( $block->context['postId'] ?? get_the_ID() );
$can_record = $step_id !== '' && $checklist_id > 0 && Results\can_record();
$history    = $can_record ? Results\get_step_history( $checklist_id, $step_id ) : [];
$saved      = $history[0] ?? null;
$result     = $saved ? Results\result_for_status( $saved->post_status ) : '';
$is_retest  = $result === 'fixed';

// The form is never filled in: every save is a new result, so a step starts blank however often it was tested.
$notice = '';

if ( $saved && $is_retest ) {
	$failure = Results\last_failure( $history );
	$author  = static fn ( WP_Post $post ): string => get_the_author_meta(
		'display_name',
		(int) $post->post_author
	);

	$notice = '<div class="uat-checklist__retest"><p class="uat-checklist__note-text">'
		. esc_html(
			sprintf(
				/* translators: 1: date and time the step was marked fixed, 2: name of the developer who marked it. */
				__( 'Marked fixed on %1$s by %2$s.', 'uat-checklist' ),
				Results\saved_at( $saved ),
				$author( $saved )
			)
		)
		. '</p>'
		. (
			$saved->post_content !== ''
				? '<p class="uat-checklist__note-text">' . nl2br( esc_html( $saved->post_content ) ) . '</p>'
				: ''
		)
		. ( $failure ? '<p class="uat-checklist__note-meta">'
			. esc_html(
				sprintf(
					/* translators: 1: name of the tester, 2: date and time of the Fail, 3: the tester's comment. */
					__( '%1$s marked this Fail on %2$s: %3$s', 'uat-checklist' ),
					$author( $failure ),
					Results\saved_at( $failure ),
					$failure->post_content
				)
			)
			. '</p>' . Screenshots\markup( $failure, 'large' ) : '' )
		. '</div>';
}

$form = '';

if ( $can_record ) {
	$choices = '';

	foreach ( array_keys( Results\STATUSES ) as $value ) {
		$choices .= sprintf(
			'<label class="uat-checklist__choice uat-checklist__choice--%1$s">'
				. '<input type="radio" name="result" value="%1$s"> %2$s</label>',
			esc_attr( $value ),
			esc_html( Results\label( $value ) )
		);
	}

	$comment_id         = 'uat-comment-' . $step_id;
	$hint_id            = 'uat-hint-' . $step_id;
	$screenshot_id      = 'uat-screenshot-' . $step_id;
	$screenshot_hint_id = 'uat-screenshot-hint-' . $step_id;

	$form = sprintf(
		'<form class="uat-checklist__result" data-checklist-id="%1$d" data-step-id="%2$s" data-max-upload="%13$d" hidden>'
			. '<fieldset class="uat-checklist__choices">'
			. '<legend class="uat-checklist__label">%3$s</legend>%4$s</fieldset>'
			. '<label class="uat-checklist__label" for="%5$s">%6$s</label>'
			. '<textarea class="uat-checklist__comment" id="%5$s" name="comment" rows="2" maxlength="%7$d"'
			. ' aria-describedby="%8$s"></textarea>'
			. '<p class="uat-checklist__hint" id="%8$s">%9$s</p>'
			. '<div class="uat-checklist__attach">'
			. '<span class="uat-checklist__label">%15$s</span>'
			. '<div class="uat-checklist__preview" hidden>'
			. '<button type="button" class="uat-checklist__remove">%20$s</button></div>'
			// The input stays in the page, focusable, so keyboard and screen readers use the native control.
			. '<input type="file" class="uat-checklist__file" id="%14$s" name="%16$s" accept="%17$s" aria-describedby="%18$s">'
			. '<label class="uat-checklist__upload" for="%14$s">%21$s</label>'
			. '<p class="uat-checklist__hint" id="%18$s">%19$s</p>'
			. '</div>'
			. '<div class="uat-checklist__actions">'
			. '<button type="submit" class="uat-checklist__save wp-element-button">%10$s</button>'
			. '<p class="uat-checklist__status" role="status">%11$s</p></div>'
			. '</form>'
			. '<noscript><p class="uat-checklist__hint">%12$s</p></noscript>',
		$checklist_id,
		esc_attr( $step_id ),
		esc_html__( 'Result', 'uat-checklist' ),
		$choices,
		esc_attr( $comment_id ),
		esc_html__( 'Comment', 'uat-checklist' ),
		Results\COMMENT_MAX_LENGTH,
		esc_attr( $hint_id ),
		esc_html__( 'Required when the step fails.', 'uat-checklist' ),
		esc_html__( 'Save result', 'uat-checklist' ),
		$saved && ! $is_retest ? esc_html( Results\describe( $saved ) ) : '',
		esc_html__( 'Turn on JavaScript to record a result.', 'uat-checklist' ),
		Screenshots\max_bytes(),
		esc_attr( $screenshot_id ),
		esc_html__( 'Screenshot', 'uat-checklist' ),
		esc_attr( Screenshots\FIELD ),
		esc_attr( implode( ',', array_values( Screenshots\MIMES ) ) ),
		esc_attr( $screenshot_hint_id ),
		esc_html(
			sprintf(
				/* translators: %s: the largest file size the site accepts, such as 2 MB. */
				__( 'Optional. A JPEG, PNG, GIF or WebP image of up to %s.', 'uat-checklist' ),
				size_format( Screenshots\max_bytes() )
			)
		),
		esc_html__( 'Remove screenshot', 'uat-checklist' ),
		esc_html__( 'Add a screenshot', 'uat-checklist' )
	);
}

$is_required = ( $attributes['required'] ?? true ) !== false;

// The required tag and the result badge share one cell of the summary, so its grid keeps four columns.
$badge = sprintf(
	'<span class="uat-checklist__tags">%1$s<span class="uat-checklist__badge"%2$s>%3$s</span></span>',
	$is_required
		? '<span class="uat-checklist__required">' . esc_html__( 'Required', 'uat-checklist' ) . '</span>'
		: '',
	$result === '' ? ' hidden' : '',
	$is_retest ? esc_html__( 'Retest', 'uat-checklist' ) : esc_html( Results\label( $result ) )
);

$wrapper = [ 'class' => 'uat-checklist__step' ];

if ( $is_required ) {
	$wrapper['data-required'] = 'true';
}

if ( $result !== '' ) {
	// Colour only ever means Pass or Fail, so a step waiting for a retest has its own neutral value.
	$wrapper['data-result'] = $is_retest ? 'retest' : $result;
}

printf(
	'<li %1$s><details class="uat-checklist__details" name="uat-checklist-steps"%2$s>'
		. '<summary class="uat-checklist__summary"><span class="uat-checklist__text">%3$s</span>%4$s</summary>'
		. '<div class="uat-checklist__body">%5$s%7$s%6$s%8$s%10$s%9$s</div>'
		. '</details></li>',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes every value it emits.
	get_block_wrapper_attributes( $wrapper ),
	// The page opens at the first step to retest, else the first still to test, and at none once all are done.
	$can_record && step_to_open( $checklist_id ) === $step_id ? ' open' : '',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered through wp_kses() above.
	$summary_text,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html() and esc_html__().
	$badge,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered through wp_kses() above.
	$has_links ? '<p class="uat-checklist__text">' . $body_text . '</p>' : '',
	$instructions !== ''
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item filtered through wp_kses() above.
		? '<ol class="uat-checklist__list uat-checklist__instructions">' . $instructions . '</ol>'
		: '',
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url() and esc_attr().
	$figure,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from wp_kses() and esc_html__().
	$expected_markup,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value in the form is escaped where sprintf() receives it.
	$form,
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html(), esc_html__() and Screenshots\markup().
	$notice
);
