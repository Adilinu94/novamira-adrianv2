<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Manifest — parses and normalises Template Kit manifests.
 *
 * Supports two wire formats:
 *
 * FORMAT_ENHANCED (Novamira extended format)
 *   Identified by: absence of "manifest_version" key.
 *   Templates carry inline `content` arrays — no external files needed.
 *   Full support for plugins, menus, settings, design_system, media, fonts, cleanup.
 *
 * FORMAT_ELEMENTOR (Elementor / Envato kit format)
 *   Identified by: presence of "manifest_version" key.
 *   Templates carry a `source` path pointing to a separate JSON file.
 *   Caller must pre-load template content and pass it as $template_contents.
 *   Plugin slugs are derived from the `file` field (e.g. "elementskit-lite/…" → "elementskit-lite").
 *   Images listed in `images[]` carry `thumbnail_url` for the demo site.
 *
 * @since 1.7.0
 */
class Kit_Manifest {

	const FORMAT_ENHANCED  = 'enhanced';
	const FORMAT_ELEMENTOR = 'elementor';

	/** @var array Parsed raw manifest data. */
	private array $data;

	/** @var string FORMAT_ENHANCED or FORMAT_ELEMENTOR. */
	private string $format;

	/**
	 * @param string $manifest_json     Raw JSON string of the manifest file.
	 * @param array  $template_contents For FORMAT_ELEMENTOR only: map of
	 *                                  { source_path => content_array }, e.g.
	 *                                  { "templates/home.json" => [...] }.
	 *                                  Ignored for FORMAT_ENHANCED.
	 * @throws \InvalidArgumentException On JSON parse failure.
	 */
	public function __construct( string $manifest_json, array $template_contents = [] ) {
		$data = json_decode( $manifest_json, true );
		if ( ! is_array( $data ) ) {
			throw new \InvalidArgumentException( 'manifest_json is not valid JSON.' );
		}

		$this->data   = $data;
		$this->format = isset( $data['manifest_version'] ) ? self::FORMAT_ELEMENTOR : self::FORMAT_ENHANCED;

		if ( self::FORMAT_ELEMENTOR === $this->format && ! empty( $template_contents ) ) {
			$this->data['_template_contents'] = $template_contents;
		}
	}

	// -------------------------------------------------------------------------
	// Identity
	// -------------------------------------------------------------------------

	public function get_format(): string {
		return $this->format;
	}

	public function get_kit_name(): string {
		if ( self::FORMAT_ELEMENTOR === $this->format ) {
			return $this->data['title'] ?? 'Unnamed Kit';
		}
		return $this->data['kit_name'] ?? $this->data['title'] ?? 'Unnamed Kit';
	}

	public function get_kit_version(): string {
		return $this->data['kit_version'] ?? $this->data['version'] ?? '1.0';
	}

	// -------------------------------------------------------------------------
	// Templates
	// -------------------------------------------------------------------------

	/**
	 * Return all templates as a normalised list.
	 *
	 * Each entry:
	 * [
	 *   'id'            => string   (slug, e.g. "homepage")
	 *   'title'         => string
	 *   'type'          => string   ('page','section-header','section-footer','section','global-styles')
	 *   'post_type'     => string   ('page','elementor-hf','elementor_library')
	 *   'content'       => array    (Elementor element tree)
	 *   'page_settings' => array    (kit page_settings / Elementor globals — global-styles template only)
	 *   'conditions'    => array
	 *   'seo'           => array|null
	 * ]
	 *
	 * @return array[]
	 */
	public function get_templates(): array {
		if ( self::FORMAT_ELEMENTOR === $this->format ) {
			return $this->normalize_elementor_templates();
		}
		return $this->normalize_enhanced_templates();
	}

	private function normalize_elementor_templates(): array {
		$raw      = $this->data['templates'] ?? [];
		$contents = $this->data['_template_contents'] ?? [];
		$out      = [];

		foreach ( $raw as $tpl ) {
			$source       = $tpl['source'] ?? '';
			$meta_type    = $tpl['metadata']['template_type'] ?? '';
			$type         = $this->derive_type_from_elementor_meta( $tpl['type'] ?? 'section', $meta_type );
			$post_type    = $this->type_to_post_type( $type );
			$loaded       = $contents[ $source ] ?? [];
			$page_settings = ( 'global-styles' === $type ) ? ( $loaded['page_settings'] ?? [] ) : [];

			$out[] = [
				'id'            => $this->name_to_slug( $tpl['name'] ?? $source ),
				'title'         => $tpl['name'] ?? $source,
				'type'          => $type,
				'post_type'     => $post_type,
				'content'       => $loaded['content'] ?? [],
				'page_settings' => $page_settings,
				'conditions'    => [],
				'seo'           => null,
			];
		}

		return $out;
	}

	private function normalize_enhanced_templates(): array {
		$raw = $this->data['templates'] ?? [];
		$out = [];

		foreach ( $raw as $tpl ) {
			$id        = $tpl['id'] ?? $this->name_to_slug( $tpl['title'] ?? '' );
			$type      = $tpl['type'] ?? 'page';
			$post_type = $tpl['post_type'] ?? $this->type_to_post_type( $type );

			$out[] = [
				'id'            => $id,
				'title'         => $tpl['title'] ?? $id,
				'type'          => $type,
				'post_type'     => $post_type,
				'content'       => $tpl['content'] ?? [],
				'page_settings' => $tpl['page_settings'] ?? [],
				'conditions'    => $tpl['conditions'] ?? [],
				'seo'           => $tpl['seo'] ?? null,
			];
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Plugins
	// -------------------------------------------------------------------------

	/**
	 * Required plugins — normalized as:
	 * [ 'slug'=>string, 'name'=>string, 'min_version'=>string, 'source'=>'wordpress' ]
	 *
	 * @return array[]
	 */
	public function get_required_plugins(): array {
		if ( self::FORMAT_ELEMENTOR === $this->format ) {
			$out = [];
			foreach ( $this->data['required_plugins'] ?? [] as $p ) {
				$slug = $this->file_to_slug( $p['file'] ?? '' );
				if ( 'elementor' === $slug ) {
					continue; // Elementor itself is a prerequisite, not an extra plugin
				}
				$out[] = [
					'slug'        => $slug,
					'name'        => $p['name'] ?? $slug,
					'min_version' => $p['version'] ?? '',
					'source'      => 'wordpress',
				];
			}
			return $out;
		}

		$out = [];
		foreach ( $this->data['plugins']['required'] ?? [] as $p ) {
			$out[] = [
				'slug'        => $p['slug'] ?? '',
				'name'        => $p['name'] ?? $p['slug'] ?? '',
				'min_version' => $p['min_version'] ?? '',
				'source'      => $p['source'] ?? 'wordpress',
			];
		}
		return $out;
	}

	/**
	 * Premium plugins (can't be auto-installed; must be reported to user).
	 *
	 * @return array[]
	 */
	public function get_premium_plugins(): array {
		// Real kit format has no premium concept; everything is required_plugins.
		if ( self::FORMAT_ELEMENTOR === $this->format ) {
			return [];
		}
		$out = [];
		foreach ( $this->data['plugins']['premium'] ?? [] as $p ) {
			$out[] = [
				'slug' => $p['slug'] ?? '',
				'name' => $p['name'] ?? $p['slug'] ?? '',
				'url'  => $p['url'] ?? '',
			];
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Design system / globals
	// -------------------------------------------------------------------------

	/**
	 * Return Elementor kit page_settings array (colors, typography, etc.).
	 * For FORMAT_ELEMENTOR: comes from the global-styles template's page_settings.
	 * For FORMAT_ENHANCED: from design_system.globals.
	 *
	 * @return array|null  null if no design system in manifest.
	 */
	public function get_design_system(): ?array {
		if ( self::FORMAT_ELEMENTOR === $this->format ) {
			foreach ( $this->get_templates() as $tpl ) {
				if ( 'global-styles' === $tpl['type'] ) {
					return $tpl['page_settings'] ?: null;
				}
			}
			return null;
		}
		return $this->data['design_system']['globals'] ?? null;
	}

	// -------------------------------------------------------------------------
	// Site settings
	// -------------------------------------------------------------------------

	/**
	 * @return array  Keys: site_name, site_tagline, timezone, date_format,
	 *                      permalink_structure, show_on_front, front_page, posts_page.
	 */
	public function get_settings(): array {
		return $this->data['settings'] ?? [];
	}

	// -------------------------------------------------------------------------
	// Menus
	// -------------------------------------------------------------------------

	/** @return array[] */
	public function get_menus(): array {
		return $this->data['menus'] ?? [];
	}

	// -------------------------------------------------------------------------
	// Media
	// -------------------------------------------------------------------------

	/**
	 * Normalised media list.
	 *
	 * FORMAT_ENHANCED: from media.files — each entry has original_url + local_path.
	 * FORMAT_ELEMENTOR: from images[] — each entry has thumbnail_url as the download URL.
	 *
	 * Returns: [ ['ref'=>string, 'url'=>string, 'filename'=>string] ]
	 *
	 * @return array[]
	 */
	public function get_media(): array {
		if ( self::FORMAT_ELEMENTOR === $this->format ) {
			$out = [];
			foreach ( $this->data['images'] ?? [] as $img ) {
				$filename = $img['filename'] ?? '';
				$out[]    = [
					'ref'      => $filename,
					'url'      => $img['thumbnail_url'] ?? '',
					'filename' => $filename,
				];
			}
			return $out;
		}

		$out  = [];
		$base = $this->data['media']['source_base_url'] ?? '';
		foreach ( $this->data['media']['files'] ?? [] as $ref => $file ) {
			$out[] = [
				'ref'      => $ref,
				'url'      => $file['original_url'] ?? $base . ( $file['local_path'] ?? $ref ),
				'filename' => basename( $file['local_path'] ?? $ref ),
			];
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Fonts
	// -------------------------------------------------------------------------

	/** @return array  Keys: google_fonts_to_host (array), strategy (string). */
	public function get_fonts(): array {
		return $this->data['fonts'] ?? [];
	}

	// -------------------------------------------------------------------------
	// Theme
	// -------------------------------------------------------------------------

	/** @return array|null */
	public function get_theme_config(): ?array {
		return $this->data['theme'] ?? null;
	}

	// -------------------------------------------------------------------------
	// Cleanup
	// -------------------------------------------------------------------------

	/** @return array */
	public function get_cleanup_config(): array {
		return $this->data['cleanup'] ?? [];
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate the manifest and return a list of error strings.
	 * Empty list = valid.
	 *
	 * @return string[]
	 */
	public function validate(): array {
		$errors = [];

		if ( '' === $this->get_kit_name() ) {
			$errors[] = 'kit_name / title is required.';
		}

		if ( empty( $this->data['templates'] ) ) {
			$errors[] = 'manifest must contain at least one template.';
		}

		if ( self::FORMAT_ENHANCED === $this->format ) {
			foreach ( $this->data['templates'] ?? [] as $i => $tpl ) {
				if ( empty( $tpl['title'] ) && empty( $tpl['id'] ) ) {
					$errors[] = "templates[$i]: missing title and id.";
				}
			}

			foreach ( $this->data['plugins']['required'] ?? [] as $i => $p ) {
				if ( empty( $p['slug'] ) ) {
					$errors[] = "plugins.required[$i]: missing slug.";
				}
			}

			foreach ( $this->data['menus'] ?? [] as $i => $menu ) {
				if ( empty( $menu['name'] ) ) {
					$errors[] = "menus[$i]: missing name.";
				}
			}
		}

		return $errors;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Derive internal type from real Elementor kit template type + metadata.
	 */
	private function derive_type_from_elementor_meta( string $type, string $meta_type ): string {
		if ( 'global-styles' === $meta_type ) {
			return 'global-styles';
		}
		if ( 'section-header' === $meta_type ) {
			return 'section-header';
		}
		if ( 'section-footer' === $meta_type ) {
			return 'section-footer';
		}
		if ( 'page' === $type || 'single-page' === $meta_type ) {
			return 'page';
		}
		return 'section';
	}

	/**
	 * Map internal type to WordPress post_type.
	 */
	private function type_to_post_type( string $type ): string {
		return match ( $type ) {
			'page'           => 'page',
			'section-header',
			'section-footer' => 'elementor-hf',
			default          => 'elementor_library',
		};
	}

	/**
	 * "My Page Title" → "my-page-title"
	 */
	private function name_to_slug( string $name ): string {
		$slug = strtolower( $name );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( $slug, '-' );
	}

	/**
	 * "elementskit-lite/elementskit-lite.php" → "elementskit-lite"
	 */
	private function file_to_slug( string $file ): string {
		$parts = explode( '/', $file );
		return $parts[0] ?? $file;
	}
}
