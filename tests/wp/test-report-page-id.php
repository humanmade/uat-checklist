<?php
/**
 * The page ID the report dialog sends: a post's ID when the screen is one, and 0 on an archive, where the queried
 * object is a term or a user.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Panel;

echo "\nThe report dialog's page ID\n";

/**
 * Returns the page ID the report dialog is given while a query is the main one.
 *
 * @param array<string, mixed> $args Query arguments, as the front end parses them from the address.
 * @return int Page ID.
 */
function hm_test_report_page_id( array $args ): int {
	$previous = $GLOBALS['wp_query'];

	$GLOBALS['wp_query'] = new WP_Query( $args );

	try {
		return Panel\report_data()['page']['id'];
	} finally {
		$GLOBALS['wp_query'] = $previous;
	}
}

$show_on_front = get_option( 'show_on_front' );
$posts_page    = get_option( 'page_for_posts' );
$page_id       = 0;
$posts_page_id = 0;
$post_id       = 0;
$term_id       = 0;
$author_id     = 0;

try {
	$page_id       = (int) wp_insert_post(
		[
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'hm-uat-test page',
		]
	);
	$posts_page_id = (int) wp_insert_post(
		[
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'hm-uat-test posts page',
		]
	);
	$author_id     = (int) wp_insert_user(
		[
			'user_login' => 'hm-uat-test-' . wp_generate_password( 8, false ),
			'user_pass'  => wp_generate_password(),
			'role'       => 'author',
		]
	);
	$term_id       = (int) wp_insert_term( 'hm-uat-test category ' . wp_generate_password( 6, false ), 'category' )['term_id'];
	$post_id       = (int) wp_insert_post(
		[
			'post_status'   => 'publish',
			'post_title'    => 'hm-uat-test post',
			'post_author'   => $author_id,
			'post_category' => [ $term_id ],
		]
	);

	update_option( 'show_on_front', 'posts' );
	update_option( 'page_for_posts', 0 );

	check( 'a page sends its own ID', hm_test_report_page_id( [ 'page_id' => $page_id ] ), $page_id );
	check( 'so does a post', hm_test_report_page_id( [ 'p' => $post_id ] ), $post_id );
	check( 'a category archive sends none, not the category\'s ID', hm_test_report_page_id( [ 'cat' => $term_id ] ), 0 );
	check( 'an author archive sends none, not the user\'s ID', hm_test_report_page_id( [ 'author' => $author_id ] ), 0 );
	check( 'the blog on the home page sends none', hm_test_report_page_id( [] ), 0 );

	update_option( 'show_on_front', 'page' );
	update_option( 'page_for_posts', $posts_page_id );

	check( 'the posts page sends the page set for it', hm_test_report_page_id( [ 'page_id' => $posts_page_id ] ), $posts_page_id );
	check( 'an ordinary page still sends its own ID beside it', hm_test_report_page_id( [ 'page_id' => $page_id ] ), $page_id );

	$screen_before = $GLOBALS['current_screen'] ?? null;

	set_current_screen( 'dashboard' );

	try {
		check( 'a wp-admin screen sends none', hm_test_report_page_id( [ 'page_id' => $page_id ] ), 0 );
	} finally {
		$GLOBALS['current_screen'] = $screen_before;

		if ( $screen_before === null ) {
			unset( $GLOBALS['current_screen'] );
		}
	}
} finally {
	update_option( 'show_on_front', $show_on_front );
	update_option( 'page_for_posts', $posts_page );

	foreach ( [ $post_id, $page_id, $posts_page_id ] as $id ) {
		if ( $id > 0 ) {
			wp_delete_post( $id, true );
		}
	}

	if ( $term_id > 0 ) {
		wp_delete_term( $term_id, 'category' );
	}

	if ( $author_id > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $author_id );
	}
}
