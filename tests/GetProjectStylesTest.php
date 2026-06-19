<?php
/**
 * Test: Get_Project_Styles — XML-Parsing (12 cases).
 *
 * @package Novamira\AdrianV2\Tests
 * @since   1.1.0
 */

declare(strict_types=1);

// In mock mode, wp_abilities_api_init never fires, so require class directly.
require_once __DIR__ . '/../includes/abilities/utilities/class-get-project-styles.php';

use Novamira\AdrianV2\Abilities\Utilities\Get_Project_Styles;
use PHPUnit\Framework\TestCase;

#[CoversClass(Get_Project_Styles::class)]
class GetProjectStylesTest extends TestCase
{
    private const SAMPLE_XML = <<<'XML'
<Colors>
  <Color name="/Neutrals/Neutral 950" hex="#010004" r="1" g="0" b="4"/>
  <Color name="/Primary scale/Primary 500" hex="#0f5bff" r="15" g="91" b="255"/>
</Colors>
<TextStyles>
  <TextStyle name="/Headings/80" fontSize="72" fontWeight="500" fontFamily="Geist" lineHeight="1em" letterSpacing="-0.02em"/>
  <TextStyle name="/Paragraphs/16" fontSize="16" fontWeight="500" lineHeight="1.5em"/>
</TextStyles>
XML;

    protected function setUp(): void
    {
        $GLOBALS['_registered_abilities'] = [];
        Get_Project_Styles::register();
    }

    // ── Empty / Error ────────────────────────────────────────────────────────

    public function test_empty_xml_returns_error(): void
    {
        $result = Get_Project_Styles::execute(['xml' => '']);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
        $this->assertSame([], $result['colors']);
        $this->assertSame([], $result['textStyles']);
    }

    public function test_whitespace_only_xml_returns_error(): void
    {
        $result = Get_Project_Styles::execute(['xml' => "   \n  \t  "]);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    // ── Full format (no fence) ───────────────────────────────────────────────

    public function test_full_format_extracts_all_color_keys(): void
    {
        $result = Get_Project_Styles::execute(['xml' => self::SAMPLE_XML, 'format' => 'full']);

        $this->assertTrue($result['success']);
        $this->assertSame('full', $result['format']);

        $colors = $result['colors'];
        $this->assertCount(2, $colors);
        $this->assertArrayHasKey('/Neutrals/Neutral 950', $colors);
        $this->assertArrayHasKey('/Primary scale/Primary 500', $colors);

        $c = $colors['/Neutrals/Neutral 950'];
        $this->assertSame('#010004', $c['hex']);
        $this->assertSame('rgb(1, 0, 4)', $c['rgb']);
        $this->assertSame(1, $c['r']);
        $this->assertSame(0, $c['g']);
        $this->assertSame(4, $c['b']);
    }

    public function test_full_format_extracts_all_text_style_keys(): void
    {
        $result = Get_Project_Styles::execute(['xml' => self::SAMPLE_XML, 'format' => 'full']);

        $textStyles = $result['textStyles'];
        $this->assertCount(2, $textStyles);

        $ts = $textStyles['/Headings/80'];
        $this->assertSame('72', $ts['fontSize']);
        $this->assertSame('500', $ts['fontWeight']);
        $this->assertSame('Geist', $ts['fontFamily']);
        $this->assertSame('1em', $ts['lineHeight']);
        $this->assertSame('-0.02em', $ts['letterSpacing']);

        // Second text style has no fontFamily or letterSpacing — should be empty strings.
        $ps = $textStyles['/Paragraphs/16'];
        $this->assertSame('16', $ps['fontSize']);
        $this->assertSame('1.5em', $ps['lineHeight']);
        $this->assertSame('', $ps['fontFamily']);
        $this->assertSame('', $ps['letterSpacing']);
    }

    // ── Compact format ───────────────────────────────────────────────────────

    public function test_compact_format_only_returns_hex_and_font_size_weight(): void
    {
        $result = Get_Project_Styles::execute(['xml' => self::SAMPLE_XML, 'format' => 'compact']);

        $this->assertTrue($result['success']);
        $this->assertSame('compact', $result['format']);

        // Colors: only 'hex' key.
        $c = $result['colors']['/Neutrals/Neutral 950'];
        $this->assertSame(['hex' => '#010004'], $c, 'Compact: color must only have "hex" key');
        $this->assertArrayNotHasKey('rgb', $c);
        $this->assertArrayNotHasKey('r', $c);

        // TextStyles: only 'fontSize' and 'fontWeight'.
        $ts = $result['textStyles']['/Headings/80'];
        $this->assertSame(['fontSize' => '72', 'fontWeight' => '500'], $ts, 'Compact: text style must only have fontSize+fontWeight');
        $this->assertArrayNotHasKey('fontFamily', $ts);
    }

    // ── Markdown code fences ─────────────────────────────────────────────────

    public function test_strips_markdown_fence_with_xml_hint(): void
    {
        $fenced = "```xml\n" . self::SAMPLE_XML . "\n```";
        $result = Get_Project_Styles::execute(['xml' => $fenced]);

        $this->assertTrue($result['success'], 'Must parse XML wrapped in ```xml ... ```');
        $this->assertCount(2, $result['colors'], 'Must find both colors inside fenced XML');
        $this->assertCount(2, $result['textStyles']);
    }

    public function test_strips_markdown_fence_without_xml_hint(): void
    {
        $fenced = "```\n" . self::SAMPLE_XML . "\n```";
        $result = Get_Project_Styles::execute(['xml' => $fenced]);

        $this->assertTrue($result['success'], 'Must parse XML wrapped in plain ``` ... ```');
        $this->assertArrayHasKey('/Neutrals/Neutral 950', $result['colors']);
    }

    // ── Missing / optional attributes ────────────────────────────────────────

    public function test_skips_color_without_name_attribute(): void
    {
        $xml = '<Colors><Color hex="#fff" r="255" g="255" b="255"/></Colors>';
        $result = Get_Project_Styles::execute(['xml' => $xml]);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['colors'], 'Color without name must be skipped');
    }

    // ── Color without r/g/b attributes ───────────────────────────────────────

    public function test_color_without_rgb_defaults_to_zero(): void
    {
        $xml = '<Colors><Color name="/Blue" hex="#0000ff"/></Colors>';
        $result = Get_Project_Styles::execute(['xml' => $xml, 'format' => 'full']);

        $c = $result['colors']['/Blue'];
        $this->assertSame('#0000ff', $c['hex']);
        // No r/g/b attributes → defaults to 0.
        $this->assertSame('rgb(0, 0, 0)', $c['rgb']);
        $this->assertSame(0, $c['r']);
        $this->assertSame(0, $c['g']);
        $this->assertSame(0, $c['b']);
    }

    // ── Mixed case attributes ────────────────────────────────────────────────

    public function test_handles_mixed_case_text_style_attributes(): void
    {
        $xml = <<<'XML'
<TextStyles>
  <TextStyle name="/Test" fontsize="24" fontweight="700" fontfamily="Inter" lineheight="1.2" letterspacing="0.01em"/>
</TextStyles>
XML;
        $result = Get_Project_Styles::execute(['xml' => $xml, 'format' => 'full']);

        $ts = $result['textStyles']['/Test'];
        $this->assertSame('24', $ts['fontSize'], 'Must handle lowercase fontsize attribute');
        $this->assertSame('700', $ts['fontWeight'], 'Must handle lowercase fontweight attribute');
        $this->assertSame('Inter', $ts['fontFamily'], 'Must handle lowercase fontfamily attribute');
        $this->assertSame('1.2', $ts['lineHeight'], 'Must handle lowercase lineheight attribute');
        $this->assertSame('0.01em', $ts['letterSpacing'], 'Must handle lowercase letterspacing attribute');
    }

    // ── Structure: only colors, only textStyles ──────────────────────────────

    public function test_colors_only_no_text_styles(): void
    {
        $xml = '<Colors><Color name="/Red" hex="#ff0000" r="255" g="0" b="0"/></Colors>';
        $result = Get_Project_Styles::execute(['xml' => $xml]);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['colors']);
        $this->assertCount(0, $result['textStyles'], 'textStyles must be empty when no TextStyle tags present');
    }

    public function test_text_styles_only_no_colors(): void
    {
        $xml = '<TextStyles><TextStyle name="/Body" fontSize="14" fontWeight="400"/></TextStyles>';
        $result = Get_Project_Styles::execute(['xml' => $xml]);

        $this->assertTrue($result['success']);
        $this->assertCount(0, $result['colors'], 'colors must be empty when no Color tags present');
        $this->assertCount(1, $result['textStyles']);
    }

    // ── register() ───────────────────────────────────────────────────────────

    public function test_register_defines_correct_category_and_schema(): void
    {
        $registered = $GLOBALS['_registered_abilities']['novamira-adrianv2/get-project-styles'] ?? null;
        $this->assertNotNull($registered, 'get-project-styles must be registered');

        $def = $registered['callable'] ?? [];
        $this->assertSame('adrianv2-utilities', $def['category'] ?? '');
        $this->assertSame([Get_Project_Styles::class, 'execute'], $def['callback'] ?? null);

        $schema = $def['schema'] ?? [];
        $this->assertArrayHasKey('xml', $schema['properties'] ?? []);
        $this->assertArrayHasKey('format', $schema['properties'] ?? []);
        $this->assertContains('compact', $schema['properties']['format']['enum'] ?? []);
        $this->assertContains('full', $schema['properties']['format']['enum'] ?? []);
    }
}
