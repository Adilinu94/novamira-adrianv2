<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/rankmath-check-setup
 *
 * Read-only probe of the Rank Math SEO environment.
 * Reports: active, version, active modules, sitemap, schema, local SEO,
 * analytics connection, and detected configuration issues.
 *
 * @since 1.8.0
 */
final class Rankmath_Check_Setup {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/rankmath-check-setup',
			[
				'label'       => 'Check Rank Math Setup',
				'description' => 'Reports the Rank Math SEO environment: active state, version, active modules (sitemap, schema, local-seo, analytics, status-checker), general settings, breadcrumbs, and detected configuration issues. Read-only.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'active'   => [ 'type' => 'boolean' ],
						'version'  => [ 'type' => [ 'string', 'null' ] ],
						'modules'  => [ 'type' => 'object' ],
						'settings' => [ 'type' => 'object' ],
						'issues'   => [ 'type' => 'array' ],
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
		$active  = defined( 'RANK_MATH_VERSION' );
		$version = $active ? RANK_MATH_VERSION : null;
		$issues  = [];

		if ( ! $active ) {
			return [
				'active'   => false,
				'version'  => null,
				'modules'  => [],
				'settings' => [],
				'issues'   => [ 'Rank Math SEO is not active.' ],
			];
		}

		// Active modules: stored as comma-separated string in rank_math_modules option.
		$modules_raw    = get_option( 'rank_math_modules', '' );
		$active_modules = is_array( $modules_raw )
			? $modules_raw
			: array_filter( explode( ',', (string) $modules_raw ) );

		$known_modules  = [ 'sitemap', 'schema', 'local-seo', 'analytics', 'status-checker',
		                     'redirections', 'link-counter', 'role-manager', 'search-console' ];
		$module_status  = [];
		foreach ( $known_modules as $m ) {
			$module_status[ $m ] = in_array( $m, $active_modules, true );
		}

		$general = get_option( 'rank-math-options-general', [] );
		$titles  = get_option( 'rank-math-options-titles', [] );

		$settings = [
			'breadcrumbs_enabled'   => ! empty( $general['breadcrumbs'] ),
			'og_enabled'            => ! empty( $titles['homepage_facebook_image'] ) || ! empty( $general['opengraph'] ),
			'homepage_title'        => $titles['homepage_title'] ?? null,
			'homepage_description'  => $titles['homepage_description'] ?? null,
			'noindex_empty_taxonomies' => ! empty( $titles['noindex_empty_taxonomies'] ),
			'schema_type_default'   => $titles['schema_type'] ?? null,
		];

		// Issues.
		if ( ! $module_status['sitemap'] ) {
			$issues[] = 'Sitemap module is not active — search engines cannot discover pages via XML sitemap.';
		}
		if ( ! $module_status['schema'] ) {
			$issues[] = 'Schema module is not active — rich results will be missing.';
		}
		if ( empty( $titles['homepage_title'] ) ) {
			$issues[] = 'Homepage SEO title is empty.';
		}
		if ( empty( $titles['homepage_description'] ) ) {
			$issues[] = 'Homepage meta description is empty.';
		}

		return [
			'active'   => true,
			'version'  => $version,
			'modules'  => $module_status,
			'settings' => $settings,
			'issues'   => $issues,
		];
	}
}
