<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Create_Component
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/create-component', [
            'label'               => 'Create Component',
            'description'         => 'Extract elements from a page and save them as a new container template (component) in the Elementor library. Elements are located by their IDs and extracted with their full subtree.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'source_post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the source page containing the elements to extract.',
                    ],
                    'element_ids'    => [
                        'type'        => 'array',
                        'description' => 'Array of element IDs to extract. Each element includes its children.',
                        'items'       => ['type' => 'string'],
                    ],
                    'title'          => [
                        'type'        => 'string',
                        'description' => 'Title for the new component/template.',
                    ],
                    'slug'           => [
                        'type'        => 'string',
                        'description' => 'Optional URL slug. Auto-generated from title if empty.',
                    ],
                ],
                'required'   => ['source_post_id', 'element_ids', 'title'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'       => ['type' => 'boolean'],
                    'template_id'   => ['type' => 'integer'],
                    'title'         => ['type' => 'string'],
                    'edit_url'      => ['type' => 'string'],
                    'element_count' => ['type' => 'integer'],
                    'found_ids'     => ['type' => 'array', 'items' => ['type' => 'string']],
                    'missing_ids'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'message'       => ['type' => 'string'],
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

    public static function execute($input = null)
    {
        $source_post_id = absint($input['source_post_id']);
        $element_ids    = $input['element_ids'];
        $title          = sanitize_text_field($input['title']);
        $slug           = !empty($input['slug']) ? sanitize_title($input['slug']) : sanitize_title($title);

        $source_post = get_post($source_post_id);
        if (!$source_post) {
            return ['success' => false, 'message' => 'Source post not found.'];
        }

        $source_data = get_post_meta($source_post_id, '_elementor_data', true);
        if (is_string($source_data)) {
            $source_data = json_decode($source_data, true);
        }
        if (empty($source_data)) {
            return ['success' => false, 'message' => 'No Elementor data found in source post.'];
        }

        $extracted = [];
        $found_ids = [];

        $walk = function (&$elements) use (&$walk, $element_ids, &$extracted, &$found_ids) {
            foreach ($elements as &$el) {
                if (in_array($el['id'], $element_ids, true)) {
                    $extracted[] = $el;
                    $found_ids[] = $el['id'];
                } elseif (!empty($el['elements'])) {
                    $walk($el['elements']);
                }
            }
        };
        $walk($source_data);

        if (empty($extracted)) {
            return [
                'success'      => false,
                'message'      => 'None of the specified element IDs found in the source page.',
                'searched_ids' => $element_ids,
            ];
        }

        $template_id = wp_insert_post([
            'post_title'  => $title,
            'post_name'   => $slug,
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
            'meta_input'  => [
                '_elementor_edit_mode'     => 'builder',
                '_elementor_template_type' => 'container',
                '_elementor_version'       => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
                '_wp_page_template'        => 'default',
            ],
        ]);

        if (is_wp_error($template_id)) {
            return ['success' => false, 'message' => 'Failed to create template: ' . $template_id->get_error_message()];
        }

        update_post_meta($template_id, '_elementor_data', wp_slash(wp_json_encode($extracted)));

        $page_settings = get_post_meta($source_post_id, '_elementor_page_settings', true);
        if (!empty($page_settings)) {
            update_post_meta($template_id, '_elementor_page_settings', $page_settings);
        }

        $missing_ids = array_values(array_diff($element_ids, $found_ids));

        return [
            'success'       => true,
            'template_id'   => $template_id,
            'title'         => $title,
            'edit_url'      => admin_url('post.php?post=' . $template_id . '&action=elementor'),
            'element_count' => count($extracted),
            'found_ids'     => $found_ids,
            'missing_ids'   => $missing_ids,
            'message'       => sprintf('Component created with %d elements.', count($extracted))
                              . (empty($missing_ids) ? '' : ' Missing: ' . implode(', ', $missing_ids)),
        ];
    }
}

add_action('wp_abilities_api_init', [Create_Component::class, 'register']);
