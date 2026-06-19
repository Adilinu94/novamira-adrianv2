<?php
/**
 * Test: Elementor_Version_Resolver — V3/V4 Detection (12 cases).
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;
use PHPUnit\Framework\TestCase;

#[CoversClass(Elementor_Version_Resolver::class)]
class ElementorVersionResolverTest extends TestCase
{
    private const POST_V4 = 42;
    private const POST_V3 = 43;
    private const POST_NON_ELEMENTOR = 99;

    protected function setUp(): void
    {
        // Reset all global test state.
        $GLOBALS['_wpcode_meta']      = [];
        $GLOBALS['_test_wp_cache']    = [];
        $GLOBALS['_test_posts']       = [];

        // --- Seed a V4 page (post 42): _elementor_edit_mode='builder' + data with e-flexbox ---
        $GLOBALS['_wpcode_meta'][self::POST_V4]['_elementor_edit_mode'] = 'builder';
        $GLOBALS['_wpcode_meta'][self::POST_V4]['_elementor_data'] = json_encode([
            ['id' => 'root', 'elType' => 'e-flexbox', 'elements' => [
                ['id' => 'child', 'elType' => 'e-heading'],
            ]],
        ]);

        // --- Seed a V3 page (post 43): _elementor_edit_mode='builder' + V3-only data ---
        $GLOBALS['_wpcode_meta'][self::POST_V3]['_elementor_edit_mode'] = 'builder';
        $GLOBALS['_wpcode_meta'][self::POST_V3]['_elementor_data'] = json_encode([
            ['id' => 'section', 'elType' => 'section', 'elements' => [
                ['id' => 'column', 'elType' => 'column', 'elements' => [
                    ['id' => 'widget', 'elType' => 'widget', 'widgetType' => 'heading'],
                ]],
            ]],
        ]);

        // --- Seed a non-Elementor page (post 99) ---
        $GLOBALS['_wpcode_meta'][self::POST_NON_ELEMENTOR]['_elementor_edit_mode'] = '';
        $GLOBALS['_wpcode_meta'][self::POST_NON_ELEMENTOR]['_elementor_data'] = '';
    }

    // ── resolve() ────────────────────────────────────────────────────────────

    public function test_resolve_explicit_v3_returns_v3(): void
    {
        $result = Elementor_Version_Resolver::resolve(self::POST_V4, 'v3');
        $this->assertSame('v3', $result,
            'resolve() with explicit "v3" must return "v3" regardless of page data');
    }

    public function test_resolve_explicit_v4_returns_v4(): void
    {
        $result = Elementor_Version_Resolver::resolve(self::POST_V3, 'v4');
        $this->assertSame('v4', $result,
            'resolve() with explicit "v4" must return "v4" regardless of page data');
    }

    public function test_resolve_auto_detects_v4_from_atomic_data(): void
    {
        $result = Elementor_Version_Resolver::resolve(self::POST_V4);
        $this->assertSame('v4', $result,
            'resolve(auto) must detect V4 when _elementor_data contains e-flexbox');
    }

    public function test_resolve_auto_detects_v3_from_v3_data(): void
    {
        $result = Elementor_Version_Resolver::resolve(self::POST_V3);
        $this->assertSame('v3', $result,
            'resolve(auto) must detect V3 when _elementor_data has no V4 atomic containers');
    }

    public function test_resolve_auto_falls_back_to_v3_for_non_elementor_page(): void
    {
        $result = Elementor_Version_Resolver::resolve(self::POST_NON_ELEMENTOR);
        $this->assertSame('v3', $result,
            'resolve(auto) must fall back to V3 for non-Elementor pages');
    }

    // ── site_is_v4() ─────────────────────────────────────────────────────────

    public function test_site_is_v4_false_in_mock_mode(): void
    {
        // In mock mode: ELEMENTOR_VERSION is NOT defined,
        // Global_Classes_Repository class does NOT exist.
        $this->assertFalse(Elementor_Version_Resolver::site_is_v4(),
            'site_is_v4() must return false when neither ELEMENTOR_VERSION >= 4.0 nor Global_Classes_Repository exists');
    }

    // ── page_is_v4() ─────────────────────────────────────────────────────────

    public function test_page_is_v4_true_for_atomic_container(): void
    {
        $this->assertTrue(Elementor_Version_Resolver::page_is_v4(self::POST_V4),
            'page_is_v4() must return true when _elementor_data contains e-flexbox');
    }

    public function test_page_is_v4_false_for_v3_data(): void
    {
        $this->assertFalse(Elementor_Version_Resolver::page_is_v4(self::POST_V3),
            'page_is_v4() must return false for V3-only page data');
    }

    // ── detect_page_version() ────────────────────────────────────────────────

    public function test_detect_page_version_unknown_for_non_elementor_post(): void
    {
        $result = Elementor_Version_Resolver::detect_page_version(self::POST_NON_ELEMENTOR);
        $this->assertSame('unknown', $result,
            'detect_page_version() must return "unknown" for posts without _elementor_edit_mode=builder');
    }

    public function test_detect_page_version_caches_result(): void
    {
        // First call populates cache.
        $first = Elementor_Version_Resolver::detect_page_version(self::POST_V4);
        $this->assertSame('v4', $first);

        // Verify the cache key was stored.
        $cached = wp_cache_get('novamira_resolver_v4_' . self::POST_V4, 'novamira');
        $this->assertSame('v4', $cached,
            'detect_page_version() must cache the result via wp_cache_set()');

        // Second call should return from cache (same result).
        $second = Elementor_Version_Resolver::detect_page_version(self::POST_V4);
        $this->assertSame('v4', $second);
    }

    // ── bust_cache() ─────────────────────────────────────────────────────────

    public function test_bust_cache_clears_cached_detection(): void
    {
        // Prime the cache.
        Elementor_Version_Resolver::detect_page_version(self::POST_V4);
        $cached = wp_cache_get('novamira_resolver_v4_' . self::POST_V4, 'novamira');
        $this->assertSame('v4', $cached, 'Cache must be populated before busting');

        // Bust it.
        Elementor_Version_Resolver::bust_cache(self::POST_V4);

        $after = wp_cache_get('novamira_resolver_v4_' . self::POST_V4, 'novamira', false, $found);
        $this->assertFalse($found,
            'bust_cache() must remove the cached detection for the specified post');
    }

    // ── atomic_capabilities() ────────────────────────────────────────────────

    public function test_atomic_capabilities_returns_expected_structure(): void
    {
        $caps = Elementor_Version_Resolver::atomic_capabilities();

        $this->assertIsArray($caps,
            'atomic_capabilities() must return an array');
        $this->assertArrayHasKey('elementor_version', $caps);
        $this->assertArrayHasKey('atomic_supported', $caps);
        $this->assertArrayHasKey('global_classes_available', $caps);
        $this->assertArrayHasKey('elementor_active', $caps);

        $this->assertIsString($caps['elementor_version']);
        $this->assertIsBool($caps['atomic_supported']);
        $this->assertIsBool($caps['global_classes_available']);
        $this->assertIsBool($caps['elementor_active']);

        // In mock mode: Elementor\Plugin exists, so elementor_active is true.
        $this->assertTrue($caps['elementor_active'],
            'elementor_active must be true when \Elementor\Plugin class exists');
        // ELEMENTOR_VERSION is not defined → 'unknown'.
        $this->assertSame('unknown', $caps['elementor_version']);
    }
}
