<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Audit;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Ability: Variable Audit
 *
 * Scans all Elementor pages for e-gv-* variable references.
 * Reports defined variables, their usage across pages, unused variables,
 * and drift (references to variable IDs that are no longer defined).
 */
class Variable_Audit
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/variable-audit', [
            'label'               => 'Variable Audit',
            'description'         => 'Scans Elementor pages for e-gv-* variable references. Reports usage, unused variables, and drift (references to undefined variable IDs).',
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'scope' => [
                        'type'        => 'string',
                        'enum'        => ['all', 'post_ids'],
                        'description' => 'Scan all Elementor posts, or restrict to post_ids. Default: all.',
                    ],
                    'post_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'Post IDs to scan (only used when scope=post_ids).',
                    ],
                    'variable_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Optional filter: only report these variable IDs.',
                    ],
                    'report' => [
                        'type'        => 'string',
                        'enum'        => ['full', 'unused', 'drift', 'summary'],
                        'description' => 'Output format. Default: full.',
                    ],
                ],
            ],
            'meta' => ['show_in_rest' => true, 'mcp' => ['public' => true]],
        ]);
    }

    public static function execute(mixed $params): array
    {
        $scope        = $params['scope']        ?? 'all';
        $filter_ids   = $params['variable_ids'] ?? [];
        $scan_ids     = isset($params['post_ids']) ? array_map('intval', (array) $params['post_ids']) : [];
        $report       = $params['report']       ?? 'full';

        $defined = self::get_defined_variables();

        $post_ids_to_scan = ($scope === 'post_ids' && !empty($scan_ids))
            ? $scan_ids
            : self::get_all_elementor_post_ids();

        $usage      = [];
        $drift_refs = [];

        foreach ($post_ids_to_scan as $post_id) {
            $raw = get_post_meta($post_id, '_elementor_data', true);
            if (empty($raw) || !str_contains($raw, 'e-gv-')) {
                continue;
            }

            $post       = get_post($post_id);
            $post_title = $post ? $post->post_title : "(ID: {$post_id})";
            $found      = self::extract_variable_references($raw);

            foreach ($found as $var_id => $element_ids) {
                if (!empty($filter_ids) && !in_array($var_id, $filter_ids, true)) {
                    continue;
                }

                if (isset($defined[$var_id])) {
                    $usage[$var_id] ??= [
                        'label' => $defined[$var_id]['label'],
                        'type'  => $defined[$var_id]['type'],
                        'pages' => [],
                    ];
                    $usage[$var_id]['pages'][] = [
                        'post_id'     => $post_id,
                        'post_title'  => $post_title,
                        'element_ids' => array_values(array_unique($element_ids)),
                    ];
                } else {
                    $drift_refs[$var_id][] = ['post_id' => $post_id, 'post_title' => $post_title];
                }
            }
        }

        $unused = [];
        foreach ($defined as $var_id => $meta) {
            if (isset($usage[$var_id])) {
                continue;
            }
            if (!empty($filter_ids) && !in_array($var_id, $filter_ids, true)) {
                continue;
            }
            $unused[] = array_merge(['id' => $var_id], $meta);
        }

        $drift = [];
        foreach ($drift_refs as $var_id => $pages) {
            $drift[] = ['id' => $var_id, 'pages' => $pages];
        }

        $output = [
            'success'       => true,
            'defined_count' => count($defined),
            'used_count'    => count($usage),
            'unused_count'  => count($unused),
            'drift_count'   => count($drift),
            'pages_scanned' => count($post_ids_to_scan),
        ];

        return match ($report) {
            'unused'  => array_merge($output, ['unused' => $unused]),
            'drift'   => array_merge($output, ['drift' => $drift]),
            'summary' => $output,
            default   => array_merge($output, [
                'defined' => $defined,
                'usage'   => $usage,
                'unused'  => $unused,
                'drift'   => $drift,
            ]),
        };
    }

    private static function get_defined_variables(): array
    {
        $defined = [];

        if (!class_exists('\\Elementor\\Plugin')) {
            return $defined;
        }

        $kit_id = \Elementor\Plugin::$instance->kits_manager->get_active_id();
        if (!$kit_id) {
            return $defined;
        }

        $raw = get_post_meta((int)$kit_id, '_elementor_kit_variables', true);
        if (empty($raw)) {
            return $defined;
        }

        $variables = is_array($raw) ? $raw : json_decode($raw, true);
        if (!is_array($variables)) {
            return $defined;
        }

        foreach ($variables as $var) {
            $id = $var['id'] ?? null;
            if (!$id) {
                continue;
            }
            if (!str_starts_with((string) $id, 'e-gv-')) {
                $id = 'e-gv-' . $id;
            }
            $defined[$id] = [
                'label' => $var['label'] ?? $id,
                'type'  => $var['type']  ?? 'unknown',
                'value' => $var['value'] ?? null,
            ];
        }

        return $defined;
    }

    private static function get_all_elementor_post_ids(): array
    {
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data'"
        );
        return array_map('intval', $ids ?: []);
    }

    private static function extract_variable_references(string $raw_json): array
    {
        $result = [];
        preg_match_all('/e-gv-[a-zA-Z0-9]+/', $raw_json, $matches);
        if (empty($matches[0])) {
            return $result;
        }

        foreach (array_unique($matches[0]) as $var_id) {
            $element_ids = [];
            $offset      = 0;
            while (($pos = strpos($raw_json, $var_id, $offset)) !== false) {
                $context = substr($raw_json, max(0, $pos - 500), 500);
                if (preg_match_all('/"id":"([a-f0-9]{7,8})"/', $context, $id_matches)) {
                    foreach ($id_matches[1] as $eid) {
                        $element_ids[] = $eid;
                    }
                }
                $offset = $pos + 1;
            }
            $result[$var_id] = array_values(array_unique($element_ids));
        }

        return $result;
    }
}

add_action('wp_abilities_api_init', [Variable_Audit::class, 'register']);
