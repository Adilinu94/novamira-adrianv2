<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability Categories — registered once on `wp_abilities_api_categories_init`.
 *
 * 13 categories, one per ability sub-domain:
 *   - adrianv2-elementor         (Elementor core operations)
 *   - adrianv2-global-classes    (Global class management)
 *   - adrianv2-v4-management     (V4 migration + components + design system + interactions)
 *   - adrianv2-variables         (Global variables)
 *   - adrianv2-batch             (Batch content operations)
 *   - adrianv2-atomic            (Atomic widgets & layouts)
 *   - adrianv2-media             (Media library operations)
 *   - adrianv2-audit             (Visual & structural audits)
 *   - adrianv2-php-sandbox       (PHP snippet management)
 *   - adrianv2-custom-code       (Custom CSS/JS injection)
 *   - adrianv2-seo               (SEO toolkit)
 *   - adrianv2-a11y              (Accessibility toolkit)
 *   - adrianv2-utilities         (Misc utilities)
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */
add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		$categories = array(
			'novamira-adrianv2'       => array(
				'label'       => __( 'Novamira AdrianV2', 'novamira-adrianv2' ),
				'description' => __( 'Umbrella category for all Novamira AdrianV2 abilities.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-elementor'      => array(
				'label'       => __( 'AdrianV2 — Elementor', 'novamira-adrianv2' ),
				'description' => __( 'Core Elementor operations: read/write pages, clone, duplicate, reorder, patch styles.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-global-classes' => array(
				'label'       => __( 'AdrianV2 — Global Classes', 'novamira-adrianv2' ),
				'description' => __( 'Manage Elementor 4.0 global classes: add, remove, edit variants, apply variables.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'v4' ),
			),
			'adrianv2-v4-management'  => array(
				'label'       => __( 'AdrianV2 — V4 Management', 'novamira-adrianv2' ),
				'description' => __( 'V4 migration (kit convert, foundation), component create/insert/detach, design system import/export, interactions.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'v4' ),
			),
			'adrianv2-variables'      => array(
				'label'       => __( 'AdrianV2 — Variables', 'novamira-adrianv2' ),
				'description' => __( 'Create, update, delete Elementor v4 global variables in the kit.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'v4' ),
			),
			'adrianv2-batch'          => array(
				'label'       => __( 'AdrianV2 — Batch', 'novamira-adrianv2' ),
				'description' => __( 'Batch read of multiple Elementor pages in one call.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-atomic'         => array(
				'label'       => __( 'AdrianV2 — Atomic', 'novamira-adrianv2' ),
				'description' => __( 'Elementor 4.0 atomic widgets, layouts (flexbox, div-block), and version detection.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'v4' ),
			),
			'adrianv2-media'          => array(
				'label'       => __( 'AdrianV2 — Media', 'novamira-adrianv2' ),
				'description' => __( 'Media library: upload, list, edit, delete, batch upload, featured image, usage audit.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-audit'          => array(
				'label'       => __( 'AdrianV2 — Audit', 'novamira-adrianv2' ),
				'description' => __( 'Page, class, responsive, layout, visual-QA, and variable audits.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-php-sandbox'    => array(
				'label'       => __( 'AdrianV2 — PHP Sandbox', 'novamira-adrianv2' ),
				'description' => __( 'PHP snippet authoring: validate, create, update, get, list, delete (always drafts until admin activates).', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-custom-code'    => array(
				'label'       => __( 'AdrianV2 — Custom Code', 'novamira-adrianv2' ),
				'description' => __( 'Custom CSS / JS injection at element, page, or site-wide level.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-wpcode'         => array(
				'label'       => __( 'AdrianV2 — WPCode', 'novamira-adrianv2' ),
				'description' => __( 'Manage WPCode snippets (HTML/CSS/JS/PHP/universal/text/blocks/scss) over MCP without WP-CLI: list, get, create, update, set status, duplicate, delete.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-live-edit'      => array(
				'label'       => __( 'AdrianV2 — Live Edit', 'novamira-adrianv2' ),
				'description' => __( 'Production-safe live edits: assign CSS classes to Elementor containers with concurrency guards and Document API writes; route WPCode snippet updates through the kses bypass + compiled-asset cache purge when the standard `WPCode_Snippet::save()` path is broken.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-seo'            => array(
				'label'       => __( 'AdrianV2 — SEO', 'novamira-adrianv2' ),
				'description' => __( 'SEO audit, keyword extraction, meta-tag generation, JSON-LD schema markup.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-a11y'           => array(
				'label'       => __( 'AdrianV2 — A11Y', 'novamira-adrianv2' ),
				'description' => __( 'WCAG accessibility audit, color-contrast fixer, alt-text auto-suggest.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-design-audit'   => array(
				'label'       => __( 'AdrianV2 — Design Audit', 'novamira-adrianv2' ),
				'description' => __( 'Comprehensive design quality evaluation: layout patterns, column audits, composition rhythm, emphasis drift, section rivalry, surface overuse, separator discipline, component overuse, layout mechanism fit, and native widget opportunity detection. Produces 0-100 holistic design scores with actionable fix recommendations.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-design-utilities' => array(
				'label'       => __( 'AdrianV2 — Design Utilities', 'novamira-adrianv2' ),
				'description' => __( 'Destructive design repair tools: zero container padding, reset negative margins, copy lane settings, enforce boundary coherence, apply text hierarchy, normalize responsive values, sync component variants, normalize section spacing, image-to-background conversion, fix gap rhythm. Plus read-only render context evaluation and style guide extraction.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-templates'      => array(
				'label'       => __( 'AdrianV2 — Templates', 'novamira-adrianv2' ),
				'description' => __( 'Full Elementor template CRUD: get, create, update, delete, restore, empty-trash, duplicate, import, and export templates (elementor_library post type).', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-site-tools'     => array(
				'label'       => __( 'AdrianV2 — Site Tools', 'novamira-adrianv2' ),
				'description' => __( 'Elementor site-level management: clear cache, maintenance mode, experiments, kit settings, active kit switching, and URL replacement.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-pro'            => array(
				'label'       => __( 'AdrianV2 — Pro Features', 'novamira-adrianv2' ),
				'description' => __( 'Elementor Pro features: custom code CRUD, form submissions list/get/delete, and theme builder display conditions management.', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
			'adrianv2-utilities'      => array(
				'label'       => __( 'AdrianV2 — Utilities', 'novamira-adrianv2' ),
				'description' => __( 'Misc utilities (smoke tests, hello-world probes).', 'novamira-adrianv2' ),
				'meta'        => array( 'elementor_version' => 'mixed' ),
			),
		// adrianv2-live-edit registered above to keep related live-edit entries grouped.
		);
		foreach ( $categories as $slug => $args ) {
			wp_register_ability_category( $slug, $args );
		}
	}
);
