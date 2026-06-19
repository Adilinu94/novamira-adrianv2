<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Variables;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Batch_Create_Variables
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/batch-create-variables', [
            'label'               => 'Batch Create Variables',
            'description'         => 'Create multiple Elementor v4 Global Variables (design tokens) at once. Supports color, font, and size types. Handles conflict resolution: skip (default), overwrite, or rename. Use this instead of calling create-variable repeatedly.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'variables' => [
                        'type'        => 'array',
                        'description' => 'Array of variable definitions to create.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'label' => ['type' => 'string', 'description' => 'Variable label/name (no spaces, max 50 chars).'],
                                'type'  => ['type' => 'string', 'description' => 'Variable type: color, font, or size.'],
                                'value' => ['type' => 'string', 'description' => 'Value: hex for color ("#FF0000"), font name for font ("Inter"), CSS dimension for size ("24px").'],
                            ],
                            'required' => ['label', 'type', 'value'],
                        ],
                    ],
                    'strategy' => ['type' => 'string', 'description' => 'Conflict resolution: skip (keep existing), overwrite (replace), rename (add suffix). Default: skip.'],
                    'dry_run'  => ['type' => 'boolean', 'description' => 'Preview only, do not persist. Default: false.'],
                ],
                'required'   => ['variables'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'created'     => ['type' => 'integer'],
                    'skipped'     => ['type' => 'integer'],
                    'overwritten' => ['type' => 'integer'],
                    'renamed'     => ['type' => 'integer'],
                    'errors'      => ['type' => 'integer'],
                    'items'       => ['type' => 'array'],
                    'dry_run'     => ['type' => 'boolean'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        // Early Helpers guard (1.1.0): prevent PHP fatal when the Helpers
        // class hasn't been loaded yet (e.g. bootstrap ordering edge case).
        // Returns a clean WP_Error so the agent can fall back gracefully.
        if (!class_exists('Novamira\\AdrianV2\\Helpers')) {
            return new \WP_Error(
                'helpers_not_loaded',
                __('The Novamira\AdrianV2\Helpers class is not loaded. This is a plugin bootstrap ordering issue — try reloading the page or re-activating the plugin.', 'novamira-adrianv2')
            );
        }

        // V4 guard (1.1.0): V4 global variables require Elementor 4.0+.
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error(
                'v4_required',
                sprintf(
                    __('%s requires Elementor 4.0+. Detected version: %s.', 'novamira-adrianv2'),
                    'batch-create-variables',
                    \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()
                )
            );
        }

        $variables = $input['variables'] ?? [];
        $strategy  = $input['strategy'] ?? 'skip';
        $dry_run   = $input['dry_run'] ?? false;

        if (!in_array($strategy, ['skip', 'overwrite', 'rename'], true)) {
            $strategy = 'skip';
        }

        $kit_id = (int) get_option('elementor_active_kit');

        // CORRECT storage: _elementor_global_variables with {data: {ID: {...}}, watermark, version} wrapper
        list($existing, $wrapper) = Helpers::load_v4_variables($kit_id);
        // $existing is ID-keyed: { "e-gv-xxx": {label, value:{$$type,value}, type, order, ...} }

        // Build label->ID lookup
        $by_label = [];
        foreach ($existing as $id => $var) {
            $by_label[$var['label'] ?? ''] = $id;
        }

        // Track highest existing order
        $max_order = 0;
        foreach ($existing as $var) {
            $max_order = max($max_order, $var['order'] ?? 0);
        }
        $now = current_time('mysql');

        $counts = ['created' => 0, 'skipped' => 0, 'overwritten' => 0, 'renamed' => 0, 'errors' => 0];
        $items  = [];

        // Map short type -> full type string
        $type_map = [
            'color' => 'global-color-variable',
            'font'  => 'global-font-variable',
            'size'  => 'global-size-variable',
        ];

        foreach ($variables as $def) {
            $label = trim($def['label'] ?? '');
            $type  = $def['type'] ?? 'color';
            $value = $def['value'] ?? '';

            if (empty($label)) {
                $counts['errors']++;
                $items[] = ['label' => $label, 'action' => 'error', 'error' => 'Label is required'];
                continue;
            }
            if (!in_array($type, ['color', 'font', 'size'], true)) {
                $counts['errors']++;
                $items[] = ['label' => $label, 'action' => 'error', 'error' => "Invalid type: $type"];
                continue;
            }

            $full_type = $type_map[$type];
            $final_label = $label;
            $action      = 'created';

            if (isset($by_label[$label])) {
                switch ($strategy) {
                    case 'skip':
                        $action = 'skipped';
                        break;
                    case 'overwrite':
                        $action = 'overwritten';
                        break;
                    case 'rename':
                        $n = 1;
                        while (isset($by_label["$label-$n"])) {
                            $n++;
                        }
                        $final_label = "$label-$n";
                        $action = 'renamed';
                        break;
                }
            }

            if ($action === 'skipped') {
                $counts['skipped']++;
                $existing_id = $by_label[$label];
                $items[] = ['label' => $label, 'action' => 'skipped', 'variable_label' => $label, 'variable_id' => $existing_id];
                continue;
            }

            // Wrap value in $$type format
            $wrapped_value = Helpers::wrap_variable_value($type, $value);

            // Elementor v4 format: e-gv- + 7 hex characters
            $new_id = 'e-gv-' . substr(md5($final_label . mt_rand()), 0, 7);

            if ($dry_run) {
                $counts[$action]++;
                if ($action === 'overwritten') {
                    $items[] = ['label' => $label, 'action' => $action, 'variable_label' => $final_label, 'variable_id' => $by_label[$label]];
                } else {
                    $items[] = ['label' => $label, 'action' => $action, 'variable_label' => $final_label, 'variable_id' => $new_id];
                }
                continue;
            }

            try {
                if ($action === 'overwritten') {
                    $old_id = $by_label[$label];
                    $old_order = $existing[$old_id]['order'] ?? $max_order + 1;
                    $old_created = $existing[$old_id]['created_at'] ?? $now;

                    $existing[$old_id] = [
                        'label'      => $final_label,
                        'value'      => $wrapped_value,
                        'type'       => $full_type,
                        'order'      => $old_order,
                        'created_at' => $old_created,
                        'updated_at' => $now,
                    ];
                    $by_label[$final_label] = $old_id;
                    unset($by_label[$label]);
                    $variable_id = $old_id;
                } else {
                    $max_order++;
                    $existing[$new_id] = [
                        'label'      => $final_label,
                        'value'      => $wrapped_value,
                        'type'       => $full_type,
                        'order'      => $max_order,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $by_label[$final_label] = $new_id;
                    $variable_id = $new_id;
                }

                // Write back with correct wrapper
                Helpers::save_v4_variables($kit_id, $existing, $wrapper);

                $counts[$action]++;
                $items[] = ['label' => $label, 'action' => $action, 'variable_label' => $final_label, 'variable_id' => $variable_id, 'type' => $type, 'value' => $value];

            } catch (\Throwable $e) {
                $counts['errors']++;
                $items[] = ['label' => $label, 'action' => 'error', 'error' => $e->getMessage()];
            }
        }

        return [
            'created'     => $counts['created'],
            'skipped'     => $counts['skipped'],
            'overwritten' => $counts['overwritten'],
            'renamed'     => $counts['renamed'],
            'errors'      => $counts['errors'],
            'items'       => $items,
            'dry_run'     => $dry_run,
        ];
    }
}

add_action('wp_abilities_api_init', [Batch_Create_Variables::class, 'register']);
