<?php
/**
 * The UAT results list in wp-admin: columns, a checklist filter, and no edit screen.
 *
 * Results are read and trashed from the list only. The classic edit screen
 * offers neither the Pass and Fail statuses nor the comment, so saving from it
 * would move a result to `publish` and drop it out of the list. The one change a
 * developer makes is Mark fixed, which adds a result of its own.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Admin;

use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Notifications;
use HM\UAT_Checklist\Overview;
use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\REST;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Screenshots;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Query variable the results list is filtered by checklist with.
 */
const CHECKLIST_FILTER = 'uat_checklist_id';

/**
 * Query variable that limits the results list to the latest result on each step, the one the panel and the
 * overview count.
 */
const LATEST_FILTER = 'uat_latest';

/**
 * Action the Mark fixed form posts, and the prefix of its nonce: one nonce per result.
 */
const MARK_FIXED_ACTION = 'uat_mark_fixed';

/**
 * Slug of the page that confirms Mark fixed. It has no menu item: the Results list links to it.
 */
const MARK_FIXED_PAGE = 'uat-mark-fixed';

/**
 * Query variable that makes the Results list say a step was marked fixed.
 */
const FIXED_NOTICE = 'uat_fixed';

/**
 * Mark fixed form field that, when ticked, saves the note without emailing it to the tester.
 */
const NO_EMAIL_FIELD = 'no_email';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_filter( 'map_meta_cap', __NAMESPACE__ . '\\deny_editing', 10, 4 );
	add_filter( 'manage_' . Results\POST_TYPE . '_posts_columns', __NAMESPACE__ . '\\columns' );
	add_action( 'manage_' . Results\POST_TYPE . '_posts_custom_column', __NAMESPACE__ . '\\render_column', 10, 2 );
	add_filter( 'bulk_actions-edit-' . Results\POST_TYPE, __NAMESPACE__ . '\\bulk_actions' );
	add_action( 'restrict_manage_posts', __NAMESPACE__ . '\\render_checklist_filter' );
	add_action( 'pre_get_posts', __NAMESPACE__ . '\\filter_by_checklist' );
	add_action( 'load-edit.php', __NAMESPACE__ . '\\label_notes' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\style_results_screens' );
	add_filter( 'post_row_actions', __NAMESPACE__ . '\\row_actions', 10, 2 );
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_mark_fixed_page' );
	add_action( 'admin_post_' . MARK_FIXED_ACTION, __NAMESPACE__ . '\\handle_mark_fixed' );
	add_action( 'admin_notices', __NAMESPACE__ . '\\render_fixed_notice' );
}

/**
 * On the results list and the Mark fixed page only, sets the latest-only checkbox in line with the filter bar's
 * controls, and fits a tester's screenshot under their comment.
 *
 * Core floats those controls and sizes them, and leaves a label beside them unaligned and touching the Filter
 * button. A screenshot's figure would otherwise take the browser's default margins.
 *
 * @return void
 */
function style_results_screens(): void {
	$screens = [ 'edit-' . Results\POST_TYPE, get_plugin_page_hookname( MARK_FIXED_PAGE, '' ) ];

	if ( ! in_array( get_current_screen()?->id, $screens, true ) ) {
		return;
	}

	$css = <<<'CSS'
		.uat-latest-filter {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			min-height: 32px;
			margin-inline-end: 8px;
			vertical-align: top;
		}

		.uat-latest-filter input {
			margin: 0;
		}

		.uat-checklist__screenshot {
			margin: 0;
		}

		.uat-checklist__screenshot img {
			display: block;
			box-sizing: border-box;
			max-width: 100%;
			height: auto;
			border: 1px solid color-mix( in srgb, currentColor 20%, transparent );
		}

		.column-uat_screenshot {
			width: 144px;
		}

		/* Set apart from the rows above as the tester's own words, with the red edge core gives an error notice. */
		.uat-fixed-comment {
			box-sizing: border-box;
			max-width: 65ch;
			margin: 0;
			padding: 12px 16px;
			border: 1px solid #c3c4c7;
			border-inline-start: 4px solid #d63638;
			background-color: #fff;
		}

		.uat-fixed-comment p {
			margin: 0 0 1em;
			font-size: 14px;
			line-height: 1.6;
		}

		.uat-fixed-comment p:last-child {
			margin-block-end: 0;
		}

		.uat-fixed-screenshot img {
			max-width: min( 100%, 640px );
		}

		.uat-fixed-screenshot p {
			margin: 8px 0 0;
		}
		CSS;

	wp_add_inline_style( 'list-tables', $css );
}

/**
 * Returns a result's status, or for a trashed one the status it had before, which WordPress keeps in meta.
 *
 * @param WP_Post $result Result.
 * @return string Post status.
 */
function status_of( WP_Post $result ): string {
	return $result->post_status === 'trash'
		? (string) get_post_meta( $result->ID, '_wp_trash_meta_status', true )
		: $result->post_status;
}

/**
 * On the results list only, titles additional comments, which have no step, as "Additional comments".
 *
 * @return void
 */
function label_notes(): void {
	if ( get_current_screen()?->post_type === Results\POST_TYPE ) {
		add_filter( 'the_title', __NAMESPACE__ . '\\note_title', 10, 2 );
	}
}

/**
 * Returns "Additional comments" as the title of a tester's additional comments, and any other title unchanged.
 *
 * @param string $title   Title.
 * @param mixed  $post_id Post ID; some callers pass none.
 * @return string Title.
 */
function note_title( string $title, mixed $post_id = 0 ): string {
	$post = get_post( (int) $post_id );

	if (
		! $post instanceof WP_Post
		|| $post->post_type !== Results\POST_TYPE
		|| status_of( $post ) !== Results\NOTE_STATUS
	) {
		return $title;
	}

	return __( 'Additional comments', 'uat-checklist' );
}

/**
 * Denies `edit_post` on results for every user, which removes the edit link, Quick Edit and the edit screen.
 *
 * @param string[] $caps    Primitive capabilities required.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id User ID.
 * @param mixed[]  $args    Capability arguments; the first is the post ID.
 * @return string[] Primitive capabilities required.
 */
function deny_editing( array $caps, string $cap, int $user_id, array $args ): array {
	if ( $cap !== 'edit_post' || empty( $args[0] ) ) {
		return $caps;
	}

	return get_post_type( (int) $args[0] ) === Results\POST_TYPE ? [ 'do_not_allow' ] : $caps;
}

/**
 * Returns the list table columns.
 *
 * @param array<string, string> $columns Default columns.
 * @return array<string, string> Columns.
 */
function columns( array $columns ): array {
	return [
		'cb'             => $columns['cb'] ?? '',
		'title'          => __( 'Step', 'uat-checklist' ),
		'uat_result'     => __( 'Result', 'uat-checklist' ),
		'uat_comment'    => __( 'Comment', 'uat-checklist' ),
		'uat_screenshot' => __( 'Screenshot', 'uat-checklist' ),
		'uat_page'       => __( 'Checklist', 'uat-checklist' ),
		'author'         => __( 'By', 'uat-checklist' ),
		'uat_saved'      => __( 'Last saved', 'uat-checklist' ),
	];
}

/**
 * Prints one custom column cell.
 *
 * @param string $column  Column key.
 * @param int    $post_id Result ID.
 * @return void
 */
function render_column( string $column, int $post_id ): void {
	$result = get_post( $post_id );

	if ( ! $result instanceof WP_Post ) {
		return;
	}

	switch ( $column ) {
		case 'uat_result':
			echo '<strong>'
				. esc_html( Results\label( Results\result_for_status( status_of( $result ) ) ) )
				. '</strong>';
			break;

		case 'uat_comment':
			echo nl2br( esc_html( $result->post_content ) );
			break;

		case 'uat_screenshot':
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Screenshots\markup() from esc_url(), esc_html__() and wp_get_attachment_image().
			echo Screenshots\markup( $result, 'medium' );
			break;

		case 'uat_page':
			render_checklist_cell( $result );
			break;

		case 'uat_saved':
			echo esc_html( Results\saved_at( $result ) );
			break;
	}
}

/**
 * Prints the checklist a result belongs to, linked to this list filtered by it, to its editor, and to its
 * instructions page when it has one.
 *
 * @param WP_Post $result Result.
 * @return void
 */
function render_checklist_cell( WP_Post $result ): void {
	$checklist = get_post( $result->post_parent );

	if ( ! $checklist instanceof WP_Post || $checklist->post_type !== Checklists\POST_TYPE ) {
		esc_html_e( '(checklist deleted)', 'uat-checklist' );
		return;
	}

	printf(
		'<a href="%1$s">%2$s</a>',
		esc_url( add_query_arg( CHECKLIST_FILTER, $checklist->ID ) ),
		esc_html( get_the_title( $checklist ) )
	);

	$links = checklist_links( $checklist->ID );

	if ( $links !== [] ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link built by checklist_links() from esc_url() and esc_html__().
		echo '<br>' . implode( ' | ', $links );
	}
}

/**
 * Returns a checklist's Edit checklist link, for a user who can edit it, and its View instructions link, when it
 * has an instructions page.
 *
 * @param int $checklist_id Checklist.
 * @return string[] Escaped links, possibly none.
 */
function checklist_links( int $checklist_id ): array {
	$links = [];

	if ( current_user_can( 'edit_post', $checklist_id ) ) {
		$links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( (string) get_edit_post_link( $checklist_id ) ),
			esc_html__( 'Edit checklist', 'uat-checklist' )
		);
	}

	$instructions = Checklists\instructions_url( $checklist_id );

	if ( $instructions !== '' ) {
		$links[] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $instructions ),
			esc_html__( 'View instructions', 'uat-checklist' )
		);
	}

	return $links;
}

/**
 * Removes the bulk Edit action, leaving Move to Trash.
 *
 * @param array<string, string> $actions Bulk actions.
 * @return array<string, string> Bulk actions.
 */
function bulk_actions( array $actions ): array {
	unset( $actions['edit'] );

	return $actions;
}

/**
 * Prints the checklist dropdown above the results list.
 *
 * @param string $post_type Post type of the list being shown.
 * @return void
 */
function render_checklist_filter( string $post_type ): void {
	if ( $post_type !== Results\POST_TYPE ) {
		return;
	}

	// Built here: wp_dropdown_pages() reads hierarchical post types only.
	$query = new WP_Query(
		[
			'post_type'              => Checklists\POST_TYPE,
			'post_status'            => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'posts_per_page'         => REST\MAX_CHECKLISTS,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]
	);

	$selected = requested_checklist();
	$options  = sprintf( '<option value="0">%s</option>', esc_html__( 'All checklists', 'uat-checklist' ) );

	foreach ( $query->posts as $checklist ) {
		if ( $checklist instanceof WP_Post ) {
			$options .= sprintf(
				'<option value="%1$d"%2$s>%3$s</option>',
				$checklist->ID,
				selected( $selected, $checklist->ID, false ),
				esc_html( get_the_title( $checklist ) )
			);
		}
	}

	printf(
		'<label class="screen-reader-text" for="%1$s">%2$s</label><select name="%1$s" id="%1$s">%3$s</select>',
		esc_attr( CHECKLIST_FILTER ),
		esc_html__( 'Filter by checklist', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html(), esc_html__(), selected() and integer IDs.
		$options
	);

	// Inside the filter form, so it is kept each time the list is filtered.
	printf(
		'<label class="uat-latest-filter"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
		esc_attr( LATEST_FILTER ),
		checked( requested_latest(), true, false ),
		esc_html__( 'Latest result on each step only', 'uat-checklist' )
	);
}

/**
 * Limits the admin results list to the checklist chosen in the dropdown.
 *
 * @param WP_Query $query Query being prepared.
 * @return void
 */
function filter_by_checklist( WP_Query $query ): void {
	if (
		! is_admin()
		|| ! $query->is_main_query()
		|| $query->get( 'post_type' ) !== Results\POST_TYPE
	) {
		return;
	}

	$checklist_id = requested_checklist();

	if ( $checklist_id > 0 ) {
		$query->set( 'post_parent', $checklist_id );
	}

	if ( requested_latest() ) {
		limit_to_latest( $query, $checklist_id );
	}
}

/**
 * Limits a results query to the latest result on each step: one checklist's steps, or every published checklist's.
 *
 * @param WP_Query $query        Query being prepared.
 * @param int      $checklist_id Checklist, or 0 for every published checklist.
 * @return void
 */
function limit_to_latest( WP_Query $query, int $checklist_id ): void {
	// get_post( 0 ) returns the global post, so 0 must not reach it.
	$checklist  = $checklist_id > 0 ? get_post( $checklist_id ) : null;
	$checklists = $checklist instanceof WP_Post && $checklist->post_type === Checklists\POST_TYPE
		? [ $checklist ]
		: Progress\published_checklists();
	$ids        = [];

	foreach ( $checklists as $one ) {
		foreach ( Progress\latest_results( $one->ID, Progress\step_ids( $one ) ) as $result ) {
			$ids[] = $result->ID;
		}
	}

	// An empty post__in means no limit to WP_Query, so no results is asked for as a post that cannot exist.
	$query->set( 'post__in', $ids === [] ? [ 0 ] : $ids );
}

/**
 * Returns whether the list is limited to the latest result on each step.
 *
 * @return bool Whether it is.
 */
function requested_latest(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter in a GET query string; it changes nothing.
	return isset( $_GET[ LATEST_FILTER ] ) && absint( wp_unslash( $_GET[ LATEST_FILTER ] ) ) === 1;
}

/**
 * Returns the checklist ID the list is filtered by, or 0 for every checklist.
 *
 * @return int Checklist ID.
 */
function requested_checklist(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only list filter in a GET query string; it changes nothing.
	return isset( $_GET[ CHECKLIST_FILTER ] ) ? absint( wp_unslash( $_GET[ CHECKLIST_FILTER ] ) ) : 0;
}

/**
 * Returns whether a result is a Fail that is still its step's latest result, from any tester.
 *
 * One Fixed covers every tester's Fail on the step, so a Fail that something newer has replaced has nothing left
 * to mark.
 *
 * @param WP_Post $result Result.
 * @return bool Whether it is.
 */
function is_open_fail( WP_Post $result ): bool {
	if (
		$result->post_type !== Results\POST_TYPE
		|| $result->post_status !== Results\STATUSES['fail']
	) {
		return false;
	}

	$latest = Results\get_checklist_results( $result->post_parent )[ Results\step_id_of( $result ) ] ?? null;

	return $latest instanceof WP_Post && $latest->post_status === Results\STATUSES['fail'];
}

/**
 * Returns the address of the page that marks a result's step fixed.
 *
 * @param int $result_id Result.
 * @return string URL.
 */
function mark_fixed_url( int $result_id ): string {
	return add_query_arg(
		[
			'page'   => MARK_FIXED_PAGE,
			'result' => $result_id,
		],
		admin_url( 'admin.php' )
	);
}

/**
 * Adds Mark fixed to a Fail that is its step's latest result, for a user who can mark results fixed.
 *
 * @param array<string, string> $actions Row actions.
 * @param WP_Post               $post    Post the row is for.
 * @return array<string, string> Row actions.
 */
function row_actions( array $actions, WP_Post $post ): array {
	if ( current_user_can( Access\CAP_MANAGE ) && is_open_fail( $post ) ) {
		$actions['uat_mark_fixed'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( mark_fixed_url( $post->ID ) ),
			esc_html__( 'Mark fixed', 'uat-checklist' )
		);
	}

	return $actions;
}

/**
 * Registers the Mark fixed page with no menu parent, so it is reachable by its address and no menu shows it.
 *
 * @return void
 */
function add_mark_fixed_page(): void {
	add_submenu_page(
		'',
		__( 'Mark fixed', 'uat-checklist' ),
		'',
		Access\CAP_MANAGE,
		MARK_FIXED_PAGE,
		__NAMESPACE__ . '\\render_mark_fixed_page'
	);
}

/**
 * Returns the error for a result that cannot be marked fixed.
 *
 * @return WP_Error Error.
 */
function not_fixable(): WP_Error {
	return new WP_Error(
		'uat_checklist_not_fixable',
		__( 'Only a Fail that is still the latest result on its step can be marked fixed.', 'uat-checklist' )
	);
}

/**
 * Prints the page that shows the Fail and asks for a note before marking its step fixed.
 *
 * @return void
 */
function render_mark_fixed_page(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shows a form; the post it makes is nonce-checked.
	$result = get_post( isset( $_GET['result'] ) ? absint( wp_unslash( $_GET['result'] ) ) : 0 );

	if ( ! $result instanceof WP_Post || ! is_open_fail( $result ) ) {
		wp_die( esc_html( not_fixable()->get_error_message() ), '', [ 'back_link' => true ] );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mark fixed', 'uat-checklist' ); ?></h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( MARK_FIXED_ACTION ); ?>">
			<input type="hidden" name="result_id" value="<?php echo esc_attr( (string) $result->ID ); ?>">
			<?php wp_nonce_field( MARK_FIXED_ACTION . '_' . $result->ID ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Step', 'uat-checklist' ); ?></th>
					<td><?php echo esc_html( $result->post_title ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Failed by', 'uat-checklist' ); ?></th>
					<td><?php echo esc_html( get_the_author_meta( 'display_name', (int) $result->post_author ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Failed on', 'uat-checklist' ); ?></th>
					<td><?php echo esc_html( Results\saved_at( $result ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Their comment', 'uat-checklist' ); ?></th>
					<td>
						<blockquote class="uat-fixed-comment">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by esc_html() before wpautop() adds the paragraphs.
							echo wpautop( esc_html( $result->post_content ) );
							?>
						</blockquote>
					</td>
				</tr>
				<?php $screenshot = Screenshots\of( $result ); ?>
				<?php if ( $screenshot !== null ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Their screenshot', 'uat-checklist' ); ?></th>
						<td class="uat-fixed-screenshot">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by Screenshots\markup() from esc_url(), esc_html__() and wp_get_attachment_image().
							echo Screenshots\markup( $result, 'large' );
							?>
							<p>
								<a href="<?php echo esc_url( (string) wp_get_attachment_url( $screenshot->ID ) ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( 'Open full size', 'uat-checklist' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'uat-checklist' ); ?></span>
								</a>
							</p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><label for="uat-fixed-note"><?php esc_html_e( 'Note for testers', 'uat-checklist' ); ?></label></th>
					<td>
						<textarea
							name="note"
							id="uat-fixed-note"
							class="large-text"
							rows="4"
							maxlength="<?php echo esc_attr( (string) Results\COMMENT_MAX_LENGTH ); ?>"
						></textarea>
						<p class="description"><?php esc_html_e( 'Optional. Testers see it when they retest the step, and the tester who failed it is emailed the note.', 'uat-checklist' ); ?></p>
						<p>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( NO_EMAIL_FIELD ); ?>" value="1">
								<?php esc_html_e( "Don't send email notification", 'uat-checklist' ); ?>
							</label>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Mark fixed', 'uat-checklist' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Marks a failed step fixed: saves a Fixed result and leaves the Fail as it was.
 *
 * This is the decision; the admin-post handler only checks the nonce and reports the outcome. When the note is not
 * empty it also schedules an email to the tester who saved the Fail, so the admin form and the ability both notify,
 * unless `$notify` is false.
 *
 * @param int    $result_id ID of a Fail on the step.
 * @param int    $user_id   User marking it, who must be able to manage UAT checklists.
 * @param string $note      Note for testers, already sanitised.
 * @param bool   $notify    Whether to email the tester the note. The note shows in the panel either way.
 * @return WP_Post|WP_Error The Fixed result, or why it was refused.
 */
function mark_fixed( int $result_id, int $user_id, string $note, bool $notify = true ): WP_Post|WP_Error {
	if ( ! user_can( $user_id, Access\CAP_MANAGE ) ) {
		return new WP_Error(
			'uat_checklist_forbidden',
			__( 'You cannot mark results fixed.', 'uat-checklist' )
		);
	}

	$result = get_post( $result_id );

	if ( ! $result instanceof WP_Post || ! is_open_fail( $result ) ) {
		return not_fixable();
	}

	if ( mb_strlen( $note ) > Results\COMMENT_MAX_LENGTH ) {
		return new WP_Error(
			'uat_checklist_note_too_long',
			sprintf(
				/* translators: %s: the most characters a note can hold. */
				__( 'The note can be at most %s characters.', 'uat-checklist' ),
				number_format_i18n( Results\COMMENT_MAX_LENGTH )
			)
		);
	}

	$checklist = get_post( $result->post_parent );
	$step_id   = Results\step_id_of( $result );
	$step      = $checklist instanceof WP_Post ? Blocks\find_step( $checklist, $step_id ) : null;

	if ( ! $checklist instanceof WP_Post || $step === null ) {
		return new WP_Error(
			'uat_checklist_no_step',
			__( 'This step is no longer in the checklist.', 'uat-checklist' )
		);
	}

	$fixed = Results\save_fixed(
		$checklist->ID,
		$user_id,
		$step_id,
		Results\step_text( $step ),
		$note
	);

	if ( $notify && ! is_wp_error( $fixed ) ) {
		Notifications\schedule_fixed_notice( $fixed, $result );
	}

	return $fixed;
}

/**
 * Handles the Mark fixed form: checks its nonce, marks the step fixed and returns to the checklist's results.
 *
 * @return void
 */
function handle_mark_fixed(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the ID is what the nonce below is made from.
	$result_id = isset( $_POST['result_id'] ) ? absint( wp_unslash( $_POST['result_id'] ) ) : 0;

	check_admin_referer( MARK_FIXED_ACTION . '_' . $result_id );

	$fixed = mark_fixed(
		$result_id,
		get_current_user_id(),
		isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '',
		! isset( $_POST[ NO_EMAIL_FIELD ] )
	);

	if ( is_wp_error( $fixed ) ) {
		wp_die( esc_html( $fixed->get_error_message() ), '', [ 'back_link' => true ] );
	}

	wp_safe_redirect( add_query_arg( FIXED_NOTICE, 1, Overview\results_url( $fixed->post_parent ) ) );
	exit;
}

/**
 * On the results list only, says a step was marked fixed after the redirect from Mark fixed.
 *
 * @return void
 */
function render_fixed_notice(): void {
	if (
		get_current_screen()?->id !== 'edit-' . Results\POST_TYPE
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only flag in a GET query string; it changes nothing.
		|| ! isset( $_GET[ FIXED_NOTICE ] )
	) {
		return;
	}

	wp_admin_notice(
		esc_html__( 'Marked fixed. Testers now see the step as Retest.', 'uat-checklist' ),
		[ 'type' => 'success' ]
	);
}
