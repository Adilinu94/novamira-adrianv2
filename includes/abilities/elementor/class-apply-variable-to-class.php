<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Apply_Variable_To_Class
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/apply-variable-to-class', [
            'label'               => 'Apply Variable to Class',
            'description'         => 'Set a CSS property on a Global Class variant to reference a v4 Variable (design token) instead of a hardcoded value. Stores the variable reference as var(--e-global-<type>-<id>) so that changing the variable automatically updates all classes that reference it. This is the critical binding that makes v4 a true design-token system.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'class_id'       => ['type' => 'string', 'description' => 'ID of the Global Class.'],
                    'property'       => ['type' => 'string', 'description' => 'CSS property to bind (e.g., "color", "font-size", "font-family").'],
                    'variable_id'    => ['type' => 'string', 'description' => 'ID of the v4 Variable (e.g., "e-gv-807398d") or v3 color/typo ID (e.g., "primary", "4a7cc0c").'],
                    'variable_type'  => ['type' => 'string', 'description' => 'Variable type: "color", "font", "size", or "v3-color", "v3-typo". Auto-detected if omitted.'],
                    'variant_index'  => ['type' => 'integer', 'description' => 'Variant index to modify (0-based). Default: 0 (desktop variant).'],
                ],
                'required'   => ['class_id', 'property', 'variable_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'class_id'       => ['type' => 'string'],
                    'property'       => ['type' => 'string'],
                    'variable_ref'   => ['type' => 'string'],
                    'variable_value' => ['type' => 'string'],
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
            return new \WP_Error('v4_required', sprintf(__('%s requires Elementor 4.0+. Detected version: %s.', 'novamira-adrianv2'), 'apply-variable-to-class', \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_version_string()));
        }
        $class_id      = $input['class_id'] ?? '';
        $property      = $input['property'] ?? '';
        $variable_id   = $input['variable_id'] ?? '';
        $variable_type = $input['variable_type'] ?? null;
        $variant_index = $input['variant_index'] ?? 0;

        if (empty($class_id)) {
            return ['error' => 'class_id is required'];
        }
        if (empty($property)) {
            return ['error' => 'property is required'];
        }
        if (empty($variable_id)) {
            return ['error' => 'variable_id is required'];
        }

        $kit_id = get_option('elementor_active_kit');

        $post = Helpers::get_class_post($class_id);
        if (!$post) {
            return ['error' => "Class '$class_id' not found."];
        }

        $data = get_post_meta($post->ID, '_elementor_global_class_data', true);
        if (!is_array($data)) {
            $data = [];
        }
        $variants = $data['variants'] ?? [];

        if (!isset($variants[$variant_index])) {
            return ['error' => "Variant index $variant_index not found. Class has " . count($variants) . " variants."];
        }

        // Determine the variable type and CSS variable reference
        $full_type = null;
        $var_value = null;

        if ($variable_type === null) {
            // Auto-detect: check v4 variables first, then v3
            [$v4_vars, $wrapper] = Helpers::load_v4_variables($kit_id);

            foreach ($v4_vars as $id => $v) {
                if ($id === $variable_id) {
                    $full_type = $v['type'] ?? 'global-color-variable';
                    $variable_type = str_replace('global-', '', str_replace('-variable', '', $full_type));
                    $var_value = $v['value'] ?? '';
                    $type_to_global = ['color' => 'global-color-variable', 'font' => 'global-font-variable', 'size' => 'global-size-variable'];
                    $full_type = $type_to_global[$variable_type] ?? 'global-color-variable';
                    break;
                }
            }

            // If not found in v4, check v3 colors
            if ($full_type === null) {
                $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
                $all_colors = array_merge($settings['system_colors'] ?? [], $settings['custom_colors'] ?? []);
                foreach ($all_colors as $c) {
                    if (($c['_id'] ?? $c['id'] ?? '') === $variable_id) {
                        $full_type = 'v3-color';
                        $var_value = $c['color'] ?? '';
                        break;
                    }
                }
                // Check by title match too
                if ($full_type === null) {
                    foreach ($all_colors as $c) {
                        if (($c['title'] ?? '') === $variable_id) {
                            $variable_id = $c['_id'] ?? $c['id'] ?? '';
                            $full_type = 'v3-color';
                            $var_value = $c['color'] ?? '';
                            break;
                        }
                    }
                }
            }

            // If still not found, check v3 typography
            if ($full_type === null) {
                $settings = get_post_meta($kit_id, '_elementor_page_settings', true);
                $all_typo = array_merge($settings['system_typography'] ?? [], $settings['custom_typography'] ?? []);
                foreach ($all_typo as $t) {
                    $tid = $t['_id'] ?? $t['id'] ?? '';
                    if ($tid === $variable_id) {
                        $full_type = 'v3-typo';
                        break;
                    }
                }
            }
        }

        if ($full_type === null) {
            $type_to_global = ['color' => 'global-color-variable', 'font' => 'global-font-variable', 'size' => 'global-size-variable'];
            $full_type = $type_to_global[$variable_type] ?? 'global-color-variable';
        }

        // Map CSS property to the correct $$type for variable references
        $prop_to_var_type = [
            'color'            => 'global-color-variable',
            'background-color' => 'global-color-variable',
            'border-color'     => 'global-color-variable',
            'font-family'      => 'global-font-variable',
            'font-size'        => 'global-size-variable',
            'font-weight'      => 'global-font-variable',
            'line-height'      => 'global-size-variable',
            'letter-spacing'   => 'global-size-variable',
            'word-spacing'     => 'global-size-variable',
        ];

        $var_ref_type = $prop_to_var_type[$property] ?? 'global-color-variable';
        $wrapped = ['$$type' => $var_ref_type, 'value' => $variable_id];

        $variants[$variant_index]['props'][$property] = $wrapped;
        $data['variants'] = $variants;
        update_post_meta($post->ID, '_elementor_global_class_data', $data);

        Guards::invalidate_all_elementor_caches();

        $type_to_prefix = ['global-color-variable' => 'color', 'global-font-variable' => 'typography', 'global-size-variable' => 'size'];
        $css_prefix = $type_to_prefix[$full_type] ?? 'color';
        if ($full_type === 'v3-color') {
            $css_var_ref = "var(--e-global-color-$variable_id)";
        } elseif ($full_type === 'v3-typo') {
            $css_var_ref = "var(--e-global-typography-$variable_id)";
        } else {
            $css_var_ref = "var(--e-global-$css_prefix-$variable_id)";
        }

        return [
            'class_id'       => $class_id,
            'property'       => $property,
            'variable_ref'   => $css_var_ref,
            'variable_id'    => $variable_id,
            'variable_value' => $var_value,
            'variant_index'  => $variant_index,
        ];
    }
}

add_action('wp_abilities_api_init', [Apply_Variable_To_Class::class, 'register']);
