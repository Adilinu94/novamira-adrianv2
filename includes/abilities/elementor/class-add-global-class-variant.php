<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Add_Global_Class_Variant
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/add-global-class-variant', [
            'label'               => 'Add Class Variant',
            'description'         => 'Add a responsive or state variant to an existing v4 Global Class. Each variant defines CSS properties for a specific breakpoint (desktop, tablet, mobile) and/or state (hover, focus, active). This is how v4 classes achieve responsive styling that v3 typography presets cannot.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'class_id'   => ['type' => 'string', 'description' => 'ID of the Global Class to add the variant to (e.g., "gc-xxxx").'],
                    'breakpoint' => ['type' => 'string', 'description' => 'Breakpoint: desktop, tablet, or mobile. Default: desktop.'],
                    'state'      => ['type' => 'string', 'description' => 'State: null (default), hover, focus, active.'],
                    'props'      => [
                        'type'        => 'object',
                        'description' => 'CSS properties for this variant. Scalar values (color:"#FF0000", font-size:"24px", padding:24) are auto-wrapped. Pass the full {$$type, value} shape for complex types.',
                    ],
                ],
                'required'   => ['class_id', 'props'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'class_id'      => ['type' => 'string'],
                    'variant_index' => ['type' => 'integer'],
                    'variant_count' => ['type' => 'integer'],
                    'meta'          => ['type' => 'object'],
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
        // V4 guard (1.1.0): global classes are a V4-only concept.
        if (!\Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()) {
            return new \WP_Error('v4_required', sprintf(__('%s requires Elementor 4.0+. Detected version: %s.', 'novamira-adrianv2'), 'add-global-class-variant', \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()));
        }

        $class_id   = $input['class_id'] ?? '';
        $breakpoint = $input['breakpoint'] ?? 'desktop';
        $state      = $input['state'] ?? null;
        $props      = $input['props'] ?? [];

        if (empty($class_id)) {
            return ['error' => 'class_id is required'];
        }

        $valid_bps = ['desktop', 'tablet', 'mobile'];
        if (!in_array($breakpoint, $valid_bps, true)) {
            return ['error' => "Invalid breakpoint '$breakpoint'. Valid: desktop, tablet, mobile."];
        }

        $valid_states = [null, 'hover', 'focus', 'active'];
        if (!in_array($state, $valid_states, true)) {
            return ['error' => "Invalid state. Valid: null, hover, focus, active."];
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

        // Check for duplicate breakpoint+state combo
        foreach ($variants as $i => $v) {
            $v_bp = $v['meta']['breakpoint'] ?? '';
            $v_st = $v['meta']['state'] ?? null;
            if ($v_bp === $breakpoint && $v_st === $state) {
                return ['error' => "Variant with breakpoint='$breakpoint' and state=" . ($state ?? 'null') . " already exists at index $i. Use edit-global-class-variant to modify it."];
            }
        }

        $wrapped_props = Helpers::wrap_style_props($props);
        $variants[] = ['meta' => ['breakpoint' => $breakpoint, 'state' => $state], 'props' => $wrapped_props];
        $data['variants'] = $variants;

        update_post_meta($post->ID, '_elementor_global_class_data', wp_slash(maybe_serialize($data)));

        Guards::invalidate_all_elementor_caches();

        return [
            'class_id'      => $class_id,
            'variant_index' => count($variants) - 1,
            'variant_count' => count($variants),
            'meta'          => ['breakpoint' => $breakpoint, 'state' => $state],
        ];
    }
}

add_action('wp_abilities_api_init', [Add_Global_Class_Variant::class, 'register']);
