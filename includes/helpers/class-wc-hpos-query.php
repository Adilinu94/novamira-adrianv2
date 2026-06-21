<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * WC_HPOS_Query — HPOS-aware Wrapper für WooCommerce-Datenbankzugriffe.
 *
 * WordPress-Sniff-Wahrheit: seit WC 8.x ist HPOS (High-Performance Order
 * Storage, Custom-Tables `wp_wc_orders` uvw.) der Default. Klassische
 * `WP_Query` mit `post_type='shop_order'` oder direkte `wp_posts`-Lookups
 * brechen dann. Diese Helper-Klasse ist die einzige erlaubte Schicht für
 * WC-Daten-Lookups in unseren MCP-Abilities.
 *
 * Robustheit:
 *   - `is_available()` als Gate (Klasse zurückgeben wenn WC-Plugin nicht aktiv)
 *   - HPOS-Detection via `OrderUtil::custom_orders_table_usage_is_enabled()`
 *   - Bei Fehlen von OrderUtil: legacy-Pfad bleibt funktionsfähig
 *   - Argument-Normalisierung (limit/offset → WC-konforme args)
 *   - Output ist IMMER Array (auch wenn WC_Product/Order zurückgeben würde)
 *
 * @package Novamira_AdrianV2
 * @since   2.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HPOS-aware WC query wrapper.
 *
 * @since 2.0.0
 */
final class WC_HPOS_Query {

    /**
     * Gate for `is_available()` checks upstream. Returns true if WooCommerce
     * is loaded AND WC version >= 8.0 (HPOS era).
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists('WooCommerce')
            && class_exists('WC_Product')
            && class_exists('WC_Order')
            && defined('WC_VERSION')
            && version_compare((string) WC_VERSION, '8.0.0', '>=');
    }

    /**
     * Whether the store is using HPOS (custom order tables).
     *
     * Returns false when:
     *   - WC plugin not active
     *   - WC < 8.x (OrderUtil doesn't exist)
     *   - Site opted out of HPOS via the WC settings toggle
     *
     * @return bool
     */
    public static function is_hpos_enabled(): bool {
        if (!self::is_available()) {
            return false;
        }
        if (!class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            return false;
        }
        return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Fetch products with normalized args. Wraps `wc_get_products()`.
     *
     * Argument normalization:
     *   - `limit` is converted to `limit` (WC convention)
     *   - `offset` is kept (WC supports it)
     *   - `status` defaults to 'any' if absent
     *   - `search` (anywhen) becomes `s` param of WC
     *   - `low_stock_threshold` is converted to a custom meta_query for <= N
     *
     * @param array $args Raw MCP input.
     * @return array Array of products (as arrays — call WC_Data_Formatter to format).
     */
    public static function get_products(array $args): array {
        if (!self::is_available() || !function_exists('wc_get_products')) {
            return [];
        }

        $limit  = isset($args['limit']) ? max(1, min((int) $args['limit'], 500)) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        $wc_args = [
            'limit'  => $limit,
            'offset' => $offset,
            'status' => $args['status'] ?? 'any',
            'paginate' => false,
        ];

        if (!empty($args['type'])) {
            $wc_args['type'] = (string) $args['type'];
        }
        if (!empty($args['search'])) {
            $wc_args['s'] = (string) $args['search'];
        }
        if (!empty($args['sku'])) {
            $wc_args['sku'] = (string) $args['sku'];
        }
        if (!empty($args['stock_status'])) {
            $wc_args['stock_status'] = (string) $args['stock_status'];
        }
        if (isset($args['category']) && (int) $args['category'] > 0) {
            $wc_args['category'] = [(int) $args['category']];
        }

        // Low-stock threshold filter (custom meta_query).
        if (isset($args['low_stock_threshold']) && is_int($args['low_stock_threshold'])) {
            $threshold = (int) $args['low_stock_threshold'];
            $wc_args['stock_quantity_compare'] = '<=';
            $wc_args['stock_quantity_value']   = $threshold;
        }

        // Build an orderby whitelist to keep MCP inputs safe.
        $orderby = (string) ($args['orderby'] ?? 'date');
        if (!in_array($orderby, ['date', 'title', 'menu_order', 'price', 'popularity', 'rating', 'id'], true)) {
            $orderby = 'date';
        }
        $wc_args['orderby'] = $orderby;
        $wc_args['order']   = (strtoupper((string) ($args['order'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';

        $products = wc_get_products(apply_filters('novamira_adrianv2_wc_get_products_args', $wc_args));
        return is_array($products) ? $products : [];
    }

    /**
     * Fetch a single product by id, returns the WP_Post or WC_Product-like
     * data array (via WC_Product::get_data() when available).
     *
     * @param int $product_id
     * @return array|null Empty array on miss, formatted on hit.
     */
    public static function get_product(int $product_id): ?array {
        if (!self::is_available() || $product_id <= 0) {
            return null;
        }
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return null;
        }
        return self::extract_product_data($product);
    }

    /**
     * Fetch orders (always HPOS-aware). Wraps `wc_get_orders()`.
     *
     * @param array $args Normalized order-query args.
     * @return array Array of order data arrays (or WC_Order objects — call
     *               `self::extract_order_data()` to format).
     */
    public static function get_orders(array $args): array {
        if (!self::is_available() || !function_exists('wc_get_orders')) {
            return [];
        }

        $limit  = isset($args['limit']) ? max(1, min((int) $args['limit'], 500)) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;

        $wc_args = [
            'limit'    => $limit,
            'offset'   => $offset,
            'paginate' => false,
        ];

        // Status — accept either 'status' or 'statuses[]'.
        if (!empty($args['status'])) {
            $wc_args['status'] = (string) $args['status'];
        } elseif (!empty($args['statuses']) && is_array($args['statuses'])) {
            $wc_args['status'] = array_map('strval', $args['statuses']);
        } else {
            $wc_args['status'] = 'any';
        }

        if (!empty($args['customer'])) {
            $wc_args['customer'] = (string) $args['customer'];
        }
        if (isset($args['customer_id']) && (int) $args['customer_id'] > 0) {
            $wc_args['customer_id'] = (int) $args['customer_id'];
        }
        if (!empty($args['date_from'])) {
            $wc_args['date_created'] = '>=' . (string) $args['date_from'];
        } elseif (!empty($args['date_to'])) {
            $wc_args['date_created'] = '<=' . (string) $args['date_to'];
        }
        if (!empty($args['date_after']) && !empty($args['date_before'])) {
            $wc_args['date_created'] = (string) $args['date_after'] . '...' . (string) $args['date_before'];
        }

        $orderby = (string) ($args['orderby'] ?? 'date');
        if (!in_array($orderby, ['date', 'id', 'total', 'order_id'], true)) {
            $orderby = 'date';
        }
        $wc_args['orderby'] = $orderby;
        $wc_args['order']   = (strtoupper((string) ($args['order'] ?? 'DESC'))) === 'ASC' ? 'ASC' : 'DESC';

        $orders = wc_get_orders(apply_filters('novamira_adrianv2_wc_get_orders_args', $wc_args));
        return is_array($orders) ? $orders : [];
    }

    /**
     * Fetch a single order by id.
     *
     * @param int $order_id
     * @return array|null
     */
    public static function get_order(int $order_id): ?array {
        if (!self::is_available() || $order_id <= 0) {
            return null;
        }
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            return null;
        }
        return self::extract_order_data($order);
    }

    /**
     * Fetch a coupon by id (returns a flat data array, ready for formatter).
     *
     * @param int $coupon_id
     * @return array|null
     */
    public static function get_coupon(int $coupon_id): ?array {
        if (!self::is_available() || $coupon_id <= 0) {
            return null;
        }
        $coupon = function_exists('new') ? new \WC_Coupon($coupon_id) : null;
        if (!$coupon || (method_exists($coupon, 'get_id') ? !$coupon->get_id() : true)) {
            return null;
        }
        return self::extract_coupon_data($coupon);
    }

    /**
     * @param int $customer_id
     * @return array|null
     */
    public static function get_customer(int $customer_id): ?array {
        if (!self::is_available() || $customer_id <= 0) {
            return null;
        }
        $customer = function_exists('new') ? new \WC_Customer($customer_id) : null;
        if (!$customer) {
            return null;
        }
        return self::extract_customer_data($customer);
    }

    /**
     * Adjusts stock for a product (or a specific variation). Performs a
     * quantity-relative delta, not absolute set.
     *
     * @param int  $product_id
     * @param int  $delta
     * @param string $reason
     * @return array{ ok: bool, new_quantity: int|null, error: ?string }
     */
    public static function adjust_stock(int $product_id, int $delta, string $reason = ''): array {
        if (!self::is_available()) {
            return ['ok' => false, 'new_quantity' => null, 'error' => 'wc_unavailable'];
        }
        if ($product_id <= 0) {
            return ['ok' => false, 'new_quantity' => null, 'error' => 'invalid_product_id'];
        }

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return ['ok' => false, 'new_quantity' => null, 'error' => 'product_not_found'];
        }
        $qty = (int) $product->get_stock_quantity();
        $new = $qty + $delta;

        $product->set_stock_quantity($new);
        $product->save();

        if ('' !== $reason) {
            /** Hook-driven logging — third-party plugins can react to changes */
            do_action('novamira_adrianv2_wc_stock_adjusted', $product_id, $qty, $new, $reason);
        }

        return ['ok' => true, 'new_quantity' => $new, 'error' => null];
    }

    // ─────────────────────────────────────────────────────────────────────
    // extract_*_data — internal: pull a flat array out of a WC_* object
    // via get_data(). Used by get_product/get_order/get_coupon, but also
    // exposed publicly so Ability classes can format the array they already
    // hold without refetching.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param mixed $product A WC_Product (or null).
     * @return array
     */
    public static function extract_product_data($product): array {
        if (!is_object($product) || !method_exists($product, 'get_data')) {
            return [];
        }
        $data = (array) $product->get_data();
        // Normalize ids that WC encode as WC_Product|WC_Product_Simple objects
        if (isset($data['image']) && is_object($data['image'])) {
            $data['image_id'] = (int) $data['image']->get_id();
        }
        return $data;
    }

    /**
     * @param mixed $order A WC_Order (or null).
     * @return array
     */
    public static function extract_order_data($order): array {
        if (!is_object($order) || !method_exists($order, 'get_data')) {
            return [];
        }
        $data = (array) $order->get_data();

        // OrderUtil: HPOS-aware refund loader (or fallback to legacy).
        if (self::is_hpos_enabled() && class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            $data['refunds'] = self::load_refunds($order);
        } elseif (method_exists($order, 'get_refunds')) {
            $raw = $order->get_refunds();
            $data['refunds'] = is_array($raw) ? array_map(static function($r) {
                if (!is_object($r)) {
                    return [];
                }
                return [
                    'id'     => (int) $r->get_id(),
                    'amount' => (string) $r->get_amount(),
                    'reason' => (string) $r->get_reason(),
                    'date_created' => (string) $r->get_date_created(),
                ];
            }, $raw) : [];
        }
        return $data;
    }

    /**
     * @param mixed $coupon A WC_Coupon (or null).
     * @return array
     */
    public static function extract_coupon_data($coupon): array {
        if (!is_object($coupon) || !method_exists($coupon, 'get_data')) {
            return [];
        }
        return (array) $coupon->get_data();
    }

    /**
     * @param mixed $customer
     * @return array
     */
    public static function extract_customer_data($customer): array {
        if (!is_object($customer) || !method_exists($customer, 'get_data')) {
            return [];
        }
        return (array) $customer->get_data();
    }

    /**
     * HPOS refund loader — uses WC_Data_Store for orders if available.
     *
     * @param mixed $order
     * @return array
     */
    private static function load_refunds($order): array {
        if (!method_exists($order, 'get_id')) {
            return [];
        }
        $order_id = (int) $order->get_id();
        if ($order_id <= 0) {
            return [];
        }

        // Static, well-defined WC API: wc_get_orders with type='shop_order_refund'
        // and parent set to our order id. Works in both HPOS and legacy mode.
        if (!function_exists('wc_get_orders')) {
            return [];
        }

        $refunds = wc_get_orders([
            'type'     => 'shop_order_refund',
            'parent'   => $order_id,
            'limit'    => -1,
            'paginate' => false,
        ]);

        if (!is_array($refunds)) {
            return [];
        }
        $out = [];
        foreach ($refunds as $r) {
            if (!is_object($r)) {
                continue;
            }
            $out[] = [
                'id'           => (int) $r->get_id(),
                'amount'       => (string) $r->get_amount(),
                'reason'       => (string) $r->get_reason(),
                'date_created' => (string) $r->get_date_created(),
            ];
        }
        return $out;
    }
}
