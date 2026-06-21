<?php
declare(strict_types=1);

/**
 * WordPress Integration Test for fix_kit_styles_for_page()
 *
 * Requires WordPress to be loaded (wp-load.php). Tests the positive path
 * where a Kit has _elementor_data with style classes, and a page tree
 * references those Kit-level classes that need responsive fixes.
 *
 * Run via: php wp-content/plugins/novamira-adrianv2/tests/run-integration.php
 *
 * @covers \Novamira\AdrianV2\Helpers\Conversion_AutoFixer::fix_kit_styles_for_page
 */

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\Conversion_AutoFixer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class FixKitStylesForPageIntegrationTest extends TestCase
{
    /** @var int|null Active Kit post ID. */
    private ?int $kit_id = null;

    /** @var mixed Original _elementor_data for the Kit. */
    private $original_kit_data = null;

    // =================================================================
    // Lifecycle
    // =================================================================

    protected function setUp(): void
    {
        $this->kit_id = (int) get_option('elementor_active_kit');
        $this->assertGreaterThan(0, $this->kit_id, 'No active Elementor Kit found');

        // Backup original Kit data.
        $this->original_kit_data = get_post_meta($this->kit_id, '_elementor_data', true);

        // Reset the static cache so load_kit_tree() reads fresh data.
        $this->resetKitCache();
    }

    protected function tearDown(): void
    {
        // Restore original Kit data.
        if ($this->kit_id > 0) {
            if (null !== $this->original_kit_data && '' !== $this->original_kit_data) {
                update_post_meta($this->kit_id, '_elementor_data', $this->original_kit_data);
            } else {
                delete_post_meta($this->kit_id, '_elementor_data');
            }
            $this->resetKitCache();
        }
    }

    // =================================================================
    // Positive path: Kit with classes gets responsive fixes
    // =================================================================

    public function test_kit_styles_get_responsive_fixes_when_page_references_them(): void
    {
        // ── Arrange: Kit tree with style classes that need responsive fixes ──
        $kit_class_id = 'gc-test-kit-class-' . uniqid();

        $kit_tree = [
            [
                'id'       => 'kit-container-1',
                'elType'   => 'e-flexbox',
                'settings' => [
                    'classes' => ['$$type' => 'classes', 'value' => []],
                ],
                'elements' => [],
                'styles'   => [
                    $kit_class_id => [
                        'id'       => $kit_class_id,
                        'label'    => 'Kit Heading Style',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                'props'      => [
                                    'font-size'           => ['$$type' => 'size', 'value' => ['size' => 48, 'unit' => 'px']],
                                    'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 32, 'unit' => 'px']],
                                ],
                                'custom_css' => null,
                            ],
                            // Only desktop — no tablet or mobile variants.
                            // fix_kit_styles_for_page should generate them.
                        ],
                    ],
                ],
            ],
        ];

        // Save Kit tree to the database.
        $this->saveKitTree($kit_tree);

        // ── Arrange: Page tree that references the Kit class ──
        $page_tree = [
            [
                'id'         => 'page-widget-1',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [
                        '$$type' => 'classes',
                        'value'  => [$kit_class_id],   // References Kit class
                    ],
                    'title'   => 'Page Heading',
                ],
                'elements' => [],
                'styles'   => [
                    // Page has its own local class, but the Kit class is NOT defined here.
                    'e-page-local' => [
                        'id'       => 'e-page-local',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                'props'      => [
                                    'color' => ['$$type' => 'string', 'value' => '#333333'],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // ── Act ──
        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page($page_tree, $fixes);

        // ── Assert ──
        $this->assertNotNull($result, 'Should return modified Kit tree, not null');
        $this->assertIsArray($result);
        $this->assertCount(1, $result, 'Kit tree should have 1 top-level element');

        // The Kit tree should now have responsive variants.
        $kit_styles = $result[0]['styles'][$kit_class_id] ?? null;
        $this->assertNotNull($kit_styles, 'Kit class should still exist in returned tree');

        $variants = $kit_styles['variants'] ?? [];
        $this->assertGreaterThanOrEqual(2, count($variants), 'Should have at least desktop + tablet/mobile');

        // Find breakpoints.
        $breakpoints = [];
        foreach ($variants as $v) {
            $breakpoints[] = $v['meta']['breakpoint'] ?? '?';
        }

        $this->assertContains('desktop', $breakpoints);
        $this->assertContains('tablet', $breakpoints, 'Should have generated a tablet variant');
        $this->assertContains('mobile', $breakpoints, 'Should have generated a mobile variant');

        $this->assertGreaterThan(0, $fixes, 'Should report at least 1 fix');
    }

    // =================================================================
    // Positive path: Kit with nested styles
    // =================================================================

    public function test_kit_styles_in_nested_elements_get_fixed(): void
    {
        $kit_class_id = 'gc-nested-kit-' . uniqid();

        // Kit tree with styles on a deeply nested element.
        $kit_tree = [
            [
                'id'       => 'kit-root',
                'elType'   => 'e-flexbox',
                'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
                'elements' => [
                    [
                        'id'       => 'kit-nested',
                        'elType'   => 'e-div-block',
                        'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
                        'elements' => [],
                        'styles'   => [
                            $kit_class_id => [
                                'id'       => $kit_class_id,
                                'label'    => 'Nested Kit Style',
                                'type'     => 'class',
                                'variants' => [
                                    [
                                        'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                        'props'      => [
                                            'font-size' => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                                            'gap'       => ['$$type' => 'size', 'value' => ['size' => 16, 'unit' => 'px']],
                                        ],
                                        'custom_css' => null,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'styles' => [],
            ],
        ];

        $this->saveKitTree($kit_tree);

        $page_tree = [
            [
                'id'         => 'pw-1',
                'elType'     => 'widget',
                'widgetType' => 'e-paragraph',
                'settings'   => [
                    'classes' => ['$$type' => 'classes', 'value' => [$kit_class_id]],
                    'text'    => 'Referencing nested Kit class',
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page($page_tree, $fixes);

        $this->assertNotNull($result);

        // Find the nested element with styles.
        $nested = $result[0]['elements'][0] ?? null;
        $this->assertNotNull($nested);

        $variants = $nested['styles'][$kit_class_id]['variants'] ?? [];
        $breakpoints = array_column($variants, 'meta');
        $bps = array_map(fn($m) => $m['breakpoint'] ?? '?', $breakpoints);

        $this->assertContains('tablet', $bps);
        $this->assertContains('mobile', $bps);

        // Verify tablet gap scaling: 16 × 0.75 = 12.0
        $tablet_variant = null;
        foreach ($variants as $v) {
            if ('tablet' === ($v['meta']['breakpoint'] ?? '')) {
                $tablet_variant = $v;
                break;
            }
        }
        $this->assertNotNull($tablet_variant);
        $this->assertEquals(12.0, $tablet_variant['props']['gap']['value']['size']);
    }

    // =================================================================
    // Positive path: Page references multiple Kit classes
    // =================================================================

    public function test_multiple_kit_classes_all_get_fixed(): void
    {
        $class_a = 'gc-multi-a-' . uniqid();
        $class_b = 'gc-multi-b-' . uniqid();

        $kit_tree = [
            [
                'id'       => 'kit-multi',
                'elType'   => 'e-flexbox',
                'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
                'elements' => [],
                'styles'   => [
                    $class_a => [
                        'id'       => $class_a,
                        'label'    => 'Style A',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                'props'      => [
                                    'font-size' => ['$$type' => 'size', 'value' => ['size' => 36, 'unit' => 'px']],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                    $class_b => [
                        'id'       => $class_b,
                        'label'    => 'Style B',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                'props'      => [
                                    'font-size'             => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']],
                                    'padding-inline-start'  => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->saveKitTree($kit_tree);

        $page_tree = [
            [
                'id'         => 'pw-multi',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => ['$$type' => 'classes', 'value' => [$class_a, $class_b]],
                    'title'   => 'Multi-class heading',
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page($page_tree, $fixes);

        $this->assertNotNull($result);

        $styles = $result[0]['styles'] ?? [];

        // Class A: should have tablet + mobile.
        $va = $styles[$class_a]['variants'] ?? [];
        $this->assertGreaterThanOrEqual(3, count($va), 'Class A should have 3 variants');
        $bps_a = array_map(fn($v) => $v['meta']['breakpoint'] ?? '?', $va);
        $this->assertContains('tablet', $bps_a);
        $this->assertContains('mobile', $bps_a);

        // Class B: should have tablet + mobile.
        $vb = $styles[$class_b]['variants'] ?? [];
        $this->assertGreaterThanOrEqual(3, count($vb), 'Class B should have 3 variants');
        $bps_b = array_map(fn($v) => $v['meta']['breakpoint'] ?? '?', $vb);
        $this->assertContains('tablet', $bps_b);
        $this->assertContains('mobile', $bps_b);

        // Both should have generated fixes (at least 4: 2 tablet + 2 mobile).
        $this->assertGreaterThanOrEqual(4, $fixes);
    }

    // =================================================================
    // Negative path: Kit with no matching classes returns null
    // =================================================================

    public function test_returns_null_when_kit_has_no_matching_classes(): void
    {
        $kit_tree = [
            [
                'id'       => 'kit-unrelated',
                'elType'   => 'e-flexbox',
                'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
                'elements' => [],
                'styles'   => [
                    'gc-other-style' => [
                        'id'       => 'gc-other-style',
                        'label'    => 'Other',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                'props'      => [
                                    'color' => ['$$type' => 'string', 'value' => '#000'],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->saveKitTree($kit_tree);

        $page_tree = [
            [
                'id'         => 'pw-other',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => ['$$type' => 'classes', 'value' => ['gc-completely-different-class']],
                    'title'   => 'Different',
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page($page_tree, $fixes);

        // The page references 'gc-completely-different-class', but the Kit
        // only defines 'gc-other-style' — no match, so null.
        $this->assertNull($result);
        $this->assertEquals(0, $fixes);
    }

    // =================================================================
    // Edge case: Kit class already has mobile variant — no change
    // =================================================================

    public function test_kit_class_with_all_variants_is_not_modified(): void
    {
        $class_id = 'gc-already-complete-' . uniqid();

        $kit_tree = [
            [
                'id'       => 'kit-complete',
                'elType'   => 'e-flexbox',
                'settings' => ['classes' => ['$$type' => 'classes', 'value' => []]],
                'elements' => [],
                'styles'   => [
                    $class_id => [
                        'id'       => $class_id,
                        'label'    => 'Already Complete',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                'props'      => [
                                    'font-size' => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                                ],
                                'custom_css' => null,
                            ],
                            [
                                'meta'       => ['breakpoint' => 'tablet', 'state' => null],
                                'props'      => [
                                    'font-size' => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']],
                                ],
                                'custom_css' => null,
                            ],
                            [
                                'meta'       => ['breakpoint' => 'mobile', 'state' => null],
                                'props'      => [
                                    'font-size' => ['$$type' => 'size', 'value' => ['size' => 16, 'unit' => 'px']],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->saveKitTree($kit_tree);

        $page_tree = [
            [
                'id'         => 'pw-complete',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => ['$$type' => 'classes', 'value' => [$class_id]],
                    'title'   => 'Already complete',
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page($page_tree, $fixes);

        // The class already has desktop + tablet + mobile, and the mobile
        // values differ from desktop — no changes should be needed.
        // However, clean_mobile_overrides won't remove anything either.
        // So the Kit tree should return null (no changes made).
        $this->assertNull($result, 'Should return null when all variants already exist');
        $this->assertEquals(0, $fixes);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Save a Kit element tree to the database and reset the cache.
     */
    private function saveKitTree(array $tree): void
    {
        $encoded = wp_json_encode($tree);
        update_post_meta($this->kit_id, '_elementor_data', wp_slash($encoded));
        $this->resetKitCache();
    }

    /**
     * Reset the static kit_tree_cache so load_kit_tree() reads fresh data.
     */
    private function resetKitCache(): void
    {
        $rp1 = new ReflectionProperty(Conversion_AutoFixer::class, 'kit_tree_cache');
        $rp2 = new ReflectionProperty(Conversion_AutoFixer::class, 'kit_tree_cache_id');
        $rp1->setAccessible(true);
        $rp2->setAccessible(true);
        $rp1->setValue(null, null);
        $rp2->setValue(null, null);
    }
}
