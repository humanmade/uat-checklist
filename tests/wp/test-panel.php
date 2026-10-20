<?php
/**
 * The optional hub and guide pages the admin bar links to.
 */

declare( strict_types=1 );

use HM\UAT_Checklist\Panel;

echo "\nThe hub page is optional\n";
// Slugs no real page uses, so the checks hold on a site with content in it.
$hub_slug   = 'hm-uat-test-hub';
$guide_slug = 'hm-uat-test-guide';
$use_hub    = static fn (): string => $hub_slug;
$use_guide  = static fn (): string => $guide_slug;

add_filter( 'hm_uat_checklist_hub_slug', $use_hub );
add_filter( 'hm_uat_checklist_guide_slug', $use_guide );

try {
	check( 'with no hub page, there is no hub link', Panel\hub_page_id(), 0 );
	check( 'with no guide page, there is no guide link', Panel\guide_page_id(), 0 );

	hm_test_with_page(
		[ 'post_name' => $guide_slug ],
		static function ( int $guide_id ): void {
			check( 'with no hub, a top-level guide is found', Panel\guide_page_id(), $guide_id );
		}
	);

	hm_test_with_page(
		[ 'post_name' => $hub_slug ],
		static function ( int $hub_id ) use ( $guide_slug ): void {
			check( 'a published hub page is found', Panel\hub_page_id(), $hub_id );

			hm_test_with_page(
				[
					'post_name'   => $guide_slug,
					'post_parent' => $hub_id,
				],
				static function ( int $guide_id ): void {
					check( 'a guide under the hub is found', Panel\guide_page_id(), $guide_id );
				}
			);
		}
	);

	hm_test_with_page(
		[
			'post_name'   => $hub_slug,
			'post_status' => 'draft',
		],
		static function (): void {
			check( 'a draft hub page is not linked', Panel\hub_page_id(), 0 );
		}
	);
} finally {
	remove_filter( 'hm_uat_checklist_hub_slug', $use_hub );
	remove_filter( 'hm_uat_checklist_guide_slug', $use_guide );
}

$off = hm_test_with_filter(
	'hm_uat_checklist_hub_slug',
	'__return_empty_string',
	static fn (): int => Panel\hub_page_id()
);
check( 'an empty hub slug turns the link off', $off, 0 );
