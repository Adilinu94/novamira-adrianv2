<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Audit;

if (!defined('ABSPATH')) {
    exit();
}

class Visual_Qa
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/visual-qa', [
            'label'               => 'Visual QA',
            'description'         => 'Audit a page for visual quality issues: overflow risks (fixed px widths exceeding viewport), z-index stacking conflicts, negative margins that may cause overlap, and absolute-positioned overlap risks. Checks both desktop and responsive breakpoints.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [
                        'type'        => 'integer',
                        'description' => 'ID of the Elementor page to audit.',
                    ],
                    'checks'     => [
                        'type'        => 'array',
                        'description' => 'Which checks to run. Omit or pass all to run every check.',
                        'items'       => [
                            'type' => 'string',
                            'enum' => ['overflow', 'z_index', 'negative_margins', 'overlap', 'fixed_dimensions'],
                        ],
                    ],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => ['type' => 'boolean'],
                    'post_id'        => ['type' => 'integer'],
                    'post_title'     => ['type' => 'string'],
                    'total_issues'   => ['type' => 'integer'],
                    'by_severity'    => ['type' => 'object'],
                    'by_check'       => ['type' => 'object'],
                    'issues'         => ['type' => 'array', 'items' => ['type' => 'object']],
                    'message'        => ['type' => 'string'],
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
        $post_id = absint($input['post_id']);
        $checks  = $input['checks'] ?? ['overflow', 'z_index', 'negative_margins', 'overlap', 'fixed_dimensions'];

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'message' => 'Post not found.'];
        }

        $page_data = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($page_data)) {
            $page_data = json_decode($page_data, true);
        }
        if (!is_array($page_data)) {
            return ['success' => false, 'message' => 'No Elementor data found.'];
        }

        // Get active breakpoints for context
        $kit_id = get_option('elementor_active_kit');
        $kit_settings = get_post_meta($kit_id, '_elementor_page_settings', true);
        if (is_string($kit_settings)) { $kit_settings = maybe_unserialize($kit_settings); }
        $active_breakpoints = $kit_settings['active_breakpoints'] ?? [];

        $breakpoint_widths = [
            'desktop' => (int) ($kit_settings['viewport_lg'] ?? 0),
            'tablet'  => (int) ($active_breakpoints['viewport_tablet']['viewport_width'] ?? 768),
            'mobile'  => (int) ($active_breakpoints['viewport_mobile']['viewport_width'] ?? 360),
        ];

        $issues          = [];
        $z_index_map     = []; // element_id => z_index
        $absolute_els    = [];
        $all_els         = [];

        $isV4 = function($value) {
            return is_array($value) && isset($value['type']) && isset($value['value']);
        };

        $unwrap = function($value) use ($isV4) {
            return $isV4($value) ? $value['value'] : $value;
        };

        $toPx = function($val, $unit) {
            $v = floatval($val);
            if ($unit === 'rem' || $unit === 'em') { return $v * 16; }
            if ($unit === 'vh') { return $v * 10; }
            if ($unit === 'vw') { return $v * 14; }
            return $v;
        };

        $element_label = function($el) {
            $type = $el['widgetType'] ?? $el['elType'] ?? 'unknown';
            $title = $el['settings']['title'] ?? $el['settings']['editor'] ?? $el['settings']['text'] ?? '';
            if (is_array($title)) { $title = $title['value'] ?? ($title['text'] ?? ''); }
            $title = wp_strip_all_tags((string) $title);
            $label = $type;
            if ($title && strlen($title) < 60) {
                $label .= ' "' . $title . '"';
            }
            return $label;
        };

        $addIssue = function($severity, $check, $el, $msg) use (&$issues) {
            $issues[] = [
                'severity'    => $severity,
                'type'        => $check,
                'element_id'  => $el['id'],
                'element_type' => $el['widgetType'] ?? $el['elType'] ?? 'unknown',
                'message'     => $msg,
            ];
        };

        // Collect element info in a single pass
        $collect_info = function(&$els, $parent = null, $depth = 0) use (&$collect_info, &$z_index_map, &$absolute_els, &$all_els, $unwrap, $isV4, $toPx, $addIssue, $checks, $breakpoint_widths, $element_label) {
            foreach ($els as &$el) {
                $settings = $el['settings'] ?? [];
                $elType   = $el['elType'] ?? 'widget';
                $wType    = $el['widgetType'] ?? '';

                $all_els[$el['id']] = [
                    'id'        => $el['id'],
                    'elType'    => $elType,
                    'widgetType' => $wType,
                    'parent'    => $parent,
                    'depth'     => $depth,
                    'label'     => $element_label($el),
                ];

                // Z-Index check
                if (in_array('z_index', $checks, true)) {
                    $z_index = $settings['_z_index'] ?? $settings['z_index'] ?? null;
                    if ($z_index !== null && $z_index !== '') {
                        $zi = (int) $unwrap($z_index);
                        $z_index_map[$el['id']] = $zi;

                        if ($zi > 100) {
                            $addIssue('warning', 'z_index', $el,
                                sprintf('Very high z-index: %d. This may cause stacking issues and is often unnecessary.', $zi));
                        } elseif ($zi > 50) {
                            $addIssue('info', 'z_index', $el,
                                sprintf('Elevated z-index: %d. Review if this is needed.', $zi));
                        }
                    }
                }

                // Overflow check -- fixed width exceeding breakpoint widths
                if (in_array('overflow', $checks, true)) {
                    $width = $settings['width'] ?? $settings['_width'] ?? $settings['custom_width'] ?? null;
                    if ($width) {
                        $wVal  = $isV4($width) ? $width['value'] : ($width['size'] ?? $width);
                        $wUnit = $isV4($width) ? ($width['unit'] ?? 'px') : ($width['unit'] ?? 'px');
                        $wPx   = $toPx($wVal, $wUnit);

                        $overflow_breakpoints = [];
                        foreach (['mobile' => 360, 'tablet' => 768] as $bp => $bpWidth) {
                            // Check responsive width overrides
                            $resp_key = 'width_' . $bp;
                            $resp_width = $settings[$resp_key] ?? null;
                            if ($resp_width) {
                                $rVal  = $isV4($resp_width) ? $resp_width['value'] : ($resp_width['size'] ?? $resp_width);
                                $rUnit = $isV4($resp_width) ? ($resp_width['unit'] ?? 'px') : ($resp_width['unit'] ?? 'px');
                                $rPx   = $toPx($rVal, $rUnit);
                                if ($rUnit === 'px' && $rPx > $bpWidth) {
                                    $overflow_breakpoints[] = $bp;
                                }
                            } elseif ($wUnit === 'px' && $wPx > $bpWidth) {
                                $overflow_breakpoints[] = $bp;
                            }
                        }
                        if (!empty($overflow_breakpoints)) {
                            $addIssue('warning', 'overflow', $el,
                                sprintf('Fixed width %.0f%s may overflow on: %s (viewport widths: mobile=360px, tablet=768px).',
                                    $wVal, $wUnit, implode(', ', $overflow_breakpoints)));
                        }
                    }

                    // Check for horizontal overflow via padding + width
                    $padding = $settings['padding'] ?? null;
                    if ($padding) {
                        $pLeft  = floatval($isV4($padding) ? ($padding['value']['left'] ?? 0) : ($padding['left'] ?? 0));
                        $pRight = floatval($isV4($padding) ? ($padding['value']['right'] ?? 0) : ($padding['right'] ?? 0));
                        $pUnit  = $isV4($padding) ? ($padding['unit'] ?? 'px') : ($padding['unit'] ?? 'px');
                        $totalPaddingPx = $toPx($pLeft + $pRight, $pUnit);
                        if ($totalPaddingPx > 100) {
                            $addIssue('info', 'overflow', $el,
                                sprintf('Large horizontal padding: %.0f%s (%.0fpx). Check mobile layout.', $pLeft + $pRight, $pUnit, $totalPaddingPx));
                        }
                    }
                }

                // Fixed dimensions check
                if (in_array('fixed_dimensions', $checks, true)) {
                    foreach (['width' => 'Width', 'height' => 'Height', 'min_height' => 'Min Height', 'custom_height' => 'Height'] as $key => $label) {
                        $val = $settings[$key] ?? null;
                        if (!$val) continue;
                        $vSize = $isV4($val) ? ($val['value'] ?? $val['size'] ?? null) : ($val['size'] ?? $val);
                        $vUnit = $isV4($val) ? ($val['unit'] ?? 'px') : ($val['unit'] ?? 'px');
                        if (!$vSize) continue;
                        $vPx = $toPx($vSize, $vUnit);

                        if ($vUnit === 'px' && $vPx > 500) {
                            $addIssue('info', 'fixed_dimensions', $el,
                                sprintf('%s: %.0fpx is fixed and large. May cause issues on smaller screens. Consider using %% or vw.', $label, $vSize));
                        }
                        // Warn about vh units (common iOS issues)
                        if ($vUnit === 'vh' && $vSize > 50) {
                            $addIssue('warning', 'fixed_dimensions', $el,
                                sprintf('%s: %.0fvh. vh units can cause issues on mobile browsers with dynamic toolbars.', $label, $vSize));
                        }
                    }
                }

                // Negative margins check
                if (in_array('negative_margins', $checks, true)) {
                    foreach (['margin', '_margin', 'margin_tablet', '_margin_tablet', 'margin_mobile', '_margin_mobile'] as $margin_key) {
                        $margin = $settings[$margin_key] ?? null;
                        if (!$margin) continue;
                        $mTop    = floatval($isV4($margin) ? ($margin['value']['top'] ?? 0) : ($margin['top'] ?? 0));
                        $mRight  = floatval($isV4($margin) ? ($margin['value']['right'] ?? 0) : ($margin['right'] ?? 0));
                        $mBottom = floatval($isV4($margin) ? ($margin['value']['bottom'] ?? 0) : ($margin['bottom'] ?? 0));
                        $mLeft   = floatval($isV4($margin) ? ($margin['value']['left'] ?? 0) : ($margin['left'] ?? 0));
                        $mUnit   = $isV4($margin) ? ($margin['unit'] ?? 'px') : ($margin['unit'] ?? 'px');

                        $negatives = [];
                        if ($mTop < 0) { $negatives[] = sprintf('top: %s%s', $mTop, $mUnit); }
                        if ($mRight < 0) { $negatives[] = sprintf('right: %s%s', $mRight, $mUnit); }
                        if ($mBottom < 0) { $negatives[] = sprintf('bottom: %s%s', $mBottom, $mUnit); }
                        if ($mLeft < 0) { $negatives[] = sprintf('left: %s%s', $mLeft, $mUnit); }

                        if (!empty($negatives)) {
                            $bp_suffix = str_replace(['_margin', 'margin_', '__'], '', $margin_key);
                            $bp_label  = in_array($bp_suffix, ['tablet', 'mobile']) ? " ($bp_suffix)" : '';
                            $addIssue('warning', 'negative_margins', $el,
                                sprintf('Negative margins%s: %s. This may cause element overlap or overflow.', $bp_label, implode(', ', $negatives)));
                            break; // One issue per element for negative margins
                        }
                    }
                }

                // Overlap check -- absolute positioned elements
                if (in_array('overlap', $checks, true)) {
                    $position = $settings['_position'] ?? $settings['position'] ?? null;
                    $pos_val  = $unwrap($position);
                    if ($pos_val === 'absolute' || $pos_val === 'fixed') {
                        $offset_x = floatval($unwrap($settings['_offset_x'] ?? $settings['offset_x'] ?? 0));
                        $offset_y = floatval($unwrap($settings['_offset_y'] ?? $settings['offset_y'] ?? 0));

                        $absolute_els[] = [
                            'id'         => $el['id'],
                            'label'      => $element_label($el),
                            'position'   => $pos_val,
                            'offset_x'   => $offset_x,
                            'offset_y'   => $offset_y,
                            'z_index'    => $z_index_map[$el['id']] ?? 0,
                            'parent'     => $parent,
                            'depth'      => $depth,
                        ];

                        if ($pos_val === 'fixed') {
                            $addIssue('info', 'overlap', $el,
                                'Fixed-position element. May overlap with sticky headers, admin bars, or other fixed elements.');
                        }
                    }
                }

                if (!empty($el['elements'])) {
                    $collect_info($el['elements'], $el['id'], $depth + 1);
                }
            }
        };

        $collect_info($page_data);

        // Post-processing: check for z-index conflicts
        if (in_array('z_index', $checks, true)) {
            $by_z = [];
            foreach ($z_index_map as $el_id => $zi) {
                $by_z[$zi][] = $el_id;
            }
            foreach ($by_z as $zi => $el_ids) {
                if (count($el_ids) > 1) {
                    foreach ($el_ids as $el_id) {
                        $el_info = $all_els[$el_id] ?? null;
                        if ($el_info) {
                            $issues[] = [
                                'severity'     => 'info',
                                'type'         => 'z_index',
                                'element_id'   => $el_id,
                                'element_type' => $el_info['elType'],
                                'message'      => sprintf(
                                    'Shared z-index %d with %d other element(s) (%s). Verify intended stacking order.',
                                    $zi, count($el_ids) - 1,
                                    implode(', ', array_diff($el_ids, [$el_id]))
                                ),
                            ];
                        }
                    }
                }
            }
        }

        // Post-processing: check for overlapping absolute elements at same depth
        if (in_array('overlap', $checks, true) && count($absolute_els) > 1) {
            for ($i = 0; $i < count($absolute_els); $i++) {
                for ($j = $i + 1; $j < count($absolute_els); $j++) {
                    $a = $absolute_els[$i];
                    $b = $absolute_els[$j];
                    // Same parent and same depth = potential overlap
                    if ($a['parent'] === $b['parent'] && $a['depth'] === $b['depth']) {
                        $issues[] = [
                            'severity'     => 'info',
                            'type'         => 'overlap',
                            'element_id'   => $a['id'],
                            'element_type' => 'absolute-positioned',
                            'message'      => sprintf(
                                'Potential overlap with "%s" (#%s). Both are %s-positioned siblings in the same container.',
                                $b['label'], $b['id'], $a['position']
                            ),
                        ];
                    }
                }
            }
        }

        // Build summary
        $by_severity = ['error' => 0, 'warning' => 0, 'info' => 0];
        $by_check    = [];
        foreach ($issues as $issue) {
            $by_severity[$issue['severity']]++;
            $by_check[$issue['type']] = ($by_check[$issue['type']] ?? 0) + 1;
        }

        return [
            'success'      => true,
            'post_id'      => $post_id,
            'post_title'   => $post->post_title,
            'total_issues' => count($issues),
            'by_severity'  => $by_severity,
            'by_check'     => $by_check,
            'issues'       => $issues,
            'breakpoint_info' => [
                'active_breakpoints' => array_keys($active_breakpoints),
                'estimated_widths'   => $breakpoint_widths,
            ],
            'message'      => sprintf(
                'Visual QA found %d issues (%d warnings, %d info) in "%s" across %d checks.',
                count($issues), $by_severity['warning'], $by_severity['info'],
                $post->post_title, count($checks)
            ),
        ];
    }
}

add_action('wp_abilities_api_init', [Visual_Qa::class, 'register']);
