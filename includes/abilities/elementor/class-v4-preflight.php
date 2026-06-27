<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Conversion_Auditor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validate_V4_Tree — server-side V4 tree validation via Conversion_Auditor.
 *
 * Runs the full Conversion_Auditor suite against an existing post's
 * _elementor_data: orphan styles, dangling class refs, responsive
 * issues, layout violations, content gaps. Returns a structured issues list.
 *
 * Replaces client-side scripts/validate-v4-tree.js by giving pipeline
 * access to server-side context: active kit, live class registry,
 * experiment flags.
 *
 * @since 1.9.0
 */
final class Validate_V4_Tree {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/validate-v4-tree',
			[
				'label'       => 'Validate V4 Tree',
				'description' => 'Runs Conversion_Auditor checks on a post\'s _elementor_data: orphan styles, dangling class refs, responsive violations, layout issues, empty content. Returns issues grouped by type+severity. Use in pipeline preflight after convert-page-v3-to-v4.',
				'category'    => 'adrianv2-elementor',
				'input_schema' => [
					'type'     => 'object',
					'required' => [ 'post_id' ],
					'properties' => [
						'post_id'       => [ 'type' => 'integer' ],
						'severity'      => [
							'type'    => 'string',
							'enum'    => [ 'all', 'error', 'warning' ],
							'default' => 'all',
							'description' => 'Filter returned issues by severity.',
						],
						'checks'        => [
							'type'  => 'array',
							'items' => [ 'type' => 'string', 'enum' => [ 'class', 'responsive', 'layout', 'content' ] ],
							'description' => 'Limit to specific check types. Default: all.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'post_id'   => [ 'type' => 'integer' ],
						'v4_status' => [ 'type' => 'string' ],
						'issues'    => [ 'type' => 'array' ],
						'summary'   => [ 'type' => 'object' ],
						'pass'      => [ 'type' => 'boolean' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta' => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}

	public static function execute( $input = null ): array {
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$severity = $input['severity'] ?? 'all';
		$checks   = $input['checks'] ?? [];

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return [ 'pass' => false, 'issues' => [], 'summary' => [], 'error' => "Post {$post_id} not found." ];
		}

		$raw  = get_post_meta( $post_id, '_elementor_data', true );
		$tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( ! is_array( $tree ) ) {
			return [ 'pass' => false, 'issues' => [], 'summary' => [], 'error' => 'No Elementor data on this post.' ];
		}

		$all_issues = Conversion_Auditor::audit( $tree );

		// Filter by severity.
		if ( 'all' !== $severity ) {
			$all_issues = array_values( array_filter( $all_issues, fn( $i ) => ( $i['severity'] ?? '' ) === $severity ) );
		}

		// Filter by type.
		if ( ! empty( $checks ) ) {
			$all_issues = array_values( array_filter( $all_issues, fn( $i ) => in_array( $i['type'] ?? '', $checks, true ) ) );
		}

		// Summary by type+severity.
		$summary = [];
		foreach ( $all_issues as $issue ) {
			$key              = ( $issue['type'] ?? 'misc' ) . '.' . ( $issue['severity'] ?? 'info' );
			$summary[ $key ]  = ( $summary[ $key ] ?? 0 ) + 1;
		}

		// V4 status check via list-v3-pages logic.
		$v4_status = List_V3_Pages::detect_status( $raw );

		$errors = array_filter( $all_issues, fn( $i ) => ( $i['severity'] ?? '' ) === 'error' );

		return [
			'post_id'   => $post_id,
			'v4_status' => $v4_status,
			'issues'    => $all_issues,
			'summary'   => $summary,
			'pass'      => count( $errors ) === 0,
		];
	}
}


