<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Import_Design_System
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/import-design-system', [
            'label'               => 'Import Design System',
            'description'         => 'Import a design system from JSON. Supports conflict resolution strategies: "skip" (keep existing), "overwrite" (replace existing), "rename" (add numeric suffix). Handles colors, typography, and global classes.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'json_data'          => [
                        'type'        => 'string',
                        'description' => 'The JSON export string to import (from adrians-export-design-system).',
                    ],
                    'what'               => [
                        'type'        => 'string',
                        'description' => 'What to import from the JSON data.',
                        'enum'        => ['colors', 'typography', 'classes', 'all'],
                    ],
                    'conflict_strategy'  => [
                        'type'        => 'string',
                        'description' => 'How to handle naming conflicts: "skip" (keep existing unchanged), "overwrite" (replace existing), "rename" (add "(imported)" suffix).',
                        'enum'        => ['skip', 'overwrite', 'rename'],
                    ],
                    'dry_run'            => [
                        'type'        => 'boolean',
                        'description' => 'If true, only preview what would be imported without making changes.',
                    ],
                ],
                'required'   => ['json_data'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'   => ['type' => 'boolean'],
                    'results'   => ['type' => 'object'],
                    'dry_run'   => ['type' => 'boolean'],
                    'changes'   => ['type' => 'object'],
                    'message'   => ['type' => 'string'],
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
        $json_data = $input['json_data'];
        $what      = $input['what'] ?? 'all';
        $strategy  = $input['conflict_strategy'] ?? 'skip';
        $dry_run   = $input['dry_run'] ?? false;

        $import_data = json_decode($json_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()];
        }

        $kit_id = get_option('elementor_active_kit');
        if (!$kit_id) {
            return ['success' => false, 'message' => 'No active Elementor kit found.'];
        }

        $kit_settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (is_string($kit_settings)) {
            $kit_settings = maybe_unserialize($kit_settings);
        }
        if (!is_array($kit_settings)) {
            $kit_settings = [];
        }

        $changes = [];
        $results = [
            'conflicts'   => [],
            'imported'    => [],
            'skipped'     => [],
            'overwritten' => [],
            'renamed'     => [],
        ];

        $resolve_conflict = function ($existing_items, $new_item, $key_field = 'title') use ($strategy, &$results) {
            $new_title = $new_item[$key_field] ?? '';
            foreach ($existing_items as $existing) {
                if (($existing[$key_field] ?? '') === $new_title) {
                    $results['conflicts'][] = $new_title;
                    switch ($strategy) {
                        case 'skip':
                            return [null, 'skipped'];
                        case 'rename':
                            $suffix = ' (imported)';
                            $new_item[$key_field] = $new_title . $suffix;
                            return [$new_item, 'renamed'];
                        case 'overwrite':
                            return [$new_item, 'overwritten'];
                    }
                }
            }
            return [$new_item, 'imported'];
        };

        // Import Colors
        if (($what === 'colors' || $what === 'all') && !empty($import_data['system_colors'])) {
            $existing_colors = $kit_settings['system_colors'] ?? [];
            $new_colors      = $existing_colors;

            foreach ($import_data['system_colors'] as $color) {
                if (empty($color['_id'])) {
                    $color['_id'] = substr(md5($color['title'] . mt_rand()), 0, 8);
                }
                list($resolved, $action) = $resolve_conflict($existing_colors, $color);
                if ($resolved !== null) {
                    // overwrite existing by _id match or append
                    $replaced = false;
                    foreach ($new_colors as $i => $existing) {
                        if ($existing['_id'] === $color['_id']) {
                            if ($strategy === 'overwrite') {
                                $new_colors[$i] = $resolved;
                                $replaced = true;
                            }
                            break;
                        }
                    }
                    if (!$replaced) {
                        $new_colors[] = $resolved;
                    }
                    $results[$action][] = $color['title'] ?? $color['_id'];
                } else {
                    $results['skipped'][] = $color['title'] ?? $color['_id'];
                }
            }

            $changes['system_colors'] = [
                'before' => count($existing_colors),
                'after'  => count($new_colors),
            ];

            if (!$dry_run) {
                $kit_settings['system_colors'] = $new_colors;
            }
        }

        // Import Typography
        if (($what === 'typography' || $what === 'all') && !empty($import_data['system_typography'])) {
            $existing_typos = $kit_settings['system_typography'] ?? [];
            $new_typos      = $existing_typos;

            foreach ($import_data['system_typography'] as $typo) {
                if (empty($typo['_id'])) {
                    $typo['_id'] = substr(md5($typo['title'] . mt_rand()), 0, 8);
                }
                list($resolved, $action) = $resolve_conflict($existing_typos, $typo);
                if ($resolved !== null) {
                    $replaced = false;
                    foreach ($new_typos as $i => $existing) {
                        if ($existing['_id'] === $typo['_id']) {
                            if ($strategy === 'overwrite') {
                                $new_typos[$i] = $resolved;
                                $replaced       = true;
                            }
                            break;
                        }
                    }
                    if (!$replaced) {
                        $new_typos[] = $resolved;
                    }
                    $results[$action][] = $typo['title'] ?? $typo['_id'];
                } else {
                    $results['skipped'][] = $typo['title'] ?? $typo['_id'];
                }
            }

            $changes['system_typography'] = [
                'before' => count($existing_typos),
                'after'  => count($new_typos),
            ];

            if (!$dry_run) {
                $kit_settings['system_typography'] = $new_typos;
            }
        }

        // Import Global Classes
        if (($what === 'classes' || $what === 'all') && !empty($import_data['global_classes'])) {
            $class_order  = get_post_meta($kit_id, '_elementor_global_classes_order', true);
            $class_labels = get_post_meta($kit_id, '_elementor_global_classes_labels', true);
            $class_styles = get_post_meta($kit_id, '_elementor_global_classes_styles', true);

            if (is_string($class_order)) {
                $class_order = maybe_unserialize($class_order);
            }
            if (is_string($class_labels)) {
                $class_labels = maybe_unserialize($class_labels);
            }
            if (is_string($class_styles)) {
                $class_styles = maybe_unserialize($class_styles);
            }

            if (!is_array($class_order)) {
                $class_order = ['order' => []];
            }
            if (!is_array($class_labels)) {
                $class_labels = [];
            }
            if (!is_array($class_styles)) {
                $class_styles = [];
            }

            $existing_ids  = $class_order['order'] ?? [];
            $changes['global_classes'] = [
                'before' => count($existing_ids),
            ];

            foreach ($import_data['global_classes'] as $cls_id => $cls_data) {
                $label = $cls_data['label'] ?? '';
                $styles = $cls_data['styles'] ?? [];

                // Check for label conflict
                $conflict = false;
                foreach ($class_labels as $existing_id => $existing_label) {
                    if ($existing_label === $label && $existing_id !== $cls_id) {
                        $conflict = true;
                        break;
                    }
                }

                if ($conflict) {
                    $results['conflicts'][] = $label;
                    if ($strategy === 'skip') {
                        $results['skipped'][] = $label;
                        continue;
                    } elseif ($strategy === 'rename') {
                        $label = $label . ' (imported)';
                        $results['renamed'][] = $label;
                    }
                    // overwrite: proceed normally
                }

                if (!in_array($cls_id, $existing_ids, true)) {
                    $class_order['order'][] = $cls_id;
                }
                $class_labels[$cls_id] = $label;
                if (!empty($styles)) {
                    $class_styles[$cls_id] = $styles;
                }

                $results['imported'][] = $label;
            }

            $changes['global_classes']['after'] = count($class_order['order']);

            if (!$dry_run) {
                update_post_meta($kit_id, '_elementor_global_classes_order', $class_order);
                update_post_meta($kit_id, '_elementor_global_classes_labels', $class_labels);
                if (!empty($class_styles)) {
                    update_post_meta($kit_id, '_elementor_global_classes_styles', $class_styles);
                }
            }
        }

        // Save kit settings if modified
        if (!empty($changes) && !$dry_run) {
            update_post_meta($kit_id, '_elementor_page_settings', $kit_settings);
            clean_post_cache($kit_id);
        }

        $total_imported = count($results['imported']);
        $total_skipped  = count($results['skipped']);
        $total_overwritten = count($results['overwritten']);
        $total_conflicts = count($results['conflicts']);

        return [
            'success' => true,
            'results' => $results,
            'dry_run' => $dry_run,
            'changes' => $changes,
            'message' => $dry_run
                ? sprintf('DRY RUN: Would import %d, skip %d, overwrite %d (conflicts: %d).', $total_imported, $total_skipped, $total_overwritten, $total_conflicts)
                : sprintf('Imported %d, skipped %d, overwritten %d (conflicts: %d). Strategy: %s.', $total_imported, $total_skipped, $total_overwritten, $total_conflicts, $strategy),
        ];
    }
}

add_action('wp_abilities_api_init', [Import_Design_System::class, 'register']);
