<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\V4Management;

use Novamira\AdrianV2\Helpers\V4_Props;

if (!defined('ABSPATH')) { exit; }

/**
 * Sync-Schema — exports the live V4 prop-type schema as JSON.
 *
 * Replaces the cached framer-v4-pipeline schemas/v4-atomic-schema.json
 * with a live, authoritative source from the running plugin.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */
class Sync_Schema {

    /**
     * Register the ability.
     */
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/sync-schema', [
            'name'        => 'novamira-adrianv2/sync-schema',
            'label'       => __('Sync V4 Schema', 'novamira-adrianv2'),
            'description' => __('Exports the live V4 atomic prop-type schema (compact ~5 KB, full ~50 KB).', 'novamira-adrianv2'),
            'category'    => 'adrianv2-v4-management',
            'callback'    => [self::class, 'execute'],
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'format'   => ['type' => 'string', 'enum' => ['compact', 'full'], 'default' => 'compact'],
                    'sections' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['all']],
                ],
            ],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'mcp' => ['public' => true, 'type' => 'tool'],
        ]);
    }

    /**
     * Execute: return the V4 prop-type schema.
     *
     * @param array|null $input
     * @return array
     */
    public static function execute($input = null): array {
        $format   = $input['format'] ?? 'compact';
        $sections = $input['sections'] ?? ['all'];

        $schema = V4_Props::get_schema();

        // Filter sections if requested.
        if (!in_array('all', $sections, true)) {
            $filtered = ['version' => $schema['version']];
            if (in_array('types', $sections, true)) {
                $filtered['types'] = $schema['types'];
            }
            if (in_array('properties', $sections, true)) {
                $filtered['properties'] = $schema['properties'];
            }
            $schema = $filtered;
        }

        // Compact mode: strip descriptions and example values.
        if ('compact' === $format && isset($schema['properties'])) {
            foreach ($schema['properties'] as $prop => &$def) {
                if (is_array($def)) {
                    $def = array_intersect_key($def, array_flip(['type', 'widgets']));
                }
            }
            unset($def);
        }

        return [
            'success'          => true,
            'version'          => $schema['version'] ?? '1.0.0',
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown',
            'generated_at'     => current_time('c'),
            'format'           => $format,
            'schema'           => $schema,
            'summary'          => sprintf(
                '%d types, %d properties (%s format)',
                count($schema['types'] ?? []),
                count($schema['properties'] ?? []),
                $format
            ),
        ];
    }
}
