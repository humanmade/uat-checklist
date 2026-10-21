<?php
/**
 * Abilities: the plugin's reads and writes for an AI assistant, registered with the Abilities API and listed by the
 * WordPress MCP adapter.
 *
 * None records a Pass or a Fail. A result is a tester's judgement, and one an assistant saved would read as theirs.
 *
 * @package HM_UAT_Checklist
 */

declare( strict_types = 1 );

namespace HM\UAT_Checklist\Abilities;

use HM\UAT_Checklist\Access;
use HM\UAT_Checklist\Admin;
use HM\UAT_Checklist\Blocks;
use HM\UAT_Checklist\Checklists;
use HM\UAT_Checklist\Progress;
use HM\UAT_Checklist\Results;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Prefix of every ability name.
 */
const PREFIX = 'uat-checklist/';

/**
 * Ability category.
 */
const CATEGORY = 'uat-checklist';

/**
 * Tags a step's text keeps, the ones the step block renders.
 */
const STEP_HTML = [
	'strong' => [],
	'em'     => [],
	'b'      => [],
	'i'      => [],
	'a'      => [ 'href' => true ],
];

/**
 * Wires up the module's hooks.
 *
 * @return void
 */
function bootstrap(): void {
	add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
	add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
}

/**
 * Registers the category the abilities are listed under.
 *
 * @return void
 */
function register_category(): void {
	wp_register_ability_category(
		CATEGORY,
		[
			'label'       => __( 'UAT checklists', 'uat-checklist' ),
			'description' => __(
				'Testing checklists, how far testing has got, and the steps that failed.',
				'uat-checklist'
			),
		]
	);
}

/**
 * Registers the five abilities.
 *
 * @return void
 */
function register_abilities(): void {
	$checklist_id = [
		'type'        => 'integer',
		'minimum'     => 1,
		'description' => __( 'Checklist ID.', 'uat-checklist' ),
	];

	wp_register_ability(
		PREFIX . 'get-progress',
		[
			'label'               => __( 'Get testing progress', 'uat-checklist' ),
			'description'         => __(
				'Returns how many steps each published checklist has, and how many have passed, failed, wait for a retest or have not been tested, by each step\'s latest result from any tester. The totals cover every published checklist.',
				'uat-checklist'
			),
			'category'            => CATEGORY,
			'input_schema'        => input_schema( [] ),
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'totals'     => counts_schema(),
					'checklists' => [
						'type'  => 'array',
						'items' => counts_schema(
							[
								'id'    => [ 'type' => 'integer' ],
								'title' => [ 'type' => 'string' ],
							]
						),
					],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\\get_progress',
			'permission_callback' => __NAMESPACE__ . '\\can_use',
			'meta'                => meta( true, false, true ),
		]
	);

	wp_register_ability(
		PREFIX . 'get-checklist',
		[
			'label'               => __( 'Get a checklist', 'uat-checklist' ),
			'description'         => __(
				'Returns one checklist\'s title, intro and sections, and each step with the latest result on it. Pass this back to save-checklist, changed, to edit the checklist.',
				'uat-checklist'
			),
			'category'            => CATEGORY,
			'input_schema'        => input_schema( [ 'checklist_id' => $checklist_id ], [ 'checklist_id' ] ),
			'output_schema'       => checklist_schema(),
			'execute_callback'    => __NAMESPACE__ . '\\get_checklist',
			'permission_callback' => __NAMESPACE__ . '\\can_use',
			'meta'                => meta( true, false, true ),
		]
	);

	wp_register_ability(
		PREFIX . 'list-failures',
		[
			'label'               => __( 'List open failures', 'uat-checklist' ),
			'description'         => __(
				'Returns every step whose latest result is a Fail, with the tester\'s comment and the result ID that mark-fixed takes. Covers every published checklist, or the one given.',
				'uat-checklist'
			),
			'category'            => CATEGORY,
			'input_schema'        => input_schema( [ 'checklist_id' => $checklist_id ] ),
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'failures' => [
						'type'  => 'array',
						'items' => failure_schema(),
					],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\\list_failures',
			'permission_callback' => __NAMESPACE__ . '\\can_use',
			'meta'                => meta( true, false, true ),
		]
	);

	wp_register_ability(
		PREFIX . 'mark-fixed',
		[
			'label'               => __( 'Mark a failure fixed', 'uat-checklist' ),
			'description'         => __(
				'Marks a failed step fixed, so testers see it tagged Retest. The Fail stays on record, and the step counts as not completed until a tester passes or fails it again. Takes a result ID from list-failures.',
				'uat-checklist'
			),
			'category'            => CATEGORY,
			'input_schema'        => input_schema(
				[
					'result_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the Fail, from list-failures.', 'uat-checklist' ),
					],
					'note'      => [
						'type'        => 'string',
						'maxLength'   => Results\COMMENT_MAX_LENGTH,
						'description' => __(
							'What was fixed. Testers see it when they retest the step, and the tester who failed it is emailed the note.',
							'uat-checklist'
						),
					],
					'notify'    => [
						'type'        => 'boolean',
						'description' => __(
							'False to save the note without emailing it to the tester. Defaults to true.',
							'uat-checklist'
						),
					],
				],
				[ 'result_id' ]
			),
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'fixed_result_id' => [ 'type' => 'integer' ],
					'checklist_id'    => [ 'type' => 'integer' ],
					'step_id'         => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => __NAMESPACE__ . '\\mark_fixed',
			'permission_callback' => __NAMESPACE__ . '\\can_use',
			// A second call on the same result is refused, so it changes nothing more.
			'meta'                => meta( false, false, true ),
		]
	);

	wp_register_ability(
		PREFIX . 'save-checklist',
		[
			'label'               => __( 'Save a checklist', 'uat-checklist' ),
			'description'         => __(
				'Creates a checklist, or replaces an existing one\'s title, intro and sections. To edit one, read it with get-checklist and keep each step\'s id: a step keeps its results only through its id. Leave the id out for a new step. A step left out is removed.',
				'uat-checklist'
			),
			'category'            => CATEGORY,
			'input_schema'        => input_schema(
				[
					'checklist_id' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Checklist to replace. Leave it out to create one.', 'uat-checklist' ),
					],
					'title'        => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => __( 'Title testers pick the checklist by.', 'uat-checklist' ),
					],
					'status'       => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'description' => __(
							'Publish to show it to testers. A new checklist is a draft unless this says otherwise; an existing one keeps its status.',
							'uat-checklist'
						),
					],
				] + checklist_body_schema(),
				[ 'title', 'sections' ]
			),
			'output_schema'       => checklist_schema(),
			'execute_callback'    => __NAMESPACE__ . '\\save_checklist',
			'permission_callback' => __NAMESPACE__ . '\\can_use',
			// Called twice with no checklist_id, it creates two checklists.
			'meta'                => meta( false, true, false ),
		]
	);
}

/**
 * Returns the meta every ability shares: listed by the MCP adapter, and kept off core's REST routes.
 *
 * @param bool $read_only   Whether it changes nothing.
 * @param bool $destructive Whether it can remove or replace content.
 * @param bool $idempotent  Whether calling it again with the same input changes nothing more.
 * @return array<string, mixed> Meta.
 */
function meta( bool $read_only, bool $destructive, bool $idempotent ): array {
	return [
		'annotations'  => [
			'readonly'    => $read_only,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		],
		'public'       => true,
		'show_in_rest' => false,
	];
}

/**
 * Returns an object input schema. It defaults to empty, so an ability called with no input still validates.
 *
 * @param array<string, mixed> $properties Properties.
 * @param string[]             $required   Required property names.
 * @return array<string, mixed> Schema.
 */
function input_schema( array $properties, array $required = [] ): array {
	$schema = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'default'              => [],
	];

	if ( $properties !== [] ) {
		$schema['properties'] = $properties;
	}

	if ( $required !== [] ) {
		$schema['required'] = $required;
	}

	return $schema;
}

/**
 * Returns the schema of one set of counts, from Progress\counts(), with its status and percentage.
 *
 * @param array<string, mixed> $extra Further properties.
 * @return array<string, mixed> Schema.
 */
function counts_schema( array $extra = [] ): array {
	$integers = array_fill_keys(
		[
			'tests',
			'completed',
			'not_completed',
			'passed',
			'failed',
			'retest',
			'required',
			'required_completed',
			'percent_tested',
		],
		[ 'type' => 'integer' ]
	);

	return [
		'type'       => 'object',
		'properties' => $extra + $integers + [
			'status' => [
				'type' => 'string',
				'enum' => [
					Progress\STATUS_EMPTY,
					Progress\STATUS_NOT_STARTED,
					Progress\STATUS_IN_PROGRESS,
					Progress\STATUS_COMPLETE,
				],
			],
		],
	];
}

/**
 * Returns the schema of a checklist's intro and sections, as get-checklist returns them and save-checklist takes them
 * back.
 *
 * @return array<string, mixed> Properties.
 */
function checklist_body_schema(): array {
	$html  = __( 'HTML: strong, em and links are kept.', 'uat-checklist' );
	$lines = [
		'type'  => 'array',
		'items' => [ 'type' => 'string' ],
	];
	$step  = [
		'id'             => [
			'type'        => 'string',
			'description' => __(
				'The step\'s id from get-checklist. Leave it out for a new step.',
				'uat-checklist'
			),
		],
		'step'           => [
			'type'        => 'string',
			'minLength'   => 1,
			'description' => __( 'What the tester checks, or a scenario\'s short title.', 'uat-checklist' )
				. ' '
				. $html,
		],
		'expected'       => [
			'type'        => 'string',
			'description' => __( 'The one expected result.', 'uat-checklist' ) . ' ' . $html,
		],
		'expected_items' => $lines + [
			'description' => __( 'A scenario\'s expected results, used instead of expected.', 'uat-checklist' )
				. ' '
				. $html,
		],
		'instructions'   => $lines + [
			'description' => __( 'A scenario\'s numbered actions.', 'uat-checklist' ) . ' ' . $html,
		],
		'required'       => [
			'type'        => 'boolean',
			'description' => __( 'False for a nice-to-have step. Defaults to true.', 'uat-checklist' ),
		],
		'image_url'      => [
			'type'        => 'string',
			'description' => __( 'Screenshot shown above the instructions.', 'uat-checklist' ),
		],
		'image_alt'      => [
			'type'        => 'string',
			'description' => __( 'The screenshot\'s alternative text.', 'uat-checklist' ),
		],
		// Accepted on save so get-checklist's output can be passed back as it is.
		'result'         => [
			'type'        => 'string',
			'enum'        => [ 'pass', 'fail', 'fixed', '' ],
			'description' => __(
				'The latest result from any tester; fixed means it waits for a retest, empty means untested. save-checklist ignores it.',
				'uat-checklist'
			),
		],
	];

	return [
		'intro'    => $lines + [
			'description' => __( 'Paragraphs above the sections, such as the ticket the checklist tests.', 'uat-checklist' )
				. ' '
				. $html,
		],
		'sections' => [
			'type'     => 'array',
			'minItems' => 1,
			'items'    => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'title', 'steps' ],
				'properties'           => [
					'title' => [
						'type'        => 'string',
						'description' => __(
							'Section title, such as In the editor or Mobile width.',
							'uat-checklist'
						),
					],
					'steps' => [
						'type'     => 'array',
						'minItems' => 1,
						'items'    => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'step' ],
							'properties'           => $step,
						],
					],
				],
			],
		],
	];
}

/**
 * Returns the schema of a checklist as get-checklist and save-checklist return it.
 *
 * @return array<string, mixed> Schema.
 */
function checklist_schema(): array {
	return [
		'type'       => 'object',
		'properties' => [
			'id'       => [ 'type' => 'integer' ],
			'title'    => [ 'type' => 'string' ],
			'status'   => [ 'type' => 'string' ],
			'edit_url' => [ 'type' => 'string' ],
		] + checklist_body_schema(),
	];
}

/**
 * Returns the schema of one open failure.
 *
 * @return array<string, mixed> Schema.
 */
function failure_schema(): array {
	$properties = array_fill_keys(
		[ 'checklist_title', 'section', 'step_id', 'step', 'expected', 'tester', 'failed_at', 'comment' ],
		[ 'type' => 'string' ]
	);

	return [
		'type'       => 'object',
		'properties' => [
			'result_id'      => [ 'type' => 'integer' ],
			'checklist_id'   => [ 'type' => 'integer' ],
			'expected_items' => [
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
			],
		] + $properties,
	];
}

/**
 * Returns whether the current user may use the abilities: only a user whose email is at a staff domain.
 *
 * Narrower than the plugin's capabilities on purpose: the profile's full-access grant and the reading capability
 * every Contributor holds do not open an AI assistant's door to the site.
 *
 * @return bool Whether they may.
 */
function can_use(): bool {
	return Access\has_staff_email( wp_get_current_user() );
}

/**
 * Returns the overview's counts, site-wide and for each published checklist.
 *
 * @return array{totals: array<string, int|string>, checklists: array<int, array<string, int|string>>} Progress.
 */
function get_progress(): array {
	$rows = Progress\checklist_counts();

	return [
		'totals'     => with_status( Progress\totals( $rows ) ),
		'checklists' => array_map( __NAMESPACE__ . '\\with_status', $rows ),
	];
}

/**
 * Adds a set of counts' status and percentage tested.
 *
 * @param array{tests: int, completed: int, required: int, required_completed: int} $counts Counts.
 * @return array<string, int|string> Counts.
 */
function with_status( array $counts ): array {
	return $counts + [
		'status'         => Progress\status( $counts ),
		'percent_tested' => Progress\percent_tested( $counts ),
	];
}

/**
 * Returns one checklist the current user may read.
 *
 * @param array{checklist_id: int} $input Input.
 * @return array<string, mixed>|WP_Error Checklist.
 */
function get_checklist( array $input ): array|WP_Error {
	$checklist = find_checklist( $input['checklist_id'] );

	return $checklist instanceof WP_Post ? describe( $checklist ) : $checklist;
}

/**
 * Returns a checklist by ID, in any status but the trash: every user the abilities admit is a UAT manager, who can
 * read drafts.
 *
 * @param int $checklist_id Checklist ID.
 * @return WP_Post|WP_Error Checklist.
 */
function find_checklist( int $checklist_id ): WP_Post|WP_Error {
	$checklist = get_post( $checklist_id );

	if (
		! $checklist instanceof WP_Post
		|| $checklist->post_type !== Checklists\POST_TYPE
		|| $checklist->post_status === 'trash'
	) {
		return new WP_Error(
			'uat_checklist_not_found',
			__( 'There is no checklist with that ID.', 'uat-checklist' )
		);
	}

	return $checklist;
}

/**
 * Returns a checklist's title, intro and sections, each step with its latest result.
 *
 * The intro is every paragraph outside a section, in order, so saving the result back loses no text. A paragraph
 * the editor placed between sections moves above them.
 *
 * @param WP_Post $checklist Checklist.
 * @return array<string, mixed> Checklist.
 */
function describe( WP_Post $checklist ): array {
	$block  = Blocks\find_checklist_block( $checklist );
	$latest = Results\get_checklist_results( $checklist->ID );
	$out    = [
		'id'       => $checklist->ID,
		'title'    => Results\plain_text( $checklist->post_title ),
		'status'   => $checklist->post_status,
		'edit_url' => (string) get_edit_post_link( $checklist->ID, 'raw' ),
		'intro'    => [],
		'sections' => [],
	];

	foreach ( $block['innerBlocks'] ?? [] as $inner ) {
		if ( $inner['blockName'] === Blocks\PARAGRAPH_BLOCK ) {
			$text = trim( (string) preg_replace( '#^\s*<p[^>]*>|</p>\s*$#', '', (string) $inner['innerHTML'] ) );

			if ( $text !== '' ) {
				$out['intro'][] = $text;
			}
		}

		if ( $inner['blockName'] === Blocks\SECTION_BLOCK ) {
			$steps = array_filter(
				$inner['innerBlocks'],
				static fn ( array $step ): bool => $step['blockName'] === Blocks\STEP_BLOCK
			);

			$out['sections'][] = [
				'title' => (string) ( $inner['attrs']['title'] ?? '' ),
				'steps' => array_values(
					array_map(
						static fn ( array $step ): array => describe_step( $step['attrs'], $latest ),
						$steps
					)
				),
			];
		}
	}

	return $out;
}

/**
 * Returns one step's attributes under the abilities' names, with its latest result.
 *
 * @param array<string, mixed>   $attrs  Step block attributes.
 * @param array<string, WP_Post> $latest Latest result on each step, keyed by step ID.
 * @return array<string, mixed> Step.
 */
function describe_step( array $attrs, array $latest ): array {
	$id = (string) ( $attrs['stepId'] ?? '' );

	return [
		'id'             => $id,
		'step'           => (string) ( $attrs['step'] ?? '' ),
		'expected'       => (string) ( $attrs['expected'] ?? '' ),
		'expected_items' => array_map( 'strval', (array) ( $attrs['expectedItems'] ?? [] ) ),
		'instructions'   => array_map( 'strval', (array) ( $attrs['instructions'] ?? [] ) ),
		'required'       => ( $attrs['required'] ?? true ) !== false,
		'image_url'      => (string) ( $attrs['imageUrl'] ?? '' ),
		'image_alt'      => (string) ( $attrs['imageAlt'] ?? '' ),
		'result'         => isset( $latest[ $id ] )
			? Results\result_for_status( $latest[ $id ]->post_status )
			: '',
	];
}

/**
 * Returns every step whose latest result is a Fail, on the given checklist or on every published one.
 *
 * @param array{checklist_id?: int} $input Input.
 * @return array{failures: array<int, array<string, mixed>>}|WP_Error Failures.
 */
function list_failures( array $input ): array|WP_Error {
	$checklists = Progress\published_checklists();

	if ( isset( $input['checklist_id'] ) ) {
		$checklist = find_checklist( $input['checklist_id'] );

		if ( is_wp_error( $checklist ) ) {
			return $checklist;
		}

		$checklists = [ $checklist ];
	}

	$failures = [];

	foreach ( $checklists as $checklist ) {
		$latest = Results\get_checklist_results( $checklist->ID );

		foreach ( describe( $checklist )['sections'] as $section ) {
			foreach ( $section['steps'] as $step ) {
				if ( $step['result'] === 'fail' ) {
					$failures[] = describe_failure( $checklist, $section['title'], $step, $latest[ $step['id'] ] );
				}
			}
		}
	}

	return [ 'failures' => $failures ];
}

/**
 * Returns one open failure: the step, and who failed it, when and why.
 *
 * @param WP_Post              $checklist Checklist.
 * @param string               $section   Title of the step's section.
 * @param array<string, mixed> $step      Step, from describe_step().
 * @param WP_Post              $result    The Fail.
 * @return array<string, mixed> Failure.
 */
function describe_failure( WP_Post $checklist, string $section, array $step, WP_Post $result ): array {
	return [
		'result_id'       => $result->ID,
		'checklist_id'    => $checklist->ID,
		'checklist_title' => Results\plain_text( $checklist->post_title ),
		'section'         => $section,
		'step_id'         => $step['id'],
		'step'            => $step['step'],
		'expected'        => $step['expected'],
		'expected_items'  => $step['expected_items'],
		'tester'          => (string) get_the_author_meta( 'display_name', (int) $result->post_author ),
		'failed_at'       => (string) get_post_modified_time( DATE_ATOM, true, $result ),
		'comment'         => Results\plain_text( $result->post_content ),
	];
}

/**
 * Marks a failed step fixed, through the same decision as the Results list's Mark fixed.
 *
 * @param array{result_id: int|string, note?: string, notify?: bool|int|string} $input Input.
 * @return array{fixed_result_id: int, checklist_id: int, step_id: string}|WP_Error The Fixed result.
 */
function mark_fixed( array $input ): array|WP_Error {
	// The schema validates the input but does not convert it: "5" passes as an integer and "false" as a boolean.
	$fixed = Admin\mark_fixed(
		absint( $input['result_id'] ),
		get_current_user_id(),
		sanitize_textarea_field( $input['note'] ?? '' ),
		rest_sanitize_boolean( $input['notify'] ?? true )
	);

	if ( is_wp_error( $fixed ) ) {
		return $fixed;
	}

	return [
		'fixed_result_id' => $fixed->ID,
		'checklist_id'    => $fixed->post_parent,
		'step_id'         => Results\step_id_of( $fixed ),
	];
}

/**
 * Creates a checklist, or replaces an existing one's title and content.
 *
 * @param array{checklist_id?: int, title: string, status?: string, intro?: string[], sections: array<int, array<string, mixed>>} $input Input.
 * @return array<string, mixed>|WP_Error The checklist as saved.
 */
function save_checklist( array $input ): array|WP_Error {
	$existing = isset( $input['checklist_id'] ) ? find_checklist( $input['checklist_id'] ) : null;

	if ( is_wp_error( $existing ) ) {
		return $existing;
	}

	$content = checklist_content(
		$input['intro'] ?? [],
		$input['sections'],
		$existing === null ? [] : Progress\step_ids( $existing )
	);

	if ( is_wp_error( $content ) ) {
		return $content;
	}

	$post = [
		'post_type'    => Checklists\POST_TYPE,
		'post_title'   => sanitize_text_field( $input['title'] ),
		'post_content' => $content,
		'post_status'  => $input['status'] ?? $existing->post_status ?? 'draft',
	];

	if ( $existing !== null ) {
		$post['ID'] = $existing->ID;
	}

	$id = $existing === null
		? wp_insert_post( wp_slash( $post ), true )
		: wp_update_post( wp_slash( $post ), true );

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	return get_checklist( [ 'checklist_id' => (int) $id ] );
}

/**
 * Returns a checklist's block markup: the Testing checklist block, its intro paragraphs above its sections.
 *
 * @param string[]                         $intro     Intro paragraphs.
 * @param array<int, array<string, mixed>> $sections  Sections, each with its steps.
 * @param string[]                         $known_ids Step IDs the checklist holds now, the only ones a step may keep.
 * @return string|WP_Error Markup, or why it was refused.
 */
function checklist_content( array $intro, array $sections, array $known_ids ): string|WP_Error {
	$inner = [];
	$seen  = [];

	foreach ( $intro as $paragraph ) {
		$html = trim( wp_kses( $paragraph, STEP_HTML ) );

		if ( $html !== '' ) {
			$inner[] = block( Blocks\PARAGRAPH_BLOCK, [], [], "<p>{$html}</p>" );
		}
	}

	foreach ( $sections as $section ) {
		$steps = [];

		foreach ( $section['steps'] as $step ) {
			$id = (string) ( $step['id'] ?? '' );

			if ( $id !== '' && ( ! in_array( $id, $known_ids, true ) || isset( $seen[ $id ] ) ) ) {
				return new WP_Error(
					'uat_checklist_unknown_step',
					sprintf(
						/* translators: %s: a step ID. */
						__(
							'Step id %s is not a step of this checklist, or is given twice. Leave the id out to add a new step.',
							'uat-checklist'
						),
						$id
					)
				);
			}

			$id          = $id !== '' ? $id : wp_generate_uuid4();
			$seen[ $id ] = true;
			$steps[]     = block( Blocks\STEP_BLOCK, step_attributes( $id, $step ) );
		}

		$inner[] = block( Blocks\SECTION_BLOCK, [ 'title' => sanitize_text_field( $section['title'] ) ], $steps );
	}

	return serialize_block( block( Blocks\CHECKLIST_BLOCK, [], $inner ) );
}

/**
 * Returns a step block's attributes from a step as save-checklist takes it, leaving out those at their default as
 * the editor does.
 *
 * @param string               $id   Step ID.
 * @param array<string, mixed> $step Step.
 * @return array<string, mixed> Attributes.
 */
function step_attributes( string $id, array $step ): array {
	$html  = static fn ( mixed $text ): string => trim( wp_kses( (string) $text, STEP_HTML ) );
	$lines = static fn ( mixed $items ): array => array_values(
		array_filter(
			array_map( $html, (array) $items ),
			static fn ( string $line ): bool => $line !== ''
		)
	);
	$attrs = [
		'stepId'        => $id,
		'step'          => $html( $step['step'] ),
		'expected'      => $html( $step['expected'] ?? '' ),
		'expectedItems' => $lines( $step['expected_items'] ?? [] ),
		'instructions'  => $lines( $step['instructions'] ?? [] ),
		'imageUrl'      => esc_url_raw( (string) ( $step['image_url'] ?? '' ) ),
		'imageAlt'      => sanitize_text_field( (string) ( $step['image_alt'] ?? '' ) ),
	];

	if ( ( $step['required'] ?? true ) === false ) {
		$attrs['required'] = false;
	}

	return array_filter( $attrs, static fn ( mixed $value ): bool => $value !== '' && $value !== [] );
}

/**
 * Returns a parsed block, as serialize_block() takes it.
 *
 * The checklist and section blocks save their inner blocks and nothing else, and a step saves nothing, so each
 * inner block is a null in innerContent with no markup around it.
 *
 * @param string                           $name  Block name.
 * @param array<string, mixed>             $attrs Attributes.
 * @param array<int, array<string, mixed>> $inner Inner blocks.
 * @param string                           $html  The block's own markup, for a paragraph.
 * @return array<string, mixed> Block.
 */
function block( string $name, array $attrs, array $inner = [], string $html = '' ): array {
	return [
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $inner,
		'innerHTML'    => $html,
		'innerContent' => $html !== '' ? [ $html ] : array_fill( 0, count( $inner ), null ),
	];
}
