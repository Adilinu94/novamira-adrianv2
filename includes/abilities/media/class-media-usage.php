<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if (!defined('ABSPATH')) {
    exit();
}

class Media_Usage
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/media-usage', [
            'label'               => 'Media Usage',
            'description'         => 'Find where an attachment is used across the site: Elementor pages, featured images, post content. Also supports finding unused attachments (orphaned media). Use this before deleting to understand impact, or to audit your media library.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'attachment_id' => ['type' => 'integer', 'description' => 'ID of the attachment to check. If omitted with find_unused=true, finds all unattached media.'],
                    'find_unused'   => ['type' => 'boolean', 'description' => 'Instead of checking one attachment, find all media NOT used in any Elementor page or as featured image. Set limit to control how many.'],
                    'limit'         => ['type' => 'integer', 'description' => 'Max results when find_unused is true. Default: 50.'],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'attachment_id' => ['type' => 'integer'],
                    'title'         => ['type' => 'string'],
                    'used_in'       => ['type' => 'array'],
                    'total_used'    => ['type' => 'integer'],
                    'unused'        => ['type' => 'array'],
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

    /**
     * Find Elementor pages that reference a specific attachment ID.
     *
     * @param int $attachment_id The attachment ID.
     * @return array<int, array<string, mixed>>
     */
    private static function find_attachment_in_elementor(int $attachment_id): array
    {
        $id_str = (string)$attachment_id;
        $used_in = [];

        $posts = get_posts([
            'post_type'      => ['page', 'post', 'elementor_library'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_key'       => '_elementor_data',
            'meta_compare'   => 'EXISTS',
        ]);

        foreach ($posts as $post) {
            $data = get_post_meta($post->ID, '_elementor_data', true);
            if (empty($data)) { continue; }

            $data_str = is_array($data) ? wp_json_encode($data) : $data;

            $patterns = [
                '"id":"' . $id_str . '"',
                '"id":' . $id_str . ',',
                '"id":' . $id_str . '}',
                '"img":{"id":"' . $id_str . '"',
            ];

            $found = false;
            foreach ($patterns as $pattern) {
                if (str_contains($data_str, $pattern)) {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                // Try to find which elements use it
                $elements = [];
                if (is_string($data)) { $data = json_decode($data, true); }
                if (is_array($data)) {
                    $walk = function(&$els) use (&$walk, $id_str, &$elements) {
                        foreach ($els as &$el) {
                            $el_str = wp_json_encode($el);
                            if (str_contains($el_str, '"id":"' . $id_str . '"') || str_contains($el_str, '"id":' . $id_str)) {
                                $elements[] = [
                                    'element_id' => $el['id'] ?? 'unknown',
                                    'widget_type' => $el['widgetType'] ?? ($el['elType'] ?? 'unknown'),
                                ];
                            }
                            if (!empty($el['elements'])) { $walk($el['elements']); }
                        }
                    };
                    $walk($data);
                }

                $used_in[] = [
                    'post_id'    => $post->ID,
                    'post_title' => $post->post_title,
                    'post_type'  => $post->post_type,
                    'edit_url'   => get_edit_post_link($post->ID, 'raw'),
                    'elements'   => $elements,
                ];
            }
        }

        return $used_in;
    }

    /**
     * Find posts that use a specific attachment as featured image.
     *
     * @param int $attachment_id The attachment ID.
     * @return array<int, array<string, mixed>>
     */
    private static function check_featured_image(int $attachment_id): array
    {
        $posts = get_posts([
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_thumbnail_id',
            'meta_value'     => $attachment_id,
        ]);

        return array_map(function($p) {
            return [
                'post_id'    => $p->ID,
                'post_title' => $p->post_title,
                'post_type'  => $p->post_type,
                'usage'      => 'featured_image',
            ];
        }, $posts);
    }

    public static function execute($input = null)
    {
        $attachment_id = $input['attachment_id'] ?? null;
        $find_unused   = $input['find_unused'] ?? false;
        $limit         = min((int)($input['limit'] ?? 50), 200);

        if ($find_unused) {
            // Find all attachments, then check each one
            $all_atts = get_posts([
                'post_type'      => 'attachment',
                'posts_per_page' => -1,
                'post_status'    => 'inherit',
            ]);

            $unused = [];
            $checked = 0;
            foreach ($all_atts as $att) {
                if (count($unused) >= $limit) { break; }
                $checked++;

                $elem_usage = self::find_attachment_in_elementor($att->ID);
                $feat_usage = self::check_featured_image($att->ID);

                if (empty($elem_usage) && empty($feat_usage)) {
                    $unused[] = [
                        'attachment_id' => $att->ID,
                        'title'    => $att->post_title,
                        'mime_type' => $att->post_mime_type,
                        'url'      => wp_get_attachment_url($att->ID),
                        'date'     => $att->post_date,
                    ];
                }
            }

            return [
                'unused'         => $unused,
                'total_unused'   => count($unused),
                'total_checked'  => $checked,
                'total_media'    => count($all_atts),
            ];
        }

        // Single attachment lookup
        if (empty($attachment_id)) {
            return ['error' => 'Provide attachment_id or set find_unused=true.'];
        }

        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return ['error' => 'Attachment not found.'];
        }

        $elem_usage = self::find_attachment_in_elementor($attachment_id);
        $feat_usage = self::check_featured_image($attachment_id);
        $used_in = array_merge($elem_usage, $feat_usage);

        return [
            'attachment_id' => (int)$attachment_id,
            'title'         => $post->post_title,
            'mime_type'     => $post->post_mime_type,
            'url'           => wp_get_attachment_url($attachment_id),
            'used_in'       => $used_in,
            'total_used'    => count($used_in),
        ];
    }
}

add_action('wp_abilities_api_init', [Media_Usage::class, 'register']);
