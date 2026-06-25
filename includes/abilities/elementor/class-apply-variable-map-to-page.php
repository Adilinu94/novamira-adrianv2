<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Elementor_Document_Saver;
use Novamira\AdrianV2\Helpers\V3_To_V4_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply_Variable_Map_To_Page — replaces inline hex color props with Global Variable references.
 *
 * After convert-page-v3-to-v4 runs, colors land as {$$type:'color', value:'#HEX'}.
 * This ability walks the page tree and replaces any hex value that exists in the
 * variable_map with the correct {$$type:'global-color-variable', value:'e-gv-...'}.
 *
 * Required when the converter's color_index didn't resolve all colors (e.g. when
 * convert-page-v3-to-v4 was called without variable_map, or colors were injected
 * post-conversion via patch-element-styles).
 *
 * @package Novamira_AdrianV2
 * @since   1.5.1
 */
class Apply_Variable_Map_To_Page {

	/**
	 * Register the MCP ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/apply-variable-map-to-page',
			array(
				'label'               => 'Apply Variable Map to Page',
				'description'         => 'Walks the _elementor_data of a V4 page and replaces inline {$$type:\'color\'} values with {$$type:\'global-color-variable\'} references where a matching hex exists in the variable_map. Run after convert-page-v3-to-v4 to bind the Design System. dry_run:true by default.',
				'category'            => 'adrianv2-elementor',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'post_id', 'variable_map' ),
					'properties' => array(
						'post_id'      => array(
							'type'        => 'integer',
							'description' => 'Post ID of the page to update.',
						),
						'variable_map' => array(
							'type'        => 'object',
							'description' => 'Map from V3 color ID to {id, label, type, value} from kit-convert-v3-to-v4. The \'value\' (hex) is used for matching.',
						),
						'dry_run'      => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Preview replacements without persisting. Default: true.',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'dry_run'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'replacements'  => array( 'type' => 'integer', 'description' => 'Number of color references replaced.' ),
						'color_index'   => array( 'type' => 'object', 'description' => 'The hex→id lookup used.' ),
						'errors'        => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array|null $input
	 * @return array|\WP_Error
	 */
	public static function execute( $input = null ) {
		$post_id      = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$variable_map = $input['variable_map'] ?? array();
		$dry_run      = $input['dry_run'] ?? true;

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', 'post_id is required and must be a positive integer.' );
		}

		if ( empty( $variable_map ) || ! is_array( $variable_map ) ) {
			return new \WP_Error( 'invalid_variable_map', 'variable_map must be a non-empty object from kit-convert-v3-to-v4.' );
		}

		// Build the color index: [normalized_hex => 'e-gv-XXXXXXX'].
		$color_index = V3_To_V4_Converter::build_color_index( $variable_map );

		if ( empty( $color_index ) ) {
			return array(
				'success'      => true,
				'dry_run'      => $dry_run,
				'post_id'      => $post_id,
				'replacements' => 0,
				'color_index'  => $color_index,
				'errors'       => array( 'variable_map contained no global-color-variable entries — nothing to replace.' ),
			);
		}

		// Read current elementor data (raw SQL to avoid slashing issues).
		global $wpdb;
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' LIMIT 1",
				$post_id
			)
		);

		if ( null === $raw || '' === $raw ) {
			return new \WP_Error( 'no_elementor_data', "No _elementor_data found for post ID {$post_id}." );
		}

		$tree = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			return new \WP_Error( 'invalid_json', '_elementor_data is not valid JSON.' );
		}

		$replacements = 0;
		$updated_tree = self::walk_tree( $tree, $color_index, $replacements );

		if ( ! $dry_run && $replacements > 0 ) {
			// Persist via safe SQL (no WP slashing).
			$json = wp_json_encode( $updated_tree );
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $json ),
				array( 'post_id' => $post_id, 'meta_key' => '_elementor_data' ),
				array( '%s' ),
				array( '%d', '%s' )
			);

			// Clear Elementor CSS cache.
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			}
			delete_post_meta( $post_id, '_elementor_css' );
		}

		return array(
			'success'      => true,
			'dry_run'      => $dry_run,
			'post_id'      => $post_id,
			'replacements' => $replacements,
			'color_index'  => $color_index,
			'errors'       => array(),
		);
	}

	/**
	 * Recursively walk the element tree and replace inline color values.
	 *
	 * @param array  $tree         Elementor element tree.
	 * @param array  $color_index  [normalized_hex => 'e-gv-XXXXXXX'].
	 * @param int    &$count       Replacement counter (passed by reference).
	 * @return array               Updated tree.
	 */
	private static function walk_tree( array $tree, array $color_index, int &$count ): array {
		foreach ( $tree as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			// Replace in styles.
			if ( ! empty( $element['styles'] ) && is_array( $element['styles'] ) ) {
				$element['styles'] = self::replace_in_styles( $element['styles'], $color_index, $count );
			}

			// Recurse into children.
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::walk_tree( $element['elements'], $color_index, $count );
			}
		}

		return $tree;
	}

	/**
	 * Replace {$$type:'color', value:'#HEX'} with {$$type:'global-color-variable', value:'e-gv-...'}
	 * in a styles object for all breakpoints and all props.
	 *
	 * @param array  $styles       V4 styles object.
	 * @param array  $color_index  [normalized_hex => 'e-gv-XXXXXXX'].
	 * @param int    &$count       Replacement counter.
	 * @return array               Updated styles.
	 */
	private static function replace_in_styles( array $styles, array $color_index, int &$count ): array {
		foreach ( $styles as $breakpoint => &$bp_data ) {
			if ( ! is_array( $bp_data ) || ! isset( $bp_data['props'] ) ) {
				continue;
			}

			foreach ( $bp_data['props'] as $prop_key => &$prop_value ) {
				if ( ! is_array( $prop_value ) ) {
					continue;
				}

				$type  = $prop_value['$$type'] ?? null;
				$value = $prop_value['value'] ?? null;

				if ( 'color' === $type && is_string( $value ) ) {
					$normalized = self::normalize_hex( $value );
					if ( isset( $color_index[ $normalized ] ) ) {
						$prop_value = array(
							'$$type' => 'global-color-variable',
							'value'  => $color_index[ $normalized ],
						);
						++$count;
					}
				}
			}
		}

		return $styles;
	}

	/**
	 * Normalize a hex color string to uppercase 6-char form.
	 *
	 * @param string $color Hex color (3 or 6 chars, with or without #).
	 * @return string Normalized hex (e.g. '#FF0000').
	 */
	private static function normalize_hex( string $color ): string {
		$color = ltrim( strtoupper( $color ), '#' );

		if ( 3 === strlen( $color ) ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}

		// Truncate 8-char RRGGBBAA to 6 chars.
		if ( strlen( $color ) > 6 ) {
			$color = substr( $color, 0, 6 );
		}

		return '#' . $color;
	}
}
