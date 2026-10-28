<?php
/**
 * Access: who manages UAT checklists, decided by email domain or a grant on the profile, and who may read results.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Access;

use WP_Error;
use WP_REST_Request;
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
	add_filter( 'wp_pre_insert_user_data', __NAMESPACE__ . '\\keep_stored_email', 10, 4 );
	add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\\refuse_staff_email', 10, 3 );
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
 * Returns whether an email address is at a staff domain. The match is exact: `humanmade.com.example` and
 * `nothumanmade.com` are not staff, and neither is a subdomain.
 *
 * @param string $email Email address.
 * @return bool Whether it is.
 */
function is_staff_address( string $email ): bool {
	$domain = substr( strrchr( '@' . $email, '@' ), 1 );

	return in_array( strtolower( $domain ), staff_domains(), true );
}

/**
 * Returns whether a user's email is at a staff domain.
 *
 * @param WP_User $user User.
 * @return bool Whether it is.
 */
function has_staff_email( WP_User $user ): bool {
	return is_staff_address( (string) $user->user_email );
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
 * Returns whether a request completes core's email confirmation for a user and this address.
 *
 * Core mails the link to the new address and stores its hash only as the user's `_new_email` meta, so holding the
 * hash proves the user controls the mailbox.
 *
 * @param int    $user_id   User whose email changes.
 * @param string $new_email The address being set.
 * @return bool Whether the hash in the request matches the pending change to this address.
 */
function is_confirmed_email_change( int $user_id, string $new_email ): bool {
	$pending = get_user_meta( $user_id, '_new_email', true );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the hash is the proof, as in wp-admin/user-edit.php.
	$hash = isset( $_GET['newuseremail'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['newuseremail'] ) ) : '';

	return $hash !== ''
		&& is_array( $pending )
		&& isset( $pending['hash'] )
		&& isset( $pending['newemail'] )
		&& hash_equals( (string) $pending['hash'], $hash )
		&& sanitize_email( esc_html( trim( (string) $pending['newemail'] ) ) ) === $new_email;
}

/**
 * Returns whether the acting user is moving their own unverified email to a staff address.
 *
 * Core saves an email set through `wp_update_user()` or REST without proving the mailbox, so a staff address set
 * that way would make any logged-in user a manager. A user who already manages UAT gains nothing from it, an
 * administrator may set one, and so may an address confirmed through core's emailed link.
 *
 * @param int    $user_id   User being updated.
 * @param string $new_email The address being set.
 * @return bool Whether the change must be refused.
 */
function is_unconfirmed_staff_email( int $user_id, string $new_email ): bool {
	$stored = get_userdata( $user_id );

	return $user_id === get_current_user_id()
		&& $stored !== false
		&& is_staff_address( $new_email )
		&& ! is_manager( $stored )
		&& ! current_user_can( 'edit_users' )
		&& ! is_confirmed_email_change( $user_id, $new_email );
}

/**
 * Puts a user's stored email back when they set a staff address on themselves without proving it.
 *
 * @param array<string, mixed> $data     Data about to be saved.
 * @param bool                 $update   Whether an existing user is updated.
 * @param int|null             $user_id  User ID on an update.
 * @param array<string, mixed> $userdata The data passed to the insert, before sanitising.
 * @return array<string, mixed> Data.
 */
function keep_stored_email( array $data, bool $update, ?int $user_id, array $userdata ): array {
	if ( $update && $user_id !== null && is_unconfirmed_staff_email( $user_id, (string) ( $data['user_email'] ?? '' ) ) ) {
		$stored = get_userdata( $user_id );

		if ( $stored !== false ) {
			$data['user_email'] = $stored->user_email;
		}
	}

	return $data;
}

/**
 * Refuses a REST user update that sets a staff address the user has not proved, so the caller is told rather than
 * silently reverted by keep_stored_email(). Hooked here and not on `rest_pre_insert_user` because the users
 * controller's update_item() never checks that filter's return value for an error.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Response so far.
 * @param array<string, mixed>                             $handler  Route handler.
 * @param WP_REST_Request                                  $request  Request.
 * @return WP_REST_Response|WP_HTTP_Response|WP_Error|mixed The response, or the refusal.
 */
function refuse_staff_email( $response, array $handler, WP_REST_Request $request ) {
	$email = $request->get_param( 'email' );

	if (
		in_array( $request->get_method(), [ 'POST', 'PUT', 'PATCH' ], true )
		&& is_string( $email )
		&& preg_match( '#^/wp/v2/users/(me|\d+)$#', $request->get_route(), $matches ) === 1
		&& is_unconfirmed_staff_email( $matches[1] === 'me' ? get_current_user_id() : (int) $matches[1], $email )
	) {
		return new WP_Error(
			'hm_uat_checklist_staff_email_unconfirmed',
			__( 'An email address at a staff domain can only be set by an administrator, or confirmed from the link mailed to it.', 'uat-checklist' ),
			[ 'status' => 403 ]
		);
	}

	return $response;
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
