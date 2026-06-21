<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Elementor_Version_Resolver — Kanonische V3/V4-Detection für das gesamte Plugin.
 *
 * Ersetzt die verstreuten Version-Checks in Elementor_WC_Bridge, V4_Props und
 * atomic-layouts durch eine einzige, gecachte Quelle. Jede Ability, die wissen
 * muss ob sie auf einer V3- oder V4-Seite operiert, nutzt diese Klasse.
 *
 * Detection-Strategie (pro Post):
 *   1. `_elementor_version` Post-Meta >= 4.0 → V4
 *   2. `_elementor_data` enthält V4-Atomic-Container (e-flexbox, e-div-block) → V4
 *   3. `Elementor\Modules\GlobalClasses\Global_Classes_Repository` existiert → V4
 *   4. Sonst → V3
 *
 * Site-weit:
 *   - `ELEMENTOR_VERSION` >= 4.0 ODER Global_Classes_Repository-Klasse existiert
 *
 * Caching:
 *   - `wp_cache_*` mit 5-Minuten-TTL pro Post-ID (Cache-Group: 'novamira')
 *   - Cache-Bust: `wp_cache_delete('novamira_resolver_v4_' . $post_id, 'novamira')`
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static Elementor-V3/V4-Detection-Helper.
 *
 * @since 1.1.0
 */
final class Elementor_Version_Resolver {

    /**
     * Version constants for `target_version` / return values.
     */
    public const VERSION_V3   = 'v3';
    public const VERSION_V4   = 'v4';
    public const VERSION_AUTO = 'auto';

    /**
     * Elementor's V4 Global Classes repository class name (fully qualified).
     */
    private const V4_GLOBAL_CLASSES_REPO = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';

    /**
     * Cache group used for version-detection results.
     */
    private const CACHE_GROUP = 'novamira';

    /**
     * Cache TTL in seconds (5 minutes).
     */
    private const CACHE_TTL = 300;

    // ────────────────────────────────────────────────────────────
    //  Public API
    // ────────────────────────────────────────────────────────────

    /**
     * Resolve user-supplied `target_version` to either 'v3' or 'v4'.
     *
     * Falls back to per-post auto-detection when target is 'auto'.
     *
     * @param int    $post_id       The post to inspect.
     * @param string $target_version One of 'auto'|'v3'|'v4'.
     * @return string 'v3'|'v4' — never 'unknown'.
     */
    public static function resolve(int $post_id, string $target_version = self::VERSION_AUTO): string {
        if (in_array($target_version, [self::VERSION_V3, self::VERSION_V4], true)) {
            return $target_version;
        }
        $detected = self::detect_page_version($post_id);
        return self::VERSION_V4 === $detected ? self::VERSION_V4 : self::VERSION_V3;
    }

    /**
     * Site-wide check: is Elementor 4.x (atomic-capable) installed?
     *
     * Checks either ELEMENTOR_VERSION >= 4.0 OR the V4 Global Classes
     * repository class exists (module loaded).
     *
     * @return bool
     */
    public static function site_is_v4(): bool {
        if (defined('ELEMENTOR_VERSION') && version_compare(ELEMENTOR_VERSION, '4.0.0', '>=')) {
            return true;
        }
        return class_exists(self::V4_GLOBAL_CLASSES_REPO);
    }

    /**
     * Per-page check: does the saved Elementor data contain atomic
     * widgets/containers?
     *
     * @param int $post_id
     * @return bool
     */
    public static function page_is_v4(int $post_id): bool {
        return self::VERSION_V4 === self::detect_page_version($post_id);
    }

    /**
     * Returns the V4 atomic schema status for the current site.
     *
     * Used by abilities to refuse early if a V4-only operation is
     * requested on a V3 site.
     *
     * @return array{elementor_version: string, atomic_supported: bool, global_classes_available: bool, elementor_active: bool}
     */
    public static function atomic_capabilities(): array {
        $elementor_active = class_exists('\\Elementor\\Plugin');
        $version_string   = defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : 'unknown';
        $site_v4          = self::site_is_v4();
        $global_classes   = class_exists(self::V4_GLOBAL_CLASSES_REPO);

        return [
            'elementor_version'        => $version_string,
            'atomic_supported'         => $site_v4 && $elementor_active,
            'global_classes_available' => $global_classes,
            'elementor_active'         => $elementor_active,
        ];
    }

    /**
     * Readable Elementor version string for the current site.
     *
     * @return string e.g. "4.1.0-beta1", "3.27.0", "not-installed"
     */
    public static function site_version_string(): string {
        if (defined('ELEMENTOR_VERSION')) {
            return (string) ELEMENTOR_VERSION;
        }
        return class_exists('\\Elementor\\Plugin') ? 'unknown' : 'not-installed';
    }

    /**
     * Detect the Elementor version of a specific page/post.
     *
     * Returns 'unknown' if the post is not an Elementor page.
     *
     * @param int $post_id
     * @return string 'v3'|'v4'|'unknown'
     */
    public static function detect_page_version(int $post_id): string {
        // Check cache first.
        $cache_key = 'novamira_resolver_v4_' . $post_id;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_string($cached) && in_array($cached, [self::VERSION_V3, self::VERSION_V4, 'unknown'], true)) {
            return $cached;
        }

        $result = self::detect_page_version_uncached($post_id);

        // Cache the result.
        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);

        return $result;
    }

    /**
     * Bust the version-detection cache for a single post.
     *
     * Call this after a manual meta write that could change the page's
     * Elementor version classification.
     *
     * @param int $post_id
     */
    public static function bust_cache(int $post_id): void {
        wp_cache_delete('novamira_resolver_v4_' . $post_id, self::CACHE_GROUP);
    }

    // ────────────────────────────────────────────────────────────
    //  Internal detection
    // ────────────────────────────────────────────────────────────

    /**
     * Uncached version of detect_page_version.
     *
     * @param int $post_id
     * @return string 'v3'|'v4'|'unknown'
     */
    private static function detect_page_version_uncached(int $post_id): string {
        // Not an Elementor page at all?
        if (!self::is_elementor_page($post_id)) {
            return 'unknown';
        }

        // Signal 1: post-level _elementor_version meta >= 4.0.
        if (function_exists('get_post_meta')) {
            $ver = (string) get_post_meta($post_id, '_elementor_version', true);
            if (preg_match('/^(\d+)\./', $ver, $m) && (int) $m[1] >= 4) {
                return self::VERSION_V4;
            }
        }

        // Signal 2: V4 atomic containers in `_elementor_data`.
        if (self::data_has_v4_atomic($post_id)) {
            return self::VERSION_V4;
        }

        // Signal 3: site-level V4 module loaded.
        if (class_exists(self::V4_GLOBAL_CLASSES_REPO)) {
            return self::VERSION_V4;
        }

        return self::VERSION_V3;
    }

    /**
     * Check whether a post is built with Elementor.
     *
     * @param int $post_id
     * @return bool
     */
    private static function is_elementor_page(int $post_id): bool {
        if ($post_id <= 0 || !function_exists('get_post_meta')) {
            return false;
        }
        $mode = (string) get_post_meta($post_id, '_elementor_edit_mode', true);
        return 'builder' === $mode;
    }

    /**
     * Walk `_elementor_data` looking for V4 atomic containers.
     *
     * @param int $post_id
     * @return bool True if any V4-atomic container (e-flexbox, e-div-block) is present.
     */
    private static function data_has_v4_atomic(int $post_id): bool {
        if (!function_exists('get_post_meta')) {
            return false;
        }
        $raw = (string) get_post_meta($post_id, '_elementor_data', true);
        if ('' === $raw) {
            return false;
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return false;
        }
        return self::tree_contains_any($json, ['e-flexbox', 'e-div-block']);
    }

    /**
     * Depth-first search for any of the given elType strings inside an
     * Elementor tree.
     *
     * @param array    $tree    Element array.
     * @param string[] $needles The elType strings to look for.
     * @return bool
     */
    private static function tree_contains_any(array $tree, array $needles): bool {
        foreach ($tree as $el) {
            if (!is_array($el)) {
                continue;
            }
            $et = isset($el['elType']) ? (string) $el['elType'] : '';
            if (in_array($et, $needles, true)) {
                return true;
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                if (self::tree_contains_any($el['elements'], $needles)) {
                    return true;
                }
            }
        }
        return false;
    }
}
