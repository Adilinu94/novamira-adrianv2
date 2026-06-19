<?php
/**
 * WC_Data_Formatter_Test — Unit tests for the pure formatting helper.
 *
 * Tests intentionally use plain arrays (no WC instance) because the formatter
 * is designed to be WC-independent. This keeps the suite runnable in the
 * stdlib-only mock mode of tests/bootstrap.php.
 *
 * @covers \Novamira\AdrianV2\Helpers\WC_Data_Formatter
 */

declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\WC_Data_Formatter;
use PHPUnit\Framework\TestCase;

#[CoversClass(WC_Data_Formatter::class)]
final class WC_Data_Formatter_Test extends TestCase {

    public function test_product_summary_returns_flat_array(): void {
        $data = [
            'id'                 => 42,
            'name'               => 'Test Product',
            'slug'               => 'test-product',
            'type'               => 'simple',
            'status'             => 'publish',
            'sku'                => 'TP-01',
            'price'              => '19.99',
            'regular_price'      => '24.99',
            'sale_price'         => '19.99',
            'stock_quantity'     => 7,
            'stock_status'       => 'instock',
            'manage_stock'       => true,
            'category_ids'       => [1, 2, 3],
            'tag_ids'            => [['term_id' => 10], '20'],
            'weight'             => '1.5',
            'dimensions'         => ['length' => '10', 'width' => '20', 'height' => '30'],
            'image'              => ['id' => 99, 'url' => 'https://example.com/p.jpg'],
        ];
        $out = WC_Data_Formatter::product_summary($data);
        $this->assertSame(42, $out['id']);
        $this->assertSame('Test Product', $out['name']);
        $this->assertSame('TP-01', $out['sku']);
        $this->assertSame(99, $out['image_id']);
        $this->assertSame([1, 2, 3], $out['categories']);
        $this->assertSame([10, 20], $out['tags']);
        $this->assertSame('simple', $out['type']);
    }

    public function test_product_summary_handles_missing_keys_gracefully(): void {
        $out = WC_Data_Formatter::product_summary(['id' => 1, 'name' => 'X']);
        $this->assertSame(1, $out['id']);
        $this->assertSame('X', $out['name']);
        $this->assertSame('simple', $out['type']);
        $this->assertSame('draft', $out['status']);
        $this->assertSame('instock', $out['stock_status']);
        $this->assertSame(0, $out['stock_quantity']);
        $this->assertSame([], $out['categories']);
        $this->assertSame([], $out['tags']);
        $this->assertSame(['length' => '', 'width' => '', 'height' => ''], $out['dimensions']);
    }

    public function test_product_summary_image_three_forms(): void {
        // Form 1: image_id key directly.
        $out1 = WC_Data_Formatter::product_summary(['id' => 1, 'image_id' => 5]);
        $this->assertSame(5, $out1['image_id']);

        // Form 2: nested image array with id.
        $out2 = WC_Data_Formatter::product_summary(['id' => 1, 'image' => ['id' => 7]]);
        $this->assertSame(7, $out2['image_id']);

        // Form 3: nothing.
        $out3 = WC_Data_Formatter::product_summary(['id' => 1]);
        $this->assertSame(0, $out3['image_id']);
    }

    public function test_products_summary_drops_non_array_entries(): void {
        $out = WC_Data_Formatter::products_summary(['a string', ['id' => 1], null, ['id' => 2]]);
        $this->assertCount(2, $out);
        $this->assertSame(1, $out[0]['id']);
        $this->assertSame(2, $out[1]['id']);
    }

    public function test_order_summary_returns_flat_array(): void {
        $data = [
            'id' => 99,
            'status' => 'processing',
            'total' => '123.45',
            'currency' => 'EUR',
            'customer_id' => 7,
            'billing' => ['email' => 'a@b.c'],
            'line_items' => [
                ['id' => 1, 'product_id' => 101, 'quantity' => 2, 'subtotal' => '10', 'total' => '20'],
                ['id' => 2, 'product_id' => 102, 'quantity' => 1, 'subtotal' => '5', 'total' => '5'],
            ],
            'date_created' => '2026-06-17T10:00:00',
        ];
        $out = WC_Data_Formatter::order_summary($data);
        $this->assertSame(99, $out['id']);
        $this->assertSame('processing', $out['status']);
        $this->assertSame('123.45', $out['total']);
        $this->assertSame(2, $out['items_count']);
        $this->assertSame(7, $out['customer_id']);
        $this->assertSame('a@b.c', $out['billing_email']);
    }

    public function test_order_detail_includes_addresses_and_line_items(): void {
        $data = [
            'id' => 99,
            'status' => 'processing',
            'total' => '100',
            'billing' => ['email' => 'a@b.c', 'first_name' => 'Ada'],
            'shipping' => ['first_name' => 'Ada', 'address_1' => 'X'],
            'line_items' => [['id' => 1, 'product_id' => 5, 'name' => 'Y', 'quantity' => 1]],
            'payment_method' => 'stripe',
            'refunds' => [['id' => 11, 'amount' => '25', 'reason' => 'damaged']],
        ];
        $out = WC_Data_Formatter::order_detail($data, true, true);
        $this->assertCount(1, $out['line_items']);
        $this->assertSame('stripe', $out['payment_method']);
        $this->assertSame('a@b.c', $out['billing']['email']);
        $this->assertSame('X', $out['shipping']['address_1']);
        $this->assertCount(1, $out['refunds']);
    }

    public function test_order_detail_suppresses_address_and_line_items(): void {
        $data = [
            'id' => 1,
            'billing' => ['email' => 'a@b.c'],
            'shipping' => ['address_1' => 'X'],
            'line_items' => [['id' => 1, 'product_id' => 5]],
        ];
        $out = WC_Data_Formatter::order_detail($data, false, false);
        $this->assertArrayNotHasKey('line_items', $out);
        $this->assertArrayNotHasKey('billing', $out);
        $this->assertArrayNotHasKey('shipping', $out);
    }

    public function test_coupon_summary(): void {
        $data = [
            'id' => 7,
            'code' => 'SUMMER10',
            'discount_type' => 'percent',
            'amount' => '10',
            'usage_limit' => 100,
            'usage_limit_per_user' => 1,
            'usage_count' => 12,
            'date_expires' => '2026-12-31',
            'minimum_amount' => '50',
            'product_ids' => [1, 2, 3],
            'excluded_product_ids' => ['4', '5'],
            'free_shipping' => true,
        ];
        $out = WC_Data_Formatter::coupon_summary($data);
        $this->assertSame(7, $out['id']);
        $this->assertSame('SUMMER10', $out['code']);
        $this->assertSame('percent', $out['discount_type']);
        $this->assertSame([1, 2, 3], $out['product_ids']);
        $this->assertSame([4, 5], $out['excluded_product_ids']);
        $this->assertTrue($out['free_shipping']);
    }

    public function test_customer_summary_omits_email_by_default(): void {
        $data = [
            'id' => 3,
            'role' => 'customer',
            'username' => 'jane',
            'orders_count' => 4,
            'total_spent' => '500',
            'email' => 'jane@example.com',  // intentionally included in raw to verify NOT in summary
            'date_created' => '2025-01-01',
        ];
        $out = WC_Data_Formatter::customer_summary($data);
        $this->assertSame(3, $out['id']);
        $this->assertSame('customer', $out['role']);
        $this->assertSame('jane', $out['username']);
        $this->assertSame(4, $out['orders_count']);
        $this->assertArrayNotHasKey('email', $out, 'customer_summary must not leak email PII');
        $this->assertArrayNotHasKey('first_name', $out);
        $this->assertArrayNotHasKey('billing', $out);
    }

    public function test_sales_row(): void {
        $row = WC_Data_Formatter::sales_row([
            'product_id' => 10,
            'product_name' => 'Widget',
            'orders_count' => 4,
            'quantity' => 12,
            'total' => '240',
        ]);
        $this->assertSame(10, $row['product_id']);
        $this->assertSame(12, $row['quantity']);
        $this->assertSame('240', $row['total']);
    }

    public function test_address_fields_are_stringified(): void {
        $out = WC_Data_Formatter::product_summary([]);
        $this->assertSame('', $out['dimensions']['length']);
        $this->assertSame('', $out['dimensions']['width']);
        $this->assertSame('', $out['dimensions']['height']);
    }
}
