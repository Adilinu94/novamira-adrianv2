<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Import_ClonerLabs_Batch
 *
 * Ability: `novamira-adrianv2/import-clonerlabs-batch`
 *
 * Imports multiple ClonerLabs exports in one call. Each item in `exports` is
 * forwarded to `Import_ClonerLabs_Page::execute()` with shared parameters
 * applied as defaults.
 *
 * BUG #3 FIX: Does NOT use `Kit_Rollback::create_snapshot()` — that class
 * requires a `Kit_Manifest` object which cannot be constructed here.
 * Instead uses a lightweight own snapshot stored in WP options.
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class Import_ClonerLabs_Batch {

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/import-clonerlabs-batch', [
            'label'       => 'Import ClonerLabs Batch',
            'description' =>
                'Imports multiple ClonerLabs exports in one call. '
                . 'Shared options (target, status, upload_media, etc.) apply to all items unless '
                . 'overridden per-item. Creates a lightweight rollback snapshot before import.',
            'category'    => 'adrianv2-clonerlabs',

            'input_schema' => [
                'type'       => 'object',
                'required'   => [ 'exports' ],
                'properties' => [
                    'exports'             => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'required'   => [ 'cloner_data' ],
                            'properties' => [
                                'cloner_data' => [ 'type' => 'object' ],
                                'title'       => [ 'type' => 'string' ],
                                'slug'        => [ 'type' => 'string' ],
                                'post_id'     => [ 'type' => 'integer' ],
                            ],
                        ],
                        'description' => 'Array of ClonerLabs export objects, each with a cloner_data field.',
                    ],
                    // Shared defaults applied to every item.
                    'target'              => [ 'type' => 'string',  'enum' => [ 'v3', 'v4' ],                         'default' => 'v3' ],
                    'status'              => [ 'type' => 'string',  'enum' => [ 'draft', 'publish', 'private' ],      'default' => 'draft' ],
                    'template'            => [ 'type' => 'string',  'default' => 'elementor_header_footer' ],
                    'v4_strategy'         => [ 'type' => 'string',  'enum' => [ 'keep_v3', 'skip', 'error' ],         'default' => 'keep_v3' ],
                    'upload_media'        => [ 'type' => 'boolean', 'default' => true ],
                    'apply_global_styles' => [ 'type' => 'boolean', 'default' => false, 'description' => 'Defaults to false in batch to avoid overwriting kit colors on every page.' ],
                    'cleanup_styles'      => [ 'type' => 'boolean', 'default' => true ],
                    'regenerate_ids'      => [ 'type' => 'boolean', 'default' => true ],
                    'batch_name'          => [ 'type' => 'string',  'description' => 'Optional label for the rollback snapshot.' ],
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'     => [ 'type' => 'boolean' ],
                    'snapshot_id' => [ 'type' => 'string' ],
                    'total'       => [ 'type' => 'integer' ],
                    'succeeded'   => [ 'type' => 'integer' ],
                    'failed'      => [ 'type' => 'integer' ],
                    'results'     => [ 'type' => 'array' ],
                    'summary'     => [ 'type' => 'string' ],
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
        $input      = $input ?? [];
        $exports    = $input['exports'] ?? [];
        $batch_name = (string) ( $input['batch_name'] ?? 'Batch ' . gmdate( 'Y-m-d H:i' ) );

        if ( ! is_array( $exports ) || empty( $exports ) ) {
            return [ 'success' => false, 'error' => 'exports must be a non-empty array.' ];
        }

        // Shared defaults.
        $defaults = [
            'target'              => $input['target']              ?? 'v3',
            'status'              => $input['status']              ?? 'draft',
            'template'            => $input['template']            ?? 'elementor_header_footer',
            'v4_strategy'         => $input['v4_strategy']         ?? 'keep_v3',
            'upload_media'        => $input['upload_media']        ?? true,
            'apply_global_styles' => $input['apply_global_styles'] ?? false,
            'cleanup_styles'      => $input['cleanup_styles']      ?? true,
            'regenerate_ids'      => $input['regenerate_ids']      ?? true,
        ];

        // BUG #3: Own lightweight snapshot (Kit_Rollback not usable here).
        $snapshot_id = self::create_snapshot( $batch_name );

        $results   = [];
        $succeeded = 0;
        $failed    = 0;
        $post_ids  = [];

        foreach ( $exports as $i => $item ) {
            if ( ! is_array( $item ) || ! isset( $item['cloner_data'] ) ) {
                $results[] = [
                    'index'   => $i,
                    'success' => false,
                    'error'   => 'Missing cloner_data field.',
                ];
                $failed++;
                continue;
            }

            $call_params = array_merge( $defaults, [
                'cloner_data' => $item['cloner_data'],
                'title'       => $item['title']   ?? '',
                'slug'        => $item['slug']    ?? '',
                'post_id'     => $item['post_id'] ?? 0,
            ] );

            $result = Import_ClonerLabs_Page::execute( $call_params );

            $results[] = array_merge( [ 'index' => $i ], $result );

            if ( ! empty( $result['success'] ) ) {
                $succeeded++;
                if ( ! empty( $result['post_id'] ) ) {
                    $post_ids[] = $result['post_id'];
                }
            } else {
                $failed++;
            }
        }

        // Record created post IDs in snapshot for rollback.
        self::record_snapshot_posts( $snapshot_id, $post_ids );

        return [
            'success'     => $failed === 0,
            'snapshot_id' => $snapshot_id,
            'total'       => count( $exports ),
            'succeeded'   => $succeeded,
            'failed'      => $failed,
            'results'     => $results,
            'summary'     => sprintf(
                "Batch '%s': %d/%d succeeded. Snapshot: %s.",
                $batch_name,
                $succeeded,
                count( $exports ),
                $snapshot_id
            ),
        ];
    }

    // ── Snapshot (BUG #3 FIX) ─────────────────────────────────────────────────

    private static function create_snapshot( string $batch_name ): string {
        $snapshot_id = 'cloner_' . gmdate( 'Ymd_Hi' ) . '_' . substr( uniqid(), -4 );
        $snapshots   = get_option( '_novamira_cloner_snapshots', [] );
        if ( ! is_array( $snapshots ) ) $snapshots = [];

        $snapshots[] = [
            'id'           => $snapshot_id,
            'batch_name'   => $batch_name,
            'timestamp'    => gmdate( 'c' ),
            'posts_created'=> [],
        ];

        // Keep at most 10 snapshots.
        if ( count( $snapshots ) > 10 ) {
            array_shift( $snapshots );
        }

        update_option( '_novamira_cloner_snapshots', $snapshots, false );
        return $snapshot_id;
    }

    private static function record_snapshot_posts( string $snapshot_id, array $post_ids ): void {
        $snapshots = get_option( '_novamira_cloner_snapshots', [] );
        if ( ! is_array( $snapshots ) ) return;

        foreach ( $snapshots as &$snap ) {
            if ( ( $snap['id'] ?? '' ) === $snapshot_id ) {
                $snap['posts_created'] = $post_ids;
                break;
            }
        }
        unset( $snap );
        update_option( '_novamira_cloner_snapshots', $snapshots, false );
    }
}
