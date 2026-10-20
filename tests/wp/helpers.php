<?php
/**
 * Shared helpers for the WordPress suite.
 *
 * Every helper that creates something deletes it in a `finally`, and every one that adds a
 * filter or switches user puts it back there too, so a failing check leaves the site as it
 * found it.
 */

declare( strict_types=1 );

// A tally on $GLOBALS rather than globals declared at file scope: `wp eval-file` includes
// run.php inside a function, so its top-level variables are not global.
$GLOBALS['hm_test'] = [
	'pass' => 0,
	'fail' => 0,
];

/**
 * Records one check, printing ok or FAIL with both values.
 *
 * @param string $label    What is being checked.
 * @param mixed  $actual   Value the plugin produced.
 * @param mixed  $expected Value it should have produced.
 */
function check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		++$GLOBALS['hm_test']['pass'];
		printf( "  ok    %s\n", $label );
		return;
	}

	++$GLOBALS['hm_test']['fail'];
	printf(
		"  FAIL  %s\n        expected %s, got %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
}

/**
 * Runs a callback with a filter added, removing it afterwards.
 *
 * @param string   $hook     Filter name.
 * @param callable $filter   Filter callback.
 * @param callable $callback Code to run while the filter is in place.
 * @return mixed What the callback returned.
 */
function hm_test_with_filter( string $hook, callable $filter, callable $callback ) {
	add_filter( $hook, $filter, 10, 2 );

	try {
		return $callback();
	} finally {
		remove_filter( $hook, $filter, 10 );
	}
}

/**
 * Returns a failed call's error code, or null when it succeeded.
 *
 * @param mixed $result What the call returned.
 * @return string|null Error code.
 */
function hm_test_error_code( $result ): ?string {
	return is_wp_error( $result ) ? $result->get_error_code() : null;
}

/**
 * Runs a callback, then unschedules each event it scheduled on one hook.
 *
 * Only the events this callback scheduled, by their exact arguments: clearing the whole hook would also drop events
 * the site itself has queued, such as a real tester's email.
 *
 * @param string   $hook     Cron hook.
 * @param callable $callback Code that may schedule events on it.
 * @return mixed What the callback returned.
 */
function hm_test_unscheduling( string $hook, callable $callback ) {
	$scheduled = [];
	// Last, so it records the event as it is saved, after any other filter has changed or refused it.
	$record = static function ( $event ) use ( $hook, &$scheduled ) {
		if ( is_object( $event ) && $event->hook === $hook ) {
			$scheduled[] = $event->args;
		}

		return $event;
	};

	add_filter( 'schedule_event', $record, PHP_INT_MAX );

	try {
		return $callback();
	} finally {
		remove_filter( 'schedule_event', $record, PHP_INT_MAX );

		foreach ( $scheduled as $args ) {
			wp_clear_scheduled_hook( $hook, $args );
		}
	}
}

/**
 * Runs a callback with a page published, deleting it afterwards.
 *
 * @param array<string, mixed> $args     Post fields for wp_insert_post().
 * @param callable             $callback Receives the page ID.
 * @return mixed What the callback returned.
 */
function hm_test_with_page( array $args, callable $callback ) {
	$page_id = wp_insert_post(
		$args + [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'UAT test page',
		],
		true
	);

	if ( is_wp_error( $page_id ) ) {
		throw new RuntimeException( $page_id->get_error_message() );
	}

	try {
		return $callback( (int) $page_id );
	} finally {
		wp_delete_post( (int) $page_id, true );
	}
}

/**
 * Runs a callback as a newly created user of a role, deleting the user afterwards.
 *
 * @param string   $role     Role.
 * @param callable $callback Receives the user ID.
 * @param string   $email    The user's email address, or '' to leave WordPress's default of none.
 * @return mixed What the callback returned.
 */
function hm_test_as_user( string $role, callable $callback, string $email = '' ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$previous = get_current_user_id();
	$user_id  = wp_insert_user(
		[
			'user_login' => 'hm-uat-test-' . wp_generate_password( 8, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
			'user_email' => $email,
		]
	);

	if ( is_wp_error( $user_id ) ) {
		throw new RuntimeException( $user_id->get_error_message() );
	}

	wp_set_current_user( $user_id );

	try {
		return $callback( (int) $user_id );
	} finally {
		wp_set_current_user( $previous );
		wp_delete_user( (int) $user_id );
	}
}

/**
 * Dispatches a REST request and returns the response.
 *
 * @param string               $method HTTP method.
 * @param string               $route  Route, e.g. `/uat-checklist/v1/results`.
 * @param array<string, mixed> $params Body or query parameters.
 * @return WP_REST_Response Response.
 */
function hm_test_rest( string $method, string $route, array $params = [] ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );

	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_get_server()->dispatch( $request );
}

/**
 * Returns checklist block markup holding one section with one step.
 *
 * @param string $step_id Step UUID.
 * @return string Block markup.
 */
function hm_test_checklist_markup( string $step_id ): string {
	return '<!-- wp:uat-checklist/checklist --><!-- wp:uat-checklist/section {"title":"Editor"} -->'
		. '<!-- wp:uat-checklist/step {"stepId":"' . $step_id . '","step":"Add the block","expected":"It appears"} /-->'
		. '<!-- /wp:uat-checklist/section --><!-- /wp:uat-checklist/checklist -->';
}

/**
 * Returns checklist block markup holding one section with a step for each ID, named `Step 0`, `Step 1` and so on.
 *
 * @param string[] $step_ids Step UUIDs, in order.
 * @return string Block markup.
 */
function hm_test_numbered_checklist_markup( array $step_ids ): string {
	$steps = '';

	foreach ( $step_ids as $n => $step_id ) {
		$steps .= sprintf(
			'<!-- wp:uat-checklist/step {"stepId":"%s","step":"Step %d","expected":"Works"} /-->',
			$step_id,
			$n
		);
	}

	return '<!-- wp:uat-checklist/checklist --><!-- wp:uat-checklist/section {"title":"Editor"} -->'
		. $steps
		. '<!-- /wp:uat-checklist/section --><!-- /wp:uat-checklist/checklist -->';
}
