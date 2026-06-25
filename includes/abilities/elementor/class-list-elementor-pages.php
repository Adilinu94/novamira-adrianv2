<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class List_Elementor_Pages {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/list-elementor-pages', [
            'label'               => 'List Elementor Pages',
            'description'         => 'Finds pages, posts, and Elementor Library items built with Elementor. Supports filters, pagination, top-level section summaries, and lightweight v3/v4/atomic stats.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_type' => [
                        'type'        => 'string',
                        'description' => 'Post type to query. Default: "page". Use "any" for all public post types plus elementor_library.',
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Post status filter. Default: "publish".',
                        'enum'        => [ 'any', 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Search term to filter by title/content.',
                    ],
                    'template_type' => [
                        'type'        => 'string',
                        'description' => 'Optional _elementor_template_type filter, e.g. wp-page, wp-post, header, footer, page, container.',
                    ],
                    'page_template' => [
                        'type'        => 'string',
                        'description' => 'Optional _wp_page_template filter, e.g. default, elementor_canvas, elementor_header_footer.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'Max number of results. Default: 50, maximum: 500.',
                    ],
                    'offset' => [
                        'type'        => 'integer',
                        'description' => 'Number of matching posts to skip. Default: 0.',
                    ],
                    'orderby' => [
                        'type'        => 'string',
                        'description' => 'Sort field. Default: title.',
                        'enum'        => [ 'title', 'date', 'modified', 'ID' ],
                    ],
                    'order' => [
                        'type'        => 'string',
                        'description' => 'Sort direction. Default: ASC.',
                        'enum'        => [ 'ASC', 'DESC' ],
                    ],
                    'include_sections' => [
                        'type'        => 'boolean',
                        'description' => 'Include top-level element summaries. Default: false.',
                    ],
                    'include_stats' => [
                        'type'        => 'boolean',
                        'description' => 'Include lightweight v3/v4/atomic widget statistics. Default: true.',
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
                            'total'              => [ 'type' => 'integer' ],
                            'limit'              => [ 'type' => 'integer' ],
                            'offset'             => [ 'type' => 'integer' ],
                            'pages'              => [ 'type' => 'array' ],
                            'post_types_checked' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
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
        $post_type         = isset($input['post_type']) ? (string) $input['post_type'] : 'page';
        $status            = isset($input['status']) ? (string) $input['status'] : 'publish';
        $search            = isset($input['search']) ? sanitize_text_field((string) $input['search']) : '';
        $template_type     = isset($input['template_type']) ? sanitize_text_field((string) $input['template_type']) : '';
        $page_template     = isset($input['page_template']) ? sanitize_text_field((string) $input['page_template']) : '';
        $limit             = isset($input['limit']) ? max(1, min((int) $input['limit'], 500)) : 50;
        $offset            = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;
        $orderby           = isset($input['orderby']) ? (string) $input['orderby'] : 'title';
        $order             = isset($input['order']) && 'DESC' === strtoupper((string) $input['order']) ? 'DESC' : 'ASC';
        $include_sections  = !empty($input['include_sections']);
        $include_stats     = !array_key_exists('include_stats', $input) || (bool) $input['include_stats'];
        $post_types_checked = self::resolve_post_types($post_type);

        $meta_query = [
            [
                'key'     => '_elementor_edit_mode',
                'value'   => 'builder',
                'compare' => '=',
            ],
        ];

        if ('' !== $template_type) {
            $meta_query[] = [
                'key'     => '_elementor_template_type',
                'value'   => $template_type,
                'compare' => '=',
            ];
        }

        if ('' !== $page_template) {
            $meta_query[] = [
                'key'     => '_wp_page_template',
                'value'   => $page_template,
                'compare' => '=',
            ];
        }

        $args = [
            'post_type'      => $post_types_checked,
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'post_status'    => $status,
            'meta_query'     => $meta_query,
            'orderby'        => $orderby,
            'order'          => $order,
            'no_found_rows'  => false,
        ];

        if ('' !== $search) {
            $args['s'] = $search;
        }

        $query = new \WP_Query($args);
        $pages = [];

        foreach ($query->posts as $post) {
            $data  = self::read_elementor_data($post->ID);
            $stats = self::collect_stats($data);

            $entry = [
                'id'            => (int) $post->ID,
                'title'         => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
                'status'        => $post->post_status,
                'post_type'     => $post->post_type,
                'permalink'     => get_permalink($post),
                'edit_url'      => admin_url('post.php?post=' . $post->ID . '&action=elementor'),
                'wp_edit_url'   => admin_url('post.php?post=' . $post->ID . '&action=edit'),
                'template_type' => get_post_meta($post->ID, '_elementor_template_type', true),
                'version'       => get_post_meta($post->ID, '_elementor_version', true),
                'edit_mode'     => get_post_meta($post->ID, '_elementor_edit_mode', true),
                'page_template' => self::normalize_page_template(get_post_meta($post->ID, '_wp_page_template', true)),
                'modified_gmt'  => get_post_modified_time('c', true, $post),
                'element_count' => $stats['element_count'],
            ];

            if ($include_stats) {
                $entry['stats'] = $stats;
            }

            if ($include_sections) {
                $entry['sections'] = self::top_level_sections($data);
            }

            $pages[] = $entry;
        }

        return [
            'success' => true,
            'data'    => [
                'count'              => count($pages),
                'total'              => (int) $query->found_posts,
                'limit'              => $limit,
                'offset'             => $offset,
                'pages'              => $pages,
                'post_types_checked' => $post_types_checked,
            ],
        ];
    }

    private static function resolve_post_types(string $post_type): array {
        if ('any' === $post_type) {
            $post_types = get_post_types(['public' => true], 'names');
            $post_types[] = 'elementor_library';
            return array_values(array_unique($post_types));
        }

        return [$post_type];
    }

    private static function read_elementor_data(int $post_id): array {
        $data = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($data) && '' !== $data) {
            $data = json_decode($data, true);
        }
        return is_array($data) ? $data : [];
    }

    private static function normalize_page_template(string $template): string {
        $template = trim($template);
        return '' === $template ? 'default' : $template;
    }

    private static function collect_stats(array $elements): array {
        $stats = [
            'element_count'        => 0,
            'top_level_count'      => count($elements),
            'atomic_widget_count'  => 0,
            'legacy_widget_count'  => 0,
            'atomic_container_count' => 0,
            'legacy_container_count' => 0,
            'widget_types'         => [],
        ];

        self::collect_stats_recursive($elements, $stats);
        ksort($stats['widget_types']);
        return $stats;
    }

    private static function collect_stats_recursive(array $elements, array &$stats): void {
        foreach ($elements as $element) {
            $stats['element_count']++;
            $el_type     = isset($element['elType']) ? (string) $element['elType'] : '';
            $widget_type = isset($element['widgetType']) ? (string) $element['widgetType'] : '';

            if ('widget' === $el_type && '' !== $widget_type) {
                if (0 === strpos($widget_type, 'e-')) {
                    $stats['atomic_widget_count']++;
                } else {
                    $stats['legacy_widget_count']++;
                }
                if (!isset($stats['widget_types'][$widget_type])) {
                    $stats['widget_types'][$widget_type] = 0;
                }
                $stats['widget_types'][$widget_type]++;
            }

            if (in_array($el_type, ['e-flexbox', 'e-div-block'], true)) {
                $stats['atomic_container_count']++;
            } elseif (in_array($el_type, ['container', 'section', 'column'], true)) {
                $stats['legacy_container_count']++;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                self::collect_stats_recursive($element['elements'], $stats);
            }
        }
    }

    private static function top_level_sections(array $elements): array {
        $sections = [];
        foreach ($elements as $element) {
            $sections[] = [
                'id'          => $element['id'] ?? '',
                'elType'      => $element['elType'] ?? 'unknown',
                'widgetType'  => $element['widgetType'] ?? null,
                'child_count' => isset($element['elements']) && is_array($element['elements']) ? count($element['elements']) : 0,
            ];
        }
        return $sections;
    }
}

add_action('wp_abilities_api_init', [List_Elementor_Pages::class, 'register']);
