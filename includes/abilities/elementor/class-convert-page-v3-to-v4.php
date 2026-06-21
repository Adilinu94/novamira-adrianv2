<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Conversion_Auditor;
use Novamira\AdrianV2\Helpers\Conversion_AutoFixer;
use Novamira\AdrianV2\Helpers\Elementor_Document_Saver;
use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;
use Novamira\AdrianV2\Helpers\V3_To_V4_Converter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convert_Page_V3_To_V4 — migrates a V3 Elementor page to V4 Atomic structure.
 *
 * Reads _elementor_data from post_id, optionally runs kit-convert-v3-to-v4
 * first for design-system mapping, and delegates tree conversion to
 * V3_To_V4_Converter with variable_map and semantic_classes.
 *
 * Writes via Elementor_Document_Saver::save_data().
 *
 * @package Novamira_AdrianV2
 * @since   1.2.0
 */
class Convert_Page_V3_To_V4 {

	/**
	 * Register the MCP ability.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/convert-page-v3-to-v4',
			array(
				'label'               => 'Convert Page V3 to V4 Atomic',
				'description'         => 'Converts an Elementor V3 page tree into V4 Atomic. Optionally runs kit-convert-v3-to-v4 first for design-system mapping (colors→variables, typography→global classes). Dry-run by default.',
				'category'            => 'novamira-adrianv2',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'                 => array(
							'type'        => 'integer',
							'description' => 'Source page ID to read and convert.',
						),
						'dry_run'                 => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Preview only — returns converted_tree but does not persist.',
						),
						'target_post_id'          => array(
							'type'        => 'integer',
							'default'     => null,
							'description' => 'Write to this post instead of overwriting the source.',
						),
						'unknown_widget_strategy' => array(
							'type'        => 'string',
							'enum'        => array( 'keep_v3', 'skip', 'error' ),
							'default'     => 'keep_v3',
							'description' => 'Strategy for widgets without a V4 atomic equivalent.',
						),
						'run_kit_convert'         => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Run kit-convert-v3-to-v4 first. Creates V4 Variables from V3 colors and Global Classes from typography presets, then applies them during page conversion.',
						),
						'variable_map'            => array(
							'type'        => 'object',
							'default'     => array(),
							'description' => 'Reusable variable_map from an earlier kit-convert-v3-to-v4 call. Used when run_kit_convert=false.',
						),
						'class_map'               => array(
							'type'        => 'object',
							'default'     => array(),
							'description' => 'Reusable class_map from an earlier kit-convert-v3-to-v4 call. Used as semantic class fallback when run_kit_convert=false.',
						),
						'semantic_classes'        => array(
							'type'        => 'object',
							'default'     => array(),
							'description' => 'Optional explicit semantic class groups: {heading:[], body:[], button:[]}.',
						),
						'auto_fix'                 => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Automatically fix audit issues: remove empty containers, generate missing responsive variants, clean orphan styles.',
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			)
		);
	}

	/**
	 * Execute the conversion.
	 *
	 * @param array|null $input
	 * @return array|\WP_Error
	 */
	public static function execute( $input = null ) {
		$post_id         = (int) ( $input['post_id'] ?? 0 );
		$dry_run         = (bool) ( $input['dry_run'] ?? true );
		$target_id       = isset( $input['target_post_id'] ) ? (int) $input['target_post_id'] : null;
		$strategy        = $input['unknown_widget_strategy'] ?? 'keep_v3';
		$run_kit_convert = (bool) ( $input['run_kit_convert'] ?? false );

		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', 'post_id must be a positive integer.' );
		}
		if ( ! in_array( $strategy, array( 'keep_v3', 'skip', 'error' ), true ) ) {
			$strategy = 'keep_v3';
		}

		if ( ! Elementor_Version_Resolver::site_is_v4() ) {
			return new \WP_Error(
				'v4_not_available',
				'Page conversion to V4 requires Elementor 4.0+ (atomic runtime) to be installed on this site.'
			);
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return new \WP_Error( 'no_elementor_data', "No _elementor_data found for post $post_id." );
		}

		$source_tree = is_array( $raw ) ? $raw : json_decode( $raw, true );
		if ( ! is_array( $source_tree ) ) {
			return new \WP_Error( 'invalid_data', 'Could not decode _elementor_data as a JSON array.' );
		}

		// ── Optional: run kit-convert-v3-to-v4 for design-system mapping ──
		$variable_map      = is_array( $input['variable_map'] ?? null ) ? $input['variable_map'] : array();
		$semantic_classes  = array(
			'heading' => array(),
			'body'    => array(),
			'button'  => array(),
		);
		if ( is_array( $input['semantic_classes'] ?? null ) ) {
			$semantic_classes = array_merge( $semantic_classes, $input['semantic_classes'] );
		} elseif ( is_array( $input['class_map'] ?? null ) && ! empty( $input['class_map'] ) ) {
			$class_ids = array_values( array_filter( $input['class_map'], 'is_string' ) );
			$semantic_classes = array(
				'heading' => $class_ids,
				'body'    => $class_ids,
				'button'  => $class_ids,
			);
		}
		$kit_result = null;

		if ( $run_kit_convert ) {
			$kit_result = Kit_Convert_V3_To_V4::execute( array(
				'dry_run' => $dry_run,
			) );

			if ( is_wp_error( $kit_result ) ) {
				$kit_result = null;
			} else {
				$variable_map = $kit_result['variable_map'] ?? array();
				$semantic_classes = self::build_semantic_classes( $kit_result );
			}
		}

		// ── Convert page tree ──
		$stats = array(
			'elements_read'       => 0,
			'converted'           => 0,
			'kept_v3'             => 0,
			'skipped'             => 0,
			'unsupported_widgets' => array(),
		);
		$warnings       = array();
		$converted_tree = V3_To_V4_Converter::convert_elements(
			$source_tree, $strategy, $stats, $warnings,
			$variable_map, $semantic_classes
		);

		if ( 'error' === $strategy && ! empty( $stats['unsupported_widgets'] ) ) {
			return new \WP_Error(
				'unsupported_widgets',
				'Conversion aborted: unsupported widget types found.',
				array( 'widgets' => array_unique( $stats['unsupported_widgets'] ) )
			);
		}

		// ── Post-conversion: audit then optionally auto-fix ──
		$auto_fix = (bool) ( $input['auto_fix'] ?? false );
		$fixes_applied = 0;

		if ( $auto_fix ) {
			$converted_tree = Conversion_AutoFixer::run( $converted_tree, $fixes_applied );

			// Also fix Kit-level style classes referenced by this page.
			$kit_fixes = 0;
			$kit_tree  = Conversion_AutoFixer::fix_kit_styles_for_page( $converted_tree, $kit_fixes );
			if ( null !== $kit_tree ) {
				$kit_id = (int) get_option( 'elementor_active_kit' );
				if ( $kit_id > 0 && ! $dry_run ) {
					update_post_meta( $kit_id, '_elementor_data', wp_slash( wp_json_encode( $kit_tree ) ) );
				}
				$fixes_applied += $kit_fixes;
			}
		}

		// Audit after potential auto-fix to reflect final state.
		$audit_issues = Conversion_Auditor::audit( $converted_tree );
		$audit_warnings = Conversion_Auditor::to_warnings( $audit_issues );

		$result = array(
			'success'         => true,
			'dry_run'         => $dry_run,
			'source_post_id'  => $post_id,
			'target_post_id'  => null,
			'stats'           => $stats,
			'warnings'        => array_merge( $warnings, $audit_warnings ),
			'audit'           => array(
				'total_issues' => count( $audit_issues ),
				'by_severity'  => array(
					'error'   => count( Conversion_Auditor::filter( $audit_issues, null, 'error' ) ),
					'warning' => count( Conversion_Auditor::filter( $audit_issues, null, 'warning' ) ),
					'info'    => count( Conversion_Auditor::filter( $audit_issues, null, 'info' ) ),
				),
				'by_type'      => array(
					'layout'    => count( Conversion_Auditor::filter( $audit_issues, 'layout' ) ),
					'class'     => count( Conversion_Auditor::filter( $audit_issues, 'class' ) ),
					'responsive' => count( Conversion_Auditor::filter( $audit_issues, 'responsive' ) ),
				),
				'issues'        => $audit_issues,
			),
			'auto_fix'        => $auto_fix,
			'fixes_applied'   => $fixes_applied,
			'run_kit_convert' => $run_kit_convert,
		);

		if ( $kit_result !== null ) {
			$result['kit'] = array(
				'variable_map'     => $variable_map,
				'semantic_classes' => $semantic_classes,
				'class_map'        => $kit_result['class_map'] ?? array(),
				'phase_colors'     => $kit_result['phase_colors'] ?? null,
				'phase_classes'    => $kit_result['phase_classes'] ?? null,
			);
		}

		if ( $dry_run ) {
			$result['converted_tree'] = $converted_tree;
			return $result;
		}

		// ── Persist ──
		$write_id                  = $target_id ?? $post_id;
		$result['target_post_id'] = $write_id;

		update_post_meta( $post_id, '_novamira_v3_backup', wp_slash( wp_json_encode( $source_tree ) ) );
		update_post_meta( $write_id, '_elementor_edit_mode', 'builder' );

		$save = Elementor_Document_Saver::save_data( $write_id, $converted_tree );
		if ( ! $save['success'] ) {
			return new \WP_Error( 'save_failed', 'Elementor_Document_Saver::save_data() failed.', $save );
		}

		if ( ! empty( $save['warnings'] ) ) {
			$result['warnings'] = array_merge( $result['warnings'], $save['warnings'] );
		}

		return $result;
	}

	/**
	 * Build semantic class groupings from kit-convert results.
	 *
	 * Analyses phase_classes items' labels and groups global class IDs
	 * into heading, body, and button buckets for intelligent assignment
	 * during page conversion.
	 *
	 * @param array $kit_result Kit_Convert_V3_To_V4 result.
	 * @return array{heading: string[], body: string[], button: string[]}
	 */
	private static function build_semantic_classes( array $kit_result ): array {
		$classes = array(
			'heading' => array(),
			'body'    => array(),
			'button'  => array(),
		);

		$class_map   = $kit_result['class_map'] ?? array();
		$phase_items = $kit_result['phase_classes']['items'] ?? array();

		// Build label → class_id lookup from phase_classes items.
		$label_to_id = array();
		foreach ( $phase_items as $item ) {
			$label = $item['class_label'] ?? '';
			if ( empty( $label ) ) {
				continue;
			}
			// Find the corresponding class_id from class_map.
			$v3_id = $item['v3_id'] ?? '';
			$class_id = $class_map[ $v3_id ] ?? null;
			if ( $class_id !== null ) {
				$label_to_id[ $label ] = $class_id;
			}
		}

		// If label matching fails, fall back to all class_map values.
		if ( empty( $label_to_id ) && ! empty( $class_map ) ) {
			$all_ids = array_values( $class_map );
			return array(
				'heading' => $all_ids,
				'body'    => $all_ids,
				'button'  => $all_ids,
			);
		}

		$heading_patterns = array( 'heading', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'title', 'headline', 'display' );
		$button_patterns  = array( 'button', 'btn', 'cta', 'link' );
		$body_patterns    = array( 'body', 'text', 'paragraph', 'primary', 'secondary', 'caption', 'eyebrow', 'label' );

		foreach ( $label_to_id as $label => $class_id ) {
			$lower = strtolower( $label );

			foreach ( $heading_patterns as $pat ) {
				if ( str_contains( $lower, $pat ) ) {
					$classes['heading'][] = $class_id;
					continue 2;
				}
			}
			foreach ( $button_patterns as $pat ) {
				if ( str_contains( $lower, $pat ) ) {
					$classes['button'][] = $class_id;
					continue 2;
				}
			}
			foreach ( $body_patterns as $pat ) {
				if ( str_contains( $lower, $pat ) ) {
					$classes['body'][] = $class_id;
					continue 2;
				}
			}
			// Unmatched classes go to body as default.
			$classes['body'][] = $class_id;
		}

		return $classes;
	}
}

add_action( 'wp_abilities_api_init', array( Convert_Page_V3_To_V4::class, 'register' ) );
