<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

use Novamira\AdrianV2\Helpers\Guards;
use Novamira\AdrianV2\Helpers\Conversion_AutoFixer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Repair_ClonerLabs_Page
 *
 * Ability: `novamira-adrianv2/repair-clonerlabs-page`
 *
 * FIX #15: Wraps the existing `Conversion_AutoFixer::run()` rather than
 * reimplementing its fixes. AutoFixer handles: empty containers, broken images,
 * flexbox children wrapping, excessive nesting, dangling class refs, orphan
 * styles, duplicate styles, and responsive variant generation.
 *
 * ClonerLabs-specific extra fixes applied after AutoFixer:
 *   - Strip any remaining `_gsapCode` fields
 *   - Normalise `custom-widget` → `html` widgetType
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class Repair_ClonerLabs_Page {

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/repair-clonerlabs-page', [
            'label'       => 'Repair ClonerLabs Page',
            'description' => 'Runs structural auto-fixes on a ClonerLabs-imported Elementor page: '
                . 'empty containers, broken images, excessive nesting, orphan styles, '
                . 'GSAP script stripping, and custom-widget normalisation. '
                . 'Set dry_run=true to preview fixes without saving.',
            'category'    => 'adrianv2-clonerlabs',

            'input_schema' => [
                'type'       => 'object',
                'required'   => [ 'page_id' ],
                'properties' => [
                    'page_id' => [ 'type' => 'integer', 'description' => 'Post ID of the Elementor page to repair.' ],
                    'dry_run' => [ 'type' => 'boolean', 'default' => false, 'description' => 'If true, returns the fix report without saving changes.' ],
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'page_id'    => [ 'type' => 'integer' ],
                    'dry_run'    => [ 'type' => 'boolean' ],
                    'fixes_applied' => [ 'type' => 'integer' ],
                    'cloner_fixes'  => [ 'type' => 'object' ],
                    'summary'    => [ 'type' => 'string' ],
                    'error'      => [ 'type' => 'string' ],
                ],
            ],

            'execute_callback'    => [ self::class, 'execute' ],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
            ],
        ] );
    }

    public static function execute( ?array $input = null ): array {
        $input   = $input ?? [];
        $page_id = (int) ( $input['page_id'] ?? 0 );
        $dry_run = (bool) ( $input['dry_run'] ?? false );

        if ( $page_id <= 0 ) {
            return [ 'success' => false, 'error' => 'page_id is required.' ];
        }

        $elements = Guards::get_elementor_data( $page_id );
        if ( $elements === false ) {
            return [ 'success' => false, 'error' => "No Elementor data found for post #{$page_id}." ];
        }

        // ── AutoFixer pass (handles structural issues). ────────────────────────
        $auto_total = 0;
        $fixed      = Conversion_AutoFixer::run( $elements, $auto_total );

        // ── ClonerLabs-specific cleanup passes. ───────────────────────────────
        $gsap_scripts   = [];
        $fixed          = self::collect_and_strip_gsap( $fixed, $gsap_scripts );

        $custom_widget_count = 0;
        $fixed               = self::normalise_custom_widgets( $fixed, $custom_widget_count );

        $cloner_fixes = [
            'gsap_scripts_stripped'   => count( $gsap_scripts ),
            'custom_widgets_converted' => $custom_widget_count,
        ];

        $total_fixes = $auto_total + array_sum( $cloner_fixes );

        // ── Save ──────────────────────────────────────────────────────────────
        if ( ! $dry_run && $total_fixes > 0 ) {
            Guards::save_elementor_data( $page_id, $fixed );
        }

        return [
            'success'       => true,
            'page_id'       => $page_id,
            'dry_run'       => $dry_run,
            'fixes_applied' => $total_fixes,
            'cloner_fixes'  => $cloner_fixes,
            'summary'       => sprintf(
                "%d total fix(es) applied to page #%d%s.",
                $total_fixes,
                $page_id,
                $dry_run ? ' (dry run — not saved)' : ''
            ),
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function collect_and_strip_gsap( array $elements, array &$scripts ): array {
        return array_map( function ( array $el ) use ( &$scripts ): array {
            if ( ! empty( $el['_gsapCode'] ) && is_string( $el['_gsapCode'] ) ) {
                $scripts[] = $el['_gsapCode'];
                unset( $el['_gsapCode'] );
            }
            if ( ! empty( $el['elements'] ) ) {
                $el['elements'] = self::collect_and_strip_gsap( $el['elements'], $scripts );
            }
            return $el;
        }, $elements );
    }

    private static function normalise_custom_widgets( array $elements, int &$count ): array {
        return array_map( function ( array $el ) use ( &$count ): array {
            if ( ( $el['widgetType'] ?? '' ) === 'custom-widget' ) {
                $el['widgetType'] = 'html';
                if ( empty( $el['settings']['html'] ) && ! empty( $el['settings']['content'] ) ) {
                    $el['settings']['html'] = (string) $el['settings']['content'];
                }
                $count++;
            }
            if ( ! empty( $el['elements'] ) ) {
                $el['elements'] = self::normalise_custom_widgets( $el['elements'], $count );
            }
            return $el;
        }, $elements );
    }
}
