<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\Conversion_Auditor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Conversion_Auditor — validates layout, class, and responsive audits.
 *
 * @covers \Novamira\AdrianV2\Helpers\Conversion_Auditor
 */
final class ConversionAuditorTest extends TestCase
{
    // -----------------------------------------------------------------
    // Empty tree
    // -----------------------------------------------------------------

    public function test_empty_tree_produces_no_issues(): void
    {
        $issues = Conversion_Auditor::audit( [] );

        $this->assertEmpty( $issues );
    }

    // -----------------------------------------------------------------
    // Layout: Empty container
    // -----------------------------------------------------------------

    public function test_empty_container_without_styling_is_flagged(): void
    {
        $tree = [
            [
                'id'       => 'aaaaaaa',
                'elType'   => 'e-flexbox',
                'settings' => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $layout_warnings = Conversion_Auditor::filter( $issues, 'layout', 'warning' );
        $this->assertCount( 1, $layout_warnings );
        $this->assertStringContainsString( 'Empty', $layout_warnings[0]['message'] );
        $this->assertStringContainsString( 'e-flexbox', $layout_warnings[0]['message'] );
        $this->assertEquals( 'aaaaaaa', $layout_warnings[0]['element_id'] );
    }

    public function test_empty_container_with_padding_is_not_flagged(): void
    {
        $tree = [
            [
                'id'       => 'bbbbbbb',
                'elType'   => 'e-flexbox',
                'settings' => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                    'padding' => [ 'top' => 10, 'bottom' => 10, 'left' => 0, 'right' => 0, 'unit' => 'px' ],
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $layout_warnings = Conversion_Auditor::filter( $issues, 'layout', 'warning' );
        $this->assertEmpty( $layout_warnings, 'Container with visual padding should not be flagged as empty' );
    }

    // -----------------------------------------------------------------
    // Layout: missing widget content
    // -----------------------------------------------------------------

    public function test_heading_without_title_is_flagged(): void
    {
        $tree = [
            [
                'id'         => 'h-empty',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $content_warnings = Conversion_Auditor::filter( $issues, 'layout', 'warning' );
        $this->assertCount( 1, $content_warnings );
        $this->assertStringContainsString( 'no', $content_warnings[0]['message'] );
        $this->assertStringContainsString( 'title', $content_warnings[0]['message'] );
    }

    public function test_button_without_text_is_flagged(): void
    {
        $tree = [
            [
                'id'         => 'b-empty',
                'elType'     => 'widget',
                'widgetType' => 'e-button',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $content_warnings = Conversion_Auditor::filter( $issues, 'layout', 'warning' );
        $this->assertCount( 1, $content_warnings );
        $this->assertStringContainsString( 'e-button', $content_warnings[0]['message'] );
    }

    public function test_heading_with_title_is_not_flagged(): void
    {
        $tree = [
            [
                'id'         => 'h-ok',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                    'title'   => 'Hello World',
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $all = Conversion_Auditor::filter( $issues, 'layout' );
        $this->assertEmpty( $all );
    }

    // -----------------------------------------------------------------
    // Layout: image without source
    // -----------------------------------------------------------------

    public function test_image_without_source_is_flagged(): void
    {
        $tree = [
            [
                'id'         => 'img-empty',
                'elType'     => 'widget',
                'widgetType' => 'e-image',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $image_errors = Conversion_Auditor::filter( $issues, 'layout', 'error' );
        $this->assertCount( 1, $image_errors );
        $this->assertStringContainsString( 'e-image', $image_errors[0]['message'] );
        $this->assertStringContainsString( 'no image source', $image_errors[0]['message'] );
    }

    public function test_image_with_id_is_not_flagged(): void
    {
        $tree = [
            [
                'id'         => 'img-ok',
                'elType'     => 'widget',
                'widgetType' => 'e-image',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                    'image'   => [ 'id' => 42 ],
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $image_errors = Conversion_Auditor::filter( $issues, 'layout', 'error' );
        $this->assertEmpty( $image_errors );
    }

    // -----------------------------------------------------------------
    // Layout: excessive nesting
    // -----------------------------------------------------------------

    public function test_deeply_nested_element_is_flagged(): void
    {
        // Build a tree nested 7 deep.
        $tree = [];
        $current = &$tree;
        for ( $i = 0; $i < 7; $i++ ) {
            $current[0] = [
                'id'       => 'd' . $i,
                'elType'   => 'e-flexbox',
                'settings' => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                    'padding' => [ 'top' => 10, 'bottom' => 10, 'left' => 0, 'right' => 0, 'unit' => 'px' ],
                ],
                'elements' => [],
                'styles'   => [],
            ];
            $current = &$current[0]['elements'];
        }

        $issues = Conversion_Auditor::audit( $tree );

        $nesting_info = Conversion_Auditor::filter( $issues, 'layout', 'info' );
        $this->assertGreaterThan( 0, count( $nesting_info ), 'Deeply nested elements should produce info-level issues' );
        $this->assertStringContainsString( 'nested', $nesting_info[ count( $nesting_info ) - 1 ]['message'] );
    }

    // -----------------------------------------------------------------
    // Layout: section with direct widget children
    // -----------------------------------------------------------------

    public function test_e_flexbox_with_direct_widget_children_is_flagged(): void
    {
        $tree = [
            [
                'id'       => 'sec-direct',
                'elType'   => 'e-flexbox',
                'settings' => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                ],
                'elements' => [
                    [
                        'id'         => 'w1',
                        'elType'     => 'widget',
                        'widgetType' => 'e-heading',
                        'settings'   => [
                            'classes' => [ '$$type' => 'classes', 'value' => [] ],
                            'title'   => 'Direct widget',
                        ],
                        'elements' => [],
                        'styles'   => [],
                    ],
                ],
                'styles' => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $layout_errors = Conversion_Auditor::filter( $issues, 'layout', 'error' );
        $this->assertCount( 1, $layout_errors );
        $this->assertStringContainsString( 'direct widget', $layout_errors[0]['message'] );
        $this->assertEquals( 'sec-direct', $layout_errors[0]['element_id'] );
    }

    public function test_e_flexbox_with_container_children_is_not_flagged(): void
    {
        $tree = [
            [
                'id'       => 'sec-ok',
                'elType'   => 'e-flexbox',
                'settings' => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                ],
                'elements' => [
                    [
                        'id'       => 'col1',
                        'elType'   => 'e-div-block',
                        'settings' => [
                            'classes' => [ '$$type' => 'classes', 'value' => [] ],
                        ],
                        'elements' => [
                            [
                                'id'         => 'w1',
                                'elType'     => 'widget',
                                'widgetType' => 'e-heading',
                                'settings'   => [
                                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                                    'title'   => 'Nested widget',
                                ],
                                'elements' => [],
                                'styles'   => [],
                            ],
                        ],
                        'styles' => [],
                    ],
                ],
                'styles' => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $layout_errors = Conversion_Auditor::filter( $issues, 'layout', 'error' );
        $this->assertEmpty( $layout_errors, 'e-flexbox with e-div-block children should not be flagged' );
    }

    // -----------------------------------------------------------------
    // Class Audit: dangling references
    // -----------------------------------------------------------------

    public function test_dangling_class_reference_is_flagged(): void
    {
        // Element references a class ID that is not defined in any styles map.
        $tree = [
            [
                'id'         => 'el-refs',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'gc-missing-class' ] ],
                    'title'   => 'Has dangling ref',
                ],
                'elements' => [],
                'styles'   => [],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $class_errors = Conversion_Auditor::filter( $issues, 'class', 'error' );
        $this->assertCount( 1, $class_errors );
        $this->assertStringContainsString( 'gc-missing-class', $class_errors[0]['message'] );
        $this->assertStringContainsString( 'not defined', $class_errors[0]['message'] );
    }

    // -----------------------------------------------------------------
    // Class Audit: orphan styles
    // -----------------------------------------------------------------

    public function test_orphan_style_is_flagged(): void
    {
        // Element has a styles map entry, but no element references that class ID.
        $tree = [
            [
                'id'         => 'el-orphan',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [] ],
                    'title'   => 'Has orphan style',
                ],
                'elements' => [],
                'styles'   => [
                    'e-orphan-abc' => [
                        'id'       => 'e-orphan-abc',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props'      => [
                                    'color' => [ '$$type' => 'string', 'value' => '#ff0000' ],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $orphan_warnings = Conversion_Auditor::filter( $issues, 'class', 'warning' );
        $this->assertCount( 1, $orphan_warnings );
        $this->assertStringContainsString( 'e-orphan-abc', $orphan_warnings[0]['message'] );
        $this->assertStringContainsString( 'orphan', $orphan_warnings[0]['message'] );
    }

    // -----------------------------------------------------------------
    // Class Audit: duplicate style definitions
    // -----------------------------------------------------------------

    public function test_duplicate_style_definition_is_flagged(): void
    {
        // Two elements define the same class ID in their styles map.
        $tree = [
            [
                'id'         => 'el-a',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'e-shared-class' ] ],
                    'title'   => 'Element A',
                ],
                'elements' => [],
                'styles'   => [
                    'e-shared-class' => [
                        'id'       => 'e-shared-class',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'  => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props' => [ 'color' => [ '$$type' => 'string', 'value' => '#ff0000' ] ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'         => 'el-b',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'e-shared-class' ] ],
                    'title'   => 'Element B',
                ],
                'elements' => [],
                'styles'   => [
                    'e-shared-class' => [
                        'id'       => 'e-shared-class',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'  => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props' => [ 'color' => [ '$$type' => 'string', 'value' => '#0000ff' ] ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $dup_errors = Conversion_Auditor::filter( $issues, 'class', 'error' );
        $this->assertCount( 1, $dup_errors );
        $this->assertStringContainsString( 'e-shared-class', $dup_errors[0]['message'] );
        $this->assertStringContainsString( 'multiple times', $dup_errors[0]['message'] );
    }

    // -----------------------------------------------------------------
    // Responsive Audit: desktop-only important props
    // -----------------------------------------------------------------

    public function test_desktop_only_font_size_without_mobile_is_flagged(): void
    {
        $tree = [
            [
                'id'         => 'el-font',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'e-font-local' ] ],
                    'title'   => 'Desktop only',
                ],
                'elements' => [],
                'styles'   => [
                    'e-font-local' => [
                        'id'       => 'e-font-local',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props'      => [
                                    'font-size' => [ '$$type' => 'size', 'value' => [ 'size' => 48, 'unit' => 'px' ] ],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $resp_warnings = Conversion_Auditor::filter( $issues, 'responsive', 'warning' );
        $this->assertCount( 1, $resp_warnings );
        $this->assertStringContainsString( 'no mobile breakpoint', $resp_warnings[0]['message'] );
        $this->assertStringContainsString( 'font-size', $resp_warnings[0]['message'] );
    }

    public function test_style_with_mobile_variant_is_not_flagged(): void
    {
        $tree = [
            [
                'id'         => 'el-full',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'e-full-local' ] ],
                    'title'   => 'Fully responsive',
                ],
                'elements' => [],
                'styles'   => [
                    'e-full-local' => [
                        'id'       => 'e-full-local',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props'      => [
                                    'font-size' => [ '$$type' => 'size', 'value' => [ 'size' => 48, 'unit' => 'px' ] ],
                                ],
                                'custom_css' => null,
                            ],
                            [
                                'meta'       => [ 'breakpoint' => 'mobile', 'state' => null ],
                                'props'      => [
                                    'font-size' => [ '$$type' => 'size', 'value' => [ 'size' => 24, 'unit' => 'px' ] ],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $resp_warnings = Conversion_Auditor::filter( $issues, 'responsive', 'warning' );
        $this->assertEmpty( $resp_warnings, 'Style with mobile variant should not produce warnings' );
    }

    // -----------------------------------------------------------------
    // Responsive Audit: identical mobile overrides
    // -----------------------------------------------------------------

    public function test_identical_mobile_override_is_flagged_as_info(): void
    {
        $tree = [
            [
                'id'         => 'el-ident',
                'elType'     => 'widget',
                'widgetType' => 'e-heading',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'e-ident-local' ] ],
                    'title'   => 'Same values',
                ],
                'elements' => [],
                'styles'   => [
                    'e-ident-local' => [
                        'id'       => 'e-ident-local',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props'      => [
                                    'padding-block-start' => [ '$$type' => 'size', 'value' => [ 'size' => 20, 'unit' => 'px' ] ],
                                ],
                                'custom_css' => null,
                            ],
                            [
                                'meta'       => [ 'breakpoint' => 'mobile', 'state' => null ],
                                'props'      => [
                                    'padding-block-start' => [ '$$type' => 'size', 'value' => [ 'size' => 20, 'unit' => 'px' ] ],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $info = Conversion_Auditor::filter( $issues, 'responsive', 'info' );
        $this->assertCount( 1, $info );
        $this->assertStringContainsString( 'identical', $info[0]['message'] );
        $this->assertStringContainsString( 'redundant', $info[0]['message'] );
    }

    // -----------------------------------------------------------------
    // Responsive Audit: fixed px width without responsive
    // -----------------------------------------------------------------

    public function test_fixed_px_width_without_responsive_is_flagged(): void
    {
        $tree = [
            [
                'id'         => 'el-wide',
                'elType'     => 'widget',
                'widgetType' => 'e-image',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'e-wide-local' ] ],
                    'image'   => [ 'id' => 1 ],
                ],
                'elements' => [],
                'styles'   => [
                    'e-wide-local' => [
                        'id'       => 'e-wide-local',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props'      => [
                                    'width' => [ '$$type' => 'size', 'value' => [ 'size' => 800, 'unit' => 'px' ] ],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $resp_warnings = Conversion_Auditor::filter( $issues, 'responsive', 'warning' );
        $this->assertGreaterThanOrEqual( 1, count( $resp_warnings ),
            'Fixed 800px width without responsive alternative should produce a warning' );
        $has_width_warning = false;
        foreach ( $resp_warnings as $w ) {
            if ( str_contains( $w['message'], 'width' ) && str_contains( $w['message'], 'overflow' ) ) {
                $has_width_warning = true;
            }
        }
        $this->assertTrue( $has_width_warning, 'Should contain a warning about fixed width overflow' );
    }

    public function test_small_px_width_is_not_flagged(): void
    {
        // 200px width is below the 300px threshold, so no warning.
        $tree = [
            [
                'id'         => 'el-narrow',
                'elType'     => 'widget',
                'widgetType' => 'e-image',
                'settings'   => [
                    'classes' => [ '$$type' => 'classes', 'value' => [ 'e-narrow-local' ] ],
                    'image'   => [ 'id' => 2 ],
                ],
                'elements' => [],
                'styles'   => [
                    'e-narrow-local' => [
                        'id'       => 'e-narrow-local',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props'      => [
                                    'width' => [ '$$type' => 'size', 'value' => [ 'size' => 200, 'unit' => 'px' ] ],
                                ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $issues = Conversion_Auditor::audit( $tree );

        $all = Conversion_Auditor::filter( $issues, 'responsive' );
        foreach ( $all as $issue ) {
            $this->assertStringNotContainsString( 'overflow', $issue['message'],
                'Small fixed width should not trigger overflow warning' );
        }
    }

    // -----------------------------------------------------------------
    // filter() helper
    // -----------------------------------------------------------------

    public function test_filter_by_type(): void
    {
        $issues = [
            [ 'type' => 'layout', 'severity' => 'error', 'element_id' => 'a', 'message' => 'Layout error' ],
            [ 'type' => 'class', 'severity' => 'error', 'element_id' => 'b', 'message' => 'Class error' ],
            [ 'type' => 'responsive', 'severity' => 'warning', 'element_id' => 'c', 'message' => 'Resp warning' ],
        ];

        $layout = Conversion_Auditor::filter( $issues, 'layout' );
        $this->assertCount( 1, $layout );
        $this->assertEquals( 'Layout error', $layout[0]['message'] );

        $class = Conversion_Auditor::filter( $issues, 'class' );
        $this->assertCount( 1, $class );
        $this->assertEquals( 'Class error', $class[0]['message'] );
    }

    public function test_filter_by_severity(): void
    {
        $issues = [
            [ 'type' => 'layout', 'severity' => 'error', 'element_id' => 'a', 'message' => 'Error' ],
            [ 'type' => 'layout', 'severity' => 'warning', 'element_id' => 'b', 'message' => 'Warning' ],
            [ 'type' => 'layout', 'severity' => 'info', 'element_id' => 'c', 'message' => 'Info' ],
        ];

        $errors = Conversion_Auditor::filter( $issues, null, 'error' );
        $this->assertCount( 1, $errors );

        $warnings = Conversion_Auditor::filter( $issues, null, 'warning' );
        $this->assertCount( 1, $warnings );

        $infos = Conversion_Auditor::filter( $issues, null, 'info' );
        $this->assertCount( 1, $infos );
    }

    public function test_filter_combined(): void
    {
        $issues = [
            [ 'type' => 'layout', 'severity' => 'error', 'element_id' => 'a', 'message' => 'Layout error' ],
            [ 'type' => 'layout', 'severity' => 'warning', 'element_id' => 'b', 'message' => 'Layout warning' ],
            [ 'type' => 'class', 'severity' => 'error', 'element_id' => 'c', 'message' => 'Class error' ],
        ];

        $layout_errors = Conversion_Auditor::filter( $issues, 'layout', 'error' );
        $this->assertCount( 1, $layout_errors );
        $this->assertEquals( 'Layout error', $layout_errors[0]['message'] );
    }

    // -----------------------------------------------------------------
    // to_warnings() helper
    // -----------------------------------------------------------------

    public function test_to_warnings_extracts_errors_and_warnings(): void
    {
        $issues = [
            [ 'type' => 'layout', 'severity' => 'error', 'element_id' => 'el1', 'message' => 'Broken layout' ],
            [ 'type' => 'class', 'severity' => 'warning', 'element_id' => 'el2', 'message' => 'Orphan style' ],
            [ 'type' => 'responsive', 'severity' => 'info', 'element_id' => 'el3', 'message' => 'Redundant override' ],
        ];

        $warnings = Conversion_Auditor::to_warnings( $issues );

        $this->assertCount( 2, $warnings, 'Should include error and warning, but not info' );
        $this->assertStringContainsString( '[audit:layout:error]', $warnings[0] );
        $this->assertStringContainsString( 'Broken layout', $warnings[0] );
        $this->assertStringContainsString( 'el1', $warnings[0] );

        $this->assertStringContainsString( '[audit:class:warning]', $warnings[1] );
        $this->assertStringContainsString( 'Orphan style', $warnings[1] );
        $this->assertStringContainsString( 'el2', $warnings[1] );
    }

    public function test_to_warnings_empty(): void
    {
        $warnings = Conversion_Auditor::to_warnings( [] );
        $this->assertEmpty( $warnings );
    }

    // -----------------------------------------------------------------
    // deep_collect_styles
    // -----------------------------------------------------------------

    public function test_deep_collect_styles_collects_all(): void
    {
        $tree = [
            [
                'id'       => 'parent',
                'elType'   => 'e-flexbox',
                'settings' => [ 'classes' => [ '$$type' => 'classes', 'value' => [] ] ],
                'elements' => [
                    [
                        'id'         => 'child',
                        'elType'     => 'widget',
                        'widgetType' => 'e-heading',
                        'settings'   => [
                            'classes' => [ '$$type' => 'classes', 'value' => [ 'e-child-class' ] ],
                            'title'   => 'Child',
                        ],
                        'elements' => [],
                        'styles'   => [
                            'e-child-class' => [
                                'id'       => 'e-child-class',
                                'label'    => 'local',
                                'type'     => 'class',
                                'variants' => [
                                    [
                                        'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                        'props'      => [ 'color' => [ '$$type' => 'string', 'value' => '#000' ] ],
                                        'custom_css' => null,
                                    ],
                                    [
                                        'meta'       => [ 'breakpoint' => 'mobile', 'state' => null ],
                                        'props'      => [ 'font-size' => [ '$$type' => 'size', 'value' => [ 'size' => 14, 'unit' => 'px' ] ] ],
                                        'custom_css' => null,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'styles' => [
                    'e-parent-class' => [
                        'id'       => 'e-parent-class',
                        'label'    => 'local',
                        'type'     => 'class',
                        'variants' => [
                            [
                                'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                'props'      => [ 'background-color' => [ '$$type' => 'string', 'value' => '#f0f0f0' ] ],
                                'custom_css' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $collected = Conversion_Auditor::deep_collect_styles( $tree );

        $this->assertCount( 2, $collected );
        $this->assertArrayHasKey( 'e-parent-class', $collected );
        $this->assertArrayHasKey( 'e-child-class', $collected );
        $this->assertEquals( 'parent', $collected['e-parent-class']['element_id'] );
        $this->assertEquals( 'child', $collected['e-child-class']['element_id'] );
        $this->assertCount( 1, $collected['e-parent-class']['variants'] );
        $this->assertCount( 2, $collected['e-child-class']['variants'] );
    }

    public function test_deep_collect_styles_empty_tree(): void
    {
        $collected = Conversion_Auditor::deep_collect_styles( [] );
        $this->assertEmpty( $collected );
    }

    // -----------------------------------------------------------------
    // Combined: all three audit types in one tree
    // -----------------------------------------------------------------

    public function test_all_three_audits_on_complex_tree(): void
    {
        $tree = [
            // Layout error: e-flexbox with direct widget children.
            [
                'id'       => 'sec-bad',
                'elType'   => 'e-flexbox',
                'settings' => [ 'classes' => [ '$$type' => 'classes', 'value' => [] ] ],
                'elements' => [
                    [
                        'id'         => 'w-empty',
                        'elType'     => 'widget',
                        'widgetType' => 'e-button',
                        'settings'   => [
                            'classes' => [ '$$type' => 'classes', 'value' => [ 'e-btn-local' ] ],
                            // No 'text' — missing content warning.
                        ],
                        'elements' => [],
                        'styles'   => [
                            'e-btn-local' => [
                                'id'       => 'e-btn-local',
                                'label'    => 'local',
                                'type'     => 'class',
                                'variants' => [
                                    [
                                        'meta'       => [ 'breakpoint' => 'desktop', 'state' => null ],
                                        'props'      => [
                                            'font-size' => [ '$$type' => 'size', 'value' => [ 'size' => 16, 'unit' => 'px' ] ],
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

        $issues = Conversion_Auditor::audit( $tree );

        $by_type = [
            'layout'    => count( Conversion_Auditor::filter( $issues, 'layout' ) ),
            'class'     => count( Conversion_Auditor::filter( $issues, 'class' ) ),
            'responsive' => count( Conversion_Auditor::filter( $issues, 'responsive' ) ),
        ];

        // Layout: direct widgets in flexbox (error) + missing button text (warning)
        $this->assertEquals( 2, $by_type['layout'] );
        // Class: should be 0 (no dangling/orphan/duplicate)
        $this->assertEquals( 0, $by_type['class'] );
        // Responsive: desktop font-size without mobile variant (warning)
        $this->assertEquals( 1, $by_type['responsive'] );

        $this->assertCount( 3, $issues );
    }
}
