<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Insert_Component
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/insert-component', [
            'label'               => 'Insert Component',
            'description'         => 'Insert a saved container template (component) into a page. Supports before/after/inside a target element, or append to the end. Element IDs are regenerated to avoid conflicts.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'           => [
                        'type'        => 'integer',
                        'description' => 'ID of the target page.',
                    ],
                    'template_id'       => [
                        'type'        => 'integer',
                        'description' => 'ID of the template/component to insert.',
                    ],
                    'target_element_id' => [
                        'type'        => 'string',
                        'description' => 'ID of the element to position relative to. Required unless position is "append".',
                    ],
                    'position'          => [
                        'type'        => 'string',
                        'description' => 'Placement: before/after the target element, inside as first child, or append to page root.',
                        'enum'        => ['before', 'after', 'inside', 'append'],
                    ],
                ],
                'required'   => ['post_id', 'template_id', 'position'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => ['type' => 'boolean'],
                    'inserted_count' => ['type' => 'integer'],
                    'inserted_ids'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'message'        => ['type' => 'string'],
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
        $post_id     = absint($input['post_id']);
        $template_id = absint($input['template_id']);
        $target      = $input['target_element_id'] ?? null;
        $position    = $input['position'];

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'message' => 'Target post not found.'];
        }

        $template_post = get_post($template_id);
        if (!$template_post || $template_post->post_type !== 'elementor_library') {
            return ['success' => false, 'message' => 'Template not found or not an Elementor template.'];
        }

        $template_data = get_post_meta($template_id, '_elementor_data', true);
        if (is_string($template_data)) {
            $template_data = json_decode($template_data, true);
        }
        if (empty($template_data)) {
            return ['success' => false, 'message' => 'No Elementor data found in template.'];
        }

        $page_data = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($page_data)) {
            $page_data = json_decode($page_data, true);
        }
        if (!is_array($page_data)) {
            $page_data = [];
        }

        $id_map = [];
        $regenerate_ids = function (&$elements) use (&$regenerate_ids, &$id_map) {
            foreach ($elements as &$el) {
                $old_id = $el['id'];
                $new_id = substr(md5($old_id . mt_rand()), 0, 7);
                $id_map[$old_id] = $new_id;
                $el['id'] = $new_id;
                if (!empty($el['elements'])) {
                    $regenerate_ids($el['elements']);
                }
            }
        };
        $regenerate_ids($template_data);

        $collect_ids = function (&$elements) use (&$collect_ids) {
            $ids = [];
            foreach ($elements as &$el) {
                $ids[] = $el['id'];
                if (!empty($el['elements'])) {
                    $ids = array_merge($ids, $collect_ids($el['elements']));
                }
            }
            return $ids;
        };

        $inserted = false;

        if ($position === 'append') {
            $page_data = array_merge($page_data, $template_data);
            $inserted  = true;
        } elseif (empty($target)) {
            return ['success' => false, 'message' => 'target_element_id is required for position "' . $position . '".'];
        } else {
            $found = false;
            $walk  = function (&$elements) use (&$walk, $target, $position, $template_data, &$found, &$inserted) {
                foreach ($elements as $i => &$el) {
                    if ($found) {
                        break;
                    }
                    if ($el['id'] === $target) {
                        $found = true;
                        switch ($position) {
                            case 'before':
                                array_splice($elements, $i, 0, $template_data);
                                break;
                            case 'after':
                                array_splice($elements, $i + 1, 0, $template_data);
                                break;
                            case 'inside':
                                if (!isset($el['elements'])) {
                                    $el['elements'] = [];
                                }
                                $el['elements'] = array_merge($template_data, $el['elements']);
                                break;
                        }
                        $inserted = true;
                        break;
                    }
                    if (!empty($el['elements'])) {
                        $walk($el['elements']);
                    }
                }
            };
            $walk($page_data);

            if (!$found) {
                return ['success' => false, 'message' => "Target element '$target' not found in page."];
            }
        }

        if (!$inserted) {
            return ['success' => false, 'message' => 'Failed to insert component.'];
        }

        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($page_data)));
        clean_post_cache($post_id);

        $inserted_ids = $collect_ids($template_data);

        return [
            'success'        => true,
            'inserted_count' => count($inserted_ids),
            'inserted_ids'   => $inserted_ids,
            'id_map'         => $id_map,
            'message'        => sprintf('Component inserted. %d elements added to page #%d.', count($inserted_ids), $post_id),
        ];
    }
}

add_action('wp_abilities_api_init', [Insert_Component::class, 'register']);
