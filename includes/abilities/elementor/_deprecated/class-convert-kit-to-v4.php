<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

if (!defined('ABSPATH')) {
    exit();
}

class Convert_Kit_To_V4
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/convert-kit-to-v4', [
            'label'               => 'Convert Kit v3 to v4 (Legacy)',
            'description'         => 'DEPRECATED: Superseded by novamira/adrians-kit-convert-v3-to-v4 which provides full 4-phase orchestration with e_global_class post type support and responsive variants. This ability is kept for reference only.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => ['type' => 'object', 'properties' => []],
            'output_schema'       => ['type' => 'object'],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        return [
            'deprecated' => true,
            'message'    => 'This ability is superseded. Use novamira/adrians-kit-convert-v3-to-v4 for the full 4-phase orchestration with e_global_class post type support and responsive variants.',
        ];
    }
}

add_action('wp_abilities_api_init', [Convert_Kit_To_V4::class, 'register']);
