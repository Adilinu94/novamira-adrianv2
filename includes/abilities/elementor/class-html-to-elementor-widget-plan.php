<?php
declare(strict_types=1);

/**
 * Ability 36: HTML to Elementor Widget Plan
 *
 * Analyzes arbitrary HTML and proposes a native Elementor widget/container plan.
 * This is intentionally a planning ability, not a blind converter: it identifies
 * which parts should become widgets, which parts require CSS/JS, and where HTML
 * widgets are still justified.
 */

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Html_To_Elementor_Widget_Plan
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/html-to-elementor-widget-plan', [
            'label'       => 'HTML to Elementor Widget Plan',
            'description' => 'Analyzes HTML and creates a structured Elementor widget conversion plan. Maps tags to native v4/v3 widgets, extracts CSS/JS assets, flags unconvertible parts, estimates native widget coverage, and returns a simplified tree for building pages with Elementor widgets instead of HTML dumps.',
            'category'    => 'adrianv2-elementor',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'html' => [
                        'type'        => 'string',
                        'description' => 'Full or partial HTML source to analyze.',
                    ],
                    'target_surface' => [
                        'type'        => 'string',
                        'description' => 'Target Elementor surface: v4 atomic or legacy v3.',
                        'enum'        => ['v4', 'v3'],
                        'default'     => 'v4',
                    ],
                    'include_tree' => [
                        'type'        => 'boolean',
                        'description' => 'Include the simplified conversion tree. Default true.',
                    ],
                    'max_nodes' => [
                        'type'        => 'integer',
                        'description' => 'Maximum DOM nodes to include in the tree. Default 250.',
                    ],
                ],
                'required' => ['html'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'             => ['type' => 'boolean'],
                    'target_surface'      => ['type' => 'string'],
                    'summary'             => ['type' => 'object'],
                    'stats'               => ['type' => 'object'],
                    'native_widget_ratio' => ['type' => 'number'],
                    'css_inventory'       => ['type' => 'object'],
                    'js_inventory'        => ['type' => 'object'],
                    'unconverted'         => ['type' => 'array'],
                    'recommendations'     => ['type' => 'array'],
                    'tree'                => ['type' => 'array'],
                    'error'               => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $html = isset($input['html']) ? (string) $input['html'] : '';
        if (trim($html) === '') {
            return ['success' => false, 'error' => 'html is required.'];
        }

        $target_surface = in_array($input['target_surface'] ?? 'v4', ['v4', 'v3'], true) ? $input['target_surface'] : 'v4';
        $include_tree   = array_key_exists('include_tree', $input) ? (bool) $input['include_tree'] : true;
        $max_nodes      = isset($input['max_nodes']) ? max(1, min(1000, (int) $input['max_nodes'])) : 250;

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        if (!$loaded) {
            return ['success' => false, 'error' => 'HTML could not be parsed.'];
        }

        $ctx = [
            'target_surface' => $target_surface,
            'max_nodes'      => $max_nodes,
            'node_count'     => 0,
            'stats'          => [
                'total_elements'       => 0,
                'native_candidates'    => 0,
                'container_candidates' => 0,
                'html_required'        => 0,
                'css_blocks'           => 0,
                'script_blocks'        => 0,
                'inline_event_handlers'=> 0,
                'inline_styles'        => 0,
                'images'               => 0,
                'links'                => 0,
                'forms'                => 0,
            ],
            'tag_counts'      => [],
            'class_counts'    => [],
            'ids'             => [],
            'css_blocks'      => [],
            'script_blocks'   => [],
            'unconverted'     => [],
            'recommendations' => [],
        ];

        $roots = [];
        $body = $doc->getElementsByTagName('body')->item(0);
        $start = $body ?: $doc;
        foreach ($start->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $node = self::analyze_element($child, $ctx);
                if ($node) {
                    $roots[] = $node;
                }
            }
        }

        $total_relevant = max(1, $ctx['stats']['native_candidates'] + $ctx['stats']['html_required']);
        $native_ratio = round($ctx['stats']['native_candidates'] / $total_relevant, 3);

        if ($ctx['stats']['script_blocks'] > 0 || $ctx['stats']['inline_event_handlers'] > 0) {
            $ctx['recommendations'][] = 'Keep behavior as page-level JavaScript or a small dedicated widget; native Elementor widgets cannot coordinate arbitrary shared state by themselves.';
        }
        if ($ctx['stats']['css_blocks'] > 0 || $ctx['stats']['inline_styles'] > 0) {
            $ctx['recommendations'][] = 'Move repeated visual patterns into Elementor v4 local styles/global classes where possible; keep keyframes, pseudo-elements, and cross-widget state selectors as page CSS.';
        }
        if ($ctx['stats']['images'] > 0) {
            $ctx['recommendations'][] = 'Use Elementor Image/e-image widgets for real images. Inline SVG can be uploaded to media and then used as SVG/Image when it is reusable.';
        }
        if ($native_ratio < 0.7) {
            $ctx['recommendations'][] = 'Native coverage is low. Build an exact HTML reference first, then convert component groups incrementally.';
        } else {
            $ctx['recommendations'][] = 'Native coverage is high enough to build a widget-first Elementor version with limited HTML fallbacks.';
        }

        arsort($ctx['tag_counts']);
        arsort($ctx['class_counts']);

        return [
            'success'        => true,
            'target_surface' => $target_surface,
            'summary'        => [
                'recommended_build_strategy' => self::strategy($ctx, $native_ratio),
                'primary_surface'            => $target_surface === 'v4' ? 'Elementor v4 atomic widgets/containers' : 'Legacy v3 Elementor containers/widgets',
                'native_widget_ratio'        => $native_ratio,
            ],
            'stats' => array_merge($ctx['stats'], [
                'tag_counts'   => array_slice($ctx['tag_counts'], 0, 30, true),
                'class_counts' => array_slice($ctx['class_counts'], 0, 40, true),
                'ids'          => array_values(array_slice(array_unique($ctx['ids']), 0, 80)),
            ]),
            'native_widget_ratio' => $native_ratio,
            'css_inventory' => [
                'count'  => count($ctx['css_blocks']),
                'blocks' => array_slice($ctx['css_blocks'], 0, 10),
            ],
            'js_inventory' => [
                'count'  => count($ctx['script_blocks']),
                'blocks' => array_slice($ctx['script_blocks'], 0, 10),
            ],
            'unconverted'     => $ctx['unconverted'],
            'recommendations' => array_values(array_unique($ctx['recommendations'])),
            'tree'            => $include_tree ? $roots : [],
        ];
    }

    private static function analyze_element(\DOMElement $el, array &$ctx)
    {
        if ($ctx['node_count'] >= $ctx['max_nodes']) {
            return null;
        }
        $ctx['node_count']++;

        $tag = strtolower($el->tagName);
        $ctx['stats']['total_elements']++;
        $ctx['tag_counts'][$tag] = ($ctx['tag_counts'][$tag] ?? 0) + 1;

        $classes = self::tokenize_classes($el->getAttribute('class'));
        foreach ($classes as $class) {
            $ctx['class_counts'][$class] = ($ctx['class_counts'][$class] ?? 0) + 1;
        }
        $id = $el->getAttribute('id');
        if ($id !== '') {
            $ctx['ids'][] = $id;
        }
        if ($el->hasAttribute('style')) {
            $ctx['stats']['inline_styles']++;
        }

        $inline_handlers = self::inline_handlers($el);
        if (!empty($inline_handlers)) {
            $ctx['stats']['inline_event_handlers'] += count($inline_handlers);
        }

        $mapping = self::map_tag($tag, $el, $ctx['target_surface']);
        if ($mapping['native']) {
            $ctx['stats']['native_candidates']++;
        } else {
            $ctx['stats']['html_required']++;
        }
        if ($mapping['role'] === 'container') {
            $ctx['stats']['container_candidates']++;
        }
        if ($tag === 'img') {
            $ctx['stats']['images']++;
        }
        if ($tag === 'a') {
            $ctx['stats']['links']++;
        }
        if (in_array($tag, ['form', 'input', 'textarea', 'select'], true)) {
            $ctx['stats']['forms']++;
        }

        if ($tag === 'style') {
            $css = trim($el->textContent);
            $ctx['stats']['css_blocks']++;
            $ctx['css_blocks'][] = [
                'selector_hint' => self::selector_hint($el),
                'length'        => strlen($css),
                'features'      => self::css_features($css),
                'sample'        => mb_substr(preg_replace('/\s+/', ' ', $css), 0, 240),
            ];
        }

        if ($tag === 'script') {
            $js = trim($el->textContent);
            $ctx['stats']['script_blocks']++;
            $ctx['script_blocks'][] = [
                'selector_hint' => self::selector_hint($el),
                'length'        => strlen($js),
                'features'      => self::js_features($js),
                'sample'        => mb_substr(preg_replace('/\s+/', ' ', $js), 0, 240),
            ];
        }

        if (!$mapping['native'] || !empty($inline_handlers)) {
            $ctx['unconverted'][] = [
                'tag'             => $tag,
                'selector_hint'   => self::selector_hint($el),
                'reason'          => $mapping['reason'] ?: 'Inline event handler must be moved out of markup. The element itself can usually remain a native widget.',
                'inline_handlers' => $inline_handlers,
                'suggestion'      => $mapping['fallback'] ?: 'Use the native widget for the visual element and move the handler logic into page-level JavaScript or a custom widget controller.',
            ];
        }

        $children = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $child_node = self::analyze_element($child, $ctx);
                if ($child_node) {
                    $children[] = $child_node;
                }
            }
        }

        return [
            'tag'              => $tag,
            'selector_hint'    => self::selector_hint($el),
            'text_sample'      => self::text_sample($el),
            'classes'          => $classes,
            'id'               => $id ?: null,
            'elementor_target' => $mapping['target'],
            'native'           => $mapping['native'],
            'confidence'       => $mapping['confidence'],
            'role'             => $mapping['role'],
            'notes'            => $mapping['notes'],
            'children'         => $children,
        ];
    }

    private static function map_tag(string $tag, \DOMElement $el, string $surface): array
    {
        $v4 = $surface === 'v4';
        $target = null;
        $role = 'widget';
        $native = true;
        $confidence = 0.9;
        $reason = '';
        $fallback = '';
        $notes = [];

        $container_tags = ['body', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'figure', 'figcaption', 'ul', 'ol', 'li'];
        if (in_array($tag, $container_tags, true)) {
            $target = $v4 ? 'e-div-block/e-flexbox container' : 'container';
            $role = 'container';
            $confidence = in_array($tag, ['ul', 'ol', 'li'], true) ? 0.7 : 0.95;
            if (in_array($tag, ['ul', 'ol'], true)) {
                $notes[] = 'Could become an Icon List only when list items are simple text/link rows; otherwise keep as containers.';
            }
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if (preg_match('/^h[1-6]$/', $tag)) {
            $target = $v4 ? 'e-heading' : 'heading';
            $notes[] = 'Preserve original heading tag.';
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if ($tag === 'br') {
            $target = 'inline line break inside parent text';
            $role = 'inline';
            $confidence = 0.85;
            $notes[] = 'Preserve as a line break in the parent heading/text widget content.';
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if (in_array($tag, ['p', 'span', 'strong', 'em', 'b', 'i', 'small', 'blockquote', 'pre', 'code', 'kbd', 'samp'], true)) {
            $target = $v4 ? 'e-paragraph' : 'text-editor';
            $confidence = in_array($tag, ['strong', 'em', 'b', 'i', 'span'], true) ? 0.75 : 0.9;
            if (in_array($tag, ['strong', 'em', 'b', 'i', 'span'], true)) {
                $notes[] = 'May be better merged into parent rich text if it is inline-only.';
            }
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if (in_array($tag, ['a', 'button'], true)) {
            $target = $v4 ? 'e-button' : 'button';
            $confidence = self::has_block_children($el) ? 0.65 : 0.9;
            if (self::has_block_children($el)) {
                $notes[] = 'Complex nested link/button may require container plus link settings instead of a button widget.';
            }
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if ($tag === 'img') {
            $target = $v4 ? 'e-image' : 'image';
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if ($tag === 'svg') {
            $target = $v4 ? 'e-svg or e-image after media upload' : 'image/html';
            $confidence = 0.55;
            $notes[] = 'Inline SVG should usually be uploaded to media first, then inserted as SVG/Image widget.';
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if (in_array($tag, ['video', 'audio', 'iframe', 'canvas'], true)) {
            $native = $tag === 'video' && $v4;
            $target = $native ? 'e-self-hosted-video or e-youtube' : 'html';
            $confidence = $native ? 0.7 : 0.35;
            $reason = 'Embedded or media runtime element does not always have a direct editable widget equivalent.';
            $fallback = 'Use the closest media widget when possible, otherwise an HTML widget.';
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if (in_array($tag, ['style', 'script'], true)) {
            $native = false;
            $target = $tag === 'style' ? 'page_css' : 'page_js';
            $confidence = 0.2;
            $reason = $tag === 'style' ? 'CSS belongs in page/custom CSS or Elementor style props, not as a visible widget.' : 'JavaScript behavior must stay as JS or become a custom widget.';
            $fallback = $tag === 'style' ? 'Extract rules into native style props, global classes, or page CSS.' : 'Move behavior into page JS, a code snippet, or a dedicated custom widget.';
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        if (in_array($tag, ['form', 'input', 'textarea', 'select', 'label'], true)) {
            $native = false;
            $target = 'form/metform/html';
            $confidence = 0.4;
            $reason = 'Forms need plugin-specific widgets and validation handling.';
            $fallback = 'Use Elementor Form, MetForm, or keep as HTML only if static/non-submitting.';
            return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
        }

        $native = false;
        $target = 'html';
        $confidence = 0.25;
        $reason = 'No safe generic Elementor widget mapping for this tag.';
        $fallback = 'Use an HTML widget or create a dedicated atomic widget if reused.';
        return compact('target', 'role', 'native', 'confidence', 'reason', 'fallback', 'notes');
    }

    private static function strategy(array $ctx, float $native_ratio): string
    {
        if ($ctx['stats']['script_blocks'] > 0 || $ctx['stats']['inline_event_handlers'] > 0) {
            return 'Build native widget structure first, keep a small page-level JS controller for behavior, and use the exact HTML reference as visual QA baseline.';
        }
        if ($native_ratio >= 0.85) {
            return 'Build directly with native Elementor widgets and use minimal page CSS for cross-widget layout only.';
        }
        return 'Create an exact reference, then convert stable sections into native widgets incrementally.';
    }

    private static function tokenize_classes(string $class_attr): array
    {
        $class_attr = trim($class_attr);
        if ($class_attr === '') {
            return [];
        }
        return array_values(array_filter(preg_split('/\s+/', $class_attr)));
    }

    private static function selector_hint(\DOMElement $el): string
    {
        $tag = strtolower($el->tagName);
        $id = $el->getAttribute('id');
        if ($id !== '') {
            return $tag . '#' . $id;
        }
        $classes = self::tokenize_classes($el->getAttribute('class'));
        if (!empty($classes)) {
            return $tag . '.' . implode('.', array_slice($classes, 0, 3));
        }
        return $tag;
    }

    private static function text_sample(\DOMElement $el): string
    {
        if (in_array(strtolower($el->tagName), ['script', 'style'], true)) {
            return '';
        }
        $text = trim(preg_replace('/\s+/', ' ', $el->textContent));
        return mb_substr($text, 0, 120);
    }

    private static function inline_handlers(\DOMElement $el): array
    {
        $handlers = [];
        foreach ($el->attributes ?? [] as $attr) {
            if (str_starts_with(strtolower($attr->name), 'on')) {
                $handlers[] = $attr->name;
            }
        }
        return $handlers;
    }

    private static function has_block_children(\DOMElement $el): bool
    {
        $block_tags = ['div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'figure', 'ul', 'ol', 'li'];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array(strtolower($child->tagName), $block_tags, true)) {
                return true;
            }
        }
        return false;
    }

    private static function css_features(string $css): array
    {
        $features = [];
        foreach (['@keyframes', '@media', ':hover', '::before', '::after', 'position: fixed', 'position:absolute', 'display:grid', 'display: grid', 'display:flex', 'display: flex', 'transform', 'transition'] as $needle) {
            if (stripos($css, $needle) !== false) {
                $features[] = $needle;
            }
        }
        return $features;
    }

    private static function js_features(string $js): array
    {
        $features = [];
        $checks = [
            'querySelector'    => 'DOM querying',
            'addEventListener' => 'event listeners',
            'classList'        => 'state classes',
            'wheel'            => 'wheel control',
            'touch'            => 'touch control',
            'keydown'          => 'keyboard control',
            'setTimeout'       => 'timed transitions',
        ];
        foreach ($checks as $needle => $label) {
            if (stripos($js, $needle) !== false) {
                $features[] = $label;
            }
        }
        return array_values(array_unique($features));
    }
}

add_action('wp_abilities_api_init', [Html_To_Elementor_Widget_Plan::class, 'register']);
