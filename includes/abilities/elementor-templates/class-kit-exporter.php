<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Exporter — export a configured WordPress site as a Novamira Enhanced Kit manifest.
 *
 * Produces the same JSON format that Import_Template_Kit expects, so the output
 * of this ability can be directly fed to import-template-kit on another site.
 *
 * What is exported:
 *   - All Elementor-edited posts/pages (or a filtered subset via post_ids)
 *   - Elementor kit globals (design tokens: colors, typography, shadows, spacing)
 *   - WP site settings (blogname, description, page_on_front, permalink structure)
 *   - Active nav menus and their item targets (converted to page: / url: / home format)
 *   - Active theme slug
 *   - Required plugin list (active plugins that are NOT core WordPress)
 *   - Media file references extracted from _elementor_data image URLs
 *   - Google Fonts referenced in elementor data
 *
 * What is NOT exported:
 *   - Actual media file contents (only URLs/refs so import-kit-media can download them)
 *   - Actual font files (only family names so import-kit-fonts can download them)
 *   - Plugin files (only slug + version for import-kit-plugins to install)
 *   - DB user data, orders, comments, or any sensitive site content
 *
 * @since 1.7.0
 */
class Kit_Exporter {

	/** Plugins that are always present and should never appear in required_plugins. */
	const CORE_PLUGINS = [
		'elementor/elementor.php',
		'elementor-pro/elementor-pro.php',
	];

	// -------------------------------------------------------------------------
	// MCP Ability registration
	// -------------------------------------------------------------------------

	/**
	 * Register the export-kit MCP ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/export-kit',
			[
				'label'       => 'Export Site as Template Kit',
				'description' => 'Export the current WordPress site (Elementor pages + kit globals + menus + site settings + plugin requirements) as a Novamira Enhanced Kit manifest JSON. The output can be directly imported on another site using import-template-kit. Optionally filter to specific post IDs. Dry-run returns the manifest without saving it as an option.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'properties' => [
						'kit_name' => [
							'type'        => 'string',
							'description' => 'Name for the exported kit. Defaults to the site blogname.',
						],
						'kit_version' => [
							'type'        => 'string',
							'default'     => '1.0',
							'description' => 'Semantic version string for the kit (e.g. "1.0", "2.1.0").',
						],
						'post_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => 'Limit export to these specific post IDs. Exports all Elementor pages if omitted.',
						],
						'include_menus' => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Include active nav menus in the export.',
						],
						'include_plugins' => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Include active plugin requirements in the export.',
						],
						'include_media' => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Include media file references extracted from Elementor data.',
						],
						'save_as_option' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Persist the manifest JSON as WP option _novamira_last_kit_export. Useful for retrieval via execute-php.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'        => [ 'type' => 'boolean' ],
						'manifest'       => [ 'type' => 'string', 'description' => 'The full Novamira Enhanced Kit manifest JSON.' ],
						'summary'        => [
							'type'       => 'object',
							'properties' => [
								'kit_name'        => [ 'type' => 'string' ],
								'kit_version'     => [ 'type' => 'string' ],
								'pages_exported'  => [ 'type' => 'integer' ],
								'menus_exported'  => [ 'type' => 'integer' ],
								'plugins_listed'  => [ 'type' => 'integer' ],
								'media_refs'      => [ 'type' => 'integer' ],
								'google_fonts'    => [ 'type' => 'integer' ],
							],
						],
						'warnings'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Execute
	// -------------------------------------------------------------------------

	/**
	 * Execute the export-kit ability.
	 *
	 * @param array|null $input
	 * @return array
	 */
	public static function execute( ?array $input ): array {
		$input = $input ?? [];

		$kit_name       = trim( (string) ( $input['kit_name'] ?? get_option( 'blogname', 'My Kit' ) ) );
		$kit_version    = trim( (string) ( $input['kit_version'] ?? '1.0' ) );
		$post_ids       = ! empty( $input['post_ids'] ) ? array_map( 'intval', (array) $input['post_ids'] ) : null;
		$inc_menus      = (bool) ( $input['include_menus'] ?? true );
		$inc_plugins    = (bool) ( $input['include_plugins'] ?? true );
		$inc_media      = (bool) ( $input['include_media'] ?? true );
		$save_as_option = (bool) ( $input['save_as_option'] ?? false );

		$warnings = [];

		// 1. Collect Elementor-edited posts.
		$templates = self::build_templates( $post_ids, $warnings );

		// 2. Design system (Elementor kit globals).
		$design_system = self::build_design_system( $warnings );

		// 3. Site settings.
		$settings = self::build_site_settings();

		// 4. Nav menus.
		$menus = $inc_menus ? self::build_menus( $templates, $warnings ) : [];

		// 5. Active plugins.
		$plugins_section = $inc_plugins ? self::build_plugins( $warnings ) : [ 'required' => [], 'premium' => [] ];

		// 6. Media references.
		$media_section = $inc_media ? self::build_media( $templates, $warnings ) : [ 'source_base_url' => '', 'files' => [] ];

		// 7. Google Fonts.
		$fonts_section = self::build_fonts( $templates );

		// 8. Active theme.
		$theme_config = [
			'stylesheet'   => get_option( 'stylesheet' ),
			'template'     => get_option( 'template' ),
		];

		// Assemble manifest.
		$manifest = [
			'kit_name'        => $kit_name,
			'kit_version'     => $kit_version,
			'exported_at'     => gmdate( 'c' ),
			'exported_from'   => get_option( 'siteurl' ),
			'design_system'   => $design_system,
			'settings'        => $settings,
			'templates'       => $templates,
			'menus'           => $menus,
			'plugins'         => $plugins_section,
			'media'           => $media_section,
			'fonts'           => $fonts_section,
			'theme'           => $theme_config,
		];

		$json = wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		if ( ! $json ) {
			return [ 'success' => false, 'error' => 'Failed to JSON-encode manifest.' ];
		}

		if ( $save_as_option ) {
			update_option( '_novamira_last_kit_export', $json );
		}

		return [
			'success'  => true,
			'manifest' => $json,
			'summary'  => [
				'kit_name'       => $kit_name,
				'kit_version'    => $kit_version,
				'pages_exported' => count( $templates ),
				'menus_exported' => count( $menus ),
				'plugins_listed' => count( $plugins_section['required'] ?? [] ),
				'media_refs'     => count( $media_section['files'] ?? [] ),
				'google_fonts'   => count( $fonts_section['google_fonts_to_host'] ?? [] ),
			],
			'warnings' => $warnings,
		];
	}

	// -------------------------------------------------------------------------
	// Builders
	// -------------------------------------------------------------------------

	/**
	 * Build the templates section from Elementor-edited posts.
	 *
	 * @param int[]|null $post_ids  null = all Elementor posts.
	 * @param array      $warnings  Mutable warnings list.
	 * @return array[]
	 */
	private static function build_templates( ?array $post_ids, array &$warnings ): array {
		if ( $post_ids !== null ) {
			$query_args = [
				'post__in'       => $post_ids,
				'post_type'      => 'any',
				'post_status'    => 'any',
				'posts_per_page' => count( $post_ids ),
			];
		} else {
			$query_args = [
				'meta_key'       => '_elementor_edit_mode',
				'meta_value'     => 'builder',
				'post_type'      => [ 'page', 'elementor_library', 'elementor-hf' ],
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => 200,
			];
		}

		$posts     = get_posts( $query_args );
		$templates = [];

		foreach ( $posts as $post ) {
			$elementor_data = \Novamira\AdrianV2\Helpers\Guards::get_elementor_data( $post->ID );

			if ( false === $elementor_data ) {
				$warnings[] = "Post #{$post->ID} '{$post->post_title}' has no Elementor data — skipped.";
				continue;
			}

			// Build a stable template ref from slug, falling back to ID.
			$ref       = sanitize_key( $post->post_name ?: "post_{$post->ID}" );
			$post_type = $post->post_type;

			// Detect type: HFE header/footer, Elementor template, or plain page.
			$type = 'page';
			if ( 'elementor-hf' === $post_type ) {
				$conditions = (array) get_post_meta( $post->ID, '_elementor_conditions', true );
				if ( ! empty( $conditions ) ) {
					$type = self::guess_hfe_type( $conditions );
				} else {
					$type = 'section-header';
				}
			} elseif ( 'elementor_library' === $post_type ) {
				$type = get_post_meta( $post->ID, '_elementor_template_type', true ) ?: 'section';
			}

			// SEO meta (Yoast).
			$seo = [];
			if ( defined( 'WPSEO_VERSION' ) ) {
				$seo['yoast_title']       = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
				$seo['yoast_description'] = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			}
			if ( defined( 'RANK_MATH_VERSION' ) ) {
				$seo['rankmath_title']       = get_post_meta( $post->ID, 'rank_math_title', true );
				$seo['rankmath_description'] = get_post_meta( $post->ID, 'rank_math_description', true );
			}

			$templates[] = [
				'id'            => $ref,
				'source_post_id' => $post->ID,
				'title'         => $post->post_title,
				'slug'          => $post->post_name,
				'post_type'     => $post_type,
				'post_status'   => $post->post_status,
				'type'          => $type,
				'content'       => $elementor_data,
				'page_settings' => [],
				'conditions'    => $type !== 'page' ? [ 'include/general' ] : [],
				'seo'           => ! empty( array_filter( $seo ) ) ? $seo : null,
			];
		}

		return $templates;
	}

	/**
	 * Build design_system section from the Elementor kit post globals.
	 *
	 * @param array $warnings
	 * @return array
	 */
	private static function build_design_system( array &$warnings ): array {
		$globals = [];

		// Find the Elementor kit post.
		$kit_post = self::get_elementor_kit_post();

		if ( ! $kit_post ) {
			$warnings[] = 'No Elementor kit post found — design_system.globals will be empty.';
			return [ 'globals' => [] ];
		}

		$kit_data = \Novamira\AdrianV2\Helpers\Guards::get_elementor_data( $kit_post->ID );
		if ( ! $kit_data ) {
			// Try page_settings instead (kit globals live there).
			$page_settings = get_post_meta( $kit_post->ID, '_elementor_page_settings', true );
			if ( is_array( $page_settings ) ) {
				$globals = self::extract_globals_from_page_settings( $page_settings );
			}
		}

		return [ 'globals' => $globals ];
	}

	/**
	 * Build site settings section.
	 *
	 * @return array
	 */
	private static function build_site_settings(): array {
		return [
			'blogname'            => get_option( 'blogname' ),
			'blogdescription'     => get_option( 'blogdescription' ),
			'show_on_front'       => get_option( 'show_on_front' ),
			'permalink_structure' => get_option( 'permalink_structure' ),
			'page_on_front_ref'   => self::resolve_page_ref( (int) get_option( 'page_on_front' ) ),
			'page_for_posts_ref'  => self::resolve_page_ref( (int) get_option( 'page_for_posts' ) ),
		];
	}

	/**
	 * Build menus section by reading all registered nav menus and their items.
	 *
	 * @param array[] $templates  Already-built template list (for ref lookup).
	 * @param array   $warnings
	 * @return array[]
	 */
	private static function build_menus( array $templates, array &$warnings ): array {
		$locations = get_theme_mod( 'nav_menu_locations', [] );
		if ( ! is_array( $locations ) || empty( $locations ) ) {
			return [];
		}

		// Build a reverse map: post_id → template_ref (for converting page links).
		$post_to_ref = [];
		foreach ( $templates as $tpl ) {
			if ( ! empty( $tpl['source_post_id'] ) ) {
				$post_to_ref[ (int) $tpl['source_post_id'] ] = $tpl['id'];
			}
		}

		$menus = [];

		foreach ( $locations as $location => $menu_id ) {
			if ( ! $menu_id ) {
				continue;
			}

			$menu_obj = wp_get_nav_menu_object( (int) $menu_id );
			if ( ! $menu_obj ) {
				continue;
			}

			$items      = wp_get_nav_menu_items( (int) $menu_id );
			$menu_items = [];

			if ( is_array( $items ) ) {
				// Build parent map for nesting.
				$id_to_idx = [];
				foreach ( $items as $i => $item ) {
					$id_to_idx[ $item->ID ] = $i;
				}

				foreach ( $items as $item ) {
					$target = self::item_to_target( $item, $post_to_ref );
					$menu_items[] = [
						'title'     => $item->title,
						'target'    => $target,
						'parent'    => $item->menu_item_parent ? (int) $item->menu_item_parent : null,
						'classes'   => implode( ' ', (array) $item->classes ),
						'attr_title' => $item->attr_title,
					];
				}
			}

			$menus[] = [
				'name'     => $menu_obj->name,
				'slug'     => $menu_obj->slug,
				'location' => $location,
				'items'    => $menu_items,
			];
		}

		return $menus;
	}

	/**
	 * Build plugins section from currently active plugins.
	 *
	 * @param array $warnings
	 * @return array  { required: [], premium: [] }
	 */
	private static function build_plugins( array &$warnings ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', [] );
		$required       = [];
		$premium        = [];

		foreach ( $active_plugins as $plugin_file ) {
			if ( in_array( $plugin_file, self::CORE_PLUGINS, true ) ) {
				continue;
			}

			$meta    = $all_plugins[ $plugin_file ] ?? [];
			$slug    = explode( '/', $plugin_file )[0];
			$version = $meta['Version'] ?? '';

			$entry = [
				'slug'        => $slug,
				'name'        => $meta['Name'] ?? $slug,
				'version'     => $version,
				'min_version' => $version,
				'plugin_file' => $plugin_file,
				'source'      => 'wordpress',  // assume .org; user can correct for premium plugins
			];

			$required[] = $entry;
		}

		return [ 'required' => $required, 'premium' => $premium ];
	}

	/**
	 * Build media references by scanning Elementor data for image URLs.
	 *
	 * @param array[] $templates
	 * @param array   $warnings
	 * @return array  { source_base_url: string, files: { ref => { original_url, local_path } } }
	 */
	private static function build_media( array $templates, array &$warnings ): array {
		$uploads_url  = trailingslashit( wp_upload_dir()['baseurl'] );
		$files        = [];
		$seen_urls    = [];

		foreach ( $templates as $tpl ) {
			self::scan_for_images( $tpl['content'] ?? [], $uploads_url, $files, $seen_urls );
		}

		return [
			'source_base_url' => $uploads_url,
			'files'           => $files,
		];
	}

	/**
	 * Build fonts section by extracting Google Fonts family names from Elementor data.
	 *
	 * @param array[] $templates
	 * @return array  { google_fonts_to_host: [], strategy: 'download' }
	 */
	private static function build_fonts( array $templates ): array {
		$families = [];

		foreach ( $templates as $tpl ) {
			self::scan_for_google_fonts( $tpl['content'] ?? [], $families );
		}

		return [
			'google_fonts_to_host' => array_values( array_unique( $families ) ),
			'strategy'             => 'download',
		];
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Find the active Elementor kit post (elementor_kit post type).
	 *
	 * @return \WP_Post|null
	 */
	private static function get_elementor_kit_post(): ?\WP_Post {
		$active_kit_id = get_option( 'elementor_active_kit' );
		if ( $active_kit_id ) {
			$post = get_post( (int) $active_kit_id );
			if ( $post && 'elementor_library' === $post->post_type ) {
				return $post;
			}
		}

		// Fallback: query by post type.
		$kits = get_posts( [
			'post_type'      => 'elementor_library',
			'meta_key'       => '_elementor_template_type',
			'meta_value'     => 'kit',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
		] );

		return $kits[0] ?? null;
	}

	/**
	 * Extract color / typography globals from an Elementor page_settings array.
	 *
	 * @param array $page_settings
	 * @return array
	 */
	private static function extract_globals_from_page_settings( array $page_settings ): array {
		$globals = [];

		// Elementor stores globals as system_colors / system_typography arrays.
		$color_keys = [ 'system_colors', 'custom_colors' ];
		$typo_keys  = [ 'system_typography', 'custom_typography' ];

		foreach ( $color_keys as $key ) {
			if ( ! empty( $page_settings[ $key ] ) ) {
				foreach ( $page_settings[ $key ] as $entry ) {
					$id    = $entry['_id'] ?? $entry['id'] ?? '';
					$label = $entry['title'] ?? '';
					$color = $entry['color'] ?? '';
					if ( $id && $color ) {
						$globals[ $id ] = [
							'id'    => $id,
							'label' => $label,
							'type'  => 'color',
							'value' => $color,
						];
					}
				}
			}
		}

		foreach ( $typo_keys as $key ) {
			if ( ! empty( $page_settings[ $key ] ) ) {
				foreach ( $page_settings[ $key ] as $entry ) {
					$id    = $entry['_id'] ?? $entry['id'] ?? '';
					$label = $entry['title'] ?? '';
					if ( $id ) {
						$globals[ $id ] = [
							'id'    => $id,
							'label' => $label,
							'type'  => 'typography',
							'value' => $entry['typography_typography'] ?? [],
						];
					}
				}
			}
		}

		return $globals;
	}

	/**
	 * Resolve a post ID to a template ref string (for page_on_front_ref etc).
	 *
	 * @param int $post_id
	 * @return string  e.g. "homepage" or ""
	 */
	private static function resolve_page_ref( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		return sanitize_key( $post->post_name ?: "post_{$post_id}" );
	}

	/**
	 * Convert a WP_Post nav menu item to a target string (page:ref, url:href, home).
	 *
	 * @param \WP_Post $item
	 * @param array    $post_to_ref
	 * @return string
	 */
	private static function item_to_target( \WP_Post $item, array $post_to_ref ): string {
		$type    = $item->type ?? 'custom';
		$obj_id  = (int) ( $item->object_id ?? 0 );
		$url     = $item->url ?? '';

		if ( 'post_type' === $type && $obj_id > 0 ) {
			$ref = $post_to_ref[ $obj_id ] ?? null;
			if ( $ref ) {
				return "page:{$ref}";
			}
			// Post exists but wasn't exported (not Elementor-edited).
			$post = get_post( $obj_id );
			if ( $post ) {
				return 'page:' . sanitize_key( $post->post_name ?: "post_{$obj_id}" );
			}
		}

		if ( 'taxonomy' === $type && $obj_id > 0 ) {
			$term = get_term( $obj_id );
			if ( $term && ! is_wp_error( $term ) ) {
				return "category:{$term->slug}";
			}
		}

		if ( home_url( '/' ) === trailingslashit( $url ) ) {
			return 'home';
		}

		return "url:{$url}";
	}

	/**
	 * Guess the HFE type from conditions array.
	 *
	 * @param array $conditions
	 * @return string
	 */
	private static function guess_hfe_type( array $conditions ): string {
		$str = implode( ' ', $conditions );
		if ( str_contains( $str, 'footer' ) ) {
			return 'section-footer';
		}
		return 'section-header';
	}

	/**
	 * Recursively scan Elementor element tree for image URLs in wp-content/uploads.
	 *
	 * @param array  $elements
	 * @param string $uploads_url   Base URL of uploads dir.
	 * @param array  $files         Mutable output map.
	 * @param array  $seen_urls     Deduplication set.
	 */
	private static function scan_for_images(
		array $elements,
		string $uploads_url,
		array &$files,
		array &$seen_urls
	): void {
		foreach ( $elements as $el ) {
			$settings = $el['settings'] ?? [];

			// Look for image-type settings with a URL.
			foreach ( $settings as $key => $value ) {
				if ( is_array( $value ) && isset( $value['url'] ) && is_string( $value['url'] ) ) {
					$url = $value['url'];
					if ( str_starts_with( $url, $uploads_url ) && ! isset( $seen_urls[ $url ] ) ) {
						$seen_urls[ $url ] = true;
						$local_path        = str_replace( $uploads_url, '', $url );
						$ref               = sanitize_key( pathinfo( $local_path, PATHINFO_FILENAME ) );
						$files[ $ref ]     = [
							'original_url' => $url,
							'local_path'   => $local_path,
						];
					}
				}
			}

			// Also search nested serialised strings for media URLs.
			if ( isset( $settings['background_image']['url'] ) ) {
				$url = $settings['background_image']['url'];
				if ( is_string( $url ) && str_starts_with( $url, $uploads_url ) && ! isset( $seen_urls[ $url ] ) ) {
					$seen_urls[ $url ] = true;
					$local_path        = str_replace( $uploads_url, '', $url );
					$ref               = sanitize_key( pathinfo( $local_path, PATHINFO_FILENAME ) );
					$files[ $ref ]     = [
						'original_url' => $url,
						'local_path'   => $local_path,
					];
				}
			}

			// Recurse into children.
			if ( ! empty( $el['elements'] ) ) {
				self::scan_for_images( $el['elements'], $uploads_url, $files, $seen_urls );
			}
		}
	}

	/**
	 * Recursively scan Elementor element tree for Google Fonts family names.
	 *
	 * @param array  $elements
	 * @param array  $families  Mutable output list.
	 */
	private static function scan_for_google_fonts( array $elements, array &$families ): void {
		foreach ( $elements as $el ) {
			$settings = $el['settings'] ?? [];

			// Typography settings embed font_family as a string.
			foreach ( $settings as $key => $value ) {
				if ( str_ends_with( $key, '_font_family' ) && is_string( $value ) && $value !== '' ) {
					$families[] = $value;
				}
				if ( is_array( $value ) && isset( $value['font_family'] ) && is_string( $value['font_family'] ) ) {
					$families[] = $value['font_family'];
				}
			}

			// Recurse.
			if ( ! empty( $el['elements'] ) ) {
				self::scan_for_google_fonts( $el['elements'], $families );
			}
		}
	}
}
