<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if (!defined('ABSPATH')) {
    exit();
}

class Delete_Media
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/delete-media', [
            'label'               => 'Delete Media',
            'description'         => 'Delete one or more attachments from the media library. By default runs a safety check that reports which Elementor pages reference each attachment. Pass force=true to bypass the check and delete permanently. Pass permanent=true to skip trash.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'attachment_ids' => ['type' => 'array', 'description' => 'Array of attachment IDs to delete.', 'items' => ['type' => 'integer']],
                    'force'          => ['type' => 'boolean', 'description' => 'Bypass the usage safety check and delete even if attachments are referenced in pages.'],
                    'permanent'      => ['type' => 'boolean', 'description' => 'Permanently delete (skip trash). Default: false (move to trash).'],
                    'dry_run'        => ['type' => 'boolean', 'description' => 'Only check usage without deleting. Returns what would happen.'],
                ],
                'required'   => ['attachment_ids'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'deleted'  => ['type' => 'array'],
                    'blocked'  => ['type' => 'array'],
                    'errors'   => ['type' => 'array'],
                    'dry_run'  => ['type' => 'boolean'],
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

    /**
     * Find where an attachment is used across Elementor pages and as featured images.
     *
     * @param int $attachment_id The attachment ID.
     * @return array<int, array<string, mixed>>
     */
    private static function find_attachment_usage(int $attachment_id): array
    {
        $used_in = [];

        // Search all Elementor pages for references to this attachment ID
        $posts = get_posts([
            'post_type'      => ['page', 'post', 'elementor_library'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_key'       => '_elementor_data',
            'meta_compare'   => 'EXISTS',
        ]);

        $id_str = (string)$attachment_id;

        foreach ($posts as $post) {
            $data = get_post_meta($post->ID, '_elementor_data', true);
            if (empty($data)) { continue; }

            $data_str = is_array($data) ? wp_json_encode($data) : $data;

            // Check for the attachment ID in various Elementor image formats
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
                $used_in[] = [
                    'post_id'    => $post->ID,
                    'post_title' => $post->post_title,
                    'post_type'  => $post->post_type,
                    'edit_url'   => get_edit_post_link($post->ID, 'raw'),
                ];
            }
        }

        // Also check featured images
        $featured_posts = get_posts([
            'post_type'      => 'any',
            'posts_per_page' => -1,
            'meta_key'       => '_thumbnail_id',
            'meta_value'     => $attachment_id,
        ]);
        foreach ($featured_posts as $fp) {
            $used_in[] = [
                'post_id'    => $fp->ID,
                'post_title' => $fp->post_title,
                'post_type'  => $fp->post_type,
                'usage'      => 'featured_image',
            ];
        }

        return $used_in;
    }

    public static function execute($input = null)
    {
        $attachment_ids = $input['attachment_ids'] ?? [];
        $force     = $input['force'] ?? false;
        $permanent = $input['permanent'] ?? false;
        $dry_run   = $input['dry_run'] ?? false;

        $deleted = [];
        $blocked = [];
        $errors  = [];

        foreach ($attachment_ids as $id) {
            $id = (int)$id;
            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                $errors[] = ['attachment_id' => $id, 'error' => 'Attachment not found'];
                continue;
            }

            // Safety: check usage
            $usage = self::find_attachment_usage($id);

            if ($dry_run) {
                $blocked[] = [
                    'attachment_id' => $id,
                    'title'   => $post->post_title,
                    'mime_type' => $post->post_mime_type,
                    'usage'   => $usage,
                    'would_delete' => empty($usage) || $force,
                ];
                continue;
            }

            if (!empty($usage) && !$force) {
                $blocked[] = [
                    'attachment_id' => $id,
                    'title'   => $post->post_title,
                    'usage'   => $usage,
                    'reason'  => 'Attachment is referenced in ' . count($usage) . ' page(s). Use force=true to delete anyway.',
                ];
                continue;
            }

            $result = wp_delete_attachment($id, $permanent);
            if ($result) {
                $deleted[] = [
                    'attachment_id' => $id,
                    'title'     => $post->post_title,
                    'permanent' => $permanent,
                    'was_used'  => !empty($usage),
                ];
            } else {
                $errors[] = ['attachment_id' => $id, 'error' => 'wp_delete_attachment returned false'];
            }
        }

        return [
            'deleted' => $deleted,
            'blocked' => $blocked,
            'errors'  => $errors,
            'dry_run' => $dry_run,
        ];
    }
}

add_action('wp_abilities_api_init', [Delete_Media::class, 'register']);
