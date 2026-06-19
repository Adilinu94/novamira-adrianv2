<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * WC_Data_Formatter — WC-unabhängige Format-transformer für WooCommerce-Objekte.
 *
 * Diese Helper-Klasse formatiert WC-Datenstrukturen in saubere, JSON-serializable
 * Arrays. Sie ist bewusst WC-Klassen-unabhängig implementiert (akzeptiert nur
 * `array` als Input), damit sie ohne installiertes WC-Plugin PHP-unit-testbar
 * bleibt. WP-Funktionen (`get_permalink`, `get_post_meta` etc.) sind in Tests
 * mockbar.
 *
 * Eingabe-Daten kommen typischerweise aus:
 *   - `wc_get_products()`, `wc_get_orders()` und Konsorten
 *   - `$product->get_data()` / `$order->get_data()` (im Aufrufer konvertiert)
 *   - Helper-Wrapper `WC_HPOS_Query::get_*`
 *
 * Kein direkter Aufruf von `WC_Product`-Methoden - das macht die Klasse
 * robust gegen WC-Core-API-Änderungen zwischen Versionen.
 *
 * @package Novamira_AdrianV2
 * @since   2.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formats WC product/order/coupon/customer data arrays for the MCP layer.
 *
 * @since 2.0.0
 */
final class WC_Data_Formatter {

    /**
     * Compact product summary used by list endpoints.
     *
     * Only uppercase-key style — no nested objects, no protected meta, no image
     * sub-objects. Image IDs are kept simple so downstream MCP agents can
     * request full resolution through `list-media`.
     *
     * @param array $data Raw product data array (from WC_Product::get_data()).
     * @return array Flat summary safe for direct JSON encoding.
     */
    public static function product_summary(array $data): array {
        $id   = (int) ($data['id'] ?? 0);
        $sku  = (string) ($data['sku'] ?? '');
        $type = (string) ($data['type'] ?? 'simple');

        $image_id = 0;
        if (isset($data['image_id']) && is_int($data['image_id'])) {
            $image_id = $data['image_id'];
        } elseif (is_array($data['image'] ?? null) && isset($data['image']['id'])) {
            $image_id = (int) $data['image']['id'];
        }

        $permalink = '';
        if ($id > 0 && function_exists('get_permalink')) {
            $permalink = (string) get_permalink($id);
        }

        return [
            'id'             => $id,
            'name'           => (string) ($data['name'] ?? ''),
            'slug'           => (string) ($data['slug'] ?? ''),
            'type'           => $type,
            'status'         => (string) ($data['status'] ?? 'draft'),
            'sku'            => $sku,
            'price'          => (string) ($data['price'] ?? ''),
            'regular_price'  => (string) ($data['regular_price'] ?? ''),
            'sale_price'     => (string) ($data['sale_price'] ?? ''),
            'stock_quantity' => (int) ($data['stock_quantity'] ?? 0),
            'stock_status'   => (string) ($data['stock_status'] ?? 'instock'),
            'manage_stock'   => (bool) ($data['manage_stock'] ?? false),
            'image_id'       => $image_id,
            'permalink'      => $permalink,
            'categories'     => self::extract_term_ids($data['category_ids'] ?? null),
            'tags'           => self::extract_term_ids($data['tag_ids'] ?? null),
            'weight'         => $data['weight'] ?? '',
            'dimensions'     => self::clean_dimensions($data['dimensions'] ?? []),
        ];
    }

    /**
     * Maps a list of products (array-of-arrays) to compact summaries.
     *
     * @param array<int, array> $products Array of product data arrays.
     * @return array<int, array> Array of formatted summaries.
     */
    public static function products_summary(array $products): array {
        $out = [];
        foreach ($products as $product_data) {
            if (!is_array($product_data)) {
                continue;
            }
            $out[] = self::product_summary($product_data);
        }
        return $out;
    }

    /**
     * Order summary suitable for list responses; line items are kept brief.
     *
     * For full detail use `order_detail()`.
     *
     * @param array $data Raw order data (from WC_Order::get_data()).
     * @return array
     */
    public static function order_summary(array $data): array {
        $id = (int) ($data['id'] ?? 0);
        return [
            'id'             => $id,
            'number'         => (string) ($data['number'] ?? ($data['id'] ?? '')),
            'status'         => (string) ($data['status'] ?? 'pending'),
            'total'          => (string) ($data['total'] ?? '0'),
            'currency'       => (string) ($data['currency'] ?? ''),
            'customer_id'    => (int) ($data['customer_id'] ?? 0),
            'billing_email'  => (string) ($data['billing']['email'] ?? ''),
            'items_count'    => is_array($data['line_items'] ?? null) ? count($data['line_items']) : 0,
            'date_created'   => (string) ($data['date_created'] ?? ''),
            'date_modified'  => (string) ($data['date_modified'] ?? ''),
        ];
    }

    /**
     * Full order detail including line items, refunds, and notes.
     *
     * Refund notes are intentionally included so AI agents can spot disputes.
     *
     * @param array $data       Raw WC_Order::get_data() output.
     * @param bool  $line_items Include line_items array.
     * @param bool  $addresses  Include billing/shipping address objects.
     * @return array
     */
    public static function order_detail(array $data, bool $line_items = true, bool $addresses = true): array {
        $summary = self::order_summary($data);
        if ($line_items) {
            $summary['line_items'] = self::summarize_line_items($data['line_items'] ?? []);
        }
        if ($addresses) {
            $summary['billing']  = self::clean_address($data['billing'] ?? []);
            $summary['shipping'] = self::clean_address($data['shipping'] ?? []);
        }
        $summary['refunds']      = self::summarize_refunds($data['refunds'] ?? []);
        $summary['payment_method']     = (string) ($data['payment_method'] ?? '');
        $summary['payment_method_title'] = (string) ($data['payment_method_title'] ?? '');
        $summary['customer_note'] = (string) ($data['customer_note'] ?? '');
        return $summary;
    }

    /**
     * Coupon summary.
     *
     * @param array $data Raw WC_Coupon::get_data().
     * @return array
     */
    public static function coupon_summary(array $data): array {
        return [
            'id'                  => (int) ($data['id'] ?? 0),
            'code'                => (string) ($data['code'] ?? ''),
            'discount_type'       => (string) ($data['discount_type'] ?? 'fixed_cart'),
            'amount'              => (string) ($data['amount'] ?? '0'),
            'usage_limit'         => (int) ($data['usage_limit'] ?? 0),
            'usage_limit_per_user' => (int) ($data['usage_limit_per_user'] ?? 0),
            'usage_count'         => (int) ($data['usage_count'] ?? 0),
            'date_expires'        => (string) ($data['date_expires'] ?? ''),
            'minimum_amount'      => (string) ($data['minimum_amount'] ?? ''),
            'product_ids'         => self::extract_int_list($data['product_ids'] ?? []),
            'excluded_product_ids' => self::extract_int_list($data['excluded_product_ids'] ?? []),
            'free_shipping'       => (bool) ($data['free_shipping'] ?? false),
        ];
    }

    /**
     * Compact customer summary - explicitly NOT including email/name in default
     * summary, because the ability layer enforces PII access control upstream.
     *
     * @param array $data Raw WC_Customer::get_data().
     * @return array
     */
    public static function customer_summary(array $data): array {
        return [
            'id'           => (int) ($data['id'] ?? 0),
            'role'         => (string) ($data['role'] ?? 'customer'),
            'username'     => (string) ($data['username'] ?? ''),
            'orders_count' => (int) ($data['orders_count'] ?? 0),
            'total_spent'  => (string) ($data['total_spent'] ?? '0'),
            'date_created' => (string) ($data['date_created'] ?? ''),
        ];
    }

    /**
     * Sales-report row summarizer - per-product/per-period aggregates.
     *
     * @param array $row One row from WC_Reports (product_id, product_name, qty, total).
     * @return array
     */
    public static function sales_row(array $row): array {
        return [
            'product_id'   => (int) ($row['product_id'] ?? 0),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'orders_count' => (int) ($row['orders_count'] ?? 0),
            'quantity'     => (int) ($row['quantity'] ?? 0),
            'total'        => (string) ($row['total'] ?? '0'),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal helpers - WC-independent, fully unit-testable.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Coerces a list of term IDs (which can be either ints, or
     * already-array refs from WC_Data) into a flat int list.
     *
     * @param mixed $value Raw `category_ids`/`tag_ids` value.
     * @return int[]
     */
    private static function extract_term_ids($value): array {
        if (!is_array($value)) {
            return [];
        }
        $ids = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $ids[] = $item;
            } elseif (is_array($item) && isset($item['term_id'])) {
                $ids[] = (int) $item['term_id'];
            } elseif (is_numeric($item)) {
                $ids[] = (int) $item;
            }
        }
        return $ids;
    }

    /**
     * Strips WC's heavy product dimensions object down to a flat map.
     *
     * @param mixed $value Raw dimensions value (array, string, or absend).
     * @return array
     */
    private static function clean_dimensions($value): array {
        if (!is_array($value)) {
            return ['length' => '', 'width' => '', 'height' => ''];
        }
        return [
            'length' => (string) ($value['length'] ?? ''),
            'width'  => (string) ($value['width'] ?? ''),
            'height' => (string) ($value['height'] ?? ''),
        ];
    }

    /**
     * Address object normalizer - strips any nested objects.
     *
     * @param mixed $value Raw billing/shipping array.
     * @return array
     */
    private static function clean_address($value): array {
        if (!is_array($value)) {
            return [];
        }
        $keys = [
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'email', 'phone',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (string) ($value[$key] ?? '');
        }
        return $out;
    }

    /**
     * Maps raw WC line_items down to: { id, name, qty, subtotal, total }.
     *
     * @param array<int, mixed> $items Raw line_items.
     * @return array
     */
    private static function summarize_line_items(array $items): array {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $product_id = (int) ($item['product_id'] ?? 0);
            $name       = (string) ($item['name'] ?? '');
            if ('' === $name && $product_id > 0 && function_exists('get_the_title')) {
                $name = (string) get_the_title($product_id);
            }
            $out[] = [
                'id'         => (int) ($item['id'] ?? 0),
                'product_id' => $product_id,
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'name'       => $name,
                'quantity'   => (int) ($item['quantity'] ?? 0),
                'subtotal'   => (string) ($item['subtotal'] ?? '0'),
                'total'      => (string) ($item['total'] ?? '0'),
            ];
        }
        return $out;
    }

    /**
     * Refund objects (from WC_Order::get_refunds()) flattened.
     *
     * @param array<int, mixed> $refunds
     * @return array
     */
    private static function summarize_refunds(array $refunds): array {
        $out = [];
        foreach ($refunds as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = [
                'id'     => (int) ($r['id'] ?? 0),
                'amount' => (string) ($r['amount'] ?? '0'),
                'reason' => (string) ($r['reason'] ?? ''),
                'date'   => (string) ($r['date_created'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Forces an array-of-ints to a clean int[] array.
     *
     * @param array<int, mixed> $list
     * @return int[]
     */
    private static function extract_int_list(array $list): array {
        $out = [];
        foreach ($list as $k) {
            $out[] = (int) $k;
        }
        return $out;
    }
}
