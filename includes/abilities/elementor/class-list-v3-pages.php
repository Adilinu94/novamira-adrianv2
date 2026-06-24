<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List_V3_Pages — scan all Elementor posts and report V3 / V4 / mixed status.
 *
 * Faster than running convert-site-v3-to-v4 with dry_run because it reads
 * only the first 512 chars of _elementor_data to detect the elType signature.
 *
 * V3 indicators: `"elType":"section"` or `"elType":"column"` at root level.
 * V4 indicators: `"elType":"e-flexbox"` or `"elType":"e-div-block"` at root.
 * Mixed: both present (partial conversion or manual edits).
 *
 * @since 1.8.0
 */
class List_V3_Pages {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/list-v3-pages',
			[
				'label'       => 'List V3 Pages',
				'description' => 'Fast scan of all Elementor posts. Returns per-post V3/V4/mixed/empty status. Use to identify which pages still need convert-page-v3-to-v4.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'filter' => [
							'type'        => 'string',
							'enum'        => [ 'all', 'v3', 'v4', 'mixed', 'empty' ],
							'default'     => 'all',
							'description' => 'Return only posts matching this status.',
						],
						'limit'  => [ 'type' => 'integer', 'default' => 100 ],
						'offset' => [ 'type' => 'integer', 'default' => 0 ],
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
		$filter = $input['filter'] ?? 'all';
		$limit  = max( 1, (int) ( $input['limit'] ?? 100 ) );
		$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value, p.post_title, p.post_type, p.post_status
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_elementor_data'
				   AND pm.meta_value NOT IN ('', '[]')
				   AND p.post_status IN ('publish', 'draft', 'private')
				 ORDER BY pm.post_id ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		$summary = [ 'v3' => 0, 'v4' => 0, 'mixed' => 0, 'empty' => 0 ];
		$pages   = [];

		foreach ( $rows ?? [] as $row ) {
			$status = self::detect_status( $row['meta_value'] ?? '' );
			$summary[ $status ]++;

			if ( 'all' === $filter || $filter === $status ) {
				$pages[] = [
					'post_id'     => (int) $row['post_id'],
					'title'       => $row['post_title'],
					'post_type'   => $row['post_type'],
					'post_status' => $row['post_status'],
					'status'      => $status,
					'edit_url'    => admin_url( "post.php?post={$row['post_id']}&action=elementor" ),
				];
			}
		}

		return [
			'summary'    => $summary,
			'pages'      => $pages,
			'filter'     => $filter,
			'returned'   => count( $pages ),
			'limit'      => $limit,
			'offset'     => $offset,
		];
	}

	/**
	 * Detect V3/V4/mixed/empty status from raw _elementor_data JSON.
	 * Reads only the string — no full JSON parse needed.
	 */
	public static function detect_status( string $raw ): string {
		if ( '' === $raw || '[]' === $raw ) {
			return 'empty';
		}

		$has_v3 = str_contains( $raw, '"section"' ) || str_contains( $raw, '"column"' );
		$has_v4 = str_contains( $raw, '"e-flexbox"' ) || str_contains( $raw, '"e-div-block"' )
		          || str_contains( $raw, '"e-heading"' ) || str_contains( $raw, '"e-paragraph"' );

		if ( $has_v3 && $has_v4 ) {
			return 'mixed';
		}
		if ( $has_v3 ) {
			return 'v3';
		}
		if ( $has_v4 ) {
			return 'v4';
		}
		return 'empty';
	}
}
