<?php
/**
 * Access: who manages UAT checklists, decided by email domain or a grant on the profile, and who may read results.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Access;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Held by a UAT manager: writes checklists, marks results fixed and trashes them.
 */
const CAP_MANAGE = 'manage_uat_checklists';

/**
 * Held by managers and by every role that can edit posts: reads the Overview and Results.
 */
const CAP_READ = 'read_uat_results';

/**
 * User meta set by the profile checkbox, giving a user outside the staff domains full access.
 */
const GRANT_META = 'hm_uat_checklist_full_access';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_filter( 'user_has_cap', __NAMESPACE__ . '\\grant_capabilities', 10, 4 );
	add_action( 'show_user_profile', __NAMESPACE__ . '\\render_profile_field' );
	add_action( 'edit_user_profile', __NAMESPACE__ . '\\render_profile_field' );
	add_action( 'personal_options_update', __NAMESPACE__ . '\\save_profile_field' );
	add_action( 'edit_user_profile_update', __NAMESPACE__ . '\\save_profile_field' );
}

/**
 * Returns the email domains whose users are UAT managers.
 *
 * @return string[] Lowercase domains.
 */
function staff_domains(): array {
	/**
	 * Filters the email domains whose users are UAT managers.
	 *
	 * @param string[] $domains Domains, without the @.
	 */
	$domains = apply_filters( 'hm_uat_checklist_staff_domains', [ 'humanmade.com' ] );

	return array_map( 'strtolower', array_map( 'trim', $domains ) );
}

/**
 * Returns whether a user's email is at a staff domain. The match is exact: `humanmade.com.example` and
 * `nothumanmade.com` are not staff, and neither is a subdomain.
 *
 * @param WP_User $user User.
 * @return bool Whether it is.
 */
function has_staff_email( WP_User $user ): bool {
	$domain = substr( strrchr( '@' . $user->user_email, '@' ), 1 );

	return in_array( strtolower( $domain ), staff_domains(), true );
}

/**
 * Returns whether a user is a UAT manager: a staff email, or the grant on their profile.
 *
 * @param WP_User $user User.
 * @return bool Whether they are.
 */
function is_manager( WP_User $user ): bool {
	return has_staff_email( $user ) || (bool) get_user_meta( $user->ID, GRANT_META, true );
}

/**
 * Gives managers both capabilities and everyone who can edit posts the reading one. Reads $allcaps and never calls
 * current_user_can(), which would run this filter again.
 *
 * @param array<string, bool> $allcaps All the user's capabilities.
 * @param string[]            $caps    Capabilities being checked.
 * @param mixed[]             $args    Arguments of the check.
 * @param WP_User             $user    User.
 * @return array<string, bool> Capabilities.
 */
function grant_capabilities( array $allcaps, array $caps, array $args, WP_User $user ): array {
	if ( is_manager( $user ) ) {
		$allcaps[ CAP_MANAGE ] = true;
		$allcaps[ CAP_READ ]   = true;
	} elseif ( ! empty( $allcaps['edit_posts'] ) ) {
		$allcaps[ CAP_READ ] = true;
	}

	return $allcaps;
}

/**
 * Prints the full access checkbox on a profile. Only a user at a staff domain sees it, so access granted here cannot
 * be passed on.
 *
 * @param WP_User $profile_user User whose profile it is.
 * @return void
 */
function render_profile_field( WP_User $profile_user ): void {
	if ( ! has_staff_email( wp_get_current_user() ) ) {
		return;
	}

	$by_email = has_staff_email( $profile_user );
	?>
	<h2><?php esc_html_e( 'UAT checklists', 'uat-checklist' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Access', 'uat-checklist' ); ?></th>
			<td>
				<label for="<?php echo esc_attr( GRANT_META ); ?>">
					<input
						type="checkbox"
						name="<?php echo esc_attr( GRANT_META ); ?>"
						id="<?php echo esc_attr( GRANT_META ); ?>"
						value="1"
						<?php checked( $by_email || is_manager( $profile_user ) ); ?>
						<?php disabled( $by_email ); ?>
					>
					<?php esc_html_e( 'Full access to UAT checklists', 'uat-checklist' ); ?>
				</label>
				<p class="description">
					<?php
					echo esc_html(
						$by_email
							? __( 'Their email address gives them full access.', 'uat-checklist' )
							: __( 'Lets them write checklists, mark results fixed and trash them.', 'uat-checklist' )
					);
					?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Saves the checkbox for a user who has no staff email. Core has already checked the `update-user_` nonce in
 * wp-admin/user-edit.php before firing both actions.
 *
 * @param int $user_id User whose profile is saved.
 * @return void
 */
function save_profile_field( int $user_id ): void {
	$target = get_userdata( $user_id );

	if (
		! has_staff_email( wp_get_current_user() )
		|| ! current_user_can( 'edit_user', $user_id )
		|| $target === false
		|| has_staff_email( $target )
	) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verified the nonce before firing this action.
	if ( ! empty( $_POST[ GRANT_META ] ) ) {
		update_user_meta( $user_id, GRANT_META, 1 );
		return;
	}

	delete_user_meta( $user_id, GRANT_META );
}
