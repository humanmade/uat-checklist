<?php
/**
 * The Abilities API abilities: who may run each, what they read, what save-checklist writes, and that none is on
 * core's REST routes.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Abilities;
use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Notifications;
use HM\UAT_Checklist\Results;

echo "\nAbilities\n";

/**
 * Runs an ability as the current user.
 *
 * @param string $name  Ability name, without the plugin's prefix.
 * @param mixed  $input Input.
 * @return mixed Result.
 */
function hm_test_ability( string $name, $input = null ) {
	return wp_get_ability( Abilities\PREFIX . $name )->execute( $input );
}

$names = [ 'get-progress', 'get-checklist', 'list-failures', 'mark-fixed', 'save-checklist' ];

check(
	'all five are registered, listed for MCP and kept off REST',
	array_map(
		static function ( string $name ): ?array {
			$ability = wp_get_ability( Abilities\PREFIX . $name );

			if ( ! $ability ) {
				return null;
			}

			return [
				$ability->get_category(),
				$ability->get_meta_item( 'public' ),
				$ability->get_meta_item( 'show_in_rest' ),
			];
		},
		$names
	),
	array_fill( 0, 5, [ Abilities\CATEGORY, true, false ] )
);

$created = [];

try {
	hm_test_as_user(
		'administrator',
		static function ( int $administrator ): void {
			check(
				'core\'s abilities route does not serve them',
				hm_test_rest( 'GET', '/wp-abilities/v1/abilities/' . Abilities\PREFIX . 'get-progress' )->get_status(),
				404
			);

			$saved = hm_test_ability(
				'save-checklist',
				[
					'title'    => 'x',
					'sections' => [
						[
							'title' => 'S',
							'steps' => [ [ 'step' => 'A' ] ],
						],
					],
				]
			);

			check(
				'an administrator without a staff email cannot save a checklist',
				hm_test_error_code( $saved ),
				'ability_invalid_permissions'
			);

			// Should the check fail, the save may have made a draft it cannot return, so delete by author.
			$made = get_posts(
				[
					'post_type'   => Checklists\POST_TYPE,
					'post_status' => 'any',
					'author'      => $administrator,
					'fields'      => 'ids',
				]
			);

			foreach ( $made as $checklist_id ) {
				wp_delete_post( (int) $checklist_id, true );
			}
		}
	);

	hm_test_as_user(
		'editor',
		static function ( int $manager ) use ( &$created ): void {
			$saved = hm_test_ability(
				'save-checklist',
				[
					'title'    => 'Abilities test checklist',
					'intro'    => [ 'Ticket <a href="https://example.com/1">#1</a>.', '' ],
					'sections' => [
						[
							'title' => 'In the editor',
							'steps' => [
								[
									'step'           => 'Add the block <script>alert(1)</script>and <strong>save</strong>',
									'instructions'   => [ 'Insert it.', 'Save.' ],
									'expected_items' => [ 'It saves.', 'No error.' ],
									'image_url'      => 'javascript:alert(1)',
								],
							],
						],
						[
							'title' => 'Mobile width',
							'steps' => [
								[
									'step'     => 'Long text wraps',
									'expected' => 'Nothing overflows.',
									'required' => false,
								],
							],
						],
					],
				]
			);

			if ( is_wp_error( $saved ) ) {
				check( 'a manager saves a new checklist', $saved->get_error_message(), 'a checklist' );
				return;
			}

			$created[] = $saved['id'];
			$first     = $saved['sections'][0]['steps'][0];
			$second    = $saved['sections'][1]['steps'][0];
			$blocks    = parse_blocks( (string) get_post_field( 'post_content', $saved['id'] ) );

			check( 'a new checklist is a draft', get_post_status( $saved['id'] ), 'draft' );
			check( 'its title is the one given', get_the_title( $saved['id'] ), 'Abilities test checklist' );
			check(
				'each new step gets a UUID',
				[
					wp_is_uuid( $first['id'] ),
					wp_is_uuid( $second['id'] ),
					$first['id'] !== $second['id'],
				],
				[ true, true, true ]
			);
			check(
				'the intro keeps its link and drops the empty paragraph',
				$saved['intro'],
				[ 'Ticket <a href="https://example.com/1">#1</a>.' ]
			);
			check(
				'a step\'s text keeps strong and loses script tags',
				$first['step'],
				'Add the block alert(1)and <strong>save</strong>'
			);
			check( 'a javascript: image address is dropped', $first['image_url'], '' );
			check(
				'a scenario keeps its instructions and expected results',
				[ $first['instructions'], $first['expected_items'] ],
				[
					[ 'Insert it.', 'Save.' ],
					[ 'It saves.', 'No error.' ],
				]
			);
			check(
				'required defaults to true and false is kept',
				[ $first['required'], $second['required'] ],
				[ true, false ]
			);
			check(
				'the content is one Testing checklist block the plugin reads',
				Blocks\step_ids_in( $blocks ),
				[ $first['id'], $second['id'] ]
			);
			check(
				'only the optional step is left out of the required ones',
				Blocks\step_ids_in( $blocks, true ),
				[ $first['id'] ]
			);

			$published = hm_test_ability(
				'save-checklist',
				[
					'checklist_id' => $saved['id'],
					'title'        => 'Abilities test checklist',
					'status'       => 'publish',
					'sections'     => $saved['sections'],
				]
			);
			check( 'status publish publishes it', get_post_status( $saved['id'] ), 'publish' );
			check(
				'saving back what get-checklist returned changes no step',
				is_array( $published ) ? $published['sections'] : $published,
				$saved['sections']
			);

			// Saved as the manager: a test user's results are deleted with them, and the manager outlives every check.
			Results\save( $saved['id'], $manager, $first['id'], 'Add the block', 'fail', 'It did not save.' );
			Results\save( $saved['id'], $manager, $second['id'], 'Long text wraps', 'pass', '' );

			hm_test_as_user(
				'contributor',
				static function (): void {
					check(
						'a contributor without a staff email cannot read progress',
						hm_test_error_code( hm_test_ability( 'get-progress' ) ),
						'ability_invalid_permissions'
					);
				}
			);

			hm_test_as_user(
				'editor',
				static function ( int $granted ): void {
					update_user_meta( $granted, Access\GRANT_META, 1 );

					check( 'full access on the profile is a UAT manager', current_user_can( Access\CAP_MANAGE ), true );
					check(
						'but does not open the abilities',
						hm_test_error_code( hm_test_ability( 'list-failures' ) ),
						'ability_invalid_permissions'
					);
					check(
						'not even to mark a fix',
						hm_test_error_code( hm_test_ability( 'mark-fixed', [ 'result_id' => 1 ] ) ),
						'ability_invalid_permissions'
					);
				}
			);

			hm_test_as_user(
				'contributor',
				static function () use ( $saved, $first ): void {
					$progress = hm_test_ability( 'get-progress' );
					$rows     = is_array( $progress ) ? array_column( $progress['checklists'], null, 'id' ) : [];
					$row      = $rows[ $saved['id'] ] ?? null;

					check(
						'a contributor with a staff email reads the checklist\'s progress',
						$row
							? [ $row['tests'], $row['passed'], $row['failed'], $row['status'], $row['percent_tested'] ]
							: $progress,
						[ 2, 1, 1, 'complete', 100 ]
					);

					$failures  = hm_test_ability( 'list-failures', [ 'checklist_id' => $saved['id'] ] );
					$failure   = is_array( $failures ) ? ( $failures['failures'][0] ?? null ) : null;
					$site_wide = hm_test_ability( 'list-failures' )['failures'] ?? [];

					check(
						'list-failures returns only the failed step',
						is_array( $failures ) ? array_column( $failures['failures'], 'step_id' ) : $failures,
						[ $first['id'] ]
					);
					check(
						'with the tester\'s comment and the section',
						$failure ? [ $failure['comment'], $failure['section'] ] : null,
						[ 'It did not save.', 'In the editor' ]
					);
					check(
						'the site-wide list includes it',
						in_array( $first['id'], array_column( $site_wide, 'step_id' ), true ),
						true
					);
				},
				'reader@humanmade.com'
			);

			$checklist_only = [ 'checklist_id' => $saved['id'] ];
			$failure        = hm_test_ability( 'list-failures', $checklist_only )['failures'][0];
			$fixed          = hm_test_unscheduling(
				Notifications\FIXED_EVENT,
				static fn () => hm_test_ability(
					'mark-fixed',
					[
						'result_id' => $failure['result_id'],
						'note'      => 'Patched.',
					]
				)
			);

			check(
				'a manager marks it fixed',
				is_array( $fixed ) ? get_post_status( $fixed['fixed_result_id'] ) : $fixed,
				Results\FIXED_STATUS
			);
			check( 'the Fail stays on record', get_post_status( $failure['result_id'] ), Results\STATUSES['fail'] );
			check(
				'the step reads as waiting for a retest',
				hm_test_ability( 'get-checklist', $checklist_only )['sections'][0]['steps'][0]['result'],
				'fixed'
			);
			check(
				'and is no longer an open failure',
				hm_test_ability( 'list-failures', $checklist_only )['failures'],
				[]
			);
			check(
				'marking it again is refused',
				hm_test_error_code( hm_test_ability( 'mark-fixed', [ 'result_id' => $failure['result_id'] ] ) ),
				'uat_checklist_not_fixable'
			);

			// A tester's Fail, since a manager's own is never emailed whatever notify says.
			hm_test_as_user(
				'subscriber',
				static function ( int $tester ) use ( $manager, $saved, $first ): void {
					/*
					 * Saves a fresh Fail, marks it fixed through the ability as the manager, and reports whether the
					 * email was scheduled.
					 */
					$emails = static function ( array $input ) use ( $tester, $manager, $saved, $first ): bool {
						$fail = Results\save( $saved['id'], $tester, $first['id'], 'Add the block', 'fail', 'Broken.' );

						wp_set_current_user( $manager );

						try {
							return hm_test_unscheduling(
								Notifications\FIXED_EVENT,
								static function () use ( $fail, $input ): bool {
									$fixed = hm_test_ability( 'mark-fixed', [ 'result_id' => $fail->ID ] + $input );

									return is_array( $fixed ) && is_int(
										wp_next_scheduled( Notifications\FIXED_EVENT, [ $fixed['fixed_result_id'], $fail->ID ] )
									);
								}
							);
						} finally {
							wp_set_current_user( $tester );
						}
					};

					check(
						'mark-fixed with notify false schedules no email, and without it schedules one',
						[
							$emails( [ 'note' => 'Patched quietly.', 'notify' => false ] ),
							$emails( [ 'note' => 'Patched again.' ] ),
						],
						[ false, true ]
					);
				}
			);

			$reworded = $saved['sections'];

			$reworded[0]['steps'][0]['step'] = 'Insert the block and save it';
			$reworded[1]['steps'][]          = [ 'step' => 'A new step' ];

			$updated = hm_test_ability(
				'save-checklist',
				[
					'checklist_id' => $saved['id'],
					'title'        => 'Abilities test checklist',
					'sections'     => $reworded,
				]
			);
			$steps   = is_array( $updated ) ? array_merge( ...array_column( $updated['sections'], 'steps' ) ) : [];

			check( 'an update keeps the checklist published', get_post_status( $saved['id'] ), 'publish' );
			check(
				'a reworded step keeps its id and its result',
				array_slice(
					array_map( static fn ( array $step ): array => [ $step['id'], $step['result'] ], $steps ),
					0,
					2
				),
				[
					[ $first['id'], 'fixed' ],
					[ $second['id'], 'pass' ],
				]
			);
			check(
				'a step added without an id gets a new one',
				isset( $steps[2] )
					&& wp_is_uuid( $steps[2]['id'] )
					&& ! in_array( $steps[2]['id'], [ $first['id'], $second['id'] ], true ),
				true
			);

			$before  = get_post_field( 'post_content', $saved['id'] );
			$refused = static function ( array $sections ) use ( $saved ): ?string {
				return hm_test_error_code(
					hm_test_ability(
						'save-checklist',
						[
							'checklist_id' => $saved['id'],
							'title'        => 'Abilities test checklist',
							'sections'     => $sections,
						]
					)
				);
			};

			check(
				'an id the checklist does not have is refused',
				$refused(
					[
						[
							'title' => 'S',
							'steps' => [
								[
									'id'   => wp_generate_uuid4(),
									'step' => 'A',
								],
							],
						],
					]
				),
				'uat_checklist_unknown_step'
			);
			check(
				'so is an id given twice',
				$refused(
					[
						[
							'title' => 'S',
							'steps' => [
								[
									'id'   => $first['id'],
									'step' => 'A',
								],
								[
									'id'   => $first['id'],
									'step' => 'B',
								],
							],
						],
					]
				),
				'uat_checklist_unknown_step'
			);
			check(
				'and a refusal leaves the checklist as it was',
				get_post_field( 'post_content', $saved['id'] ),
				$before
			);

			wp_update_post(
				[
					'ID'          => $saved['id'],
					'post_status' => 'draft',
				]
			);

			check(
				'a draft checklist is read too',
				hm_test_ability( 'get-checklist', $checklist_only )['status'] ?? null,
				'draft'
			);
			hm_test_with_page(
				[],
				static function ( int $page_id ): void {
					check(
						'a page is not a checklist',
						hm_test_error_code( hm_test_ability( 'get-checklist', [ 'checklist_id' => $page_id ] ) ),
						'uat_checklist_not_found'
					);
				}
			);

			// Stored text is HTML-safe (a title or comment through kses), and an ability's output is plain text. The
			// title is stored as kses leaves it for a user without unfiltered_html; the editor saving it here has that
			// capability, so it is written directly. A Subscriber at a staff domain may use the abilities and has none.
			global $wpdb;
			$wpdb->update( $wpdb->posts, [ 'post_title' => 'Q&amp;A: it\'s live' ], [ 'ID' => $saved['id'] ] );
			clean_post_cache( $saved['id'] );

			hm_test_as_user(
				'subscriber',
				static function ( int $tester ) use ( $saved, $first, $checklist_only ): void {
					Results\save(
						$saved['id'],
						$tester,
						$first['id'],
						'Add the block',
						'fail',
						'It does nothing & the page reloads.'
					);

					// Not texturized: get-checklist's title goes back through save-checklist, so it must read as stored.
					$plain   = "Q&A: it's live";
					$failure = hm_test_ability( 'list-failures', $checklist_only )['failures'][0] ?? [];

					check(
						'an ability gives a checklist\'s title as plain text',
						hm_test_ability( 'get-checklist', $checklist_only )['title'] ?? null,
						$plain
					);
					check(
						'and a failure\'s checklist title and comment',
						[ $failure['checklist_title'] ?? null, $failure['comment'] ?? null ],
						[ $plain, 'It does nothing & the page reloads.' ]
					);
				},
				'tester@humanmade.com'
			);
		},
		'manager@humanmade.com'
	);
} finally {
	foreach ( $created as $checklist_id ) {
		wp_delete_post( $checklist_id, true );
	}
}
