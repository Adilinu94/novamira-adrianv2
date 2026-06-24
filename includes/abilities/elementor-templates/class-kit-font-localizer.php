<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Font_Localizer — download Google Fonts and serve them locally (DSGVO-konform).
 *
 * Stores fonts in /wp-content/fonts/{family-slug}/ and saves the generated
 * @font-face CSS to WP option `_novamira_kit_fonts_css_{session_id}`.
 *
 * Fallback: if download fails, the original Google Fonts URL is returned
 * and a warning is appended to the result.
 *
 * @since 1.7.0
 */
class Kit_Font_Localizer {

	const FONTS_SUBDIR = 'fonts'; // relative to WP_CONTENT_DIR

	/**
	 * Localize all fonts from the manifest.
	 *
	 * @param Kit_Manifest $manifest
	 * @param string       $session_id  Used as CSS option suffix.
	 * @param bool         $dry_run
	 * @return array  { localized: [], failed: [], css_option_key: string, total_size_kb: int }
	 */
	public static function localize_all(
		Kit_Manifest $manifest,
		string $session_id = '',
		bool $dry_run = false
	): array {
		$fonts_config = $manifest->get_fonts();
		$font_list    = $fonts_config['google_fonts_to_host'] ?? [];

		if ( empty( $font_list ) ) {
			return [ 'localized' => [], 'failed' => [], 'css_option_key' => '', 'total_size_kb' => 0 ];
		}

		$localized    = [];
		$failed       = [];
		$all_css      = '';
		$total_bytes  = 0;

		foreach ( $font_list as $family ) {
			$result = self::localize_family( $family, $dry_run );
			if ( isset( $result['error'] ) ) {
				$failed[] = [ 'family' => $family, 'error' => $result['error'] ];
			} else {
				$localized[]  = [
					'family' => $family,
					'files'  => $result['files_saved'],
					'size_kb' => (int) ( $result['bytes'] / 1024 ),
				];
				$all_css     .= $result['css'];
				$total_bytes += $result['bytes'];
			}
		}

		$css_option_key = '';
		if ( ! $dry_run && $all_css ) {
			$css_option_key = "_novamira_kit_fonts_css_{$session_id}";
			update_option( $css_option_key, $all_css );
		}

		return [
			'localized'      => $localized,
			'failed'         => $failed,
			'css_option_key' => $css_option_key,
			'total_size_kb'  => (int) ( $total_bytes / 1024 ),
		];
	}

	/**
	 * Localize a single Google Font family.
	 *
	 * @param string $family  e.g. "Inter" or "Playfair Display"
	 * @param bool   $dry_run
	 * @return array  { css, files_saved, bytes } or { error }
	 */
	public static function localize_family( string $family, bool $dry_run = false ): array {
		// Fetch the CSS2 API for all standard weights + italic variants.
		$api_url = 'https://fonts.googleapis.com/css2?family='
			. urlencode( str_replace( ' ', '+', $family ) )
			. ':ital,wght@0,300;0,400;0,600;0,700;1,400&display=swap';

		$response = wp_remote_get( $api_url, [
			'headers' => [ 'User-Agent' => 'Mozilla/5.0 (compatible; Novamira-Crawler)' ],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}

		$css_body = wp_remote_retrieve_body( $response );
		if ( ! $css_body ) {
			return [ 'error' => "Empty response from Google Fonts for '{$family}'." ];
		}

		// Parse all woff2 URLs.
		preg_match_all( '/url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.woff2)\)/', $css_body, $matches );
		$font_urls = array_unique( $matches[1] ?? [] );

		if ( empty( $font_urls ) ) {
			return [ 'error' => "No woff2 URLs found in Google Fonts CSS for '{$family}'." ];
		}

		$family_slug = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '-', $family ) );
		$dir_path    = WP_CONTENT_DIR . '/' . self::FONTS_SUBDIR . '/' . $family_slug . '/';
		$dir_url     = content_url( self::FONTS_SUBDIR . '/' . $family_slug . '/' );

		if ( ! $dry_run ) {
			wp_mkdir_p( $dir_path );
		}

		$files_saved = [];
		$total_bytes = 0;

		foreach ( $font_urls as $font_url ) {
			$filename = basename( parse_url( $font_url, PHP_URL_PATH ) );
			$file_path = $dir_path . $filename;

			if ( ! $dry_run ) {
				if ( ! file_exists( $file_path ) ) {
					$font_response = wp_remote_get( $font_url, [ 'timeout' => 30 ] );
					if ( is_wp_error( $font_response ) ) {
						continue; // Skip this variant, not fatal.
					}
					$font_data = wp_remote_retrieve_body( $font_response );
					if ( $font_data ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						file_put_contents( $file_path, $font_data );
						$total_bytes += strlen( $font_data );
					}
				} else {
					$total_bytes += filesize( $file_path );
				}
			}

			$files_saved[] = $filename;
		}

		// Rewrite Google URLs in CSS to local paths.
		$local_css = preg_replace_callback(
			'/url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.woff2)\)/',
			static function ( array $m ) use ( $dir_url, $family_slug ): string {
				$filename = basename( parse_url( $m[1], PHP_URL_PATH ) );
				return "url({$dir_url}{$filename})";
			},
			$css_body
		);

		return [
			'css'         => $local_css ?? $css_body,
			'files_saved' => $files_saved,
			'bytes'       => $total_bytes,
		];
	}

	// -------------------------------------------------------------------------
	// MCP Ability registration
	// -------------------------------------------------------------------------

	/**
	 * Register the import-kit-fonts MCP ability.
	 *
	 * @since 1.7.0
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/import-kit-fonts',
			[
				'label'       => 'Import Kit Fonts (DSGVO-local)',
				'description' => 'Download Google Fonts declared in the kit manifest and serve them from /wp-content/fonts/ (DSGVO-compliant). Generates @font-face CSS saved as WP option _novamira_kit_fonts_css_{session_id}. Fonts that fail to download fall back to the original Google URL with a warning. Dry-run mode plans the download without writing files.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'manifest' ],
					'properties' => [
						'manifest' => [
							'type'        => 'string',
							'description' => 'Kit manifest JSON.',
						],
						'session_id' => [
							'type'        => 'string',
							'default'     => '',
							'description' => 'Import session ID used as WP option suffix for the generated CSS.',
						],
						'dry_run' => [
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Plan without downloading files.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'localized'      => [ 'type' => 'array' ],
						'failed'         => [ 'type' => 'array' ],
						'css_option_key' => [ 'type' => 'string' ],
						'total_size_kb'  => [ 'type' => 'integer' ],
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

	/**
	 * Execute import-kit-fonts.
	 *
	 * @param array|null $input
	 * @return array
	 */
	public static function execute( ?array $input ): array {
		$manifest_json = $input['manifest'] ?? '';
		$session_id    = trim( (string) ( $input['session_id'] ?? '' ) );
		$dry_run       = (bool) ( $input['dry_run'] ?? false );

		if ( empty( $manifest_json ) ) {
			return [ 'error' => 'manifest is required.' ];
		}

		$manifest = Kit_Manifest::from_json( $manifest_json );
		return self::localize_all( $manifest, $session_id, $dry_run );
	}
}
