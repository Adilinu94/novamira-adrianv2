<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Helpers — shared helper methods for AdrianV2 abilities.
 *
 * Merged from novamira-adrians v1 (Helpers + Guards) plus the Elementor_Data
 * helper methods from the former trait in novamira-adrians-extra. Lives in the
 * shared `Novamira\AdrianV2\Helpers` namespace so every ability sub-domain can
 * call Helpers::* / Guards::* without trait-import boilerplate.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared helper methods for AdrianV2 abilities.
 *
 * @since 1.0.0
 */
final class Helpers {

    /**
     * Find an e_global_class post by its class ID.
     *
     * @param string $class_id The global class ID.
     * @return \WP_Post|null
     */
    public static function get_class_post(string $class_id): ?\WP_Post {
        $posts = get_posts([
            'post_type'      => 'e_global_class',
            'meta_key'       => '_elementor_global_class_id',
            'meta_value'     => $class_id,
            'posts_per_page' => 1,
            'post_status'    => 'any',
        ]);
        return empty($posts) ? null : $posts[0];
    }

    /**
     * Auto-wrap scalar CSS values into v4 {$$type, value} format.
     *
     * @param array<string, mixed> $props Style properties.
     * @return array<string, mixed>
     */
    public static function wrap_style_props(array $props): array {
        $wrapped = [];
        foreach ($props as $key => $value) {
            if (is_array($value) && isset($value['$$type'])) {
                $wrapped[$key] = $value;
                continue;
            }

            if (in_array($key, ['color', 'background-color', 'border-color', 'text-decoration-color', 'outline-color'], true)) {
                $wrapped[$key] = ['$$type' => 'color', 'value' => $value];
                continue;
            }

            if (in_array($key, ['font-size', 'line-height', 'letter-spacing', 'word-spacing', 'width', 'height',
                'min-width', 'min-height', 'max-width', 'max-height', 'padding', 'margin', 'gap',
                'border-radius', 'border-width', 'outline-width'], true)) {
                if (is_string($value) && preg_match('/^(-?[\d.]+)\s*(px|em|rem|%|vw|vh|pt|cm|mm|in|pc|ex|ch|vmin|vmax)$/', $value, $m)) {
                    $wrapped[$key] = ['$$type' => 'size', 'value' => ['size' => (float) $m[1], 'unit' => $m[2]]];
                } elseif (is_numeric($value)) {
                    $wrapped[$key] = ['$$type' => 'size', 'value' => ['size' => (float) $value, 'unit' => 'px']];
                } elseif (is_string($value) && str_starts_with($value, 'var(')) {
                    $wrapped[$key] = ['$$type' => 'size', 'value' => ['size' => 0, 'unit' => 'px']];
                } else {
                    $wrapped[$key] = $value;
                }
                continue;
            }

            if (in_array($key, ['font-weight', 'font-style', 'font-family', 'text-transform', 'text-decoration',
                'text-align', 'display', 'position', 'flex-direction', 'flex-wrap', 'justify-content',
                'align-items', 'align-content', 'align-self', 'overflow', 'white-space', 'cursor',
                'box-sizing', 'visibility', 'pointer-events'], true)) {
                $wrapped[$key] = ['$$type' => 'string', 'value' => (string) $value];
                continue;
            }

            $wrapped[$key] = $value;
        }
        return $wrapped;
    }

    /**
     * Sanitize a string into a valid variable/class label.
     *
     * @param string $str Raw string.
     * @return string
     */
    public static function sanitize_label(string $str): string {
        $str = strtolower(trim($str));
        $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
        $str = preg_replace('/\s+/', '-', $str);
        $str = trim($str, '-');
        return $str ?: 'untitled';
    }

    /**
     * Load v4 global variables from the kit.
     *
     * @param int $kit_id The Elementor kit post ID.
     * @return array{0: array<string, mixed>, 1: array{data: array, watermark: int, version: int}} Data map and full wrapper.
     */
    public static function load_v4_variables(int $kit_id): array {
        $raw = get_post_meta($kit_id, '_elementor_global_variables', true);
        $wrapper = (is_string($raw) && !empty($raw)) ? json_decode($raw, true) : null;
        if (!is_array($wrapper)) {
            $wrapper = ['data' => [], 'watermark' => 1, 'version' => 2];
        }
        return [$wrapper['data'] ?? [], $wrapper];
    }

    /**
     * Save v4 global variables back to the kit.
     *
     * @param int                  $kit_id  The Elementor kit post ID.
     * @param array<string, mixed> $data    Variable data map.
     * @param array<string, mixed> $wrapper Full wrapper array.
     */
    public static function save_v4_variables(int $kit_id, array $data, array $wrapper): void {
        $wrapper['data'] = $data;
        update_post_meta($kit_id, '_elementor_global_variables', wp_slash(wp_json_encode($wrapper)));
    }

    /**
     * Wrap a raw value into the {$$type, value} format required by Elementor v4 variables.
     *
     * @param string $type  The variable type (color, font, size, string).
     * @param string $value The raw value.
     * @return array{$$type: string, value: mixed}
     */
    public static function wrap_variable_value(string $type, string $value): array {
        switch ($type) {
            case 'color':
                return ['$$type' => 'color', 'value' => $value];
            case 'font':
                return ['$$type' => 'string', 'value' => $value];
            case 'size':
                $size_val = 0;
                $unit_val = 'px';
                if (preg_match('/^(-?[\d.]+)\s*(px|em|rem|%|vw|vh|pt|cm|mm)$/', $value, $m)) {
                    $size_val = (float) $m[1];
                    $unit_val = $m[2];
                }
                return ['$$type' => 'size', 'value' => ['size' => $size_val, 'unit' => $unit_val]];
            default:
                return ['$$type' => 'string', 'value' => $value];
        }
    }

    /**
     * Unwrap a value, flattening any atomic wrapper.
     *
     * Port of trait-elementor-data-helpers from novamira-adrians-extra.
     *
     * @param mixed $value The raw value.
     * @return mixed The unwrapped value.
     */
    public static function unwrap_atomic($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (isset($value['$$type']) && array_key_exists('value', $value)) {
            return $value['value'];
        }
        return $value;
    }

    /**
     * Walks a settings array and unwraps every atomic prop recursively.
     *
     * @param array $settings Element settings.
     * @return array Settings with atomic props unwrapped.
     */
    public static function flatten_settings(array $settings): array {
        $out = [];
        foreach ($settings as $key => $value) {
            if (is_array($value) && isset($value['$$type'])) {
                $out[$key] = self::unwrap_atomic($value);
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::flatten_settings($value);
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }
}

/**
 * Guard utilities for AdrianV2 abilities.
 *
 * Cache invalidation, data format validation, and render guards shared across
 * multiple ability sub-domains.
 *
 * @since 1.0.0
 */
final class Guards {

    /**
     * Invalidate Elementor CSS cache for a post.
     */
    public static function invalidate_elementor_cache(int $post_id): void {
        clean_post_cache($post_id);

        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
            (new \Elementor\Core\Files\CSS\Post($post_id))->delete();
        }

        if (isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    }

    /**
     * Invalidate all Elementor-related caches globally.
     */
    public static function invalidate_all_elementor_caches(): void {
        if (class_exists('\Elementor\Plugin')) {
            $instance = \Elementor\Plugin::$instance;
            if (isset($instance->files_manager)) {
                $instance->files_manager->clear_cache();
            }
        }

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        if (class_exists('\Elementor\Core\Files\CSS\Global_CSS')) {
            (new \Elementor\Core\Files\CSS\Global_CSS())->update();
        }
    }

    /**
     * Check if a post exists and is built with Elementor.
     *
     * @return true|\WP_Error True if valid, WP_Error otherwise.
     */
    public static function ensure_elementor_post(int $post_id): true|\WP_Error {
        $post = get_post($post_id);

        if (!$post) {
            return new \WP_Error('post_not_found', sprintf('Post with ID %d not found.', $post_id));
        }

        $document = \Elementor\Plugin::instance()->documents->get($post_id);

        if (!$document || !$document->is_built_with_elementor()) {
            return new \WP_Error('not_elementor', 'This post is not built with Elementor.');
        }

        return true;
    }

    /**
     * Validate that JSON data decodes correctly.
     *
     * @return array|false Decoded array or false on failure.
     */
    public static function validate_json(string $json, string $label = 'data'): array|false {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return is_array($decoded) ? $decoded : false;
    }

    /**
     * Validate that an Elementor element data array is well-formed.
     */
    public static function is_valid_elementor_data(mixed $data): bool {
        if (!is_array($data)) {
            return false;
        }

        foreach ($data as $element) {
            if (!is_array($element) || !isset($element['id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decode and validate Elementor page data from post meta.
     *
     * @return array|false Decoded element data or false on failure.
     */
    public static function get_elementor_data(int $post_id): array|false {
        $raw = get_post_meta($post_id, '_elementor_data', true);

        if (!is_string($raw) || empty($raw)) {
            return false;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return false;
        }

        return $data;
    }

    /**
     * Save Elementor page data back to post meta and invalidate caches.
     */
    public static function save_elementor_data(int $post_id, array $data): void {
        $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        update_post_meta($post_id, '_elementor_data', wp_slash($encoded));
        self::invalidate_elementor_cache($post_id);
    }

    /**
     * Validate that the markdown_rendering experiment is active.
     *
     * @return true|\WP_Error
     */
    public static function ensure_markdown_rendering_active(): true|\WP_Error {
        $experiments = \Elementor\Plugin::instance()->experiments;

        if (!$experiments->is_feature_active('markdown_rendering')) {
            return new \WP_Error(
                'markdown_rendering_inactive',
                'The markdown_rendering experiment is not active. Enable it in Elementor > Settings > Features.'
            );
        }

        return true;
    }
}
