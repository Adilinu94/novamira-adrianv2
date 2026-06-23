<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Helpers\Local_Styles_Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Local_Styles_Renderer.
 *
 * All private/protected methods are exercised via ReflectionMethod so we don't
 * need a live WordPress installation.
 *
 * @covers \Novamira\AdrianV2\Helpers\Local_Styles_Renderer
 */
final class LocalStylesRendererTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @return \ReflectionMethod */
	private function method( string $name ): \ReflectionMethod {
		$rm = new \ReflectionMethod( Local_Styles_Renderer::class, $name );
		$rm->setAccessible( true );
		return $rm;
	}

	private function invoke( string $method, mixed ...$args ): mixed {
		return $this->method( $method )->invoke( null, ...$args );
	}

	// -------------------------------------------------------------------------
	// prop_to_css — color
	// -------------------------------------------------------------------------

	public function test_prop_to_css_color_hex(): void {
		$this->assertSame( '#00EBAF', $this->invoke( 'prop_to_css', [ '$$type' => 'color', 'value' => '#00EBAF' ] ) );
	}

	public function test_prop_to_css_color_rgba(): void {
		$this->assertSame( 'rgba(0,0,0,0.5)', $this->invoke( 'prop_to_css', [ '$$type' => 'color', 'value' => 'rgba(0,0,0,0.5)' ] ) );
	}

	public function test_prop_to_css_color_empty_value_returns_empty(): void {
		$this->assertSame( '', $this->invoke( 'prop_to_css', [ '$$type' => 'color', 'value' => '' ] ) );
	}

	// -------------------------------------------------------------------------
	// prop_to_css — global-color-variable
	// -------------------------------------------------------------------------

	public function test_prop_to_css_global_color_variable(): void {
		$this->assertSame(
			'var(--e-gv-bebd7fa)',
			$this->invoke( 'prop_to_css', [ '$$type' => 'global-color-variable', 'value' => 'e-gv-bebd7fa' ] )
		);
	}

	public function test_prop_to_css_global_color_variable_empty_returns_empty(): void {
		$this->assertSame( '', $this->invoke( 'prop_to_css', [ '$$type' => 'global-color-variable', 'value' => '' ] ) );
	}

	// -------------------------------------------------------------------------
	// prop_to_css — size
	// -------------------------------------------------------------------------

	public function test_prop_to_css_size_integer(): void {
		$this->assertSame( '16px', $this->invoke( 'prop_to_css', [ '$$type' => 'size', 'value' => [ 'size' => 16, 'unit' => 'px' ] ] ) );
	}

	public function test_prop_to_css_size_float(): void {
		$this->assertSame( '1.5rem', $this->invoke( 'prop_to_css', [ '$$type' => 'size', 'value' => [ 'size' => 1.5, 'unit' => 'rem' ] ] ) );
	}

	public function test_prop_to_css_size_zero(): void {
		$this->assertSame( '0px', $this->invoke( 'prop_to_css', [ '$$type' => 'size', 'value' => [ 'size' => 0, 'unit' => 'px' ] ] ) );
	}

	public function test_prop_to_css_size_no_value_returns_empty(): void {
		$this->assertSame( '', $this->invoke( 'prop_to_css', [ '$$type' => 'size', 'value' => [ 'unit' => 'px' ] ] ) );
	}

	public function test_prop_to_css_size_non_array_value_returns_empty(): void {
		// value must be array {size, unit} — bare int/string is not the V4 format.
		$result = $this->invoke( 'prop_to_css', [ '$$type' => 'size', 'value' => 'bad' ] );
		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// prop_to_css — string / number / boolean
	// -------------------------------------------------------------------------

	public function test_prop_to_css_string(): void {
		$this->assertSame( 'row', $this->invoke( 'prop_to_css', [ '$$type' => 'string', 'value' => 'row' ] ) );
	}

	public function test_prop_to_css_string_empty_returns_empty(): void {
		$this->assertSame( '', $this->invoke( 'prop_to_css', [ '$$type' => 'string', 'value' => '' ] ) );
	}

	public function test_prop_to_css_number(): void {
		$this->assertSame( '3', $this->invoke( 'prop_to_css', [ '$$type' => 'number', 'value' => 3 ] ) );
	}

	public function test_prop_to_css_boolean_true(): void {
		$this->assertSame( 'true', $this->invoke( 'prop_to_css', [ '$$type' => 'boolean', 'value' => true ] ) );
	}

	public function test_prop_to_css_boolean_false(): void {
		$this->assertSame( 'false', $this->invoke( 'prop_to_css', [ '$$type' => 'boolean', 'value' => false ] ) );
	}

	// -------------------------------------------------------------------------
	// prop_to_css — unsupported types return ''
	// -------------------------------------------------------------------------

	public function test_prop_to_css_image_attachment_id_returns_empty(): void {
		$this->assertSame( '', $this->invoke( 'prop_to_css', [ '$$type' => 'image-attachment-id', 'value' => 42 ] ) );
	}

	public function test_prop_to_css_unknown_type_returns_empty(): void {
		$this->assertSame( '', $this->invoke( 'prop_to_css', [ '$$type' => 'totally-unknown', 'value' => 'x' ] ) );
	}

	public function test_prop_to_css_missing_type_returns_empty(): void {
		$this->assertSame( '', $this->invoke( 'prop_to_css', [ 'value' => 'x' ] ) );
	}

	// -------------------------------------------------------------------------
	// dimensions_shorthand
	// -------------------------------------------------------------------------

	private function size_prop( float $n, string $unit = 'px' ): array {
		return [ '$$type' => 'size', 'value' => [ 'size' => $n, 'unit' => $unit ] ];
	}

	public function test_dimensions_shorthand_logical_all_same(): void {
		$dims = [
			'block-start'  => $this->size_prop( 0 ),
			'inline-end'   => $this->size_prop( 0 ),
			'block-end'    => $this->size_prop( 0 ),
			'inline-start' => $this->size_prop( 0 ),
		];
		$this->assertSame( '0px 0px 0px 0px', $this->invoke( 'dimensions_shorthand', $dims ) );
	}

	public function test_dimensions_shorthand_logical_different(): void {
		$dims = [
			'block-start'  => $this->size_prop( 1, 'rem' ),
			'inline-end'   => $this->size_prop( 2, 'rem' ),
			'block-end'    => $this->size_prop( 1, 'rem' ),
			'inline-start' => $this->size_prop( 2, 'rem' ),
		];
		$this->assertSame( '1rem 2rem 1rem 2rem', $this->invoke( 'dimensions_shorthand', $dims ) );
	}

	public function test_dimensions_shorthand_physical_fallback(): void {
		$dims = [
			'top'    => $this->size_prop( 10 ),
			'right'  => $this->size_prop( 20 ),
			'bottom' => $this->size_prop( 30 ),
			'left'   => $this->size_prop( 40 ),
		];
		$this->assertSame( '10px 20px 30px 40px', $this->invoke( 'dimensions_shorthand', $dims ) );
	}

	// -------------------------------------------------------------------------
	// dimensions_to_declarations
	// -------------------------------------------------------------------------

	public function test_dimensions_to_declarations_logical(): void {
		$prop = [
			'$$type' => 'dimensions',
			'value'  => [
				'block-start'  => $this->size_prop( 8 ),
				'block-end'    => $this->size_prop( 8 ),
				'inline-start' => $this->size_prop( 16 ),
				'inline-end'   => $this->size_prop( 16 ),
			],
		];
		$decls = $this->invoke( 'dimensions_to_declarations', 'padding', $prop );
		$this->assertContains( 'padding-block-start: 8px;', $decls );
		$this->assertContains( 'padding-block-end: 8px;', $decls );
		$this->assertContains( 'padding-inline-start: 16px;', $decls );
		$this->assertContains( 'padding-inline-end: 16px;', $decls );
		$this->assertCount( 4, $decls );
	}

	// -------------------------------------------------------------------------
	// collect_styles
	// -------------------------------------------------------------------------

	/**
	 * collect_styles has a by-reference second parameter; ReflectionMethod::invoke()
	 * passes by value, so we use invokeArgs() with a reference variable.
	 */
	private function collect( array $elements ): array {
		$all = [];
		$args = [ $elements, &$all ];
		$this->method( 'collect_styles' )->invokeArgs( null, $args );
		return $all;
	}

	public function test_collect_styles_flat(): void {
		$elements = [
			[
				'id'     => 'aaa',
				'elType' => 'e-flexbox',
				'styles' => [
					'e-aaa-111' => [ 'variants' => [] ],
				],
			],
		];
		$all = $this->collect( $elements );
		$this->assertArrayHasKey( 'e-aaa-111', $all );
	}

	public function test_collect_styles_nested(): void {
		$elements = [
			[
				'id'       => 'parent',
				'elType'   => 'e-flexbox',
				'styles'   => [ 'e-parent-1' => [ 'variants' => [] ] ],
				'elements' => [
					[
						'id'     => 'child',
						'elType' => 'e-div-block',
						'styles' => [ 'e-child-1' => [ 'variants' => [] ] ],
					],
				],
			],
		];
		$all = $this->collect( $elements );
		$this->assertArrayHasKey( 'e-parent-1', $all );
		$this->assertArrayHasKey( 'e-child-1', $all );
	}

	public function test_collect_styles_deduplicates(): void {
		// Same style_id in two elements — first wins (not duplicated).
		$elements = [
			[
				'id'     => 'a',
				'styles' => [ 'e-shared-1' => [ 'variants' => [ 'first' ] ] ],
			],
			[
				'id'     => 'b',
				'styles' => [ 'e-shared-1' => [ 'variants' => [ 'second' ] ] ],
			],
		];
		$all = $this->collect( $elements );
		$this->assertCount( 1, $all );
		$this->assertSame( [ 'first' ], $all['e-shared-1']['variants'] );
	}

	public function test_collect_styles_skips_non_array_elements(): void {
		$elements = [ null, 'string', 42, [] ];
		$all = $this->collect( $elements );
		$this->assertEmpty( $all );
	}

	// -------------------------------------------------------------------------
	// render_style_def — selector with/without e- prefix
	// -------------------------------------------------------------------------

	public function test_render_style_def_selector_with_e_prefix(): void {
		$style_id  = 'e-abc123-def456';
		$style_def = [
			'variants' => [
				[
					'meta'  => [ 'breakpoint' => 'desktop', 'state' => null ],
					'props' => [ 'color' => [ '$$type' => 'color', 'value' => '#FF0000' ] ],
				],
			],
		];
		$css = $this->invoke( 'render_style_def', $style_id, $style_def );
		$this->assertStringContainsString( '.e-abc123-def456 {', $css );
		$this->assertStringContainsString( 'color: #FF0000;', $css );
	}

	public function test_render_style_def_selector_without_e_prefix(): void {
		$style_id  = 'abc123-def456';
		$style_def = [
			'variants' => [
				[
					'meta'  => [ 'breakpoint' => 'desktop', 'state' => null ],
					'props' => [ 'color' => [ '$$type' => 'color', 'value' => '#00FF00' ] ],
				],
			],
		];
		$css = $this->invoke( 'render_style_def', $style_id, $style_def );
		$this->assertStringContainsString( '.e-abc123-def456 {', $css );
	}

	public function test_render_style_def_responsive_tablet_wraps_in_media_query(): void {
		$style_def = [
			'variants' => [
				[
					'meta'  => [ 'breakpoint' => 'tablet', 'state' => null ],
					'props' => [ 'font-size' => [ '$$type' => 'size', 'value' => [ 'size' => 14, 'unit' => 'px' ] ] ],
				],
			],
		];
		$css = $this->invoke( 'render_style_def', 'e-tab-1', $style_def );
		$this->assertStringContainsString( '@media (max-width: 1024px)', $css );
	}

	public function test_render_style_def_responsive_mobile_wraps_in_media_query(): void {
		$style_def = [
			'variants' => [
				[
					'meta'  => [ 'breakpoint' => 'mobile', 'state' => null ],
					'props' => [ 'font-size' => [ '$$type' => 'size', 'value' => [ 'size' => 12, 'unit' => 'px' ] ] ],
				],
			],
		];
		$css = $this->invoke( 'render_style_def', 'e-mob-1', $style_def );
		$this->assertStringContainsString( '@media (max-width: 767px)', $css );
	}

	public function test_render_style_def_state_appended_as_pseudo_class(): void {
		$style_def = [
			'variants' => [
				[
					'meta'  => [ 'breakpoint' => 'desktop', 'state' => 'hover' ],
					'props' => [ 'color' => [ '$$type' => 'color', 'value' => '#0000FF' ] ],
				],
			],
		];
		$css = $this->invoke( 'render_style_def', 'e-hover-1', $style_def );
		$this->assertStringContainsString( '.e-hover-1:hover {', $css );
	}

	public function test_render_style_def_empty_variants_returns_empty(): void {
		$css = $this->invoke( 'render_style_def', 'e-empty-1', [ 'variants' => [] ] );
		$this->assertSame( '', $css );
	}

	public function test_render_style_def_skips_props_with_empty_css_value(): void {
		// image-attachment-id yields '' → that declaration must be absent.
		$style_def = [
			'variants' => [
				[
					'meta'  => [ 'breakpoint' => 'desktop', 'state' => null ],
					'props' => [
						'background-image' => [ '$$type' => 'image-attachment-id', 'value' => 42 ],
						'color'            => [ '$$type' => 'color', 'value' => '#123456' ],
					],
				],
			],
		];
		$css = $this->invoke( 'render_style_def', 'e-img-1', $style_def );
		$this->assertStringNotContainsString( 'background-image', $css );
		$this->assertStringContainsString( 'color: #123456;', $css );
	}
}
