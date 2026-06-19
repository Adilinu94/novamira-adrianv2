<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if (!defined('ABSPATH')) {
    exit();
}

class List_Media
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/list-media', [
            'label'               => 'List Media',
            'description'         => 'Search, filter, and paginate through the WordPress media library. Returns metadata including dimensions, file size, alt text, and URLs. Supports filtering by MIME type, search query, date range, and sorting.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'search'     => ['type' => 'string', 'description' => 'Search by file name or title.'],
                    'mime_type'  => ['type' => 'string', 'description' => 'Filter by MIME type. Accepts partial matches like "image/", "image/png", "font/", "application/".'],
                    'orderby'    => ['type' => 'string', 'description' => 'Sort field: date, title, size. Default: date.'],
                    'order'      => ['type' => 'string', 'description' => 'Sort direction: asc or desc. Default: desc.'],
                    'per_page'   => ['type' => 'integer', 'description' => 'Items per page (max 100). Default: 50.'],
                    'page'       => ['type' => 'integer', 'description' => 'Page number (1-based). Default: 1.'],
                    'date_from'  => ['type' => 'string', 'description' => 'Filter by date from (YYYY-MM-DD).'],
                    'date_to'    => ['type' => 'string', 'description' => 'Filter by date to (YYYY-MM-DD).'],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'items'       => ['type' => 'array'],
                    'total'       => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
                    'page'        => ['type' => 'integer'],
                    'per_page'    => ['type' => 'integer'],
                    'mime_counts' => ['type' => 'object'],
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
        $search    = $input['search'] ?? '';
        $mime_type = $input['mime_type'] ?? '';
        $orderby   = $input['orderby'] ?? 'date';
        $order     = strtoupper($input['order'] ?? 'DESC');
        $per_page  = min((int)($input['per_page'] ?? 50), 100);
        $page      = max(1, (int)($input['page'] ?? 1));
        $date_from = $input['date_from'] ?? '';
        $date_to   = $input['date_to'] ?? '';

        if (!in_array($orderby, ['date', 'title', 'size'], true)) { $orderby = 'date'; }
        if (!in_array($order, ['ASC', 'DESC'], true)) { $order = 'DESC'; }

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby === 'size' ? 'date' : $orderby,
            'order'          => $order,
        ];

        if ($search) {
            $args['s'] = $search;
        }

        if ($mime_type) {
            $args['post_mime_type'] = $mime_type;
        }

        if ($date_from || $date_to) {
            $date_query = ['inclusive' => true];
            if ($date_from) { $date_query['after'] = $date_from; }
            if ($date_to)   { $date_query['before'] = $date_to; }
            $args['date_query'] = [$date_query];
        }

        $query = new \WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            $meta     = wp_get_attachment_metadata($post->ID);
            $filepath = get_attached_file($post->ID);
            $size_kb  = (file_exists($filepath) && ($sz = filesize($filepath)) !== false)
                ? round($sz / 1024, 1) : null;

            $items[] = [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'slug'     => $post->post_name,
                'mime_type' => $post->post_mime_type,
                'url'      => wp_get_attachment_url($post->ID),
                'width'    => $meta['width'] ?? null,
                'height'   => $meta['height'] ?? null,
                'file_size_kb' => $size_kb,
                'alt'      => get_post_meta($post->ID, '_wp_attachment_image_alt', true) ?: '',
                'caption'  => $post->post_excerpt ?: '',
                'description' => $post->post_content ?: '',
                'date'     => $post->post_date,
                'sizes'    => array_keys($meta['sizes'] ?? []),
                'thumbnail_url' => wp_get_attachment_thumb_url($post->ID),
            ];
        }

        // Sort by size if requested (post-processing since WP_Query can't sort by filesize)
        if ($orderby === 'size') {
            usort($items, function($a, $b) use ($order) {
                $sa = $a['file_size_kb'] ?? 0;
                $sb = $b['file_size_kb'] ?? 0;
                return $order === 'ASC' ? $sa <=> $sb : $sb <=> $sa;
            });
        }

        // MIME type distribution counts
        $mime_counts = $GLOBALS['wpdb']->get_results(
            "SELECT post_mime_type, COUNT(*) as cnt FROM {$GLOBALS['wpdb']->posts} WHERE post_type='attachment' AND post_status='inherit' GROUP BY post_mime_type ORDER BY cnt DESC",
            ARRAY_A
        );
        $counts = [];
        foreach ($mime_counts as $row) {
            $counts[$row['post_mime_type']] = (int)$row['cnt'];
        }

        return [
            'items'       => $items,
            'total'       => (int)$query->found_posts,
            'total_pages' => (int)$query->max_num_pages,
            'page'        => $page,
            'per_page'    => $per_page,
            'mime_counts' => $counts,
        ];
    }
}

add_action('wp_abilities_api_init', [List_Media::class, 'register']);
