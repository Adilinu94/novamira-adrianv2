<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Global_Widgets {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/global-widgets', [
            'label'               => 'Global Widgets',
            'description'         => 'Manage Elementor Global Widgets: list all saved global widgets, save any widget from a page as a new global widget, or insert an existing global widget into a page.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'action'       => [
                        'type' => 'string',
                        'enum' => ['list', 'save', 'insert'],
                        'description' => 'What to do: list global widgets, save a widget as global, or insert a global widget into a page.',
                    ],
                    'search'       => ['type' => 'string', 'description' => '[list] Optional search term to filter by title.'],
                    'limit'        => ['type' => 'integer', 'description' => '[list] Max results. Default 50.'],
                    'source_post_id' => ['type' => 'integer', 'description' => '[save] The page/post ID containing the widget to save as global.'],
                    'element_id'   => ['type' => 'string', 'description' => '[save] The element ID to save as a global widget.'],
                    'title'        => ['type' => 'string', 'description' => '[save] Human-readable title for the saved global widget.'],
                    'template_id'  => ['type' => 'integer', 'description' => '[insert] The global widget template ID to insert.'],
                    'target_post_id' => ['type' => 'integer', 'description' => '[insert] The page/post ID to insert the global widget into.'],
                    'target_parent_id' => ['type' => 'string', 'description' => '[insert] Target parent element ID. Omit for root.'],
                    'target_position' => ['type' => 'integer', 'description' => '[insert] Zero-based position within target parent. Omit for end.'],
                ],
                'required'   => ['action'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => ['type' => 'object'],
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
        $action = $input['action'];

        switch ($action) {
            case 'list':
                return self::gw_list($input);
            case 'save':
                return self::gw_save($input);
            case 'insert':
                return self::gw_insert($input);
            default:
                return ['success' => false, 'data' => ['message' => "Unknown action '$action'."]];
        }
    }

    private static function gw_list(array $input): array {
        $search = $input['search'] ?? '';
        $limit  = $input['limit'] ?? 50;

        $args = [
            'post_type'      => 'elementor_library',
            'posts_per_page' => min($limit, 200),
            'post_status'    => 'publish',
            'meta_key'       => '_elementor_template_type',
            'meta_value'     => 'widget',
        ];

        if ($search) {
            $args['s'] = $search;
        }

        $posts = get_posts($args);
        $results = [];

        foreach ($posts as $p) {
            $widget_type = get_post_meta($p->ID, '_elementor_template_widget_type', true);
            $data = json_decode(get_post_meta($p->ID, '_elementor_data', true), true);
            $preview = null;
            if (is_array($data) && !empty($data)) {
                $first = $data[0];
                $preview = [
                    'elType'     => $first['elType'] ?? 'widget',
                    'widgetType' => $first['widgetType'] ?? $widget_type,
                    'has_styles' => !empty($first['styles']),
                ];
            }

            $results[] = [
                'id'          => $p->ID,
                'title'       => $p->post_title,
                'status'      => $p->post_status,
                'widget_type' => $widget_type,
                'edit_url'    => get_edit_post_link($p->ID, 'raw'),
                'preview'     => $preview,
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'total'   => count($results),
                'results' => $results,
            ],
        ];
    }

    private static function gw_save(array $input): array {
        $source_post_id = $input['source_post_id'] ?? null;
        $element_id     = $input['element_id'] ?? null;
        $title          = $input['title'] ?? null;

        if (!$source_post_id || !$element_id) {
            return ['success' => false, 'data' => ['message' => 'source_post_id and element_id are required for save action.']];
        }

        $post = get_post($source_post_id);
        if (!$post) {
            return ['success' => false, 'data' => ['message' => "Source post $source_post_id not found."]];
        }

        $raw  = get_post_meta($source_post_id, '_elementor_data', true);
        $tree = json_decode($raw, true);
        if (!is_array($tree)) {
            return ['success' => false, 'data' => ['message' => 'Elementor data not found on source post.']];
        }

        // Find the element
        $find = function (&$els, $id) use (&$find) {
            foreach ($els as &$el) {
                if ($el['id'] === $id) {
                    return $el;
                }
                if (!empty($el['elements'])) {
                    $r = $find($el['elements'], $id);
                    if ($r !== null) return $r;
                }
            }
            return null;
        };

        $element = $find($tree, $element_id);
        if ($element === null) {
            return ['success' => false, 'data' => ['message' => "Element '$element_id' not found on post $source_post_id."]];
        }

        $widget_type = $element['widgetType'] ?? null;
        if (!$widget_type || $widget_type === 'global') {
            return ['success' => false, 'data' => ['message' => 'Element is not a savable widget (missing widgetType or is already a global reference).']];
        }

        // Create template post
        $template_id = wp_insert_post([
            'post_type'    => 'elementor_library',
            'post_title'   => $title ?: ($post->post_title . ' â€” ' . $element_id),
            'post_status'  => 'publish',
            'post_content' => '',
        ]);

        if (is_wp_error($template_id) || !$template_id) {
            return ['success' => false, 'data' => ['message' => 'Failed to create template post.']];
        }

        // Set required meta
        update_post_meta($template_id, '_elementor_template_type', 'widget');
        update_post_meta($template_id, '_elementor_template_widget_type', $widget_type);
        update_post_meta($template_id, '_elementor_edit_mode', 'builder');
        update_post_meta($template_id, '_elementor_version', get_post_meta($source_post_id, '_elementor_version', true) ?: '4.1.0-beta1');

        // Store the element as the template's _elementor_data (single-element array)
        update_post_meta($template_id, '_elementor_data', wp_slash(wp_json_encode([$element])));

        return [
            'success' => true,
            'data'    => [
                'template_id'  => $template_id,
                'title'        => $title ?: ($post->post_title . ' â€” ' . $element_id),
                'widget_type'  => $widget_type,
                'source_post_id' => $source_post_id,
                'source_element_id' => $element_id,
                'edit_url'     => get_edit_post_link($template_id, 'raw'),
            ],
        ];
    }

    private static function gw_insert(array $input): array {
        $template_id      = $input['template_id'] ?? null;
        $target_post_id   = $input['target_post_id'] ?? null;
        $target_parent_id = $input['target_parent_id'] ?? null;
        $target_position  = $input['target_position'] ?? null;

        if (!$template_id || !$target_post_id) {
            return ['success' => false, 'data' => ['message' => 'template_id and target_post_id are required for insert action.']];
        }

        // Verify template exists and is a global widget
        $template = get_post($template_id);
        if (!$template || $template->post_type !== 'elementor_library') {
            return ['success' => false, 'data' => ['message' => "Template $template_id not found or not an Elementor template."]];
        }

        $template_type = get_post_meta($template_id, '_elementor_template_type', true);
        if ($template_type !== 'widget') {
            return ['success' => false, 'data' => ['message' => "Template $template_id is not a Global Widget (type: $template_type)."]];
        }

        // Read target page
        $post = get_post($target_post_id);
        if (!$post) {
            return ['success' => false, 'data' => ['message' => "Target post $target_post_id not found."]];
        }

        $raw  = get_post_meta($target_post_id, '_elementor_data', true);
        $tree = json_decode($raw, true);
        if (!is_array($tree)) {
            $tree = [];
        }

        // Create the global widget reference element
        $new_id = substr(md5(uniqid('gw', true)), 0, 7);
        $ref_element = [
            'id'         => $new_id,
            'elType'     => 'widget',
            'widgetType' => 'global',
            'templateID' => (int) $template_id,
            'elements'   => [],
        ];

        // Insert into tree
        $find_parent = function (&$elements, $id) use (&$find_parent) {
            $result = ['found' => false, 'ref' => null];
            foreach ($elements as &$el) {
                if ($el['id'] === $id) {
                    $result['found'] = true;
                    $result['el'] = &$el;
                    return $result;
                }
                if (!empty($el['elements'])) {
                    $r = $find_parent($el['elements'], $id);
                    if ($r['found']) return $r;
                }
            }
            return $result;
        };

        if ($target_parent_id) {
            $r = $find_parent($tree, $target_parent_id);
            if (!$r['found']) {
                return ['success' => false, 'data' => ['message' => "Target parent '$target_parent_id' not found in post $target_post_id."]];
            }
            if (empty($r['el']['elements']) || !is_array($r['el']['elements'])) {
                $r['el']['elements'] = [];
            }
            $target_array = &$r['el']['elements'];
        } else {
            $target_array = &$tree;
        }

        if ($target_position !== null) {
            $pos = $target_position;
            if ($pos < 0) {
                $pos = max(0, count($target_array) + 1 + $pos);
            }
            $pos = max(0, min($pos, count($target_array)));
            array_splice($target_array, $pos, 0, [$ref_element]);
        } else {
            $target_array[] = $ref_element;
            $pos = count($target_array) - 1;
        }

        update_post_meta($target_post_id, '_elementor_data', wp_slash(wp_json_encode($tree)));
        Guards::invalidate_elementor_cache($target_post_id);

        return [
            'success' => true,
            'data'    => [
                'element_id'       => $new_id,
                'template_id'      => (int) $template_id,
                'template_title'   => $template->post_title,
                'target_post_id'   => $target_post_id,
                'target_parent_id' => $target_parent_id,
                'position'         => $pos ?? count($target_array) - 1,
            ],
        ];
    }
}

add_action('wp_abilities_api_init', [Global_Widgets::class, 'register']);
