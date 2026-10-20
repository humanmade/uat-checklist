<?php
/**
 * Deleting a checklist for good deletes its results and additional comments; the trash keeps them.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Results;

echo "\nDeleting a checklist\n";

// Read straight from the table, so a result in any status, the trash included, is counted.
$children = static function ( int $parent_id ): int {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d",
			Results\POST_TYPE,
			$parent_id
		)
	);
};

hm_test_as_user(
	'subscriber',
	static function ( int $tester_id ) use ( $children ): void {
		$step_id = wp_generate_uuid4();
		$args    = static fn ( string $title ): array => [
			'post_type'    => Checklists\POST_TYPE,
			'post_title'   => $title,
			'post_content' => hm_test_checklist_markup( $step_id ),
		];

		hm_test_with_page(
			$args( 'Kept' ),
			static function ( int $kept_id ) use ( $args, $children, $tester_id, $step_id ): void {
				Results\save( $kept_id, $tester_id, $step_id, 'Add the block', 'pass', '' );

				hm_test_with_page(
					$args( 'Deleted' ),
					static function ( int $checklist_id ) use ( $children, $kept_id, $tester_id, $step_id ): void {
						Results\save( $checklist_id, $tester_id, $step_id, 'Add the block', 'fail', 'Broken' );
						Results\save_fixed( $checklist_id, $tester_id, $step_id, 'Add the block', 'Mended' );
						$trashed = Results\save( $checklist_id, $tester_id, $step_id, 'Add the block', 'pass', '' );
						wp_trash_post( $trashed->ID );
						Results\save_note( $checklist_id, $tester_id, 'All good.' );

						wp_trash_post( $checklist_id );
						check(
							'a checklist in the trash keeps its results and comments',
							$children( $checklist_id ),
							4
						);

						// Read once first, so the history is cached and the check below sees whether the delete clears it.
						Results\result_ids_by_step( $checklist_id );
						wp_delete_post( $checklist_id, true );
						check(
							'a checklist deleted for good takes them with it, trashed ones too',
							$children( $checklist_id ),
							0
						);
						check( 'it reads back no history', Results\result_ids_by_step( $checklist_id ), [] );
						check( 'another checklist keeps its results', $children( $kept_id ), 1 );
					}
				);
			}
		);

		// Results saved before checklists had a post type belong to the page that held the checklist.
		hm_test_with_page(
			[ 'post_title' => 'Not a checklist' ],
			static function ( int $page_id ) use ( $children, $tester_id, $step_id ): void {
				$result = Results\save( $page_id, $tester_id, $step_id, 'Add the block', 'pass', '' );

				wp_delete_post( $page_id, true );
				check( 'deleting a page leaves results that name it as their parent', $children( $page_id ), 1 );
				wp_delete_post( $result->ID, true );
			}
		);
	}
);
