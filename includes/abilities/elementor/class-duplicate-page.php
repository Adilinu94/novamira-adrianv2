<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Duplicate_Page {
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/duplicate-page', [
            'label'               => 'Duplicate Page',
            'description'         => 'Creates a full copy of a page including Elementor data, page settings, featured image, and optionally regenerates element IDs to avoid CSS conflicts.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'source_post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the source post/page to duplicate.',
                    ],
                    'title'          => [
                        'type'        => 'string',
                        'description' => 'Title for the new page. Defaults to "Source Title (Copy)".',
                    ],
                    'slug'           => [
                        'type'        => 'string',
                        'description' => 'Slug for the new page. Auto-generated from title if omitted.',
                    ],
                    'status'         => [
                        'type'        => 'string',
                        'description' => 'Post status for the duplicate. Default: draft.',
                        'enum'        => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'regenerate_ids' => [
                        'type'        => 'boolean',
                        'description' => 'Regenerate all element IDs and style IDs in the Elementor data to avoid conflicts. Default: true.',
                    ],
                ],
                'required'   => ['source_post_id'],
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
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null) {
        $source_id       = $input['source_post_id'];
        $new_title       = $input['title'] ?? null;
        $new_slug        = $input['slug'] ?? null;
        $status          = $input['status'] ?? 'draft';
        $regenerate_ids  = $input['regenerate_ids'] ?? true;

        $source = get_post($source_id);
        if (!$source) {
            return ['success' => false, 'error' => "Source post {$source_id} not found."];
        }

        if (!$new_title) {
            $new_title = $source->post_title . ' (Copy)';
        }

        $new_id = wp_insert_post([
            'post_type'      => $source->post_type,
            'post_title'     => $new_title,
            'post_name'      => $new_slug ?? '',
            'post_status'    => $status,
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_parent'    => $source->post_parent,
            'post_author'    => $source->post_author,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
        ]);

        if (is_wp_error($new_id)) {
            return ['success' => false, 'error' => $new_id->get_error_message()];
        }

        $copied_meta = [];

        // Copy Elementor data without running WordPress' meta unslash path.
        if ($regenerate_ids) {
            $elementor_data = self::get_raw_meta_value((int) $source_id, '_elementor_data');
            if ('' !== $elementor_data) {
                $decoded = json_decode($elementor_data, true);
                if (is_array($decoded)) {
                    $decoded = self::regenerate_ids($decoded);
                    update_post_meta($new_id, '_elementor_data', wp_slash(wp_json_encode($decoded)));
                    $copied_meta[] = '_elementor_data';
                } elseif (self::copy_raw_meta_values((int) $source_id, (int) $new_id, '_elementor_data')) {
                    $copied_meta[] = '_elementor_data';
                }
            }
        } elseif (self::copy_raw_meta_values((int) $source_id, (int) $new_id, '_elementor_data')) {
            $copied_meta[] = '_elementor_data';
        }

        // Copy Elementor meta fields
        $meta_keys = [
            '_elementor_page_settings',
            '_elementor_edit_mode',
            '_elementor_version',
            '_elementor_css',
            '_elementor_controls_usage',
            '_elementor_template_type',
        ];

        foreach ($meta_keys as $key) {
            if (self::copy_raw_meta_values((int) $source_id, (int) $new_id, $key)) {
                $copied_meta[] = $key;
            }
        }

        // Copy featured image
        $thumbnail_id = get_post_thumbnail_id($source_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_id, $thumbnail_id);
            $copied_meta[] = '_thumbnail_id';
        }

        Guards::invalidate_elementor_cache($new_id);

        return [
            'success' => true,
            'data'    => [
                'new_post_id'      => $new_id,
                'title'            => $new_title,
                'status'           => $status,
                'post_type'        => $source->post_type,
                'permalink'        => get_permalink($new_id),
                'edit_url'         => get_edit_post_link($new_id, 'raw'),
                'source_post_id'   => $source_id,
                'ids_regenerated'  => $regenerate_ids,
                'copied_meta_keys' => $copied_meta,
            ],
        ];
    }

    private static function regenerate_ids(array $elements): array {
        $id_map    = [];
        $style_map = [];

        // First pass: collect all IDs and pre-generate replacements
        $collect = function ($els) use (&$collect, &$id_map, &$style_map) {
            foreach ($els as &$el) {
                if (!empty($el['id'])) {
                    $id_map[$el['id']] = substr(md5($el['id'] . mt_rand()), 0, 7);
                }
                if (!empty($el['styles']) && is_array($el['styles'])) {
                    foreach ($el['styles'] as $sid => $sdata) {
                        $style_map[$sid] = substr(md5($sid . mt_rand()), 0, 7);
                    }
                }
                if (!empty($el['elements'])) {
                    $collect($el['elements']);
                }
            }
        };
        $collect($elements);

        // Second pass: replace all IDs
        $replace = function ($els) use (&$replace, &$id_map, &$style_map) {
            foreach ($els as &$el) {
                if (!empty($el['id']) && isset($id_map[$el['id']])) {
                    $el['id'] = $id_map[$el['id']];
                }
                if (!empty($el['styles']) && is_array($el['styles'])) {
                    $new_styles = [];
                    foreach ($el['styles'] as $sid => $sdata) {
                        $new_sid = $style_map[$sid] ?? $sid;
                        $new_styles[$new_sid] = $sdata;
                    }
                    $el['styles'] = $new_styles;
                }
                if (!empty($el['elements'])) {
                    $el['elements'] = $replace($el['elements']);
                }
            }
            return $els;
        };

        return $replace($elements);
    }

    private static function get_raw_meta_value(int $post_id, string $meta_key): string {
        $values = self::get_raw_meta_values($post_id, $meta_key);
        return isset($values[0]) ? (string) $values[0] : '';
    }

    /**
     * Read raw meta values exactly as stored in wp_postmeta.
     *
     * @return string[]
     */
    private static function get_raw_meta_values(int $post_id, string $meta_key): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
                $post_id,
                $meta_key
            )
        );

        return is_array($values) ? array_map('strval', $values) : [];
    }

    /**
     * Copy raw post meta rows without wp_unslash/wp_slash transformations.
     */
    private static function copy_raw_meta_values(int $source_id, int $target_id, string $meta_key): bool {
        global $wpdb;

        $values = self::get_raw_meta_values($source_id, $meta_key);
        if (empty($values)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete(
            $wpdb->postmeta,
            [
                'post_id'  => $target_id,
                'meta_key' => $meta_key,
            ],
            [ '%d', '%s' ]
        );

        $copied = false;
        foreach ($values as $value) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $inserted = $wpdb->insert(
                $wpdb->postmeta,
                [
                    'post_id'    => $target_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $value,
                ],
                [ '%d', '%s', '%s' ]
            );
            $copied = $copied || false !== $inserted;
        }

        return $copied;
    }
}

add_action('wp_abilities_api_init', [Duplicate_Page::class, 'register']);
