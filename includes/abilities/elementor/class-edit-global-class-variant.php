<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Edit_Global_Class_Variant
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/edit-global-class-variant', [
            'label'               => 'Edit Class Variant',
            'description'         => 'Edit an existing variant on a Global Class. Target by variant index (0-based) or by breakpoint+state combination. Supports merge (default) or replace mode. Use list-class-variants first to discover indices.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'class_id'      => ['type' => 'string', 'description' => 'ID of the Global Class.'],
                    'variant_index' => ['type' => 'integer', 'description' => '0-based index of the variant to edit. Use this OR breakpoint+state.'],
                    'breakpoint'    => ['type' => 'string', 'description' => 'Target variant by breakpoint (use with state).'],
                    'state'         => ['type' => 'string', 'description' => 'Target variant by state (use with breakpoint).'],
                    'props'         => ['type' => 'object', 'description' => 'CSS properties to set or update. Merged on top of existing props by default.'],
                    'replace'       => ['type' => 'boolean', 'description' => 'Replace the entire variant props instead of merging. Default: false.'],
                ],
                'required'   => ['class_id', 'props'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'class_id'      => ['type' => 'string'],
                    'variant_index' => ['type' => 'integer'],
                    'meta'          => ['type' => 'object'],
                    'updated_props' => ['type' => 'array'],
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
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error('v4_required', sprintf(__('%s requires Elementor 4.0+. Detected version: %s.', 'novamira-adrianv2'), 'edit-global-class-variant', \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()));
        }
        $class_id      = $input['class_id'] ?? '';
        $variant_index = $input['variant_index'] ?? null;
        $breakpoint    = $input['breakpoint'] ?? null;
        $state         = $input['state'] ?? null;
        $props         = $input['props'] ?? [];
        $replace       = $input['replace'] ?? false;

        if (empty($class_id)) {
            return ['error' => 'class_id is required'];
        }
        if (empty($props)) {
            return ['error' => 'props is required'];
        }

        $post = Helpers::get_class_post($class_id);
        if (!$post) {
            return ['error' => "Class '$class_id' not found."];
        }

        $data = get_post_meta($post->ID, '_elementor_global_class_data', true);
        if (is_string($data)) {
            $data = maybe_unserialize($data);
        }
        if (!is_array($data)) {
            $data = [];
        }

        $variants = $data['variants'] ?? [];

        $idx = null;
        if ($variant_index !== null) {
            $idx = (int) $variant_index;
            if (!isset($variants[$idx])) {
                return ['error' => "Variant index $idx not found. Class has " . count($variants) . " variants (0-" . (count($variants) - 1) . ")."];
            }
        } elseif ($breakpoint !== null) {
            foreach ($variants as $i => $v) {
                if (($v['meta']['breakpoint'] ?? '') === $breakpoint && ($v['meta']['state'] ?? null) === $state) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null) {
                return ['error' => "Variant with breakpoint='$breakpoint' and state=" . ($state ?? 'null') . " not found."];
            }
        } else {
            return ['error' => 'Provide variant_index or breakpoint (+ state) to identify the variant.'];
        }

        $wrapped_props = Helpers::wrap_style_props($props);
        $old_props = $variants[$idx]['props'] ?? [];

        $variants[$idx]['props'] = $replace ? $wrapped_props : array_merge($old_props, $wrapped_props);
        $updated_keys = array_keys($wrapped_props);

        $data['variants'] = $variants;
        update_post_meta($post->ID, '_elementor_global_class_data', wp_slash(maybe_serialize($data)));

        Guards::invalidate_all_elementor_caches();

        return [
            'class_id'      => $class_id,
            'variant_index' => $idx,
            'meta'          => $variants[$idx]['meta'],
            'updated_props' => $updated_keys,
            'mode'          => $replace ? 'replace' : 'merge',
        ];
    }
}

add_action('wp_abilities_api_init', [Edit_Global_Class_Variant::class, 'register']);
