<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Media;

if (!defined('ABSPATH')) {
    exit();
}

class Edit_Media
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/edit-media', [
            'label'               => 'Edit Media',
            'description'         => 'Edit attachment metadata: title, alt text, caption, and description. Supports editing a single attachment or batch-updating multiple attachments at once.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'attachment_id' => ['type' => 'integer', 'description' => 'ID of the attachment to edit. Required unless batch is provided.'],
                    'title'         => ['type' => 'string', 'description' => 'New title for the attachment.'],
                    'alt'           => ['type' => 'string', 'description' => 'Alt text for the image (important for SEO and accessibility).'],
                    'caption'       => ['type' => 'string', 'description' => 'Caption text.'],
                    'description'   => ['type' => 'string', 'description' => 'Description text (post_content).'],
                    'batch'         => [
                        'type'  => 'array',
                        'description' => 'Batch update multiple attachments. Array of {attachment_id, title?, alt?, caption?, description?} objects.',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'attachment_id' => ['type' => 'integer'],
                                'title'         => ['type' => 'string'],
                                'alt'           => ['type' => 'string'],
                                'caption'       => ['type' => 'string'],
                                'description'   => ['type' => 'string'],
                            ],
                            'required' => ['attachment_id'],
                        ],
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'updated' => ['type' => 'array'],
                    'errors'  => ['type' => 'array'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $batch = $input['batch'] ?? null;

        if ($batch !== null) {
            // Build batch from array
            $updates = $batch;
        } else {
            // Single mode
            $attachment_id = $input['attachment_id'] ?? 0;
            if (empty($attachment_id)) {
                return ['updated' => [], 'errors' => [['attachment_id' => 0, 'error' => 'attachment_id is required when batch is not provided']]];
            }
            $updates = [[
                'attachment_id' => $attachment_id,
                'title'         => $input['title'] ?? null,
                'alt'           => $input['alt'] ?? null,
                'caption'       => $input['caption'] ?? null,
                'description'   => $input['description'] ?? null,
            ]];
        }

        $updated = [];
        $errors  = [];

        foreach ($updates as $update) {
            $id = (int)($update['attachment_id'] ?? 0);
            if (empty($id)) {
                $errors[] = ['attachment_id' => 0, 'error' => 'Missing attachment_id'];
                continue;
            }

            $post = get_post($id);
            if (!$post || $post->post_type !== 'attachment') {
                $errors[] = ['attachment_id' => $id, 'error' => 'Attachment not found'];
                continue;
            }

            $changes = [];
            $post_data = ['ID' => $id];

            if (isset($update['title']) && $update['title'] !== null) {
                $post_data['post_title'] = $update['title'];
                $changes[] = 'title';
            }
            if (isset($update['caption']) && $update['caption'] !== null) {
                $post_data['post_excerpt'] = $update['caption'];
                $changes[] = 'caption';
            }
            if (isset($update['description']) && $update['description'] !== null) {
                $post_data['post_content'] = $update['description'];
                $changes[] = 'description';
            }

            if (count($post_data) > 1) {
                $result = wp_update_post($post_data, true);
                if (is_wp_error($result)) {
                    $errors[] = ['attachment_id' => $id, 'error' => $result->get_error_message()];
                    continue;
                }
            }

            if (isset($update['alt']) && $update['alt'] !== null) {
                update_post_meta($id, '_wp_attachment_image_alt', $update['alt']);
                $changes[] = 'alt';
            }

            $updated[] = [
                'attachment_id' => $id,
                'title'         => get_the_title($id),
                'alt'           => get_post_meta($id, '_wp_attachment_image_alt', true) ?: '',
                'changes'       => $changes,
            ];
        }

        return [
            'updated' => $updated,
            'errors'  => $errors,
        ];
    }
}

add_action('wp_abilities_api_init', [Edit_Media::class, 'register']);
