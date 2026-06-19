<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Audit;

if (!defined('ABSPATH')) {
    exit();
}

class Responsive_Audit
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/responsive-audit', [
            'label'               => 'Responsive Audit',
            'description'         => 'Analyzes responsive behavior of an Elementor page: active breakpoints, per-element visibility, responsive style variants (v4), responsive settings (v3), and a breakpoint-by-breakpoint visibility tree.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [
                        'type'        => 'integer',
                        'description' => 'The page/post ID to audit.',
                    ],
                    'breakpoint' => [
                        'type'        => 'string',
                        'description' => 'Optional: filter to a specific breakpoint (desktop, tablet, mobile, widescreen, laptop, tablet_extra, mobile_extra).',
                    ],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => ['type' => 'object'],
                    'error'   => ['type' => 'string'],
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
        $post_id      = $input['post_id'];
        $filter_bp    = $input['breakpoint'] ?? null;

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'error' => "Post $post_id not found."];
        }

        $raw  = get_post_meta($post_id, '_elementor_data', true);
        $tree = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($tree)) {
            return ['success' => false, 'error' => 'No Elementor data found on this post.'];
        }

        // Get active breakpoints from Elementor kit
        $active_breakpoints = ['desktop'];
        if (class_exists('\\Elementor\\Plugin')) {
            try {
                $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
                if ($kit) {
                    $kit_settings = $kit->get_settings();
                    if (!empty($kit_settings['active_breakpoints']) && is_array($kit_settings['active_breakpoints'])) {
                        $active_breakpoints = $kit_settings['active_breakpoints'];
                        if (!in_array('desktop', $active_breakpoints)) {
                            array_unshift($active_breakpoints, 'desktop');
                        }
                    }
                }
            } catch (\Throwable $e) {
                // fall back to defaults
            }
        }

        if ($filter_bp && !in_array($filter_bp, $active_breakpoints)) {
            return ['success' => false, 'error' => "Breakpoint '$filter_bp' is not active. Active: " . implode(', ', $active_breakpoints)];
        }

        // All known breakpoint suffixes for v3 responsive setting detection
        $all_bps = ['desktop', 'widescreen', 'laptop', 'tablet_extra', 'tablet', 'mobile_extra', 'mobile'];
        $bp_suffixes = array_diff($all_bps, ['desktop']); // desktop is default, no suffix

        $elements        = [];
        $total_elements  = 0;
        $responsive_count = 0;
        $visibility_map  = []; // breakpoint => visible element IDs

        foreach ($active_breakpoints as $bp) {
            $visibility_map[$bp] = [];
        }

        $walk = function(&$els, $depth = 0) use (&$walk, &$elements, &$total_elements, &$responsive_count, &$visibility_map, $active_breakpoints, $bp_suffixes, $all_bps) {
            foreach ($els as &$el) {
                $total_elements++;
                $el_id   = $el['id'] ?? null;
                $el_type = $el['elType'] ?? 'unknown';
                $widget  = $el['widgetType'] ?? null;

                $settings = $el['settings'] ?? [];
                $styles   = $el['styles'] ?? [];

                $responsive_settings = [];
                $responsive_visibility = [];
                $responsive_styles = [];

                // Detect v3-style responsive settings (suffixed keys)
                foreach ($settings as $key => $value) {
                    foreach ($bp_suffixes as $bp) {
                        if (str_ends_with($key, '_' . $bp)) {
                            $base_key = substr($key, 0, -(strlen($bp) + 1));
                            if (!isset($responsive_settings[$base_key])) {
                                $responsive_settings[$base_key] = [];
                            }
                            $responsive_settings[$base_key][$bp] = $value;
                        }
                    }
                }

                // Detect v3 responsive visibility controls
                foreach ($all_bps as $bp) {
                    if ($bp === 'desktop') continue;
                    $hide_key = 'hide_' . $bp;
                    if (isset($settings[$hide_key]) && $settings[$hide_key]) {
                        $responsive_visibility[$bp] = 'hidden';
                    } elseif (isset($settings[$hide_key])) {
                        $responsive_visibility[$bp] = 'visible';
                    }
                }

                // Detect v4-style responsive variants in styles
                if (is_array($styles)) {
                    foreach ($styles as $style_id => $style_data) {
                        if (isset($style_data['variants']) && is_array($style_data['variants'])) {
                            foreach ($style_data['variants'] as $variant) {
                                $bp = $variant['meta']['breakpoint'] ?? null;
                                $state = $variant['meta']['state'] ?? null;
                                if ($bp && $bp !== 'desktop') {
                                    if (!isset($responsive_styles[$bp])) {
                                        $responsive_styles[$bp] = [];
                                    }
                                    $variant_info = ['style_id' => $style_id];
                                    if ($state) {
                                        $variant_info['state'] = $state;
                                    }
                                    $variant_info['props_count'] = count($variant['props'] ?? []);
                                    $responsive_styles[$bp][] = $variant_info;
                                }
                            }
                        }
                    }
                }

                $has_responsive = !empty($responsive_settings) || !empty($responsive_visibility) || !empty($responsive_styles);

                if ($has_responsive) {
                    $responsive_count++;
                    $element_info = [
                        'element_id'   => $el_id,
                        'type'         => $el_type,
                    ];
                    if ($widget) {
                        $element_info['widget_type'] = $widget;
                    }
                    if (!empty($responsive_visibility)) {
                        $element_info['responsive_visibility'] = $responsive_visibility;
                    }
                    if (!empty($responsive_settings)) {
                        $element_info['responsive_settings'] = $responsive_settings;
                    }
                    if (!empty($responsive_styles)) {
                        $element_info['responsive_styles'] = $responsive_styles;
                    }
                    $elements[] = $element_info;
                }

                // Visibility map: determine for each breakpoint if this element is visible
                foreach ($active_breakpoints as $bp) {
                    if ($bp === 'desktop') {
                        // desktop always visible unless all breakpoints hide it (edge case)
                        $visibility_map[$bp][] = $el_id;
                    } else {
                        $hide_key = 'hide_' . $bp;
                        $hidden = isset($settings[$hide_key]) && $settings[$hide_key];
                        if (!$hidden) {
                            $visibility_map[$bp][] = $el_id;
                        }
                    }
                }

                if (!empty($el['elements'])) {
                    $walk($el['elements'], $depth + 1);
                }
            }
        };

        $walk($tree);

        // Build breakpoint visibility summary
        $bp_summary = [];
        foreach ($active_breakpoints as $bp) {
            $bp_summary[$bp] = [
                'visible_elements' => count($visibility_map[$bp]),
                'total_elements'   => $total_elements,
            ];
        }

        $result = [
            'post_id'           => $post_id,
            'post_title'        => $post->post_title,
            'active_breakpoints'=> $active_breakpoints,
            'total_elements'    => $total_elements,
            'responsive_elements'=> $responsive_count,
            'elements'          => $elements,
            'breakpoint_visibility' => $bp_summary,
        ];

        // If filter breakpoint, show full visibility list
        if ($filter_bp) {
            $result['filtered_breakpoint'] = $filter_bp;
            $result['visible_element_ids'] = $visibility_map[$filter_bp] ?? [];
        } else {
            $result['visible_element_ids_by_breakpoint'] = $visibility_map;
        }

        return [
            'success' => true,
            'data'    => $result,
        ];
    }
}

add_action('wp_abilities_api_init', [Responsive_Audit::class, 'register']);
