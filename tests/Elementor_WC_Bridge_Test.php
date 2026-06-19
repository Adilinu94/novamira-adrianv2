<?php
/**
 * Elementor_WC_Bridge_Test — V3/V4 detection + WC integration tests.
 *
 * @covers \Novamira\AdrianV2\Helpers\Elementor_WC_Bridge
 */

declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\Elementor_WC_Bridge;
use PHPUnit\Framework\TestCase;

#[CoversClass(Elementor_WC_Bridge::class)]
final class Elementor_WC_Bridge_Test extends TestCase {

    protected function setUp(): void {
        // Test post-fixtures: post_id → elementor_edit_mode + elementor_version + elementor_data
        $GLOBALS['_test_posts'][10] = [
            'ID' => 10, 'post_title' => 'V3 Page',
            'post_type' => 'page', 'post_status' => 'publish',
        ];
        $GLOBALS['_test_post_meta'][10] = [
            '_elementor_edit_mode' => ['builder'],
            '_elementor_version'   => ['3.18.0'],
            '_elementor_data'      => [json_encode([
                ['id' => 'a1', 'elType' => 'section', 'elements' => [
                    ['id' => 'b1', 'elType' => 'column', 'elements' => []],
                ]],
            ])],
        ];
        $GLOBALS['_test_post_meta'][11] = [
            '_elementor_edit_mode' => ['builder'],
            '_elementor_version'   => ['4.0.0'],
            '_elementor_data'      => [json_encode([
                ['id' => 'a2', 'elType' => 'e-flexbox', 'elements' => []],
            ])],
        ];
        $GLOBALS['_test_post_meta'][12] = [
            '_elementor_edit_mode' => ['builder'],
            '_elementor_data'      => [json_encode([
                ['id' => 'a3', 'elType' => 'section'],
                ['id' => 'a4', 'elType' => 'e-div-block'],
            ])],
        ];
        $GLOBALS['_test_post_meta'][99] = [
            '_elementor_edit_mode' => [''], // not elementor
        ];
    }

    public function test_is_elementor_page_finds_builder(): void {
        $this->assertTrue(Elementor_WC_Bridge::is_elementor_page(10));
        $this->assertTrue(Elementor_WC_Bridge::is_elementor_page(12));
        $this->assertFalse(Elementor_WC_Bridge::is_elementor_page(99));
    }

    public function test_is_elementor_page_returns_false_for_invalid_id(): void {
        $this->assertFalse(Elementor_WC_Bridge::is_elementor_page(0));
        $this->assertFalse(Elementor_WC_Bridge::is_elementor_page(-5));
    }

    public function test_detect_v3_via_version_meta(): void {
        $this->assertSame('v3', Elementor_WC_Bridge::detect_page_version(10));
    }

    public function test_detect_v4_via_version_meta(): void {
        $this->assertSame('v4', Elementor_WC_Bridge::detect_page_version(11));
    }

    public function test_detect_v4_via_atomic_eltype(): void {
        // post 12 has no _elementor_version meta but has e-div-block in data
        $this->assertSame('v4', Elementor_WC_Bridge::detect_page_version(12));
    }

    public function test_detect_unknown_for_non_elementor(): void {
        $this->assertSame('unknown', Elementor_WC_Bridge::detect_page_version(99));
    }

    public function test_resolve_version_honors_explicit_v3(): void {
        $this->assertSame('v3', Elementor_WC_Bridge::resolve_version(11, 'v3'));
    }

    public function test_resolve_version_honors_explicit_v4(): void {
        $this->assertSame('v4', Elementor_WC_Bridge::resolve_version(10, 'v4'));
    }

    public function test_resolve_version_auto_detects_v3(): void {
        $this->assertSame('v3', Elementor_WC_Bridge::resolve_version(10, 'auto'));
    }

    public function test_resolve_version_auto_detects_v4(): void {
        $this->assertSame('v4', Elementor_WC_Bridge::resolve_version(11, 'auto'));
    }

    public function test_resolve_version_falls_back_to_v3_for_unknown(): void {
        $this->assertSame('v3', Elementor_WC_Bridge::resolve_version(99, 'auto'));
    }

    public function test_set_product_template_v3_writes_meta(): void {
        $r = Elementor_WC_Bridge::set_product_template(20, 50, 'v3');
        $this->assertTrue($r['ok']);
        $this->assertSame('v3', $r['version']);
        $this->assertArrayHasKey('product_post_meta', $r['post_meta']);
    }

    public function test_set_product_template_v4_writes_meta(): void {
        $r = Elementor_WC_Bridge::set_product_template(20, 50, 'v4');
        $this->assertTrue($r['ok']);
        $this->assertSame('v4', $r['version']);
    }

    public function test_set_product_template_rejects_zero_ids(): void {
        $r1 = Elementor_WC_Bridge::set_product_template(0, 50);
        $r2 = Elementor_WC_Bridge::set_product_template(20, 0);
        $this->assertFalse($r1['ok']);
        $this->assertFalse($r2['ok']);
        $this->assertSame('invalid_ids', $r1['error']);
        $this->assertSame('invalid_ids', $r2['error']);
    }

    public function test_inject_product_card_v3_returns_three_widgets(): void {
        $r = Elementor_WC_Bridge::inject_product_card(10, 5, [], 'v3');
        $this->assertSame('v3', $r['version']);
        $this->assertSame('container', $r['el_type']);
        $this->assertCount(3, $r['inner']);
    }

    public function test_inject_product_card_v4_uses_atomic_widgets(): void {
        $r = Elementor_WC_Bridge::inject_product_card(11, 5, [], 'v4');
        $this->assertSame('v4', $r['version']);
        $this->assertSame('e-div-block', $r['el_type']);
        $this->assertSame('e-image', $r['inner'][0]['widgetType']);
        $this->assertSame('e-heading', $r['inner'][1]['widgetType']);
        $this->assertSame('e-button', $r['inner'][2]['widgetType']);
    }

    public function test_inject_product_card_rejects_invalid_ids(): void {
        $r1 = Elementor_WC_Bridge::inject_product_card(0, 5);
        $r2 = Elementor_WC_Bridge::inject_product_card(10, 0);
        $this->assertFalse((bool) $r1['element_id']);
        $this->assertFalse((bool) $r2['element_id']);
        $this->assertSame('invalid_ids', $r1['error']);
    }
}
