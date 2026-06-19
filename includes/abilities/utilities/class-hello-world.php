<?php
declare(strict_types=1);

/**
 * Adrians - Hello World demo ability.
 * Name: novamira/adrians-greet (one slash only per WP 6.9 regex)
 */

namespace Novamira\AdrianV2\Abilities\Utilities;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Hello_World
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/greet', [
            'label'               => 'Hello World Greet',
            'description'         => 'Returns a greeting message. Accepts an optional name parameter.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Name to greet. Defaults to "World".',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => [
                        'type'       => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $name = isset($input['name']) && is_string($input['name']) && $input['name'] !== ''
            ? $input['name']
            : 'World';

        return [
            'success' => true,
            'data'    => [
                'message' => sprintf('Hello, %s!', $name),
            ],
        ];
    }
}

add_action('wp_abilities_api_init', [Hello_World::class, 'register']);
