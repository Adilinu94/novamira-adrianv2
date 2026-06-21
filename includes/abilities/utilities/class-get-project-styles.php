<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Utilities;

if (!defined('ABSPATH')) { exit; }

/**
 * Get_Project_Styles — Parses Unframer getProjectXml output into a
 * normalized style map of {colors, textStyles}.
 *
 * Eliminates the need for manual style extraction from XML-attribute blobs.
 * The agent passes the raw getProjectXml response and gets back structured,
 * token-ready color and text-style definitions.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */
class Get_Project_Styles
{
    /**
     * Register the ability.
     */
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/get-project-styles', [
            'name'        => 'novamira-adrianv2/get-project-styles',
            'label'       => __('Get Project Styles', 'novamira-adrianv2'),
            'description' => __('Parses Unframer getProjectXml output into a normalized {colors, textStyles} map. Extracts hex/rgb values for colors and fontSize/fontWeight/fontFamily/lineHeight/letterSpacing for text styles. Returns token-ready definitions.', 'novamira-adrianv2'),
            'category'    => 'adrianv2-utilities',
            'callback'    => [self::class, 'execute'],
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'xml'    => ['type' => 'string', 'description' => 'Raw XML output from getProjectXml (Unframer MCP).'],
                    'format' => ['type' => 'string', 'enum' => ['full', 'compact'], 'default' => 'full', 'description' => 'full = all keys; compact = only hex + fontSize/fontWeight.'],
                ],
                'required'   => ['xml'],
            ],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'mcp' => ['public' => true, 'type' => 'tool'],
        ]);
    }

    /**
     * Execute: parse XML and return normalized style map.
     *
     * @param array|null $input
     * @return array
     */
    public static function execute($input = null): array
    {
        $xml_raw = trim($input['xml'] ?? '');
        $format  = $input['format'] ?? 'full';

        if ($xml_raw === '') {
            return [
                'success' => false,
                'error'   => 'getProjectXml output is empty. Pass the full XML response from the Unframer MCP.',
                'colors'  => [],
                'textStyles' => [],
            ];
        }

        $styles = self::parse_xml($xml_raw, $format);

        return [
            'success'    => true,
            'format'     => $format,
            'colors'     => $styles['colors'],
            'textStyles' => $styles['textStyles'],
            'summary'    => sprintf(
                '%d color(s), %d text style(s) extracted (%s format).',
                count($styles['colors']),
                count($styles['textStyles']),
                $format
            ),
        ];
    }

    /**
     * Parse the Unframer project XML and extract color/text-style definitions.
     *
     * The XML structure (simplified):
     *   <Colors>
     *     <Color name="/Neutrals/Neutral 950" hex="#010004" r="1" g="0" b="4"/>
     *     ...
     *   </Colors>
     *   <TextStyles>
     *     <TextStyle name="/Headings/80" fontSize="72" fontWeight="500" fontFamily="Geist" lineHeight="1em" letterSpacing="-0.02em"/>
     *     ...
     *   </TextStyles>
     *
     * @param string $xml    Raw XML string.
     * @param string $format 'full' or 'compact'.
     * @return array{colors: array<string, array>, textStyles: array<string, array>}
     */
    private static function parse_xml(string $xml, string $format): array
    {
        $colors     = [];
        $textStyles = [];

        // Strip Markdown code fences if present.
        $xml = preg_replace('/^```(?:xml)?\\s*\\n/', '', $xml);
        $xml = preg_replace('/\\n```\\s*$/', '', $xml);
        $xml = trim($xml);

        // Extract <Colors> block via regex (avoids full XML parsing issues
        // when the XML is embedded in a larger MCP response).
        if (preg_match_all('/<Color\\s+([^>]+)\\/>/i', $xml, $color_matches)) {
            foreach ($color_matches[1] as $attrs_str) {
                $attrs = self::parse_attributes($attrs_str);
                $name  = $attrs['name'] ?? '';
                if (empty($name)) {
                    continue;
                }

                $hex = $attrs['hex'] ?? '';
                $r   = (int) ($attrs['r'] ?? 0);
                $g   = (int) ($attrs['g'] ?? 0);
                $b   = (int) ($attrs['b'] ?? 0);

                if ('compact' === $format) {
                    $colors[$name] = ['hex' => $hex];
                } else {
                    $colors[$name] = [
                        'hex' => $hex,
                        'rgb' => "rgb({$r}, {$g}, {$b})",
                        'r'   => $r,
                        'g'   => $g,
                        'b'   => $b,
                    ];
                }
            }
        }

        // Extract <TextStyles> block.
        if (preg_match_all('/<TextStyle\\s+([^>]+)\\/>/i', $xml, $text_matches)) {
            foreach ($text_matches[1] as $attrs_str) {
                $attrs = self::parse_attributes($attrs_str);
                $name  = $attrs['name'] ?? '';
                if (empty($name)) {
                    continue;
                }

                if ('compact' === $format) {
                    $textStyles[$name] = [
                        'fontSize'   => $attrs['fontSize'] ?? $attrs['fontsize'] ?? '',
                        'fontWeight' => $attrs['fontWeight'] ?? $attrs['fontweight'] ?? '',
                    ];
                } else {
                    $textStyles[$name] = [
                        'fontSize'      => $attrs['fontSize'] ?? $attrs['fontsize'] ?? '',
                        'fontWeight'    => $attrs['fontWeight'] ?? $attrs['fontweight'] ?? '',
                        'fontFamily'    => $attrs['fontFamily'] ?? $attrs['fontfamily'] ?? '',
                        'lineHeight'    => $attrs['lineHeight'] ?? $attrs['lineheight'] ?? '',
                        'letterSpacing' => $attrs['letterSpacing'] ?? $attrs['letterspacing'] ?? '',
                    ];
                }
            }
        }

        return [
            'colors'     => $colors,
            'textStyles' => $textStyles,
        ];
    }

    /**
     * Parse an XML attribute string like: name="foo" hex="#123" r="1"
     * into an associative array.
     *
     * @param string $attrs_str
     * @return array<string, string>
     */
    private static function parse_attributes(string $attrs_str): array
    {
        $attrs = [];
        if (preg_match_all('/([a-zA-Z0-9_-]+)\\s*=\\s*"([^"]*)"/', $attrs_str, $matches)) {
            foreach ($matches[1] as $i => $key) {
                $attrs[$key] = $matches[2][$i];
            }
        }
        return $attrs;
    }
}
