<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Tests;

use Novamira\AdrianV2\Abilities\Elementor\Get_Page_Elements;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Novamira\AdrianV2\Abilities\Elementor\Get_Page_Elements
 */
final class GetPageElementsTest extends TestCase
{
    private function tree(): array
    {
        return [
            [
                'id'         => 'sec1',
                'elType'     => 'section',
                'settings'   => ['background_color' => '#fff'],
                'elements'   => [
                    [
                        'id'         => 'col1',
                        'elType'     => 'column',
                        'settings'   => [],
                        'elements'   => [
                            [
                                'id'         => 'w1',
                                'elType'     => 'widget',
                                'widgetType' => 'heading',
                                'settings'   => ['title' => 'Hello World', 'classes' => ['value' => ['g-abc']]],
                                'elements'   => [],
                            ],
                            [
                                'id'         => 'w2',
                                'elType'     => 'widget',
                                'widgetType' => 'image',
                                'settings'   => ['image' => ['url' => 'https://example.com/img.jpg', 'id' => 5]],
                                'elements'   => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id'         => 'sec2',
                'elType'     => 'section',
                'settings'   => ['custom_css' => 'body { color: red; }'],
                'elements'   => [],
            ],
        ];
    }

    public function test_flatten_returns_all_elements(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $this->assertCount(5, $flat); // sec1, col1, w1, w2, sec2
    }

    public function test_flatten_assigns_correct_parent_ids(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);

        $by_id = array_column($flat, null, 'id');
        $this->assertSame('',     $by_id['sec1']['parent_id']);
        $this->assertSame('sec1', $by_id['col1']['parent_id']);
        $this->assertSame('col1', $by_id['w1']['parent_id']);
        $this->assertSame('col1', $by_id['w2']['parent_id']);
        $this->assertSame('',     $by_id['sec2']['parent_id']);
    }

    public function test_flatten_assigns_correct_depth(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertSame(0, $by_id['sec1']['depth']);
        $this->assertSame(1, $by_id['col1']['depth']);
        $this->assertSame(2, $by_id['w1']['depth']);
        $this->assertSame(2, $by_id['w2']['depth']);
    }

    public function test_flatten_counts_direct_children(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertSame(1, $by_id['sec1']['children']); // col1
        $this->assertSame(2, $by_id['col1']['children']); // w1, w2
        $this->assertSame(0, $by_id['w1']['children']);
        $this->assertSame(0, $by_id['sec2']['children']);
    }

    public function test_summary_extracts_title(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertSame('Hello World', $by_id['w1']['summary']['title']);
    }

    public function test_summary_extracts_image_url(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertSame('https://example.com/img.jpg', $by_id['w2']['summary']['image_url']);
    }

    public function test_summary_extracts_global_classes(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertContains('g-abc', $by_id['w1']['summary']['classes']);
    }

    public function test_summary_flags_custom_css(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertTrue($by_id['sec2']['summary']['has_custom_css'] ?? false);
        $this->assertArrayNotHasKey('has_custom_css', $by_id['w1']['summary']);
    }

    public function test_summary_extracts_background_color(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertSame('#fff', $by_id['sec1']['summary']['background_color']);
    }

    public function test_execute_returns_error_for_zero_post_id(): void
    {
        $result = Get_Page_Elements::execute(['post_id' => 0]);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('post_id', $result['error']);
    }

    public function test_execute_returns_error_for_null_input(): void
    {
        $result = Get_Page_Elements::execute(null);
        $this->assertFalse($result['success']);
    }

    public function test_flatten_handles_empty_tree(): void
    {
        $flat = [];
        Get_Page_Elements::flatten([], '', 0, $flat);
        $this->assertCount(0, $flat);
    }

    public function test_flatten_includes_widget_type(): void
    {
        $flat = [];
        Get_Page_Elements::flatten($this->tree(), '', 0, $flat);
        $by_id = array_column($flat, null, 'id');

        $this->assertSame('heading', $by_id['w1']['widget_type']);
        $this->assertSame('image',   $by_id['w2']['widget_type']);
        $this->assertSame('',        $by_id['sec1']['widget_type']);
    }
}
