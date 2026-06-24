<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/aioseo-check-setup
 *
 * Read-only probe of the All in One SEO environment.
 * Reports: active, version, enabled features, sitemap, social profiles,
 * robots settings, and detected configuration issues.
 *
 * @since 1.8.0
 */
final class Aioseo_Check_Setup {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/aioseo-check-setup',
			[
				'label'       => 'Check All in One SEO Setup',
				'description' => 'Reports the AIOSEO environment: active state, version, enabled features (sitemap, social meta, breadcrumbs, local business, redirects), title/meta templates, and detected configuration issues. Read-only.',
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
						'features' => [ 'type' => 'object' ],
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
		$active  = defined( 'AIOSEO_VERSION' );
		$version = $active ? AIOSEO_VERSION : null;
		$issues  = [];

		if ( ! $active ) {
			return [
				'active'   => false,
				'version'  => null,
				'features' => [],
				'settings' => [],
				'issues'   => [ 'All in One SEO is not active.' ],
			];
		}

		// AIOSEO stores its main config in the aioseo_options option as JSON.
		$opts_raw = get_option( 'aioseo_options', '{}' );
		$opts     = is_array( $opts_raw ) ? $opts_raw : (array) json_decode( $opts_raw, true );

		$general  = $opts['searchAppearance']['global'] ?? [];
		$social   = $opts['social'] ?? [];
		$sitemap  = get_option( 'aioseo_options_sitemap', '{}' );
		$sitemap  = is_array( $sitemap ) ? $sitemap : (array) json_decode( $sitemap, true );

		$features = [
			'sitemap'            => ! empty( $sitemap['general']['enable'] ),
			'video_sitemap'      => ! empty( $sitemap['video']['enable'] ),
			'og_enabled'         => ! empty( $social['facebook']['general']['enable'] ),
			'twitter_enabled'    => ! empty( $social['twitter']['general']['enable'] ),
			'breadcrumbs'        => ! empty( $opts['breadcrumbs']['enable'] ),
			'local_business'     => ! empty( $opts['localBusiness']['enable'] ),
			'redirects'          => ! empty( $opts['redirects']['enable'] ),
			'schema_enabled'     => ! empty( $opts['schema']['enable'] ),
		];

		$settings = [
			'homepage_title'       => $general['siteTitle'] ?? null,
			'homepage_description' => $general['metaDescription'] ?? null,
			'separator'            => $general['separator'] ?? null,
			'robots_noindex'       => ! empty( $general['noindexPaginated'] ),
		];

		// Issues.
		if ( ! $features['sitemap'] ) {
			$issues[] = 'XML Sitemap is disabled — search engines cannot discover pages automatically.';
		}
		if ( ! $features['og_enabled'] ) {
			$issues[] = 'Facebook/OpenGraph meta is disabled — social sharing previews will be missing.';
		}
		if ( empty( $general['siteTitle'] ) ) {
			$issues[] = 'Homepage SEO title is empty.';
		}
		if ( empty( $general['metaDescription'] ) ) {
			$issues[] = 'Homepage meta description is empty.';
		}

		return [
			'active'   => true,
			'version'  => $version,
			'features' => $features,
			'settings' => $settings,
			'issues'   => $issues,
		];
	}
}
