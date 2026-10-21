<?php
/**
 * REST routes: saving a step result or additional comments, and the checklist list, checklist and retest list the
 * Testing checklist panel reads.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\REST;

use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Screenshots;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

const REST_NAMESPACE = 'uat-checklist/v1';

const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

/**
 * Upper bound on the checklists the panel's picker lists.
 */
const MAX_CHECKLISTS = 200;

/**
 * Results one user may save per RATE_WINDOW. A tester clicks through steps one at a time, so 30 a minute is well
 * above any real use and stops a script filling the posts table.
 */
const RATE_LIMIT = 30;

const RATE_WINDOW = MINUTE_IN_SECONDS;

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
}

/**
 * Returns the argument schema for a checklist ID.
 *
 * @return array<string, mixed> Schema.
 */
function checklist_id_arg(): array {
	return [
		'type'              => 'integer',
		'required'          => true,
		'minimum'           => 1,
		'validate_callback' => 'rest_validate_request_arg',
		'sanitize_callback' => 'absint',
	];
}

/**
 * Returns the argument schema for a comment.
 *
 * @return array<string, mixed> Schema.
 */
function comment_arg(): array {
	return [
		'type'              => 'string',
		'default'           => '',
		'maxLength'         => Results\COMMENT_MAX_LENGTH,
		'validate_callback' => 'rest_validate_request_arg',
		'sanitize_callback' => 'sanitize_textarea_field',
	];
}

/**
 * Registers `POST uat-checklist/v1/results` and `/notes`, and the three `GET` routes the panel reads: the checklist
 * list, one checklist and the tester's retest list.
 *
 * @return void
 */
function register_routes(): void {
	register_rest_route(
		REST_NAMESPACE,
		'/checklists',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_checklists',
			'permission_callback' => 'HM\\UAT_Checklist\\Results\\can_record',
		]
	);

	register_rest_route(
		REST_NAMESPACE,
		'/checklists/(?P<id>\d+)',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\get_checklist',
			'permission_callback' => 'HM\\UAT_Checklist\\Results\\can_record',
			'args'                => [ 'id' => checklist_id_arg() ],
		]
	);

	register_rest_route(
		REST_NAMESPACE,
		'/retests',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\\list_retests',
			'permission_callback' => 'HM\\UAT_Checklist\\Results\\can_record',
		]
	);

	register_rest_route(
		REST_NAMESPACE,
		'/results',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\save_result',
			'permission_callback' => 'HM\\UAT_Checklist\\Results\\can_record',
			'args'                => [
				'checklist_id' => checklist_id_arg(),
				'step_id'      => [
					'type'              => 'string',
					'required'          => true,
					'pattern'           => UUID_PATTERN,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'status'       => [
					'type'              => 'string',
					'required'          => true,
					'enum'              => array_keys( Results\STATUSES ),
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_key',
				],
				'comment'      => comment_arg(),
			],
		]
	);

	register_rest_route(
		REST_NAMESPACE,
		'/notes',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\\save_note',
			'permission_callback' => 'HM\\UAT_Checklist\\Results\\can_record',
			'args'                => [
				'checklist_id' => checklist_id_arg(),
				'comment'      => comment_arg(),
			],
		]
	);
}

/**
 * Lists the published checklists by title, with the testing progress on each.
 *
 * @return WP_REST_Response `[ { id, title, done, total, required_done, required_total } ]`.
 */
function list_checklists(): WP_REST_Response {
	$checklists = [];

	foreach ( Progress\published_checklists() as $checklist ) {
		$blocks       = parse_blocks( $checklist->post_content );
		$step_ids     = Blocks\step_ids_in( $blocks );
		$required_ids = Blocks\step_ids_in( $blocks, true );

		$checklists[] = [
			'id'             => $checklist->ID,
			'title'          => Blocks\checklist_title( $checklist ),
			'done'           => Results\count_done( $checklist->ID, $step_ids ),
			'total'          => count( $step_ids ),
			'required_done'  => Results\count_done( $checklist->ID, $required_ids ),
			'required_total' => count( $required_ids ),
		];
	}

	return new WP_REST_Response( $checklists );
}

/**
 * Lists the current user's Fails that have been fixed and wait for a retest.
 *
 * @return WP_REST_Response `[ { checklist_id, checklist_title, step_id, step, note, fixed_by, fixed_at, url } ]`.
 */
function list_retests(): WP_REST_Response {
	return new WP_REST_Response( Progress\awaiting_retest( get_current_user_id() ) );
}

/**
 * Returns the published checklist with an ID, or why it cannot be used.
 *
 * @param int $checklist_id Checklist ID.
 * @return WP_Post|WP_Error Checklist.
 */
function published_checklist( int $checklist_id ): WP_Post|WP_Error {
	$checklist = get_post( $checklist_id );

	if ( ! $checklist instanceof WP_Post || ! Checklists\is_published_checklist( $checklist ) ) {
		return new WP_Error(
			'uat_checklist_not_found',
			__( 'This checklist could not be found.', 'uat-checklist' ),
			[ 'status' => 404 ]
		);
	}

	return $checklist;
}

/**
 * Returns one checklist, rendered for the current user.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error `{ html }`, or why it was refused.
 */
function get_checklist( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$checklist = published_checklist( (int) $request['id'] );

	if ( is_wp_error( $checklist ) ) {
		return $checklist;
	}

	return new WP_REST_Response( [ 'html' => Blocks\render_checklist( $checklist ) ] );
}

/**
 * Returns how many results a user has saved within RATE_WINDOW.
 *
 * Counted from the posts table, which already records every save, so the limit stores nothing of its own.
 *
 * @param int $user_id User ID.
 * @return int Results saved.
 */
function recent_save_count( int $user_id ): int {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WP_Query cannot count by date without loading the posts, and the answer changes with every save.
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_date_gmt >= %s",
			Results\POST_TYPE,
			$user_id,
			gmdate( 'Y-m-d H:i:s', time() - RATE_WINDOW )
		)
	);
}

/**
 * Saves the current user's result for one step of a published checklist.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error `{ status, label, message }`, or why it was refused.
 */
function save_result( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$step_id = (string) $request['step_id'];
	$status  = (string) $request['status'];
	// Already trimmed: the route sanitises it with sanitize_textarea_field().
	$comment   = (string) $request['comment'];
	$checklist = published_checklist( (int) $request['checklist_id'] );

	if ( is_wp_error( $checklist ) ) {
		return $checklist;
	}

	$step = Blocks\find_step( $checklist, $step_id );

	if ( $step === null ) {
		return new WP_Error(
			'uat_checklist_no_step',
			__( 'This step is no longer in the checklist. Reload the page and try again.', 'uat-checklist' ),
			[ 'status' => 404 ]
		);
	}

	if ( $status === 'fail' && $comment === '' ) {
		return new WP_Error(
			'uat_checklist_comment_required',
			__( 'Add a comment saying what went wrong.', 'uat-checklist' ),
			[ 'status' => 400 ]
		);
	}

	if ( recent_save_count( get_current_user_id() ) >= RATE_LIMIT ) {
		return new WP_Error(
			'hm_uat_checklist_rate_limited',
			__( 'Too many results saved in the last minute. Wait a moment, then save again.', 'uat-checklist' ),
			[ 'status' => 429 ]
		);
	}

	$file = Screenshots\chosen_file( $request->get_file_params() );

	if ( $file !== null && Screenshots\uploaded_today( get_current_user_id() ) >= Screenshots\daily_limit() ) {
		return new WP_Error(
			'hm_uat_checklist_screenshot_limited',
			sprintf(
				/* translators: %s: how many screenshots a tester can add in a day. */
				__( 'You have added %s screenshots in the last day, the most a tester can. Save without one, or add it tomorrow.', 'uat-checklist' ),
				number_format_i18n( Screenshots\daily_limit() )
			),
			[ 'status' => 429 ]
		);
	}

	$upload = $file === null ? null : Screenshots\store( $file );

	if ( is_wp_error( $upload ) ) {
		return $upload;
	}

	$result = Results\save(
		$checklist->ID,
		get_current_user_id(),
		$step_id,
		Results\step_text( $step ),
		$status,
		$comment
	);

	if ( is_wp_error( $result ) ) {
		if ( $upload !== null ) {
			wp_delete_file( $upload['file'] );
		}

		$result->add_data( [ 'status' => 500 ] );

		return $result;
	}

	$message = Results\describe( $result );

	// The result stands without its screenshot: the Pass or Fail is the record, and the tester is told to add it again.
	if ( $upload !== null && is_wp_error( Screenshots\attach( $upload, $result ) ) ) {
		wp_delete_file( $upload['file'] );
		$message .= ' ' . __( 'The screenshot could not be saved.', 'uat-checklist' );
	}

	return new WP_REST_Response(
		[
			'status'  => $status,
			'label'   => Results\label( $status ),
			'message' => $message,
		]
	);
}

/**
 * Saves the current user's additional comments on a published checklist, or deletes them when empty.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error What happened, or why it was refused.
 */
function save_note( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$checklist = published_checklist( (int) $request['checklist_id'] );

	if ( is_wp_error( $checklist ) ) {
		return $checklist;
	}

	// Already trimmed: the route sanitises it with sanitize_textarea_field().
	$note = Results\save_note( $checklist->ID, get_current_user_id(), (string) $request['comment'] );

	if ( is_wp_error( $note ) ) {
		$note->add_data( [ 'status' => 500 ] );

		return $note;
	}

	return new WP_REST_Response(
		[
			'message' => $note === null
				? __( 'Comment removed.', 'uat-checklist' )
				: Results\describe_note( $note ),
		]
	);
}
