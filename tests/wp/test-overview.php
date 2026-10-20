<?php
/**
 * Progress and UAT → Overview: what counts as passed, failed and not completed, that the Results list can show
 * exactly the results counted, and what the page prints.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Admin;
use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Overview;
use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\Results;

echo "\nThe overview's counts\n";

$step_ids = [ wp_generate_uuid4(), wp_generate_uuid4(), wp_generate_uuid4() ];

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Overview test checklist',
		'post_content' => hm_test_numbered_checklist_markup( $step_ids ),
	],
	static function ( int $checklist_id ) use ( $step_ids ): void {
		// Two testers, so the later result on a step can be checked to win. Deleting them deletes their results.
		hm_test_as_user(
			'subscriber',
			static function ( int $first ) use ( $checklist_id, $step_ids ): void {
				hm_test_as_user(
					'subscriber',
					static function ( int $second ) use ( $checklist_id, $step_ids, $first ): void {
						Results\save( $checklist_id, $first, $step_ids[0], 'Step 0', 'pass', '' );
						$latest_fail = Results\save( $checklist_id, $second, $step_ids[0], 'Step 0', 'fail', 'Broken.' );
						Results\save( $checklist_id, $first, $step_ids[1], 'Step 1', 'fail', 'Broken.' );
						Results\save( $checklist_id, $second, $step_ids[1], 'Step 1', 'pass', '' );
						Results\save( $checklist_id, $first, wp_generate_uuid4(), 'A deleted step', 'fail', 'Gone.' );

						$checklist = get_post( $checklist_id );
						$counts    = Progress\count_steps( $checklist );

						check( 'every step is a test', $counts['tests'], 3 );
						check( 'a step that passed and then failed counts as failed', $counts['failed'], 1 );
						check( 'a step that failed and then passed counts as passed', $counts['passed'], 1 );
						check( 'passed and failed steps are completed', $counts['completed'], 2 );
						check( 'a step with no result is not completed', $counts['not_completed'], 1 );
						check( 'a step is required unless marked otherwise', $counts['required'], 3 );
						check( 'required steps with a result are counted', $counts['required_completed'], 2 );
						check(
							'a checklist with a required step left is in progress',
							Progress\status( $counts ),
							Progress\STATUS_IN_PROGRESS
						);
						check(
							'two of three required steps is 66%, rounded down',
							Progress\percent_tested( $counts ),
							66
						);

						$rows = array_values(
							array_filter(
								Progress\checklist_counts(),
								static fn ( array $row ): bool => $row['id'] === $checklist_id
							)
						);
						check( 'a published checklist has a row', count( $rows ), 1 );
						check( 'its row counts its steps', $rows[0]['tests'] ?? null, 3 );

						$failed_results = static function ( bool $latest ) use ( $checklist_id ): array {
							$query = new WP_Query();
							$query->parse_query(
								[
									'post_type'   => Results\POST_TYPE,
									'post_status' => Results\STATUSES['fail'],
									'post_parent' => $checklist_id,
									'fields'      => 'ids',
								]
							);

							if ( $latest ) {
								Admin\limit_to_latest( $query, $checklist_id );
							}

							return array_map( 'intval', $query->get_posts() );
						};

						check(
							'every Fail is in the Results list, superseded or on a deleted step',
							count( $failed_results( false ) ),
							3
						);
						check(
							'with latest only, the Results list holds the one Fail the overview counts',
							$failed_results( true ),
							[ $latest_fail->ID ]
						);

						hm_test_as_user(
							'administrator',
							static function () use ( $checklist_id ): void {
								ob_start();
								Overview\render_page();
								$html = (string) ob_get_clean();

								$latest_fails_url = Overview\results_url( $checklist_id, Results\STATUSES['fail'], true );
								$fail_badge       = sprintf(
									'<a class="uat-overview__badge" href="%s"><span aria-hidden="true">1</span>',
									esc_url( $latest_fails_url )
								);

								check(
									'the page lists the checklist',
									str_contains( $html, 'Overview test checklist' ),
									true
								);
								check(
									'its row is tagged In progress',
									str_contains(
										$html,
										'<span class="uat-overview__status uat-overview__status--in-progress">'
											. 'In progress</span>'
									),
									true
								);
								check(
									'its row gives its required count and share',
									str_contains( $html, '2 of 3 required, 66%' ),
									true
								);
								check(
									'a failed count is a Fail badge leading to the latest failed results',
									str_contains( $html, $fail_badge ),
									true
								);
								check(
									'the badge asks the Results list for the latest results only',
									str_contains( $latest_fails_url, Admin\LATEST_FILTER . '=1' ),
									true
								);
							}
						);
					}
				);
			}
		);
	}
);

hm_test_with_page(
	[
		'post_type'   => Checklists\POST_TYPE,
		'post_title'  => 'Overview draft checklist',
		'post_status' => 'draft',
	],
	static function ( int $draft_id ): void {
		check(
			'a draft checklist has no row',
			in_array( $draft_id, array_column( Progress\checklist_counts(), 'id' ), true ),
			false
		);

		$query = new WP_Query();
		$query->parse_query( [ 'post_type' => Results\POST_TYPE ] );
		Admin\limit_to_latest( $query, $draft_id );
		check(
			'latest only, on a checklist with no results, lists nothing rather than everything',
			$query->get( 'post__in' ),
			[ 0 ]
		);
	}
);

$optional_ids = [ wp_generate_uuid4(), wp_generate_uuid4() ];

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Overview optional-only checklist',
		'post_content' => '<!-- wp:uat-checklist/checklist --><!-- wp:uat-checklist/section {"title":"Editor"} -->'
			. '<!-- wp:uat-checklist/step {"stepId":"' . $optional_ids[0]
			. '","step":"One","expected":"Works","required":false} /-->'
			. '<!-- wp:uat-checklist/step {"stepId":"' . $optional_ids[1]
			. '","step":"Two","expected":"Works","required":false} /-->'
			. '<!-- /wp:uat-checklist/section --><!-- /wp:uat-checklist/checklist -->',
	],
	static function ( int $checklist_id ) use ( $optional_ids ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $tester ) use ( $checklist_id, $optional_ids ): void {
				Results\save( $checklist_id, $tester, $optional_ids[0], 'One', 'pass', '' );
				$counts = Progress\count_steps( get_post( $checklist_id ) );

				check(
					'with no step marked required, every step counts as required, as the panel counts them',
					$counts['required'],
					2
				);
				check( 'and the one with a result counts towards them', $counts['required_completed'], 1 );
			}
		);
	}
);

echo "\nHow far a checklist has got\n";

$none = Progress\counts( 0, 0, 0, 0, 0 );
$done = Progress\counts( 4, 1, 1, 2, 2 );

check( 'a checklist with no steps has no status of its own', Progress\status( $none ), Progress\STATUS_EMPTY );
check(
	'a checklist with no results has not started',
	Progress\status( Progress\counts( 3, 0, 0, 3, 0 ) ),
	Progress\STATUS_NOT_STARTED
);
check(
	'a result on an optional step alone starts it',
	Progress\status( Progress\counts( 3, 1, 0, 2, 0 ) ),
	Progress\STATUS_IN_PROGRESS
);
check(
	'every required step with a result is fully tested, optional ones aside',
	Progress\status( $done ),
	Progress\STATUS_COMPLETE
);
check( 'fully tested reads 100%', Progress\percent_tested( $done ), 100 );
check(
	'199 of 200 required steps reads 99%, never 100%',
	Progress\percent_tested( Progress\counts( 200, 199, 0, 200, 199 ) ),
	99
);
check( 'no steps reads 0%', Progress\percent_tested( $none ), 0 );

$totals = Progress\totals(
	[
		Progress\counts( 4, 2, 1, 3, 2 ),
		Progress\counts( 3, 0, 3, 3, 3 ),
	]
);
check( 'totals add up across checklists', $totals, Progress\counts( 7, 2, 4, 6, 5 ) );

echo "\nThe overview's meter\n";
check( 'a checklist with no steps has no meter', Overview\meter( $none, 'm' ), '' );

$meter = Overview\meter( Progress\counts( 3, 1, 1, 3, 2 ), 'm' );
check(
	'the passed segment takes its share of the width',
	str_contains( $meter, '<rect class="uat-overview__pass" x="0.000%" width="33.333%" height="100%">' ),
	true
);
check(
	'the failed segment starts where the passed one ends, hatched',
	str_contains( $meter, '<rect fill="url(#m)" x="33.333%" width="33.333%" height="100%">' ),
	true
);
check(
	'the untested segment fills the rest',
	str_contains( $meter, '<rect class="uat-overview__untested" x="66.667%" width="33.333%" height="100%">' ),
	true
);
check( 'each segment names its count on hover', str_contains( $meter, '<title>Failed: 1 of 3</title>' ), true );
check(
	'the meter is hidden from assistive technology, which reads the counts',
	str_contains( $meter, 'aria-hidden="true"' ),
	true
);
check( 'a gap separates each pair of segments that meet', substr_count( $meter, 'uat-overview__gap' ), 2 );

echo "\nA step marked fixed\n";

$retest_ids    = [ wp_generate_uuid4(), wp_generate_uuid4() ];
$retest_markup = '<!-- wp:uat-checklist/checklist --><!-- wp:uat-checklist/section {"title":"Editor"} -->'
	. '<!-- wp:uat-checklist/step {"stepId":"' . $retest_ids[0] . '","step":"One","expected":"Works"} /-->'
	. '<!-- wp:uat-checklist/step {"stepId":"' . $retest_ids[1] . '","step":"Two","expected":"Works"} /-->'
	. '<!-- /wp:uat-checklist/section --><!-- /wp:uat-checklist/checklist -->';

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Overview retest checklist',
		'post_content' => $retest_markup,
	],
	static function ( int $checklist_id ) use ( $retest_ids ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $tester ) use ( $checklist_id, $retest_ids ): void {
				hm_test_as_user(
					'administrator',
					static function ( int $developer ) use ( $checklist_id, $retest_ids, $tester ): void {
						Results\save( $checklist_id, $tester, $retest_ids[0], 'One', 'fail', 'Broken.' );
						Results\save( $checklist_id, $tester, $retest_ids[1], 'Two', 'pass', '' );
						Results\save_fixed( $checklist_id, $developer, $retest_ids[0], 'One', 'Patched.' );

						$row_now = static fn (): array => [
							'id'    => $checklist_id,
							'title' => 'Overview retest checklist',
						] + Progress\count_steps( get_post( $checklist_id ) );
						$row     = $row_now();

						ob_start();
						Overview\render_summary( Progress\totals( [ $row ] ), [ $row ] );
						$summary = (string) ob_get_clean();

						check(
							'the summary says how many steps need a retest',
							str_contains( $summary, '1 step needs a retest' ),
							true
						);
						check(
							'a row with a step to retest carries a neutral tag, beside its status',
							str_contains(
								Overview\render_row( $row ),
								'<span class="uat-overview__status">1 to retest</span>'
							),
							true
						);

						Results\save( $checklist_id, $tester, $retest_ids[0], 'One', 'pass', '' );
						check(
							'a retest that passes clears the tag',
							str_contains( Overview\render_row( $row_now() ), 'to retest' ),
							false
						);
					}
				);
			}
		);
	}
);

hm_test_as_user(
	'administrator',
	static function (): void {
		ob_start();
		Overview\render_empty();
		$html = (string) ob_get_clean();

		check(
			'with nothing published, the page offers to add a checklist',
			str_contains( $html, 'post-new.php?post_type=' . Checklists\POST_TYPE ),
			true
		);
	},
	'developer@humanmade.com'
);

// get_the_title() texturizes `&` and `'` into entities, but the panel sets the REST title as textContent.
hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Q&A: it\'s live',
		'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
	],
	static function ( int $checklist_id ): void {
		$plain = "Q&A: it\u{2019}s live";

		check( 'a checklist\'s title reads as plain text', Blocks\checklist_title( $checklist_id ), $plain );

		hm_test_as_user(
			'subscriber',
			static function () use ( $checklist_id, $plain ): void {
				$titles = array_column(
					(array) hm_test_rest( 'GET', '/uat-checklist/v1/checklists' )->get_data(),
					'title',
					'id'
				);
				check( 'so is the title the REST list gives the panel', $titles[ $checklist_id ] ?? null, $plain );

				$one = hm_test_rest( 'GET', "/uat-checklist/v1/checklists/{$checklist_id}" );
				check(
					'and the rendered checklist escapes it once, not twice',
					str_contains( (string) ( $one->get_data()['html'] ?? '' ), "Q&amp;A: it\u{2019}s live" ),
					true
				);
			}
		);
	}
);
