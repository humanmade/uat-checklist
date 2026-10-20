<?php
/**
 * Ready to retest: the dashboard widget and the Profile notice that tell a tester which of their Fails have been
 * fixed.
 *
 * A Subscriber tester has no dashboard, because core's login sends a user without `edit_posts` to the Profile
 * screen, so the same list shows in both places. `Progress\awaiting_retest()` works it out on each request.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Retests;

use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\Results;

defined( 'ABSPATH' ) || exit;

const WIDGET_ID = 'hm_uat_checklist_retests';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\\add_widget' );
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_profile_notice' );
}

/**
 * Adds the Ready to retest widget to the dashboard, when the current user has a step to retest.
 *
 * The list is worked out here and handed to the widget as its callback arguments, so it is built once.
 *
 * @return void
 */
function add_widget(): void {
	if ( ! Results\can_record() ) {
		return;
	}

	$items = Progress\awaiting_retest( get_current_user_id() );

	if ( $items === [] ) {
		return;
	}

	wp_add_dashboard_widget(
		WIDGET_ID,
		__( 'Ready to retest', 'uat-checklist' ),
		__NAMESPACE__ . '\\render_widget',
		null,
		[ 'items' => $items ]
	);
}

/**
 * Prints the widget's list.
 *
 * @param mixed                $data   Dashboard data object, unused.
 * @param array<string, mixed> $widget The widget, whose `args` hold the items `add_widget()` found.
 * @return void
 */
function render_widget( mixed $data, array $widget ): void {
	echo render_list( $widget['args']['items'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_list() escapes each value.
}

/**
 * Prints the list as a notice on the Profile screen, where a tester without a dashboard lands.
 *
 * @return void
 */
function render_profile_notice(): void {
	if ( get_current_screen()?->id !== 'profile' || ! Results\can_record() ) {
		return;
	}

	$items = Progress\awaiting_retest( get_current_user_id() );

	if ( $items === [] ) {
		return;
	}

	wp_admin_notice(
		render_list( $items ),
		[
			'type'           => 'info',
			'paragraph_wrap' => false,
		]
	);
}

/**
 * Returns the list both places show, so they cannot drift apart.
 *
 * @param array<int, array{checklist_id: int, checklist_title: string, step_id: string, step: string, note: string, fixed_by: string, fixed_at: string, url: string}> $items Steps to retest.
 * @return string Escaped markup.
 */
function render_list( array $items ): string {
	$count = count( $items );

	$html = '<p>' . esc_html(
		sprintf(
			/* translators: %s: how many steps. */
			_n(
				'%s step you failed has been fixed. Retest it to close it.',
				'%s steps you failed have been fixed. Retest them to close them.',
				$count,
				'uat-checklist'
			),
			number_format_i18n( $count )
		)
	) . '</p><ul>';

	foreach ( $items as $item ) {
		$html .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['step'] ) . '</a><br>'
			. esc_html( $item['checklist_title'] ) . '<br>'
			. esc_html( fixed_line( $item['fixed_by'], $item['fixed_at'] ) );

		if ( $item['note'] !== '' ) {
			$html .= '<p>' . nl2br( esc_html( $item['note'] ) ) . '</p>';
		}

		$html .= '</li>';
	}

	return $html . '</ul>';
}

/**
 * Returns who marked a step fixed and when, leaving the name out when the user has been deleted.
 *
 * @param string $fixed_by Display name, or an empty string when the user is gone.
 * @param string $fixed_at Date and time, formatted for the site.
 * @return string Sentence.
 */
function fixed_line( string $fixed_by, string $fixed_at ): string {
	if ( $fixed_by === '' ) {
		/* translators: %s: date and time the step was marked fixed. */
		return sprintf( __( 'Marked fixed on %s.', 'uat-checklist' ), $fixed_at );
	}

	/* translators: 1: name of the person who marked the step fixed, 2: date and time. */
	return sprintf( __( 'Marked fixed by %1$s on %2$s.', 'uat-checklist' ), $fixed_by, $fixed_at );
}
