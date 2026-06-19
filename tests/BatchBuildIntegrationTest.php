<?php
/**
 * Test: Phase 5 — End-to-End Integration Tests (8 cases).
 *
 * Integration tests for:
 *   - Kit_Convert_V3_To_V4 (dry-run, V4 guard)
 *   - Setup_V4_Foundation (read kit, create base classes)
 *   - Batch_Build_Page (V4 page build, new page, V3/V4 guard)
 *   - Full pipeline: convert → setup → build
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// All integration tests need V4 to be available. Define the constant
// unconditionally so test order doesn't cause race conditions.
if (!defined('ELEMENTOR_VERSION')) {
    define('ELEMENTOR_VERSION', '4.0.0');
}

require_once __DIR__ . '/../includes/helpers/class-helpers.php';
require_once __DIR__ . '/../includes/abilities/elementor/class-kit-convert-v3-to-v4.php';
require_once __DIR__ . '/../includes/abilities/elementor/class-setup-v4-foundation.php';
require_once __DIR__ . '/../includes/abilities/elementor/class-batch-build-page.php';

use Novamira\AdrianV2\Abilities\Elementor\Batch_Build_Page;
use Novamira\AdrianV2\Abilities\Elementor\Kit_Convert_V3_To_V4;
use Novamira\AdrianV2\Abilities\Elementor\Setup_V4_Foundation;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
class BatchBuildIntegrationTest extends TestCase
{
    private const KIT_ID = 1;
    private const PAGE_ID = 42;

    protected function setUp(): void
    {
        $GLOBALS['_test_posts']          = [];
        $GLOBALS['_wpcode_meta']         = [];
        $GLOBALS['_test_wp_cache']       = [];
        $GLOBALS['_test_elementor_docs'] = [];
        $GLOBALS['_test_post_meta_update_calls'] = [];
        $GLOBALS['_registered_abilities'] = [];
        $GLOBALS['_test_kits_manager_active_id'] = self::KIT_ID;
        $GLOBALS['_test_revision_counter'] = 0;
        $GLOBALS['_test_revisions']       = [];

        // Seed kit so get_post(KIT_ID) works.
        $GLOBALS['_test_posts'][self::KIT_ID] = [
            'ID'          => self::KIT_ID,
            'post_title'  => 'Default Kit',
            'post_status' => 'publish',
            'post_type'   => 'elementor_kit',
        ];

        // Seed page so get_post(PAGE_ID) works.
        $GLOBALS['_test_posts'][self::PAGE_ID] = [
            'ID'          => self::PAGE_ID,
            'post_title'  => 'Test Page',
            'post_status' => 'draft',
            'post_type'   => 'page',
        ];

        // Register all 3 abilities.
        Batch_Build_Page::register();
        Setup_V4_Foundation::register();
        Kit_Convert_V3_To_V4::register();

        // ── Override get_option for elementor_active_kit ──
        // The mock returns its $default, so override _test_options.
        $GLOBALS['_test_options'] = ['elementor_active_kit' => self::KIT_ID];
    }

    // ── 1. Batch Build Page — Empty Elements Guard ───────────────────────────

    public function test_batch_build_page_rejects_empty_elements(): void
    {
        $result = Batch_Build_Page::execute(['elements' => []]);
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    // ── 2. Kit Convert — Dry-run Colors ──────────────────────────────────────

    public function test_kit_convert_dry_run_colors(): void
    {

        // Seed V3 page settings with system_colors.
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_page_settings'] = [
            'system_colors' => [
                ['_id' => 'primary',  'title' => 'Primary',  'color' => '#0f5bff'],
                ['_id' => 'secondary','title' => 'Secondary','color' => '#6c757d'],
            ],
        ];

        $result = Kit_Convert_V3_To_V4::execute([
            'dry_run'   => true,
            'do_colors' => true,
            'do_typography' => false,
            'do_classes'    => false,
            'do_responsive' => false,
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['dry_run']);
        $this->assertSame('running', $result['phase_colors']['status']);
        $this->assertGreaterThan(0, $result['phase_colors']['created']);
        $this->assertArrayHasKey('primary', $result['variable_map']);
        $this->assertSame('primary-color', $result['variable_map']['primary']['label']);
    }

    // ── 3. Setup V4 Foundation — Reads Existing Kit ──────────────────────────

    public function test_setup_v4_foundation_reads_existing_variables_and_classes(): void
    {
        // Seed variables: one color, one font.
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_variables'] = json_encode([
            'data' => [
                'e-gv-color1' => [
                    'label' => 'primary-color',
                    'value' => ['$$type' => 'color', 'value' => '#0f5bff'],
                    'type'  => 'global-color-variable',
                    'order' => 1,
                ],
                'e-gv-font1' => [
                    'label' => 'font-heading',
                    'value' => ['$$type' => 'string', 'value' => 'Geist'],
                    'type'  => 'global-font-variable',
                    'order' => 2,
                ],
            ],
        ]);

        // Seed global classes.
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_classes_order'] = [
            'order' => ['gc-abc123', 'gc-def456'],
        ];
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_classes_labels'] = [
            'gc-abc123' => 'e-flexbox-base',
            'gc-def456' => 'primary',
        ];

        // Override get_option for elementor_active_kit.
        $GLOBALS['_test_options'] = ['elementor_active_kit' => self::KIT_ID];

        $result = Setup_V4_Foundation::execute([]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Variables grouped.
        $this->assertArrayHasKey('primary-color', $result['variables']['colors']);
        $this->assertSame('e-gv-color1', $result['variables']['colors']['primary-color']);
        $this->assertArrayHasKey('font-heading', $result['variables']['fonts']);

        // Classes.
        $this->assertArrayHasKey('e-flexbox-base', $result['classes']);
        $this->assertArrayHasKey('primary', $result['classes']);

        // Quick ref.
        $qr = $result['quick_ref'];
        $this->assertArrayHasKey('flexbox_base', $qr['base_classes']);
        $this->assertNotNull($qr['base_classes']['flexbox_base']);
        $this->assertArrayHasKey('primary', $qr['colors']);
        $this->assertSame('e-gv-color1', $qr['colors']['primary']);
    }

    // ── 4. Setup V4 Foundation — Creates Missing Base Classes ────────────────

    public function test_setup_v4_foundation_creates_missing_base_classes(): void
    {
        // Empty kit — no base classes exist.
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_classes_order'] = ['order' => []];
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_classes_labels'] = [];
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_variables'] = json_encode(['data' => []]);

        $GLOBALS['_test_options'] = ['elementor_active_kit' => self::KIT_ID];

        $result = Setup_V4_Foundation::execute(['create_missing' => true]);

        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['base_classes']['e-flexbox-base']['status']);
        $this->assertSame('created', $result['base_classes']['e-div-block-base']['status']);
        $this->assertNotNull($result['base_classes']['e-flexbox-base']['id']);
    }

    // ── 5. Batch Build Page — V4 Atomic Page ─────────────────────────────────

    public function test_batch_build_page_v4_atomic_elements(): void
    {
        // Mark page as V4 so the version guard passes.
        $GLOBALS['_wpcode_meta'][self::PAGE_ID]['_elementor_edit_mode'] = 'builder';
        $GLOBALS['_wpcode_meta'][self::PAGE_ID]['_elementor_version']  = '4.0.0';

        $elements = [
            [
                'type'     => 'e-flexbox',
                'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
                'children' => [
                    [
                        'type'     => 'e-heading',
                        'settings' => [
                            'title' => ['$$type' => 'html-v3', 'value' => 'Hello World'],
                            'tag'   => ['$$type' => 'string', 'value' => 'h1'],
                        ],
                    ],
                ],
            ],
        ];

        $result = Batch_Build_Page::execute([
            'post_id'  => self::PAGE_ID,
            'elements' => $elements,
            'opt_in'   => true, // bypass version mismatch guard
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(self::PAGE_ID, $result['post_id']);
        $this->assertGreaterThan(0, $result['total_elements']);

        // Verify _elementor_data was written.
        $meta_calls = $GLOBALS['_test_post_meta_update_calls'] ?? [];
        $data_calls = array_filter($meta_calls, fn($c) => '_elementor_data' === $c['key']);
        $this->assertNotEmpty($data_calls, '_elementor_data must be written to post meta');

        $last = end($data_calls);
        $encoded = is_string($last['value']) ? stripslashes($last['value']) : '';
        $this->assertStringContainsString('e-flexbox', $encoded);
        $this->assertStringContainsString('e-heading', $encoded);
    }

    // ── 6. Batch Build Page — New Page Creation ──────────────────────────────

    public function test_batch_build_page_creates_new_page(): void
    {
        $elements = [[
            'type'     => 'e-flexbox',
            'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
            'children' => [],
        ]];

        $result = Batch_Build_Page::execute([
            'title'    => 'My New V4 Page',
            'slug'     => 'my-new-v4-page',
            'status'   => 'draft',
            'elements' => $elements,
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['created_page']);
        $this->assertGreaterThan(0, $result['post_id']);
        $this->assertStringContainsString((string) $result['post_id'], $result['permalink']);
    }

    // ── 7. Batch Build Page — V3/V4 Mismatch Guard ───────────────────────────

    public function test_batch_build_page_guards_v4_page_with_v3_tree(): void
    {
        // Mark page as V4.
        $GLOBALS['_wpcode_meta'][self::PAGE_ID]['_elementor_edit_mode'] = 'builder';
        $GLOBALS['_wpcode_meta'][self::PAGE_ID]['_elementor_version']  = '4.0.0';

        // But provide a V3-only tree (no e-* elements).
        $elements = [[
            'type'     => 'section',
            'settings' => [],
            'children' => [
                ['type' => 'column', 'settings' => [], 'children' => [
                    ['type' => 'heading', 'settings' => ['title' => 'V3 Heading']],
                ]],
            ],
        ]];

        $result = Batch_Build_Page::execute([
            'post_id'  => self::PAGE_ID,
            'elements' => $elements,
        ]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('page_version_mismatch', array_key_first($result->errors));
    }

    // ── 8. Full Pipeline: Convert → Setup → Build ────────────────────────────

    public function test_full_pipeline_convert_setup_build(): void
    {
        // Step 1: Kit Convert dry-run with V3 colors.
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_page_settings'] = [
            'system_colors' => [
                ['_id' => 'primary',  'title' => 'Primary',  'color' => '#0f5bff'],
                ['_id' => 'text',     'title' => 'Text',     'color' => '#212529'],
            ],
            'system_typography' => [],
        ];
        $GLOBALS['_test_options'] = ['elementor_active_kit' => self::KIT_ID];

        $convert = Kit_Convert_V3_To_V4::execute([
            'dry_run'         => true,
            'do_colors'       => true,
            'do_typography'   => false,
            'do_classes'      => false,
            'do_responsive'   => false,
        ]);

        $this->assertTrue($convert['dry_run']);
        $this->assertArrayHasKey('primary', $convert['variable_map']);
        $this->assertArrayHasKey('text', $convert['variable_map']);

        // Step 2: Setup V4 Foundation — seed the kit with what convert produced.
        // For this integration, seed variables that match the conversion output.
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_variables'] = json_encode([
            'data' => [
                'e-gv-001' => [
                    'label' => 'primary-color',
                    'value' => ['$$type' => 'color', 'value' => '#0f5bff'],
                    'type'  => 'global-color-variable',
                    'order' => 1,
                ],
                'e-gv-002' => [
                    'label' => 'text-color',
                    'value' => ['$$type' => 'color', 'value' => '#212529'],
                    'type'  => 'global-color-variable',
                    'order' => 2,
                ],
            ],
        ]);
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_classes_order'] = ['order' => ['gc-flex']];
        $GLOBALS['_wpcode_meta'][self::KIT_ID]['_elementor_global_classes_labels'] = ['gc-flex' => 'e-flexbox-base'];

        $foundation = Setup_V4_Foundation::execute([]);
        $this->assertTrue($foundation['success']);
        $primary_id = $foundation['quick_ref']['colors']['primary'];
        $flex_id    = $foundation['quick_ref']['base_classes']['flexbox_base'];

        $this->assertNotNull($primary_id);
        $this->assertNotNull($flex_id);

        // Step 3: Batch Build Page using IDs from foundation.
        $GLOBALS['_wpcode_meta'][self::PAGE_ID]['_elementor_edit_mode'] = 'builder';
        $GLOBALS['_wpcode_meta'][self::PAGE_ID]['_elementor_version']  = '4.0.0';

        $elements = [[
            'type'     => 'e-flexbox',
            'settings' => ['classes' => ['$$type' => 'classes', 'value' => [$flex_id]]],
            'children' => [
                [
                    'type'     => 'e-heading',
                    'settings' => [
                        'title' => ['$$type' => 'html-v3', 'value' => 'Pipeline Test'],
                        'tag'   => ['$$type' => 'string', 'value' => 'h2'],
                    ],
                ],
            ],
        ]];

        $build = Batch_Build_Page::execute([
            'post_id'  => self::PAGE_ID,
            'elements' => $elements,
            'opt_in'   => true,
        ]);

        $this->assertTrue($build['success']);
        $this->assertSame(self::PAGE_ID, $build['post_id']);

        // Verify the flexbox class ID from foundation was used in the build.
        $meta_calls = $GLOBALS['_test_post_meta_update_calls'] ?? [];
        $data_calls = array_filter($meta_calls, fn($c) => '_elementor_data' === $c['key']);
        $last = end($data_calls);
        $encoded = is_string($last['value']) ? stripslashes($last['value']) : '';
        $this->assertStringContainsString($flex_id, $encoded,
            'Flexbox base class ID from foundation must appear in _elementor_data');
    }
}
