<?php
/**
 * UAT → Overview: the counts Progress gives, across the site and per checklist, for the people who read results.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Overview;

use HM\UAT_Checklist;
use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Admin;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Panel;
use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\Results;
use HM\UAT_Checklist\Styles;

defined( 'ABSPATH' ) || exit;

const HANDLE = 'uat-checklist-overview';

const PAGE_SLUG = 'uat-overview';

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_page' );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue' );
}

/**
 * Adds UAT as a top-level menu: Overview, the Checklists and Results the post types put under it, and Add checklist.
 *
 * Both post types name PAGE_SLUG as their menu, and core adds their items before this runs, so Overview takes the
 * first place by position and Add checklist the third, after Checklists.
 *
 * @return void
 */
function add_page(): void {
	add_menu_page(
		__( 'Testing overview', 'uat-checklist' ),
		__( 'UAT', 'uat-checklist' ),
		Access\CAP_READ,
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page',
		'dashicons-yes-alt',
		// Just below Pages, which sits at 20.
		21
	);

	add_submenu_page(
		PAGE_SLUG,
		__( 'Testing overview', 'uat-checklist' ),
		__( 'Overview', 'uat-checklist' ),
		Access\CAP_READ,
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page',
		0
	);

	add_submenu_page(
		PAGE_SLUG,
		__( 'Add checklist', 'uat-checklist' ),
		__( 'Add checklist', 'uat-checklist' ),
		Access\CAP_MANAGE,
		'post-new.php?post_type=' . Checklists\POST_TYPE,
		'',
		2
	);
}

/**
 * Enqueues the page's stylesheet, on the page only.
 *
 * @param string $hook_suffix Current admin screen's hook suffix.
 * @return void
 */
function enqueue( string $hook_suffix ): void {
	if (
		$hook_suffix !== 'toplevel_page_' . PAGE_SLUG
		|| ! file_exists( UAT_Checklist\PATH . 'build/overview.asset.php' )
	) {
		return;
	}

	// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingCustomConstant -- this plugin's own build manifest, existence checked above.
	$asset = require UAT_Checklist\PATH . 'build/overview.asset.php';

	// The panel's stylesheet carries the theme's preset variables the tokens point at, which wp-admin does not
	// load. Anyone who can open this page can record results, so the panel always loads here.
	wp_enqueue_style(
		HANDLE,
		plugins_url( 'build/overview.css', UAT_Checklist\FILE ),
		[ Styles\HANDLE, Panel\HANDLE ],
		$asset['version']
	);
}

/**
 * Returns the Results list's address, optionally filtered.
 *
 * @param int    $checklist_id Checklist, or 0 for every checklist.
 * @param string $post_status  Result status such as `uat-fail`, or '' for every status.
 * @param bool   $latest       Whether to list only the latest result on each step, the one these counts use.
 * @return string URL.
 */
function results_url( int $checklist_id = 0, string $post_status = '', bool $latest = false ): string {
	$args = [ 'post_type' => Results\POST_TYPE ];

	if ( $checklist_id > 0 ) {
		$args[ Admin\CHECKLIST_FILTER ] = $checklist_id;
	}

	if ( $post_status !== '' ) {
		$args['post_status'] = $post_status;
	}

	if ( $latest ) {
		$args[ Admin\LATEST_FILTER ] = 1;
	}

	return add_query_arg( $args, admin_url( 'edit.php' ) );
}

/**
 * Returns a count in the site's number format, escaped for HTML.
 *
 * @param int $count Count.
 * @return string Formatted count.
 */
function number( int $count ): string {
	return esc_html( number_format_i18n( $count ) );
}

/**
 * Returns a meter of passed, failed and untested steps as SVG, drawn from percentages so it needs no inline style.
 *
 * Fail is hatched as well as coloured, because the fallback red and green are all but identical under
 * deuteranopia. Each segment names its count on hover. The counts are printed beside the meter, so it is hidden
 * from assistive technology.
 *
 * @param array{tests: int, passed: int, failed: int, not_completed: int} $counts Counts.
 * @param string                                                         $id     ID for the hatch pattern, unique on the page.
 * @return string SVG, or '' for a checklist with no steps.
 */
function meter( array $counts, string $id ): string {
	if ( $counts['tests'] === 0 ) {
		return '';
	}

	// %F ignores the locale, so a decimal comma never reaches an attribute.
	$share     = static fn ( int $count ): string => esc_attr( sprintf( '%.3F%%', $count / $counts['tests'] * 100 ) );
	$completed = $counts['passed'] + $counts['failed'];
	$segments  = '';

	foreach (
		[
			[
				'class="uat-overview__pass"',
				0,
				$counts['passed'],
				__( 'Passed', 'uat-checklist' ),
			],
			[
				'fill="url(#' . esc_attr( $id ) . ')"',
				$counts['passed'],
				$counts['failed'],
				__( 'Failed', 'uat-checklist' ),
			],
			[
				'class="uat-overview__untested"',
				$completed,
				$counts['not_completed'],
				__( 'Not completed', 'uat-checklist' ),
			],
		] as [ $paint, $start, $count, $label ]
	) {
		if ( $count > 0 ) {
			$segments .= sprintf(
				'<rect %1$s x="%2$s" width="%3$s" height="100%%"><title>%4$s</title></rect>',
				$paint,
				$share( $start ),
				$share( $count ),
				esc_html(
					sprintf(
						/* translators: 1: Passed, Failed or Not completed, 2: steps in that state, 3: steps in all. */
						__( '%1$s: %2$s of %3$s', 'uat-checklist' ),
						$label,
						number_format_i18n( $count ),
						number_format_i18n( $counts['tests'] )
					)
				)
			);
		}
	}

	// A gap of the surface colour where one segment meets the next, so neighbours read apart without a stroke.
	foreach ( array_unique( [ $counts['passed'], $completed ] ) as $edge ) {
		if ( $edge > 0 && $edge < $counts['tests'] ) {
			$segments .= sprintf(
				'<rect class="uat-overview__gap" x="%s" width="2" height="100%%" transform="translate(-1 0)"/>',
				$share( $edge )
			);
		}
	}

	return sprintf(
		'<svg class="uat-overview__meter" aria-hidden="true" focusable="false">'
			. '<defs><pattern id="%1$s" width="6" height="6"'
			. ' patternUnits="userSpaceOnUse" patternTransform="rotate(45)">'
			. '<rect class="uat-overview__hatch" width="6" height="6"/>'
			. '<rect class="uat-overview__hatch-line" width="2" height="6"/></pattern></defs>'
			. '%2$s</svg>',
		esc_attr( $id ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_attr() and esc_html().
		$segments
	);
}

/**
 * Prints the page.
 *
 * @return void
 */
function render_page(): void {
	$rows  = Progress\checklist_counts();
	$links = $rows === [] ? '' : sprintf(
		'<p class="uat-overview__links"><a href="%1$s">%2$s</a><a href="%3$s">%4$s</a></p>',
		esc_url( results_url() ),
		esc_html__( 'View all results', 'uat-checklist' ),
		esc_url( results_url( 0, Results\STATUSES['fail'], true ) ),
		esc_html__( 'View failed results', 'uat-checklist' )
	);

	// Core moves admin notices to just after .wp-header-end, so they land under the title rather than above the sheet.
	printf(
		'<div class="wrap"><div class="uat-overview">'
			. '<div class="uat-overview__header"><h1 class="uat-overview__title">%1$s</h1>%2$s</div>'
			. '<hr class="wp-header-end">',
		esc_html__( 'Testing overview', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url() and esc_html__().
		$links
	);

	if ( $rows === [] ) {
		render_empty();
	} else {
		render_summary( Progress\totals( $rows ), $rows );
		render_table( $rows );
	}

	echo '</div></div>';
}

/**
 * Prints what the page shows before any checklist is published.
 *
 * @return void
 */
function render_empty(): void {
	$add = current_user_can( Access\CAP_MANAGE )
		? sprintf(
			'<a class="uat-overview__button" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'post-new.php?post_type=' . Checklists\POST_TYPE ) ),
			esc_html__( 'Add checklist', 'uat-checklist' )
		)
		: '';

	printf(
		'<div class="uat-overview__empty">'
			. '<h2 class="uat-overview__heading">%1$s</h2><p class="uat-overview__note">%2$s</p>%3$s'
			. '</div>',
		esc_html__( 'No checklists are published yet', 'uat-checklist' ),
		esc_html__(
			'Testers find a checklist in the UAT panel once it is published, and its steps are counted here from then on.',
			'uat-checklist'
		),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url() and esc_html__().
		$add
	);
}

/**
 * Prints the site-wide totals: the share of required tests completed, the meter, a key giving each segment's
 * count, and how many checklists have got how far.
 *
 * @param array{tests: int, completed: int, not_completed: int, passed: int, failed: int, required: int, required_completed: int, retest: int} $totals Totals.
 * @param array<int, array{tests: int, completed: int, required: int, required_completed: int}>                                       $rows   Each checklist's counts.
 * @return void
 */
function render_summary( array $totals, array $rows ): void {
	if ( $totals['tests'] === 0 ) {
		printf(
			'<section class="uat-overview__summary"><p class="uat-overview__lead">%s</p></section>',
			esc_html__(
				'The published checklists have no steps yet. Add steps to a checklist to start counting.',
				'uat-checklist'
			)
		);
		return;
	}

	$lead = sprintf(
		/* translators: %s: share of the required tests with a result, such as 73. Keep the %% after it, which prints a percent sign. */
		esc_html__( '%s%% of required tests completed', 'uat-checklist' ),
		'<span class="uat-overview__figure">' . number( Progress\percent_tested( $totals ) ) . '</span>'
	);

	$tally = sprintf(
		esc_html(
			/* translators: 1: required tests with a result, 2: required tests, 3: tests with a result, 4: tests in all. */
			_n(
				'%1$s of %2$s required, %3$s of %4$s test in all',
				'%1$s of %2$s required, %3$s of %4$s tests in all',
				$totals['tests'],
				'uat-checklist'
			)
		),
		number( $totals['required_completed'] ),
		number( $totals['required'] ),
		number( $totals['completed'] ),
		number( $totals['tests'] )
	);

	$retest = $totals['retest'] > 0
		? '<p class="uat-overview__tally">' . sprintf(
			/* translators: %s: steps a developer has marked fixed that testers have not retested yet. */
			esc_html( _n( '%s step needs a retest', '%s steps need a retest', $totals['retest'], 'uat-checklist' ) ),
			number( $totals['retest'] )
		) . '</p>'
		: '';

	$key = '';

	foreach (
		[
			'pass'     => [ __( 'Passed', 'uat-checklist' ), $totals['passed'] ],
			'fail'     => [ __( 'Failed', 'uat-checklist' ), $totals['failed'] ],
			'untested' => [ __( 'Not completed', 'uat-checklist' ), $totals['not_completed'] ],
		] as $tone => [ $label, $count ]
	) {
		$key .= sprintf(
			'<div class="uat-overview__key-item uat-overview__key-item--%1$s"><dt>%2$s</dt><dd>%3$s</dd></div>',
			esc_attr( $tone ),
			esc_html( $label ),
			number( $count )
		);
	}

	printf(
		'<section class="uat-overview__summary" aria-labelledby="uat-overview-summary">'
			. '<h2 class="screen-reader-text" id="uat-overview-summary">%1$s</h2>'
			. '<p class="uat-overview__lead">%2$s</p><p class="uat-overview__tally">%3$s</p>'
			. '%7$s%4$s<dl class="uat-overview__key">%5$s</dl>%6$s</section>',
		esc_html__( 'Every checklist', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from number() and esc_html__().
		$lead,
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from number() and esc_html( _n() ).
		$tally,
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built from escaped values in meter().
		meter( $totals, 'uat-overview-hatch' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_attr(), esc_html() and number().
		$key,
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_statuses().
		render_statuses( $rows ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from number() and esc_html( _n() ).
		$retest
	);
}

/**
 * Returns the labels of a checklist's status, keyed by Progress's STATUS_ constants.
 *
 * @return array<string, string> Labels.
 */
function status_labels(): array {
	return [
		Progress\STATUS_COMPLETE    => __( 'Fully tested', 'uat-checklist' ),
		Progress\STATUS_IN_PROGRESS => __( 'In progress', 'uat-checklist' ),
		Progress\STATUS_NOT_STARTED => __( 'Not started', 'uat-checklist' ),
		Progress\STATUS_EMPTY       => __( 'No steps yet', 'uat-checklist' ),
	];
}

/**
 * Returns how many checklists are fully tested, in progress and not started, with checklists that have no steps
 * listed only when there are any.
 *
 * @param array<int, array{tests: int, completed: int, required: int, required_completed: int}> $rows Each checklist's counts.
 * @return string Markup.
 */
function render_statuses( array $rows ): string {
	$tally = array_count_values( array_map( 'HM\\UAT_Checklist\\Progress\\status', $rows ) );
	$items = '';

	foreach ( status_labels() as $status => $label ) {
		if ( $status === Progress\STATUS_EMPTY && empty( $tally[ $status ] ) ) {
			continue;
		}

		$items .= sprintf(
			'<div class="uat-overview__status-count"><dt>%1$s</dt><dd>%2$s</dd></div>',
			esc_html( $label ),
			number( $tally[ $status ] ?? 0 )
		);
	}

	return sprintf(
		'<div class="uat-overview__statuses"><h3 class="uat-overview__subheading">%1$s</h3><dl>%2$s</dl></div>',
		esc_html__( 'Checklists', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html() and number().
		$items
	);
}

/**
 * Prints the table of checklists, one row each.
 *
 * @param array<int, array{id: int, title: string, tests: int, completed: int, not_completed: int, passed: int, failed: int, required: int, required_completed: int, retest: int}> $rows Counts.
 * @return void
 */
function render_table( array $rows ): void {
	printf(
		'<section class="uat-overview__checklists" aria-labelledby="uat-overview-checklists">'
			. '<h2 class="uat-overview__heading" id="uat-overview-checklists">%1$s</h2>'
			. '<table class="uat-overview__table"><thead><tr>'
			. '<th scope="col">%2$s</th><th scope="col">%3$s</th>'
			. '<th scope="col" class="uat-overview__number">%4$s</th>'
			. '<th scope="col" class="uat-overview__number">%5$s</th>'
			. '<th scope="col" class="uat-overview__number">%6$s</th>'
			. '</tr></thead><tbody>%7$s</tbody></table>'
			. '<p class="uat-overview__note">%8$s</p></section>',
		esc_html__( 'By checklist', 'uat-checklist' ),
		esc_html__( 'Checklist', 'uat-checklist' ),
		esc_html__( 'Completed', 'uat-checklist' ),
		esc_html__( 'Passed', 'uat-checklist' ),
		esc_html__( 'Failed', 'uat-checklist' ),
		esc_html__( 'Not completed', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each row escaped in render_row().
		implode( '', array_map( __NAMESPACE__ . '\\render_row', $rows ) ),
		esc_html__(
			'Each step counts once, by its latest result from any tester, as the panel shows it. A checklist is fully tested once every required step has a result, and the percentages count required steps only. Draft checklists are counted once they are published.',
			'uat-checklist'
		)
	);
}

/**
 * Returns one checklist's row: its name and links, its completed count and meter, then its three counts.
 *
 * @param array{id: int, title: string, tests: int, completed: int, not_completed: int, passed: int, failed: int, required: int, required_completed: int, retest: int} $row Counts.
 * @return string Row markup.
 */
function render_row( array $row ): string {
	$links = [
		sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( results_url( $row['id'] ) ),
			esc_html__( 'View results', 'uat-checklist' )
		),
		...Admin\checklist_links( $row['id'] ),
	];

	$status = Progress\status( $row );

	$progress = $status === Progress\STATUS_EMPTY
		? '<span class="uat-overview__count">' . esc_html( status_labels()[ $status ] ) . '</span>'
		: '<span class="uat-overview__count">' . sprintf(
			/* translators: 1: required tests with a result, 2: required tests, 3: their share as a percentage, such as 75. Keep the %% after 3, which prints a percent sign. */
			esc_html__( '%1$s of %2$s required, %3$s%%', 'uat-checklist' ),
			number( $row['required_completed'] ),
			number( $row['required'] ),
			number( Progress\percent_tested( $row ) )
		) . '</span>' . meter( $row, 'uat-overview-hatch-' . $row['id'] );

	// Failures are what a reader scans the table for, so a count above zero takes the panel's Fail badge, and
	// leads to the same failures, each step's latest result, in the Results list.
	$failed = $row['failed'] > 0
		? sprintf(
			'<a class="uat-overview__badge" href="%1$s">'
				. '<span aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></a>',
			esc_url( results_url( $row['id'], Results\STATUSES['fail'], true ) ),
			number( $row['failed'] ),
			esc_html(
				sprintf(
					/* translators: 1: number of failed steps, 2: the checklist's title. */
					_n(
						'%1$s failed step in %2$s. View its failed results.',
						'%1$s failed steps in %2$s. View their failed results.',
						$row['failed'],
						'uat-checklist'
					),
					number_format_i18n( $row['failed'] ),
					$row['title']
				)
			)
		)
		: number( 0 );

	$tags = $status === Progress\STATUS_EMPTY ? '' : sprintf(
		'<span class="uat-overview__status uat-overview__status--%1$s">%2$s</span>',
		esc_attr( $status ),
		esc_html( status_labels()[ $status ] )
	);

	// The same neutral tag as the status: colour on this page only ever means Pass or Fail.
	if ( $row['retest'] > 0 ) {
		$tags .= sprintf(
			'<span class="uat-overview__status">%s</span>',
			sprintf(
				/* translators: %s: steps a developer has marked fixed that testers have not retested yet. */
				esc_html__( '%s to retest', 'uat-checklist' ),
				number( $row['retest'] )
			)
		);
	}

	return sprintf(
		'<tr><th scope="row" class="uat-overview__name"><span class="uat-overview__name-line">'
			. '<span class="uat-overview__name-text">%1$s</span>%11$s</span>'
			. '<span class="uat-overview__row-links">%2$s</span></th>'
			. '<td class="uat-overview__progress" data-label="%3$s">%4$s</td>'
			. '<td class="uat-overview__number" data-label="%5$s">%6$s</td>'
			. '<td class="uat-overview__number" data-label="%7$s">%8$s</td>'
			. '<td class="uat-overview__number" data-label="%9$s">%10$s</td></tr>',
		esc_html( $row['title'] !== '' ? $row['title'] : __( '(no title)', 'uat-checklist' ) ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link built above or by Admin\checklist_links() from esc_url() and esc_html__().
		implode( '', $links ),
		esc_attr__( 'Completed', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_html__(), number() and meter()'s escaped values.
		$progress,
		esc_attr__( 'Passed', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number() escapes.
		number( $row['passed'] ),
		esc_attr__( 'Failed', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from esc_url(), esc_html() and number().
		$failed,
		esc_attr__( 'Not completed', 'uat-checklist' ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- number() escapes.
		number( $row['not_completed'] ),
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr(), esc_html() and number().
		$tags
	);
}
