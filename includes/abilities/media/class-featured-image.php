<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if (!defined('ABSPATH')) {
    exit();
}

class Featured_Image
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/featured-image', [
            'label'               => 'Featured Image',
            'description'         => 'Reads, sets, or removes a post featured image. Validates attachment existence and image MIME type before setting.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'action' => [
                        'type'        => 'string',
                        'description' => 'Action: get, set, or remove.',
                        'enum'        => [ 'get', 'set', 'remove' ],
                    ],
                    'post_id' => [ 'type' => 'integer', 'description' => 'Target post/page ID.' ],
                    'attachment_id' => [ 'type' => 'integer', 'description' => 'Attachment ID to set as featured image. Required for action=set.' ],
                ],
                'required'   => [ 'action', 'post_id' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'data' => [ 'type' => 'object' ],
                    'error' => [ 'type' => 'string' ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            ],
        ]);
    }

    /**
     * Validate that an attachment exists and is an image.
     *
     * @param int $attachment_id The attachment ID.
     * @return true|string True if valid, error message otherwise.
     */
    private static function validate_attachment(int $attachment_id): true|string
    {
        $attachment = get_post($attachment_id);
        if (!$attachment || 'attachment' !== $attachment->post_type) {
            return sprintf('Attachment with ID %d not found.', $attachment_id);
        }
        $mime = get_post_mime_type($attachment);
        if (0 !== strpos((string) $mime, 'image/')) {
            return sprintf('Attachment %d is not an image. MIME type: %s.', $attachment_id, $mime ?: 'unknown');
        }
        return true;
    }

    /**
     * Build the featured image response data for a post.
     *
     * @param \WP_Post $post The post object.
     * @return array<string, mixed>
     */
    private static function featured_image_response(\WP_Post $post): array
    {
        $post_id = (int) $post->ID;
        $attachment_id = (int) get_post_thumbnail_id($post_id);
        $image = null;
        if ($attachment_id) {
            $image = [
                'id' => $attachment_id,
                'title' => get_the_title($attachment_id),
                'url' => wp_get_attachment_url($attachment_id),
                'mime_type' => get_post_mime_type($attachment_id),
                'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
                'sizes' => [],
            ];
            foreach ([ 'thumbnail', 'medium', 'large', 'full' ] as $size) {
                $src = wp_get_attachment_image_src($attachment_id, $size);
                if ($src) {
                    $image['sizes'][ $size ] = [ 'url' => $src[0], 'width' => $src[1], 'height' => $src[2] ];
                }
            }
        }
        return [
            'post_id' => $post_id,
            'post_title' => get_the_title($post),
            'post_type' => $post->post_type,
            'has_featured_image' => (bool) $attachment_id,
            'featured_image_id' => $attachment_id,
            'featured_image' => $image,
        ];
    }

    public static function execute($input = null)
    {
        $action = isset($input['action']) ? (string) $input['action'] : '';
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);

        if (!$post) {
            return [ 'success' => false, 'error' => sprintf('Post with ID %d not found.', $post_id) ];
        }

        if ('get' === $action) {
            return [ 'success' => true, 'data' => self::featured_image_response($post) ];
        }

        if ('set' === $action) {
            if (empty($input['attachment_id'])) {
                return [ 'success' => false, 'error' => 'attachment_id is required for action=set.' ];
            }
            $attachment_id = (int) $input['attachment_id'];
            $validation = self::validate_attachment($attachment_id);
            if (true !== $validation) {
                return [ 'success' => false, 'error' => $validation ];
            }
            set_post_thumbnail($post_id, $attachment_id);
            clean_post_cache($post_id);
            return [ 'success' => true, 'data' => array_merge(self::featured_image_response($post), [ 'changed' => true ]) ];
        }

        if ('remove' === $action) {
            $had_thumbnail = has_post_thumbnail($post_id);
            delete_post_thumbnail($post_id);
            clean_post_cache($post_id);
            return [ 'success' => true, 'data' => array_merge(self::featured_image_response($post), [ 'changed' => $had_thumbnail ]) ];
        }

        return [ 'success' => false, 'error' => 'action must be get, set, or remove.' ];
    }
}

add_action('wp_abilities_api_init', [Featured_Image::class, 'register']);
