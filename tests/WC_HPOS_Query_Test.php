<?php
/**
 * WC_HPOS_Query_Test — Unit tests for HPOS-aware WC query wrapper.
 *
 * @covers \Novamira\AdrianV2\Helpers\WC_HPOS_Query
 */

declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\WC_HPOS_Query;
use PHPUnit\Framework\TestCase;

#[CoversClass(WC_HPOS_Query::class)]
final class WC_HPOS_Query_Test extends TestCase {

    protected function setUp(): void {
        // Mock wc_get_products / wc_get_orders to use a fixture array
        // declared via $GLOBALS['_test_wc_products'] / ['_test_wc_orders'].
        $GLOBALS['_test_wc_products'] = [
            ['id' => 1, 'name' => 'A', 'price' => '10', 'stock_quantity' => 4, 'type' => 'simple', 'status' => 'publish'],
            ['id' => 2, 'name' => 'B', 'price' => '20', 'stock_quantity' => 0, 'type' => 'simple', 'status' => 'publish'],
            ['id' => 3, 'name' => 'C', 'price' => '5',  'stock_quantity' => 12, 'type' => 'variable', 'status' => 'draft'],
        ];
        $GLOBALS['_test_wc_orders'] = [
            ['id' => 100, 'status' => 'completed', 'total' => '50', 'currency' => 'EUR', 'billing' => ['email' => 'x@y.z']],
            ['id' => 101, 'status' => 'processing', 'total' => '20', 'currency' => 'EUR', 'billing' => ['email' => 'a@b.c']],
        ];
        $GLOBALS['_test_wc_calls'] = [];
    }

    public function test_is_available_returns_boolean(): void {
        // Mock mode declares WC_VERSION < 8, so this is false.
        $this->assertIsBool(WC_HPOS_Query::is_available());
    }

    public function test_get_products_returns_array_when_mocked(): void {
        $out = WC_HPOS_Query::get_products(['limit' => 10]);
        $this->assertIsArray($out);
    }

    public function test_get_orders_returns_array_when_mocked(): void {
        $out = WC_HPOS_Query::get_orders(['limit' => 10]);
        $this->assertIsArray($out);
    }

    public function test_get_product_with_zero_id_returns_null(): void {
        $this->assertNull(WC_HPOS_Query::get_product(0));
    }

    public function test_get_order_with_zero_id_returns_null(): void {
        $this->assertNull(WC_HPOS_Query::get_order(0));
    }

    public function test_get_product_with_invalid_id_returns_null(): void {
        $this->assertNull(WC_HPOS_Query::get_product(99999));
    }

    public function test_get_coupon_with_zero_id_returns_null(): void {
        $this->assertNull(WC_HPOS_Query::get_coupon(0));
    }

    public function test_get_customer_with_zero_id_returns_null(): void {
        $this->assertNull(WC_HPOS_Query::get_customer(0));
    }

    public function test_adjust_stock_returns_invalid_product_id_on_zero(): void {
        $result = WC_HPOS_Query::adjust_stock(0, 5);
        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_product_id', $result['error']);
        $this->assertNull($result['new_quantity']);
    }

    public function test_adjust_stock_returns_wc_unavailable_error(): void {
        // Mock mode has WC_VERSION < 8
        $result = WC_HPOS_Query::adjust_stock(1, 3);
        $this->assertFalse($result['ok']);
        $this->assertSame('wc_unavailable', $result['error']);
    }

    public function test_extract_product_data_handles_non_object(): void {
        $this->assertSame([], WC_HPOS_Query::extract_product_data(null));
        $this->assertSame([], WC_HPOS_Query::extract_product_data('not an object'));
        $this->assertSame([], WC_HPOS_Query::extract_product_data(new \stdClass()));
    }

    public function test_extract_order_data_handles_non_object(): void {
        $this->assertSame([], WC_HPOS_Query::extract_order_data(null));
        $this->assertSame([], WC_HPOS_Query::extract_order_data([]));
    }

    public function test_extract_order_data_calls_get_data_on_object(): void {
        $order = new class {
            public function get_data(): array { return ['id' => 99, 'status' => 'processing']; }
        };
        $result = WC_HPOS_Query::extract_order_data($order);
        $this->assertSame(99, $result['id']);
        $this->assertSame('processing', $result['status']);
    }

    public function test_extract_coupon_data_handles_non_object(): void {
        $this->assertSame([], WC_HPOS_Query::extract_coupon_data(null));
    }

    public function test_extract_customer_data_handles_non_object(): void {
        $this->assertSame([], WC_HPOS_Query::extract_customer_data(null));
    }

    public function test_is_hpos_returns_false_in_mock_mode(): void {
        // Without OrderUtil class is loaded and WC_VERSION is < 8.0
        $this->assertFalse(WC_HPOS_Query::is_hpos_enabled());
    }
}
