<?php
/**
 * Access: a user cannot make themselves a manager by setting a staff email on their own account.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Access;

/**
 * Returns whether a user holds the manage capability, read afresh from the database.
 *
 * @param int $user_id User.
 * @return bool Whether they do.
 */
function hm_test_manages_uat( int $user_id ): bool {
	clean_user_cache( $user_id );

	return user_can( $user_id, Access\CAP_MANAGE );
}

/**
 * Returns a user's stored email, read afresh from the database.
 *
 * @param int $user_id User.
 * @return string Email.
 */
function hm_test_stored_email( int $user_id ): string {
	clean_user_cache( $user_id );

	return get_userdata( $user_id )->user_email;
}

/**
 * Runs a callback with core's email change notice switched off, so the run mails nobody.
 *
 * @param callable $callback Code that updates users.
 * @return mixed What the callback returned.
 */
function hm_test_without_change_notice( callable $callback ) {
	add_filter( 'send_email_change_email', '__return_false' );

	try {
		return $callback();
	} finally {
		remove_filter( 'send_email_change_email', '__return_false' );
	}
}

/**
 * Does what wp-admin/user-edit.php does when the emailed confirmation link is opened: updates the current user's
 * email from `_new_email` while the link's hash is in the query string.
 *
 * @param int    $user_id User confirming.
 * @param string $hash    The hash in the link.
 * @return int|WP_Error Result of wp_update_user().
 */
function hm_test_open_confirmation_link( int $user_id, string $hash ) {
	$_GET['newuseremail'] = $hash;

	try {
		$pending          = get_user_meta( $user_id, '_new_email', true );
		$user             = new stdClass();
		$user->ID         = $user_id;
		$user->user_email = esc_html( trim( $pending['newemail'] ) );

		return hm_test_without_change_notice( static fn () => wp_update_user( $user ) );
	} finally {
		unset( $_GET['newuseremail'] );
	}
}

echo "\nA user cannot set a staff email on themselves\n";

hm_test_as_user(
	'subscriber',
	static function ( int $user_id ): void {
		$before  = hm_test_stored_email( $user_id );
		$own     = hm_test_email_at( 'example.com' );
		$staff   = hm_test_email_at( 'humanmade.com' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_param( 'email', $staff );
		$response = rest_do_request( $request );

		check( 'REST refuses it with a 403', $response->get_status(), 403 );
		check( 'with the plugin\'s error code', $response->get_data()['code'] ?? null, 'hm_uat_checklist_staff_email_unconfirmed' );
		check( 'REST leaves the stored email', hm_test_stored_email( $user_id ), $before );
		check( 'REST does not make them a manager', hm_test_manages_uat( $user_id ), false );
		check( 'nor as the current user', current_user_can( Access\CAP_MANAGE ), false );

		$result = hm_test_without_change_notice(
			static fn () => wp_update_user(
				[
					'ID'         => $user_id,
					'user_email' => $staff,
				]
			)
		);
		check( 'wp_update_user does not fail', $result, $user_id );
		check( 'wp_update_user reverts the email', hm_test_stored_email( $user_id ), $before );
		check( 'wp_update_user does not make them a manager', hm_test_manages_uat( $user_id ), false );

		hm_test_without_change_notice(
			static fn () => wp_update_user(
				[
					'ID'         => $user_id,
					'user_email' => $own,
				]
			)
		);
		check( 'a change to a non-staff address is saved', hm_test_stored_email( $user_id ), $own );
		check( 'and is not a manager', hm_test_manages_uat( $user_id ), false );
	},
	hm_test_email_at( 'example.org' )
);

echo "\nThe emailed confirmation link still sets one\n";

hm_test_as_user(
	'subscriber',
	static function ( int $user_id ): void {
		$staff = hm_test_email_at( 'humanmade.com' );
		$hash  = md5( $staff . time() . wp_rand() );
		update_user_meta(
			$user_id,
			'_new_email',
			[
				'hash'     => $hash,
				'newemail' => $staff,
			]
		);

		hm_test_open_confirmation_link( $user_id, 'wrong-' . $hash );
		check( 'a wrong hash is refused', hm_test_manages_uat( $user_id ), false );

		hm_test_open_confirmation_link( $user_id, '' );
		check( 'an empty hash is refused', hm_test_manages_uat( $user_id ), false );

		$_GET['newuseremail'] = $hash;
		hm_test_without_change_notice(
			static fn () => wp_update_user(
				[
					'ID'         => $user_id,
					'user_email' => hm_test_email_at( 'humanmade.com' ),
				]
			)
		);
		unset( $_GET['newuseremail'] );
		check( 'a hash for a different address is refused', hm_test_manages_uat( $user_id ), false );

		update_user_meta(
			$user_id,
			'_new_email',
			[
				'hash'     => $hash,
				'newemail' => $staff,
			]
		);
		$result = hm_test_open_confirmation_link( $user_id, $hash );
		check( 'the matching hash is accepted', $result, $user_id );
		check( 'and the staff email is saved', hm_test_stored_email( $user_id ), $staff );
		check( 'and they are a manager', hm_test_manages_uat( $user_id ), true );
	},
	hm_test_email_at( 'example.org' )
);

echo "\nOther changes are left alone\n";

hm_test_as_user(
	'administrator',
	static function ( int $admin_id ): void {
		$staff = hm_test_email_at( 'humanmade.com' );

		hm_test_as_user(
			'subscriber',
			static function ( int $target ) use ( $staff, $admin_id ): void {
				wp_set_current_user( $admin_id );
				hm_test_without_change_notice(
					static fn () => wp_update_user(
						[
							'ID'         => $target,
							'user_email' => $staff,
						]
					)
				);
				check( 'an administrator sets a staff email on a Subscriber', hm_test_stored_email( $target ), $staff );
				check( 'who becomes a manager', hm_test_manages_uat( $target ), true );
			},
			hm_test_email_at( 'example.org' )
		);

		$own_staff = hm_test_email_at( 'humanmade.com' );
		wp_set_current_user( $admin_id );
		hm_test_without_change_notice(
			static fn () => wp_update_user(
				[
					'ID'         => $admin_id,
					'user_email' => $own_staff,
				]
			)
		);
		check( 'an administrator sets a staff email on themselves', hm_test_stored_email( $admin_id ), $own_staff );
	},
	hm_test_email_at( 'example.org' )
);

hm_test_as_user(
	'subscriber',
	static function ( int $target ): void {
		hm_test_as_user(
			'contributor',
			static function ( int $actor ) use ( $target ): void {
				wp_set_current_user( $actor );
				$staff = hm_test_email_at( 'humanmade.com' );
				hm_test_without_change_notice(
					static fn () => wp_update_user(
						[
							'ID'         => $target,
							'user_email' => $staff,
						]
					)
				);
				check( 'the check applies only to a user\'s own account', hm_test_stored_email( $target ), $staff );
			},
			hm_test_email_at( 'example.org' )
		);
	},
	hm_test_email_at( 'example.org' )
);

hm_test_as_user(
	'subscriber',
	static function ( int $user_id ): void {
		$other   = hm_test_email_at( 'humanmade.com' );
		$request = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
		$request->set_param( 'email', $other );
		$response = hm_test_without_change_notice( static fn () => rest_do_request( $request ) );

		check( 'a staff user may change to another staff address over REST', $response->get_status(), 200 );
		check( 'and it is saved', hm_test_stored_email( $user_id ), $other );
	},
	hm_test_email_at( 'humanmade.com' )
);

hm_test_as_user(
	'subscriber',
	static function ( int $user_id ): void {
		update_user_meta( $user_id, Access\GRANT_META, 1 );
		$staff = hm_test_email_at( 'humanmade.com' );
		hm_test_without_change_notice(
			static fn () => wp_update_user(
				[
					'ID'         => $user_id,
					'user_email' => $staff,
				]
			)
		);
		check( 'a user with the profile grant may set a staff address', hm_test_stored_email( $user_id ), $staff );
	},
	hm_test_email_at( 'example.org' )
);

$created = hm_test_as_user(
	'subscriber',
	static fn ( int $user_id ): string => hm_test_stored_email( $user_id ),
	$created_email = hm_test_email_at( 'humanmade.com' )
);
check( 'creating a user with a staff email is unaffected', $created, $created_email );
