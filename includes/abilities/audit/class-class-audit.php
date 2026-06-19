<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Audit;

if (!defined('ABSPATH')) {
    exit();
}

class Class_Audit
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/class-audit', [
            'label'               => 'Class Usage Audit',
            'description'         => 'Cross-references defined Global Classes with actual usage across Elementor pages. Finds unused classes, shows per-page usage, and identifies classes referenced but not defined.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'scope'       => [
                        'type'        => 'string',
                        'description' => 'Audit scope. "all" for every Elementor page, "post_ids" to limit to specific pages.',
                        'enum'        => ['all', 'post_ids'],
                    ],
                    'post_ids'    => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Specific page/post IDs to audit. Only used when scope is post_ids.',
                    ],
                    'class_id'    => [
                        'type'        => 'string',
                        'description' => 'Optional: audit a single class — find all pages and elements where it is used.',
                    ],
                    'post_type'   => [
                        'type'        => 'string',
                        'description' => 'Filter by post type. Default includes all Elementor-enabled types.',
                    ],
                    'limit'       => [
                        'type'        => 'integer',
                        'description' => 'Max pages to audit. Default 50.',
                    ],
                ],
                'required'   => [],
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
        $scope     = $input['scope'] ?? 'all';
        $post_ids  = $input['post_ids'] ?? [];
        $class_id  = $input['class_id'] ?? null;
        $post_type = $input['post_type'] ?? null;
        $limit     = $input['limit'] ?? 50;

        // Get defined global classes (v4) from kit post meta
        $defined_classes = [];
        $kit_id = get_option('elementor_active_kit');
        if ($kit_id) {
            $order_data = get_post_meta($kit_id, '_elementor_global_classes_order', true);
            $labels_data = get_post_meta($kit_id, '_elementor_global_classes_labels', true);

            if (is_string($order_data)) {
                $order_data = maybe_unserialize($order_data);
            }
            if (is_string($labels_data)) {
                $labels_data = maybe_unserialize($labels_data);
            }

            $class_ids = $order_data['order'] ?? [];
            foreach ($class_ids as $gcid) {
                $defined_classes[$gcid] = [
                    'id'    => $gcid,
                    'label' => $labels_data[$gcid] ?? '',
                    'type'  => 'class',
                ];
            }
        }

        // Collect pages to audit
        if ($scope === 'post_ids' && !empty($post_ids)) {
            $pages = array_map('get_post', $post_ids);
            $pages = array_filter($pages, fn($p) => $p !== null);
        } else {
            $args = [
                'post_type'      => $post_type ?: 'any',
                'posts_per_page' => $limit,
                'post_status'    => ['publish', 'draft'],
                'meta_key'       => '_elementor_edit_mode',
                'fields'         => 'ids',
            ];
            if ($class_id) {
                // When searching for a single class, check all Elementor pages
                $args['posts_per_page'] = $limit;
            }
            $page_ids = get_posts($args);
            $pages = array_map('get_post', $page_ids);
        }

        if (empty($pages)) {
            return ['success' => true, 'data' => ['message' => 'No Elementor pages found.', 'defined_classes' => $defined_classes, 'used_classes' => [], 'unused_classes' => array_keys($defined_classes)]];
        }

        $class_usage  = []; // class_id => ['count' => int, 'pages' => [...], 'elements' => [...]]
        $page_summary = []; // post_id => ['title' => str, 'classes' => [...]]

        foreach ($pages as $post) {
            $pid   = $post->ID;
            $raw   = get_post_meta($pid, '_elementor_data', true);
            $tree  = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($tree)) continue;

            $page_classes = [];

            $walk = function(&$els) use (&$walk, &$page_classes, &$class_usage, $pid) {
                foreach ($els as &$el) {
                    $el_id = $el['id'] ?? null;
                    $classes_val = $el['settings']['classes']['value'] ?? null;

                    if (is_array($classes_val)) {
                        foreach ($classes_val as $cid) {
                            if (!is_string($cid)) continue;

                            // Track per-page
                            if (!isset($page_classes[$cid])) {
                                $page_classes[$cid] = [];
                            }
                            $page_classes[$cid][] = $el_id;

                            // Track global
                            if (!isset($class_usage[$cid])) {
                                $class_usage[$cid] = ['count' => 0, 'pages' => [], 'elements' => []];
                            }
                            $class_usage[$cid]['count']++;
                            if (!in_array($pid, $class_usage[$cid]['pages'])) {
                                $class_usage[$cid]['pages'][] = $pid;
                            }
                            if (!isset($class_usage[$cid]['elements'][$pid])) {
                                $class_usage[$cid]['elements'][$pid] = [];
                            }
                            $class_usage[$cid]['elements'][$pid][] = $el_id;
                        }
                    }

                    if (!empty($el['elements'])) {
                        $walk($el['elements']);
                    }
                }
            };

            $walk($tree);

            if (!empty($page_classes)) {
                $page_summary[] = [
                    'post_id'   => $pid,
                    'title'     => $post->post_title,
                    'status'    => $post->post_status,
                    'edit_url'  => get_edit_post_link($pid, 'raw'),
                    'class_count' => count($page_classes),
                    'classes'   => $page_classes,
                ];
            }
        }

        // Determine unused classes
        $used_class_ids   = array_keys($class_usage);
        $defined_class_ids = array_keys($defined_classes);
        $unused_class_ids = array_diff($defined_class_ids, $used_class_ids);
        $undefined_class_ids = array_diff($used_class_ids, $defined_class_ids);

        // Build result
        $result = [
            'defined_classes'       => $defined_classes,
            'defined_count'         => count($defined_classes),
            'used_classes'          => $class_usage,
            'used_count'            => count($class_usage),
            'unused_class_ids'      => array_values($unused_class_ids),
            'unused_count'          => count($unused_class_ids),
            'undefined_class_ids'   => array_values($undefined_class_ids),
            'undefined_count'       => count($undefined_class_ids),
            'pages_audited'         => count($page_summary),
            'page_summary'          => $page_summary,
        ];

        return [
            'success' => true,
            'data'    => $result,
        ];
    }
}

add_action('wp_abilities_api_init', [Class_Audit::class, 'register']);
