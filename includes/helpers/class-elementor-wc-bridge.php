<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Elementor_WC_Bridge — Elementor V3/V4-Auto-Detection für WC-Produktseiten.
 *
 * Hintergründe:
 *   - Elementor V3 (vor 4.0): Section-/Column-/Widget-Hierarchie, Single-Page
 *     Templates via `_wp_page_template` (z.B. `elementor_canvas`),
 *     `_elementor_data` JSON mit `elType` (`section`, `column`, `container`,
 *     `widget`).
 *   - Elementor V4 (4.0+): Atomic-Widgets (`elType e-*`), Global Classes
 *     (geleitet durch `Elementor\Modules\GlobalClasses\Global_Classes_Repository`),
 *     Theme-Builder-2 mit eigenem Conditions-System, `_elementor_conditions` als
 *     Post-Meta pro Singular und `_elementor_pro_conditions`. V4 hat einen
 *     eigenen `PageType`-Mechanismus für Single-Produkt-Templates.
 *
 * Detection-Strategie:
 *   V4 wird erkannt wenn MINDESTENS EINE dieser Bedingungen erfüllt ist:
 *     1. `_elementor_version` Post-Meta >= 4.0
 *     2. `_elementor_data` JSON enthält einen `elType` von `e-flexbox` oder
 *        `e-div-block` (= V4-Atomic-Container)
 *     3. Die Elementor-Klasse `Elementor\Modules\GlobalClasses\Global_Classes_Repository`
 *        existiert (= V4 Modul ist geladen)
 *   Sonst: V3.
 *
 * Optional kann der Aufrufer explizit `target_version=v3|v4` setzen, dann wird
 * der Detection-Schritt übersprungen.
 *
 * @package Novamira_AdrianV2
 * @since   2.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static Elementor-V3/V4 detection + WC integration helpers.
 *
 * @since 2.0.0
 */
final class Elementor_WC_Bridge {

    /**
     * Detected versions for `target_version` constant used in helpers.
     */
    public const VERSION_V3   = 'v3';
    public const VERSION_V4   = 'v4';
    public const VERSION_AUTO = 'auto';

    /**
     * Elementor's V4 Global Classes repository class name (literal).
     */
    private const V4_GLOBAL_CLASSES_REPO = '\\Elementor\\Modules\\GlobalClasses\\Global_Classes_Repository';

    /**
     * The current page/post declares it's a built-with-Elementor page.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_elementor_page(int $post_id): bool {
        if ($post_id <= 0 || !function_exists('get_post_meta')) {
            return false;
        }
        $mode = (string) get_post_meta($post_id, '_elementor_edit_mode', true);
        return 'builder' === $mode;
    }

    /**
     * Detect the Elementor version of a specific page/post.
     *
     * Delegates to Elementor_Version_Resolver (canonical source, 1.1.0+).
     *
     * @param int $post_id
     * @return string 'v3'|'v4'|'unknown'
     */
    public static function detect_page_version(int $post_id): string {
        return Elementor_Version_Resolver::detect_page_version($post_id);
    }

    // data_has_v4_atomic() and tree_contains_any() moved to
    // Elementor_Version_Resolver (1.1.0). The Bridge delegates
    // detect_page_version() there — no local copies needed.

    /**
     * Resolve user-supplied `target_version` to either 'v3' or 'v4'.
     *
     * Delegates to Elementor_Version_Resolver (canonical source, 1.1.0+).
     *
     * @param int    $post_id
     * @param string $target_version One of 'auto'|'v3'|'v4'.
     * @return string 'v3'|'v4' — never 'unknown' here (downgrade is fine).
     */
    public static function resolve_version(int $post_id, string $target_version = self::VERSION_AUTO): string {
        return Elementor_Version_Resolver::resolve($post_id, $target_version);
    }

    /**
     * Assign an Elementor template to a WC product for the Single Product
     * page layout. V3 and V4 store this differently:
     *   - V3: uses `wc_product_meta_lookup` style indirection via
     *     `_wp_page_template` + an Elementor single-product lookup. We write
     *     `_wp_page_template` on the product post so the front-end renderer
     *     picks up `templates/single-product.php` (or set by the theme).
     *   - V4: Elementor Pro Template-Conditions are stored in `_elementor_conditions`
     *     and `_elementor_pro_conditions` on the Elementor Library post and the
     *     product post. We bind by writing the Elementor Library post id to the
     *     product post; it's read back at render time by Elementor's
     *     `Source_Local`.
     *
     * @param int    $product_id
     * @param int    $template_id Elementor Library post id.
     * @param string $version     'auto'|'v3'|'v4'.
     * @return array{ ok: bool, post_meta: array, version: string, error: ?string }
     */
    public static function set_product_template(int $product_id, int $template_id, string $version = self::VERSION_AUTO): array {
        if ($product_id <= 0 || $template_id <= 0) {
            return ['ok' => false, 'post_meta' => [], 'version' => '', 'error' => 'invalid_ids'];
        }
        // V4 needs to know the *page*'s version more than the product post's
        // (because the library template is type-conditional). We pick the
        // version via auto-detection tied to the product's single page,
        // defaulting to Library-template type info when present.
        $resolved = self::detect_library_template_version($template_id, $version);

        $written = [];
        if (self::VERSION_V4 === $resolved) {
            // V4 path: record the binding as Elementor does internally for
            // "applied to single product". We keep our own post-meta namespace
            // so a future Elementor schema change can't lose the binding.
            if (function_exists('update_post_meta')) {
                update_post_meta($product_id, '_novamira_v4_product_template_id', (string) $template_id);
                update_post_meta($template_id, '_novamira_v4_template_product_id', (string) $product_id);
                $written['product_post_meta']  = '_novamira_v4_product_template_id';
                $written['template_post_meta'] = '_novamira_v4_template_product_id';
            }
        } else {
            // V3 path: WP core's `_wp_page_template`.
            if (function_exists('update_post_meta')) {
                update_post_meta($product_id, '_wp_page_template', 'elementor-theme-builder/single-product.php');
                update_post_meta($product_id, '_novamira_v3_product_elementor_template_id', (string) $template_id);
                $written['product_post_meta'] = '_wp_page_template';
                $written['extra_post_meta']   = '_novamira_v3_product_elementor_template_id';
            }
        }

        return [
            'ok'        => !empty($written),
            'post_meta' => $written,
            'version'   => $resolved,
            'error'     => empty($written) ? 'meta_write_failed' : null,
        ];
    }

    /**
     * Detects the version of a Library template post. Honors user's
     * `target_version` if explicit, otherwise inspects the library post itself.
     *
     * @param int    $template_id Elementor Library post id.
     * @param string $wanted      User intent.
     * @return string
     */
    private static function detect_library_template_version(int $template_id, string $wanted): string {
        if (in_array($wanted, [self::VERSION_V3, self::VERSION_V4], true)) {
            return $wanted;
        }
        if (!function_exists('get_post_meta') || !function_exists('get_post')) {
            return self::VERSION_V3;
        }
        $tpl_type = (string) get_post_meta($template_id, '_elementor_template_type', true);
        $version  = (string) get_post_meta($template_id, '_elementor_version', true);
        if (preg_match('/^(\d+)\./', $version, $m) && (int) $m[1] >= 4) {
            return self::VERSION_V4;
        }
        if ('v4' === $tpl_type || str_contains($tpl_type, 'single-product-v4')) {
            return self::VERSION_V4;
        }
        return class_exists(self::V4_GLOBAL_CLASSES_REPO) ? self::VERSION_V4 : self::VERSION_V3;
    }

    /**
     * Inject a product card widget (atomic e-product-card for V4, image+title
     * stack for V3) onto a target page or template. Body-management is delegated
     * to Elementor_Data_Helpers inside the calling Ability.
     *
     * @param int    $post_id
     * @param int    $product_id
     * @param array  $options Optional placement hints.
     * @param string $version
     * @return array{ element_id: string, version: string, el_type: string, error: ?string }
     */
    public static function inject_product_card(int $post_id, int $product_id, array $options = [], string $version = self::VERSION_AUTO): array {
        if ($post_id <= 0 || $product_id <= 0) {
            return ['element_id' => '', 'version' => '', 'el_type' => '', 'error' => 'invalid_ids'];
        }
        $resolved = self::resolve_version($post_id, $version);
        $new_id   = bin2hex(random_bytes(4));
        $new_id   = substr($new_id, 0, 7);

        if (self::VERSION_V4 === $resolved) {
            // V4 atomic: e-product-card-style wrapper using e-div-block + e-image + e-heading + e-button.
            // We RETURN the descriptor; the calling ability writes it via Elementor_Data_Helpers.
            return [
                'element_id' => 'wcprod-' . $new_id,
                'version'    => self::VERSION_V4,
                'el_type'    => 'e-div-block',
                'inner'      => [
                    ['id' => 'wcimg-' . $new_id,   'elType' => 'widget', 'widgetType' => 'e-image',  'settings' => self::v4_image_settings($product_id)],
                    ['id' => 'wcttl-' . $new_id,   'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => ['tag' => 'h3']],
                    ['id' => 'wcbtn-' . $new_id,   'elType' => 'widget', 'widgetType' => 'e-button', 'settings' => ['text' => 'View product']],
                ],
            ];
        }
        // V3 path: native section-column-widget stack.
        return [
            'element_id' => 'wcprod-' . $new_id,
            'version'    => self::VERSION_V3,
            'el_type'    => 'container',
            'inner'      => [
                ['id' => 'wcimg-' . $new_id, 'elType' => 'widget', 'widgetType' => 'image',     'settings' => self::v3_image_settings($product_id)],
                ['id' => 'wcttl-' . $new_id, 'elType' => 'widget', 'widgetType' => 'heading',   'settings' => ['header_size' => 'h3']],
                ['id' => 'wcbtn-' . $new_id, 'elType' => 'widget', 'widgetType' => 'button',    'settings' => ['text' => 'View product']],
            ],
        ];
    }

    /**
     * V4-style `image` settings block produced from a product id. Pure
     * structure — actual image resolution happens at render time.
     *
     * @param int $product_id
     * @return array
     */
    private static function v4_image_settings(int $product_id): array {
        return [
            'image' => [
                '$$type' => 'image-attachment-id',
                'value'  => (string) self::fetch_product_image_id($product_id),
            ],
        ];
    }

    /**
     * V3-style image settings block.
     *
     * @param int $product_id
     * @return array
     */
    private static function v3_image_settings(int $product_id): array {
        return [
            'image' => [
                'id'  => self::fetch_product_image_id($product_id),
                'url' => '', // WC's image post meta is empty until renderer loads it.
            ],
        ];
    }

    /**
     * Returns the FEATURED image id for a product (or 0 when missing).
     *
     * @param int $product_id
     * @return int
     */
    private static function fetch_product_image_id(int $product_id): int {
        if ($product_id <= 0 || !function_exists('get_post_thumbnail_id')) {
            return 0;
        }
        return (int) get_post_thumbnail_id($product_id);
    }
}
