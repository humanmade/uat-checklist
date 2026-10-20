<?php
/**
 * The checklist post type: who can read it over core's REST API, and the optional instructions page.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;

/**
 * Renders a checklist as a newly created Subscriber sees it.
 *
 * @param int $checklist_id Checklist.
 * @return string Markup.
 */
$render_as_tester = static function ( int $checklist_id ): string {
	return hm_test_as_user(
		'subscriber',
		static fn (): string => Blocks\render_checklist( get_post( $checklist_id ) )
	);
};

echo "\nChecklists are private to the site's members\n";

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'Private checklist',
		'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
	],
	static function ( int $checklist_id ): void {
		$route = '/wp/v2/' . Checklists\REST_BASE;

		wp_set_current_user( 0 );
		check(
			'a logged-out visitor cannot list them over core\'s REST API',
			hm_test_rest( 'GET', $route )->get_status(),
			401
		);
		check( 'nor read one', hm_test_rest( 'GET', "{$route}/{$checklist_id}" )->get_status(), 401 );
		check( 'the post type has no front-end page', is_post_type_viewable( Checklists\POST_TYPE ), false );

		remove_filter( 'rest_request_before_callbacks', 'HM\\UAT_Checklist\\Checklists\\restrict_rest', 10 );

		try {
			check(
				'with the filter removed, core would serve it to a logged-out visitor',
				hm_test_rest( 'GET', "{$route}/{$checklist_id}" )->get_status(),
				200
			);
		} finally {
			add_filter( 'rest_request_before_callbacks', 'HM\\UAT_Checklist\\Checklists\\restrict_rest', 10, 3 );
		}

		hm_test_as_user(
			'subscriber',
			static function () use ( $route, $checklist_id ): void {
				check(
					'a member of the site can read one',
					hm_test_rest( 'GET', "{$route}/{$checklist_id}" )->get_status(),
					200
				);

				$response = hm_test_rest(
					'POST',
					"{$route}/{$checklist_id}",
					[ 'meta' => [ Checklists\INSTRUCTIONS_META => 1 ] ]
				);
				check( 'a tester cannot set its instructions page', $response->get_status(), 403 );
			}
		);

		hm_test_as_user(
			'editor',
			static function () use ( $route, $checklist_id ): void {
				check( 'a UAT manager can edit one', current_user_can( 'edit_post', $checklist_id ), true );

				$response = hm_test_rest(
					'POST',
					"{$route}/{$checklist_id}",
					[ 'meta' => [ Checklists\INSTRUCTIONS_META => 42 ] ]
				);
				check(
					'and set its instructions page, as the editor sidebar does',
					[
						$response->get_status(),
						(int) get_post_meta( $checklist_id, Checklists\INSTRUCTIONS_META, true ),
					],
					[ 200, 42 ]
				);
			},
			'tester@humanmade.com'
		);
	}
);

echo "\nThe instructions page is optional\n";

hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'No instructions',
		'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
	],
	static function ( int $checklist_id ) use ( $render_as_tester ): void {
		check( 'with none set, there is no instructions link', Checklists\instructions_url( $checklist_id ), '' );
		check(
			'the pop-out opens on the home page',
			str_starts_with( Checklists\window_url( $checklist_id ), home_url( '/' ) . '?' ),
			true
		);
		check(
			'and names the checklist',
			str_contains( Checklists\window_url( $checklist_id ), Blocks\CHECKLIST_PARAM . '=' . $checklist_id ),
			true
		);

		$html = $render_as_tester( $checklist_id );
		check( 'the rendered checklist has no instructions link', str_contains( $html, 'uat-checklist__page' ), false );
		check( 'it keeps its pop-out link', str_contains( $html, 'uat-checklist__window' ), true );

		hm_test_with_page(
			[ 'post_title' => 'Buttons: how to use the block' ],
			static function ( int $page_id ) use ( $checklist_id, $render_as_tester ): void {
				update_post_meta( $checklist_id, Checklists\INSTRUCTIONS_META, $page_id );

				check(
					'with a page set, the link goes to it',
					Checklists\instructions_url( $checklist_id ),
					get_permalink( $page_id )
				);
				check( 'the page knows its checklist', Checklists\checklist_for_page( $page_id ), $checklist_id );
				check(
					'the pop-out opens on that page',
					str_starts_with( Checklists\window_url( $checklist_id ), (string) get_permalink( $page_id ) ),
					true
				);
				check(
					'the rendered checklist links to it',
					str_contains( $render_as_tester( $checklist_id ), 'uat-checklist__page' ),
					true
				);

				wp_update_post(
					[
						'ID'          => $page_id,
						'post_status' => 'draft',
					]
				);
				check(
					'a page taken out of publication is not linked',
					Checklists\instructions_url( $checklist_id ),
					''
				);
			}
		);
	}
);

$logged_out = hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
	],
	static function ( int $checklist_id ): string {
		wp_set_current_user( 0 );
		return Blocks\render_checklist( get_post( $checklist_id ) );
	}
);
check( 'a checklist renders nothing for a logged-out visitor', $logged_out, '' );

echo "\nThe paragraphs above the first section are the checklist's intro\n";

$intro_step = wp_generate_uuid4();
$intro_html = hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => 'With an intro',
		'post_content' => '<!-- wp:uat-checklist/checklist -->'
			. '<!-- wp:paragraph --><p>Tickets: ABC-12</p><!-- /wp:paragraph -->'
			. '<!-- wp:paragraph --><p></p><!-- /wp:paragraph -->'
			. '<!-- wp:uat-checklist/section {"title":"Editor"} -->'
			. '<!-- wp:uat-checklist/step {"stepId":"' . $intro_step
			. '","step":"Add the block","expected":"It appears"} /-->'
			. '<!-- /wp:uat-checklist/section -->'
			. '<!-- wp:paragraph --><p>Then check the front end.</p><!-- /wp:paragraph -->'
			. '<!-- /wp:uat-checklist/checklist -->',
	],
	$render_as_tester
);

$description = strpos( $intro_html, 'uat-checklist__description' );
$sections    = strpos( $intro_html, 'uat-checklist__sections' );
check(
	'the intro renders under the header, above the sections',
	$description !== false && $description < $sections,
	true
);
check(
	'it holds the leading paragraph',
	str_contains( substr( $intro_html, (int) $description, $sections - (int) $description ), 'Tickets: ABC-12' ),
	true
);
check( 'an empty paragraph renders nothing', str_contains( $intro_html, '<p></p>' ), false );
check(
	'a paragraph after a section stays among the sections',
	strpos( $intro_html, 'Then check the front end.' ) > $sections,
	true
);
check(
	'the step still renders once',
	substr_count( $intro_html, 'data-step-id="' . $intro_step . '"' ),
	1
);

$no_intro = hm_test_with_page(
	[
		'post_type'    => Checklists\POST_TYPE,
		'post_content' => hm_test_checklist_markup( wp_generate_uuid4() ),
	],
	$render_as_tester
);
check(
	'a checklist with no intro has no intro wrapper',
	str_contains( $no_intro, 'uat-checklist__description' ),
	false
);
