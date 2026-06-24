<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/yoast-check-setup
 *
 * Read-only probe of the Yoast SEO environment.
 * Reports: active, version, key settings, site-wide title/meta templates,
 * sitemap status, breadcrumbs, open graph, and a list of configuration issues.
 *
 * @since 1.8.0
 */
final class Yoast_Check_Setup {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/yoast-check-setup',
			[
				'label'       => 'Check Yoast SEO Setup',
				'description' => 'Reports the Yoast SEO environment: active state, version, title/meta templates, XML sitemap, breadcrumbs, OpenGraph, robots settings, and detected configuration issues. Read-only.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'                 => 'object',
					'properties'           => [],
					'additionalProperties' => false,
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'active'    => [ 'type' => 'boolean' ],
						'version'   => [ 'type' => [ 'string', 'null' ] ],
						'settings'  => [ 'type' => 'object' ],
						'issues'    => [ 'type' => 'array' ],
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
		$active  = defined( 'WPSEO_VERSION' );
		$version = $active ? WPSEO_VERSION : null;
		$issues  = [];

		if ( ! $active ) {
			return [
				'active'   => false,
				'version'  => null,
				'settings' => [],
				'issues'   => [ 'Yoast SEO is not active.' ],
			];
		}

		$opts    = get_option( 'wpseo', [] );
		$titles  = get_option( 'wpseo_titles', [] );
		$social  = get_option( 'wpseo_social', [] );

		$settings = [
			'title_template_home'   => $titles['title-home-wpseo'] ?? null,
			'metadesc_template_home'=> $titles['metadesc-home-wpseo'] ?? null,
			'title_template_post'   => $titles['title-post'] ?? null,
			'title_template_page'   => $titles['title-page'] ?? null,
			'breadcrumbs_enabled'   => ! empty( $titles['breadcrumbs-enable'] ),
			'og_enabled'            => ! empty( $social['opengraph'] ),
			'twitter_enabled'       => ! empty( $social['twitter'] ),
			'sitemap_enabled'       => class_exists( 'WPSEO_Sitemaps' ),
			'noindex_subpages'      => ! empty( $titles['noindex-subpages-wpseo'] ),
			'separator'             => $titles['separator'] ?? '—',
		];

		// Issues.
		if ( empty( $titles['title-home-wpseo'] ) ) {
			$issues[] = 'Homepage title template is empty.';
		}
		if ( empty( $titles['metadesc-home-wpseo'] ) ) {
			$issues[] = 'Homepage meta description template is empty.';
		}
		if ( empty( $social['opengraph'] ) ) {
			$issues[] = 'OpenGraph is disabled — social sharing previews will be missing.';
		}
		if ( empty( $opts['ms_defaults_disabled'] ) && is_multisite() ) {
			$issues[] = 'Running on multisite — verify network-level Yoast settings.';
		}

		return [
			'active'   => true,
			'version'  => $version,
			'settings' => $settings,
			'issues'   => $issues,
		];
	}
}
