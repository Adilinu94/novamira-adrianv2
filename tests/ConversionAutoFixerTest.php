<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\Conversion_AutoFixer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for Conversion_AutoFixer — validates process_style_variants,
 * clean_mobile_overrides, fix_kit_styles_for_page, and scale_props.
 *
 * @covers \Novamira\AdrianV2\Helpers\Conversion_AutoFixer
 */
final class ConversionAutoFixerTest extends TestCase
{
    // -----------------------------------------------------------------
    // process_style_variants — core responsive variant generation
    // -----------------------------------------------------------------

    public function test_process_style_variants_adds_tablet_and_mobile_when_only_desktop(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 48, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 3, $style_def['variants'], 'Should have desktop, tablet, mobile' );
        $this->assertEquals( 2, $fixes, 'Should count 2 fixes (tablet + mobile)' );

        // Desktop untouched.
        $this->assertEquals( 'desktop', $style_def['variants'][0]['meta']['breakpoint'] );
        $this->assertEquals( 48, $style_def['variants'][0]['props']['font-size']['value']['size'] );

        // Tablet: scaled to 90% = 43.2, rounded to 43.2.
        $this->assertEquals( 'tablet', $style_def['variants'][1]['meta']['breakpoint'] );
        $this->assertEquals( 43.2, $style_def['variants'][1]['props']['font-size']['value']['size'] );
        $this->assertEquals( 'px', $style_def['variants'][1]['props']['font-size']['value']['unit'] );

        // Mobile: scaled to 80% = 38.4.
        $this->assertEquals( 'mobile', $style_def['variants'][2]['meta']['breakpoint'] );
        $this->assertEquals( 38.4, $style_def['variants'][2]['props']['font-size']['value']['size'] );
    }

    public function test_process_style_variants_adds_mobile_only_when_tablet_exists(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 36, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'tablet', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 28, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 3, $style_def['variants'] );
        $this->assertEquals( 1, $fixes, 'Should count only 1 fix (mobile)' );

        // Tablet should still be at index 1.
        $this->assertEquals( 'tablet', $style_def['variants'][1]['meta']['breakpoint'] );
        // Mobile at the end.
        $this->assertEquals( 'mobile', $style_def['variants'][2]['meta']['breakpoint'] );
        $this->assertEquals( 28.8, $style_def['variants'][2]['props']['font-size']['value']['size'] );
    }

    public function test_process_style_variants_no_change_when_all_variants_exist(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'tablet', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 18, 'unit' => 'px']],
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
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 3, $style_def['variants'] );
        $this->assertEquals( 0, $fixes, 'No fixes should be applied when all variants exist' );
    }

    public function test_process_style_variants_skips_when_no_desktop(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'tablet', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 1, $style_def['variants'] );
        $this->assertEquals( 0, $fixes, 'Should skip when no desktop variant' );
    }

    public function test_process_style_variants_skips_empty_variants(): void
    {
        $style_def = ['variants' => []];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 0, $style_def['variants'] );
        $this->assertEquals( 0, $fixes );
    }

    public function test_process_style_variants_skips_when_no_variants_key(): void
    {
        $style_def = [];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertArrayNotHasKey( 'variants', $style_def );
        $this->assertEquals( 0, $fixes );
    }

    public function test_process_style_variants_clamps_font_size_minimum(): void
    {
        // Desktop font-size = 16px. Mobile scale = 0.8 → 12.8px, clamped to 14px.
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 16, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        // Mobile font-size should be clamped to 14px minimum.
        $mobile_props = $style_def['variants'][2]['props'];
        $this->assertEquals( 14.0, $mobile_props['font-size']['value']['size'] );
    }

    public function test_process_style_variants_handles_multiple_responsive_props(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size'             => ['$$type' => 'size', 'value' => ['size' => 32, 'unit' => 'px']],
                        'padding-block-start'   => ['$$type' => 'size', 'value' => ['size' => 40, 'unit' => 'px']],
                        'padding-block-end'     => ['$$type' => 'size', 'value' => ['size' => 40, 'unit' => 'px']],
                        'gap'                   => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 3, $style_def['variants'] );
        $this->assertEquals( 2, $fixes );

        // Check tablet props (scaled to 75-90%).
        $tablet = $style_def['variants'][1]['props'];
        $this->assertEquals( 28.8, $tablet['font-size']['value']['size'] );         // 32 × 0.9
        $this->assertEquals( 30.0, $tablet['padding-block-start']['value']['size'] ); // 40 × 0.75
        $this->assertEquals( 30.0, $tablet['padding-block-end']['value']['size'] );   // 40 × 0.75
        $this->assertEquals( 18.0, $tablet['gap']['value']['size'] );                  // 24 × 0.75

        // Check mobile props (scaled to 50-80%).
        $mobile = $style_def['variants'][2]['props'];
        $this->assertEquals( 25.6, $mobile['font-size']['value']['size'] );         // 32 × 0.8
        $this->assertEquals( 20.0, $mobile['padding-block-start']['value']['size'] ); // 40 × 0.5
        $this->assertEquals( 20.0, $mobile['padding-block-end']['value']['size'] );   // 40 × 0.5
        $this->assertEquals( 12.0, $mobile['gap']['value']['size'] );                  // 24 × 0.5
    }

    public function test_process_style_variants_skips_non_responsive_props(): void
    {
        // color is not in RESPONSIVE_PROPS, so no variants should be generated.
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'color' => ['$$type' => 'string', 'value' => '#ff0000'],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 1, $style_def['variants'], 'Non-responsive props should not trigger variant generation' );
        $this->assertEquals( 0, $fixes );
    }

    public function test_process_style_variants_handles_mixed_responsive_and_non_responsive_props(): void
    {
        // Desktop has font-size (responsive) + color (non-responsive).
        // Only font-size should be scaled in the generated variants.
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                        'color'     => ['$$type' => 'string', 'value' => '#333333'],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeProcessStyleVariants( $style_def, $fixes );

        $this->assertCount( 3, $style_def['variants'] );
        $this->assertEquals( 2, $fixes );

        // Tablet: should have font-size but NOT color.
        $tablet = $style_def['variants'][1]['props'];
        $this->assertArrayHasKey( 'font-size', $tablet );
        $this->assertArrayNotHasKey( 'color', $tablet, 'Non-responsive prop should not be copied to variants' );

        // Mobile: should have font-size but NOT color.
        $mobile = $style_def['variants'][2]['props'];
        $this->assertArrayHasKey( 'font-size', $mobile );
        $this->assertArrayNotHasKey( 'color', $mobile, 'Non-responsive prop should not be copied to variants' );
    }

    // -----------------------------------------------------------------
    // clean_mobile_overrides — remove identical mobile props
    // -----------------------------------------------------------------

    public function test_clean_mobile_overrides_removes_identical_mobile_props(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']],
                        'color'               => ['$$type' => 'string', 'value' => '#333333'],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'mobile', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']], // identical
                        'color'               => ['$$type' => 'string', 'value' => '#333333'],                   // identical
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeCleanMobileOverrides( $style_def, $fixes );

        $this->assertEquals( 2, $fixes, 'Should count 2 removed props' );

        // The mobile variant should be removed entirely (all props were identical).
        $this->assertCount( 1, $style_def['variants'], 'Mobile variant should be removed when all props were identical' );
        $this->assertEquals( 'desktop', $style_def['variants'][0]['meta']['breakpoint'] );
    }

    public function test_clean_mobile_overrides_keeps_different_mobile_props(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 40, 'unit' => 'px']],
                        'font-size'           => ['$$type' => 'size', 'value' => ['size' => 48, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'mobile', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']], // different
                        'font-size'           => ['$$type' => 'size', 'value' => ['size' => 48, 'unit' => 'px']], // identical
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeCleanMobileOverrides( $style_def, $fixes );

        $this->assertEquals( 1, $fixes, 'Should count 1 removed prop (font-size)' );

        // Mobile variant should still exist with only padding-block-start.
        $this->assertCount( 2, $style_def['variants'] );
        $mobile_props = $style_def['variants'][1]['props'];
        $this->assertArrayHasKey( 'padding-block-start', $mobile_props );
        $this->assertArrayNotHasKey( 'font-size', $mobile_props, 'Identical font-size should be removed' );
        $this->assertEquals( 20, $mobile_props['padding-block-start']['value']['size'] );
    }

    public function test_clean_mobile_overrides_no_change_when_no_duplicates(): void
    {
        $original = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 30, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'mobile', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 15, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $style_def = $original;
        $fixes = 0;
        $this->invokeCleanMobileOverrides( $style_def, $fixes );

        $this->assertEquals( 0, $fixes );
        $this->assertEquals( $original, $style_def, 'No changes when values differ' );
    }

    public function test_clean_mobile_overrides_skips_when_no_mobile(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeCleanMobileOverrides( $style_def, $fixes );

        $this->assertCount( 1, $style_def['variants'] );
        $this->assertEquals( 0, $fixes );
    }

    public function test_clean_mobile_overrides_skips_when_only_one_variant(): void
    {
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'mobile', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 16, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeCleanMobileOverrides( $style_def, $fixes );

        $this->assertCount( 1, $style_def['variants'] );
        $this->assertEquals( 0, $fixes );
    }

    public function test_clean_mobile_overrides_handles_mobile_with_extra_props(): void
    {
        // Mobile has an extra prop that desktop doesn't have — should be kept.
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'mobile', 'state' => null],
                    'props'      => [
                        'padding-block-start' => ['$$type' => 'size', 'value' => ['size' => 20, 'unit' => 'px']], // identical → removed
                        'font-size'           => ['$$type' => 'size', 'value' => ['size' => 14, 'unit' => 'px']], // only in mobile
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeCleanMobileOverrides( $style_def, $fixes );

        $this->assertEquals( 1, $fixes, 'Only the identical padding should be removed' );

        // Mobile should still exist with only font-size.
        $this->assertCount( 2, $style_def['variants'] );
        $mobile_props = $style_def['variants'][1]['props'];
        $this->assertArrayNotHasKey( 'padding-block-start', $mobile_props );
        $this->assertArrayHasKey( 'font-size', $mobile_props );
        $this->assertEquals( 14, $mobile_props['font-size']['value']['size'] );
    }

    public function test_clean_mobile_overrides_preserves_tablet_variant(): void
    {
        // When desktop, tablet, and mobile variants all exist, the tablet
        // variant should be left completely untouched.
        $style_def = [
            'variants' => [
                [
                    'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 32, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'tablet', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 28, 'unit' => 'px']],
                    ],
                    'custom_css' => null,
                ],
                [
                    'meta'       => ['breakpoint' => 'mobile', 'state' => null],
                    'props'      => [
                        'font-size' => ['$$type' => 'size', 'value' => ['size' => 32, 'unit' => 'px']], // identical to desktop
                    ],
                    'custom_css' => null,
                ],
            ],
        ];

        $fixes = 0;
        $this->invokeCleanMobileOverrides( $style_def, $fixes );

        $this->assertEquals( 1, $fixes, 'Identical mobile font-size should be removed' );

        // Mobile variant should be gone (all props identical).
        $this->assertCount( 2, $style_def['variants'] );
        $this->assertEquals( 'desktop', $style_def['variants'][0]['meta']['breakpoint'] );
        $this->assertEquals( 'tablet', $style_def['variants'][1]['meta']['breakpoint'] );

        // Tablet props should be completely untouched.
        $this->assertEquals( 28, $style_def['variants'][1]['props']['font-size']['value']['size'] );
    }

    // -----------------------------------------------------------------
    // fix_kit_styles_for_page — Kit-level style fix (public method)
    // -----------------------------------------------------------------

    public function test_fix_kit_styles_for_page_returns_null_when_no_missing_classes(): void
    {
        // All referenced classes are defined in the page tree → null.
        $page_tree = [
            [
                'id'       => 'el1',
                'elType'   => 'widget',
                'widgetType' => 'e-heading',
                'settings' => [
                    'classes' => ['$$type' => 'classes', 'value' => ['e-local-class']],
                    'title'   => 'Test',
                ],
                'elements' => [],
                'styles'   => [
                    'e-local-class' => [
                        'id'       => 'e-local-class',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => ['breakpoint' => 'desktop', 'state' => null],
                                'props'      => [
                                    'font-size' => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page( $page_tree, $fixes );

        $this->assertNull( $result, 'Should return null when all classes are defined in the page' );
        $this->assertEquals( 0, $fixes );
    }

    public function test_fix_kit_styles_for_page_returns_null_when_no_references(): void
    {
        // No element references any class → null.
        $page_tree = [
            [
                'id'       => 'el1',
                'elType'   => 'widget',
                'widgetType' => 'e-heading',
                'settings' => [
                    'classes' => ['$$type' => 'classes', 'value' => []],
                    'title'   => 'Test',
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page( $page_tree, $fixes );

        $this->assertNull( $result );
        $this->assertEquals( 0, $fixes );
    }

    public function test_fix_kit_styles_for_page_returns_null_for_empty_tree(): void
    {
        $fixes  = 0;
        $result = Conversion_AutoFixer::fix_kit_styles_for_page( [], $fixes );

        $this->assertNull( $result );
        $this->assertEquals( 0, $fixes );
    }

    // -----------------------------------------------------------------
    // scale_props — prop scaling for responsive variants
    // -----------------------------------------------------------------

    public function test_scale_props_scales_font_size_for_tablet(): void
    {
        $desktop_props = [
            'font-size' => ['$$type' => 'size', 'value' => ['size' => 48, 'unit' => 'px']],
        ];

        $tablet_scales = [
            'font-size' => 0.9, 'padding-block-start' => 0.75, 'padding-block-end' => 0.75,
            'padding-inline-start' => 0.75, 'padding-inline-end' => 0.75,
            'margin-block-start' => 0.75, 'margin-block-end' => 0.75,
            'margin-inline-start' => 0.75, 'margin-inline-end' => 0.75,
            'width' => 0, 'max-width' => 0, 'gap' => 0.75,
        ];

        $result = $this->invokeScaleProps( $desktop_props, $tablet_scales );

        $this->assertArrayHasKey( 'font-size', $result );
        $this->assertEquals( 43.2, $result['font-size']['value']['size'] );
        $this->assertEquals( 'px', $result['font-size']['value']['unit'] );
        $this->assertEquals( 'size', $result['font-size']['$$type'] );
    }

    public function test_scale_props_scales_font_size_for_mobile(): void
    {
        $desktop_props = [
            'font-size' => ['$$type' => 'size', 'value' => ['size' => 32, 'unit' => 'px']],
        ];

        $mobile_scales = [
            'font-size' => 0.8, 'padding-block-start' => 0.5, 'padding-block-end' => 0.5,
            'padding-inline-start' => 0.5, 'padding-inline-end' => 0.5,
            'margin-block-start' => 0.5, 'margin-block-end' => 0.5,
            'margin-inline-start' => 0.5, 'margin-inline-end' => 0.5,
            'width' => 0, 'max-width' => 0, 'gap' => 0.5,
        ];

        $result = $this->invokeScaleProps( $desktop_props, $mobile_scales );

        $this->assertArrayHasKey( 'font-size', $result );
        $this->assertEquals( 25.6, $result['font-size']['value']['size'] );
    }

    public function test_scale_props_converts_width_to_100_percent(): void
    {
        $desktop_props = [
            'width' => ['$$type' => 'size', 'value' => ['size' => 800, 'unit' => 'px']],
        ];

        // Scale 0 means width → 100%.
        $scales = ['width' => 0, 'max-width' => 0];

        $result = $this->invokeScaleProps( $desktop_props, $scales );

        $this->assertArrayHasKey( 'width', $result );
        $this->assertEquals( 100, $result['width']['value']['size'] );
        $this->assertEquals( '%', $result['width']['value']['unit'] );
    }

    public function test_scale_props_converts_max_width_to_100_percent(): void
    {
        $desktop_props = [
            'max-width' => ['$$type' => 'size', 'value' => ['size' => 1200, 'unit' => 'px']],
        ];

        $scales = ['width' => 0, 'max-width' => 0];

        $result = $this->invokeScaleProps( $desktop_props, $scales );

        $this->assertArrayHasKey( 'max-width', $result );
        $this->assertEquals( 100, $result['max-width']['value']['size'] );
        $this->assertEquals( '%', $result['max-width']['value']['unit'] );
    }

    public function test_scale_props_scales_padding(): void
    {
        $desktop_props = [
            'padding-block-start'  => ['$$type' => 'size', 'value' => ['size' => 60, 'unit' => 'px']],
            'padding-block-end'    => ['$$type' => 'size', 'value' => ['size' => 60, 'unit' => 'px']],
            'padding-inline-start' => ['$$type' => 'size', 'value' => ['size' => 40, 'unit' => 'px']],
            'padding-inline-end'   => ['$$type' => 'size', 'value' => ['size' => 40, 'unit' => 'px']],
        ];

        $mobile_scales = [
            'font-size' => 0.8, 'padding-block-start' => 0.5, 'padding-block-end' => 0.5,
            'padding-inline-start' => 0.5, 'padding-inline-end' => 0.5,
            'margin-block-start' => 0.5, 'margin-block-end' => 0.5,
            'margin-inline-start' => 0.5, 'margin-inline-end' => 0.5,
            'width' => 0, 'max-width' => 0, 'gap' => 0.5,
        ];

        $result = $this->invokeScaleProps( $desktop_props, $mobile_scales );

        $this->assertEquals( 30.0, $result['padding-block-start']['value']['size'] );
        $this->assertEquals( 30.0, $result['padding-block-end']['value']['size'] );
        $this->assertEquals( 20.0, $result['padding-inline-start']['value']['size'] );
        $this->assertEquals( 20.0, $result['padding-inline-end']['value']['size'] );
    }

    public function test_scale_props_scales_gap(): void
    {
        $desktop_props = [
            'gap' => ['$$type' => 'size', 'value' => ['size' => 40, 'unit' => 'px']],
        ];

        $tablet_scales = [
            'font-size' => 0.9, 'padding-block-start' => 0.75, 'padding-block-end' => 0.75,
            'padding-inline-start' => 0.75, 'padding-inline-end' => 0.75,
            'margin-block-start' => 0.75, 'margin-block-end' => 0.75,
            'margin-inline-start' => 0.75, 'margin-inline-end' => 0.75,
            'width' => 0, 'max-width' => 0, 'gap' => 0.75,
        ];

        $result = $this->invokeScaleProps( $desktop_props, $tablet_scales );

        $this->assertArrayHasKey( 'gap', $result );
        $this->assertEquals( 30.0, $result['gap']['value']['size'] );
    }

    public function test_scale_props_skips_string_type_props(): void
    {
        // A prop that IS in the scale map but has $$type = 'string'
        // (e.g., gap with calc() value) should be skipped.
        $desktop_props = [
            'gap' => ['$$type' => 'string', 'value' => 'calc(24px + 2vw)'],
        ];

        $scales = ['gap' => 0.75];

        $result = $this->invokeScaleProps( $desktop_props, $scales );

        // gap should be skipped because it's string type, not size.
        $this->assertArrayNotHasKey( 'gap', $result );
    }

    public function test_scale_props_skips_props_not_in_scale_map(): void
    {
        $desktop_props = [
            'font-size' => ['$$type' => 'size', 'value' => ['size' => 24, 'unit' => 'px']],
            'border-width' => ['$$type' => 'size', 'value' => ['size' => 2, 'unit' => 'px']],
        ];

        $scales = ['font-size' => 0.8];

        $result = $this->invokeScaleProps( $desktop_props, $scales );

        $this->assertArrayHasKey( 'font-size', $result );
        $this->assertArrayNotHasKey( 'border-width', $result, 'Props not in scale map should be skipped' );
    }

    public function test_scale_props_rounds_to_one_decimal(): void
    {
        $desktop_props = [
            'font-size' => ['$$type' => 'size', 'value' => ['size' => 47, 'unit' => 'px']],
        ];

        // 0.75 scale produces 47 × 0.75 = 35.25, rounds to 35.3.
        $scales = ['font-size' => 0.75];

        $result = $this->invokeScaleProps( $desktop_props, $scales );

        $this->assertEquals( 35.3, $result['font-size']['value']['size'] );
    }

    // -----------------------------------------------------------------
    // Reflection helpers for private methods
    // -----------------------------------------------------------------

    /**
     * Invoke the private process_style_variants method via reflection.
     */
    private function invokeProcessStyleVariants( array &$style_def, int &$fixes ): void
    {
        $method = new ReflectionMethod(
            Conversion_AutoFixer::class,
            'process_style_variants'
        );
        $method->invokeArgs( null, [ &$style_def, &$fixes ] );
    }

    /**
     * Invoke the private clean_mobile_overrides method via reflection.
     */
    private function invokeCleanMobileOverrides( array &$style_def, int &$fixes ): void
    {
        $method = new ReflectionMethod(
            Conversion_AutoFixer::class,
            'clean_mobile_overrides'
        );
        $method->invokeArgs( null, [ &$style_def, &$fixes ] );
    }

    /**
     * Invoke the private scale_props method via reflection.
     */
    private function invokeScaleProps( array $props, array $scales ): array
    {
        $method = new ReflectionMethod(
            Conversion_AutoFixer::class,
            'scale_props'
        );
        return $method->invokeArgs( null, [ $props, $scales ] );
    }
}
