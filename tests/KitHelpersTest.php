<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Menu_Builder;
use Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Plugin_Installer;
use Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Rollback;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for pure-PHP helper methods that have no WP database dependency.
 *
 * Covers:
 *   - Kit_Menu_Builder::resolve_target()
 *   - Kit_Plugin_Installer::find_plugin_file()
 *   - Kit_Rollback ring-buffer logic (find_snapshot, delete_snapshot, MAX_SNAPSHOTS cap)
 *
 * @covers \Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Menu_Builder
 * @covers \Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Plugin_Installer
 * @covers \Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Rollback
 */
final class KitHelpersTest extends TestCase
{
    // =========================================================================
    // Kit_Menu_Builder::resolve_target()
    // =========================================================================

    /** @test */
    public function test_resolve_target_returns_default_for_empty_string(): void
    {
        $result = Kit_Menu_Builder::resolve_target('', []);

        $this->assertSame('#', $result['url']);
        $this->assertSame('custom', $result['type']);
        $this->assertSame('custom', $result['object']);
        $this->assertSame(0, $result['object_id']);
    }

    /** @test */
    public function test_resolve_target_returns_default_for_no_colon_and_not_home(): void
    {
        $result = Kit_Menu_Builder::resolve_target('some-page-slug', []);

        $this->assertSame('#', $result['url']);
        $this->assertSame('custom', $result['type']);
        $this->assertSame(0, $result['object_id']);
    }

    /** @test */
    public function test_resolve_target_url_prefix_sets_url_directly(): void
    {
        $result = Kit_Menu_Builder::resolve_target('url:https://example.com/page', []);

        $this->assertSame('https://example.com/page', $result['url']);
        $this->assertSame('custom', $result['type']);
        $this->assertSame(0, $result['object_id']);
    }

    /** @test */
    public function test_resolve_target_url_hash_is_default_custom_link(): void
    {
        $result = Kit_Menu_Builder::resolve_target('url:#', []);

        $this->assertSame('#', $result['url']);
        $this->assertSame('custom', $result['type']);
    }

    /** @test */
    public function test_resolve_target_url_empty_value(): void
    {
        $result = Kit_Menu_Builder::resolve_target('url:', []);

        $this->assertSame('', $result['url']);
        $this->assertSame('custom', $result['type']);
    }

    /** @test */
    public function test_resolve_target_home_uses_home_url(): void
    {
        $result = Kit_Menu_Builder::resolve_target('home', []);

        // home_url('/') is stubbed to return 'https://example.com/'
        $this->assertSame('https://example.com/', $result['url']);
        $this->assertSame('custom', $result['type']); // home does not change type
    }

    /** @test */
    public function test_resolve_target_page_resolves_from_id_map(): void
    {
        $id_map = ['homepage' => 42, 'about' => 99];

        $result = Kit_Menu_Builder::resolve_target('page:homepage', $id_map);

        // get_permalink(42) is stubbed to 'https://example.com/?p=42'
        $this->assertSame('https://example.com/?p=42', $result['url']);
        $this->assertSame('post_type', $result['type']);
        $this->assertSame('page', $result['object']);
        $this->assertSame(42, $result['object_id']);
    }

    /** @test */
    public function test_resolve_target_page_second_entry_from_id_map(): void
    {
        $id_map = ['homepage' => 42, 'about' => 99];

        $result = Kit_Menu_Builder::resolve_target('page:about', $id_map);

        $this->assertSame('https://example.com/?p=99', $result['url']);
        $this->assertSame(99, $result['object_id']);
    }

    /** @test */
    public function test_resolve_target_unknown_prefix_returns_default(): void
    {
        $result = Kit_Menu_Builder::resolve_target('foobar:some-value', []);

        $this->assertSame('#', $result['url']);
        $this->assertSame('custom', $result['type']);
        $this->assertSame(0, $result['object_id']);
    }

    /** @test */
    public function test_resolve_target_category_prefix_returns_default_when_no_term(): void
    {
        // get_term_by is stubbed to return false (no terms in test environment).
        $result = Kit_Menu_Builder::resolve_target('category:news', []);

        $this->assertSame('#', $result['url']);
        $this->assertSame('custom', $result['type']);
        $this->assertSame(0, $result['object_id']);
    }

    /** @test */
    public function test_resolve_target_result_always_has_required_keys(): void
    {
        $cases = [
            '',
            'home',
            'url:#',
            'url:https://example.com',
            'page:ref',
            'category:slug',
            'unknown:value',
        ];

        foreach ($cases as $target) {
            $result = Kit_Menu_Builder::resolve_target($target, []);
            $this->assertArrayHasKey('url', $result, "Missing 'url' for target: $target");
            $this->assertArrayHasKey('type', $result, "Missing 'type' for target: $target");
            $this->assertArrayHasKey('object', $result, "Missing 'object' for target: $target");
            $this->assertArrayHasKey('object_id', $result, "Missing 'object_id' for target: $target");
        }
    }

    // =========================================================================
    // Kit_Plugin_Installer::find_plugin_file()
    // =========================================================================

    /** @test */
    public function test_find_plugin_file_exact_match(): void
    {
        $plugins = [
            'elementor/elementor.php'               => ['Version' => '3.20.0', 'Name' => 'Elementor'],
            'woocommerce/woocommerce.php'            => ['Version' => '8.0.0', 'Name' => 'WooCommerce'],
            'header-footer-elementor/hfe.php'       => ['Version' => '2.1.0', 'Name' => 'HFE'],
        ];

        $result = Kit_Plugin_Installer::find_plugin_file('elementor', $plugins);
        $this->assertSame('elementor/elementor.php', $result);
    }

    /** @test */
    public function test_find_plugin_file_exact_match_woocommerce(): void
    {
        $plugins = [
            'elementor/elementor.php'    => ['Version' => '3.20.0'],
            'woocommerce/woocommerce.php' => ['Version' => '8.0.0'],
        ];

        $result = Kit_Plugin_Installer::find_plugin_file('woocommerce', $plugins);
        $this->assertSame('woocommerce/woocommerce.php', $result);
    }

    /** @test */
    public function test_find_plugin_file_directory_fallback_when_main_file_differs(): void
    {
        // Plugin slug dir is 'acf' but the main PHP file is 'advanced-custom-fields.php'.
        $plugins = [
            'acf/advanced-custom-fields.php' => ['Version' => '6.0.0', 'Name' => 'ACF'],
            'elementor/elementor.php'         => ['Version' => '3.20.0'],
        ];

        $result = Kit_Plugin_Installer::find_plugin_file('acf', $plugins);
        $this->assertSame('acf/advanced-custom-fields.php', $result);
    }

    /** @test */
    public function test_find_plugin_file_returns_null_for_unknown_slug(): void
    {
        $plugins = [
            'elementor/elementor.php' => ['Version' => '3.20.0'],
        ];

        $result = Kit_Plugin_Installer::find_plugin_file('nonexistent-plugin', $plugins);
        $this->assertNull($result);
    }

    /** @test */
    public function test_find_plugin_file_returns_null_for_empty_plugin_list(): void
    {
        $result = Kit_Plugin_Installer::find_plugin_file('elementor', []);
        $this->assertNull($result);
    }

    /** @test */
    public function test_find_plugin_file_exact_match_takes_precedence_over_directory_fallback(): void
    {
        // Both slug/slug.php (exact) and slug/other.php (fallback) exist.
        // The exact match must win.
        $plugins = [
            'myslug/other-name.php' => ['Version' => '1.0.0'],
            'myslug/myslug.php'     => ['Version' => '2.0.0'],
        ];

        $result = Kit_Plugin_Installer::find_plugin_file('myslug', $plugins);
        $this->assertSame('myslug/myslug.php', $result);
    }

    /** @test */
    public function test_find_plugin_file_does_not_match_partial_slug(): void
    {
        // 'woo' should NOT match 'woocommerce/woocommerce.php'
        $plugins = [
            'woocommerce/woocommerce.php' => ['Version' => '8.0.0'],
        ];

        $result = Kit_Plugin_Installer::find_plugin_file('woo', $plugins);
        $this->assertNull($result);
    }

    /** @test */
    public function test_find_plugin_file_slug_with_hyphens(): void
    {
        $plugins = [
            'header-footer-elementor/header-footer-elementor.php' => ['Version' => '2.1.0'],
        ];

        $result = Kit_Plugin_Installer::find_plugin_file('header-footer-elementor', $plugins);
        $this->assertSame('header-footer-elementor/header-footer-elementor.php', $result);
    }

    // =========================================================================
    // Kit_Rollback ring-buffer logic
    // =========================================================================

    /**
     * Reset the in-memory WP option store before each rollback test
     * so snapshots don't bleed across tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_novamira_test_options'] = [];
    }

    /** @test */
    public function test_list_snapshots_returns_empty_when_no_snapshots(): void
    {
        $result = Kit_Rollback::list_snapshots();
        $this->assertSame([], $result);
    }

    /** @test */
    public function test_create_snapshot_returns_non_empty_id(): void
    {
        $manifest = $this->make_empty_manifest();
        $id = Kit_Rollback::create_snapshot('TestKit', $manifest);

        $this->assertIsString($id);
        $this->assertNotEmpty($id);
        $this->assertStringStartsWith('kit_', $id);
    }

    /** @test */
    public function test_snapshot_is_retrievable_after_creation(): void
    {
        $manifest = $this->make_empty_manifest();
        $id = Kit_Rollback::create_snapshot('Acme Kit', $manifest);

        $snapshots = Kit_Rollback::list_snapshots();
        $this->assertCount(1, $snapshots);
        $this->assertSame($id, $snapshots[0]['id']);
        $this->assertSame('Acme Kit', $snapshots[0]['kit_name']);
    }

    /** @test */
    public function test_newest_snapshot_is_first(): void
    {
        $manifest = $this->make_empty_manifest();
        $id1 = Kit_Rollback::create_snapshot('Kit A', $manifest);
        $id2 = Kit_Rollback::create_snapshot('Kit B', $manifest);

        $snapshots = Kit_Rollback::list_snapshots();
        $this->assertSame($id2, $snapshots[0]['id'], 'Newest snapshot should be first');
        $this->assertSame($id1, $snapshots[1]['id']);
    }

    /** @test */
    public function test_ring_buffer_caps_at_max_snapshots(): void
    {
        $manifest = $this->make_empty_manifest();

        // Create MAX_SNAPSHOTS + 3 snapshots.
        $count = Kit_Rollback::MAX_SNAPSHOTS + 3;
        for ($i = 0; $i < $count; $i++) {
            Kit_Rollback::create_snapshot("Kit #{$i}", $manifest);
        }

        $snapshots = Kit_Rollback::list_snapshots(Kit_Rollback::MAX_SNAPSHOTS + 5);
        $this->assertCount(Kit_Rollback::MAX_SNAPSHOTS, $snapshots);
    }

    /** @test */
    public function test_ring_buffer_retains_most_recent_snapshots(): void
    {
        $manifest = $this->make_empty_manifest();

        for ($i = 1; $i <= Kit_Rollback::MAX_SNAPSHOTS + 2; $i++) {
            Kit_Rollback::create_snapshot("Kit #{$i}", $manifest);
        }

        $snapshots = Kit_Rollback::list_snapshots();
        // The oldest two (Kit #1, Kit #2) should have been evicted.
        $names = array_column($snapshots, 'kit_name');
        $this->assertNotContains('Kit #1', $names, 'Oldest kit should have been evicted');
        $this->assertNotContains('Kit #2', $names, 'Second-oldest kit should have been evicted');
    }

    /** @test */
    public function test_record_posts_updates_snapshot(): void
    {
        $manifest = $this->make_empty_manifest();
        $id = Kit_Rollback::create_snapshot('PostKit', $manifest);

        Kit_Rollback::record_posts($id, ['home' => 10, 'about' => 20]);

        $snapshots = Kit_Rollback::list_snapshots();
        $this->assertContains(10, $snapshots[0]['posts_created']);
        $this->assertContains(20, $snapshots[0]['posts_created']);
    }

    /** @test */
    public function test_record_plugins_updates_snapshot(): void
    {
        $manifest = $this->make_empty_manifest();
        $id = Kit_Rollback::create_snapshot('PluginKit', $manifest);

        Kit_Rollback::record_plugins($id, ['elementor/elementor.php', 'woocommerce/woocommerce.php']);

        $snapshots = Kit_Rollback::list_snapshots();
        $this->assertContains('elementor/elementor.php', $snapshots[0]['plugins_installed']);
    }

    /** @test */
    public function test_record_menus_updates_snapshot(): void
    {
        $manifest = $this->make_empty_manifest();
        $id = Kit_Rollback::create_snapshot('MenuKit', $manifest);

        Kit_Rollback::record_menus($id, [5, 7]);

        $snapshots = Kit_Rollback::list_snapshots();
        $this->assertContains(5, $snapshots[0]['menus_created']);
        $this->assertContains(7, $snapshots[0]['menus_created']);
    }

    /** @test */
    public function test_rollback_fails_gracefully_for_unknown_snapshot(): void
    {
        $result = Kit_Rollback::rollback('kit_nonexistent_0000');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('kit_nonexistent_0000', $result['error']);
    }

    /** @test */
    public function test_rollback_removes_snapshot_from_store(): void
    {
        $manifest = $this->make_empty_manifest();
        $id = Kit_Rollback::create_snapshot('DeleteMe', $manifest);

        $this->assertCount(1, Kit_Rollback::list_snapshots());

        // rollback() internally calls delete_snapshot().
        Kit_Rollback::rollback($id);

        $this->assertCount(0, Kit_Rollback::list_snapshots());
    }

    /** @test */
    public function test_cleanup_removes_snapshots_older_than_cutoff(): void
    {
        // Directly write a stale snapshot (timestamp 30 days ago).
        $stale_id = 'kit_stale_0000';
        $stale_ts = gmdate('c', time() - (30 * DAY_IN_SECONDS));
        $fresh_id = 'kit_fresh_0000';
        $fresh_ts = gmdate('c', time() - (1 * DAY_IN_SECONDS));

        $GLOBALS['_novamira_test_options'][Kit_Rollback::OPTION_KEY] = [
            ['id' => $fresh_id, 'kit_name' => 'Fresh', 'timestamp' => $fresh_ts, 'posts_created' => [], 'menus_created' => [], 'plugins_installed' => []],
            ['id' => $stale_id, 'kit_name' => 'Stale', 'timestamp' => $stale_ts, 'posts_created' => [], 'menus_created' => [], 'plugins_installed' => []],
        ];

        $removed = Kit_Rollback::cleanup(7); // Remove snapshots older than 7 days.

        $this->assertSame(1, $removed);

        $remaining = Kit_Rollback::list_snapshots();
        $ids = array_column($remaining, 'id');
        $this->assertContains($fresh_id, $ids);
        $this->assertNotContains($stale_id, $ids);
    }

    /** @test */
    public function test_cleanup_returns_zero_when_nothing_to_remove(): void
    {
        $manifest = $this->make_empty_manifest();
        Kit_Rollback::create_snapshot('Recent', $manifest);

        $removed = Kit_Rollback::cleanup(7);
        $this->assertSame(0, $removed);
    }

    /** @test */
    public function test_list_snapshots_respects_limit_parameter(): void
    {
        $manifest = $this->make_empty_manifest();
        for ($i = 0; $i < 5; $i++) {
            Kit_Rollback::create_snapshot("Kit #{$i}", $manifest);
        }

        $limited = Kit_Rollback::list_snapshots(3);
        $this->assertCount(3, $limited);
    }

    /** @test */
    public function test_multiple_records_for_different_snapshots_do_not_cross_contaminate(): void
    {
        $manifest = $this->make_empty_manifest();
        $id_a = Kit_Rollback::create_snapshot('Kit A', $manifest);
        $id_b = Kit_Rollback::create_snapshot('Kit B', $manifest);

        Kit_Rollback::record_posts($id_a, ['home' => 10]);
        Kit_Rollback::record_posts($id_b, ['shop' => 20]);

        $all = Kit_Rollback::list_snapshots(10);

        // id_b is index 0 (newest first).
        $b = array_filter($all, fn($s) => $s['id'] === $id_b);
        $a = array_filter($all, fn($s) => $s['id'] === $id_a);

        $b = array_values($b)[0];
        $a = array_values($a)[0];

        $this->assertContains(20, $b['posts_created']);
        $this->assertNotContains(10, $b['posts_created']);

        $this->assertContains(10, $a['posts_created']);
        $this->assertNotContains(20, $a['posts_created']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return a Kit_Manifest stub with no globals (avoids needing full JSON).
     *
     * Kit_Manifest::create_snapshot() only uses the manifest to snapshot globals;
     * an empty globals array is sufficient for ring-buffer tests.
     */
    private function make_empty_manifest(): \Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Manifest
    {
        $json = json_encode([
            'kit_name'    => 'Test Kit',
            'kit_version' => '1.0',
            'templates'   => [],
            'globals'     => [],
        ]);

        return \Novamira\AdrianV2\Abilities\ElementorTemplates\Kit_Manifest::from_json($json);
    }
}
