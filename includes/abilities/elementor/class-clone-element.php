<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Clone_Element {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/clone-element', [
            'label'               => 'Clone Element',
            'description'         => 'Copies an Elementor element and all children within or between pages. Regenerates element IDs and local style IDs, updates local class references, inserts at root or under a target parent, and clears Elementor caches.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'source_post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID of the source page containing the element to clone.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'The id of the element to clone, as stored in _elementor_data.',
                    ],
                    'target_post_id' => [
                        'type'        => 'integer',
                        'description' => 'Post ID of the target page. Defaults to source_post_id.',
                    ],
                    'target_parent_id' => [
                        'type'        => 'string',
                        'description' => 'ID of the parent element to insert under in the target page. Omit to insert at root level.',
                    ],
                    'target_position' => [
                        'type'        => 'integer',
                        'description' => 'Zero-based sibling position. Omitted or out-of-range values append at the end.',
                    ],
                    'preserve_semantic_root_id' => [
                        'type'        => 'boolean',
                        'description' => 'Keep the root element id when cloning to a different target page and it does not collide. Defaults to false.',
                    ],
                ],
                'required'   => [ 'source_post_id', 'element_id' ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => [ 'type' => 'boolean' ],
                    'data'    => [
                        'type'       => 'object',
                        'properties' => [
                            'cloned_element_id' => [ 'type' => 'string' ],
                            'id_mapping'        => [ 'type' => 'object' ],
                            'style_id_mapping'  => [ 'type' => 'object' ],
                            'total_cloned'      => [ 'type' => 'integer' ],
                            'target_post_id'    => [ 'type' => 'integer' ],
                            'target_parent_id'  => [ 'type' => 'string' ],
                            'target_position'   => [ 'type' => 'integer' ],
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
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null) {
        $source_post_id = (int) $input['source_post_id'];
        $element_id     = isset($input['element_id']) ? (string) $input['element_id'] : '';
        $target_post_id = isset($input['target_post_id']) ? (int) $input['target_post_id'] : $source_post_id;

        if ('' === $element_id) {
            return ['success' => false, 'error' => 'element_id is required.'];
        }

        $source_post = get_post($source_post_id);
        if (!$source_post) {
            return ['success' => false, 'error' => sprintf('Source post with ID %d not found.', $source_post_id)];
        }

        $target_post = get_post($target_post_id);
        if (!$target_post) {
            return ['success' => false, 'error' => sprintf('Target post with ID %d not found.', $target_post_id)];
        }

        $source_data = self::read_elementor_data($source_post_id);
        if (null === $source_data) {
            return ['success' => false, 'error' => sprintf('Source post %d has no valid Elementor data.', $source_post_id)];
        }

        $found = self::find_element($source_data, $element_id);
        if (null === $found) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found in source post %d.', $element_id, $source_post_id)];
        }

        $target_data = self::read_elementor_data($target_post_id);
        if (null === $target_data) {
            $target_data = [];
        }

        $existing_ids            = self::collect_element_ids($target_data);
        $id_mapping              = [];
        $style_id_mapping        = [];
        $total_cloned            = 0;
        $preserve_semantic_root  = !empty($input['preserve_semantic_root_id']) && $source_post_id !== $target_post_id;
        $cloned                 = self::deep_clone_element($found, $existing_ids, $id_mapping, $style_id_mapping, $total_cloned, $preserve_semantic_root);
        $cloned                 = self::rewrite_local_references($cloned, $id_mapping, $style_id_mapping);
        $target_parent_id       = isset($input['target_parent_id']) ? (string) $input['target_parent_id'] : '';
        $requested_position     = array_key_exists('target_position', $input) ? (int) $input['target_position'] : null;
        $resolved_position      = null;

        if ('' !== $target_parent_id) {
            $inserted = self::insert_under_parent($target_data, $target_parent_id, $cloned, $requested_position, $resolved_position);
            if (!$inserted) {
                return ['success' => false, 'error' => sprintf('Target parent "%s" not found in target post %d.', $target_parent_id, $target_post_id)];
            }
        } else {
            $resolved_position = self::insert_at_position($target_data, $cloned, $requested_position);
        }

        self::write_elementor_data($target_post_id, $target_data);
        Guards::invalidate_elementor_cache($target_post_id);

        return [
            'success' => true,
            'data'    => [
                'cloned_element_id' => $cloned['id'],
                'id_mapping'        => $id_mapping,
                'style_id_mapping'  => $style_id_mapping,
                'total_cloned'      => $total_cloned,
                'target_post_id'    => $target_post_id,
                'target_parent_id'  => $target_parent_id,
                'target_position'   => $resolved_position,
            ],
        ];
    }

    private static function read_elementor_data(int $post_id): ?array {
        $data = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($data) && '' !== $data) {
            $data = json_decode($data, true);
        }

        return is_array($data) ? $data : null;
    }

    private static function write_elementor_data(int $post_id, array $data): void {
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($data)));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
        }
    }

    private static function find_element(array $elements, string $element_id): ?array {
        foreach ($elements as $element) {
            if (isset($element['id']) && $element['id'] === $element_id) {
                return $element;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found = self::find_element($element['elements'], $element_id);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function collect_element_ids(array $elements): array {
        $ids = [];
        foreach ($elements as $element) {
            if (isset($element['id'])) {
                $ids[] = (string) $element['id'];
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $ids = array_merge($ids, self::collect_element_ids($element['elements']));
            }
        }
        return array_values(array_unique($ids));
    }

    private static function generate_unique_id(array &$existing_ids): string {
        do {
            $id = substr(str_replace('.', '', uniqid('', true)), -7);
        } while (in_array($id, $existing_ids, true));

        $existing_ids[] = $id;
        return $id;
    }

    private static function deep_clone_element(array $element, array &$existing_ids, array &$id_mapping, array &$style_id_mapping, int &$total_cloned, bool $preserve_this_id = false): array {
        $old_id = isset($element['id']) ? (string) $element['id'] : self::generate_unique_id($existing_ids);
        $new_id = ($preserve_this_id && !in_array($old_id, $existing_ids, true)) ? $old_id : self::generate_unique_id($existing_ids);

        $id_mapping[$old_id] = $new_id;
        $total_cloned++;

        $clone       = $element;
        $clone['id'] = $new_id;

        if (isset($element['styles']) && is_array($element['styles'])) {
            $clone['styles'] = [];
            foreach ($element['styles'] as $old_style_id => $style) {
                $new_style_id = self::generate_unique_id($existing_ids);
                $style_id_mapping[(string) $old_style_id] = $new_style_id;
                if (is_array($style)) {
                    $style['id'] = $new_style_id;
                    if (isset($style['label']) && (string) $style['label'] === (string) $old_style_id) {
                        $style['label'] = $new_style_id;
                    }
                }
                $clone['styles'][$new_style_id] = $style;
            }
        }

        $clone['elements'] = [];
        if (!empty($element['elements']) && is_array($element['elements'])) {
            foreach ($element['elements'] as $child) {
                $clone['elements'][] = self::deep_clone_element($child, $existing_ids, $id_mapping, $style_id_mapping, $total_cloned, false);
            }
        }

        return $clone;
    }

    private static function rewrite_local_references($value, array $id_mapping, array $style_id_mapping, string $context_key = '') {
        if (is_array($value)) {
            if (isset($value['$$type'], $value['value']) && 'classes' === $value['$$type']) {
                $value['value'] = self::rewrite_class_list($value['value'], $id_mapping, $style_id_mapping);
                return $value;
            }

            if ('classes' === $context_key) {
                return self::rewrite_class_list($value, $id_mapping, $style_id_mapping);
            }

            $rewritten = [];
            foreach ($value as $key => $item) {
                $new_key = array_key_exists((string) $key, $style_id_mapping) ? $style_id_mapping[(string) $key] : $key;
                $rewritten[$new_key] = self::rewrite_local_references($item, $id_mapping, $style_id_mapping, (string) $key);
            }
            return $rewritten;
        }

        if (!is_string($value)) {
            return $value;
        }

        if ('classes' === $context_key) {
            return self::rewrite_class_token($value, $id_mapping, $style_id_mapping);
        }

        foreach ($style_id_mapping as $old_style_id => $new_style_id) {
            $value = str_replace('s-' . $old_style_id, 's-' . $new_style_id, $value);
        }

        foreach ($id_mapping as $old_id => $new_id) {
            $value = str_replace('s-' . $old_id, 's-' . $new_id, $value);
            $value = str_replace('elementor-element-' . $old_id, 'elementor-element-' . $new_id, $value);
            $value = str_replace('data-id="' . $old_id . '"', 'data-id="' . $new_id . '"', $value);
            $value = str_replace("data-id='" . $old_id . "'", "data-id='" . $new_id . "'", $value);
        }

        return $value;
    }

    private static function rewrite_class_list($classes, array $id_mapping, array $style_id_mapping) {
        if (is_array($classes)) {
            $rewritten = [];
            foreach ($classes as $key => $class) {
                $rewritten[$key] = self::rewrite_class_token($class, $id_mapping, $style_id_mapping);
            }
            return $rewritten;
        }

        if (is_string($classes)) {
            $tokens = preg_split('/\s+/', trim($classes));
            $tokens = array_map(function ($token) use ($id_mapping, $style_id_mapping) {
                return self::rewrite_class_token($token, $id_mapping, $style_id_mapping);
            }, $tokens);
            return implode(' ', array_filter($tokens));
        }

        return $classes;
    }

    private static function rewrite_class_token($class, array $id_mapping, array $style_id_mapping) {
        if (!is_string($class)) {
            return $class;
        }

        if (array_key_exists($class, $style_id_mapping)) {
            return $style_id_mapping[$class];
        }

        if (preg_match('/^s-(.+)$/', $class, $matches)) {
            if (array_key_exists($matches[1], $style_id_mapping)) {
                return 's-' . $style_id_mapping[$matches[1]];
            }
            if (array_key_exists($matches[1], $id_mapping)) {
                return 's-' . $id_mapping[$matches[1]];
            }
        }

        return $class;
    }

    private static function insert_under_parent(array &$elements, string $parent_id, array $cloned, $requested_position, ?int &$resolved_position): bool {
        foreach ($elements as &$element) {
            if (isset($element['id']) && $element['id'] === $parent_id) {
                if (!isset($element['elements']) || !is_array($element['elements'])) {
                    $element['elements'] = [];
                }
                $resolved_position = self::insert_at_position($element['elements'], $cloned, $requested_position);
                return true;
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                if (self::insert_under_parent($element['elements'], $parent_id, $cloned, $requested_position, $resolved_position)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function insert_at_position(array &$elements, array $cloned, $requested_position): int {
        $count = count($elements);
        $pos   = is_int($requested_position) ? $requested_position : $count;
        $pos   = max(0, min($pos, $count));
        array_splice($elements, $pos, 0, [$cloned]);
        return $pos;
    }
}

add_action('wp_abilities_api_init', [Clone_Element::class, 'register']);
