<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Edit_Interaction
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/edit-interaction', [
            'label'               => 'Edit Interaction',
            'description'         => 'Updates an existing interaction on an atomic v4 element by zero-based index. Partial updates preserve unspecified fields.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'           => ['type' => 'integer', 'description' => 'The page/post ID containing the element.'],
                    'element_id'        => ['type' => 'string', 'description' => 'The Elementor element ID of the target atomic element.'],
                    'interaction_index' => ['type' => 'integer', 'description' => 'Zero-based interaction index. Use list-interactions first.'],
                    'trigger'           => ['type' => 'string', 'enum' => ['load', 'scrollIn', 'scrollOut', 'scrollOn', 'hover', 'click']],
                    'effect'            => ['type' => 'string', 'enum' => ['fade', 'slide', 'scale', 'custom']],
                    'type'              => ['type' => 'string', 'enum' => ['in', 'out']],
                    'direction'         => ['type' => 'string', 'enum' => ['', 'left', 'right', 'top', 'bottom']],
                    'duration'          => ['type' => 'integer', 'description' => 'Duration in milliseconds.'],
                    'delay'             => ['type' => 'integer', 'description' => 'Delay in milliseconds.'],
                    'easing'            => ['type' => 'string', 'enum' => ['easeIn', 'easeOut', 'easeInOut', 'backIn', 'backInOut', 'backOut', 'linear']],
                ],
                'required'   => ['post_id', 'element_id', 'interaction_index'],
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
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $post_id          = (int) $input['post_id'];
        $element_id       = isset($input['element_id']) ? (string) $input['element_id'] : '';
        $interaction_index = (int) $input['interaction_index'];
        $updates          = self::collectUpdates($input);

        if ('' === $element_id) {
            return ['success' => false, 'error' => 'element_id is required.'];
        }
        if (empty($updates)) {
            return ['success' => false, 'error' => 'At least one interaction field must be provided.'];
        }
        if (!get_post($post_id)) {
            return ['success' => false, 'error' => sprintf('Post with ID %d not found.', $post_id)];
        }

        $data = self::readElementorData($post_id);
        if (null === $data) {
            return ['success' => false, 'error' => 'Post has no valid Elementor data.'];
        }

        $element =& self::findElementRef($data, $element_id);
        if (null === $element) {
            return ['success' => false, 'error' => sprintf('Element "%s" not found.', $element_id)];
        }

        $parsed = self::parseStore($element['interactions'] ?? []);
        $items  = $parsed['items'];
        $total  = count($items);
        if ($interaction_index < 0 || $interaction_index >= $total) {
            return ['success' => false, 'error' => sprintf('Interaction index %d out of range. Valid range: 0-%d.', $interaction_index, max(0, $total - 1))];
        }

        $before = $items[$interaction_index];
        $after  = self::applyUpdates($before, $updates);
        $items[$interaction_index] = $after;
        $element['interactions'] = self::serializeStore($items, $parsed);

        self::writeElementorData($post_id, $data);
        Guards::invalidate_elementor_cache($post_id);

        return [
            'success' => true,
            'data'    => [
                'post_id'           => $post_id,
                'element_id'        => $element_id,
                'interaction_index' => $interaction_index,
                'before'            => $before,
                'after'             => $after,
                'updates'           => $updates,
            ],
        ];
    }

    private static function collectUpdates(array $input): array
    {
        $allowed = ['trigger', 'effect', 'type', 'direction', 'duration', 'delay', 'easing'];
        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input)) {
                $updates[$key] = $input[$key];
            }
        }
        return $updates;
    }

    private static function readElementorData($post_id): ?array
    {
        $data = get_post_meta((int) $post_id, '_elementor_data', true);
        if (is_string($data) && '' !== $data) {
            $data = json_decode($data, true);
        }
        return is_array($data) ? $data : null;
    }

    private static function writeElementorData($post_id, array $data): void
    {
        update_post_meta((int) $post_id, '_elementor_data', wp_slash(wp_json_encode($data)));
        update_post_meta((int) $post_id, '_elementor_edit_mode', 'builder');
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta((int) $post_id, '_elementor_version', ELEMENTOR_VERSION);
        }
    }

    private static function &findElementRef(array &$elements, $element_id)
    {
        $null = null;
        foreach ($elements as &$element) {
            if (isset($element['id']) && $element['id'] === $element_id) {
                return $element;
            }
            if (!empty($element['elements']) && is_array($element['elements'])) {
                $found =& self::findElementRef($element['elements'], $element_id);
                if (null !== $found) {
                    return $found;
                }
            }
        }
        return $null;
    }

    private static function parseStore($store): array
    {
        if (is_string($store) && '' !== $store) {
            $decoded = json_decode($store, true);
            if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                return ['format' => 'json-object', 'items' => $decoded['items'], 'version' => $decoded['version'] ?? 1];
            }
        }
        if (is_array($store) && isset($store['items']) && is_array($store['items'])) {
            return ['format' => 'array-object', 'items' => $store['items'], 'version' => $store['version'] ?? 1];
        }
        if (is_array($store)) {
            return ['format' => 'array-list', 'items' => $store, 'version' => 1];
        }
        return ['format' => 'array-list', 'items' => [], 'version' => 1];
    }

    private static function serializeStore(array $items, array $parsed)
    {
        if ('json-object' === $parsed['format']) {
            return wp_json_encode(['items' => $items, 'version' => $parsed['version'] ?? 1]);
        }
        if ('array-object' === $parsed['format']) {
            return ['items' => $items, 'version' => $parsed['version'] ?? 1];
        }
        return $items;
    }

    private static function applyUpdates($interaction, array $updates)
    {
        if (is_array($interaction) && isset($interaction['$$type'], $interaction['value']) && is_array($interaction['value'])) {
            if (array_key_exists('trigger', $updates)) {
                self::setWrappedValue($interaction['value'], ['trigger'], $updates['trigger']);
            }
            if (isset($interaction['value']['animation']['value']) && is_array($interaction['value']['animation']['value'])) {
                $animation =& $interaction['value']['animation']['value'];
                foreach (['effect', 'type', 'direction'] as $key) {
                    if (array_key_exists($key, $updates)) {
                        self::setWrappedValue($animation, [$key], $updates[$key]);
                    }
                }
                if (array_key_exists('duration', $updates)) {
                    self::setWrappedValue($animation, ['timing_config', 'duration'], (int) $updates['duration']);
                }
                if (array_key_exists('delay', $updates)) {
                    self::setWrappedValue($animation, ['timing_config', 'delay'], (int) $updates['delay']);
                }
                if (array_key_exists('easing', $updates)) {
                    self::setWrappedValue($animation, ['config', 'easing'], $updates['easing']);
                }
            }
            return $interaction;
        }

        if (is_array($interaction)) {
            foreach ($updates as $key => $value) {
                $interaction[$key] = in_array($key, ['duration', 'delay'], true) ? (int) $value : $value;
            }
        }
        return $interaction;
    }

    private static function setWrappedValue(array &$root, array $path, $value): void
    {
        $ref =& $root;
        foreach ($path as $segment) {
            if (isset($ref[$segment]['value']) && is_array($ref[$segment]['value'])) {
                $ref =& $ref[$segment]['value'];
                continue;
            }
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = ['$$type' => is_int($value) ? 'number' : 'string', 'value' => null];
            }
            $ref =& $ref[$segment];
        }
        if (is_array($ref) && array_key_exists('value', $ref)) {
            $ref['value'] = $value;
        } else {
            $ref = $value;
        }
    }
}

add_action('wp_abilities_api_init', [Edit_Interaction::class, 'register']);
