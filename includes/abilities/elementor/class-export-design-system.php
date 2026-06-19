<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Export_Design_System
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/export-design-system', [
            'label'               => 'Export Design System',
            'description'         => 'Export the complete Elementor design system as structured JSON. Includes global colors, typography, global classes (with styles), and optional kit layout settings. Use for backups, migration, or cross-site design syncing.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'what'  => [
                        'type'        => 'string',
                        'description' => 'What to export.',
                        'enum'        => ['colors', 'typography', 'classes', 'all'],
                    ],
                    'include_kit_settings' => [
                        'type'        => 'boolean',
                        'description' => 'Include kit layout settings (container padding, page width, breakpoints, etc.).',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'     => ['type' => 'boolean'],
                    'export_data' => ['type' => 'object'],
                    'export_json' => ['type' => 'string'],
                    'summary'     => ['type' => 'object'],
                    'message'     => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $what                = $input['what'] ?? 'all';
        $include_kit_settings = $input['include_kit_settings'] ?? false;

        $kit_id = get_option('elementor_active_kit');
        if (!$kit_id) {
            return ['success' => false, 'message' => 'No active Elementor kit found.'];
        }

        $export = [
            '_meta' => [
                'exported_at'    => gmdate('c'),
                'site_url'       => home_url(),
                'site_name'      => get_bloginfo('name'),
                'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                'elementor_pro_version' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
                'kit_id'         => $kit_id,
            ],
        ];

        $summary = [];

        $kit_settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (is_string($kit_settings)) {
            $kit_settings = maybe_unserialize($kit_settings);
        }
        if (!is_array($kit_settings)) {
            $kit_settings = [];
        }

        // Colors
        if ($what === 'colors' || $what === 'all') {
            $colors = $kit_settings['system_colors'] ?? [];
            $export['system_colors'] = $colors;
            $summary['colors_count'] = count($colors);
            $summary['color_names']  = array_column($colors, 'title');
        }

        // Typography
        if ($what === 'typography' || $what === 'all') {
            $typos = $kit_settings['system_typography'] ?? [];
            $export['system_typography'] = $typos;
            $summary['typography_count'] = count($typos);
            $summary['typography_names'] = array_column($typos, 'title');
        }

        // Global Classes
        if ($what === 'classes' || $what === 'all') {
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

            $class_ids = $class_order['order'] ?? [];
            $classes   = [];
            foreach ($class_ids as $cls_id) {
                $classes[$cls_id] = [
                    'label'  => $class_labels[$cls_id] ?? '',
                    'styles' => $class_styles[$cls_id] ?? [],
                ];
            }
            $export['global_classes']   = $classes;
            $summary['classes_count']   = count($classes);
            $summary['class_ids']       = $class_ids;
        }

        // Optional kit layout settings
        if ($include_kit_settings) {
            $layout_keys = [
                'container_padding', 'container_width', 'viewport_mobile',
                'viewport_tablet', 'space_between_widgets', 'page_title_selector',
                'active_breakpoints', 'viewport_lg', 'viewport_md',
            ];
            $layout = [];
            foreach ($layout_keys as $key) {
                if (isset($kit_settings[$key])) {
                    $layout[$key] = $kit_settings[$key];
                }
            }
            $export['kit_layout']        = $layout;
            $summary['layout_keys_count'] = count($layout);
        }

        $export_json = wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'success'     => true,
            'export_data' => $export,
            'export_json' => $export_json,
            'summary'     => $summary,
            'message'     => sprintf(
                'Exported %d colors, %d typography entries, %d classes.%s',
                $summary['colors_count'] ?? 0,
                $summary['typography_count'] ?? 0,
                $summary['classes_count'] ?? 0,
                $include_kit_settings ? ' Plus kit layout settings.' : ''
            ),
        ];
    }
}

add_action('wp_abilities_api_init', [Export_Design_System::class, 'register']);
