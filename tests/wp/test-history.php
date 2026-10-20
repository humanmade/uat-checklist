<?php
/**
 * A result is never overwritten: every save is kept, a Fixed mark asks for a retest, and the panel shows it.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\Results;

echo "\nResult history\n";

$step_a = wp_generate_uuid4();
$step_b = wp_generate_uuid4();

check(
	'a repeated slug still reads back its step',
	Results\step_id_of( new WP_Post( (object) [ 'post_name' => "7-{$step_a}-2" ] ) ),
	$step_a
);

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'History',
		'post_content' => '<!-- wp:uat-checklist/checklist -->'
			. '<!-- wp:uat-checklist/section {"title":"Editor"} -->'
			. '<!-- wp:uat-checklist/step {"stepId":"' . $step_b
			. '","step":"Untested step","expected":"It appears"} /-->'
			. '<!-- wp:uat-checklist/step {"stepId":"' . $step_a
			. '","step":"Add the block","expected":"It appears"} /-->'
			. '<!-- /wp:uat-checklist/section --><!-- /wp:uat-checklist/checklist -->',
	],
	static function ( int $checklist_id ) use ( $step_a, $step_b ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $tester_id ) use ( $checklist_id, $step_a, $step_b ): void {
				hm_test_as_user(
					'editor',
					static function ( int $developer_id ) use ( $checklist_id, $tester_id, $step_a, $step_b ): void {
						$counts  = static fn (): array => Progress\count_steps( get_post( $checklist_id ) );
						$history = static function () use ( $checklist_id, $step_a ): array {
							return Results\get_step_history( $checklist_id, $step_a );
						};
						$latest  = static function () use ( $checklist_id, $step_a ): ?WP_Post {
							return Results\get_checklist_results( $checklist_id )[ $step_a ] ?? null;
						};
						$save    = static function ( string $status, string $comment ) use (
							$checklist_id,
							$tester_id,
							$step_a
						) {
							return Results\save(
								$checklist_id,
								$tester_id,
								$step_a,
								'Add the block',
								$status,
								$comment
							);
						};
						$params  = [
							'checklist_id' => $checklist_id,
							'step_id'      => $step_a,
						];

						$save( 'fail', 'Broken' );
						$second = $save( 'fail', 'Still broken' );
						check( 'two saves by one tester are both kept', count( $history() ), 2 );
						check( 'the later is the latest', $latest()?->post_content, 'Still broken' );
						check(
							'one step\'s history is its results, newest first',
							array_map(
								static fn ( WP_Post $result ): string => $result->post_content,
								Results\get_step_history( $checklist_id, $step_a )
							),
							[ 'Still broken', 'Broken' ]
						);
						check(
							'and a step with no result has none',
							Results\get_step_history( $checklist_id, $step_b ),
							[]
						);
						check(
							'a repeated save is stored under a suffixed slug',
							$second instanceof WP_Post && str_ends_with( $second->post_name, '-2' ),
							true
						);

						Results\save_fixed(
							$checklist_id,
							$developer_id,
							$step_a,
							'Add the block',
							'Changed the label'
						);
						check(
							'a Fixed mark becomes the latest result',
							Results\result_for_status( (string) $latest()?->post_status ),
							'fixed'
						);
						check(
							'the Fail stays in the history',
							Results\last_failure( $history() )?->post_content,
							'Still broken'
						);
						check(
							'a Fixed step counts as retest, not completed',
							array_intersect_key(
								$counts(),
								array_flip( [ 'retest', 'completed', 'not_completed', 'required_completed' ] )
							),
							[
								'completed'          => 0,
								'not_completed'      => 2,
								'retest'             => 1,
								'required_completed' => 0,
							]
						);

						$html  = Blocks\render_checklist( get_post( $checklist_id ) );
						$items = array_values(
							array_filter(
								preg_split( '/<li\b/', $html ),
								static fn ( string $item ): bool => str_contains( $item, 'data-step-id' )
							)
						);
						check(
							'the step to retest is marked retest',
							str_contains( $items[1], 'data-result="retest"' ),
							true
						);
						check(
							'its notice says who fixed it and what failed',
							[
								str_contains( $items[1], 'Changed the label' ),
								str_contains( $items[1], 'Still broken' ),
							],
							[ true, true ]
						);
						check(
							'it opens ahead of the untested step before it',
							[
								str_contains( $items[0], ' open>' ),
								str_contains( $items[1], ' open>' ),
							],
							[ false, true ]
						);

						$fixed_over_rest = hm_test_rest(
							'POST',
							'/uat-checklist/v1/results',
							$params + [ 'status' => 'fixed' ]
						);
						check( 'REST refuses to save Fixed', $fixed_over_rest->get_status(), 400 );

						$save( 'pass', '' );
						check(
							'a Pass after the Fixed is the latest again',
							Results\result_for_status( (string) $latest()?->post_status ),
							'pass'
						);
						check( 'and the retest is cleared', [ $counts()['retest'], $counts()['passed'] ], [ 0, 1 ] );
						check( 'with every save kept', count( $history() ), 4 );
					}
				);
			}
		);
	}
);
