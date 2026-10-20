<?php
/**
 * The REST routes the panel reads and saves through: who may call them, and what a save refuses.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Results;

echo "\nREST routes\n";

$step_id = wp_generate_uuid4();

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Buttons',
		'post_content' => hm_test_checklist_markup( $step_id ),
	],
	static function ( int $checklist_id ) use ( $step_id ): void {
		wp_set_current_user( 0 );
		check(
			'a visitor who is logged out cannot list checklists',
			hm_test_rest( 'GET', '/uat-checklist/v1/checklists' )->get_status(),
			401
		);

		hm_test_as_user(
			'subscriber',
			static function ( int $user_id ) use ( $checklist_id, $step_id ): void {
				$list = hm_test_rest( 'GET', '/uat-checklist/v1/checklists' );
				$ids  = array_column( (array) $list->get_data(), 'id' );
				check( 'a member of the site can list checklists', $list->get_status(), 200 );
				check( 'the list includes the checklist', in_array( $checklist_id, $ids, true ), true );

				$one = hm_test_rest( 'GET', "/uat-checklist/v1/checklists/{$checklist_id}" );
				check(
					'one checklist renders',
					str_contains( (string) ( $one->get_data()['html'] ?? '' ), 'uat-checklist' ),
					true
				);

				$params = [
					'checklist_id' => $checklist_id,
					'step_id'      => $step_id,
				];

				$no_comment = hm_test_rest( 'POST', '/uat-checklist/v1/results', $params + [ 'status' => 'fail' ] );
				check( 'a Fail with no comment is refused', $no_comment->get_status(), 400 );

				$other_step = hm_test_rest(
					'POST',
					'/uat-checklist/v1/results',
					[ 'step_id' => wp_generate_uuid4() ] + $params + [ 'status' => 'pass' ]
				);
				check( 'a step not in the checklist is refused', $other_step->get_status(), 404 );

				$saved = hm_test_rest( 'POST', '/uat-checklist/v1/results', $params + [ 'status' => 'pass' ] );
				check( 'a Pass is saved', $saved->get_status(), 200 );
				check( 'the response carries the Pass label', $saved->get_data()['label'] ?? null, 'Pass' );

				$result = Results\get_checklist_results( $checklist_id )[ $step_id ] ?? null;
				check( 'the result stores the step text from the checklist', $result?->post_title, 'Add the block' );
				check( 'the result belongs to the checklist', $result?->post_parent, $checklist_id );
				check( 'the result is stored as a Pass', $result?->post_status, Results\STATUSES['pass'] );

				$failed = hm_test_rest(
					'POST',
					'/uat-checklist/v1/results',
					$params + [
						'status'  => 'fail',
						'comment' => 'Nothing happens.',
					]
				);
				check( 'a Fail is saved', $failed->get_status(), 200 );
				check( 'the response carries the Fail label', $failed->get_data()['label'] ?? null, 'Fail' );

				$note = hm_test_rest(
					'POST',
					'/uat-checklist/v1/notes',
					[
						'checklist_id' => $checklist_id,
						'comment'      => 'Looks right.',
					]
				);
				check( 'additional comments are saved', $note->get_status(), 200 );
				check(
					'the comments are stored for the tester',
					Results\get_note( $checklist_id, $user_id )?->post_content,
					'Looks right.'
				);
			}
		);
	}
);

hm_test_with_page(
	[
		'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
		'post_title'   => 'A page holding a checklist block',
	],
	static function ( int $page_id ): void {
		hm_test_as_user(
			'subscriber',
			static function () use ( $page_id ): void {
				$ids = array_column(
					(array) hm_test_rest( 'GET', '/uat-checklist/v1/checklists' )->get_data(),
					'id'
				);
				check(
					'a page holding a checklist block is not a checklist',
					in_array( $page_id, $ids, true ),
					false
				);
				check(
					'nor can it be fetched as one',
					hm_test_rest( 'GET', "/uat-checklist/v1/checklists/{$page_id}" )->get_status(),
					404
				);
			}
		);
	}
);

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Rate limit',
		'post_content' => hm_test_checklist_markup( $step_id ),
	],
	static function ( int $checklist_id ) use ( $step_id ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $user_id ) use ( $checklist_id, $step_id ): void {
				$params = [
					'checklist_id' => $checklist_id,
					'step_id'      => $step_id,
					'status'       => 'pass',
				];

				for ( $i = 0; $i < HM\UAT_Checklist\REST\RATE_LIMIT; $i++ ) {
					Results\insert_result( $checklist_id, Results\STATUSES['pass'], $user_id, $step_id, 'Add the block', '' );
				}

				$limited = hm_test_rest( 'POST', '/uat-checklist/v1/results', $params );
				check( 'a save past the limit is refused', $limited->get_status(), 429 );
				check( 'the refusal names the limit', $limited->get_data()['code'] ?? null, 'hm_uat_checklist_rate_limited' );
				check(
					'the refused save stores nothing',
					count( Results\get_step_history( $checklist_id, $step_id ) ),
					HM\UAT_Checklist\REST\RATE_LIMIT
				);
			}
		);
	}
);
