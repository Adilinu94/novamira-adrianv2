<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Import_ClonerLabs_Library
 *
 * Ability: `novamira-adrianv2/import-clonerlabs-library`
 *
 * Imports a ClonerLabs saved-sections library (chrome.storage.local export).
 *
 * LIBRARY FORMAT FIX: ClonerLabs stores sections with key `elementorData`
 * (a single container object). The original plan incorrectly documented this
 * as `mappedElements`. Both are accepted for backward compatibility.
 *
 * Each section is forwarded to Import_ClonerLabs_Page which handles the
 * normalise_saved_section() conversion automatically.
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class Import_ClonerLabs_Library {

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/import-clonerlabs-library', [
            'label'       => 'Import ClonerLabs Library',
            'description' =>
                'Imports ClonerLabs saved sections into Elementor Library templates. '
                . 'Accepts the ClonerLabs localStorage export format '
                . '({ sections: [{ id, name, elementorData, isGridMode }] }). '
                . 'Each section becomes an Elementor Library template of type "section".',
            'category'    => 'adrianv2-clonerlabs',

            'input_schema' => [
                'type'       => 'object',
                'required'   => [ 'library_data' ],
                'properties' => [
                    'library_data' => [
                        'type'        => 'object',
                        'description' => 'ClonerLabs localStorage export: { sections: [...] }',
                    ],
                    'target'       => [ 'type' => 'string',  'enum' => [ 'v3', 'v4' ], 'default' => 'v3' ],
                    'v4_strategy'  => [ 'type' => 'string',  'enum' => [ 'keep_v3', 'skip', 'error' ], 'default' => 'keep_v3' ],
                    'upload_media' => [ 'type' => 'boolean', 'default' => true ],
                    'cleanup_styles'  => [ 'type' => 'boolean', 'default' => true ],
                    'regenerate_ids'  => [ 'type' => 'boolean', 'default' => true ],
                    'filter_names' => [
                        'type'        => 'array',
                        'items'       => [ 'type' => 'string' ],
                        'description' => 'Optional: only import sections whose name is in this list.',
                    ],
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'    => [ 'type' => 'boolean' ],
                    'total'      => [ 'type' => 'integer' ],
                    'imported'   => [ 'type' => 'integer' ],
                    'skipped'    => [ 'type' => 'integer' ],
                    'failed'     => [ 'type' => 'integer' ],
                    'results'    => [ 'type' => 'array' ],
                    'summary'    => [ 'type' => 'string' ],
                    'error'      => [ 'type' => 'string' ],
                ],
            ],

            'execute_callback'    => [ self::class, 'execute' ],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
            ],
        ] );
    }

    public static function execute( ?array $input = null ): array {
        $input        = $input ?? [];
        $library_data = $input['library_data'] ?? null;
        $target       = $input['target']        ?? 'v3';
        $v4_strategy  = $input['v4_strategy']   ?? 'keep_v3';
        $upload_media = (bool) ( $input['upload_media']    ?? true );
        $cleanup      = (bool) ( $input['cleanup_styles']  ?? true );
        $regen_ids    = (bool) ( $input['regenerate_ids']  ?? true );
        $filter_names = $input['filter_names'] ?? [];

        if ( ! is_array( $library_data ) ) {
            return [ 'success' => false, 'error' => 'library_data must be an object.' ];
        }

        $sections = $library_data['sections'] ?? $library_data['savedSectionsLocal'] ?? [];
        if ( ! is_array( $sections ) || empty( $sections ) ) {
            return [ 'success' => false, 'error' => 'library_data.sections must be a non-empty array.' ];
        }

        $results  = [];
        $imported = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ( $sections as $i => $section ) {
            if ( ! is_array( $section ) ) {
                $failed++;
                $results[] = [ 'index' => $i, 'success' => false, 'error' => 'Invalid section format.' ];
                continue;
            }

            $name = $section['name'] ?? "Section {$i}";

            // LIBRARY FORMAT FIX: ClonerLabs uses `elementorData`, not `mappedElements`.
            // Accept both for backward compatibility.
            $element_data = $section['elementorData'] ?? $section['mappedElements'] ?? null;
            if ( $element_data === null ) {
                $skipped++;
                $results[] = [ 'index' => $i, 'name' => $name, 'success' => false, 'error' => 'Missing elementorData field.' ];
                continue;
            }

            // Optional name filter.
            if ( ! empty( $filter_names ) && ! in_array( $name, $filter_names, true ) ) {
                $skipped++;
                $results[] = [ 'index' => $i, 'name' => $name, 'skipped' => true, 'reason' => 'Not in filter_names.' ];
                continue;
            }

            // Forward to Import_ClonerLabs_Page using the saved-section format directly.
            $result = Import_ClonerLabs_Page::execute( [
                'cloner_data'     => $section, // Phase 1 auto-detects elementorData format
                'target'          => $target,
                'v4_strategy'     => $v4_strategy,
                'upload_media'    => $upload_media,
                'cleanup_styles'  => $cleanup,
                'regenerate_ids'  => $regen_ids,
                'create_template' => true,  // Each section → Elementor Library template
                'status'          => 'publish',
                'title'           => $name,
                'apply_global_styles' => false, // No global styles in saved sections
            ] );

            $results[] = array_merge( [ 'index' => $i, 'name' => $name ], $result );

            if ( ! empty( $result['success'] ) ) {
                $imported++;
            } else {
                $failed++;
            }
        }

        return [
            'success'  => $failed === 0,
            'total'    => count( $sections ),
            'imported' => $imported,
            'skipped'  => $skipped,
            'failed'   => $failed,
            'results'  => $results,
            'summary'  => sprintf(
                "%d/%d sections imported into Elementor Library (%d skipped, %d failed).",
                $imported,
                count( $sections ),
                $skipped,
                $failed
            ),
        ];
    }
}
