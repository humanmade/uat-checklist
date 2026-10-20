<?php
/**
 * The Results list's Mark fixed: who may do it, which results it applies to, and that it adds a result rather
 * than changing the Fail.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Admin;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Notifications;
use HM\UAT_Checklist\Results;

echo "\nMark fixed\n";

$step_ids = [ wp_generate_uuid4(), wp_generate_uuid4(), wp_generate_uuid4() ];

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Mark fixed test checklist',
		'post_content' => hm_test_numbered_checklist_markup( $step_ids ),
	],
	static function ( int $checklist_id ) use ( $step_ids ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $tester ) use ( $checklist_id, $step_ids ): void {
				$earlier_pass = Results\save( $checklist_id, $tester, $step_ids[0], 'Step 0', 'pass', '' );
				$open_fail    = Results\save( $checklist_id, $tester, $step_ids[0], 'Step 0', 'fail', 'Broken.' );
				Results\save( $checklist_id, $tester, $step_ids[1], 'Step 1', 'fail', 'Broken.' );
				$passed = Results\save( $checklist_id, $tester, $step_ids[1], 'Step 1', 'pass', '' );
				$gone   = Results\save( $checklist_id, $tester, $step_ids[2], 'Step 2', 'fail', 'Broken.' );

				$stale_fail = Results\get_step_history( $checklist_id, $step_ids[1] )[1];

				hm_test_as_user(
					'contributor',
					static function ( int $contributor ) use ( $open_fail ): void {
						check(
							'a user who cannot manage checklists is refused',
							hm_test_error_code( Admin\mark_fixed( $open_fail->ID, $contributor, '' ) ),
							'uat_checklist_forbidden'
						);
						check(
							'the row action is not offered to them',
							isset( Admin\row_actions( [], $open_fail )['uat_mark_fixed'] ),
							false
						);
					}
				);

				hm_test_as_user(
					'administrator',
					static function ( int $developer ) use (
						$checklist_id,
						$step_ids,
						$open_fail,
						$earlier_pass,
						$passed,
						$stale_fail,
						$gone
					): void {
						$refused = static function ( int $result_id, string $note = '' ) use ( $developer ): ?string {
							return hm_test_error_code( Admin\mark_fixed( $result_id, $developer, $note ) );
						};
						$offered = static function ( WP_Post $result ): bool {
							return isset( Admin\row_actions( [], $result )['uat_mark_fixed'] );
						};

						check( 'a Pass cannot be marked fixed', $refused( $passed->ID ), 'uat_checklist_not_fixable' );
						check(
							'nor an earlier Pass on a step whose latest is a Fail',
							$refused( $earlier_pass->ID ),
							'uat_checklist_not_fixable'
						);
						check(
							'a Fail that a later Pass replaced cannot be marked fixed',
							$refused( $stale_fail->ID ),
							'uat_checklist_not_fixable'
						);
						check(
							'a note past the length limit is refused',
							$refused( $open_fail->ID, str_repeat( 'a', Results\COMMENT_MAX_LENGTH + 1 ) ),
							'uat_checklist_note_too_long'
						);
						check( 'the row action is offered on a latest Fail', $offered( $open_fail ), true );
						check( 'and not on a Pass', $offered( $passed ), false );

						$fixed = hm_test_unscheduling(
							Notifications\FIXED_EVENT,
							static fn () => Admin\mark_fixed( $open_fail->ID, $developer, 'Patched.' )
						);

						check(
							'a manager marks a latest Fail fixed',
							$fixed instanceof WP_Post ? $fixed->post_status : $fixed,
							Results\FIXED_STATUS
						);
						check(
							'the Fixed result is the developer\'s, with their note',
							$fixed instanceof WP_Post ? [ (int) $fixed->post_author, $fixed->post_content ] : null,
							[ $developer, 'Patched.' ]
						);

						$fail = get_post( $open_fail->ID );
						check(
							'the Fail is left as it was',
							$fail instanceof WP_Post
								? [ $fail->post_status, $fail->post_content, $fail->post_modified_gmt ]
								: null,
							[ Results\STATUSES['fail'], 'Broken.', $open_fail->post_modified_gmt ]
						);
						check(
							'Fixed is now the step\'s latest result',
							Results\get_checklist_results( $checklist_id )[ $step_ids[0] ]->post_status,
							Results\FIXED_STATUS
						);
						check(
							'a Fail with a Fixed after it cannot be marked fixed again',
							$refused( $open_fail->ID ),
							'uat_checklist_not_fixable'
						);
						check( 'and offers no row action', $offered( $open_fail ), false );

						wp_update_post(
							[
								'ID'           => $checklist_id,
								'post_content' => '',
							]
						);
						check(
							'a step no longer in its checklist cannot be marked fixed',
							$refused( $gone->ID ),
							'uat_checklist_no_step'
						);
					},
					'developer@humanmade.com'
				);
			}
		);
	}
);

echo "\nChecklist links in the Results list\n";

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Checklist links test',
		'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
	],
	static function ( int $checklist_id ): void {
		hm_test_as_user(
			'contributor',
			static function () use ( $checklist_id ): void {
				check(
					'a reader who cannot edit checklists gets no links with no instructions page',
					Admin\checklist_links( $checklist_id ),
					[]
				);
			}
		);

		hm_test_as_user(
			'editor',
			static function () use ( $checklist_id ): void {
				check(
					'a manager gets only Edit checklist with no instructions page',
					Admin\checklist_links( $checklist_id ),
					[
						sprintf(
							'<a href="%s">Edit checklist</a>',
							esc_url( (string) get_edit_post_link( $checklist_id ) )
						),
					]
				);
			},
			'manager@humanmade.com'
		);

		hm_test_with_page(
			[ 'post_title' => 'How to test' ],
			static function ( int $page_id ) use ( $checklist_id ): void {
				update_post_meta( $checklist_id, Checklists\INSTRUCTIONS_META, $page_id );
				$view = sprintf( '<a href="%s">View instructions</a>', esc_url( (string) get_permalink( $page_id ) ) );

				hm_test_as_user(
					'contributor',
					static function () use ( $checklist_id, $view ): void {
						check(
							'a reader who cannot edit gets View instructions and no Edit link',
							Admin\checklist_links( $checklist_id ),
							[ $view ]
						);
					}
				);

				hm_test_as_user(
					'editor',
					static function () use ( $checklist_id, $view ): void {
						$edit = sprintf(
							'<a href="%s">Edit checklist</a>',
							esc_url( (string) get_edit_post_link( $checklist_id ) )
						);

						check(
							'a manager gets Edit checklist, then View instructions',
							Admin\checklist_links( $checklist_id ),
							[ $edit, $view ]
						);
					},
					'manager@humanmade.com'
				);
			}
		);
	}
);
