<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Abilities\Elementor\List_V3_Pages;
use Novamira\AdrianV2\Abilities\ClonerLabs\ClonerLabs_Style_Minifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests for List_V3_Pages::detect_status() and ClonerLabs_Style_Minifier::clean().
 *
 * Both are pure-PHP without WP dependencies.
 */
final class NewAbilitiesTest extends TestCase
{
    // =========================================================================
    // List_V3_Pages::detect_status()
    // =========================================================================

    public function test_detect_empty_string(): void
    {
        $this->assertSame( 'empty', List_V3_Pages::detect_status( '' ) );
    }

    public function test_detect_empty_array_json(): void
    {
        $this->assertSame( 'empty', List_V3_Pages::detect_status( '[]' ) );
    }

    public function test_detect_v3_section(): void
    {
        $raw = json_encode( [ [ 'elType' => 'section', 'elements' => [] ] ] );
        $this->assertSame( 'v3', List_V3_Pages::detect_status( $raw ) );
    }

    public function test_detect_v3_column(): void
    {
        $raw = json_encode( [ [ 'elType' => 'column', 'elements' => [] ] ] );
        $this->assertSame( 'v3', List_V3_Pages::detect_status( $raw ) );
    }

    public function test_detect_v4_flexbox(): void
    {
        $raw = json_encode( [ [ 'elType' => 'e-flexbox', 'elements' => [] ] ] );
        $this->assertSame( 'v4', List_V3_Pages::detect_status( $raw ) );
    }

    public function test_detect_v4_heading(): void
    {
        $raw = json_encode( [ [ 'elType' => 'e-heading' ] ] );
        $this->assertSame( 'v4', List_V3_Pages::detect_status( $raw ) );
    }

    public function test_detect_mixed(): void
    {
        $raw = json_encode( [
            [ 'elType' => 'section' ],
            [ 'elType' => 'e-flexbox' ],
        ] );
        $this->assertSame( 'mixed', List_V3_Pages::detect_status( $raw ) );
    }

    public function test_detect_no_elementor_markers_returns_empty(): void
    {
        $raw = json_encode( [ [ 'elType' => 'widget', 'widgetType' => 'text-editor' ] ] );
        $this->assertSame( 'empty', List_V3_Pages::detect_status( $raw ) );
    }

    // =========================================================================
    // ClonerLabs_Style_Minifier::clean()
    // =========================================================================

    public function test_zero_padding_removed(): void
    {
        $input = [ [
            'elType'   => 'container',
            'settings' => [ 'padding' => [ 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0 ] ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayNotHasKey( 'padding', $result[0]['settings'] );
    }

    public function test_nonzero_padding_kept(): void
    {
        $input = [ [
            'elType'   => 'container',
            'settings' => [ 'padding' => [ 'top' => 10, 'right' => 0, 'bottom' => 0, 'left' => 0 ] ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayHasKey( 'padding', $result[0]['settings'] );
    }

    public function test_empty_border_removed(): void
    {
        $input = [ [
            'elType'   => 'container',
            'settings' => [ 'border_border' => '', 'border_width' => [ 'top' => '1' ], 'border_color' => '#000' ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayNotHasKey( 'border_border', $result[0]['settings'] );
        $this->assertArrayNotHasKey( 'border_width', $result[0]['settings'] );
    }

    public function test_global_color_ref_never_stripped(): void
    {
        $input = [ [
            'elType'   => 'container',
            'settings' => [ 'background_background' => 'classic', 'background_color' => 'var(--e-global-color-primary)' ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayHasKey( 'background_color', $result[0]['settings'] );
        $this->assertSame( 'var(--e-global-color-primary)', $result[0]['settings']['background_color'] );
    }

    public function test_globals_key_never_stripped(): void
    {
        $globals = [ 'background_color' => 'globals/colors?id=primary' ];
        $input = [ [
            'elType'   => 'container',
            'settings' => [
                '__globals__'       => $globals,
                'background_color'  => '',         // would be removed without globals guard
            ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayHasKey( '__globals__', $result[0]['settings'] );
        $this->assertSame( $globals, $result[0]['settings']['__globals__'] );
    }

    public function test_islocked_element_skipped(): void
    {
        $input = [ [
            'elType'   => 'container',
            'isLocked' => true,
            'settings' => [ 'padding' => [ 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0 ] ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        // isLocked elements are returned untouched — padding still present.
        $this->assertArrayHasKey( 'padding', $result[0]['settings'] );
    }

    public function test_default_font_weight_removed(): void
    {
        $input = [ [
            'elType'   => 'widget',
            'settings' => [ 'typography_font_weight' => '400' ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayNotHasKey( 'typography_font_weight', $result[0]['settings'] );
    }

    public function test_nondefault_font_weight_kept(): void
    {
        $input = [ [
            'elType'   => 'widget',
            'settings' => [ 'typography_font_weight' => '700' ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayHasKey( 'typography_font_weight', $result[0]['settings'] );
    }

    public function test_nested_elements_cleaned_recursively(): void
    {
        $input = [ [
            'elType'   => 'container',
            'settings' => [],
            'elements' => [ [
                'elType'   => 'widget',
                'settings' => [ 'padding' => [ 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0 ] ],
                'elements' => [],
            ] ],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayNotHasKey( 'padding', $result[0]['elements'][0]['settings'] );
    }

    public function test_empty_string_keys_stripped(): void
    {
        $input = [ [
            'elType'   => 'widget',
            'settings' => [ 'custom_key' => '', 'another_key' => null ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayNotHasKey( 'custom_key', $result[0]['settings'] );
        $this->assertArrayNotHasKey( 'another_key', $result[0]['settings'] );
    }

    public function test_color_keys_never_stripped(): void
    {
        $input = [ [
            'elType'   => 'widget',
            'settings' => [ 'text_color' => '', 'color' => '' ],
            'elements' => [],
        ] ];
        $result = ClonerLabs_Style_Minifier::clean( $input );
        $this->assertArrayHasKey( 'text_color', $result[0]['settings'] );
        $this->assertArrayHasKey( 'color', $result[0]['settings'] );
    }
}
