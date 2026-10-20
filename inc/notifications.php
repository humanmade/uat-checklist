<?php
/**
 * Emails the tester whose failed step a manager marks fixed with a note.
 *
 * The email goes out from a one-off cron event, so the Mark fixed request never waits on mail and a mail failure
 * cannot fail it.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Notifications;

use HM\UAT_Checklist;
use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Results;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Cron event that sends the email. Its arguments are the Fixed result's ID, then the Fail's.
 */
const FIXED_EVENT = 'hm_uat_checklist_notify_fixed';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( FIXED_EVENT, __NAMESPACE__ . '\\send_fixed_notice', 10, 2 );
	register_deactivation_hook( UAT_Checklist\FILE, __NAMESPACE__ . '\\unschedule_all' );
}

/**
 * Removes every queued email when the plugin is deactivated.
 *
 * With the plugin inactive nothing handles the event, so cron would drop each one unsent.
 *
 * @return void
 */
function unschedule_all(): void {
	wp_unschedule_hook( FIXED_EVENT );
}

/**
 * Schedules the email for a Fixed result that carries a note.
 *
 * Both IDs travel in the event: the Fixed result's author is the manager, and the tester to tell is the Fail's.
 * Without a note there is nothing the tester does not already see in the panel, and a manager who fixed their own
 * Fail needs no email about it.
 *
 * @param WP_Post $fixed The Fixed result.
 * @param WP_Post $fail  The Fail it answers.
 * @return bool Whether an event was scheduled.
 */
function schedule_fixed_notice( WP_Post $fixed, WP_Post $fail ): bool {
	if (
		trim( $fixed->post_content ) === ''
		|| (int) $fail->post_author === (int) $fixed->post_author
	) {
		return false;
	}

	return wp_schedule_single_event( time(), FIXED_EVENT, [ $fixed->ID, $fail->ID ] ) === true;
}

/**
 * Emails the tester who saved a Fail that it has been marked fixed.
 *
 * Runs from cron, possibly some time after the schedule, so it reads both results afresh. A manager who trashed the
 * Fixed result in the meantime has withdrawn the fix, and nothing is sent.
 *
 * @param int $fixed_id ID of the Fixed result.
 * @param int $fail_id  ID of the Fail it answers.
 * @return void
 */
function send_fixed_notice( int $fixed_id, int $fail_id ): void {
	$fixed = get_post( $fixed_id );
	$fail  = get_post( $fail_id );

	if (
		! $fixed instanceof WP_Post
		|| $fixed->post_status !== Results\FIXED_STATUS
		|| ! $fail instanceof WP_Post
	) {
		return;
	}

	$tester = get_userdata( (int) $fail->post_author );

	if ( $tester === false || $tester->user_email === '' ) {
		return;
	}

	// Cron runs with no user, so the tester's own language has to be switched to for the email.
	switch_to_user_locale( $tester->ID );

	$manager   = get_userdata( (int) $fixed->post_author );
	$checklist = Blocks\checklist_title( $fixed->post_parent );
	$step      = Results\plain_text( $fixed->post_title );
	$lines     = [
		/* translators: %s: the tester's display name. */
		sprintf( __( 'Hi %s,', 'uat-checklist' ), $tester->display_name ),
		'',
		// The manager's account can be deleted before cron runs, which would open the sentence on a blank name.
		$manager !== false
			? sprintf(
				/* translators: 1: the name of the person who marked the step fixed, 2: the checklist's title. */
				__( '%1$s marked a step you failed on %2$s as fixed.', 'uat-checklist' ),
				$manager->display_name,
				$checklist
			)
			: sprintf(
				/* translators: %s: the checklist's title. */
				__( 'A step you failed on %s has been marked fixed.', 'uat-checklist' ),
				$checklist
			),
		'',
		/* translators: %s: the step's text. */
		sprintf( __( 'Step: %s', 'uat-checklist' ), $step ),
		'',
	];

	if ( trim( $fail->post_content ) !== '' ) {
		$lines[] = __( 'Your comment when it failed:', 'uat-checklist' );
		$lines[] = Results\plain_text( $fail->post_content );
		$lines[] = '';
	}

	$lines[] = __( 'Their note:', 'uat-checklist' );
	$lines[] = Results\plain_text( $fixed->post_content );
	$lines[] = '';
	$lines[] = sprintf(
		/* translators: %s: the address of the checklist, where the step can be retested. */
		__( 'Retest it here: %s', 'uat-checklist' ),
		Checklists\window_url( $fixed->post_parent )
	);

	$subject = sprintf(
		/* translators: 1: the site's title, 2: the step's text. */
		__( '[%1$s] Ready to retest: %2$s', 'uat-checklist' ),
		wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
		$step
	);

	wp_mail( $tester->user_email, $subject, implode( "\n", $lines ) );

	restore_previous_locale();
}
