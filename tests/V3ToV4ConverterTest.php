<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\V3_To_V4_Converter;
use Novamira\AdrianV2\Helpers\V4_Props;
use PHPUnit\Framework\TestCase;

/**
 * Tests for V3_To_V4_Converter — validates widget/container/style conversion.
 *
 * @covers \Novamira\AdrianV2\Helpers\V3_To_V4_Converter
 */
final class V3ToV4ConverterTest extends TestCase
{
    // -----------------------------------------------------------------
    // Heading → e-heading
    // -----------------------------------------------------------------

    public function test_heading_minimal(): void
    {
        $elements = [
            [
                'id'         => 'abc123',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => [
                    'title' => 'Hello World',
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'widget', $result[0]['elType'] );
        $this->assertEquals( 'e-heading', $result[0]['widgetType'] );
        $this->assertEquals( 'Hello World', V4_Props::unwrap( $result[0]['settings']['title'] ) );
        $this->assertIsArray( $result[0]['settings']['classes'] );
        $this->assertEquals( 'classes', $result[0]['settings']['classes']['$$type'] );

        // Stats
        $this->assertEquals( 1, $stats['elements_read'] );
        $this->assertEquals( 1, $stats['converted'] );
        $this->assertEmpty( $warnings );
    }

    public function test_heading_with_header_size(): void
    {
        $elements = [
            [
                'id'         => 'h1',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => [
                    'title'       => 'Big Title',
                    'header_size' => 'h2',
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertEquals( 'Big Title', V4_Props::unwrap( $result[0]['settings']['title'] ) );
		$this->assertEquals( 'h2', $result[0]['settings']['tag'] );
    }

    public function test_heading_with_typography_and_color_styles(): void
    {
        $elements = [
            [
                'id'         => 'styled-heading',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => [
                    'title'       => 'Styled Heading',
                    'header_size' => 'h1',
                    'title_color' => '#333333',
                    'typography_typography' => [
                        'typography_font_family' => 'Poppins',
                        'typography_font_weight' => '700',
                        'typography_font_size'   => ['size' => 32, 'unit' => 'px'],
                        'typography_line_height' => ['size' => 1.2, 'unit' => 'em'],
                        'typography_text_transform' => 'uppercase',
                    ],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'e-heading', $result[0]['widgetType'] );

        // Styles should be present.
        $this->assertArrayHasKey( 'styles', $result[0] );
        $this->assertNotEmpty( $result[0]['styles'] );

        // Get the first style class.
        $style_class = array_values( $result[0]['styles'] )[0];
        $this->assertEquals( 'local', $style_class['label'] );
        $this->assertEquals( 'class', $style_class['type'] );

        // At least one variant (desktop).
        $this->assertNotEmpty( $style_class['variants'] );
        $desktop = $style_class['variants'][0];
        $this->assertEquals( 'desktop', $desktop['meta']['breakpoint'] );
        $this->assertNull( $desktop['meta']['state'] );

        $props = $desktop['props'];
        $this->assertArrayHasKey( 'color', $props );
        $this->assertArrayHasKey( 'font-family', $props );
        $this->assertArrayHasKey( 'font-weight', $props );
        $this->assertArrayHasKey( 'font-size', $props );
        $this->assertArrayHasKey( 'line-height', $props );
        $this->assertArrayHasKey( 'text-transform', $props );

        $this->assertEquals( 'string', $props['color']['$$type'] );
        $this->assertEquals( '#333333', $props['color']['value'] );
        $this->assertEquals( 'Poppins', $props['font-family']['value'] );
        $this->assertEquals( '700', $props['font-weight']['value'] );
        $this->assertEquals( 'uppercase', $props['text-transform']['value'] );
    }

    // -----------------------------------------------------------------
    // text-editor → e-paragraph
    // -----------------------------------------------------------------

    public function test_text_editor_basic(): void
    {
        $elements = [
            [
                'id'         => 'txt1',
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => [
                    'editor' => '<p>Lorem ipsum dolor sit amet.</p>',
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'widget', $result[0]['elType'] );
        $this->assertEquals( 'e-paragraph', $result[0]['widgetType'] );
        $this->assertArrayHasKey( 'paragraph', $result[0]['settings'] );
        $this->assertArrayNotHasKey( 'text', $result[0]['settings'] );
        $this->assertEquals( 'Lorem ipsum dolor sit amet.', V4_Props::unwrap( $result[0]['settings']['paragraph'] ) );
        $this->assertEquals( 1, $stats['converted'] );
    }

    public function test_text_editor_plain_text(): void
    {
        $elements = [
            [
                'id'         => 'txt2',
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => [
                    'editor' => 'Plain text without wrapper',
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertEquals( 'Plain text without wrapper', V4_Props::unwrap( $result[0]['settings']['paragraph'] ) );
    }

    public function test_text_editor_with_styles(): void
    {
        $elements = [
            [
                'id'         => 'txt3',
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => [
                    'editor'     => '<p>Styled text</p>',
                    'text_color' => '#444444',
                    'text_align' => 'left',
                    'typography_typography' => [
                        'typography_font_family' => 'Poppins',
                        'typography_font_size'   => ['size' => 16, 'unit' => 'px'],
                    ],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertArrayHasKey( 'styles', $result[0] );
        $this->assertNotEmpty( $result[0]['styles'] );

        $style_class = array_values( $result[0]['styles'] )[0];
        $this->assertNotEmpty( $style_class['variants'] );

        $props = $style_class['variants'][0]['props'];
        $this->assertArrayHasKey( 'color', $props );
        $this->assertArrayHasKey( 'text-align', $props );
        $this->assertEquals( '#444444', $props['color']['value'] );
        $this->assertEquals( 'left', $props['text-align']['value'] );
    }

    // -----------------------------------------------------------------
    // button → e-button
    // -----------------------------------------------------------------

    public function test_button_basic(): void
    {
        $elements = [
            [
                'id'         => 'btn1',
                'elType'     => 'widget',
                'widgetType' => 'button',
                'settings'   => [
                    'text' => 'Click Me',
                    'link' => ['url' => 'https://example.com'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'e-button', $result[0]['widgetType'] );
        $this->assertEquals( 'Click Me', V4_Props::unwrap( $result[0]['settings']['text'] ) );
        $this->assertArrayHasKey( 'link', $result[0]['settings'] );
        $this->assertArrayNotHasKey( 'background-color', $result[0]['settings'],
            'background-color should only be in styles, not inline' );
    }

    public function test_button_with_colors_and_border(): void
    {
        $elements = [
            [
                'id'         => 'btn2',
                'elType'     => 'widget',
                'widgetType' => 'button',
                'settings'   => [
                    'text'                     => 'Styled Button',
                    'button_background_color'  => '#0066cc',
                    'button_text_color'        => '#ffffff',
                    'button_border_border'     => 'solid',
                    'button_border_width'      => ['top' => 2, 'right' => 2, 'bottom' => 2, 'left' => 2, 'unit' => 'px'],
                    'button_border_color'      => '#004499',
                    'button_border_radius'     => ['top' => 8, 'right' => 8, 'bottom' => 8, 'left' => 8, 'unit' => 'px'],
                    'typography_typography'    => [
                        'typography_font_weight' => '600',
                    ],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertArrayHasKey( 'styles', $result[0] );
        $style_class = array_values( $result[0]['styles'] )[0];
        $this->assertNotEmpty( $style_class['variants'] );

        $props = $style_class['variants'][0]['props'];

        $this->assertArrayHasKey( 'background-color', $props );
        $this->assertArrayHasKey( 'color', $props );
        $this->assertArrayHasKey( 'border-style', $props );
        $this->assertArrayHasKey( 'border-width', $props );
        $this->assertArrayHasKey( 'border-color', $props );
        $this->assertArrayHasKey( 'border-radius', $props );
        $this->assertArrayHasKey( 'font-weight', $props );

        // Verify no inline color leakage.
        $this->assertArrayNotHasKey( 'background-color', $result[0]['settings'] );
        $this->assertArrayNotHasKey( 'color', $result[0]['settings'] );
    }

    // -----------------------------------------------------------------
    // image → e-image
    // -----------------------------------------------------------------

    public function test_image_with_attachment_id(): void
    {
        $elements = [
            [
                'id'         => 'img1',
                'elType'     => 'widget',
                'widgetType' => 'image',
                'settings'   => [
                    'image' => ['id' => 42, 'url' => 'https://example.com/img.jpg'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'e-image', $result[0]['widgetType'] );
        $image = V4_Props::unwrap( $result[0]['settings']['image'] );
        $this->assertEquals( 42, $image['id'] );
        $this->assertArrayNotHasKey( 'url', $result[0]['settings']['image']['value']['src'],
            'url should be omitted when id is set (V4 invariant)' );
    }

    public function test_image_url_fallback(): void
    {
        $elements = [
            [
                'id'         => 'img2',
                'elType'     => 'widget',
                'widgetType' => 'image',
                'settings'   => [
                    'image' => ['url' => 'https://example.com/photo.png'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $image = V4_Props::unwrap( $result[0]['settings']['image'] );
        $this->assertEquals( 'https://example.com/photo.png', $image['url'] );
    }

    public function test_image_with_alignment_style(): void
    {
        $elements = [
            [
                'id'         => 'img3',
                'elType'     => 'widget',
                'widgetType' => 'image',
                'settings'   => [
                    'image'       => ['id' => 7],
                    'image_align' => 'center',
                    'width'       => ['size' => 300, 'unit' => 'px'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertArrayHasKey( 'styles', $result[0] );
        $style_class = array_values( $result[0]['styles'] )[0];
        $props       = $style_class['variants'][0]['props'];

        $this->assertArrayHasKey( 'text-align', $props );
        $this->assertEquals( 'center', $props['text-align']['value'] );
        $this->assertArrayHasKey( 'width', $props );
        $this->assertEquals( 300, $props['width']['value']['size'] );
    }

    // -----------------------------------------------------------------
    // section → e-flexbox with padding
    // -----------------------------------------------------------------

    public function test_section_with_padding(): void
    {
        $elements = [
            [
                'id'       => 'sec1',
                'elType'   => 'section',
                'settings' => [
                    'padding' => [
                        'top'    => 24,
                        'bottom' => 24,
                        'left'   => 16,
                        'right'  => 16,
                        'unit'   => 'px',
                    ],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'e-flexbox', $result[0]['elType'] );

        // Padding should be in settings.
        $this->assertArrayHasKey( 'padding', $result[0]['settings'] );
        $this->assertEquals( 24, $result[0]['settings']['padding']['block-start'] );
        $this->assertEquals( 24, $result[0]['settings']['padding']['block-end'] );
        $this->assertEquals( 16, $result[0]['settings']['padding']['inline-start'] );
        $this->assertEquals( 16, $result[0]['settings']['padding']['inline-end'] );
        $this->assertEquals( 'px', $result[0]['settings']['padding']['unit'] );

        // Section should have a unique generated ID.
        $this->assertIsString( $result[0]['id'] );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{7}$/', $result[0]['id'] );
    }

    public function test_section_with_background_and_border(): void
    {
        $elements = [
            [
                'id'       => 'sec2',
                'elType'   => 'section',
                'settings' => [
                    'background_color' => '#f0f0f0',
                    'border_border'    => 'solid',
                    'border_width'     => ['top' => 1, 'right' => 1, 'bottom' => 1, 'left' => 1, 'unit' => 'px'],
                    'border_color'     => '#dddddd',
                    'border_radius'    => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12, 'unit' => 'px'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertEquals( 'e-flexbox', $result[0]['elType'] );
        $this->assertArrayHasKey( 'styles', $result[0] );
        $this->assertNotEmpty( $result[0]['styles'] );

        $style_class = array_values( $result[0]['styles'] )[0];
        $props       = $style_class['variants'][0]['props'];

        $this->assertArrayHasKey( 'background-color', $props );
        $this->assertArrayHasKey( 'border-style', $props );
        $this->assertArrayHasKey( 'border-width', $props );
        $this->assertArrayHasKey( 'border-color', $props );
        $this->assertArrayHasKey( 'border-radius', $props );

        // Background-color should NOT be in settings (deduplication fix).
        $this->assertArrayNotHasKey( 'background-color', $result[0]['settings'] );
    }

    public function test_section_with_nested_children(): void
    {
        $elements = [
            [
                'id'       => 'sec-parent',
                'elType'   => 'section',
                'settings' => ['padding' => ['top' => 10, 'bottom' => 10, 'left' => 0, 'right' => 0, 'unit' => 'px']],
                'elements' => [
                    [
                        'id'         => 'child-heading',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => ['title' => 'Child Title'],
                        'elements'   => [],
                    ],
                ],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'e-flexbox', $result[0]['elType'] );
        $this->assertNotEmpty( $result[0]['elements'] );
        $this->assertEquals( 'e-div-block', $result[0]['elements'][0]['elType'] );
        $this->assertEquals( 'e-heading', $result[0]['elements'][0]['elements'][0]['widgetType'] );
        $this->assertEquals( 'Child Title', V4_Props::unwrap( $result[0]['elements'][0]['elements'][0]['settings']['title'] ) );
    }

    // -----------------------------------------------------------------
    // spacer → e-div-block
    // -----------------------------------------------------------------

    public function test_spacer_default_height(): void
    {
        $elements = [
            [
                'id'         => 'sp1',
                'elType'     => 'widget',
                'widgetType' => 'spacer',
                'settings'   => [
                    'space' => ['size' => 50, 'unit' => 'px'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( 'e-div-block', $result[0]['elType'] );
        $this->assertEquals( 50, $result[0]['settings']['padding']['block-start'] );
        $this->assertEquals( 50, $result[0]['settings']['padding']['block-end'] );
        $this->assertEquals( 0, $result[0]['settings']['padding']['inline-start'] );
        $this->assertEquals( 0, $result[0]['settings']['padding']['inline-end'] );
        $this->assertEquals( 'px', $result[0]['settings']['padding']['unit'] );
    }

    public function test_spacer_custom_height(): void
    {
        $elements = [
            [
                'id'         => 'sp2',
                'elType'     => 'widget',
                'widgetType' => 'spacer',
                'settings'   => [
                    'space' => ['size' => 120, 'unit' => 'px'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertEquals( 120, $result[0]['settings']['padding']['block-start'] );
        $this->assertEquals( 120, $result[0]['settings']['padding']['block-end'] );
    }

    public function test_spacer_with_responsive_overrides(): void
    {
        $elements = [
            [
                'id'         => 'sp-resp',
                'elType'     => 'widget',
                'widgetType' => 'spacer',
                'settings'   => [
                    'space'        => ['size' => 80, 'unit' => 'px'],
                    'space_tablet' => ['size' => 40, 'unit' => 'px'],
                    'space_mobile' => ['size' => 20, 'unit' => 'px'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertEquals( 80, $result[0]['settings']['padding']['block-start'] );

        // Styles should contain responsive variants.
        $this->assertArrayHasKey( 'styles', $result[0] );
        $style_class = array_values( $result[0]['styles'] )[0];

        // Should have 2 variants: tablet, mobile (no desktop — spacer has no style props).
        $this->assertCount( 2, $style_class['variants'] );

        // Tablet variant should have padding-block-start = 40px.
        $tablet = $style_class['variants'][0];
        $this->assertEquals( 'tablet', $tablet['meta']['breakpoint'] );
        $this->assertEquals( 40, $tablet['props']['padding-block-start']['value']['size'] );
        $this->assertEquals( 40, $tablet['props']['padding-block-end']['value']['size'] );

        // Mobile variant should have padding-block-start = 20px.
        $mobile = $style_class['variants'][1];
        $this->assertEquals( 'mobile', $mobile['meta']['breakpoint'] );
        $this->assertEquals( 20, $mobile['props']['padding-block-start']['value']['size'] );
    }

    // -----------------------------------------------------------------
    // Unknown Widget — keep_v3
    // -----------------------------------------------------------------

    public function test_unknown_widget_keep_v3(): void
    {
        $unknown = [
            'id'         => 'unk1',
            'elType'     => 'widget',
            'widgetType' => 'some_third_party_widget',
            'settings'   => ['foo' => 'bar'],
            'elements'   => [],
        ];

        $elements = [ $unknown ];
        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 1, $result );
        $this->assertEquals( $unknown, $result[0], 'Unknown widget should be returned unchanged with keep_v3 strategy' );
        $this->assertEquals( 1, $stats['elements_read'] );
        $this->assertEquals( 0, $stats['converted'] );
        $this->assertEquals( 1, $stats['kept_v3'] );
        $this->assertContainsEquals( 'some_third_party_widget', $stats['unsupported_widgets'] );
    }

    // -----------------------------------------------------------------
    // Unknown Widget — skip
    // -----------------------------------------------------------------

    public function test_unknown_widget_skip(): void
    {
        $elements = [
            [
                'id'         => 'unk2',
                'elType'     => 'widget',
                'widgetType' => 'another_unknown_widget',
                'settings'   => [],
                'elements'   => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'skip', $stats, $warnings );

        $this->assertCount( 0, $result, 'Unknown widget should be skipped (not included in result) with skip strategy' );
        $this->assertEquals( 1, $stats['elements_read'] );
        $this->assertEquals( 1, $stats['skipped'] );
        $this->assertContainsEquals( 'another_unknown_widget', $stats['unsupported_widgets'] );
    }

    // -----------------------------------------------------------------
    // Unknown Widget — error (falls through to keep_v3)
    // -----------------------------------------------------------------

    public function test_unknown_widget_unrecognized_strategy_falls_back_to_keep_v3(): void
    {
        $unknown = [
            'id'         => 'unk3',
            'elType'     => 'widget',
            'widgetType' => 'yet_another_widget',
            'settings'   => [],
            'elements'   => [],
        ];

        $elements = [ $unknown ];
        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'some_unrecognized_strategy', $stats, $warnings );

        // Unrecognized strategy: anything other than 'skip' → keep_v3.
        $this->assertCount( 1, $result );
        $this->assertEquals( $unknown, $result[0] );
        $this->assertEquals( 1, $stats['kept_v3'] );
    }

    // -----------------------------------------------------------------
    // Responsive overrides (extract_responsive_overrides)
    // -----------------------------------------------------------------

    public function test_extract_responsive_overrides_typography(): void
    {
        $v3 = [
            'typography_font_size'          => ['size' => 32, 'unit' => 'px'],
            'typography_font_size_tablet'   => ['size' => 24, 'unit' => 'px'],
            'typography_font_size_mobile'   => ['size' => 18, 'unit' => 'px'],
            'typography_line_height_mobile' => ['size' => 1.4, 'unit' => 'em'],
        ];

        $overrides = V3_To_V4_Converter::extract_responsive_overrides( $v3, [] );

        $this->assertArrayHasKey( 'tablet', $overrides );
        $this->assertArrayHasKey( 'mobile', $overrides );
        $this->assertArrayHasKey( 'font-size', $overrides['tablet'] );
        $this->assertEquals( 24, $overrides['tablet']['font-size']['value']['size'] );
        $this->assertEquals( 18, $overrides['mobile']['font-size']['value']['size'] );
        $this->assertArrayHasKey( 'line-height', $overrides['mobile'] );
        $this->assertEquals( 1.4, $overrides['mobile']['line-height']['value']['size'] );
    }

    public function test_extract_responsive_overrides_spacing(): void
    {
        $v3 = [
            'padding'        => ['top' => 40, 'bottom' => 40, 'left' => 20, 'right' => 20, 'unit' => 'px'],
            'padding_tablet' => ['top' => 20, 'bottom' => 20, 'left' => 10, 'right' => 10, 'unit' => 'px'],
            'margin_mobile'  => ['top' => 0, 'bottom' => 0, 'left' => 0, 'right' => 0, 'unit' => 'px'],
        ];

        $overrides = V3_To_V4_Converter::extract_responsive_overrides( $v3, [] );

        $this->assertArrayHasKey( 'tablet', $overrides );
        $this->assertArrayHasKey( 'mobile', $overrides );
        $this->assertArrayHasKey( 'padding-block-start', $overrides['tablet'] );
        $this->assertEquals( 20, $overrides['tablet']['padding-block-start']['value']['size'] );
        $this->assertArrayHasKey( 'margin-block-start', $overrides['mobile'] );
        $this->assertEquals( 0, $overrides['mobile']['margin-block-start']['value']['size'] );
    }

    public function test_extract_responsive_overrides_no_overrides(): void
    {
        $v3 = [
            'title' => 'No responsive keys here',
        ];

        $overrides = V3_To_V4_Converter::extract_responsive_overrides( $v3, [] );

        $this->assertArrayNotHasKey( 'tablet', $overrides );
        $this->assertArrayNotHasKey( 'mobile', $overrides );
    }

    // -----------------------------------------------------------------
    // Container settings (make_container_settings)
    // -----------------------------------------------------------------

    public function test_make_container_settings_with_padding(): void
    {
        $v3 = [
            'padding' => [
                'top'    => 30,
                'bottom' => 30,
                'left'   => 15,
                'right'  => 15,
                'unit'   => 'px',
            ],
        ];

        $settings = V3_To_V4_Converter::make_container_settings( $v3 );

        $this->assertArrayHasKey( 'padding', $settings );
        $this->assertEquals( 30, $settings['padding']['block-start'] );
        $this->assertEquals( 30, $settings['padding']['block-end'] );
        $this->assertEquals( 15, $settings['padding']['inline-start'] );
        $this->assertEquals( 15, $settings['padding']['inline-end'] );
        $this->assertEquals( 'px', $settings['padding']['unit'] );

        // Background-color should NOT be in settings (deduplication fix).
        $this->assertArrayNotHasKey( 'background-color', $settings );
    }

    public function test_make_container_settings_empty(): void
    {
        $settings = V3_To_V4_Converter::make_container_settings( [] );

        $this->assertArrayHasKey( 'classes', $settings );
        $this->assertEquals( 'classes', $settings['classes']['$$type'] );
        $this->assertIsArray( $settings['classes']['value'] );
    }

    // -----------------------------------------------------------------
    // build_and_apply_styles — no props
    // -----------------------------------------------------------------

    public function test_build_and_apply_styles_no_props(): void
    {
        $result = V3_To_V4_Converter::build_and_apply_styles(
            'elem123',
            [],
            [],
            ['gc-existing-class']
        );

        $this->assertEmpty( $result['styles'] );
        $this->assertEmpty( $result['settings'] );
        $this->assertContainsEquals( 'gc-existing-class', $result['class_ids'] );
    }

    // -----------------------------------------------------------------
    // build_color_index and resolve_color_var
    // -----------------------------------------------------------------

    public function test_build_color_index_resolve(): void
    {
        $variable_map = [
            'c1' => [
                'id'    => 'e-gv-primary',
                'label' => 'primary-color',
                'type'  => 'global-color-variable',
                'value' => '#0066cc',
            ],
            'c2' => [
                'id'    => 'e-gv-text',
                'label' => 'text-color',
                'type'  => 'global-color-variable',
                'value' => '#333333',
            ],
            'f1' => [
                'id'    => 'e-gv-font-heading',
                'label' => 'font-heading',
                'type'  => 'global-font-variable',
                'value' => 'Poppins',
            ],
        ];

        $index = V3_To_V4_Converter::build_color_index( $variable_map );

        // Only color variables should be indexed; font variables excluded.
        $this->assertArrayHasKey( '#0066cc', $index );
        $this->assertArrayHasKey( '#333333', $index );
        $this->assertArrayNotHasKey( 'Poppins', $index, 'Font variable should not be in color index' );

        // Resolve colors.
        $this->assertEquals( 'e-gv-primary', V3_To_V4_Converter::resolve_color_var( '#0066cc', $index ) );
        $this->assertEquals( 'e-gv-text', V3_To_V4_Converter::resolve_color_var( '#333333', $index ) );
        $this->assertEquals( '#ff0000', V3_To_V4_Converter::resolve_color_var( '#ff0000', $index ), 'Unmapped color should return as-is' );
    }

    public function test_resolve_color_var_empty_index(): void
    {
        $result = V3_To_V4_Converter::resolve_color_var( '#000000', [] );
        $this->assertEquals( '#000000', $result, 'Should return original when index is empty' );
    }

    // -----------------------------------------------------------------
    // extract_style_props_for_widget specific cases
    // -----------------------------------------------------------------

    public function test_extract_style_props_icon(): void
    {
        $v3  = ['primary_color' => '#ff6600', 'size' => ['size' => 24, 'unit' => 'px']];
        $ci  = [];
        $props = V3_To_V4_Converter::extract_style_props_for_widget( $v3, 'icon', $ci );

        $this->assertArrayHasKey( 'color', $props );
        $this->assertArrayHasKey( 'font-size', $props );
        $this->assertEquals( '#ff6600', $props['color']['value'] );
        $this->assertEquals( 24, $props['font-size']['value']['size'] );
    }

    public function test_extract_style_props_box_shadow(): void
    {
        $v3 = [
            'box_shadow_box_shadow_type' => 'yes',
            'box_shadow_box_shadow'      => [
                'horizontal' => 0,
                'vertical'   => 4,
                'blur'       => 12,
                'spread'     => 0,
                'color'      => 'rgba(0,0,0,0.15)',
                'position'   => '',
            ],
        ];

        $ci    = [];
        $props = V3_To_V4_Converter::extract_style_props_for_widget( $v3, 'container', $ci );

        $this->assertArrayHasKey( 'box-shadow', $props );
        $this->assertStringContainsString( '0px 4px 12px 0px', $props['box-shadow']['value'] );
        $this->assertStringContainsString( 'rgba(0,0,0,0.15)', $props['box-shadow']['value'] );
    }

    // -----------------------------------------------------------------
    // WIDGET_MAP completeness
    // -----------------------------------------------------------------

    public function test_widget_map_contains_expected_keys(): void
    {
        $expected = ['heading', 'text-editor', 'button', 'image', 'icon', 'video', 'divider', 'spacer'];
        $actual   = array_keys( V3_To_V4_Converter::WIDGET_MAP );

        foreach ( $expected as $key ) {
            $this->assertContainsEquals( $key, $actual, "WIDGET_MAP should contain '$key'" );
        }
    }

    // -----------------------------------------------------------------
    // gen_id format
    // -----------------------------------------------------------------

    public function test_gen_id_format(): void
    {
        $id = V3_To_V4_Converter::gen_id();

        $this->assertIsString( $id );
        $this->assertEquals( 7, strlen( $id ), 'Generated ID should be 7 characters' );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{7}$/', $id, 'Should be a 7-char hex string' );
    }

    // -----------------------------------------------------------------
    // convert_elements with mixed types
    // -----------------------------------------------------------------

    public function test_convert_elements_mixed_types(): void
    {
        $elements = [
            [
                'id'         => 'h1',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => ['title' => 'Title'],
                'elements'   => [],
            ],
            [
                'id'         => 'b1',
                'elType'     => 'widget',
                'widgetType' => 'button',
                'settings'   => ['text' => 'Click'],
                'elements'   => [],
            ],
            [
                'id'         => 'unk1',
                'elType'     => 'widget',
                'widgetType' => 'custom_widget',
                'settings'   => [],
                'elements'   => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertCount( 3, $result );
        $this->assertEquals( 'e-heading', $result[0]['widgetType'] );
        $this->assertEquals( 'e-button', $result[1]['widgetType'] );
        $this->assertEquals( 'custom_widget', $result[2]['widgetType'], 'Kept as V3' );
        $this->assertEquals( 3, $stats['elements_read'] );
        $this->assertEquals( 3, $stats['converted'] + $stats['kept_v3'] );
    }

    // -----------------------------------------------------------------
    // convert_elements with non-array elements skipped
    // -----------------------------------------------------------------

    public function test_convert_elements_skips_non_array_entries(): void
    {
        $elements = [
            'not-an-array',
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertEmpty( $result );
        $this->assertEquals( 0, $stats['elements_read'] );
    }

    // -----------------------------------------------------------------
    // Semantic classes assignment
    // -----------------------------------------------------------------

    public function test_heading_gets_heading_semantic_classes(): void
    {
        $elements = [
            [
                'id'         => 'h-sem',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => ['title' => 'Semantic'],
                'elements'   => [],
            ],
        ];

        $semantic = [
            'heading' => ['gc-heading-xl'],
            'body'    => ['gc-body-md'],
            'button'  => ['gc-btn-primary'],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements(
            $elements, 'keep_v3', $stats, $warnings, [], $semantic
        );

        $classes = $result[0]['settings']['classes']['value'];
        $this->assertContainsEquals( 'gc-heading-xl', $classes );
        $this->assertNotContainsEquals( 'gc-body-md', $classes, 'Body class should not be on heading' );
    }

    public function test_text_editor_gets_body_semantic_classes(): void
    {
        $elements = [
            [
                'id'         => 't-sem',
                'elType'     => 'widget',
                'widgetType' => 'text-editor',
                'settings'   => ['editor' => 'Body text'],
                'elements'   => [],
            ],
        ];

        $semantic = [
            'heading' => ['gc-heading-xl'],
            'body'    => ['gc-body-md'],
            'button'  => ['gc-btn-primary'],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements(
            $elements, 'keep_v3', $stats, $warnings, [], $semantic
        );

        $classes = $result[0]['settings']['classes']['value'];
        $this->assertContainsEquals( 'gc-body-md', $classes );
    }

    public function test_button_gets_body_and_button_semantic_classes(): void
    {
        $elements = [
            [
                'id'         => 'b-sem',
                'elType'     => 'widget',
                'widgetType' => 'button',
                'settings'   => ['text' => 'Click'],
                'elements'   => [],
            ],
        ];

        $semantic = [
            'heading' => ['gc-heading-xl'],
            'body'    => ['gc-body-md'],
            'button'  => ['gc-btn-primary'],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements(
            $elements, 'keep_v3', $stats, $warnings, [], $semantic
        );

        $classes = $result[0]['settings']['classes']['value'];
        $this->assertContainsEquals( 'gc-body-md', $classes );
        $this->assertContainsEquals( 'gc-btn-primary', $classes );
    }

    // -----------------------------------------------------------------
    // Color variable resolution in style extraction
    // -----------------------------------------------------------------

    public function test_heading_color_resolved_to_variable(): void
    {
        $variable_map = [
            'c1' => [
                'id'    => 'e-gv-accent',
                'label' => 'accent-color',
                'type'  => 'global-color-variable',
                'value' => '#ff6600',
            ],
        ];

        $elements = [
            [
                'id'         => 'h-var',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => [
                    'title'       => 'Variable Color',
                    'title_color' => '#ff6600',
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements(
            $elements, 'keep_v3', $stats, $warnings,
            $variable_map // Pass variable_map so build_color_index runs.
        );

        $this->assertArrayHasKey( 'styles', $result[0] );
        $style_class = array_values( $result[0]['styles'] )[0];
        $props       = $style_class['variants'][0]['props'];

        $this->assertEquals( 'global-color-variable', $props['color']['$$type'] );
        $this->assertEquals( 'e-gv-accent', $props['color']['value'] );
    }

    // -----------------------------------------------------------------
    // Responsive overrides in widget conversion
    // -----------------------------------------------------------------

    public function test_widget_with_responsive_typography(): void
    {
        $elements = [
            [
                'id'         => 'h-resp',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => [
                    'title'               => 'Responsive',
                    'typography_typography' => [
                        'typography_font_size' => ['size' => 36, 'unit' => 'px'],
                    ],
                    'typography_font_size_tablet' => ['size' => 28, 'unit' => 'px'],
                    'typography_font_size_mobile' => ['size' => 22, 'unit' => 'px'],
                ],
                'elements' => [],
            ],
        ];

        $stats    = [];
        $warnings = [];
        $result   = V3_To_V4_Converter::convert_elements( $elements, 'keep_v3', $stats, $warnings );

        $this->assertArrayHasKey( 'styles', $result[0] );
        $style_class = array_values( $result[0]['styles'] )[0];

        // Should have 3 variants.
        $this->assertCount( 3, $style_class['variants'] );
        $this->assertEquals( 'desktop', $style_class['variants'][0]['meta']['breakpoint'] );
        $this->assertEquals( 'tablet', $style_class['variants'][1]['meta']['breakpoint'] );
        $this->assertEquals( 'mobile', $style_class['variants'][2]['meta']['breakpoint'] );

        $this->assertEquals( 36, $style_class['variants'][0]['props']['font-size']['value']['size'] );
        $this->assertEquals( 28, $style_class['variants'][1]['props']['font-size']['value']['size'] );
        $this->assertEquals( 22, $style_class['variants'][2]['props']['font-size']['value']['size'] );
    }
}
