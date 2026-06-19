<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Duplicate_Page {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/duplicate-page', [
            'label'               => 'Duplicate Page',
            'description'         => 'Creates a full copy of a page including Elementor data, page settings, featured image, and optionally regenerates element IDs to avoid CSS conflicts.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'source_post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the source post/page to duplicate.',
                    ],
                    'title'          => [
                        'type'        => 'string',
                        'description' => 'Title for the new page. Defaults to "Source Title (Copy)".',
                    ],
                    'slug'           => [
                        'type'        => 'string',
                        'description' => 'Slug for the new page. Auto-generated from title if omitted.',
                    ],
                    'status'         => [
                        'type'        => 'string',
                        'description' => 'Post status for the duplicate. Default: draft.',
                        'enum'        => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'regenerate_ids' => [
                        'type'        => 'boolean',
                        'description' => 'Regenerate all element IDs and style IDs in the Elementor data to avoid conflicts. Default: true.',
                    ],
                ],
                'required'   => ['source_post_id'],
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
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null) {
        $source_id       = $input['source_post_id'];
        $new_title       = $input['title'] ?? null;
        $new_slug        = $input['slug'] ?? null;
        $status          = $input['status'] ?? 'draft';
        $regenerate_ids  = $input['regenerate_ids'] ?? true;

        $source = get_post($source_id);
        if (!$source) {
            return ['success' => false, 'error' => "Source post {$source_id} not found."];
        }

        if (!$new_title) {
            $new_title = $source->post_title . ' (Copy)';
        }

        $new_id = wp_insert_post([
            'post_type'      => $source->post_type,
            'post_title'     => $new_title,
            'post_name'      => $new_slug ?? '',
            'post_status'    => $status,
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_parent'    => $source->post_parent,
            'post_author'    => $source->post_author,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
        ]);

        if (is_wp_error($new_id)) {
            return ['success' => false, 'error' => $new_id->get_error_message()];
        }

        $copied_meta = [];

        // Copy Elementor data
        $elementor_data = get_post_meta($source_id, '_elementor_data', true);
        if ($elementor_data) {
            if (is_string($elementor_data)) {
                $elementor_data = json_decode($elementor_data, true);
            }
            if ($regenerate_ids && is_array($elementor_data)) {
                $elementor_data = self::regenerate_ids($elementor_data);
            }
            update_post_meta($new_id, '_elementor_data', wp_slash(wp_json_encode($elementor_data)));
            $copied_meta[] = '_elementor_data';
        }

        // Copy Elementor meta fields
        $meta_keys = [
            '_elementor_page_settings',
            '_elementor_edit_mode',
            '_elementor_version',
            '_elementor_css',
            '_elementor_controls_usage',
            '_elementor_template_type',
        ];

        foreach ($meta_keys as $key) {
            $value = get_post_meta($source_id, $key, true);
            if ($value) {
                update_post_meta($new_id, $key, $value);
                $copied_meta[] = $key;
            }
        }

        // Copy featured image
        $thumbnail_id = get_post_thumbnail_id($source_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_id, $thumbnail_id);
            $copied_meta[] = '_thumbnail_id';
        }

        Guards::invalidate_elementor_cache($new_id);

        return [
            'success' => true,
            'data'    => [
                'new_post_id'      => $new_id,
                'title'            => $new_title,
                'status'           => $status,
                'post_type'        => $source->post_type,
                'permalink'        => get_permalink($new_id),
                'edit_url'         => get_edit_post_link($new_id, 'raw'),
                'source_post_id'   => $source_id,
                'ids_regenerated'  => $regenerate_ids,
                'copied_meta_keys' => $copied_meta,
            ],
        ];
    }

    private static function regenerate_ids(array $elements): array {
        $id_map    = [];
        $style_map = [];

        // First pass: collect all IDs and pre-generate replacements
        $collect = function ($els) use (&$collect, &$id_map, &$style_map) {
            foreach ($els as &$el) {
                if (!empty($el['id'])) {
                    $id_map[$el['id']] = substr(md5($el['id'] . mt_rand()), 0, 7);
                }
                if (!empty($el['styles']) && is_array($el['styles'])) {
                    foreach ($el['styles'] as $sid => $sdata) {
                        $style_map[$sid] = substr(md5($sid . mt_rand()), 0, 7);
                    }
                }
                if (!empty($el['elements'])) {
                    $collect($el['elements']);
                }
            }
        };
        $collect($elements);

        // Second pass: replace all IDs
        $replace = function ($els) use (&$replace, &$id_map, &$style_map) {
            foreach ($els as &$el) {
                if (!empty($el['id']) && isset($id_map[$el['id']])) {
                    $el['id'] = $id_map[$el['id']];
                }
                if (!empty($el['styles']) && is_array($el['styles'])) {
                    $new_styles = [];
                    foreach ($el['styles'] as $sid => $sdata) {
                        $new_sid = $style_map[$sid] ?? $sid;
                        $new_styles[$new_sid] = $sdata;
                    }
                    $el['styles'] = $new_styles;
                }
                if (!empty($el['elements'])) {
                    $el['elements'] = $replace($el['elements']);
                }
            }
            return $els;
        };

        return $replace($elements);
    }
}

add_action('wp_abilities_api_init', [Duplicate_Page::class, 'register']);
