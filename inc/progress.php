<?php
/**
 * Testing progress: how many steps each published checklist holds, and how many have passed, failed, wait for a
 * retest or have not been tested yet.
 *
 * A step is decided by its latest result from any tester, the rule the panel shows testers, so every count here
 * agrees with what they see.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Progress;

use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\REST;
use HM\UAT_Checklist\Results;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * A checklist with no steps.
 */
const STATUS_EMPTY = 'empty';

/**
 * A checklist with steps and not one result.
 */
const STATUS_NOT_STARTED = 'not-started';

/**
 * A checklist with some results, and a required step still without one.
 */
const STATUS_IN_PROGRESS = 'in-progress';

/**
 * A checklist whose every required step has a result: the point at which the panel tells a tester they may stop.
 */
const STATUS_COMPLETE = 'complete';

/**
 * Returns the published checklists by title, as many as the panel's picker lists.
 *
 * @return WP_Post[] Checklists.
 */
function published_checklists(): array {
	$query = new WP_Query(
		[
			'post_type'              => Checklists\POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => REST\MAX_CHECKLISTS,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]
	);

	return array_values(
		array_filter(
			$query->posts,
			static fn ( $post ): bool => $post instanceof WP_Post
		)
	);
}

/**
 * Returns the step IDs a checklist holds now, in order.
 *
 * @param WP_Post $checklist Checklist.
 * @return string[] Step IDs.
 */
function step_ids( WP_Post $checklist ): array {
	return Blocks\step_ids_in( parse_blocks( $checklist->post_content ) );
}

/**
 * Returns the latest result on each of the given steps, keyed by step ID.
 *
 * Results on steps since deleted from the checklist are left out; they stay in the Results list.
 *
 * @param int      $checklist_id Checklist.
 * @param string[] $step_ids     The checklist's step IDs.
 * @return array<string, WP_Post> Results.
 */
function latest_results( int $checklist_id, array $step_ids ): array {
	return array_intersect_key(
		Results\get_checklist_results( $checklist_id ),
		array_flip( $step_ids )
	);
}

/**
 * Returns the counts for each published checklist, by title.
 *
 * @return array<int, array{id: int, title: string, tests: int, completed: int, not_completed: int, passed: int, failed: int, retest: int, required: int, required_completed: int}> Counts.
 */
function checklist_counts(): array {
	return array_map(
		static fn ( WP_Post $checklist ): array => [
			'id'    => $checklist->ID,
			'title' => Blocks\checklist_title( $checklist ),
		] + count_steps( $checklist ),
		published_checklists()
	);
}

/**
 * Returns how many of a checklist's steps have passed, failed, wait for a retest or have not been tested, by each
 * step's latest result, and how many of its required steps are done.
 *
 * A checklist with no step marked required counts every step as required, as the panel's count does.
 *
 * @param WP_Post $checklist Checklist.
 * @return array{tests: int, completed: int, not_completed: int, passed: int, failed: int, retest: int, required: int, required_completed: int} Counts.
 */
function count_steps( WP_Post $checklist ): array {
	$blocks       = parse_blocks( $checklist->post_content );
	$step_ids     = Blocks\step_ids_in( $blocks );
	$required_ids = Blocks\step_ids_in( $blocks, true ) ?: $step_ids;
	$latest       = latest_results( $checklist->ID, $step_ids );
	$tally        = array_count_values(
		array_map(
			static fn ( WP_Post $result ): string => Results\result_for_status( $result->post_status ),
			$latest
		)
	);

	return counts(
		count( $step_ids ),
		$tally['pass'] ?? 0,
		$tally['fail'] ?? 0,
		count( $required_ids ),
		Results\count_done( $checklist->ID, $required_ids ),
		$tally['fixed'] ?? 0
	);
}

/**
 * Returns the counts a reader is shown, from the six that decide them.
 *
 * A step whose latest result is Fixed is waiting for a retest: it is neither completed nor tested.
 *
 * @param int $tests              Steps.
 * @param int $passed             Steps whose latest result is Pass.
 * @param int $failed             Steps whose latest result is Fail.
 * @param int $required           Required steps.
 * @param int $required_completed Required steps whose latest result is Pass or Fail.
 * @param int $retest             Steps whose latest result is Fixed.
 * @return array{tests: int, completed: int, not_completed: int, passed: int, failed: int, retest: int, required: int, required_completed: int} Counts.
 */
function counts(
	int $tests,
	int $passed,
	int $failed,
	int $required,
	int $required_completed,
	int $retest = 0
): array {
	return [
		'tests'              => $tests,
		'completed'          => $passed + $failed,
		'not_completed'      => $tests - $passed - $failed,
		'passed'             => $passed,
		'failed'             => $failed,
		'retest'             => $retest,
		'required'           => $required,
		'required_completed' => $required_completed,
	];
}

/**
 * Returns the counts summed across checklists.
 *
 * @param array<int, array{tests: int, passed: int, failed: int, retest: int, required: int, required_completed: int}> $rows Each checklist's counts.
 * @return array{tests: int, completed: int, not_completed: int, passed: int, failed: int, retest: int, required: int, required_completed: int} Totals.
 */
function totals( array $rows ): array {
	return counts(
		(int) array_sum( array_column( $rows, 'tests' ) ),
		(int) array_sum( array_column( $rows, 'passed' ) ),
		(int) array_sum( array_column( $rows, 'failed' ) ),
		(int) array_sum( array_column( $rows, 'required' ) ),
		(int) array_sum( array_column( $rows, 'required_completed' ) ),
		(int) array_sum( array_column( $rows, 'retest' ) )
	);
}

/**
 * Returns how far a checklist, or a set of them, has got: one of the STATUS_ constants.
 *
 * @param array{tests: int, completed: int, required: int, required_completed: int} $counts Counts.
 * @return string Status.
 */
function status( array $counts ): string {
	return match ( true ) {
		$counts['tests'] === 0                                => STATUS_EMPTY,
		$counts['completed'] === 0                            => STATUS_NOT_STARTED,
		$counts['required_completed'] >= $counts['required'] => STATUS_COMPLETE,
		default                                               => STATUS_IN_PROGRESS,
	};
}

/**
 * Returns the share of the required steps that have a result, as a whole percentage.
 *
 * Rounded down, so the figure reads 100 only once every required step has a result.
 *
 * @param array{required: int, required_completed: int} $counts Counts.
 * @return int Percentage, 0 when there are no steps.
 */
function percent_tested( array $counts ): int {
	return $counts['required'] === 0
		? 0
		: intdiv( $counts['required_completed'] * 100, $counts['required'] );
}

/**
 * Returns a tester's Fails that have been fixed and wait for a retest, by checklist title and then step order.
 *
 * Worked out from the results on each request, and nothing is stored. A step qualifies when its latest result, from
 * anyone, is Fixed and the Fail it answers was the tester's. Only an open Fail can be marked fixed, so that Fail is
 * the step's newest. The tester's own later Pass or Fail, or a newer result from someone else, takes the step off
 * the list, because either becomes the latest result.
 *
 * Only the checklists the tester has failed a step on are read, since no other can hold one of their Fails, and on
 * those only the steps whose latest result is Fixed have their history read, for the Fail. It runs on every dashboard
 * and Profile load and after every save in the panel, so reading every checklist's history would grow with the whole
 * site.
 *
 * The step text and note are returned as plain text: they go to JSON the panel sets as textContent, and to markup that
 * escapes them.
 *
 * @param int $user_id Tester's user ID.
 * @return array<int, array{checklist_id: int, checklist_title: string, step_id: string, step: string, note: string, fixed_by: string, fixed_at: string, url: string}> Steps to retest.
 */
function awaiting_retest( int $user_id ): array {
	$items     = [];
	$failed_on = array_flip( failed_checklist_ids( $user_id ) );

	if ( $failed_on === [] ) {
		return [];
	}

	foreach ( published_checklists() as $checklist ) {
		if ( ! isset( $failed_on[ $checklist->ID ] ) ) {
			continue;
		}

		$latest = Results\get_checklist_results( $checklist->ID );

		foreach ( step_ids( $checklist ) as $step_id ) {
			$fixed = $latest[ $step_id ] ?? null;

			if ( $fixed === null || $fixed->post_status !== Results\FIXED_STATUS ) {
				continue;
			}

			// Only a step waiting for a retest needs its history, to find the Fail the tester is asked to retest.
			$failure = Results\last_failure( Results\get_step_history( $checklist->ID, $step_id ) );

			if ( $failure === null || (int) $failure->post_author !== $user_id ) {
				continue;
			}

			$fixer = get_userdata( (int) $fixed->post_author );

			$items[] = [
				'checklist_id'    => $checklist->ID,
				'checklist_title' => Blocks\checklist_title( $checklist ),
				'step_id'         => $step_id,
				'step'            => Results\plain_text( $fixed->post_title ),
				'note'            => Results\plain_text( $fixed->post_content ),
				'fixed_by'        => $fixer !== false ? $fixer->display_name : '',
				'fixed_at'        => Results\saved_at( $fixed ),
				'url'             => Checklists\window_url( $checklist->ID ),
			];
		}
	}

	return $items;
}

/**
 * Returns the IDs of the checklists a tester has saved a Fail on, published or not.
 *
 * @param int $user_id Tester's user ID.
 * @return int[] Checklist IDs.
 */
function failed_checklist_ids( int $user_id ): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query cannot select distinct parents, and paging the Fails instead could miss a checklist. The answer changes with every save.
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s AND post_author = %d",
			Results\POST_TYPE,
			Results\STATUSES['fail'],
			$user_id
		)
	);

	return array_map( 'intval', $ids );
}
