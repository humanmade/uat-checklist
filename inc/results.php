<?php
/**
 * Test results: a `uat_result` post for every save, so no result is overwritten.
 *
 * A result is stored entirely in post columns. The tester is `post_author`, the
 * checklist is `post_parent`, the step text as it read when saved is `post_title`,
 * the comment is `post_content`, and Pass, Fail or Fixed is the post status. The slug
 * is `<user ID>-<step ID>`, which step_id_of() reads the step back from.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Results;

use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Overview;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

const POST_TYPE = 'uat_result';

/**
 * Result values a tester may save, mapped to the post statuses that store them.
 */
const STATUSES = [
	'pass' => 'uat-pass',
	'fail' => 'uat-fail',
];

/**
 * Post status of a developer's mark that a Fail is fixed. Outside STATUSES so REST cannot save it.
 */
const FIXED_STATUS = 'uat-fixed';

const COMMENT_MAX_LENGTH = 2000;

/**
 * Object cache group for the IDs of a checklist's results, kept for the request only so a read after a save is fresh.
 */
const CACHE_GROUP = 'hm_uat_checklist_results';

/**
 * Post status of a tester's additional comments on a checklist, kept out of STATUSES so no step query reads it.
 */
const NOTE_STATUS = 'uat-note';

/**
 * Upper bound on the testers' additional comments read for one checklist.
 */
const MAX_NOTES_PER_CHECKLIST = 100;

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register_post_type_and_statuses' );
	add_action( 'before_delete_post', __NAMESPACE__ . '\\delete_with_checklist', 10, 2 );
	wp_cache_add_non_persistent_groups( CACHE_GROUP );
}

/**
 * Deletes a checklist's results and additional comments when the checklist is deleted for good.
 *
 * Trashing the checklist keeps them, so restoring it brings its history back. A page's results are left alone: results
 * saved before checklists had a post type belong to the page that held the checklist, until they are moved.
 *
 * @param int     $post_id Post being deleted.
 * @param WP_Post $post    Post being deleted.
 * @return void
 */
function delete_with_checklist( int $post_id, WP_Post $post ): void {
	if ( $post->post_type !== Checklists\POST_TYPE ) {
		return;
	}

	$children = get_posts(
		[
			'post_type'      => POST_TYPE,
			'post_status'    => [ ...array_values( STATUSES ), FIXED_STATUS, NOTE_STATUS, 'trash' ],
			'post_parent'    => $post_id,
			'fields'         => 'ids',
			// Every one, unlike the bounded reads: a result missed here is orphaned for good.
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		]
	);

	foreach ( $children as $child_id ) {
		wp_delete_post( (int) $child_id, true );
	}

	wp_cache_delete( (string) $post_id, CACHE_GROUP );
}

/**
 * Registers the admin-only result post type, its Pass, Fail and Fixed statuses, and the status for additional comments.
 *
 * @return void
 */
function register_post_type_and_statuses(): void {
	register_post_type(
		POST_TYPE,
		[
			'labels'          => [
				'name'               => __( 'UAT results', 'uat-checklist' ),
				'singular_name'      => __( 'UAT result', 'uat-checklist' ),
				'all_items'          => __( 'Results', 'uat-checklist' ),
				'search_items'       => __( 'Search results', 'uat-checklist' ),
				'not_found'          => __( 'No results recorded yet.', 'uat-checklist' ),
				'not_found_in_trash' => __( 'No results in the trash.', 'uat-checklist' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => Overview\PAGE_SLUG,
			'show_in_rest'    => false,
			'supports'        => [ 'title', 'author' ],
			'rewrite'         => false,
			'query_var'       => false,
			'map_meta_cap'    => true,
			'capability_type' => 'post',
			'capabilities'    => [
				'create_posts'           => 'do_not_allow',
				'edit_posts'             => Access\CAP_READ,
				'read_private_posts'     => Access\CAP_READ,
				'edit_others_posts'      => Access\CAP_MANAGE,
				'edit_published_posts'   => Access\CAP_MANAGE,
				'edit_private_posts'     => Access\CAP_MANAGE,
				'publish_posts'          => Access\CAP_MANAGE,
				'delete_posts'           => Access\CAP_MANAGE,
				'delete_others_posts'    => Access\CAP_MANAGE,
				'delete_published_posts' => Access\CAP_MANAGE,
				'delete_private_posts'   => Access\CAP_MANAGE,
			],
		]
	);

	$status_args = [
		'public'                    => false,
		'protected'                 => true,
		'exclude_from_search'       => true,
		'show_in_admin_all_list'    => true,
		'show_in_admin_status_list' => true,
	];

	register_post_status(
		STATUSES['pass'],
		$status_args + [
			'label'       => label( 'pass' ),
			/* translators: %s: number of passed results. */
			'label_count' => _n_noop(
				'Pass <span class="count">(%s)</span>',
				'Pass <span class="count">(%s)</span>',
				'uat-checklist'
			),
		]
	);

	register_post_status(
		STATUSES['fail'],
		$status_args + [
			'label'       => label( 'fail' ),
			/* translators: %s: number of failed results. */
			'label_count' => _n_noop(
				'Fail <span class="count">(%s)</span>',
				'Fail <span class="count">(%s)</span>',
				'uat-checklist'
			),
		]
	);

	register_post_status(
		FIXED_STATUS,
		$status_args + [
			'label'       => label( 'fixed' ),
			/* translators: %s: number of results marked fixed. */
			'label_count' => _n_noop(
				'Fixed <span class="count">(%s)</span>',
				'Fixed <span class="count">(%s)</span>',
				'uat-checklist'
			),
		]
	);

	register_post_status(
		NOTE_STATUS,
		$status_args + [
			'label'       => __( 'Additional comments', 'uat-checklist' ),
			/* translators: %s: number of additional comments. */
			'label_count' => _n_noop(
				'Additional comments <span class="count">(%s)</span>',
				'Additional comments <span class="count">(%s)</span>',
				'uat-checklist'
			),
		]
	);
}

/**
 * Returns whether the current user may record results: logged in, and a member of this site.
 *
 * @return bool Whether they may.
 */
function can_record(): bool {
	return is_user_logged_in() && current_user_can( 'read' );
}

/**
 * Returns the translated label for a result value, or '' for anything that is not one.
 *
 * @param string $result `pass`, `fail` or `fixed`.
 * @return string Label.
 */
function label( string $result ): string {
	$labels = [
		'pass'  => __( 'Pass', 'uat-checklist' ),
		'fail'  => __( 'Fail', 'uat-checklist' ),
		'fixed' => __( 'Fixed', 'uat-checklist' ),
	];

	return $labels[ $result ] ?? '';
}

/**
 * Returns when a result was last saved, in the site's date and time formats.
 *
 * @param WP_Post $result Stored result.
 * @return string Date and time.
 */
function saved_at( WP_Post $result ): string {
	return (string) wp_date(
		get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
		(int) get_post_modified_time( 'U', true, $result )
	);
}

/**
 * Returns how many of the given steps are done on one checklist: their latest result is a Pass or a Fail.
 *
 * A step marked Fixed is waiting for a retest, so it is not done.
 *
 * @param int      $checklist_id Checklist.
 * @param string[] $step_ids     The checklist's step IDs.
 * @return int Steps done.
 */
function count_done( int $checklist_id, array $step_ids ): int {
	$latest = array_intersect_key( get_checklist_results( $checklist_id ), array_flip( $step_ids ) );

	return count(
		array_filter(
			$latest,
			static fn ( WP_Post $result ): bool => $result->post_status !== FIXED_STATUS
		)
	);
}

/**
 * Returns the result value a post status stores, or an empty string for any other status.
 *
 * @param string $post_status Post status.
 * @return string `pass`, `fail`, `fixed` or ''.
 */
function result_for_status( string $post_status ): string {
	return (string) array_search( $post_status, STATUSES + [ 'fixed' => FIXED_STATUS ], true );
}

/**
 * Returns the slug a tester's result for one step is stored under.
 *
 * @param int    $user_id Tester's user ID.
 * @param string $step_id Step ID.
 * @return string Slug.
 */
function slug_for( int $user_id, string $step_id ): string {
	return $user_id . '-' . $step_id;
}

/**
 * Returns the step ID a result was saved against.
 *
 * WordPress appends `-2`, `-3` to a repeated slug, so the ID is the 36 characters after the first hyphen.
 *
 * @param WP_Post $result Stored result.
 * @return string Step ID.
 */
function step_id_of( WP_Post $result ): string {
	return step_id_from_slug( $result->post_name );
}

/**
 * Returns the step ID a result slug holds, for a read that has the slug and not the post.
 *
 * The one place the slug rule lives: step_id_of() and result_ids_by_step() both read it here.
 *
 * @param string $slug Result slug, `<user ID>-<step ID>` with any `-2`, `-3` suffix.
 * @return string Step ID.
 */
function step_id_from_slug( string $slug ): string {
	return substr( explode( '-', $slug, 2 )[1] ?? '', 0, 36 );
}

/**
 * Returns text a result stored, as plain text.
 *
 * Stored comments, notes and step text are HTML-safe, not raw: the REST route's sanitize_textarea_field() turns a lone
 * `<` into `&lt;`, and core's kses turns `&` into `&amp;` when a user without `unfiltered_html` saves a result. That is
 * right for HTML output, where esc_html() does not encode it twice, and wrong anywhere the text is shown as it is: an
 * email, a JSON value the panel sets as textContent, an ability's output. Those decode it here.
 *
 * @param string $stored Text as stored.
 * @return string Plain text.
 */
function plain_text( string $stored ): string {
	return html_entity_decode( $stored, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Returns a step block's text without markup, as a result stores it.
 *
 * @param array<string, mixed> $step Step block, parsed.
 * @return string Step text.
 */
function step_text( array $step ): string {
	return trim( wp_strip_all_tags( (string) ( $step['attrs']['step'] ?? '' ) ) );
}

/**
 * Returns the IDs of every result on one checklist, from any tester, keyed by step ID, each step's newest first.
 *
 * Only two columns are read, so a checklist's whole history costs one query and no post objects, however long it is.
 * Newest is by modified date and then ID. Anything that moves results between checklists must keep their modified
 * dates, or the history is reordered.
 *
 * @param int $checklist_id Checklist.
 * @return array<string, int[]> Result IDs.
 */
function result_ids_by_step( int $checklist_id ): array {
	global $wpdb;

	$cached = wp_cache_get( (string) $checklist_id, CACHE_GROUP );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$statuses     = [ ...array_values( STATUSES ), FIXED_STATUS ];
	$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

	// The placeholders are built from the status count and every value goes through prepare(), which the sniffs cannot
	// follow through the spread. No core API selects two columns, and the caller needs every row, so the query is direct;
	// the result is cached below for the request.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_name FROM {$wpdb->posts}"
				. ' WHERE post_type = %s AND post_parent = %d'
				. " AND post_status IN ( {$placeholders} )"
				. ' ORDER BY post_modified DESC, ID DESC',
			[ POST_TYPE, $checklist_id, ...$statuses ]
		)
	);
	// phpcs:enable

	$ids = [];

	foreach ( $rows as $row ) {
		$ids[ step_id_from_slug( $row->post_name ) ][] = (int) $row->ID;
	}

	wp_cache_set( (string) $checklist_id, $ids, CACHE_GROUP );

	return $ids;
}

/**
 * Returns the posts for result IDs, in the order given, leaving out any that no longer exist.
 *
 * Reads the post cache only: the caller primes it first, in one query for every ID it will ask for.
 *
 * @param int[] $ids Result IDs.
 * @return WP_Post[] Results.
 */
function results_from_cache( array $ids ): array {
	return array_values(
		array_filter(
			array_map( 'get_post', $ids ),
			static fn ( $post ): bool => $post instanceof WP_Post
		)
	);
}

/**
 * Returns one step's results on a checklist, from any tester, newest first.
 *
 * @param int    $checklist_id Checklist.
 * @param string $step_id      Step ID.
 * @return WP_Post[] Results.
 */
function get_step_history( int $checklist_id, string $step_id ): array {
	$ids = result_ids_by_step( $checklist_id )[ $step_id ] ?? [];

	// One query for the step's results, not one per post. Terms and meta are never read from a result.
	_prime_post_caches( $ids, false, false );

	return results_from_cache( $ids );
}

/**
 * Returns the latest result for each step on one checklist, from any tester, keyed by step ID.
 *
 * Loads one post per step, not the step's whole history.
 *
 * @param int $checklist_id Checklist.
 * @return array<string, WP_Post> Results.
 */
function get_checklist_results( int $checklist_id ): array {
	$latest_ids = array_map(
		static fn ( array $ids ): int => $ids[0],
		result_ids_by_step( $checklist_id )
	);

	_prime_post_caches( array_values( $latest_ids ), false, false );

	return array_filter(
		array_map( 'get_post', $latest_ids ),
		static fn ( $post ): bool => $post instanceof WP_Post
	);
}

/**
 * Returns the newest Fail in one step's history, or null when it has none.
 *
 * @param WP_Post[] $history One step's results, newest first.
 * @return WP_Post|null Result.
 */
function last_failure( array $history ): ?WP_Post {
	foreach ( $history as $result ) {
		if ( $result->post_status === STATUSES['fail'] ) {
			return $result;
		}
	}

	return null;
}

/**
 * Inserts a result, so every save is a new post and none overwrites another.
 *
 * @param int    $checklist_id Checklist.
 * @param string $post_status  Post status that stores the result.
 * @param int    $user_id      Author's user ID.
 * @param string $step_id      Step ID.
 * @param string $step_text    Step text, stored so the result still reads after the step is edited.
 * @param string $content      Comment or note, already sanitised.
 * @return WP_Post|WP_Error Saved result.
 */
function insert_result(
	int $checklist_id,
	string $post_status,
	int $user_id,
	string $step_id,
	string $step_text,
	string $content
): WP_Post|WP_Error {
	$id = wp_insert_post(
		wp_slash(
			[
				'post_type'    => POST_TYPE,
				'post_status'  => $post_status,
				'post_author'  => $user_id,
				'post_parent'  => $checklist_id,
				'post_title'   => $step_text,
				'post_content' => $content,
				'post_name'    => slug_for( $user_id, $step_id ),
			]
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	wp_cache_delete( (string) $checklist_id, CACHE_GROUP );

	$saved = get_post( $id );

	return $saved instanceof WP_Post
		? $saved
		: new WP_Error(
			'uat_checklist_not_saved',
			__( 'The result could not be saved.', 'uat-checklist' )
		);
}

/**
 * Records a tester's Pass or Fail for one step.
 *
 * @param int    $checklist_id Checklist.
 * @param int    $user_id      Tester's user ID.
 * @param string $step_id      Step ID.
 * @param string $step_text    Step text, stored so the result still reads after the step is edited.
 * @param string $result       `pass` or `fail`.
 * @param string $comment      Tester's comment, already sanitised.
 * @return WP_Post|WP_Error Saved result.
 */
function save(
	int $checklist_id,
	int $user_id,
	string $step_id,
	string $step_text,
	string $result,
	string $comment
): WP_Post|WP_Error {
	return insert_result( $checklist_id, STATUSES[ $result ], $user_id, $step_id, $step_text, $comment );
}

/**
 * Records a developer's mark that a step's Fail is fixed, asking the testers to retest it.
 *
 * @param int    $checklist_id Checklist.
 * @param int    $user_id      Developer's user ID.
 * @param string $step_id      Step ID.
 * @param string $step_text    Step text, stored so the result still reads after the step is edited.
 * @param string $note         What was changed, already sanitised.
 * @return WP_Post|WP_Error Saved result.
 */
function save_fixed(
	int $checklist_id,
	int $user_id,
	string $step_id,
	string $step_text,
	string $note
): WP_Post|WP_Error {
	return insert_result( $checklist_id, FIXED_STATUS, $user_id, $step_id, $step_text, $note );
}

/**
 * Returns the sentence shown under a step once it has a result, for example "Saved as Pass on 2026年9月29日 14:03."
 * A Fixed result reads "Marked fixed on ..." instead.
 *
 * @param WP_Post $result Stored result.
 * @return string Sentence.
 */
function describe( WP_Post $result ): string {
	if ( $result->post_status === FIXED_STATUS ) {
		/* translators: %s: date and time the step was marked fixed. */
		return sprintf( __( 'Marked fixed on %s.', 'uat-checklist' ), saved_at( $result ) );
	}

	return sprintf(
		/* translators: 1: Pass or Fail, 2: date and time the result was saved. */
		__( 'Saved as %1$s on %2$s.', 'uat-checklist' ),
		label( result_for_status( $result->post_status ) ),
		saved_at( $result )
	);
}

/**
 * Returns the slug a tester's additional comments on one checklist are stored under.
 *
 * @param int $checklist_id Checklist.
 * @param int $user_id      Tester's user ID.
 * @return string Slug.
 */
function note_slug( int $checklist_id, int $user_id ): string {
	return 'note-' . $checklist_id . '-' . $user_id;
}

/**
 * Returns a tester's additional comments on one checklist, or null when they have none.
 *
 * @param int $checklist_id Checklist.
 * @param int $user_id      Tester's user ID.
 * @return WP_Post|null Comments.
 */
function get_note( int $checklist_id, int $user_id ): ?WP_Post {
	$query = new WP_Query(
		[
			'post_type'              => POST_TYPE,
			'post_status'            => NOTE_STATUS,
			'name'                   => note_slug( $checklist_id, $user_id ),
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		]
	);

	$post = $query->posts[0] ?? null;

	return $post instanceof WP_Post ? $post : null;
}

/**
 * Returns every tester's additional comments on one checklist, newest first.
 *
 * @param int $checklist_id Checklist.
 * @return WP_Post[] Comments.
 */
function get_checklist_notes( int $checklist_id ): array {
	$query = new WP_Query(
		[
			'post_type'              => POST_TYPE,
			'post_status'            => NOTE_STATUS,
			'post_parent'            => $checklist_id,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'posts_per_page'         => MAX_NOTES_PER_CHECKLIST,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		]
	);

	return array_values( array_filter( $query->posts, fn( $post ) => $post instanceof WP_Post ) );
}

/**
 * Creates or updates a tester's additional comments on one checklist, or deletes them when the comment is empty.
 *
 * @param int    $checklist_id Checklist.
 * @param int    $user_id      Tester's user ID.
 * @param string $comment      Comment, already sanitised.
 * @return WP_Post|WP_Error|null Saved comments, null once deleted.
 */
function save_note( int $checklist_id, int $user_id, string $comment ): WP_Post|WP_Error|null {
	$existing = get_note( $checklist_id, $user_id );

	if ( $comment === '' ) {
		if ( $existing instanceof WP_Post ) {
			wp_delete_post( $existing->ID, true );
		}

		return null;
	}

	$post = [
		'post_type'    => POST_TYPE,
		'post_status'  => NOTE_STATUS,
		'post_author'  => $user_id,
		'post_parent'  => $checklist_id,
		'post_title'   => '',
		'post_content' => $comment,
		'post_name'    => note_slug( $checklist_id, $user_id ),
	];

	if ( $existing instanceof WP_Post ) {
		$post['ID'] = $existing->ID;
		$id         = wp_update_post( wp_slash( $post ), true );
	} else {
		$id = wp_insert_post( wp_slash( $post ), true );
	}

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	$saved = get_post( $id );

	return $saved instanceof WP_Post
		? $saved
		: new WP_Error(
			'uat_checklist_note_not_saved',
			__( 'The comment could not be saved.', 'uat-checklist' )
		);
}

/**
 * Returns the sentence shown under a tester's additional comments once saved, for example "Saved on 2026年9月30日 15:02."
 *
 * @param WP_Post $note Stored comments.
 * @return string Sentence.
 */
function describe_note( WP_Post $note ): string {
	/* translators: %s: date and time the comment was saved. */
	return sprintf( __( 'Saved on %s.', 'uat-checklist' ), saved_at( $note ) );
}
