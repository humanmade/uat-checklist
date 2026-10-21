<?php
/**
 * The email a tester gets when a manager marks their Fail fixed with a note: when it is scheduled, and what it says
 * when cron sends it.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Admin;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Notifications;
use HM\UAT_Checklist\Results;

echo "\nFixed notices\n";

$step_ids = array_map( 'wp_generate_uuid4', array_fill( 0, 7, null ) );

/**
 * Counts the scheduled Fixed notices, whatever their arguments.
 *
 * @return int Events.
 */
$hm_test_pending_notices = static function (): int {
	$count = 0;

	foreach ( (array) _get_cron_array() as $events ) {
		$count += count( $events[ Notifications\FIXED_EVENT ] ?? [] );
	}

	return $count;
};

/**
 * Sends a notice with wp_mail() intercepted, so nothing leaves the site.
 *
 * @param int $fixed_id Fixed result ID.
 * @param int $fail_id  Fail ID.
 * @return array<int, array<string, mixed>> The arguments of each wp_mail() call.
 */
$hm_test_send = static function ( int $fixed_id, int $fail_id ): array {
	$mails = [];

	hm_test_with_filter(
		'pre_wp_mail',
		static function ( $pre, array $atts ) use ( &$mails ) {
			$mails[] = $atts;

			return true;
		},
		static function () use ( $fixed_id, $fail_id ): void {
			Notifications\send_fixed_notice( $fixed_id, $fail_id );
		}
	);

	return $mails;
};

hm_test_unscheduling(
	Notifications\FIXED_EVENT,
	static function () use ( $step_ids, $hm_test_pending_notices, $hm_test_send ): void {
		hm_test_with_page(
			[
				'post_type'    => Checklists\POST_TYPE,
				'post_title'   => 'Notice test checklist',
				'post_content' => hm_test_numbered_checklist_markup( $step_ids ),
			],
			static function ( int $checklist_id ) use ( $step_ids, $hm_test_pending_notices, $hm_test_send ): void {
				hm_test_as_user(
					'subscriber',
					static function ( int $tester ) use (
						$checklist_id,
						$step_ids,
						$hm_test_pending_notices,
						$hm_test_send
					): void {
						$fail_on = static function ( int $n, string $comment ) use (
							$checklist_id,
							$tester,
							$step_ids
						): WP_Post {
							$fail = Results\save(
								$checklist_id,
								$tester,
								$step_ids[ $n ],
								'Step ' . $n,
								'fail',
								$comment
							);

							if ( ! $fail instanceof WP_Post ) {
								throw new RuntimeException( 'The Fail was not saved.' );
							}

							return $fail;
						};

						$main_fail  = $fail_on( 0, 'The button does nothing.' );
						$blank_note = $fail_on( 1, 'Still broken.' );
						$no_comment = $fail_on( 3, '' );
						$trashed    = $fail_on( 4, 'Gone wrong.' );
						$orphaned   = $fail_on( 5, 'No reply.' );
						// Saved by a tester, so core's kses stores the & as &amp;.
						$ampersand  = $fail_on( 6, 'It does nothing & the page reloads.' );

						hm_test_as_user(
							'administrator',
							static function ( int $developer ) use (
								$checklist_id,
								$step_ids,
								$tester,
								$main_fail,
								$blank_note,
								$no_comment,
								$trashed,
								$orphaned,
								$ampersand,
								$hm_test_pending_notices,
								$hm_test_send
							): void {
								$notice_for = static function ( WP_Post $fail, string $note ) use ( $developer ): WP_Post {
									$fixed = Admin\mark_fixed( $fail->ID, $developer, $note );

									if ( ! $fixed instanceof WP_Post ) {
										throw new RuntimeException( 'Mark fixed was refused.' );
									}

									return $fixed;
								};

								// A manager's own Fail: there is nobody else to tell.
								$own_fail = Results\save(
									$checklist_id,
									$developer,
									$step_ids[2],
									'Step 2',
									'fail',
									'Mine.'
								);
								$before   = $hm_test_pending_notices();
								$own      = $notice_for( $own_fail, 'Fixed my own.' );
								check(
									'a manager marking their own Fail fixed schedules nothing',
									[
										$hm_test_pending_notices() - $before,
										wp_next_scheduled( Notifications\FIXED_EVENT, [ $own->ID, $own_fail->ID ] ),
									],
									[ 0, false ]
								);

								$before = $hm_test_pending_notices();
								$fixed  = $notice_for( $main_fail, 'Wired up the handler.' );
								check(
									'Mark fixed with a note schedules one event',
									$hm_test_pending_notices() - $before,
									1
								);
								check(
									'its arguments are the Fixed result, then the Fail',
									is_int( wp_next_scheduled( Notifications\FIXED_EVENT, [ $fixed->ID, $main_fail->ID ] ) ),
									true
								);

								$before = $hm_test_pending_notices();
								$blank  = $notice_for( $blank_note, '' );
								check(
									'an empty note schedules nothing',
									[
										$hm_test_pending_notices() - $before,
										wp_next_scheduled( Notifications\FIXED_EVENT, [ $blank->ID, $blank_note->ID ] ),
									],
									[ 0, false ]
								);

								$whitespace = Results\save(
									$checklist_id,
									$tester,
									$step_ids[1],
									'Step 1',
									'fail',
									'Broken again.'
								);
								$before     = $hm_test_pending_notices();
								$spaces     = $notice_for( $whitespace, " \n\t " );
								check(
									'a note of only whitespace schedules nothing',
									[
										$hm_test_pending_notices() - $before,
										wp_next_scheduled( Notifications\FIXED_EVENT, [ $spaces->ID, $whitespace->ID ] ),
									],
									[ 0, false ]
								);

								$fail_again = static function ( string $comment ) use ( $checklist_id, $tester, $step_ids ): WP_Post {
									$fail = Results\save( $checklist_id, $tester, $step_ids[1], 'Step 1', 'fail', $comment );

									if ( ! $fail instanceof WP_Post ) {
										throw new RuntimeException( 'The Fail was not saved.' );
									}

									return $fail;
								};

								$quiet_fail = $fail_again( 'Broken a third time.' );
								$before     = $hm_test_pending_notices();
								$quiet      = Admin\mark_fixed( $quiet_fail->ID, $developer, 'Fixed quietly.', false );
								check(
									'Mark fixed told not to notify saves the note and schedules nothing',
									[ $quiet instanceof WP_Post ? $quiet->post_content : null, $hm_test_pending_notices() - $before ],
									[ 'Fixed quietly.', 0 ]
								);

								/*
								 * Posts the Mark fixed form as the browser does, and reports whether it reached the
								 * redirect, which is thrown out of so the handler's exit never runs.
								 */
								$through_form = static function ( WP_Post $fail, array $fields ): bool {
									$saved = [ $_POST, $_REQUEST ];
									$_POST = [
										'result_id' => (string) $fail->ID,
										'_wpnonce'  => wp_create_nonce( Admin\MARK_FIXED_ACTION . '_' . $fail->ID ),
									] + $fields;
									$_REQUEST = $_POST;

									try {
										hm_test_with_filter(
											'wp_redirect',
											static function (): never {
												throw new RuntimeException( 'redirected' );
											},
											static fn () => Admin\handle_mark_fixed()
										);
									} catch ( RuntimeException $redirect ) {
										return $redirect->getMessage() === 'redirected';
									} finally {
										[ $_POST, $_REQUEST ] = $saved;
									}

									return false;
								};

								$before = $hm_test_pending_notices();
								check(
									'the form with Don\'t send email notification ticked marks it fixed and schedules nothing',
									[
										$through_form(
											$fail_again( 'Broken a fourth time.' ),
											[ 'note' => 'Fixed, no email.', Admin\NO_EMAIL_FIELD => '1' ]
										),
										$hm_test_pending_notices() - $before,
									],
									[ true, 0 ]
								);

								$before = $hm_test_pending_notices();
								check(
									'the form with it unticked schedules the email',
									[
										$through_form( $fail_again( 'Broken a fifth time.' ), [ 'note' => 'Fixed, emailed.' ] ),
										$hm_test_pending_notices() - $before,
									],
									[ true, 1 ]
								);

								$mails = $hm_test_send( $fixed->ID, $main_fail->ID );
								$mail  = $mails[0] ?? [];
								check( 'sending mails once', count( $mails ), 1 );
								check( 'to the tester', $mail['to'] ?? null, get_userdata( $tester )->user_email );
								$english = (string) ( $mail['subject'] ?? '' );
								$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
								check( 'the subject names the step', str_contains( $english, 'Step 0' ), true );
								check( 'and the site', str_contains( $english, $site ), true );

								$body = (string) ( $mail['message'] ?? '' );
								check(
									'the body holds the manager\'s note',
									str_contains( $body, 'Wired up the handler.' ),
									true
								);
								check(
									'and the comment the tester failed it with',
									str_contains( $body, 'The button does nothing.' ),
									true
								);
								check(
									'and the address of the checklist',
									str_contains( $body, Checklists\window_url( $checklist_id ) ),
									true
								);
								$marked_by = get_userdata( $developer )->display_name . ' marked a step you failed on';
								check( 'and names the manager', str_contains( $body, $marked_by ), true );

								update_user_meta( $tester, 'locale', 'ja' );

								// The locale switcher lists the installed languages once, when it is built, and wp-env has
								// no Japanese core pack, so it refuses to switch. A switcher built while the filter names
								// Japanese accepts it, and the original goes back afterwards.
								$switcher = $GLOBALS['wp_locale_switcher'];

								try {
									$japanese = hm_test_with_filter(
										'get_available_languages',
										static fn ( array $languages ): array => [ ...$languages, 'ja' ],
										static function () use ( $hm_test_send, $fixed, $main_fail ): array {
											$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
											$GLOBALS['wp_locale_switcher']->init();

											return $hm_test_send( $fixed->ID, $main_fail->ID )[0] ?? [];
										}
									);
								} finally {
									$GLOBALS['wp_locale_switcher'] = $switcher;
									delete_user_meta( $tester, 'locale' );
								}

								check(
									'a tester whose language is Japanese gets the subject in it',
									( $japanese['subject'] ?? $english ) !== $english,
									true
								);
								check(
									'and the next notice is back in the site\'s language',
									$hm_test_send( $fixed->ID, $main_fail->ID )[0]['subject'] ?? null,
									$english
								);

								$no_comment_fixed = $notice_for( $no_comment, 'Replaced the markup.' );
								$no_comment_mail  = $hm_test_send( $no_comment_fixed->ID, $no_comment->ID )[0] ?? [];
								$body             = (string) ( $no_comment_mail['message'] ?? '' );
								check(
									'a Fail with no comment leaves out the line quoting it',
									str_contains( $body, 'Your comment when it failed:' ),
									false
								);
								check( 'but the note is still there', str_contains( $body, 'Replaced the markup.' ), true );

								$ampersand_fixed = $notice_for( $ampersand, 'Changed a &lt; b.' );
								$body            = (string) ( $hm_test_send( $ampersand_fixed->ID, $ampersand->ID )[0]['message'] ?? '' );
								check(
									'the premise: a tester\'s comment is stored HTML-safe',
									$ampersand->post_content,
									'It does nothing &amp; the page reloads.'
								);
								check(
									'the email quotes the comment and the note as plain text, not as entities',
									[
										str_contains( $body, 'It does nothing & the page reloads.' ),
										str_contains( $body, 'Changed a < b.' ),
									],
									[ true, true ]
								);

								$trashed_fixed = $notice_for( $trashed, 'Reverted a plugin.' );
								wp_trash_post( $trashed_fixed->ID );
								check(
									'a Fixed result trashed before cron runs sends nothing',
									$hm_test_send( $trashed_fixed->ID, $trashed->ID ),
									[]
								);

								$orphaned_fixed = $notice_for( $orphaned, 'Cleared the cache.' );
								// Deleting the manager's user would delete the Fixed result, which they authored. A user
								// ID that no longer exists is what the sender sees once the account has gone.
								wp_update_post(
									[
										'ID'          => $orphaned_fixed->ID,
										'post_author' => 999999999,
									]
								);

								$body = (string) ( $hm_test_send( $orphaned_fixed->ID, $orphaned->ID )[0]['message'] ?? '' );
								check(
									'a notice after the manager\'s account is deleted still says a step was marked fixed',
									str_contains(
										$body,
										'A step you failed on Notice test checklist has been marked fixed.'
									),
									true
								);
							},
							'developer@humanmade.com'
						);
					},
					'tester@client.example'
				);
			}
		);
	}
);
