<?php
/**
 * Ready to retest: which of a tester's Fails a manager's Mark fixed has answered, as the list, the REST route and the
 * rendered markup report them.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Admin;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Notifications;
use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Retests;

echo "\nReady to retest\n";

$step_ids = [ wp_generate_uuid4(), wp_generate_uuid4(), wp_generate_uuid4() ];
$markup   = hm_test_numbered_checklist_markup( $step_ids );

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Retest test checklist',
		'post_content' => $markup,
	],
	static function ( int $checklist_id ) use ( $step_ids, $markup ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $tester ) use ( $checklist_id, $step_ids, $markup ): void {
				hm_test_as_user(
					'subscriber',
					static function ( int $other_tester ) use ( $checklist_id, $step_ids, $markup, $tester ): void {
						hm_test_as_user(
							'administrator',
							static function ( int $developer ) use (
								$checklist_id,
								$step_ids,
								$markup,
								$tester,
								$other_tester
							): void {
								$mark_fixed = static function ( WP_Post $fail, string $note ) use ( $developer ): WP_Post {
									// The email Mark fixed schedules is test-notifications.php's subject; here it is only unscheduled.
									$fixed = hm_test_unscheduling(
										Notifications\FIXED_EVENT,
										static fn () => Admin\mark_fixed( $fail->ID, $developer, $note )
									);

									if ( ! $fixed instanceof WP_Post ) {
										throw new RuntimeException( 'Mark fixed was refused.' );
									}

									return $fixed;
								};
								$listed_checklists = static function () use ( $tester ): array {
									return array_column( Progress\awaiting_retest( $tester ), 'checklist_id' );
								};

								$fail = Results\save( $checklist_id, $tester, $step_ids[0], 'Step 0', 'fail', 'Broken.' );
								check( 'a Fail alone is not ready to retest', Progress\awaiting_retest( $tester ), [] );

								$fixed = $mark_fixed( $fail, 'Patched.' );
								$items = Progress\awaiting_retest( $tester );
								$item  = $items[0] ?? [];

								check( 'Mark fixed puts the step on the list', count( $items ), 1 );
								check(
									'and the values describe the step, the note and who fixed it',
									[
										$item['checklist_id'] ?? null,
										$item['checklist_title'] ?? null,
										$item['step_id'] ?? null,
										$item['step'] ?? null,
										$item['note'] ?? null,
										$item['fixed_by'] ?? null,
										$item['fixed_at'] ?? null,
										$item['url'] ?? null,
									],
									[
										$checklist_id,
										'Retest test checklist',
										$step_ids[0],
										'Step 0',
										'Patched.',
										get_userdata( $developer )->display_name,
										Results\saved_at( $fixed ),
										Checklists\window_url( $checklist_id ),
									]
								);

								$other_fail = Results\save(
									$checklist_id,
									$other_tester,
									$step_ids[1],
									'Step 1',
									'fail',
									'Also broken.'
								);
								$mark_fixed( $other_fail, '' );

								check(
									'another tester\'s fixed Fail is not on this tester\'s list',
									array_column( Progress\awaiting_retest( $tester ), 'step_id' ),
									[ $step_ids[0] ]
								);

								$others = Progress\awaiting_retest( $other_tester );
								check( 'it is on theirs', array_column( $others, 'step_id' ), [ $step_ids[1] ] );
								check(
									'a Mark fixed with no note still appears, with an empty note',
									$others[0]['note'] ?? null,
									''
								);

								$as_tester = static function ( callable $callback ) use ( $tester ) {
									$previous = get_current_user_id();
									wp_set_current_user( $tester );

									try {
										return $callback();
									} finally {
										wp_set_current_user( $previous );
									}
								};

								$rest = $as_tester(
									static fn (): WP_REST_Response => hm_test_rest( 'GET', '/uat-checklist/v1/retests' )
								);
								check( 'the REST route answers a tester', $rest->get_status(), 200 );
								check( 'with the same items', $rest->get_data(), $items );

								$as_tester(
									static function () use ( $items ): void {
										$note = '<script>alert(1)</script>';
										$html = Retests\render_list( [ [ ...$items[0], 'note' => $note ] ] );

										check(
											'the list escapes a note\'s markup',
											[ str_contains( $html, '&lt;script&gt;' ), str_contains( $html, '<script>' ) ],
											[ true, false ]
										);
									}
								);

								hm_test_with_page(
									[
										'post_type'    => Checklists\POST_TYPE,
										'post_status'  => 'draft',
										'post_title'   => 'Draft retest checklist',
										'post_content' => $markup,
									],
									static function ( int $draft_id ) use (
										$tester,
										$step_ids,
										$mark_fixed,
										$listed_checklists
									): void {
										$draft_fail = Results\save(
											$draft_id,
											$tester,
											$step_ids[2],
											'Step 2',
											'fail',
											'Broken in draft.'
										);
										$mark_fixed( $draft_fail, 'Patched in draft.' );

										check(
											'a draft checklist\'s fixed Fail is not listed',
											in_array( $draft_id, $listed_checklists(), true ),
											false
										);

										// The same Fail on a published checklist is listed, so the draft is what hid it.
										wp_update_post(
											[
												'ID'          => $draft_id,
												'post_status' => 'publish',
											]
										);
										check(
											'and is listed once the checklist is published',
											in_array( $draft_id, $listed_checklists(), true ),
											true
										);
									}
								);

								hm_test_with_page(
									[
										'post_type'    => Checklists\POST_TYPE,
										'post_title'   => 'Retest untouched checklist',
										'post_content' => $markup,
									],
									static function ( int $untouched_id ) use (
										$checklist_id,
										$tester,
										$other_tester,
										$step_ids
									): void {
										// The tester has a Pass here and another tester a Fail, so none of the tester's Fails can be on it.
										Results\save( $untouched_id, $tester, $step_ids[0], 'Step 0', 'pass', '' );
										Results\save(
											$untouched_id,
											$other_tester,
											$step_ids[1],
											'Step 1',
											'fail',
											'Broken here.'
										);
										Results\save(
											$checklist_id,
											$tester,
											$step_ids[2],
											'Step 2',
											'fail',
											'A second Fail on the same checklist.'
										);

										check(
											'a checklist with two of the tester\'s Fails is listed once, and one with only their Pass and another\'s Fail not at all',
											Progress\failed_checklist_ids( $tester ),
											[ $checklist_id ]
										);

										// Each history the list reads is cached, so an uncached one was never read.
										wp_cache_delete( (string) $untouched_id, Results\CACHE_GROUP );
										wp_cache_delete( (string) $checklist_id, Results\CACHE_GROUP );
										Progress\awaiting_retest( $tester );

										check(
											'the retest list reads no history of a checklist the tester never failed on',
											wp_cache_get( (string) $untouched_id, Results\CACHE_GROUP ),
											false
										);
										check(
											'and does read the history of one they did',
											is_array( wp_cache_get( (string) $checklist_id, Results\CACHE_GROUP ) ),
											true
										);
									}
								);

								Results\save( $checklist_id, $tester, $step_ids[0], 'Step 0', 'pass', '' );
								check(
									'a new Pass on the step takes it off the list',
									Progress\awaiting_retest( $tester ),
									[]
								);
								check(
									'and leaves the other tester\'s',
									array_column( Progress\awaiting_retest( $other_tester ), 'step_id' ),
									[ $step_ids[1] ]
								);
							},
							'developer@humanmade.com'
						);
					}
				);
			}
		);
	}
);

// Stored text is HTML-safe (`&amp;`, `&lt;`), so the list decodes it for the JSON the panel sets as textContent.
$entity_step = wp_generate_uuid4();

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Q&A: it\'s live',
		'post_content' => '<!-- wp:uat-checklist/checklist --><!-- wp:uat-checklist/section {"title":"Editor"} -->'
			. '<!-- wp:uat-checklist/step {"stepId":"' . $entity_step . '","step":"Q&amp;A step","expected":"Works"} /-->'
			. '<!-- /wp:uat-checklist/section --><!-- /wp:uat-checklist/checklist -->',
	],
	static function ( int $checklist_id ) use ( $entity_step ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $tester ) use ( $checklist_id, $entity_step ): void {
				hm_test_as_user(
					'administrator',
					static function ( int $developer ) use ( $checklist_id, $entity_step, $tester ): void {
						$fail = Results\save( $checklist_id, $tester, $entity_step, 'Q&A step', 'fail', 'Broken.' );

						hm_test_unscheduling(
							Notifications\FIXED_EVENT,
							static fn () => Admin\mark_fixed( $fail->ID, $developer, 'Now a &lt; b' )
						);

						$item = Progress\awaiting_retest( $tester )[0] ?? [];
						check(
							'the retest list gives the title, step and note as plain text',
							[ $item['checklist_title'] ?? null, $item['step'] ?? null, $item['note'] ?? null ],
							[ "Q&A: it\u{2019}s live", 'Q&A step', 'Now a < b' ]
						);
					},
					'developer@humanmade.com'
				);
			}
		);
	}
);

wp_set_current_user( 0 );
check(
	'a visitor who is logged out cannot read the retest list',
	hm_test_rest( 'GET', '/uat-checklist/v1/retests' )->get_status(),
	401
);
