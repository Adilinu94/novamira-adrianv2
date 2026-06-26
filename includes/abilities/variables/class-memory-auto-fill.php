<?php

declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Variables;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Memory_Auto_Fill — fills missing or empty Global Variable bindings in pages.
 *
 * After batch-create-variables creates GV-IDs in the kit, pages that reference
 * those variables by placeholder may have empty `id` fields in their
 * `$$type: global-variable` envelopes. This ability patches those gaps.
 *
 * Two modes:
 *   1. Map mode    → provide variable_map: { "token-name": "e-gv-xxxxxxx" }
 *                    Scans _elementor_data for matching token references and fills IDs.
 *   2. Audit mode  → auto_mode: true — scans for any $$type:global-variable
 *                    with empty id and reports them (dry-run compatible).
 *
 * Registered as: novamira-adrianv2/memory-auto-fill
 *
 * @since 1.1.0
 */
class Memory_Auto_Fill
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/memory-auto-fill', [
            'label'       => 'Memory Auto-Fill (GV Binding)',
            'description' => 'Scans Elementor page data for empty global-variable ($$type) bindings and fills them from a provided variable_map. Use after batch-create-variables to apply GV-IDs to pages that have placeholder bindings.',
            'category'    => 'adrianv2-variables',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'Page/post ID to scan and fill.',
                    ],
                    'post_ids' => [
                        'type'        => 'array',
                        'description' => 'Multiple post IDs to scan and fill in one call.',
                        'items'       => ['type' => 'integer'],
                    ],
                    'variable_map' => [
                        'type'        => 'object',
                        'description' => 'Map of token names to GV-IDs: { "primary-color": "e-gv-xxxxxxx" }. Any $$type:global-variable whose id matches a token name (or is empty) gets filled.',
                        'additionalProperties' => ['type' => 'string'],
                    ],
                    'dry_run' => [
                        'type'        => 'boolean',
                        'description' => 'If true, report what would be filled without writing. Default: false.',
                    ],
                ],
                'required' => ['variable_map'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'    => ['type' => 'boolean'],
                    'filled'     => ['type' => 'integer', 'description' => 'Total GV bindings filled.'],
                    'skipped'    => ['type' => 'integer', 'description' => 'Already-filled bindings left untouched.'],
                    'by_post'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'post_id' => ['type' => 'integer'],
                                'filled'  => ['type' => 'integer'],
                                'skipped' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'dry_run'    => ['type' => 'boolean'],
                    'error'      => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null): array
    {
        $variable_map = (array) ($input['variable_map'] ?? []);
        $dry_run      = (bool)  ($input['dry_run']      ?? false);
        $post_id      = (int)   ($input['post_id']      ?? 0);
        $post_ids     = (array) ($input['post_ids']     ?? []);

        if ($post_id > 0 && !in_array($post_id, $post_ids, true)) {
            $post_ids[] = $post_id;
        }

        if (empty($post_ids)) {
            return ['success' => false, 'error' => 'Provide post_id or post_ids.'];
        }

        if (empty($variable_map)) {
            return ['success' => false, 'error' => 'variable_map is empty.'];
        }

        $total_filled  = 0;
        $total_skipped = 0;
        $by_post       = [];

        foreach ($post_ids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0 || !get_post($pid)) {
                continue;
            }

            $result = self::fill_post($pid, $variable_map, $dry_run);
            $total_filled  += $result['filled'];
            $total_skipped += $result['skipped'];
            $by_post[]     = array_merge(['post_id' => $pid], $result);
        }

        return [
            'success' => true,
            'filled'  => $total_filled,
            'skipped' => $total_skipped,
            'by_post' => $by_post,
            'dry_run' => $dry_run,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Fill GV bindings in a single post.
     *
     * @return array{filled: int, skipped: int}
     */
    private static function fill_post(int $post_id, array $variable_map, bool $dry_run): array
    {
        $raw  = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return ['filled' => 0, 'skipped' => 0];
        }

        $filled  = 0;
        $skipped = 0;

        self::walk_and_fill($data, $variable_map, $filled, $skipped);

        if (!$dry_run && $filled > 0) {
            $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
            update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
            delete_post_meta($post_id, '_elementor_css');
            clean_post_cache($post_id);
        }

        return ['filled' => $filled, 'skipped' => $skipped];
    }

    /**
     * Recursively walk the decoded Elementor JSON and fill empty GV bindings.
     *
     * A GV binding looks like:
     *   { "$$type": "global-variable", "id": "" }   ← empty id = needs fill
     *   { "$$type": "global-variable", "id": "e-gv-xxxxxxx" } ← already set
     *
     * The variable_map key can be either:
     *   - The token name (e.g. "primary-color") stored as the `id` placeholder
     *   - Or the ability is invoked to fill by position (any empty id)
     */
    private static function walk_and_fill(
        array &$node,
        array  $variable_map,
        int   &$filled,
        int   &$skipped
    ): void {
        foreach ($node as $key => &$value) {
            if (!is_array($value)) {
                continue;
            }

            // Check if this node is a $$type:global-variable envelope
            if (
                isset($value['$$type']) &&
                $value['$$type'] === 'global-variable'
            ) {
                $current_id = (string) ($value['id'] ?? '');

                if ($current_id !== '' && !isset($variable_map[$current_id])) {
                    // Already has a real GV-ID not in our map → skip
                    $skipped++;
                    continue;
                }

                // Either empty id or id matches a token name in the map
                $new_id = $current_id !== '' && isset($variable_map[$current_id])
                    ? $variable_map[$current_id]
                    : null;

                if ($new_id === null && count($variable_map) === 1) {
                    // Single-variable fill: apply the only variable in the map
                    $new_id = reset($variable_map);
                }

                if ($new_id !== null) {
                    $value['id'] = $new_id;
                    $filled++;
                } else {
                    $skipped++;
                }
                continue;
            }

            // Recurse into children (elements arrays, nested settings, styles)
            self::walk_and_fill($value, $variable_map, $filled, $skipped);
        }
    }
}
