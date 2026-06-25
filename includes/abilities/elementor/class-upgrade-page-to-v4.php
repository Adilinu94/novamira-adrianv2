<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/upgrade-page-to-v4
 *
 * A pipeline-friendly batch wrapper around convert-page-v3-to-v4.
 * Designed to be called by site-clone-to-v3 as the last step after a
 * V3 page has been pushed — converts one or more pages from Elementor
 * V3 widget format to V4 Atomic Widgets in a single MCP call.
 *
 * Differences from convert-page-v3-to-v4:
 *   - Accepts multiple post_ids in one call (batch mode)
 *   - Sensible pipeline defaults: dry_run=false, strategy=keep
 *   - Returns per-page summary map { post_id → result }
 *   - Skips pages that are already V4 (opt-out via skip_v4=false)
 *
 * @since 1.7.1
 */
class Upgrade_Page_To_V4 {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/upgrade-page-to-v4',
			[
				'label'       => 'Upgrade Page(s) to Elementor V4',
				'description' => 'Batch wrapper around convert-page-v3-to-v4. Pass one or more post IDs to convert from Elementor V3 widget format to V4 Atomic Widgets in a single call. Designed as the final --upgrade-to-v4 step in the site-clone-to-v3 pipeline after pages have been pushed. Skips pages already in V4 by default. Returns per-page result map.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'post_ids' ],
					'properties' => [
						'post_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => 'One or more page/post IDs to upgrade. Use [0] to upgrade ALL V3 pages site-wide (careful!).',
						],
						'strategy' => [
							'type'        => 'string',
							'enum'        => [ 'keep', 'skip', 'error' ],
							'default'     => 'keep',
							'description' => 'How to handle unsupported V3 widgets: keep (keep as V3, default), skip (remove from tree), error (abort page).',
						],
						'skip_v4' => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Skip pages already in V4. Set to false to force re-run.',
						],
						'dry_run' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Preview conversion without writing to DB.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'   => [ 'type' => 'boolean' ],
						'total'     => [ 'type' => 'integer' ],
						'upgraded'  => [ 'type' => 'integer' ],
						'skipped'   => [ 'type' => 'integer' ],
						'failed'    => [ 'type' => 'integer' ],
						'dry_run'   => [ 'type' => 'boolean' ],
						'results'   => [
							'type'        => 'object',
							'description' => 'Per-page results keyed by post_id.',
							'additionalProperties' => [
								'type'       => 'object',
								'properties' => [
									'status'    => [ 'type' => 'string', 'enum' => [ 'upgraded', 'skipped', 'failed', 'already_v4' ] ],
									'converted' => [ 'type' => 'integer' ],
									'kept_v3'   => [ 'type' => 'integer' ],
									'warnings'  => [ 'type' => 'array' ],
									'error'     => [ 'type' => 'string' ],
								],
							],
						],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					],
				],
			]
		);
	}

	public static function execute( ?array $input ): array {
		$post_ids = array_map( 'intval', (array) ( $input['post_ids'] ?? [] ) );
		$strategy = $input['strategy'] ?? 'keep';
		$skip_v4  = (bool) ( $input['skip_v4'] ?? true );
		$dry_run  = (bool) ( $input['dry_run'] ?? false );

		if ( empty( $post_ids ) ) {
			return [ 'success' => false, 'error' => 'post_ids must be a non-empty array.' ];
		}
		if ( ! in_array( $strategy, [ 'keep', 'skip', 'error' ], true ) ) {
			$strategy = 'keep';
		}

		// [0] = site-wide upgrade: query all V3 pages.
		if ( $post_ids === [ 0 ] ) {
			$post_ids = self::get_all_v3_pages();
		}

		$results  = [];
		$upgraded = 0;
		$skipped  = 0;
		$failed   = 0;

		foreach ( $post_ids as $post_id ) {
			if ( $post_id <= 0 ) {
				continue;
			}

			// Skip V4 pages if requested.
			if ( $skip_v4 && class_exists( '\\Novamira\\AdrianV2\\Helpers\\Elementor_Version_Resolver' ) ) {
				if ( \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::page_is_v4( $post_id ) ) {
					$results[ (string) $post_id ] = [ 'status' => 'already_v4' ];
					$skipped++;
					continue;
				}
			}

			// Delegate to convert-page-v3-to-v4.
			if ( ! class_exists( '\\Novamira\\AdrianV2\\Abilities\\Elementor\\Convert_Page_V3_To_V4' ) ) {
				$results[ (string) $post_id ] = [
					'status' => 'failed',
					'error'  => 'Convert_Page_V3_To_V4 class not found.',
				];
				$failed++;
				continue;
			}

			// Map strategy names: 'keep' → 'keep_v3' (convert-page uses 'keep_v3' internally).
			$internal_strategy = $strategy === 'keep' ? 'keep_v3' : $strategy;

			$result = Convert_Page_V3_To_V4::execute( [
				'post_id'                 => $post_id,
				'dry_run'                 => $dry_run,
				'unknown_widget_strategy' => $internal_strategy,
			] );

			if ( ! ( $result['success'] ?? false ) ) {
				$results[ (string) $post_id ] = [
					'status' => 'failed',
					'error'  => $result['error'] ?? 'Unknown error.',
				];
				$failed++;
				continue;
			}

			$stats = $result['stats'] ?? [];
			$results[ (string) $post_id ] = [
				'status'    => 'upgraded',
				'converted' => $stats['converted'] ?? 0,
				'kept_v3'   => $stats['kept_v3'] ?? 0,
				'skipped'   => $stats['skipped'] ?? 0,
				'warnings'  => $result['warnings'] ?? [],
			];
			$upgraded++;
		}

		return [
			'success'  => true,
			'total'    => count( $post_ids ),
			'upgraded' => $upgraded,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'dry_run'  => $dry_run,
			'results'  => $results,
		];
	}

	/**
	 * Query all pages that have Elementor V3 data (not yet V4).
	 *
	 * @return int[]
	 */
	private static function get_all_v3_pages(): array {
		$posts = get_posts( [
			'post_type'      => [ 'page', 'post', 'elementor_library' ],
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => 200,
			'meta_key'       => '_elementor_edit_mode',
			'meta_value'     => 'builder',
			'fields'         => 'ids',
		] );

		return array_map( 'intval', $posts );
	}
}
