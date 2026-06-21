<?php
/**
 * Ability: Design Repair Utilities
 *
 * Destructive design repair tools for Elementor pages.
 * Each ability modifies _elementor_data in-place via post meta.
 *
 * Ported from mcp-abilities-elementor v2.3.12 design repair suite.
 *
 * Registered abilities:
 * - novamira-adrianv2/zero-container-padding
 * - novamira-adrianv2/reset-negative-margins
 * - novamira-adrianv2/copy-lane-settings
 * - novamira-adrianv2/enforce-boundary-coherence
 * - novamira-adrianv2/apply-text-hierarchy
 * - novamira-adrianv2/normalize-responsive-values
 * - novamira-adrianv2/sync-component-variant
 * - novamira-adrianv2/normalize-section-spacing
 * - novamira-adrianv2/image-to-background
 * - novamira-adrianv2/fix-gap-rhythm
 * - novamira-adrianv2/evaluate-render-context (read-only)
 * - novamira-adrianv2/get-style-guide (read-only)
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\DesignUtilities;

if (!defined('ABSPATH')) {
    exit();
}

class Design_Repair
{
    private const LANE_KEYS = [
        'content_width', 'boxed_width', 'width', 'min_height',
        'padding', '_padding', 'padding_tablet', 'padding_mobile',
        '_margin', '_margin_tablet', '_margin_mobile',
        'gap', 'gap_between_elements', 'flex_direction',
        'align_items', 'justify_content', 'flex_wrap',
    ];

    private const MARGIN_KEYS = ['_margin', '_margin_tablet', '_margin_mobile'];

    /**
     * Register all design repair abilities.
     */
    public static function register(): void
    {
        $common_meta = [
            'show_in_rest' => true,
            'mcp'          => ['public' => true],
        ];

        $read_annotations = ['readonly' => true, 'destructive' => false, 'idempotent' => true];
        $write_annotations = ['readonly' => false, 'destructive' => true, 'idempotent' => true];

        // 1. zero-container-padding
        wp_register_ability('novamira-adrianv2/zero-container-padding', [
            'label'       => 'Zero Container Padding',
            'description' => 'Recursively sets container padding to zero in a subtree. Useful for removing inherited padding before applying a fresh spacing system. Note: operates on V3-style settings (_padding, padding); V4-style styles arrays are not modified.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'      => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'element_id'   => ['type' => 'string', 'description' => 'Root element ID to start from. Omit to start from top-level.'],
                    'include_root' => ['type' => 'boolean', 'description' => 'Include the root element. Default: false.'],
                    'max_depth'    => ['type' => 'integer', 'description' => 'Max depth to process. -1 = unlimited. Default: -1.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'changed_count'   => ['type' => 'integer'],
                    'changed_ids'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_zero_padding'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 2. reset-negative-margins
        wp_register_ability('novamira-adrianv2/reset-negative-margins', [
            'label'       => 'Reset Negative Margins',
            'description' => 'Recursively clamps negative margins to 0 for widgets and/or containers in a subtree. Handles desktop, tablet, and mobile margin variants. Note: operates on V3-style settings.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'      => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'element_id'   => ['type' => 'string', 'description' => 'Root element ID. Omit to process all.'],
                    'widgets_only' => ['type' => 'boolean', 'description' => 'Only process widgets. Default: false (process containers too).'],
                    'include_root' => ['type' => 'boolean', 'description' => 'Include root. Default: true.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'changed_count'   => ['type' => 'integer'],
                    'changed_ids'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_reset_negative_margins'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 3. copy-lane-settings
        wp_register_ability('novamira-adrianv2/copy-lane-settings', [
            'label'       => 'Copy Lane Settings',
            'description' => 'Copies layout-defining settings (content_width, padding, margins, gap, flex properties) from a source element to a target element.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'           => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'source_element_id' => ['type' => 'string', 'description' => 'Element ID to copy settings from.'],
                    'target_element_id' => ['type' => 'string', 'description' => 'Element ID to apply settings to.'],
                    'setting_keys'      => [
                        'type'        => 'array',
                        'description' => 'Specific setting keys to copy. Omit to copy all lane settings.',
                        'items'       => ['type' => 'string'],
                    ],
                ],
                'required' => ['post_id', 'source_element_id', 'target_element_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'       => ['type' => 'boolean'],
                    'copied_keys'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'       => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_copy_lane_settings'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 4. enforce-boundary-coherence
        wp_register_ability('novamira-adrianv2/enforce-boundary-coherence', [
            'label'       => 'Enforce Boundary Coherence',
            'description' => 'Enforces consistent content width, boxed width, and spacing boundaries across containers. Supports full_width and boxed modes with optional side padding/margin zeroing.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'                      => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'mode'                         => ['type' => 'string', 'enum' => ['full_width', 'boxed'], 'description' => 'Boundary mode.'],
                    'boxed_width'                  => ['type' => 'integer', 'description' => 'Boxed width in px (for boxed mode). Default: 1200.'],
                    'zero_side_padding'            => ['type' => 'boolean', 'description' => 'Zero out horizontal padding. Default: true.'],
                    'zero_side_margins'            => ['type' => 'boolean', 'description' => 'Zero out horizontal margins. Default: false.'],
                    'normalize_nested_boxed_widths' => ['type' => 'boolean', 'description' => 'Apply boxed_width to nested containers. Default: true.'],
                    'include_root'                 => ['type' => 'boolean', 'description' => 'Include root. Default: true.'],
                    'max_depth'                    => ['type' => 'integer', 'description' => 'Max depth. -1 = unlimited. Default: -1.'],
                ],
                'required' => ['post_id', 'mode'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'changed_count'   => ['type' => 'integer'],
                    'changed_ids'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_enforce_boundary'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 5. apply-text-hierarchy
        wp_register_ability('novamira-adrianv2/apply-text-hierarchy', [
            'label'       => 'Apply Text Hierarchy',
            'description' => 'Applies consistent heading, body, and button typography styles across a page subtree. Note: operates on V3-style settings typography fields.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'         => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'heading_style'   => ['type' => 'object', 'description' => 'Heading typography overrides (font_size, font_weight, color, etc.).'],
                    'body_style'      => ['type' => 'object', 'description' => 'Body text typography overrides.'],
                    'button_style'    => ['type' => 'object', 'description' => 'Button typography overrides.'],
                    'use_globals'     => ['type' => 'boolean', 'description' => 'Prefer global typography bindings. Default: true.'],
                    'include_root'    => ['type' => 'boolean', 'description' => 'Include root. Default: true.'],
                    'max_depth'       => ['type' => 'integer', 'description' => 'Max depth. -1 = unlimited. Default: -1.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'changed_count'   => ['type' => 'integer'],
                    'changed_ids'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_apply_text_hierarchy'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 6. normalize-responsive-values
        wp_register_ability('novamira-adrianv2/normalize-responsive-values', [
            'label'       => 'Normalize Responsive Values',
            'description' => 'Calculates and sets missing tablet/mobile values from desktop values using configurable scaling factors for width, padding, and font size. Note: operates on V3-style settings.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'            => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'scale_tablet'       => ['type' => 'number', 'description' => 'Tablet scale factor. Default: 0.8.'],
                    'scale_mobile'       => ['type' => 'number', 'description' => 'Mobile scale factor. Default: 0.6.'],
                    'fill_missing_only'  => ['type' => 'boolean', 'description' => 'Only fill missing breakpoint values. Default: true.'],
                    'include_root'       => ['type' => 'boolean', 'description' => 'Include root. Default: true.'],
                    'max_depth'          => ['type' => 'integer', 'description' => 'Max depth. -1 = unlimited. Default: -1.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'changed_count'   => ['type' => 'integer'],
                    'changed_ids'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_normalize_responsive'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 7. sync-component-variant
        wp_register_ability('novamira-adrianv2/sync-component-variant', [
            'label'       => 'Sync Component Variant',
            'description' => 'Synchronizes design settings (colors, spacing, typography) from a source component subtree to a structurally matching target subtree.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'           => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'source_element_id' => ['type' => 'string', 'description' => 'Source component root element ID.'],
                    'target_element_id' => ['type' => 'string', 'description' => 'Target component root element ID.'],
                    'allow_partial'     => ['type' => 'boolean', 'description' => 'Allow syncing even if child counts differ. Default: false.'],
                ],
                'required' => ['post_id', 'source_element_id', 'target_element_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'changed_count'   => ['type' => 'integer'],
                    'changed_ids'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_sync_component'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 8. normalize-section-spacing
        wp_register_ability('novamira-adrianv2/normalize-section-spacing', [
            'label'       => 'Normalize Section Spacing',
            'description' => 'Standardizes vertical padding across top-level sections to a consistent rhythm. Detects the dominant spacing value and applies it uniformly.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'        => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'target_padding' => ['type' => 'object', 'description' => 'Override: specific padding to apply. E.g. {"top":"80","bottom":"80","unit":"px"}.'],
                    'mode'           => ['type' => 'string', 'enum' => ['auto', 'manual'], 'description' => 'auto = detect dominant spacing, manual = use target_padding. Default: auto.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'          => ['type' => 'boolean'],
                    'changed_count'    => ['type' => 'integer'],
                    'changed_ids'      => ['type' => 'array', 'items' => ['type' => 'string']],
                    'dominant_padding' => ['type' => 'object'],
                    'summary'          => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_normalize_section_spacing'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 9. image-to-background
        wp_register_ability('novamira-adrianv2/image-to-background', [
            'label'       => 'Image Widget to Background',
            'description' => 'Converts image widgets to container background images where the container has no other background. Preserves the image URL and moves it to the parent container settings.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'element_id' => ['type' => 'string', 'description' => 'Specific element ID to convert. Omit to scan entire page.'],
                    'remove_widget' => ['type' => 'boolean', 'description' => 'Remove the image widget after conversion. Default: true.'],
                    'dry_run'    => ['type' => 'boolean', 'description' => 'Preview only, no modifications. Default: false.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'converted_count' => ['type' => 'integer'],
                    'converted_ids'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'dry_run'         => ['type' => 'boolean'],
                    'summary'         => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_image_to_background'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 10. fix-gap-rhythm
        wp_register_ability('novamira-adrianv2/fix-gap-rhythm', [
            'label'       => 'Fix Gap Rhythm',
            'description' => 'Scans for inconsistent gap/spacing values between structurally similar sections and standardizes them to the dominant value. Note: operates on V3-style settings.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                    'dry_run' => ['type' => 'boolean', 'description' => 'Preview only. Default: false.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'      => ['type' => 'boolean'],
                    'fixed_count'  => ['type' => 'integer'],
                    'fixed_ids'    => ['type' => 'array', 'items' => ['type' => 'string']],
                    'dominant_gap' => ['type' => 'string'],
                    'dry_run'      => ['type' => 'boolean'],
                    'summary'      => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_fix_gap_rhythm'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $write_annotations]),
        ]);

        // 11. evaluate-render-context (read-only)
        wp_register_ability('novamira-adrianv2/evaluate-render-context', [
            'label'       => 'Evaluate Render Context',
            'description' => 'Read-only diagnostic: checks the rendered frontend DOM of a page for Elementor wrapper presence, content structure, and render context health.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'ID of the page to evaluate.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'       => ['type' => 'boolean'],
                    'permalink'     => ['type' => 'string'],
                    'observations'  => ['type' => 'object'],
                    'issues'        => ['type' => 'array', 'items' => ['type' => 'object']],
                    'summary'       => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_evaluate_render_context'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $read_annotations]),
        ]);

        // 12. get-style-guide (read-only)
        wp_register_ability('novamira-adrianv2/get-style-guide', [
            'label'       => 'Get Style Guide',
            'description' => 'Extracts a design style guide summary from the page: dominant colors, typography patterns, spacing ranges, and surface treatments found in the element tree.',
            'category'    => 'adrianv2-design-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => 'ID of the Elementor page.'],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'      => ['type' => 'boolean'],
                    'colors'       => ['type' => 'object'],
                    'typography'   => ['type' => 'object'],
                    'spacing'      => ['type' => 'object'],
                    'surfaces'     => ['type' => 'array', 'items' => ['type' => 'object']],
                    'summary'      => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_get_style_guide'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => array_merge($common_meta, ['annotations' => $read_annotations]),
        ]);
    }

    // ============================================================
    // 1. ZERO CONTAINER PADDING
    // ============================================================

    public static function execute_zero_padding($input = null)
    {
        $post_id = (int) $input['post_id'];
        $include_root = (bool) ($input['include_root'] ?? false);
        $max_depth = isset($input['max_depth']) ? (int) $input['max_depth'] : -1;

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($input, $include_root, $max_depth) {
            $changed_ids = [];
            $target_id = $input['element_id'] ?? null;

            if ($target_id) {
                $found = self::find_element_by_id($data, $target_id);
                if ($found !== null) {
                    self::zero_container_padding_subtree($found, $include_root, $max_depth, 0, $changed_ids);
                    self::replace_element_in_tree($data, $target_id, $found);
                }
            } else {
                foreach ($data as &$el) {
                    if (is_array($el)) {
                        self::zero_container_padding_subtree($el, $include_root, $max_depth, 0, $changed_ids);
                    }
                }
            }

            return ['changed' => $changed_ids];
        });

        if (is_array($result) && isset($result['error'])) return $result;

        $changed = $result['changed'] ?? [];
        return [
            'success'       => true,
            'changed_count' => count($changed),
            'changed_ids'   => array_values($changed),
            'summary'       => count($changed) . ' containers had padding zeroed.',
        ];
    }

    private static function zero_container_padding_subtree(array &$element, bool $include_root, int $max_depth, int $depth, array &$changed_ids): void
    {
        if ($max_depth >= 0 && $depth > $max_depth) return;

        $el_type = $element['elType'] ?? '';
        if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true)) {
            if ($include_root || $depth > 0) {
                $element['settings']['padding'] = self::make_zero_spacing_box();
                $element['settings']['_padding'] = self::make_zero_spacing_box();
                $changed_ids[] = (string) ($element['id'] ?? '');
                $changed_ids = array_unique($changed_ids);
            }
        }

        foreach ($element['elements'] ?? [] as &$child) {
            if (is_array($child)) {
                self::zero_container_padding_subtree($child, true, $max_depth, $depth + 1, $changed_ids);
            }
        }
    }

    // ============================================================
    // 2. RESET NEGATIVE MARGINS
    // ============================================================

    public static function execute_reset_negative_margins($input = null)
    {
        $post_id = (int) $input['post_id'];
        $widgets_only = (bool) ($input['widgets_only'] ?? false);
        $include_root = $input['include_root'] ?? true;

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($input, $widgets_only, $include_root) {
            $changed_ids = [];
            $target_id = $input['element_id'] ?? null;

            if ($target_id) {
                $found = self::find_element_by_id($data, $target_id);
                if ($found !== null) {
                    self::reset_negative_margins_subtree($found, $widgets_only, $include_root, $changed_ids);
                    self::replace_element_in_tree($data, $target_id, $found);
                }
            } else {
                foreach ($data as &$el) {
                    if (is_array($el)) {
                        self::reset_negative_margins_subtree($el, $widgets_only, true, $changed_ids);
                    }
                }
            }

            return ['changed' => $changed_ids];
        });

        if (is_array($result) && isset($result['error'])) return $result;

        $changed = $result['changed'] ?? [];
        return [
            'success'       => true,
            'changed_count' => count($changed),
            'changed_ids'   => array_values($changed),
            'summary'       => count($changed) . ' elements had negative margins clamped.',
        ];
    }

    private static function reset_negative_margins_subtree(array &$element, bool $widgets_only, bool $include_root, array &$changed_ids): void
    {
        $el_type = $element['elType'] ?? '';
        $is_widget = $el_type === 'widget';
        $is_container = in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true);

        if (($is_widget || (!$widgets_only && $is_container)) && $include_root) {
            $modified = false;
            foreach (self::MARGIN_KEYS as $key) {
                $val = $element['settings'][$key] ?? null;
                if ($val !== null) {
                    $clamped = self::clamp_negative_spacing_box($val);
                    if ($clamped !== $val) {
                        $element['settings'][$key] = $clamped;
                        $modified = true;
                    }
                }
            }
            if ($modified) {
                $changed_ids[] = (string) ($element['id'] ?? '');
                $changed_ids = array_unique($changed_ids);
            }
        }

        foreach ($element['elements'] ?? [] as &$child) {
            if (is_array($child)) {
                self::reset_negative_margins_subtree($child, $widgets_only, true, $changed_ids);
            }
        }
    }

    // ============================================================
    // 3. COPY LANE SETTINGS
    // ============================================================

    public static function execute_copy_lane_settings($input = null)
    {
        $post_id = (int) $input['post_id'];
        $source_id = (string) ($input['source_element_id'] ?? '');
        $target_id = (string) ($input['target_element_id'] ?? '');
        $keys = $input['setting_keys'] ?? self::LANE_KEYS;

        if ($source_id === '' || $target_id === '') {
            return ['success' => false, 'summary' => 'Both source_element_id and target_element_id are required.'];
        }

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($source_id, $target_id, $keys) {
            $source = self::find_element_by_id($data, $source_id);
            $target = self::find_element_by_id($data, $target_id);

            if ($source === null || $target === null) {
                return ['error' => 'Source or target element not found.'];
            }

            $copied = [];
            foreach ($keys as $key) {
                if (isset($source['settings'][$key])) {
                    $target['settings'][$key] = $source['settings'][$key];
                    $copied[] = $key;
                }
            }

            self::replace_element_in_tree($data, $target_id, $target);

            return ['changed' => [$target_id], 'copied_keys' => $copied];
        });

        if (is_array($result) && isset($result['error'])) {
            return ['success' => false, 'summary' => $result['error']];
        }

        return [
            'success'     => true,
            'copied_keys' => $result['copied_keys'] ?? [],
            'summary'     => 'Copied ' . count($result['copied_keys'] ?? []) . ' lane settings.',
        ];
    }

    // ============================================================
    // 4. ENFORCE BOUNDARY COHERENCE
    // ============================================================

    public static function execute_enforce_boundary($input = null)
    {
        $post_id = (int) $input['post_id'];
        $mode = (string) ($input['mode'] ?? 'full_width');
        $boxed_width = (int) ($input['boxed_width'] ?? 1200);
        $zero_side_padding = (bool) ($input['zero_side_padding'] ?? true);
        $zero_side_margins = (bool) ($input['zero_side_margins'] ?? false);
        $normalize_nested = (bool) ($input['normalize_nested_boxed_widths'] ?? true);
        $include_root = (bool) ($input['include_root'] ?? true);
        $max_depth = isset($input['max_depth']) ? (int) $input['max_depth'] : -1;

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($mode, $boxed_width, $zero_side_padding, $zero_side_margins, $normalize_nested, $include_root, $max_depth) {
            $changed_ids = [];

            foreach ($data as &$el) {
                if (is_array($el)) {
                    self::enforce_boundary_coherence_subtree($el, $mode, $boxed_width, $zero_side_padding, $zero_side_margins, $normalize_nested, $include_root, $max_depth, 0, $changed_ids);
                }
            }

            return ['changed' => $changed_ids];
        });

        if (is_array($result) && isset($result['error'])) return $result;

        $changed = $result['changed'] ?? [];
        return [
            'success'       => true,
            'changed_count' => count($changed),
            'changed_ids'   => array_values($changed),
            'summary'       => count($changed) . ' containers had boundaries enforced in ' . $mode . ' mode.',
        ];
    }

    private static function enforce_boundary_coherence_subtree(array &$element, string $mode, int $boxed_width, bool $zero_side_padding, bool $zero_side_margins, bool $normalize_nested, bool $include_root, int $max_depth, int $depth, array &$changed_ids): void
    {
        if ($max_depth >= 0 && $depth > $max_depth) return;

        $el_type = $element['elType'] ?? '';
        if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block', 'section'], true)) {
            if ($include_root || $depth > 0) {
                $modified = false;

                if ($mode === 'full_width') {
                    $element['settings']['content_width'] = 'full';
                    unset($element['settings']['boxed_width']);
                    $modified = true;
                } elseif ($mode === 'boxed') {
                    $element['settings']['content_width'] = 'full';
                    if ($depth === 0 || $normalize_nested) {
                        $element['settings']['boxed_width'] = ['unit' => 'px', 'size' => $boxed_width];
                    }
                    $modified = true;
                }

                if ($zero_side_padding) {
                    $padding = $element['settings']['padding'] ?? $element['settings']['_padding'] ?? null;
                    if ($padding !== null) {
                        $element['settings']['padding'] = self::zero_horizontal_spacing_box($padding);
                        $element['settings']['_padding'] = self::zero_horizontal_spacing_box($padding);
                        $modified = true;
                    }
                }

                if ($zero_side_margins) {
                    foreach (self::MARGIN_KEYS as $key) {
                        if (isset($element['settings'][$key])) {
                            $element['settings'][$key] = self::zero_horizontal_spacing_box($element['settings'][$key]);
                            $modified = true;
                        }
                    }
                }

                if ($modified) {
                    $changed_ids[] = (string) ($element['id'] ?? '');
                    $changed_ids = array_unique($changed_ids);
                }
            }
        }

        foreach ($element['elements'] ?? [] as &$child) {
            if (is_array($child)) {
                self::enforce_boundary_coherence_subtree($child, $mode, $boxed_width, $zero_side_padding, $zero_side_margins, $normalize_nested, true, $max_depth, $depth + 1, $changed_ids);
            }
        }
    }

    // ============================================================
    // 5. APPLY TEXT HIERARCHY
    // ============================================================

    public static function execute_apply_text_hierarchy($input = null)
    {
        $post_id = (int) $input['post_id'];
        $heading_style = $input['heading_style'] ?? [];
        $body_style = $input['body_style'] ?? [];
        $button_style = $input['button_style'] ?? [];
        $use_globals = (bool) ($input['use_globals'] ?? true);
        $include_root = (bool) ($input['include_root'] ?? true);
        $max_depth = isset($input['max_depth']) ? (int) $input['max_depth'] : -1;

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($heading_style, $body_style, $button_style, $use_globals, $include_root, $max_depth) {
            $changed_ids = [];

            foreach ($data as &$el) {
                if (is_array($el)) {
                    self::apply_text_hierarchy_subtree($el, $heading_style, $body_style, $button_style, $use_globals, $include_root, $max_depth, 0, $changed_ids);
                }
            }

            return ['changed' => $changed_ids];
        });

        if (is_array($result) && isset($result['error'])) return $result;

        $changed = $result['changed'] ?? [];
        return [
            'success'       => true,
            'changed_count' => count($changed),
            'changed_ids'   => array_values($changed),
            'summary'       => count($changed) . ' widgets had text hierarchy applied.',
        ];
    }

    private static function apply_text_hierarchy_subtree(array &$element, array $heading_style, array $body_style, array $button_style, bool $use_globals, bool $include_root, int $max_depth, int $depth, array &$changed_ids): void
    {
        if ($max_depth >= 0 && $depth > $max_depth) return;

        if ($include_root || $depth > 0) {
            $el_type = $element['elType'] ?? '';
            if ($el_type === 'widget') {
                $modified = self::apply_text_hierarchy_to_widget($element, $heading_style, $body_style, $button_style, $use_globals);
                if ($modified) {
                    $changed_ids[] = (string) ($element['id'] ?? '');
                    $changed_ids = array_unique($changed_ids);
                }
            }
        }

        foreach ($element['elements'] ?? [] as &$child) {
            if (is_array($child)) {
                self::apply_text_hierarchy_subtree($child, $heading_style, $body_style, $button_style, $use_globals, true, $max_depth, $depth + 1, $changed_ids);
            }
        }
    }

    private static function apply_text_hierarchy_to_widget(array &$element, array $heading_style, array $body_style, array $button_style, bool $use_globals): bool
    {
        $wt = $element['widgetType'] ?? '';
        $modified = false;

        if ($wt === 'heading' || $wt === 'e-heading') {
            if (!empty($heading_style)) {
                foreach ($heading_style as $prop => $value) {
                    $element['settings'][$prop] = $value;
                }
                if ($use_globals) {
                    $element['settings']['__globals__'] = $element['settings']['__globals__'] ?? [];
                }
                // Direct unset: remove local typography to prefer global binding
                foreach (['typography_typography', 'typography_font_family', 'typography_font_size', 'typography_font_weight', 'typography_line_height', 'typography_letter_spacing'] as $k) {
                    unset($element['settings'][$k], $element['settings'][$k . '_tablet'], $element['settings'][$k . '_mobile']);
                }
                $modified = true;
            }
        } elseif ($wt === 'text-editor' || $wt === 'e-paragraph') {
            if (!empty($body_style)) {
                foreach ($body_style as $prop => $value) {
                    $element['settings'][$prop] = $value;
                }
                if ($use_globals) {
                    $element['settings']['__globals__'] = $element['settings']['__globals__'] ?? [];
                }
                foreach (['typography_typography', 'typography_font_size', 'typography_font_weight', 'typography_line_height'] as $k) {
                    unset($element['settings'][$k], $element['settings'][$k . '_tablet'], $element['settings'][$k . '_mobile']);
                }
                $modified = true;
            }
        } elseif ($wt === 'button' || $wt === 'e-button') {
            if (!empty($button_style)) {
                foreach ($button_style as $prop => $value) {
                    $element['settings'][$prop] = $value;
                }
                if ($use_globals) {
                    $element['settings']['__globals__'] = $element['settings']['__globals__'] ?? [];
                }
                foreach (['typography_typography', 'typography_font_size', 'typography_font_weight'] as $k) {
                    unset($element['settings'][$k], $element['settings'][$k . '_tablet'], $element['settings'][$k . '_mobile']);
                }
                $modified = true;
            }
        }

        return $modified;
    }

    // ============================================================
    // 6. NORMALIZE RESPONSIVE VALUES
    // ============================================================

    public static function execute_normalize_responsive($input = null)
    {
        $post_id = (int) $input['post_id'];
        $scale_tablet = (float) ($input['scale_tablet'] ?? 0.8);
        $scale_mobile = (float) ($input['scale_mobile'] ?? 0.6);
        $fill_missing_only = (bool) ($input['fill_missing_only'] ?? true);
        $include_root = (bool) ($input['include_root'] ?? true);
        $max_depth = isset($input['max_depth']) ? (int) $input['max_depth'] : -1;

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($scale_tablet, $scale_mobile, $fill_missing_only, $include_root, $max_depth) {
            $changed_ids = [];

            foreach ($data as &$el) {
                if (is_array($el)) {
                    self::normalize_responsive_values_subtree($el, $scale_tablet, $scale_mobile, $fill_missing_only, $include_root, $max_depth, 0, $changed_ids);
                }
            }

            return ['changed' => $changed_ids];
        });

        if (is_array($result) && isset($result['error'])) return $result;

        $changed = $result['changed'] ?? [];
        return [
            'success'       => true,
            'changed_count' => count($changed),
            'changed_ids'   => array_values($changed),
            'summary'       => count($changed) . ' elements had responsive values normalized (tablet: ' . $scale_tablet . 'x, mobile: ' . $scale_mobile . 'x).',
        ];
    }

    private static function normalize_responsive_values_subtree(array &$element, float $scale_tablet, float $scale_mobile, bool $fill_missing_only, bool $include_root, int $max_depth, int $depth, array &$changed_ids): void
    {
        if ($max_depth >= 0 && $depth > $max_depth) return;

        if ($include_root || $depth > 0) {
            $modified = self::normalize_element_responsive($element, $scale_tablet, $scale_mobile, $fill_missing_only);
            if ($modified) {
                $changed_ids[] = (string) ($element['id'] ?? '');
                $changed_ids = array_unique($changed_ids);
            }
        }

        foreach ($element['elements'] ?? [] as &$child) {
            if (is_array($child)) {
                self::normalize_responsive_values_subtree($child, $scale_tablet, $scale_mobile, $fill_missing_only, true, $max_depth, $depth + 1, $changed_ids);
            }
        }
    }

    private static function normalize_element_responsive(array &$element, float $scale_tablet, float $scale_mobile, bool $fill_missing_only): bool
    {
        $modified = false;
        $settings = &$element['settings'];

        $desktop_size = $settings['typography_font_size'] ?? null;
        if ($desktop_size !== null) {
            $size_num = self::extract_numeric_value($desktop_size);
            if ($size_num !== null) {
                $unit = self::extract_unit($desktop_size);
                $tablet_val = round($size_num * $scale_tablet, 1) . $unit;
                $mobile_val = round($size_num * $scale_mobile, 1) . $unit;

                if (!isset($settings['typography_font_size_tablet']) || !$fill_missing_only) {
                    $settings['typography_font_size_tablet'] = $tablet_val;
                    $modified = true;
                }
                if (!isset($settings['typography_font_size_mobile']) || !$fill_missing_only) {
                    $settings['typography_font_size_mobile'] = $mobile_val;
                    $modified = true;
                }
            }
        }

        foreach (['padding', '_padding'] as $pad_key) {
            $desktop_pad = $settings[$pad_key] ?? null;
            if ($desktop_pad !== null) {
                $tablet_pad = self::scale_spacing_box($desktop_pad, $scale_tablet);
                $mobile_pad = self::scale_spacing_box($desktop_pad, $scale_mobile);
                $tablet_key = $pad_key . '_tablet';
                $mobile_key = $pad_key . '_mobile';

                if (!isset($settings[$tablet_key]) || !$fill_missing_only) {
                    $settings[$tablet_key] = $tablet_pad;
                    $modified = true;
                }
                if (!isset($settings[$mobile_key]) || !$fill_missing_only) {
                    $settings[$mobile_key] = $mobile_pad;
                    $modified = true;
                }
            }
        }

        $width = $settings['width'] ?? null;
        if ($width !== null) {
            $width_num = self::extract_numeric_value($width);
            if ($width_num !== null) {
                $unit = self::extract_unit($width);
                if (!isset($settings['width_tablet']) || !$fill_missing_only) {
                    $settings['width_tablet'] = round($width_num * $scale_tablet, 1) . $unit;
                    $modified = true;
                }
                if (!isset($settings['width_mobile']) || !$fill_missing_only) {
                    $settings['width_mobile'] = round($width_num * $scale_mobile, 1) . $unit;
                    $modified = true;
                }
            }
        }

        return $modified;
    }

    // ============================================================
    // 7. SYNC COMPONENT VARIANT
    // ============================================================

    public static function execute_sync_component($input = null)
    {
        $post_id = (int) $input['post_id'];
        $source_id = (string) ($input['source_element_id'] ?? '');
        $target_id = (string) ($input['target_element_id'] ?? '');
        $allow_partial = (bool) ($input['allow_partial'] ?? false);

        if ($source_id === '' || $target_id === '') {
            return ['success' => false, 'summary' => 'Both source_element_id and target_element_id are required.'];
        }

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($source_id, $target_id, $allow_partial) {
            $source = self::find_element_by_id($data, $source_id);
            $target = self::find_element_by_id($data, $target_id);

            if ($source === null || $target === null) {
                return ['error' => 'Source or target element not found.'];
            }

            $changed_ids = [];
            self::sync_component_variant_subtree($source, $target, $allow_partial, $changed_ids);

            self::replace_element_in_tree($data, $target_id, $target);

            return ['changed' => $changed_ids];
        });

        if (is_array($result) && isset($result['error'])) {
            return ['success' => false, 'summary' => $result['error']];
        }

        $changed = $result['changed'] ?? [];
        return [
            'success'       => true,
            'changed_count' => count($changed),
            'changed_ids'   => array_values($changed),
            'summary'       => count($changed) . ' elements synced from source component.',
        ];
    }

    private static function sync_component_variant_subtree(array &$source, array &$target, bool $allow_partial, array &$changed_ids): void
    {
        $design_keys = self::filter_design_settings($source['settings'] ?? []);
        $merged = self::merge_settings($target['settings'] ?? [], $design_keys);
        if ($merged !== ($target['settings'] ?? [])) {
            $target['settings'] = $merged;
            $changed_ids[] = (string) ($target['id'] ?? '');
            $changed_ids = array_unique($changed_ids);
        }

        $source_children = $source['elements'] ?? [];
        $target_children = &$target['elements'];

        $n_source = count($source_children);
        $n_target = count($target_children);

        if ($allow_partial || $n_source === $n_target) {
            $n = min($n_source, $n_target);
            for ($i = 0; $i < $n; $i++) {
                $s_type = ($source_children[$i]['elType'] ?? '') === 'widget' ? ($source_children[$i]['widgetType'] ?? '') : ($source_children[$i]['elType'] ?? '');
                $t_type = ($target_children[$i]['elType'] ?? '') === 'widget' ? ($target_children[$i]['widgetType'] ?? '') : ($target_children[$i]['elType'] ?? '');

                if ($s_type === $t_type) {
                    self::sync_component_variant_subtree($source_children[$i], $target_children[$i], $allow_partial, $changed_ids);
                }
            }
        }
    }

    // ============================================================
    // 8. NORMALIZE SECTION SPACING
    // ============================================================

    public static function execute_normalize_section_spacing($input = null)
    {
        $post_id = (int) $input['post_id'];
        $mode = (string) ($input['mode'] ?? 'auto');
        $target_padding = $input['target_padding'] ?? null;

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($mode, $target_padding) {
            $top_level = [];

            foreach ($data as &$el) {
                if (is_array($el) && in_array($el['elType'] ?? '', ['container', 'e-flexbox', 'e-div-block', 'section'], true)) {
                    $top_level[] = &$el;
                }
            }

            if (empty($top_level)) {
                return ['error' => 'No top-level containers found.'];
            }

            $changed_ids = [];
            $dominant_padding = null;

            if ($mode === 'auto') {
                $padding_counts = [];
                foreach ($top_level as $section) {
                    $pad = $section['settings']['padding'] ?? $section['settings']['_padding'] ?? null;
                    if ($pad !== null) {
                        $key = is_array($pad) ? json_encode($pad) : (string) $pad;
                        $padding_counts[$key] = ($padding_counts[$key] ?? 0) + 1;
                    }
                }
                arsort($padding_counts);
                $dominant_key = array_key_first($padding_counts);
                $dominant_padding = $dominant_key ? json_decode($dominant_key, true) : self::make_spacing_box('80', 'px');

                foreach ($top_level as &$section) {
                    $section['settings']['padding'] = $dominant_padding;
                    $section['settings']['_padding'] = $dominant_padding;
                    $changed_ids[] = (string) ($section['id'] ?? '');
                }
            } elseif ($mode === 'manual' && $target_padding !== null) {
                $dominant_padding = $target_padding;
                foreach ($top_level as &$section) {
                    $section['settings']['padding'] = $target_padding;
                    $section['settings']['_padding'] = $target_padding;
                    $changed_ids[] = (string) ($section['id'] ?? '');
                }
            }

            return ['changed' => $changed_ids, 'dominant_padding' => $dominant_padding];
        });

        if (is_array($result) && isset($result['error'])) return ['success' => false, 'summary' => $result['error']];

        $changed = $result['changed'] ?? [];
        return [
            'success'          => true,
            'changed_count'    => count($changed),
            'changed_ids'      => array_values($changed),
            'dominant_padding' => $result['dominant_padding'] ?? null,
            'summary'          => count($changed) . ' sections had spacing normalized.',
        ];
    }

    // ============================================================
    // 9. IMAGE WIDGET TO BACKGROUND
    // ============================================================

    public static function execute_image_to_background($input = null)
    {
        $post_id = (int) $input['post_id'];
        $remove_widget = (bool) ($input['remove_widget'] ?? true);
        $dry_run = (bool) ($input['dry_run'] ?? false);
        $target_id = $input['element_id'] ?? null;

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($remove_widget, $dry_run, $target_id) {
            $converted = [];

            $walker = null;
            $walker = static function (array &$elements) use (&$walker, &$converted, $remove_widget, $dry_run, $target_id) {
                // Collect removals first, apply after loop to avoid index shifting
                $removals = [];

                foreach ($elements as $idx => &$el) {
                    if (!is_array($el)) continue;

                    $children = $el['elements'] ?? [];

                    foreach ($children as $cidx => $child) {
                        if (!is_array($child)) continue;
                        $wt = $child['widgetType'] ?? '';
                        if (in_array($wt, ['image', 'e-image'], true)) {
                            $image_url = $child['settings']['image']['url'] ?? $child['settings']['_image']['url'] ?? null;
                            $has_existing_bg = !empty($el['settings']['background_image']) || !empty($el['settings']['background_background']);

                            if ($image_url && !$has_existing_bg) {
                                $el_id = (string) ($el['id'] ?? '');
                                $target_match = !$target_id || $el_id === $target_id;

                                if ($target_match) {
                                    $converted[] = ['container_id' => $el_id, 'image_url' => $image_url];
                                    if (!$dry_run) {
                                        $el['settings']['background_image'] = ['url' => $image_url];
                                        $el['settings']['background_background'] = 'classic';
                                        $el['settings']['background_size'] = 'cover';
                                        $el['settings']['background_position'] = 'center center';
                                        if ($remove_widget) {
                                            $removals[] = ['parent_idx' => $idx, 'child_idx' => $cidx];
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if (!empty($el['elements'])) {
                        $walker($el['elements']);
                    }
                }

                // Apply removals after the loop completes
                if (!$dry_run && !empty($removals)) {
                    foreach (array_reverse($removals) as $rm) {
                        unset($elements[$rm['parent_idx']]['elements'][$rm['child_idx']]);
                    }
                    // Re-index all affected parents
                    $affected = array_unique(array_column($removals, 'parent_idx'));
                    foreach ($affected as $pidx) {
                        $elements[$pidx]['elements'] = array_values($elements[$pidx]['elements']);
                    }
                }
            };

            $walker($data);

            return ['changed' => array_column($converted, 'container_id'), 'converted' => $converted];
        });

        if (is_array($result) && isset($result['error'])) return ['success' => false, 'summary' => $result['error']];

        $converted = $result['converted'] ?? [];
        return [
            'success'         => true,
            'converted_count' => count($converted),
            'converted_ids'   => array_column($converted, 'container_id'),
            'dry_run'         => $dry_run,
            'summary'         => ($dry_run ? '[DRY RUN] ' : '') . count($converted) . ' image widgets ' . ($dry_run ? 'would be' : 'were') . ' converted to backgrounds.',
        ];
    }

    // ============================================================
    // 10. FIX GAP RHYTHM
    // ============================================================

    public static function execute_fix_gap_rhythm($input = null)
    {
        $post_id = (int) $input['post_id'];
        $dry_run = (bool) ($input['dry_run'] ?? false);

        $result = self::load_and_modify_data($post_id, function (array &$data) use ($dry_run) {
            $gap_values = [];
            $fixed_ids = [];

            $collector = null;
            $collector = static function (array $els) use (&$collector, &$gap_values) {
                foreach ($els as $el) {
                    if (!is_array($el)) continue;
                    $el_type = $el['elType'] ?? '';
                    if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true)) {
                        $gap = $el['settings']['gap'] ?? $el['settings']['gap_between_elements'] ?? null;
                        if ($gap !== null) {
                            $key = is_array($gap) ? ($gap['size'] ?? $gap['value'] ?? '') : (string) $gap;
                            if ($key !== '' && $key !== '0') {
                                $gap_values[$key] = ($gap_values[$key] ?? 0) + 1;
                            }
                        }
                    }
                    if (!empty($el['elements'])) {
                        $collector($el['elements']);
                    }
                }
            };

            $collector($data);

            if (empty($gap_values)) {
                return ['changed' => [], 'dominant_gap' => null];
            }

            arsort($gap_values);
            $dominant_gap = array_key_first($gap_values);

            if (!$dry_run && $dominant_gap) {
                $fixer = null;
                $fixer = static function (array &$els) use (&$fixer, $dominant_gap, &$fixed_ids) {
                    foreach ($els as &$el) {
                        if (!is_array($el)) continue;
                        $el_type = $el['elType'] ?? '';
                        if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true)) {
                            $gap = $el['settings']['gap'] ?? $el['settings']['gap_between_elements'] ?? null;
                            if ($gap !== null) {
                                $current = is_array($gap) ? ($gap['size'] ?? $gap['value'] ?? '') : (string) $gap;
                                if ($current !== '' && $current !== $dominant_gap) {
                                    $el['settings']['gap'] = ['unit' => 'px', 'size' => (int) $dominant_gap];
                                    $fixed_ids[] = (string) ($el['id'] ?? '');
                                }
                            }
                        }
                        if (!empty($el['elements'])) {
                            $fixer($el['elements']);
                        }
                    }
                };

                $fixer($data);
            }

            return ['changed' => $fixed_ids, 'dominant_gap' => $dominant_gap];
        });

        if (is_array($result) && isset($result['error'])) return ['success' => false, 'summary' => $result['error']];

        $fixed = $result['changed'] ?? [];
        return [
            'success'      => true,
            'fixed_count'  => count($fixed),
            'fixed_ids'    => array_values($fixed),
            'dominant_gap' => $result['dominant_gap'] ?? 'N/A',
            'dry_run'      => $dry_run,
            'summary'      => ($dry_run ? '[DRY RUN] Would fix ' : 'Fixed ') . count($fixed) . ' gap inconsistencies. Dominant gap: ' . ($result['dominant_gap'] ?? 'N/A'),
        ];
    }

    // ============================================================
    // 11. EVALUATE RENDER CONTEXT (read-only)
    // ============================================================

    public static function execute_evaluate_render_context($input = null)
    {
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'summary' => "Post {$post_id} not found."];
        }

        $permalink = get_permalink($post_id);
        $observations = [
            'post_status'       => $post->post_status,
            'permalink'         => $permalink,
            'elementor_active'  => did_action('elementor/init') > 0 || class_exists('\\Elementor\\Plugin'),
        ];

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode(is_string($raw) ? $raw : '[]', true);
        $observations['has_elementor_data'] = is_array($data) && !empty($data);
        $observations['element_count'] = is_array($data) ? count($data) : 0;

        $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
        $observations['edit_mode'] = $edit_mode ?: 'not set';

        $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);
        $observations['has_page_settings'] = !empty($page_settings);

        $css_status = get_post_meta($post_id, '_elementor_css', true);
        $observations['has_cached_css'] = !empty($css_status);

        $issues = [];
        if (!$observations['has_elementor_data']) {
            $issues[] = [
                'type'     => 'missing_elementor_data',
                'severity' => 'warning',
                'message'  => 'No Elementor data found for this page.',
            ];
        }
        if (empty($edit_mode)) {
            $issues[] = [
                'type'     => 'missing_edit_mode',
                'severity' => 'info',
                'message'  => 'No _elementor_edit_mode meta set — page may not be recognized as Elementor.',
            ];
        }

        return [
            'success'      => true,
            'permalink'    => $permalink,
            'observations' => $observations,
            'issues'       => $issues,
            'summary'      => empty($issues) ? 'Render context looks healthy.' : count($issues) . ' render context issue(s) found.',
        ];
    }

    // ============================================================
    // 12. GET STYLE GUIDE (read-only)
    // ============================================================

    public static function execute_get_style_guide($input = null)
    {
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'summary' => "Post {$post_id} not found."];
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode(is_string($raw) ? $raw : '[]', true);
        if (!is_array($data) || empty($data)) {
            return ['success' => false, 'summary' => 'No valid Elementor data found.'];
        }

        $colors = ['backgrounds' => [], 'text_colors' => [], 'borders' => []];
        $typography = ['heading_sizes' => [], 'body_sizes' => [], 'font_families' => []];
        $spacing = ['paddings' => [], 'margins' => [], 'gaps' => []];
        $surfaces = [];

        $collector = null;
        $collector = static function (array $els) use (&$collector, &$colors, &$typography, &$spacing, &$surfaces) {
            foreach ($els as $el) {
                if (!is_array($el)) continue;
                $settings = $el['settings'] ?? [];
                $el_type = $el['elType'] ?? '';
                $wt = $el['widgetType'] ?? '';

                $bg = $settings['background_color'] ?? $settings['background_background'] ?? null;
                if ($bg && is_string($bg)) {
                    $colors['backgrounds'][] = $bg;
                }
                $text_color = $settings['text_color'] ?? $settings['title_color'] ?? null;
                if ($text_color && is_string($text_color)) {
                    $colors['text_colors'][] = $text_color;
                }
                $border = $settings['border_color'] ?? $settings['_border_color'] ?? null;
                if ($border && is_string($border)) {
                    $colors['borders'][] = $border;
                }

                $font_size = $settings['typography_font_size'] ?? null;
                if ($font_size !== null) {
                    if (in_array($wt, ['heading', 'e-heading'], true)) {
                        $typography['heading_sizes'][] = $font_size;
                    } elseif (in_array($wt, ['text-editor', 'e-paragraph'], true)) {
                        $typography['body_sizes'][] = $font_size;
                    }
                }
                $font_family = $settings['typography_font_family'] ?? null;
                if ($font_family && is_string($font_family)) {
                    $typography['font_families'][] = $font_family;
                }

                $padding = $settings['padding'] ?? $settings['_padding'] ?? null;
                if ($padding !== null) {
                    $spacing['paddings'][] = $padding;
                }
                $gap = $settings['gap'] ?? $settings['gap_between_elements'] ?? null;
                if ($gap !== null) {
                    $spacing['gaps'][] = $gap;
                }

                if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true)) {
                    $has_bg = !empty($settings['background_color']) || !empty($settings['background_background']);
                    $has_border = !empty($settings['border_border']);
                    $has_shadow = !empty($settings['box_shadow_box_shadow']);
                    $has_radius = !empty($settings['border_radius']);
                    if ($has_bg || $has_border || $has_shadow || $has_radius) {
                        $surfaces[] = [
                            'element_id'     => (string) ($el['id'] ?? ''),
                            'has_background' => $has_bg,
                            'has_border'     => $has_border,
                            'has_shadow'     => $has_shadow,
                            'has_radius'     => $has_radius,
                            'tone'           => self::classify_surface_tone($settings),
                        ];
                    }
                }

                if (!empty($el['elements'])) {
                    $collector($el['elements']);
                }
            }
        };

        $collector($data);

        $summary_parts = [];
        $bg_colors = array_count_values(array_filter($colors['backgrounds']));
        if (!empty($bg_colors)) {
            arsort($bg_colors);
            $summary_parts[] = count($bg_colors) . ' unique background colors';
        }
        $summary_parts[] = count($typography['heading_sizes']) . ' heading text elements';
        $summary_parts[] = count($typography['body_sizes']) . ' body text elements';
        $summary_parts[] = count($surfaces) . ' styled surfaces';

        return [
            'success'    => true,
            'colors'     => $colors,
            'typography' => $typography,
            'spacing'    => $spacing,
            'surfaces'   => $surfaces,
            'summary'    => 'Style guide extracted: ' . implode(', ', $summary_parts) . '.',
        ];
    }

    // ============================================================
    // SHARED UTILITY FUNCTIONS
    // ============================================================

    private static function load_and_modify_data(int $post_id, callable $modifier): array
    {
        $post = get_post($post_id);
        if (!$post) {
            return ['error' => "Post {$post_id} not found."];
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode(is_string($raw) ? $raw : '[]', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Failed to decode Elementor data: ' . json_last_error_msg()];
        }
        if (!is_array($data)) {
            $data = [];
        }

        $result = $modifier($data);

        if (isset($result['error'])) {
            return $result;
        }

        $new_raw = wp_slash(wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        update_post_meta($post_id, '_elementor_data', $new_raw);

        if (class_exists('\\Novamira\\AdrianV2\\Helpers\\Guards')) {
            \Novamira\AdrianV2\Helpers\Guards::invalidate_elementor_cache($post_id);
        } else {
            delete_post_meta($post_id, '_elementor_css');
        }

        return $result;
    }

    private static function find_element_by_id(array $elements, string $target_id): ?array
    {
        foreach ($elements as $el) {
            if (!is_array($el)) continue;
            if (($el['id'] ?? '') === $target_id) {
                return $el;
            }
            if (!empty($el['elements'])) {
                $found = self::find_element_by_id($el['elements'], $target_id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function replace_element_in_tree(array &$elements, string $target_id, array $replacement): bool
    {
        foreach ($elements as &$el) {
            if (!is_array($el)) continue;
            if (($el['id'] ?? '') === $target_id) {
                $el = $replacement;
                return true;
            }
            if (!empty($el['elements'])) {
                if (self::replace_element_in_tree($el['elements'], $target_id, $replacement)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function make_zero_spacing_box(): array
    {
        return ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0'];
    }

    private static function make_spacing_box(string $value, string $unit = 'px'): array
    {
        return ['unit' => $unit, 'top' => $value, 'right' => $value, 'bottom' => $value, 'left' => $value];
    }

    private static function clamp_negative_spacing_box($val)
    {
        if (!is_array($val)) return max(0, (int) $val);
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (isset($val[$side])) {
                $val[$side] = (string) max(0, (int) $val[$side]);
            }
        }
        return $val;
    }

    private static function zero_horizontal_spacing_box($val): array
    {
        $box = is_array($val) ? $val : ['unit' => 'px', 'top' => $val, 'right' => $val, 'bottom' => $val, 'left' => $val];
        $box['right'] = '0';
        $box['left'] = '0';
        return $box;
    }

    private static function scale_spacing_box($val, float $scale): array
    {
        $box = is_array($val) ? $val : ['unit' => 'px', 'top' => $val, 'right' => $val, 'bottom' => $val, 'left' => $val];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (isset($box[$side]) && is_numeric($box[$side])) {
                $box[$side] = (string) round((float) $box[$side] * $scale, 1);
            }
        }
        return $box;
    }

    private static function extract_numeric_value($val): ?float
    {
        if (is_numeric($val)) return (float) $val;
        if (is_array($val)) {
            $num = $val['size'] ?? $val['value'] ?? null;
            return is_numeric($num) ? (float) $num : null;
        }
        if (is_string($val)) {
            $num = (float) filter_var($val, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            return $num > 0 ? $num : null;
        }
        return null;
    }

    private static function extract_unit($val): string
    {
        if (is_array($val)) return $val['unit'] ?? 'px';
        if (is_string($val)) {
            if (strpos($val, 'em') !== false) return 'em';
            if (strpos($val, 'rem') !== false) return 'rem';
            if (strpos($val, '%') !== false) return '%';
            if (strpos($val, 'vw') !== false) return 'vw';
        }
        return 'px';
    }

    private static function filter_design_settings(array $settings): array
    {
        $design_keys = [
            'background_color', 'background_background', 'background_image',
            'border_border', 'border_radius', 'border_color',
            'box_shadow_box_shadow', 'box_shadow_color',
            'padding', '_padding',
            'typography_typography', 'typography_font_size', 'typography_font_weight',
            'typography_font_family', 'typography_line_height', 'typography_letter_spacing',
            'text_color', 'title_color',
            '__globals__',
        ];

        $filtered = [];
        foreach ($design_keys as $key) {
            if (isset($settings[$key])) {
                $filtered[$key] = $settings[$key];
            }
        }
        return $filtered;
    }

    private static function merge_settings(array $target, array $source): array
    {
        return array_merge($target, $source);
    }

    private static function classify_surface_tone(array $settings): string
    {
        $bg = $settings['background_color'] ?? '';
        if (empty($bg) && !empty($settings['background_background'])) {
            $bg = $settings['background_background'];
        }
        if (is_array($bg)) {
            $bg = $bg['value'] ?? $bg['color'] ?? '';
        }
        if (empty($bg) || !is_string($bg)) {
            return 'light';
        }
        if (strpos($bg, 'var(') === 0) {
            return 'neutral';
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bg)) {
            $hex = strlen($bg) === 4 ? '#' . $bg[1].$bg[1].$bg[2].$bg[2].$bg[3].$bg[3] : $bg;
            $r = hexdec(substr($hex, 1, 2));
            $g = hexdec(substr($hex, 3, 2));
            $b = hexdec(substr($hex, 5, 2));
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
            if ($lum < 0.25) return 'dark';
            if ($lum < 0.55) return 'accent';
        }
        return 'light';
    }
}

// Defense-in-depth: also register via wp_abilities_api_init
add_action('wp_abilities_api_init', [Design_Repair::class, 'register']);
