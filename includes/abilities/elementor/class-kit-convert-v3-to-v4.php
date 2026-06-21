<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Kit_Convert_V3_To_V4
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/kit-convert-v3-to-v4', [
            'label'               => 'Convert Kit v3 to v4',
            'description'         => 'Full orchestration: converts Elementor v3 Global Kit (colors + typography presets) into the v4 design-token system with variables, global classes, and responsive variants. All 4 phases in one call. Use this to modernize a v3 kit automatically.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'dry_run'         => ['type' => 'boolean', 'description' => 'Preview only, do not persist. Default: false.'],
                    'do_colors'       => ['type' => 'boolean', 'description' => 'Phase 1: Convert v3 colors to v4 color variables. Default: true.'],
                    'do_typography'   => ['type' => 'boolean', 'description' => 'Phase 2: Extract font families + size scale into v4 variables (deduplicated). Default: true.'],
                    'do_classes'      => ['type' => 'boolean', 'description' => 'Phase 3: Create v4 Global Classes from v3 typography presets, referencing Phase 1+2 variables. Default: true.'],
                    'do_responsive'   => ['type' => 'boolean', 'description' => 'Phase 4: Add responsive variants (tablet/mobile) where v3 presets had breakpoint overrides. Default: true.'],
                    'color_strategy'  => ['type' => 'string', 'description' => 'Conflict resolution for color variables: skip, overwrite, rename. Default: skip.'],
                    'typo_strategy'   => ['type' => 'string', 'description' => 'Conflict resolution for typography variables: skip, overwrite, rename. Default: skip.'],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'dry_run'          => ['type' => 'boolean'],
                    'phase_colors'     => ['type' => 'object'],
                    'phase_typography' => ['type' => 'object'],
                    'phase_classes'    => ['type' => 'object'],
                    'phase_responsive' => ['type' => 'object'],
                    'variable_map'     => ['type' => 'object'],
                    'class_map'        => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        // V4 guard (1.1.0): conversion TO V4 requires V4 to be available.
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error('v4_not_available', __('Kit conversion requires Elementor 4.0+ to be installed. The target V4 format is not available on this site.', 'novamira-adrianv2'));
        }

        $dry_run        = $input['dry_run'] ?? false;
        $do_colors      = $input['do_colors'] ?? true;
        $do_typography  = $input['do_typography'] ?? true;
        $do_classes     = $input['do_classes'] ?? true;
        $do_responsive  = $input['do_responsive'] ?? true;
        $color_strategy = $input['color_strategy'] ?? 'skip';
        $typo_strategy  = $input['typo_strategy'] ?? 'skip';

        if (!in_array($color_strategy, ['skip', 'overwrite', 'rename'], true)) {
            $color_strategy = 'skip';
        }
        if (!in_array($typo_strategy, ['skip', 'overwrite', 'rename'], true)) {
            $typo_strategy = 'skip';
        }

        $kit_id   = (int) get_option('elementor_active_kit');
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (!is_array($settings)) {
            $settings = [];
        }

        // Load existing v4 variables from CORRECT meta key
        [$existing_v4, $wrapper] = Helpers::load_v4_variables($kit_id);

        // Build label->ID lookup
        $v4_by_label = [];
        foreach ($existing_v4 as $id => $v) {
            $v4_by_label[$v['label'] ?? ''] = $id;
        }

        // Track highest existing order
        $max_order = 0;
        foreach ($existing_v4 as $var) {
            $max_order = max($max_order, $var['order'] ?? 0);
        }
        $now = current_time('mysql');

        // Map full type string -> short CSS var prefix
        $type_to_css_prefix = [
            'global-color-variable' => 'color',
            'global-font-variable'  => 'typography',
            'global-size-variable'  => 'size',
        ];

        $v3_colors = array_merge($settings['system_colors'] ?? [], $settings['custom_colors'] ?? []);
        $v3_typo   = array_merge($settings['system_typography'] ?? [], $settings['custom_typography'] ?? []);

        $variable_map = [];
        $class_map    = [];
        $results      = [
            'phase_colors'     => ['status' => 'skipped', 'created' => 0, 'items' => []],
            'phase_typography' => ['status' => 'skipped', 'created' => 0, 'items' => []],
            'phase_classes'    => ['status' => 'skipped', 'created' => 0, 'items' => []],
            'phase_responsive' => ['status' => 'skipped', 'created' => 0, 'items' => []],
        ];

        // =========================================================================
        // PHASE 1: Color Tokens
        // =========================================================================
        if ($do_colors) {
            $color_name_map = [
                'primary'  => 'primary-color',
                'secondary' => 'secondary-color',
                'text'      => 'text-color',
                'accent'    => 'accent-color',
                'c31bb41'   => 'alt-bg-light',
                '4caea53'   => 'alt-bg-dark',
                'c0566ed'   => 'icon-hover-active',
                'dd6077c'   => 'white',
                '469e44d'   => 'black',
                '7d4e430'   => 'border-opacity',
                '0a944ec'   => 'transparent',
            ];

            $phase1 = &$results['phase_colors'];
            $phase1['status'] = 'running';

            foreach ($v3_colors as $c) {
                $v3_id = $c['_id'] ?? $c['id'] ?? '';
                $title = $c['title'] ?? $v3_id;
                $color = $c['color'] ?? '';
                $label = $color_name_map[$v3_id] ?? Helpers::sanitize_label($title);

                if (empty($color)) {
                    $phase1['items'][] = ['v3_id' => $v3_id, 'v3_title' => $title, 'action' => 'error', 'error' => 'No color value'];
                    continue;
                }

                $action = 'created';
                $final_label = $label;

                if (isset($v4_by_label[$label])) {
                    if ($color_strategy === 'skip') {
                        $existing_id = $v4_by_label[$label];
                        $variable_map[$v3_id] = ['id' => $existing_id, 'label' => $label, 'type' => 'global-color-variable', 'value' => $color];
                        $phase1['items'][] = ['v3_id' => $v3_id, 'v3_title' => $title, 'action' => 'skipped', 'variable_label' => $label, 'variable_id' => $existing_id];
                        continue;
                    }
                    if ($color_strategy === 'overwrite') {
                        $action = 'overwritten';
                    }
                    if ($color_strategy === 'rename') {
                        $n = 1;
                        while (isset($v4_by_label["$label-$n"])) {
                            $n++;
                        }
                        $final_label = "$label-$n";
                        $action = 'renamed';
                    }
                }

                // Generate preview ID for dry_run
                $preview_id = 'e-gv-' . substr(md5($final_label . mt_rand()), 0, 7);
                $phase1['items'][] = ['v3_id' => $v3_id, 'v3_title' => $title, 'action' => $action, 'variable_label' => $final_label, 'variable_id' => $preview_id, 'value' => $color];

                if (!$dry_run) {
                    $wrapped_value = Helpers::wrap_variable_value('color', $color);

                    if ($action === 'overwritten') {
                        $old_id = $v4_by_label[$label];
                        $old_order = $existing_v4[$old_id]['order'] ?? $max_order + 1;
                        $old_created = $existing_v4[$old_id]['created_at'] ?? $now;

                        $existing_v4[$old_id] = [
                            'label'      => $final_label,
                            'value'      => $wrapped_value,
                            'type'       => 'global-color-variable',
                            'order'      => $old_order,
                            'created_at' => $old_created,
                            'updated_at' => $now,
                        ];
                        if ($final_label !== $label) {
                            $v4_by_label[$final_label] = $old_id;
                            unset($v4_by_label[$label]);
                        }
                        $variable_map[$v3_id] = ['id' => $old_id, 'label' => $final_label, 'type' => 'global-color-variable', 'value' => $color];
                    } else {
                        $max_order++;
                        $new_id = 'e-gv-' . substr(md5($final_label . mt_rand()), 0, 7);
                        $existing_v4[$new_id] = [
                            'label'      => $final_label,
                            'value'      => $wrapped_value,
                            'type'       => 'global-color-variable',
                            'order'      => $max_order,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $v4_by_label[$final_label] = $new_id;
                        $variable_map[$v3_id] = ['id' => $new_id, 'label' => $final_label, 'type' => 'global-color-variable', 'value' => $color];
                    }

                    Helpers::save_v4_variables($kit_id, $existing_v4, $wrapper);
                }

                $phase1['created']++;
            }
        }

        // =========================================================================
        // PHASE 2: Typography Tokens (deduplicated)
        // =========================================================================
        $font_vars = [];
        $size_vars = [];

        if ($do_typography) {
            $phase2 = &$results['phase_typography'];
            $phase2['status'] = 'running';

            // Deduplicate font families
            $fonts = [];
            foreach ($v3_typo as $t) {
                $ff = $t['typography_font_family'] ?? '';
                if (!empty($ff)) {
                    $fonts[$ff] = ($fonts[$ff] ?? 0) + 1;
                }
            }

            $font_name_hints = [
                'Montserrat Alternates' => 'font-heading',
                'Poppins'               => 'font-body',
            ];
            $font_count = 0;
            foreach ($fonts as $font_name => $count) {
                $semantic = $font_name_hints[$font_name] ?? Helpers::sanitize_label($font_name);
                $font_vars[$font_name] = $semantic;

                $action = 'created';
                $final_label = $semantic;
                if (isset($v4_by_label[$semantic])) {
                    if ($typo_strategy === 'skip') {
                        $existing_id = $v4_by_label[$semantic];
                        $variable_map["font:$semantic"] = ['id' => $existing_id, 'label' => $semantic, 'type' => 'global-font-variable', 'value' => $font_name];
                        $phase2['items'][] = ['type' => 'font', 'label' => $semantic, 'value' => $font_name, 'v3_usage_count' => $count, 'action' => 'skipped'];
                        continue;
                    }
                    if ($typo_strategy === 'overwrite') {
                        $action = 'overwritten';
                    }
                    if ($typo_strategy === 'rename') {
                        $n = 1;
                        while (isset($v4_by_label["$semantic-$n"])) {
                            $n++;
                        }
                        $final_label = "$semantic-$n";
                        $action = 'renamed';
                    }
                }

                $preview_id = 'e-gv-' . substr(md5($final_label . mt_rand()), 0, 7);
                $phase2['items'][] = ['type' => 'font', 'label' => $final_label, 'value' => $font_name, 'v3_usage_count' => $count, 'action' => $action, 'variable_id' => $preview_id];
                $font_count++;

                if (!$dry_run) {
                    $wrapped_value = Helpers::wrap_variable_value('font', $font_name);

                    if ($action === 'overwritten') {
                        $old_id = $v4_by_label[$semantic];
                        $old_order = $existing_v4[$old_id]['order'] ?? $max_order + 1;
                        $old_created = $existing_v4[$old_id]['created_at'] ?? $now;

                        $existing_v4[$old_id] = [
                            'label'      => $final_label,
                            'value'      => $wrapped_value,
                            'type'       => 'global-font-variable',
                            'order'      => $old_order,
                            'created_at' => $old_created,
                            'updated_at' => $now,
                        ];
                        if ($final_label !== $semantic) {
                            $v4_by_label[$final_label] = $old_id;
                            unset($v4_by_label[$semantic]);
                        }
                        $variable_map["font:$semantic"] = ['id' => $old_id, 'label' => $final_label, 'type' => 'global-font-variable', 'value' => $font_name];
                    } else {
                        $max_order++;
                        $new_id = 'e-gv-' . substr(md5($final_label . mt_rand()), 0, 7);
                        $existing_v4[$new_id] = [
                            'label'      => $final_label,
                            'value'      => $wrapped_value,
                            'type'       => 'global-font-variable',
                            'order'      => $max_order,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $v4_by_label[$final_label] = $new_id;
                        $variable_map["font:$semantic"] = ['id' => $new_id, 'label' => $final_label, 'type' => 'global-font-variable', 'value' => $font_name];
                    }

                    Helpers::save_v4_variables($kit_id, $existing_v4, $wrapper);
                }
            }

            // Deduplicate font sizes
            $sizes = [];
            foreach ($v3_typo as $t) {
                $fs = $t['typography_font_size'] ?? null;
                if (is_array($fs) && isset($fs['size']) && !empty($fs['size'])) {
                    $key = $fs['size'] . ($fs['unit'] ?? 'px');
                    if (!isset($sizes[$key])) {
                        $sizes[$key] = ['size' => (float) $fs['size'], 'unit' => $fs['unit'] ?? 'px', 'count' => 0];
                    }
                    $sizes[$key]['count']++;
                }
            }
            ksort($sizes);

            $size_names = ['size-xs', 'size-sm', 'size-md', 'size-lg', 'size-xl', 'size-2xl', 'size-3xl', 'size-4xl', 'size-5xl'];
            $size_idx = 0;

            foreach ($sizes as $key => $sd) {
                $semantic = $size_names[$size_idx] ?? "size-{$sd['size']}{$sd['unit']}";
                $size_vars[$key] = $semantic;
                $size_idx++;

                $size_val = $sd['size'] . $sd['unit'];
                $action = 'created';
                $final_label = $semantic;
                if (isset($v4_by_label[$semantic])) {
                    if ($typo_strategy === 'skip') {
                        $existing_id = $v4_by_label[$semantic];
                        $variable_map["size:$semantic"] = ['id' => $existing_id, 'label' => $semantic, 'type' => 'global-size-variable', 'value' => $size_val];
                        $phase2['items'][] = ['type' => 'size', 'label' => $semantic, 'value' => $size_val, 'v3_usage_count' => $sd['count'], 'action' => 'skipped'];
                        continue;
                    }
                    if ($typo_strategy === 'overwrite') {
                        $action = 'overwritten';
                    }
                    if ($typo_strategy === 'rename') {
                        $n = 1;
                        while (isset($v4_by_label["$semantic-$n"])) {
                            $n++;
                        }
                        $final_label = "$semantic-$n";
                        $action = 'renamed';
                    }
                }

                $preview_id = 'e-gv-' . substr(md5($final_label . mt_rand()), 0, 7);
                $phase2['items'][] = ['type' => 'size', 'label' => $final_label, 'value' => $size_val, 'v3_usage_count' => $sd['count'], 'action' => $action, 'variable_id' => $preview_id];

                if (!$dry_run) {
                    $wrapped_value = Helpers::wrap_variable_value('size', $size_val);

                    if ($action === 'overwritten') {
                        $old_id = $v4_by_label[$semantic];
                        $old_order = $existing_v4[$old_id]['order'] ?? $max_order + 1;
                        $old_created = $existing_v4[$old_id]['created_at'] ?? $now;

                        $existing_v4[$old_id] = [
                            'label'      => $final_label,
                            'value'      => $wrapped_value,
                            'type'       => 'global-size-variable',
                            'order'      => $old_order,
                            'created_at' => $old_created,
                            'updated_at' => $now,
                        ];
                        if ($final_label !== $semantic) {
                            $v4_by_label[$final_label] = $old_id;
                            unset($v4_by_label[$semantic]);
                        }
                        $variable_map["size:$semantic"] = ['id' => $old_id, 'label' => $final_label, 'type' => 'global-size-variable', 'value' => $size_val];
                    } else {
                        $max_order++;
                        $new_id = 'e-gv-' . substr(md5($final_label . mt_rand()), 0, 7);
                        $existing_v4[$new_id] = [
                            'label'      => $final_label,
                            'value'      => $wrapped_value,
                            'type'       => 'global-size-variable',
                            'order'      => $max_order,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        $v4_by_label[$final_label] = $new_id;
                        $variable_map["size:$semantic"] = ['id' => $new_id, 'label' => $final_label, 'type' => 'global-size-variable', 'value' => $size_val];
                    }

                    Helpers::save_v4_variables($kit_id, $existing_v4, $wrapper);
                }
            }

            $phase2['created'] = $font_count + $size_idx;
        }

        // =========================================================================
        // PHASE 3: Global Classes from Typography Presets
        // =========================================================================
        if ($do_classes && !empty($v3_typo)) {
            $phase3 = &$results['phase_classes'];
            $phase3['status'] = 'running';

            foreach ($v3_typo as $t) {
                $v3_id       = $t['_id'] ?? $t['id'] ?? '';
                $title       = $t['title'] ?? $v3_id;
                $class_label = Helpers::sanitize_label($title);

                $ff            = $t['typography_font_family'] ?? '';
                $font_semantic = $font_vars[$ff] ?? null;
                $font_var      = $font_semantic ? ($variable_map["font:$font_semantic"] ?? null) : null;

                $fs            = $t['typography_font_size'] ?? null;
                $fs_key        = (is_array($fs) && isset($fs['size']) && !empty($fs['size'])) ? ($fs['size'] . ($fs['unit'] ?? 'px')) : null;
                $size_semantic = $size_vars[$fs_key] ?? null;
                $size_var      = $size_semantic ? ($variable_map["size:$size_semantic"] ?? null) : null;

                $props = [];

                if ($font_var) {
                    $props['font-family'] = ['$$type' => 'global-font-variable', 'value' => $font_var['id']];
                } elseif (!empty($ff)) {
                    $props['font-family'] = ['$$type' => 'string', 'value' => $ff];
                }

                if ($size_var) {
                    $props['font-size'] = ['$$type' => 'global-size-variable', 'value' => $size_var['id']];
                } elseif ($fs && isset($fs['size']) && !empty($fs['size'])) {
                    $props['font-size'] = ['$$type' => 'size', 'value' => ['size' => (float) $fs['size'], 'unit' => $fs['unit'] ?? 'px']];
                }

                $fw = $t['typography_font_weight'] ?? '';
                if ($fw !== '' && $fw !== null) {
                    $props['font-weight'] = ['$$type' => 'string', 'value' => (string) $fw];
                }

                $lh = $t['typography_line_height'] ?? null;
                if (is_array($lh) && isset($lh['size']) && !empty($lh['size'])) {
                    $props['line-height'] = ['$$type' => 'size', 'value' => ['size' => (float) $lh['size'], 'unit' => $lh['unit'] ?? 'em']];
                }

                if (empty($props)) {
                    $phase3['items'][] = ['v3_id' => $v3_id, 'v3_title' => $title, 'action' => 'error', 'error' => 'No typography properties to convert'];
                    continue;
                }

                $has_resp = isset($t['typography_font_size_mobile']) || isset($t['typography_font_size_tablet']);
                $phase3['items'][] = ['v3_id' => $v3_id, 'v3_title' => $title, 'action' => 'created', 'class_label' => $class_label, 'props' => $props, 'has_responsive' => $has_resp];

                if (!$dry_run) {
                    $class_id = 'gc-' . substr(md5($class_label . mt_rand()), 0, 16);
                    $post_id = wp_insert_post([
                        'post_type'   => 'e_global_class',
                        'post_title'  => $class_label,
                        'post_status' => 'publish',
                    ]);

                    if (is_wp_error($post_id) || !$post_id) {
                        $last = count($phase3['items']) - 1;
                        $phase3['items'][$last]['action'] = 'error';
                        $phase3['items'][$last]['error'] = is_wp_error($post_id) ? $post_id->get_error_message() : 'Post creation failed';
                        continue;
                    }

                    update_post_meta($post_id, '_elementor_global_class_id', $class_id);
                    $class_data = [
                        'type'     => 'class',
                        'variants' => [
                            ['meta' => ['breakpoint' => null, 'state' => null], 'props' => $props],
                        ],
                    ];
                    update_post_meta($post_id, '_elementor_global_class_data', $class_data);

                    $class_map[$v3_id] = $class_id;
                    $phase3['created']++;

                    // Update kit labels
                    $labels = get_post_meta($kit_id, '_elementor_global_classes_labels', true);
                    if (!is_array($labels)) {
                        $labels = [];
                    }
                    $labels[$class_id] = $class_label;
                    update_post_meta($kit_id, '_elementor_global_classes_labels', $labels);

                    // Update kit order
                    $order = get_post_meta($kit_id, '_elementor_global_classes_order', true);
                    if (!is_array($order)) {
                        $order = ['order' => []];
                    }
                    if (!isset($order['order'])) {
                        $order['order'] = [];
                    }
                    $order['order'][] = $class_id;
                    update_post_meta($kit_id, '_elementor_global_classes_order', $order);
                }
            }
        }

        // =========================================================================
        // PHASE 4: Responsive Variants
        // =========================================================================
        if ($do_responsive && !empty($v3_typo)) {
            $phase4 = &$results['phase_responsive'];
            $phase4['status'] = 'running';

            foreach ($v3_typo as $t) {
                $v3_id = $t['_id'] ?? $t['id'] ?? '';

                $has_mobile = isset($t['typography_font_size_mobile']);
                $has_tablet = isset($t['typography_font_size_tablet']);

                if (!$has_mobile && !$has_tablet) {
                    continue;
                }

                $class_id = $class_map[$v3_id] ?? null;
                if (!$class_id) {
                    $phase4['items'][] = ['v3_id' => $v3_id, 'v3_title' => $t['title'] ?? $v3_id, 'action' => 'error', 'error' => 'No class_id -- run phase 3 first'];
                    continue;
                }

                $phase4['items'][] = ['v3_id' => $v3_id, 'v3_title' => $t['title'] ?? $v3_id, 'action' => 'responsive', 'class_id' => $class_id, 'has_mobile' => $has_mobile, 'has_tablet' => $has_tablet];

                if (!$dry_run) {
                    $posts = get_posts([
                        'post_type'      => 'e_global_class',
                        'meta_key'       => '_elementor_global_class_id',
                        'meta_value'     => $class_id,
                        'posts_per_page' => 1,
                        'post_status'    => 'any',
                    ]);
                    if (empty($posts)) {
                        continue;
                    }
                    $post = $posts[0];

                    $data = get_post_meta($post->ID, '_elementor_global_class_data', true);
                    if (!is_array($data)) {
                        $data = [];
                    }
                    $variants = $data['variants'] ?? [];

                    $desktop_props = $variants[0]['props'] ?? [];
                    $fs_var_id = null;
                    if (($desktop_props['font-size']['$$type'] ?? '') === 'global-size-variable') {
                        $fs_var_id = $desktop_props['font-size']['value'] ?? null;
                    }

                    if ($has_tablet) {
                        $tfs = $t['typography_font_size_tablet'];
                        if ($fs_var_id) {
                            $tablet_props = [
                                'font-size' => ['$$type' => 'global-size-variable', 'value' => $fs_var_id],
                            ];
                        } else {
                            $tablet_props = [
                                'font-size' => ['$$type' => 'size', 'value' => [
                                    'size' => (float) $tfs['size'], 'unit' => $tfs['unit'] ?? 'px',
                                ]],
                            ];
                        }
                        $exists = false;
                        foreach ($variants as $v) {
                            if (($v['meta']['breakpoint'] ?? '') === 'tablet' && ($v['meta']['state'] ?? null) === null) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $variants[] = ['meta' => ['breakpoint' => 'tablet', 'state' => null], 'props' => $tablet_props];
                        }
                    }

                    if ($has_mobile) {
                        $mfs = $t['typography_font_size_mobile'];
                        if ($fs_var_id) {
                            $mobile_props = [
                                'font-size' => ['$$type' => 'global-size-variable', 'value' => $fs_var_id],
                            ];
                        } else {
                            $mobile_props = [
                                'font-size' => ['$$type' => 'size', 'value' => [
                                    'size' => (float) $mfs['size'], 'unit' => $mfs['unit'] ?? 'px',
                                ]],
                            ];
                        }
                        if (isset($t['typography_line_height_mobile'])) {
                            $mlh = $t['typography_line_height_mobile'];
                            $mobile_props['line-height'] = ['$$type' => 'size', 'value' => ['size' => (float) $mlh['size'], 'unit' => $mlh['unit'] ?? 'em']];
                        }
                        $exists = false;
                        foreach ($variants as $v) {
                            if (($v['meta']['breakpoint'] ?? '') === 'mobile' && ($v['meta']['state'] ?? null) === null) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $variants[] = ['meta' => ['breakpoint' => 'mobile', 'state' => null], 'props' => $mobile_props];
                        }
                    }

                    $data['variants'] = $variants;
                    update_post_meta($post->ID, '_elementor_global_class_data', $data);
                    $phase4['created']++;
                }
            }
        }

        if (!$dry_run) {
            Guards::invalidate_all_elementor_caches();
        }

        return [
            'dry_run'          => $dry_run,
            'phase_colors'     => $results['phase_colors'],
            'phase_typography' => $results['phase_typography'],
            'phase_classes'    => $results['phase_classes'],
            'phase_responsive' => $results['phase_responsive'],
            'variable_map'     => $variable_map,
            'class_map'        => $class_map,
        ];
    }
}

add_action('wp_abilities_api_init', [Kit_Convert_V3_To_V4::class, 'register']);
