<?php
/**
 * Access: the staff email match, the profile grant, and the two capabilities.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Checklists;

/**
 * Returns a fresh address at a domain, so no two users in a run share one.
 *
 * @param string $domain Domain.
 * @return string Address.
 */
function hm_test_email_at( string $domain ): string {
	return 'uat-' . wp_generate_password( 8, false ) . '@' . $domain;
}

/**
 * Posts the profile checkbox as one user for another, through the save hook core fires, and returns whether the
 * grant is set afterwards.
 *
 * @param int  $actor   User saving the profile.
 * @param int  $target  User whose profile it is.
 * @param bool $checked Whether the box is ticked in the form.
 * @return bool Whether the target holds the grant.
 */
function hm_test_post_grant( int $actor, int $target, bool $checked ): bool {
	wp_set_current_user( $actor );

	if ( $checked ) {
		$_POST[ Access\GRANT_META ] = '1';
	}

	try {
		Access\save_profile_field( $target );
	} finally {
		unset( $_POST[ Access\GRANT_META ] );
	}

	return (bool) get_user_meta( $target, Access\GRANT_META, true );
}

echo "\nA staff email is an exact domain match\n";

foreach (
	[
		'x@humanmade.com'         => true,
		'X@HumanMade.com'         => true,
		'x@humanmade.com.example' => false,
		'x@nothumanmade.com'      => false,
		'x@dev.humanmade.com'     => false,
		'x@example.com'           => false,
	] as $address => $staff
) {
	$user             = new WP_User();
	$user->user_email = $address;
	check( "{$address} is " . ( $staff ? '' : 'not ' ) . 'staff', Access\has_staff_email( $user ), $staff );
}

$outsider             = new WP_User();
$outsider->user_email = 'x@partner.example';
check(
	'the filter adds a domain',
	hm_test_with_filter(
		'hm_uat_checklist_staff_domains',
		static fn ( array $domains ): array => [ ...$domains, ' Partner.Example ' ],
		static fn (): bool => Access\has_staff_email( $outsider )
	),
	true
);

echo "\nThe profile grant makes a manager\n";

hm_test_as_user(
	'subscriber',
	static function ( int $user_id ): void {
		$user = get_userdata( $user_id );
		check( 'an outside user is not a manager', Access\is_manager( $user ), false );

		update_user_meta( $user_id, Access\GRANT_META, 1 );
		check( 'with the grant they are', Access\is_manager( $user ), true );
		check( 'and are not staff by it', Access\has_staff_email( $user ), false );
	},
	hm_test_email_at( 'example.com' )
);

echo "\nCapabilities follow the email and the role\n";

$checklist_args = [
	'post_type'    => Checklists\POST_TYPE,
	'post_title'   => 'Access checklist',
	'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
];

hm_test_with_page(
	$checklist_args,
	static function ( int $checklist_id ): void {
		foreach (
			[
				'a Subscriber'                         => [ 'subscriber', 'example.com', false, false, false ],
				'a Contributor'                        => [ 'contributor', 'example.com', false, true, false ],
				'an Administrator with no staff email' => [ 'administrator', 'example.com', false, true, false ],
				'an Editor with a staff email'         => [ 'editor', 'humanmade.com', true, true, true ],
				'a Subscriber with a staff email'      => [ 'subscriber', 'humanmade.com', true, true, true ],
			] as $label => [ $role, $domain, $manage, $read, $edit ]
		) {
			hm_test_as_user(
				$role,
				static function ( int $user_id ) use ( $label, $manage, $read, $edit, $checklist_id ): void {
					check(
						"{$label} manages UAT: " . var_export( $manage, true ),
						user_can( $user_id, Access\CAP_MANAGE ),
						$manage
					);
					check(
						"{$label} reads results: " . var_export( $read, true ),
						user_can( $user_id, Access\CAP_READ ),
						$read
					);
					check(
						"{$label} edits a checklist: " . var_export( $edit, true ),
						user_can( $user_id, 'edit_post', $checklist_id ),
						$edit
					);
				},
				hm_test_email_at( $domain )
			);
		}
	}
);

echo "\nOnly a staff user sets the grant, and only for a user with no staff email\n";

hm_test_as_user(
	'administrator',
	static function ( int $outsider_admin ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $target ) use ( $outsider_admin ): void {
				check(
					'an administrator with no staff email cannot set it',
					hm_test_post_grant( $outsider_admin, $target, true ),
					false
				);
			},
			hm_test_email_at( 'example.com' )
		);
	},
	hm_test_email_at( 'example.com' )
);

hm_test_as_user(
	'administrator',
	static function ( int $staff_id ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $target ) use ( $staff_id ): void {
				check( 'a staff user sets it', hm_test_post_grant( $staff_id, $target, true ), true );
				check( 'and clears it', hm_test_post_grant( $staff_id, $target, false ), false );
			},
			hm_test_email_at( 'example.com' )
		);

		hm_test_as_user(
			'subscriber',
			static function ( int $by_email ) use ( $staff_id ): void {
				check(
					'nothing is stored on a staff-email user',
					hm_test_post_grant( $staff_id, $by_email, true ),
					false
				);
			},
			hm_test_email_at( 'humanmade.com' )
		);

		hm_test_as_user(
			'administrator',
			static function ( int $granted ): void {
				update_user_meta( $granted, Access\GRANT_META, 1 );

				hm_test_as_user(
					'subscriber',
					static function ( int $target ) use ( $granted ): void {
						check(
							'a user granted by the checkbox cannot set it for someone else',
							hm_test_post_grant( $granted, $target, true ),
							false
						);
					},
					hm_test_email_at( 'example.com' )
				);
			},
			hm_test_email_at( 'example.com' )
		);
	},
	hm_test_email_at( 'humanmade.com' )
);

hm_test_as_user(
	'subscriber',
	static function ( int $staff_subscriber ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $target ) use ( $staff_subscriber ): void {
				check(
					'a staff user who cannot edit the profile cannot set it',
					hm_test_post_grant( $staff_subscriber, $target, true ),
					false
				);
			},
			hm_test_email_at( 'example.com' )
		);
	},
	hm_test_email_at( 'humanmade.com' )
);

echo "\nThe checkbox shows to staff only\n";

/**
 * Renders the profile field for a user, as the current user.
 *
 * @param int $viewer  User viewing the profile.
 * @param int $profile User whose profile it is.
 * @return string Markup.
 */
function hm_test_render_grant( int $viewer, int $profile ): string {
	wp_set_current_user( $viewer );
	ob_start();
	Access\render_profile_field( get_userdata( $profile ) );

	return (string) ob_get_clean();
}

hm_test_as_user(
	'administrator',
	static function ( int $outsider_admin ): void {
		check(
			'a viewer with no staff email gets no markup',
			hm_test_render_grant( $outsider_admin, $outsider_admin ),
			''
		);

		update_user_meta( $outsider_admin, Access\GRANT_META, 1 );
		check( 'nor does one granted by the checkbox', hm_test_render_grant( $outsider_admin, $outsider_admin ), '' );
	},
	hm_test_email_at( 'example.com' )
);

hm_test_as_user(
	'administrator',
	static function ( int $staff_id ): void {
		hm_test_as_user(
			'subscriber',
			static function ( int $target ) use ( $staff_id ): void {
				$html = hm_test_render_grant( $staff_id, $target );
				check(
					'a staff viewer sees the box for an outside user',
					str_contains( $html, 'Full access to UAT checklists' ),
					true
				);
				check( 'unticked and enabled', preg_match( '/<input[^>]*\b(checked|disabled)\b/', $html ), 0 );

				update_user_meta( $target, Access\GRANT_META, 1 );
				check(
					'ticked once granted',
					preg_match( '/<input[^>]*\bchecked\b/', hm_test_render_grant( $staff_id, $target ) ),
					1
				);
			},
			hm_test_email_at( 'example.com' )
		);

		hm_test_as_user(
			'subscriber',
			static function ( int $by_email ) use ( $staff_id ): void {
				$html = hm_test_render_grant( $staff_id, $by_email );
				check(
					'for a staff-email user it is ticked and disabled',
					preg_match( '/<input[^>]*\bchecked\b[^>]*\bdisabled\b/', $html ),
					1
				);
			},
			hm_test_email_at( 'humanmade.com' )
		);
	},
	hm_test_email_at( 'humanmade.com' )
);
