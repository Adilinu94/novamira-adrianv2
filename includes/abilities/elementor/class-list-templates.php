<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class List_Templates {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/list-templates', [
            'label'               => 'List Templates',
            'description'         => 'Queries Elementor templates and Theme Builder items. Can include display conditions and estimate which templates apply to a specific post.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'template_type' => [
                        'type'        => 'string',
                        'description' => 'Filter by template type. Default: "all" returns everything except kits.',
                        'enum'        => [ 'all', 'header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'loop', 'section', 'container', 'page', 'kit', 'error-404', 'product', 'product-archive' ],
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Filter by post status. Default: "publish".',
                        'enum'        => [ 'any', 'publish', 'draft', 'private' ],
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Search term to filter templates by title.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max number of templates to return. Default: 50, maximum: 500.',
                    ],
                    'include_conditions' => [
                        'type'        => 'boolean',
                        'description' => 'Include raw Theme Builder display conditions. Default: false unless applies_to_post_id is passed.',
                    ],
                    'applies_to_post_id' => [
                        'type'        => 'integer',
                        'description' => 'Optional post ID. Adds applies_to_post and match_reason fields for Theme Builder templates.',
                    ],
                    'applied_only' => [
                        'type'        => 'boolean',
                        'description' => 'When applies_to_post_id is used, return only matching Theme Builder templates. Default: false.',
                    ],
                    'group_by_type' => [
                        'type'        => 'boolean',
                        'description' => 'Also return templates grouped by template type. Default: false.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'data'    => [
                        'type'       => 'object',
                        'properties' => [
                            'count'              => [ 'type' => 'integer' ],
                            'templates'          => [ 'type' => 'array' ],
                            'grouped'            => [ 'type' => 'object' ],
                            'applies_to_post_id' => [ 'type' => 'integer' ],
                            'applied_templates'  => [ 'type' => 'array' ],
                        ],
                    ],
                    'error'   => [ 'type' => 'string' ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null) {
        $template_type      = isset($input['template_type']) ? (string) $input['template_type'] : 'all';
        $status             = isset($input['status']) ? (string) $input['status'] : 'publish';
        $search             = isset($input['search']) ? sanitize_text_field((string) $input['search']) : '';
        $limit              = isset($input['limit']) ? max(1, min((int) $input['limit'], 500)) : 50;
        $include_conditions = !empty($input['include_conditions']) || !empty($input['applies_to_post_id']);
        $applies_to_post_id = isset($input['applies_to_post_id']) ? (int) $input['applies_to_post_id'] : 0;
        $applied_only       = !empty($input['applied_only']);
        $group_by_type      = !empty($input['group_by_type']);

        if ($applies_to_post_id && !get_post($applies_to_post_id)) {
            return ['success' => false, 'error' => sprintf('Post with ID %d not found.', $applies_to_post_id)];
        }

        $theme_builder_types = self::theme_builder_types();
        $meta_query          = [];

        if ('all' !== $template_type) {
            $meta_query[] = [
                'key'     => '_elementor_template_type',
                'value'   => $template_type,
                'compare' => '=',
            ];
        } else {
            $meta_query[] = [
                'key'     => '_elementor_template_type',
                'value'   => 'kit',
                'compare' => '!=',
            ];
        }

        $args = [
            'post_type'      => 'elementor_library',
            'posts_per_page' => $limit,
            'post_status'    => $status,
            'meta_query'     => $meta_query,
            'orderby'        => 'post_title',
            'order'          => 'ASC',
        ];

        if ('' !== $search) {
            $args['s'] = $search;
        }

        $posts             = get_posts($args);
        $templates         = [];
        $applied_templates = [];
        $grouped           = [];

        foreach ($posts as $post) {
            $type             = (string) get_post_meta($post->ID, '_elementor_template_type', true);
            $is_theme_builder = in_array($type, $theme_builder_types, true);
            $conditions       = self::get_conditions($post->ID);
            $match            = $applies_to_post_id && $is_theme_builder ? self::matches_post($conditions, $applies_to_post_id, $type) : null;

            if ($applied_only && (!$match || empty($match['applies']))) {
                continue;
            }

            $entry = [
                'id'               => (int) $post->ID,
                'title'            => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
                'type'             => $type,
                'location'         => self::type_to_location($type),
                'status'           => $post->post_status,
                'is_theme_builder' => $is_theme_builder,
                'edit_url'         => admin_url('post.php?post=' . $post->ID . '&action=elementor'),
                'preview_url'      => get_permalink($post),
                'element_count'    => self::count_elements($post->ID),
            ];

            if ($include_conditions) {
                $entry['display_conditions'] = $conditions;
            }

            if ($match) {
                $entry['applies_to_post'] = $match['applies'];
                $entry['match_reason']    = $match['reason'];
            }

            $templates[] = $entry;

            if (!empty($entry['applies_to_post'])) {
                $applied_templates[] = $entry;
            }

            if ($group_by_type) {
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [];
                }
                $grouped[$type][] = $entry;
            }
        }

        $data = [
            'count'     => count($templates),
            'templates' => $templates,
        ];

        if ($group_by_type) {
            $data['grouped'] = $grouped;
        }

        if ($applies_to_post_id) {
            $data['applies_to_post_id'] = $applies_to_post_id;
            $data['applied_templates']  = $applied_templates;
        }

        return ['success' => true, 'data' => $data];
    }

    private static function theme_builder_types(): array {
        return ['header', 'footer', 'single', 'single-post', 'single-page', 'archive', 'loop', 'error-404', 'product', 'product-archive'];
    }

    private static function type_to_location(string $type): string {
        if (in_array($type, ['header', 'footer'], true)) {
            return $type;
        }

        if (in_array($type, ['single', 'single-post', 'single-page', 'product'], true)) {
            return 'single';
        }

        if (in_array($type, ['archive', 'product-archive'], true)) {
            return 'archive';
        }

        return '';
    }

    private static function get_conditions(int $template_id): array {
        $conditions = get_post_meta($template_id, '_elementor_conditions', true);
        if (!is_array($conditions)) {
            $conditions = [];
        }
        return array_values(array_filter(array_map('strval', $conditions)));
    }

    private static function count_elements(int $post_id): int {
        $data = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($data) && '' !== $data) {
            $data = json_decode($data, true);
        }
        if (!is_array($data)) {
            return 0;
        }
        return self::count_elements_recursive($data);
    }

    private static function count_elements_recursive(array $elements): int {
        $count = 0;
        foreach ($elements as $element) {
            $count++;
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $count += self::count_elements_recursive($element['elements']);
            }
        }
        return $count;
    }

    private static function matches_post(array $conditions, int $post_id, string $template_type): array {
        if (empty($conditions)) {
            return ['applies' => false, 'reason' => 'No display conditions stored.'];
        }

        $include_matches = [];
        $exclude_matches = [];

        foreach ($conditions as $condition) {
            $parsed = self::parse_condition($condition);
            if (!$parsed) {
                continue;
            }

            $matches = self::condition_matches_post($parsed['path'], $post_id, $template_type);
            if ($matches && 'exclude' === $parsed['mode']) {
                $exclude_matches[] = $condition;
            }
            if ($matches && 'include' === $parsed['mode']) {
                $include_matches[] = $condition;
            }
        }

        if (!empty($exclude_matches)) {
            return ['applies' => false, 'reason' => 'Excluded by: ' . implode(', ', $exclude_matches)];
        }

        if (!empty($include_matches)) {
            return ['applies' => true, 'reason' => 'Included by: ' . implode(', ', $include_matches)];
        }

        return ['applies' => false, 'reason' => 'No include condition matched this post.'];
    }

    private static function parse_condition(string $condition): ?array {
        $parts = array_values(array_filter(explode('/', $condition), 'strlen'));
        if (count($parts) < 2) {
            return null;
        }

        $mode = array_shift($parts);
        if (!in_array($mode, ['include', 'exclude'], true)) {
            return null;
        }

        return ['mode' => $mode, 'path' => $parts];
    }

    private static function condition_matches_post(array $path, int $post_id, string $template_type): bool {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $first  = $path[0] ?? '';
        $second = $path[1] ?? '';
        $third  = $path[2] ?? '';

        if ('general' === $first) {
            return true;
        }

        if ('singular' === $first) {
            if (in_array($template_type, ['header', 'footer'], true)) {
                return true;
            }

            if ('' === $second) {
                return is_singular($post->post_type) || in_array($post->post_type, get_post_types(['public' => true]), true);
            }

            if ($second === $post->post_type) {
                return '' === $third || (string) $post_id === (string) $third;
            }

            if ('page' === $second && 'page' === $post->post_type) {
                return '' === $third || (string) $post_id === (string) $third;
            }

            return false;
        }

        if ('post' === $first || 'page' === $first) {
            return $post->post_type === $first && ('' === $second || (string) $post_id === (string) $second);
        }

        if ('post_type' === $first) {
            return '' === $second || $post->post_type === $second;
        }

        if ('archive' === $first) {
            return false;
        }

        return false;
    }
}

add_action('wp_abilities_api_init', [List_Templates::class, 'register']);
