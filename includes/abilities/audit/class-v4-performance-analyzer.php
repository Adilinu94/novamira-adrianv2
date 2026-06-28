<?php
declare(strict_types=1);

/**
 * V4_Performance_Analyzer — analyzes Elementor V4 pages for performance issues.
 *
 * Checks:
 *   - DOM depth (warning at > 3 levels, error at > 5)
 *   - Empty containers (e-flexbox/e-div-block with no children/content)
 *   - Variable usage quota (% of properties using Global Variables vs. hardcoded)
 *   - Global Class reuse rate (how many GCs are actually applied)
 *   - Style duplication (identical style definitions under different IDs)
 *   - Asset count (images, fonts, SVGs per page)
 *
 * Outputs a JSON report with a 0–100 score and categorized issues array.
 *
 * @package Novamira_AdrianV2
 * @since   1.11.0
 */

namespace Novamira\AdrianV2\Abilities\Audit;

if (!defined('ABSPATH')) {
    exit();
}

class V4_Performance_Analyzer
{
    /** Maximum recommended DOM nesting depth for V4. */
    private const MAX_DEPTH_WARN  = 3;
    private const MAX_DEPTH_ERROR = 5;

    /** Penalty points per issue type (subtracted from 100). */
    private const PENALTY_ERROR   = 15;
    private const PENALTY_WARNING = 5;
    private const PENALTY_INFO    = 1;

    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/v4-performance-analysis', [
            'label'               => 'V4 Performance Analysis',
            'description'         => 'Analyzes V4 atomic pages for performance issues: DOM depth, empty containers, variable usage, GC reuse, style duplication, and asset count.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The page/post ID to analyze.',
                    ],
                    'checks'  => [
                        'type'        => 'array',
                        'items'       => [
                            'type' => 'string',
                            'enum' => [
                                'dom_depth',
                                'empty_containers',
                                'variable_usage',
                                'gc_reuse',
                                'style_duplication',
                                'asset_count',
                            ],
                        ],
                        'description' => 'Which checks to run. Default: all.',
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
        $post_id = (int) ($input['post_id'] ?? 0);
        $checks  = $input['checks'] ?? [
            'dom_depth',
            'empty_containers',
            'variable_usage',
            'gc_reuse',
            'style_duplication',
            'asset_count',
        ];

        if ($post_id <= 0) {
            return ['success' => false, 'error' => 'Invalid post_id.'];
        }

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'error' => "Post $post_id not found."];
        }

        $raw  = get_post_meta($post_id, '_elementor_data', true);
        $tree = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($tree)) {
            return ['success' => false, 'error' => 'No Elementor data found on this post.'];
        }

        if (empty($tree)) {
            return [
                'success' => true,
                'data'    => [
                    'post_id'    => $post_id,
                    'post_title' => $post->post_title,
                    'score'      => 100,
                    'passed'     => true,
                    'checks_run' => $checks,
                    'message'    => 'Page has no Elementor elements to analyze.',
                ],
            ];
        }

        $issues   = [];
        $stats    = [];
        $score    = 100;

        if (in_array('dom_depth', $checks)) {
            $depth_result = self::analyzeDomDepth($tree);
            $issues       = array_merge($issues, $depth_result['issues']);
            $stats['dom_depth'] = $depth_result['stats'];
            $score       -= $depth_result['penalty'];
        }

        if (in_array('empty_containers', $checks)) {
            $ec_result = self::analyzeEmptyContainers($tree);
            $issues    = array_merge($issues, $ec_result['issues']);
            $stats['empty_containers'] = $ec_result['stats'];
            $score    -= $ec_result['penalty'];
        }

        if (in_array('variable_usage', $checks)) {
            $vu_result = self::analyzeVariableUsage($tree);
            $issues    = array_merge($issues, $vu_result['issues']);
            $stats['variable_usage'] = $vu_result['stats'];
            $score    -= $vu_result['penalty'];
        }

        if (in_array('gc_reuse', $checks)) {
            $gc_result = self::analyzeGcReuse($tree);
            $issues    = array_merge($issues, $gc_result['issues']);
            $stats['gc_reuse'] = $gc_result['stats'];
            $score    -= $gc_result['penalty'];
        }

        if (in_array('style_duplication', $checks)) {
            $sd_result = self::analyzeStyleDuplication($tree);
            $issues    = array_merge($issues, $sd_result['issues']);
            $stats['style_duplication'] = $sd_result['stats'];
            $score    -= $sd_result['penalty'];
        }

        if (in_array('asset_count', $checks)) {
            $ac_result = self::analyzeAssetCount($tree);
            $issues    = array_merge($issues, $ac_result['issues']);
            $stats['asset_count'] = $ac_result['stats'];
            $score    -= $ac_result['penalty'];
        }

        $score = max(0, $score);

        $by_severity = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $sev = $issue['severity'] ?? 'info';
            $by_severity[$sev] = ($by_severity[$sev] ?? 0) + 1;
        }

        return [
            'success' => true,
            'data'    => [
                'post_id'      => $post_id,
                'post_title'   => $post->post_title,
                'score'        => $score,
                'passed'       => $score >= 70,
                'checks_run'   => $checks,
                'total_issues' => count($issues),
                'by_severity'  => $by_severity,
                'issues'       => $issues,
                'stats'        => $stats,
            ],
        ];
    }

    // ========================================================================
    // 1. DOM DEPTH
    // ========================================================================

    /**
     * Analyzes DOM nesting depth across the V4 element tree.
     *
     * V4 target: e-flexbox > e-flexbox > widget (3 levels). Deeper nesting
     * hurts render performance and signals structural issues.
     *
     * @param array $tree V4 element tree.
     * @return array{issues: array, stats: array, penalty: int}
     */
    private static function analyzeDomDepth(array $tree): array
    {
        $issues     = [];
        $penalty    = 0;
        $max_depth  = 0;
        $deep_paths = [];

        $walk = function(array $elements, int $depth, string $path) use (&$walk, &$max_depth, &$deep_paths, &$issues, &$penalty): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $el_id   = $el['id'] ?? '?';
                $el_type = $el['elType'] ?? $el['type'] ?? 'unknown';
                $current_path = $path === '' ? "$el_id($el_type)" : "$path > $el_id($el_type)";

                $current_depth = $depth + 1;
                if ($current_depth > $max_depth) {
                    $max_depth = $current_depth;
                }

                if ($current_depth > self::MAX_DEPTH_ERROR) {
                    $deep_paths[] = $current_path;
                    $issues[] = [
                        'severity'   => 'error',
                        'type'       => 'dom_depth_excessive',
                        'element_id' => $el_id,
                        'message'    => "DOM depth {$current_depth} exceeds maximum ({self::MAX_DEPTH_ERROR}) at {$current_path}.",
                    ];
                    $penalty += self::PENALTY_ERROR;
                } elseif ($current_depth > self::MAX_DEPTH_WARN) {
                    $deep_paths[] = $current_path;
                    $issues[] = [
                        'severity'   => 'warning',
                        'type'       => 'dom_depth_high',
                        'element_id' => $el_id,
                        'message'    => "DOM depth {$current_depth} exceeds recommendation ({self::MAX_DEPTH_WARN}) at {$current_path}.",
                    ];
                    $penalty += self::PENALTY_WARNING;
                }

                $children = $el['elements'] ?? [];
                if (!empty($children) && is_array($children)) {
                    $walk($children, $current_depth, $current_path);
                }
            }
        };

        $walk($tree, 0, '');

        return [
            'issues'  => $issues,
            'stats'   => [
                'max_depth'    => $max_depth,
                'threshold_warn' => self::MAX_DEPTH_WARN,
                'threshold_error' => self::MAX_DEPTH_ERROR,
                'deep_paths_count' => count($deep_paths),
            ],
            'penalty' => $penalty,
        ];
    }

    // ========================================================================
    // 2. EMPTY CONTAINERS
    // ========================================================================

    /**
     * Finds containers (e-flexbox, e-div-block) that have no children and
     * no meaningful content settings.
     *
     * Empty V4 containers produce blank space in the rendered page and are
     * often left over from incomplete builder operations.
     *
     * @param array $tree V4 element tree.
     * @return array{issues: array, stats: array, penalty: int}
     */
    private static function analyzeEmptyContainers(array $tree): array
    {
        $issues  = [];
        $penalty = 0;
        $total_containers = 0;
        $empty_count      = 0;

        $walk = function(array $elements) use (&$walk, &$issues, &$penalty, &$total_containers, &$empty_count): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $el_id   = $el['id'] ?? '?';
                $el_type = $el['elType'] ?? $el['type'] ?? '';
                $widget  = $el['widgetType'] ?? '';
                $settings = $el['settings'] ?? [];
                $children = $el['elements'] ?? [];

                // Count all container-type elements
                $container_types = ['e-flexbox', 'e-div-block', 'container', 'section'];
                if (in_array($el_type, $container_types) || in_array($widget, $container_types)) {
                    $total_containers++;

                    $has_children = !empty($children);
                    $has_content  = false;

                    // Check for visible content in settings
                    $content_keys = ['title', 'editor', 'text', 'description', 'html',
                                     'background_color', 'background_image', 'background_overlay'];
                    foreach ($content_keys as $ck) {
                        $val = $settings[$ck] ?? null;
                        if ($val !== null && $val !== '' && $val !== []) {
                            // For atomic wrappers, unwrap
                            if (is_array($val) && isset($val['$$type']) && isset($val['value'])) {
                                $inner = $val['value'];
                                if ($inner !== null && $inner !== '' && $inner !== []) {
                                    $has_content = true;
                                    break;
                                }
                            } else {
                                $has_content = true;
                                break;
                            }
                        }
                    }

                    // Also check for background-image in styles
                    $styles = $el['styles'] ?? [];
                    foreach ($styles as $style_def) {
                        $variants = $style_def['variants'] ?? [];
                        foreach ($variants as $variant) {
                            $props = $variant['props'] ?? [];
                            if (isset($props['background-image']) || isset($props['background-overlay'])) {
                                $has_content = true;
                                break 2;
                            }
                        }
                    }

                    if (!$has_children && !$has_content) {
                        $empty_count++;
                        $issues[] = [
                            'severity'   => 'warning',
                            'type'       => 'empty_container',
                            'element_id' => $el_id,
                            'message'    => "Container '{$el_id}' ({$el_type}) is empty — no children, background, or text content.",
                        ];
                        $penalty += self::PENALTY_WARNING;
                    }
                }

                if (!empty($children) && is_array($children)) {
                    $walk($children);
                }
            }
        };

        $walk($tree);

        return [
            'issues'  => $issues,
            'stats'   => [
                'total_containers' => $total_containers,
                'empty_containers' => $empty_count,
                'empty_pct'        => $total_containers > 0
                    ? round(($empty_count / $total_containers) * 100, 1)
                    : 0,
            ],
            'penalty' => $penalty,
        ];
    }

    // ========================================================================
    // 3. VARIABLE USAGE
    // ========================================================================

    /**
     * Measures how many style property values use Global Variables (e-gv-*)
     * versus hardcoded strings/numbers.
     *
     * High GV usage = better design consistency and easier theming.
     *
     * @param array $tree V4 element tree.
     * @return array{issues: array, stats: array, penalty: int}
     */
    private static function analyzeVariableUsage(array $tree): array
    {
        $issues     = [];
        $penalty    = 0;
        $total_props  = 0;
        $gv_props     = 0;
        $hardcoded_by_type = [];

        $walk = function(array $elements) use (&$walk, &$total_props, &$gv_props, &$hardcoded_by_type): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                // Analyze styles
                $styles = $el['styles'] ?? [];
                foreach ($styles as $style_id => $style_def) {
                    $variants = $style_def['variants'] ?? [];
                    foreach ($variants as $variant) {
                        $props = $variant['props'] ?? [];
                        foreach ($props as $prop_key => $prop_val) {
                            if (!is_array($prop_val)) {
                                continue;
                            }
                            $type  = $prop_val['$$type'] ?? '';
                            $value = $prop_val['value'] ?? null;

                            // Count global-variable usage
                            if (in_array($type, ['global-color-variable', 'global-font-variable', 'global-variable'])) {
                                $gv_props++;
                                $total_props++;
                                continue;
                            }

                            // Count typed props that could be variables
                            if (in_array($type, ['string', 'color', 'size', 'number'])) {
                                $total_props++;

                                // Check if value is an e-gv-* ID (should be wrapped, but check anyway)
                                if (is_string($value) && str_starts_with($value, 'e-gv-')) {
                                    $gv_props++;
                                } else {
                                    $prop_category = $type;
                                    if (!isset($hardcoded_by_type[$prop_category])) {
                                        $hardcoded_by_type[$prop_category] = 0;
                                    }
                                    $hardcoded_by_type[$prop_category]++;
                                }
                            }
                        }
                    }
                }

                $children = $el['elements'] ?? [];
                if (!empty($children) && is_array($children)) {
                    $walk($children);
                }
            }
        };

        $walk($tree);

        $gv_pct = $total_props > 0 ? round(($gv_props / $total_props) * 100, 1) : 0;

        if ($total_props > 0 && $gv_pct < 30) {
            $issues[] = [
                'severity'   => 'warning',
                'type'       => 'low_variable_usage',
                'element_id' => null,
                'message'    => "Only {$gv_pct}% of style properties use Global Variables. Consider replacing hardcoded values with design tokens for consistency.",
            ];
            $penalty += self::PENALTY_WARNING;
        } elseif ($total_props > 0 && $gv_pct < 50) {
            $issues[] = [
                'severity'   => 'info',
                'type'       => 'moderate_variable_usage',
                'element_id' => null,
                'message'    => "{$gv_pct}% of style properties use Global Variables. Aim for > 50% for best theming support.",
            ];
            $penalty += self::PENALTY_INFO;
        }

        return [
            'issues'  => $issues,
            'stats'   => [
                'total_props'        => $total_props,
                'gv_props'           => $gv_props,
                'hardcoded_props'    => $total_props - $gv_props,
                'gv_usage_pct'       => $gv_pct,
                'hardcoded_by_type'  => $hardcoded_by_type,
            ],
            'penalty' => $penalty,
        ];
    }

    // ========================================================================
    // 4. GLOBAL CLASS REUSE
    // ========================================================================

    /**
     * Analyzes how effectively Global Classes are reused across the page.
     *
     * A GC referenced by only one element is effectively a local style —
     * it should either be converted to a local class or reused more broadly.
     *
     * @param array $tree V4 element tree.
     * @return array{issues: array, stats: array, penalty: int}
     */
    private static function analyzeGcReuse(array $tree): array
    {
        $issues     = [];
        $penalty    = 0;
        $gc_usage   = [];  // gc_id => count of elements using it
        $total_gc_refs = 0;
        $total_elements = 0;

        $walk = function(array $elements) use (&$walk, &$gc_usage, &$gc_labels, &$total_gc_refs, &$total_elements): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $total_elements++;

                // Count GC references in classes.value
                $classes = $el['settings']['classes']['value'] ?? $el['settings']['classes'] ?? [];
                if (!is_array($classes)) {
                    $classes = [];
                }

                foreach ($classes as $cls) {
                    if (!is_string($cls)) {
                        continue;
                    }
                    // GCs typically start with 'gc-' prefix
                    if (str_starts_with($cls, 'gc-')) {
                        if (!isset($gc_usage[$cls])) {
                            $gc_usage[$cls] = 0;
                        }
                        $gc_usage[$cls]++;
                        $total_gc_refs++;
                    }
                }

                $children = $el['elements'] ?? [];
                if (!empty($children) && is_array($children)) {
                    $walk($children);
                }
            }
        };

        $walk($tree);

        // Analyze reuse patterns
        $single_use_gcs  = [];
        $multi_use_gcs   = [];
        $total_gcs       = count($gc_usage);

        foreach ($gc_usage as $gc_id => $count) {
            if ($count <= 1) {
                $single_use_gcs[] = $gc_id;
            } else {
                $multi_use_gcs[] = $gc_id;
            }
        }

        $single_use_count = count($single_use_gcs);
        if ($single_use_count > 5) {
            $issues[] = [
                'severity'   => 'warning',
                'type'       => 'low_gc_reuse',
                'element_id' => null,
                'message'    => "{$single_use_count} Global Classes used only once — consider converting to local classes or reusing across elements.",
                'details'    => array_slice($single_use_gcs, 0, 10),
            ];
            $penalty += self::PENALTY_WARNING;
        } elseif ($single_use_count > 0) {
            $issues[] = [
                'severity'   => 'info',
                'type'       => 'single_use_gcs',
                'element_id' => null,
                'message'    => "{$single_use_count} Global Class(es) used only once: " . implode(', ', array_slice($single_use_gcs, 0, 5)),
            ];
            $penalty += self::PENALTY_INFO;
        }

        $reuse_rate = $total_gcs > 0
            ? round(($total_gc_refs / $total_gcs), 1)
            : 0;

        return [
            'issues'  => $issues,
            'stats'   => [
                'total_gcs'        => $total_gcs,
                'total_gc_refs'    => $total_gc_refs,
                'total_elements'   => $total_elements,
                'single_use_gcs'   => $single_use_count,
                'multi_use_gcs'    => count($multi_use_gcs),
                'reuse_ratio'      => $reuse_rate,
                'single_use_ids'   => array_slice($single_use_gcs, 0, 10),
            ],
            'penalty' => $penalty,
        ];
    }

    // ========================================================================
    // 5. STYLE DUPLICATION
    // ========================================================================

    /**
     * Detects identical style definitions registered under different style IDs.
     *
     * Duplicate styles bloat the page JSON and indicate missed GC opportunities.
     *
     * @param array $tree V4 element tree.
     * @return array{issues: array, stats: array, penalty: int}
     */
    private static function analyzeStyleDuplication(array $tree): array
    {
        $issues      = [];
        $penalty     = 0;
        $style_hashes = [];  // hash => [style_id, element_id, ...]
        $total_styles = 0;
        $duplicate_groups = 0;

        $walk = function(array $elements) use (&$walk, &$style_hashes, &$total_styles): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $el_id   = $el['id'] ?? '?';
                $styles  = $el['styles'] ?? [];

                foreach ($styles as $style_id => $style_def) {
                    if (!is_array($style_def)) {
                        continue;
                    }
                    $total_styles++;

                    // Hash the variant props (ignoring the style ID and label)
                    $variants = $style_def['variants'] ?? [];
                    $normalized = [];
                    foreach ($variants as $v) {
                        $normalized[] = [
                            'bp'    => $v['meta']['breakpoint'] ?? null,
                            'state' => $v['meta']['state'] ?? null,
                            'props' => $v['props'] ?? [],
                        ];
                    }

                    // Sort for consistent hashing
                    usort($normalized, function($a, $b) {
                        return strcmp(
                            ($a['bp'] ?? '') . ($a['state'] ?? ''),
                            ($b['bp'] ?? '') . ($b['state'] ?? '')
                        );
                    });

                    $hash = md5(serialize($normalized));

                    if (!isset($style_hashes[$hash])) {
                        $style_hashes[$hash] = [];
                    }
                    $style_hashes[$hash][] = [
                        'style_id'   => $style_id,
                        'element_id' => $el_id,
                    ];
                }

                $children = $el['elements'] ?? [];
                if (!empty($children) && is_array($children)) {
                    $walk($children);
                }
            }
        };

        $walk($tree);

        // Find duplicates
        $duplicate_details = [];
        foreach ($style_hashes as $hash => $entries) {
            if (count($entries) > 1) {
                $duplicate_groups++;
                $ids = array_map(fn($e) => $e['style_id'] . '@' . $e['element_id'], $entries);
                $duplicate_details[] = [
                    'count'     => count($entries),
                    'style_ids' => array_slice($ids, 0, 5),
                ];
            }
        }

        if ($duplicate_groups > 10) {
            $issues[] = [
                'severity'   => 'warning',
                'type'       => 'style_duplication_high',
                'element_id' => null,
                'message'    => "{$duplicate_groups} groups of identical styles found across the page. Extract to Global Classes to reduce bloat.",
            ];
            $penalty += self::PENALTY_WARNING;
        } elseif ($duplicate_groups > 0) {
            $issues[] = [
                'severity'   => 'info',
                'type'       => 'style_duplication_low',
                'element_id' => null,
                'message'    => "{$duplicate_groups} group(s) of identical styles found. Consider extracting to Global Classes.",
            ];
            $penalty += self::PENALTY_INFO;
        }

        $dup_pct = $total_styles > 0
            ? round(($duplicate_groups / $total_styles) * 100, 1)
            : 0;

        return [
            'issues'  => $issues,
            'stats'   => [
                'total_styles'      => $total_styles,
                'duplicate_groups'  => $duplicate_groups,
                'duplicate_pct'     => $dup_pct,
                'examples'          => array_slice($duplicate_details, 0, 5),
            ],
            'penalty' => $penalty,
        ];
    }

    // ========================================================================
    // 6. ASSET COUNT
    // ========================================================================

    /**
     * Counts assets (images, SVGs, font references) per page.
     *
     * High asset counts without lazy loading or CDN delivery can cause slow
     * initial page loads.
     *
     * @param array $tree V4 element tree.
     * @return array{issues: array, stats: array, penalty: int}
     */
    private static function analyzeAssetCount(array $tree): array
    {
        $issues    = [];
        $penalty   = 0;
        $images    = 0;
        $svgs      = 0;
        $fonts     = 0;
        $image_ids = [];

        $walk = function(array $elements) use (&$walk, &$images, &$svgs, &$fonts, &$image_ids): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $el_id   = $el['id'] ?? '?';
                $widget  = $el['widgetType'] ?? '';
                $el_type = $el['elType'] ?? $el['type'] ?? '';
                $settings = $el['settings'] ?? [];
                $styles   = $el['styles'] ?? [];

                // Count image widgets
                if (in_array($widget, ['image', 'e-image', 'image-box'])) {
                    $images++;
                    $img = $settings['image'] ?? [];
                    if (is_array($img)) {
                        $img_id = $img['id'] ?? ($img['value']['id'] ?? 0);
                        if ($img_id) {
                            $image_ids[] = (int) $img_id;
                        }
                    }
                }

                // Count SVG widgets
                if (in_array($widget, ['e-svg', 'icon', 'e-icon'])) {
                    $svgs++;
                }

                // Count font references in styles
                foreach ($styles as $style_def) {
                    $variants = $style_def['variants'] ?? [];
                    foreach ($variants as $variant) {
                        $props = $variant['props'] ?? [];
                        // Check for font-family or typography props
                        if (isset($props['font-family']) || isset($props['typography'])) {
                            $fonts++;
                        }
                        // Check for background-image (counts as an asset)
                        if (isset($props['background-image'])) {
                            $images++;
                        }
                    }
                }

                // Count icon-list items
                if ($widget === 'icon-list') {
                    $items = $settings['icon_list'] ?? [];
                    if (is_array($items)) {
                        $svgs += count($items);
                    }
                }

                $children = $el['elements'] ?? [];
                if (!empty($children) && is_array($children)) {
                    $walk($children);
                }
            }
        };

        $walk($tree);

        $total_assets = $images + $svgs + $fonts;

        if ($images > 20) {
            $issues[] = [
                'severity'   => 'warning',
                'type'       => 'high_image_count',
                'element_id' => null,
                'message'    => "{$images} images on page — consider lazy loading and WebP conversion for performance.",
            ];
            $penalty += self::PENALTY_WARNING;
        }

        if ($fonts > 5) {
            $issues[] = [
                'severity'   => 'info',
                'type'       => 'high_font_count',
                'element_id' => null,
                'message'    => "{$fonts} font references found — excessive font loading delays text rendering.",
            ];
            $penalty += self::PENALTY_INFO;
        }

        if ($total_assets > 40) {
            $issues[] = [
                'severity'   => 'error',
                'type'       => 'excessive_assets',
                'element_id' => null,
                'message'    => "{$total_assets} total assets (images+SVGs+fonts) — consider splitting across multiple pages or using CDN.",
            ];
            $penalty += self::PENALTY_ERROR;
        }

        return [
            'issues'  => $issues,
            'stats'   => [
                'images'       => $images,
                'svgs'         => $svgs,
                'fonts'        => $fonts,
                'total_assets' => $total_assets,
                'image_ids'    => $image_ids,
            ],
            'penalty' => $penalty,
        ];
    }
}

add_action('wp_abilities_api_init', [V4_Performance_Analyzer::class, 'register']);
