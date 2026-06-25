<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;
use Novamira\AdrianV2\Helpers\V4_Props;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Validate_V4_Tree
 *
 * Ability: `novamira-adrianv2/validate-v4-tree`
 *
 * Server-side V4 element-tree validation.
 *
 * Replaces the pipeline's `validate-v4-tree.js` + `check-v4-requirements.js`
 * because client-side scripts cannot see:
 *   - Elementor experiment flags (atomic.variables_available)
 *   - Whether global classes actually exist in the DB
 *   - Whether atomic widgets are registered at runtime
 *
 * Validates against two modes:
 *   A) pre-build: environment check only (no post_id needed)
 *   B) post-build: environment + tree structure analysis
 *
 * @package Novamira_AdrianV2
 * @since   1.5.0
 */
final class Validate_V4_Tree {

    /** Elementor experiment option prefix. */
    private const EXP_PREFIX = 'elementor_experiment-';

    /** Experiments required for atomic widgets. */
    private const REQUIRED_EXPERIMENTS = [
        'e_atomic_elements'         => 'Atomic Widgets engine (V4)',
        'e_nested_atomic_repeaters' => 'Nested Atomic Repeaters',
    ];

    /** Experiments required for Global Classes in the editor. */
    private const REQUIRED_FOR_GLOBAL_CLASSES = [
        'e_global_styleguide' => 'Global Styleguide (Design Tokens UI)',
    ];

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/validate-v4-tree', [
            'label'       => 'Validate V4 Tree',
            'description' =>
                'Server-side V4 element tree validation. Checks experiment flags '
                . '(atomic.variables_available, atomic.global_classes), validates tree structure, '
                . 'and detects common issues (V3 widgets in V4 tree, missing $$type wrapping, '
                . 'isLocked containers, unknown widgetTypes). '
                . 'Replaces validate-v4-tree.js + check-v4-requirements.js in the pipeline.',
            'category'    => 'adrianv2-elementor',

            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => [ 'integer', 'null' ],
                        'default'     => null,
                        'description' => 'Post ID to validate. Omit to run environment-only check.',
                    ],
                    'mode' => [
                        'type'    => 'string',
                        'enum'    => [ 'environment', 'tree', 'full' ],
                        'default' => 'full',
                        'description' => 'environment = flags only; tree = structure only; full = both.',
                    ],
                    'strict' => [
                        'type'    => 'boolean',
                        'default' => false,
                        'description' => 'If true, warnings are treated as errors.',
                    ],
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'          => [ 'type' => 'boolean' ],
                    'valid'            => [ 'type' => 'boolean', 'description' => 'True when no errors (warnings ignored unless strict=true).' ],
                    'environment'      => [ 'type' => 'object' ],
                    'tree'             => [ 'type' => [ 'object', 'null' ] ],
                    'errors'           => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'warnings'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
                    'pipeline_context' => [ 'type' => 'object', 'description' => 'Values for check-v4-requirements.js compatibility.' ],
                    'summary'          => [ 'type' => 'string' ],
                    'error'            => [ 'type' => 'string' ],
                ],
            ],

            'execute_callback'    => [ self::class, 'execute' ],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    public static function execute( ?array $input = null ): array {
        $input   = $input ?? [];
        $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : null;
        $mode    = in_array( $input['mode'] ?? 'full', [ 'environment', 'tree', 'full' ], true )
                    ? ( $input['mode'] ?? 'full' )
                    : 'full';
        $strict  = (bool) ( $input['strict'] ?? false );

        $errors   = [];
        $warnings = [];

        // ── Environment Check ──────────────────────────────────────────────────
        $environment = null;
        if ( in_array( $mode, [ 'environment', 'full' ], true ) ) {
            $environment = self::check_environment( $errors, $warnings );
        }

        // ── Tree Check ─────────────────────────────────────────────────────────
        $tree = null;
        if ( in_array( $mode, [ 'tree', 'full' ], true ) ) {
            if ( $post_id === null || $post_id <= 0 ) {
                if ( $mode === 'tree' ) {
                    return [ 'success' => false, 'error' => 'post_id is required for mode=tree.' ];
                }
                // mode=full without post_id: skip tree check.
            } else {
                $elements = Guards::get_elementor_data( $post_id );
                if ( $elements === false ) {
                    $errors[] = "No Elementor data for post #{$post_id}.";
                } else {
                    $tree = self::check_tree( $elements, $errors, $warnings );
                }
            }
        }

        // ── Valid = no errors (+ no warnings when strict) ─────────────────────
        $valid = empty( $errors ) && ( ! $strict || empty( $warnings ) );

        // ── Pipeline compatibility context (mirrors check-v4-requirements.js) ──
        $pipeline_context = [
            'atomic'  => [
                'runtime_available'   => $environment['atomic_runtime_available']  ?? false,
                'variables_available' => $environment['variables_available']        ?? false,
                'global_classes'      => $environment['global_classes_active']      ?? false,
            ],
            'elementor_version'   => $environment['elementor_version']  ?? null,
            'tree_valid'          => $tree !== null ? $tree['v4_clean'] : null,
            'v3_widget_count'     => $tree['v3_widget_count']           ?? 0,
            'unwrapped_prop_count'=> $tree['unwrapped_prop_count']      ?? 0,
        ];

        $summary = sprintf(
            'V4 validation (%s): %s. %d error(s), %d warning(s).',
            $mode,
            $valid ? 'PASS' : 'FAIL',
            count( $errors ),
            count( $warnings )
        );

        return [
            'success'          => true,
            'valid'            => $valid,
            'environment'      => $environment,
            'tree'             => $tree,
            'errors'           => $errors,
            'warnings'         => $warnings,
            'pipeline_context' => $pipeline_context,
            'summary'          => $summary,
        ];
    }

    // ── Environment ───────────────────────────────────────────────────────────

    private static function check_environment( array &$errors, array &$warnings ): array {
        $elementor_active  = defined( 'ELEMENTOR_VERSION' );
        $elementor_version = $elementor_active ? ELEMENTOR_VERSION : null;

        // Experiment flags.
        $exp_states          = [];
        $all_required_active = true;

        foreach ( self::REQUIRED_EXPERIMENTS as $key => $label ) {
            $val   = get_option( self::EXP_PREFIX . $key, '' );
            $active = $val === 'active';
            $exp_states[ $key ] = [ 'label' => $label, 'active' => $active, 'raw' => $val ?: 'default' ];
            if ( ! $active ) {
                $all_required_active = false;
                $errors[] = "Experiment '{$key}' ({$label}) is not active. Run ensure-atomic-experiments to fix.";
            }
        }

        // Global Styleguide (needed for Global Classes in editor).
        $styleguide_val    = get_option( self::EXP_PREFIX . 'e_global_styleguide', '' );
        $global_classes_active = $styleguide_val === 'active';
        if ( ! $global_classes_active ) {
            $warnings[] = "Experiment 'e_global_styleguide' (Global Styleguide) is not active — Design Tokens won't appear in the editor.";
        }

        // Atomic runtime.
        $atomic_runtime = false;
        try {
            $atomic_runtime = V4_Props::is_atomic_supported();
        } catch ( \Throwable $_ ) {}

        if ( ! $atomic_runtime ) {
            $errors[] = 'V4_Props::is_atomic_supported() returned false — atomic widgets are not available at runtime.';
        }

        // Variables available = Global Design Tokens exist.
        $variables_count     = self::count_variables();
        $variables_available = $variables_count > 0;
        if ( ! $variables_available ) {
            $warnings[] = 'No Global Variables (Design Tokens) found in the active kit. Run setup-v4-foundation first.';
        }

        return [
            'elementor_active'          => $elementor_active,
            'elementor_version'         => $elementor_version,
            'experiments'               => $exp_states,
            'all_required_experiments'  => $all_required_active,
            'atomic_runtime_available'  => $atomic_runtime,
            'variables_count'           => $variables_count,
            'variables_available'       => $variables_available,
            'global_classes_active'     => $global_classes_active,
            'global_classes_count'      => self::count_global_classes(),
        ];
    }

    // ── Tree ─────────────────────────────────────────────────────────────────

    private static function check_tree( array $elements, array &$errors, array &$warnings ): array {
        $stats = [
            'total_elements'      => 0,
            'v3_widget_count'     => 0,
            'v4_widget_count'     => 0,
            'unwrapped_prop_count'=> 0,
            'locked_containers'   => 0,
            'unknown_widget_types'=> [],
        ];

        self::walk_tree( $elements, $stats );

        if ( $stats['v3_widget_count'] > 0 ) {
            $warnings[] = "{$stats['v3_widget_count']} V3 widget(s) found in tree — run import-clonerlabs-page with target=v4 or convert-page-v3-to-v4.";
        }

        if ( $stats['unwrapped_prop_count'] > 0 ) {
            $warnings[] = "{$stats['unwrapped_prop_count']} setting value(s) missing \$\$type wrapper — batch-build-page may not render them correctly.";
        }

        if ( ! empty( $stats['unknown_widget_types'] ) ) {
            $unknown = implode( ', ', array_unique( $stats['unknown_widget_types'] ) );
            $warnings[] = "Unknown widget type(s): {$unknown} — Elementor may ignore these.";
        }

        $v4_clean = $stats['v3_widget_count'] === 0 && $stats['unwrapped_prop_count'] === 0;

        return array_merge( $stats, [ 'v4_clean' => $v4_clean ] );
    }

    private static function walk_tree( array $elements, array &$stats ): void {
        // Known V4 atomic widget types.
        static $v4_types = null;
        if ( $v4_types === null ) {
            $v4_types = [
                'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg',
                'e-divider', 'e-icon', 'e-spacer', 'e-video', 'e-code',
            ];
        }
        // Known V3 widget types that indicate a non-converted page.
        static $v3_types = null;
        if ( $v3_types === null ) {
            $v3_types = [
                'heading', 'text-editor', 'image', 'button', 'divider',
                'icon', 'icon-list', 'image-carousel', 'counter', 'rating',
                'image-box', 'icon-box',
            ];
        }

        foreach ( $elements as $el ) {
            $stats['total_elements']++;
            $widget_type = $el['widgetType'] ?? null;
            $el_type     = $el['elType']     ?? '';

            if ( ! empty( $el['isLocked'] ) ) {
                $stats['locked_containers']++;
            }

            if ( $widget_type !== null ) {
                if ( in_array( $widget_type, $v4_types, true ) ) {
                    $stats['v4_widget_count']++;
                    // Check $$type wrapping on V4 widgets.
                    $stats['unwrapped_prop_count'] += self::count_unwrapped_props( $el['settings'] ?? [] );
                } elseif ( in_array( $widget_type, $v3_types, true ) ) {
                    $stats['v3_widget_count']++;
                } elseif ( ! in_array( $widget_type, [ 'html', 'nested-accordion', 'accordion', 'nav-menu', 'custom-widget' ], true ) ) {
                    $stats['unknown_widget_types'][] = $widget_type;
                }
            }

            if ( ! empty( $el['elements'] ) ) {
                self::walk_tree( $el['elements'], $stats );
            }
        }
    }

    /**
     * Count settings that look like they should be $$type-wrapped but aren't.
     * Only checks string values that look like CSS values — not exhaustive.
     */
    private static function count_unwrapped_props( array $settings ): int {
        $unwrapped = 0;
        $should_be_typed = [ 'font_size', 'padding', 'margin', 'gap', 'border_radius', 'width', 'height' ];
        foreach ( $should_be_typed as $key ) {
            if ( isset( $settings[ $key ] ) && ! is_array( $settings[ $key ] ) ) {
                $unwrapped++;
            }
        }
        return $unwrapped;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function count_variables(): int {
        $kit_id = (int) get_option( 'elementor_active_kit' );
        if ( ! $kit_id ) return 0;
        $settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        return is_array( $settings ) ? count( $settings['global_variables'] ?? [] ) : 0;
    }

    private static function count_global_classes(): int {
        return (int) get_option( 'elementor_global_classes_count', 0 );
    }
}
