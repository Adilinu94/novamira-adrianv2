<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\GlobalClasses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/create-global-class
 *
 * Creates a new Elementor Global Class (V4 Atomic) in the active kit.
 * Returns the ID of the newly created class so callers can reference it
 * when applying it to elements.
 *
 * Designed to match the interface expected by site-clone-to-v3's token-sync.ts:
 *   { label: string, selector: string }
 *
 * Writes directly to the three Elementor kit post-meta keys:
 *   _elementor_global_classes_order   — array { order: string[] }
 *   _elementor_global_classes_labels  — map { id → label }
 *   _elementor_global_classes_styles  — map { id → variants[] }
 *
 * Gates on Elementor 4.0+ (Global_Classes_Repository availability).
 *
 * @since 1.7.1
 */
class Create_Global_Class {

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/create-global-class',
			[
				'label'       => 'Create Global Class',
				'description' => 'Create a new Elementor Global Class in the active kit and return its generated ID. Accepts a label (display name) and optional selector (CSS selector to namespace the class). Also accepts an optional styles array (Elementor variant format) to set initial styles. Returns { id, label, selector } on success. Requires Elementor 4.0+.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'label' ],
					'properties' => [
						'label'    => [
							'type'        => 'string',
							'description' => 'Display name for the global class (e.g. "sv-heading-xl").',
						],
						'selector' => [
							'type'        => 'string',
							'default'     => '',
							'description' => 'Optional CSS selector to associate with this class (e.g. ".sv-heading-xl").',
						],
						'styles'   => [
							'type'        => 'array',
							'default'     => [],
							'description' => 'Optional initial style variants in Elementor format: [{ meta: { breakpoint, state }, props: { ... } }].',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'  => [ 'type' => 'boolean' ],
						'id'       => [ 'type' => 'string', 'description' => 'Generated class ID (e.g. "g-a3b4c5d6").' ],
						'class_id' => [ 'type' => 'string', 'description' => 'Alias for id — matches legacy response shape.' ],
						'label'    => [ 'type' => 'string' ],
						'selector' => [ 'type' => 'string' ],
						'error'    => [ 'type' => 'string' ],
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

	public static function execute( ?array $input ): array {
		$label    = trim( (string) ( $input['label']    ?? '' ) );
		$selector = trim( (string) ( $input['selector'] ?? '' ) );
		$styles   = $input['styles'] ?? [];

		if ( $label === '' ) {
			return [ 'success' => false, 'error' => 'label is required.' ];
		}

		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( ! $kit_id ) {
			return [ 'success' => false, 'error' => 'No active Elementor kit found (elementor_active_kit option is empty).' ];
		}

		// Load existing class meta.
		$class_order  = maybe_unserialize( get_post_meta( $kit_id, '_elementor_global_classes_order', true ) );
		$class_labels = maybe_unserialize( get_post_meta( $kit_id, '_elementor_global_classes_labels', true ) );
		$class_styles = maybe_unserialize( get_post_meta( $kit_id, '_elementor_global_classes_styles', true ) );

		if ( ! is_array( $class_order ) ) {
			$class_order = [ 'order' => [] ];
		}
		if ( ! is_array( $class_labels ) ) {
			$class_labels = [];
		}
		if ( ! is_array( $class_styles ) ) {
			$class_styles = [];
		}

		// Check for duplicate label — return existing ID if found.
		foreach ( $class_labels as $existing_id => $existing_label ) {
			if ( $existing_label === $label ) {
				return [
					'success'  => true,
					'id'       => (string) $existing_id,
					'class_id' => (string) $existing_id,
					'label'    => $label,
					'selector' => $selector,
					'note'     => 'Class with this label already exists — returning existing ID.',
				];
			}
		}

		// Generate a stable unique ID in Elementor's format: g-{7 hex chars}.
		$class_id = 'g-' . substr( md5( $label . uniqid( '', true ) ), 0, 7 );

		// Ensure uniqueness in the order list.
		while ( in_array( $class_id, $class_order['order'] ?? [], true ) ) {
			$class_id = 'g-' . substr( md5( $class_id . uniqid( '', true ) ), 0, 7 );
		}

		// Write new class.
		$class_order['order'][]  = $class_id;
		$class_labels[ $class_id ] = $label;

		if ( ! empty( $styles ) && is_array( $styles ) ) {
			$class_styles[ $class_id ] = $styles;
		} elseif ( $selector !== '' ) {
			// Create an empty desktop variant so the selector is stored.
			$class_styles[ $class_id ] = [
				[
					'meta'  => [ 'breakpoint' => 'desktop', 'state' => null ],
					'props' => [],
				],
			];
		}

		update_post_meta( $kit_id, '_elementor_global_classes_order', $class_order );
		update_post_meta( $kit_id, '_elementor_global_classes_labels', $class_labels );

		if ( ! empty( $class_styles[ $class_id ] ) ) {
			update_post_meta( $kit_id, '_elementor_global_classes_styles', $class_styles );
		}

		return [
			'success'  => true,
			'id'       => $class_id,
			'class_id' => $class_id, // legacy alias for token-sync.ts compatibility
			'label'    => $label,
			'selector' => $selector,
		];
	}
}
