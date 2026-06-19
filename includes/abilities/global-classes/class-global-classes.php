<?php
declare(strict_types=1);

/**
 * V4 Global Classes Abilities — Port from EMCP class-global-classes-abilities.php
 *
 * Exposes Elementor V4 Class Manager (Global Classes) over MCP. Resolves
 * opaque `g-` class IDs back to human-readable names and the CSS properties
 * they define, per breakpoint/state.
 *
 * Architecture: Fully static, read-only. Does NOT use Elementor_Data_Helpers
 * (no page data manipulation). Gates on `Global_Classes_Repository` availability.
 * Uses V4_Props::unwrap() to flatten $$type-wrapped style props.
 *
 * @package Extra
 * @since   1.4.0
 */

namespace Novamira\AdrianV2\Abilities\GlobalClasses;

use Novamira\AdrianV2\Helpers\V4_Props;
use Novamira\AdrianV2\Helpers\V4_Styles;
use Novamira\AdrianV2\Helpers\V4_Color_Contrast;
use Novamira\AdrianV2\Helpers\V4_Content_Extractor;
use Novamira\AdrianV2\Helpers\V4_Seo_Meta;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Store;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Validator;
use Novamira\AdrianV2\Helpers\Ability_Registry;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static ability registrar for Global Classes operations.
 *
 * @since 1.4.0
 */
class Global_Classes {
    use Ability_Registry;

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * Read permission: edit_posts.
     */
    public static function check_read_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Elementor's Global Classes repository class.
     */
    private const REPOSITORY = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';

    /**
     * Whether Elementor exposes the Global Classes repository on this site.
     */
    public static function is_available(): bool {
        return class_exists(self::REPOSITORY);
    }

    /**
     * Register all Global Classes abilities.
     *
     * Call once from wp_abilities_api_init. Skips entirely when the
     * Global Classes module is not available (Elementor < 4.0).
     */
    public static function register(): void {
        if (!self::is_available()) {
            return;
        }

        self::register_list_global_classes();
    }

    // =========================================================================
    // list-global-classes
    // =========================================================================

    private static function register_list_global_classes(): void {
        $name = 'novamira-adrianv2/list-global-classes';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('List Global Classes', 'novamira-adrianv2'),
            'description'         => __(
                'Resolves Elementor Class Manager (Global Classes) entries. Maps the opaque "g-" class IDs that appear on elements back to their human-readable names and the CSS properties they define, per breakpoint/state. Use it to understand what styling a g- class applies. Pass class_ids to resolve specific IDs, or omit to list them all. Read-only.',
                'novamira-adrianv2'
            ),
            'category'            => 'elementor',
            'execute_callback'    => [self::class, 'execute_list_global_classes'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'class_ids' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => __('Optional list of g- class IDs to resolve (e.g. ["g-037bb9c"]). Omit to return every global class.', 'novamira-adrianv2'),
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'count'   => ['type' => 'integer'],
                    'classes' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'    => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'css'   => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * Execute list-global-classes.
     *
     * @param array $input Input parameters.
     * @return array|\WP_Error
     */
    public static function execute_list_global_classes($input) {
        if (!self::is_available()) {
            return new \WP_Error(
                'unavailable',
                __('Global Classes are not available — Elementor 4.0+ is required.', 'novamira-adrianv2')
            );
        }

        // Build optional ID filter.
        $filter = [];
        if (isset($input['class_ids']) && is_array($input['class_ids'])) {
            $filter = array_map('sanitize_text_field', $input['class_ids']);
        }

        // Read from Elementor's Global Classes repository.
        try {
            $repo  = self::REPOSITORY;
            $all   = $repo::make()->all();
            $items = method_exists($all, 'get_items') ? $all->get_items() : $all;

            // Elementor wraps items in a Collection with ->all().
            if (is_object($items) && method_exists($items, 'all')) {
                $items = $items->all();
            }
            $items = (array) $items;
        } catch (\Throwable $e) {
            return new \WP_Error('read_failed', $e->getMessage());
        }

        // Enumerate classes, resolving variants to flat CSS maps.
        $classes = [];
        foreach ($items as $key => $item) {
            // Defensive: one malformed entry must not abort the whole
            // enumeration. (EMCP issue #57 — explicit IDs skipped the bad
            // entry, but no-args calls failed entirely.)
            try {
                $item = (array) $item;
                $id   = isset($item['id']) ? (string) $item['id'] : (string) $key;

                if (!empty($filter) && !in_array($id, $filter, true)) {
                    continue;
                }

                $classes[] = [
                    'id'    => $id,
                    'label' => isset($item['label']) ? (string) $item['label'] : '',
                    'css'   => self::flatten_variants($item['variants'] ?? []),
                ];
            } catch (\Throwable $e) {
                $id = is_string($key) || is_int($key) ? (string) $key : '';
                if (!empty($filter) && !in_array($id, $filter, true)) {
                    continue;
                }
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(
                        '[Novamira Adrians Extra] list-global-classes: could not fully resolve class "' .
                        $id . '": ' . $e->getMessage()
                    );
                }
                // Still surface the class id so enumeration/discovery is complete.
                $classes[] = [
                    'id'    => $id,
                    'label' => '',
                    'css'   => [],
                    'error' => 'could not resolve styles for this class',
                ];
            }
        }

        return [
            'count'   => count($classes),
            'classes' => $classes,
        ];
    }

    // =========================================================================
    // Variant flattening
    // =========================================================================

    /**
     * Flatten a class's style variants into a readable map keyed by
     * breakpoint (and state), with each variant's $$type-wrapped CSS props
     * unwrapped to plain values via V4_Props::unwrap().
     *
     * @param array $variants The class variants.
     * @return array<string, array<string, mixed>>
     */
    private static function flatten_variants(array $variants): array {
        $out = [];
        foreach ($variants as $variant) {
            // Per-variant guard: a single malformed variant must not lose
            // the rest of the class's resolved CSS.
            try {
                $variant = (array) $variant;
                $meta    = (array) ($variant['meta'] ?? []);
                $bp      = isset($meta['breakpoint']) && '' !== $meta['breakpoint']
                    ? (string) $meta['breakpoint']
                    : 'desktop';
                $state   = isset($meta['state']) && '' !== $meta['state'] && null !== $meta['state']
                    ? (string) $meta['state']
                    : '';
                $key     = $state !== '' ? $bp . ':' . $state : $bp;

                $props = (array) ($variant['props'] ?? []);
                $flat  = [];
                foreach ($props as $prop_name => $prop_value) {
                    // Per-prop guard: an unexpected single prop value must
                    // not lose the rest of the variant's resolved CSS.
                    try {
                        $flat[(string) $prop_name] = V4_Props::unwrap($prop_value);
                    } catch (\Throwable $e) {
                        $flat[(string) $prop_name] = $prop_value;
                    }
                }
                $out[$key] = $flat;
            } catch (\Throwable $e) {
                // Skip this variant entirely — better a missing variant
                // than a broken response.
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(
                        '[Novamira Adrians Extra] list-global-classes: could not resolve variant: ' .
                        $e->getMessage()
                    );
                }
            }
        }
        return $out;
    }
}
