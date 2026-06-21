<?php
declare(strict_types=1);

/**
 * V4 SEO Abilities — SEO toolkit (4 read-only / proposal tools).
 *
 *   - audit-page-seo                  (scored on-page SEO report)
 *   - extract-keywords-from-content   (frequency keyword extraction)
 *   - generate-meta-tags              (proposed title + description)
 *   - generate-schema-markup          (JSON-LD for the page)
 *
 * Pro-gated: register() skips entirely when the local SEO infrastructure is
 * unavailable. Pure analysis helpers (build_seo_report / rank_keywords /
 * propose_meta / build_jsonld) are public static so the execute callbacks
 * stay thin.
 *
 * Dependencies (local, self-contained in NickWebdesign\Adrians):
 *   - V4_Content_Extractor::extract()  — content extraction
 *   - V4_Seo_Meta::get() / ::write()   — SEO plugin meta read/write
 *
 * Architecture: Fully static. Uses Elementor_Data_Helpers trait for page
 * read/write/find/update. Uses self::generate_id() for unique element ids.
 *
 * @package Extra
 * @since   1.7.0
 */

namespace Novamira\AdrianV2\Abilities\Seo;

use Novamira\AdrianV2\Helpers\V4_Props;
use Novamira\AdrianV2\Helpers\V4_Styles;
use Novamira\AdrianV2\Helpers\V4_Color_Contrast;
use Novamira\AdrianV2\Helpers\V4_Content_Extractor;
use Novamira\AdrianV2\Helpers\V4_Seo_Meta;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Store;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Validator;
use Novamira\AdrianV2\Helpers\Ability_Registry;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static ability registrar for SEO toolkit operations.
 *
 * @since 1.7.0
 */
class Seo {
    use Elementor_Data_Helpers;
    use Ability_Registry;
    // NOTE: Audit_Helpers is a static helper class in Novamira\AdrianV2\Helpers (not a trait).
    // Call its methods via \Novamira\AdrianV2\Helpers\Audit_Helpers::method() if needed.

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * Register all SEO abilities (Pro only).
     *
     * Call once from wp_abilities_api_init. Silently skips when
     * EMCP's Content_Extractor or Seo_Meta classes are unavailable.
     */
    public static function register(): void {
        if (!self::is_available()) {
            return;
        }

        self::register_audit_page_seo();
        self::register_extract_keywords();
        self::register_generate_meta_tags();
        self::register_generate_schema_markup();
    }

    /**
     * Whether the SEO infrastructure is available (Pro gate).
     */
    private static function is_available(): bool {
        return true;
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
    // -------------------------------------------------------------------------

    /**
     * Read permission: edit_posts.
     */
    public static function check_read_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Edit permission: edit_post for the specific post (used by apply:true).
     *
     * @param array|null $input The input data.
     */
    public static function check_edit_permission($input = null): bool {
        if (!current_user_can('edit_posts')) {
            return false;
        }

        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id && !current_user_can('edit_post', $post_id)) {
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Site host for internal/external link classification.
     */
    private static function site_host(): string {
        if (!function_exists('home_url')) {
            return '';
        }
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    /**
     * Loads + extracts a page's normalized content, with defensive re-check.
     *
     * @return array|\WP_Error
     */
    private static function extracted(int $post_id) {
        if (!self::is_available()) {
            return new \WP_Error('unavailable', __('SEO infrastructure not available.', 'novamira-adrianv2'));
        }
        $page = self::read_page($post_id);
        if ($page['error'] !== null) {
            return new \WP_Error('read_failed', $page['error']);
        }
        return V4_Content_Extractor::extract($page['elements'], self::site_host());
    }

    // =========================================================================
    // audit-page-seo
    // =========================================================================

    private static function register_audit_page_seo(): void {
        $name = 'novamira-adrianv2/audit-page-seo';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Audit Page SEO', 'novamira-adrianv2'),
            'description'         => __('Audits on-page SEO for an Elementor page (H1, title/meta length, canonical, heading hierarchy, image alts, internal links, word count, optional target-keyword usage). Read-only; returns a scored report.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_audit_page_seo'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'        => ['type' => 'integer', 'description' => __('The page/post ID to audit.', 'novamira-adrianv2')],
                    'target_keyword' => ['type' => 'string', 'description' => __('Optional focus keyword to check usage of.', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'score'   => ['type' => 'integer'],
                    'checks'  => ['type' => 'array'],
                    'summary' => ['type' => 'object'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input
     * @return array|\WP_Error
     */
    public static function execute_audit_page_seo($input) {
        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', __('A valid post_id is required.', 'novamira-adrianv2'));
        }
        $extracted = self::extracted($post_id);
        if (is_wp_error($extracted)) {
            return $extracted;
        }
        $seo    = V4_Seo_Meta::get($post_id);
        $target = isset($input['target_keyword']) ? sanitize_text_field((string) $input['target_keyword']) : '';
        return self::build_seo_report($extracted, $seo, $target);
    }

    // =========================================================================
    // extract-keywords-from-content
    // =========================================================================

    private static function register_extract_keywords(): void {
        $name = 'novamira-adrianv2/extract-keywords-from-content';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Extract Keywords from Content', 'novamira-adrianv2'),
            'description'         => __('Extracts the most frequent meaningful keywords and two-word phrases from a page\'s text (stop-word filtered). No external service. Useful for choosing a target keyword.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_extract_keywords'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'limit'   => ['type' => 'integer', 'description' => __('Max keywords to return (default 20).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'keywords'    => ['type' => 'array'],
                    'bigrams'     => ['type' => 'array'],
                    'total_words' => ['type' => 'integer'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input
     * @return array|\WP_Error
     */
    public static function execute_extract_keywords($input) {
        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', __('A valid post_id is required.', 'novamira-adrianv2'));
        }
        $extracted = self::extracted($post_id);
        if (is_wp_error($extracted)) {
            return $extracted;
        }
        $limit = isset($input['limit']) ? max(1, min(100, absint($input['limit']))) : 20;
        return self::rank_keywords($extracted, $limit);
    }

    // =========================================================================
    // generate-meta-tags
    // =========================================================================

    private static function register_generate_meta_tags(): void {
        $name = 'novamira-adrianv2/generate-meta-tags';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Generate Meta Tags', 'novamira-adrianv2'),
            'description'         => __('Proposes an SEO title (<=60 chars) and meta description (<=155 chars) from the page content, keyword-front-loaded when a target keyword is given. Dry-run by default; with apply:true writes them to the active SEO plugin (Yoast / Rank Math).', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_generate_meta_tags'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'        => ['type' => 'integer'],
                    'target_keyword' => ['type' => 'string'],
                    'apply'          => ['type' => 'boolean', 'description' => __('Write the proposed meta to the active SEO plugin. Defaults to false (dry-run).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'proposed_title'       => ['type' => 'string'],
                    'proposed_description' => ['type' => 'string'],
                    'title_length'         => ['type' => 'integer'],
                    'description_length'   => ['type' => 'integer'],
                    'applied'              => ['type' => 'boolean'],
                    'write_source'         => ['type' => 'string'],
                    'written_fields'       => ['type' => 'array'],
                    'notes'                => ['type' => 'array'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input
     * @return array|\WP_Error
     */
    public static function execute_generate_meta_tags($input) {
        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', __('A valid post_id is required.', 'novamira-adrianv2'));
        }
        $extracted = self::extracted($post_id);
        if (is_wp_error($extracted)) {
            return $extracted;
        }
        $seo       = V4_Seo_Meta::get($post_id);
        $target    = isset($input['target_keyword']) ? sanitize_text_field((string) $input['target_keyword']) : '';
        $site_name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
        $proposal  = self::propose_meta($extracted, $seo, $target, $site_name);

        $proposal['applied']        = false;
        $proposal['write_source']   = '';
        $proposal['written_fields'] = [];

        if (!empty($input['apply'])) {
            $perm = self::check_edit_permission($input);
            if (true !== $perm) {
                return is_wp_error($perm) ? $perm : new \WP_Error('forbidden', __('You do not have permission to edit this page.', 'novamira-adrianv2'));
            }
            $w                          = V4_Seo_Meta::write($post_id, $proposal['proposed_title'], $proposal['proposed_description']);
            $proposal['applied']        = $w['written'];
            $proposal['write_source']   = $w['source'];
            $proposal['written_fields'] = $w['fields'];
            if (!$w['written'] && 'none' === $w['source']) {
                $proposal['notes'][] = __('No SEO plugin (Yoast / Rank Math) detected — meta was not persisted. Install one, or add the tags via generate-schema-markup / a head snippet.', 'novamira-adrianv2');
            }
        }

        return $proposal;
    }

    // =========================================================================
    // generate-schema-markup
    // =========================================================================

    private static function register_generate_schema_markup(): void {
        $name = 'novamira-adrianv2/generate-schema-markup';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Generate Schema Markup', 'novamira-adrianv2'),
            'description'         => __('Generates JSON-LD structured data for the page (Article, LocalBusiness, FAQPage, Service, or Product). LocalBusiness requires a business object (name/address/phone). FAQPage uses a provided faqs array. Dry-run by default; with apply:true injects it into the page via a managed HTML widget (replaced in place on re-apply).', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_generate_schema_markup'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'     => ['type' => 'integer'],
                    'schema_type' => [
                        'type'        => 'string',
                        'enum'        => ['auto', 'Article', 'LocalBusiness', 'FAQPage', 'Service', 'Product'],
                        'description' => __('Schema type, or "auto" to infer.', 'novamira-adrianv2'),
                    ],
                    'business'    => [
                        'type'        => 'object',
                        'description' => __('NAP for LocalBusiness: { name, street, locality, region, postal_code, country, phone, url, price_range }.', 'novamira-adrianv2'),
                    ],
                    'faqs'        => [
                        'type'        => 'array',
                        'description' => __('For FAQPage: array of { question, answer }.', 'novamira-adrianv2'),
                    ],
                    'apply'       => ['type' => 'boolean', 'description' => __('Inject the JSON-LD into the page. Defaults to false (dry-run).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'detected_type' => ['type' => 'string'],
                    'jsonld'        => ['type' => 'string'],
                    'insert_hint'   => ['type' => 'string'],
                    'applied'       => ['type' => 'boolean'],
                    'element_id'    => ['type' => 'string'],
                    'notes'         => ['type' => 'array'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input
     * @return array|\WP_Error
     */
    public static function execute_generate_schema_markup($input) {
        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', __('A valid post_id is required.', 'novamira-adrianv2'));
        }
        $extracted = self::extracted($post_id);
        if (is_wp_error($extracted)) {
            return $extracted;
        }
        $seo      = V4_Seo_Meta::get($post_id);
        $type     = isset($input['schema_type']) ? sanitize_text_field((string) $input['schema_type']) : 'auto';
        $business = isset($input['business']) && is_array($input['business']) ? $input['business'] : [];
        $faqs     = isset($input['faqs']) && is_array($input['faqs']) ? $input['faqs'] : [];
        $url      = $seo['canonical'];
        $result   = self::build_jsonld($type, $extracted, $seo, $business, $faqs, $url);

        $result['applied']    = false;
        $result['element_id'] = '';

        if (!empty($input['apply'])) {
            $perm = self::check_edit_permission($input);
            if (true !== $perm) {
                return is_wp_error($perm) ? $perm : new \WP_Error('forbidden', __('You do not have permission to edit this page.', 'novamira-adrianv2'));
            }
            if ('' === $result['jsonld']) {
                $result['notes'][] = __('No JSON-LD was generated, so nothing was injected.', 'novamira-adrianv2');
                return $result;
            }
            $injected = self::inject_schema($post_id, $result['jsonld']);
            if (is_wp_error($injected)) {
                return $injected;
            }
            $result['applied']    = true;
            $result['element_id'] = $injected;
        }

        return $result;
    }

    /**
     * Injects (or replaces in place) the page's managed JSON-LD HTML widget.
     *
     * Idempotent: the widget's element id is stored in `_novamira_schema_element_id`
     * post meta, so re-applying updates the same widget instead of stacking
     * duplicate schema. A fresh injection appends a full-width container holding
     * the script at the end of the page.
     *
     * @param int    $post_id Post ID.
     * @param string $jsonld  The JSON-LD string.
     * @return string|\WP_Error The HTML-widget element id, or WP_Error.
     */
    private static function inject_schema(int $post_id, string $jsonld) {
        $page = self::read_page($post_id);
        if ($page['error'] !== null) {
            return new \WP_Error('read_failed', $page['error']);
        }
        $elements = $page['elements'];

        $script = '<script type="application/ld+json">' . "\n" . $jsonld . "\n" . '</script>';

        $stored = function_exists('get_post_meta')
            ? (string) get_post_meta($post_id, '_novamira_schema_element_id', true)
            : '';

        if ('' !== $stored && null !== self::find_element($elements, $stored)) {
            // Replace in place.
            if (!self::update_element_settings($elements, $stored, ['html' => $script])) {
                return new \WP_Error('inject_failed', __('Could not update the existing schema widget.', 'novamira-adrianv2'));
            }
            $element_id = $stored;
        } else {
            // Fresh injection: append a full-width container with an HTML widget.
            $element_id = self::generate_id();
            $container  = [
                'id'         => self::generate_id(),
                'elType'     => 'container',
                'widgetType' => null,
                'settings'   => ['content_width' => 'full'],
                'elements'   => [
                    [
                        'id'         => $element_id,
                        'elType'     => 'widget',
                        'widgetType' => 'html',
                        'settings'   => ['html' => $script],
                        'elements'   => [],
                    ],
                ],
            ];
            $elements[] = $container;
            if (function_exists('update_post_meta')) {
                update_post_meta($post_id, '_novamira_schema_element_id', $element_id);
            }
        }

        $save = self::write_page($post_id, $elements);
        if (is_wp_error($save)) {
            return $save;
        }
        return $element_id;
    }

    // =========================================================================
    // Pure analysis helpers (unit-testable, no WordPress)
    // =========================================================================

    /**
     * Builds a scored SEO report from extracted content + resolved SEO meta.
     *
     * @param array  $ex     Content_Extractor output.
     * @param array  $seo    Seo_Meta output.
     * @param string $target Optional target keyword.
     * @return array
     */
    public static function build_seo_report(array $ex, array $seo, string $target = ''): array {
        $checks = [];

        // H1 presence.
        $h1 = 0;
        foreach ($ex['headings'] as $h) {
            if (1 === (int) $h['level']) {
                $h1++;
            }
        }
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'h1_present',
            __('Single H1', 'novamira-adrianv2'),
            (1 === $h1) ? 'pass' : (0 === $h1 ? 'fail' : 'warn'),
            sprintf(/* translators: %d: count */ __('%d H1 heading(s) found.', 'novamira-adrianv2'), $h1),
            (1 === $h1) ? '' : __('A page should have exactly one H1.', 'novamira-adrianv2')
        );

        // Heading hierarchy (no skipped levels).
        $levels = array_map(static function ($h) {
            return (int) $h['level'];
        }, $ex['headings']);
        $skip = false;
        $prev = 0;
        foreach ($levels as $lvl) {
            if ($prev > 0 && $lvl > $prev + 1) {
                $skip = true;
                break;
            }
            $prev = $lvl;
        }
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'heading_hierarchy',
            __('Heading hierarchy', 'novamira-adrianv2'),
            $skip ? 'warn' : 'pass',
            $skip ? __('A heading level is skipped (e.g. H1 → H3).', 'novamira-adrianv2') : __('No skipped heading levels.', 'novamira-adrianv2'),
            $skip ? __('Avoid jumping heading levels; keep them sequential.', 'novamira-adrianv2') : ''
        );

        // Title length.
        $title_len = \Novamira\AdrianV2\Helpers\Audit_Helpers::mb_len($seo['title'] ?? '');
        $tpl_note  = !empty($seo['title_is_template']) ? __(' (contains SEO-plugin template tokens — length is approximate)', 'novamira-adrianv2') : '';
        $checks[]  = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'title_length',
            __('Title length', 'novamira-adrianv2'),
            (0 === $title_len) ? 'fail' : (($title_len >= 30 && $title_len <= 60) ? 'pass' : 'warn'),
            sprintf(/* translators: 1: length, 2: note */ __('SEO title is %1$d characters%2$s.', 'novamira-adrianv2'), $title_len, $tpl_note),
            ($title_len >= 30 && $title_len <= 60) ? '' : __('Aim for a 30–60 character title.', 'novamira-adrianv2')
        );

        // Meta description.
        $desc_len = \Novamira\AdrianV2\Helpers\Audit_Helpers::mb_len($seo['description'] ?? '');
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'meta_description',
            __('Meta description', 'novamira-adrianv2'),
            (0 === $desc_len) ? 'fail' : (($desc_len >= 120 && $desc_len <= 160) ? 'pass' : 'warn'),
            (0 === $desc_len) ? __('No meta description set.', 'novamira-adrianv2') : sprintf(/* translators: %d: length */ __('Meta description is %d characters.', 'novamira-adrianv2'), $desc_len),
            ($desc_len >= 120 && $desc_len <= 160) ? '' : __('Aim for a 120–160 character meta description.', 'novamira-adrianv2')
        );

        // Canonical.
        $has_canonical = '' !== trim((string) ($seo['canonical'] ?? ''));
        $checks[]      = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'canonical',
            __('Canonical URL', 'novamira-adrianv2'),
            $has_canonical ? 'pass' : 'warn',
            $has_canonical ? __('Canonical URL is present.', 'novamira-adrianv2') : __('No canonical URL resolved.', 'novamira-adrianv2'),
            $has_canonical ? '' : __('Set a canonical URL (your SEO plugin usually does this automatically).', 'novamira-adrianv2')
        );

        // Image alts.
        $missing_alt = 0;
        foreach ($ex['images'] as $img) {
            if ('' === trim((string) $img['alt'])) {
                $missing_alt++;
            }
        }
        $total_img = count($ex['images']);
        $checks[]  = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'image_alts',
            __('Image alt text', 'novamira-adrianv2'),
            (0 === $missing_alt) ? 'pass' : 'fail',
            sprintf(/* translators: 1: missing, 2: total */ __('%1$d of %2$d images are missing alt text.', 'novamira-adrianv2'), $missing_alt, $total_img),
            (0 === $missing_alt) ? '' : __('Add descriptive alt text to every meaningful image.', 'novamira-adrianv2')
        );

        // Internal links.
        $internal = 0;
        foreach ($ex['links'] as $l) {
            if (!empty($l['internal'])) {
                $internal++;
            }
        }
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'internal_links',
            __('Internal links', 'novamira-adrianv2'),
            ($internal >= 1) ? 'pass' : 'warn',
            sprintf(/* translators: %d: count */ __('%d internal link(s).', 'novamira-adrianv2'), $internal),
            ($internal >= 1) ? '' : __('Add at least one internal link to related content.', 'novamira-adrianv2')
        );

        // Word count.
        $wc       = (int) $ex['word_count'];
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'word_count',
            __('Content length', 'novamira-adrianv2'),
            ($wc >= 300) ? 'pass' : ($wc >= 150 ? 'warn' : 'fail'),
            sprintf(/* translators: %d: words */ __('%d words of content.', 'novamira-adrianv2'), $wc),
            ($wc >= 300) ? '' : __('Thin content — aim for 300+ words where appropriate.', 'novamira-adrianv2')
        );

        // Target keyword usage.
        if ('' !== $target) {
            $tk        = \Novamira\AdrianV2\Helpers\Audit_Helpers::mb_lower($target);
            $title_has = false !== mb_strpos(\Novamira\AdrianV2\Helpers\Audit_Helpers::mb_lower((string) ($seo['title'] ?? '')), $tk);
            $desc_has  = false !== mb_strpos(\Novamira\AdrianV2\Helpers\Audit_Helpers::mb_lower((string) ($seo['description'] ?? '')), $tk);
            $h1_text   = '';
            foreach ($ex['headings'] as $h) {
                if (1 === (int) $h['level']) {
                    $h1_text = $h['text'];
                    break;
                }
            }
            $h1_has   = false !== mb_strpos(\Novamira\AdrianV2\Helpers\Audit_Helpers::mb_lower($h1_text), $tk);
            $hits     = ($title_has ? 1 : 0) + ($desc_has ? 1 : 0) + ($h1_has ? 1 : 0);
            $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
                'keyword_usage',
                __('Target keyword usage', 'novamira-adrianv2'),
                ($hits >= 2) ? 'pass' : ($hits >= 1 ? 'warn' : 'fail'),
                sprintf(
                    /* translators: 1: keyword, 2: in title, 3: in h1, 4: in description */
                    __('"%1$s" — title: %2$s, H1: %3$s, meta: %4$s.', 'novamira-adrianv2'),
                    $target,
                    $title_has ? '✓' : '✗',
                    $h1_has ? '✓' : '✗',
                    $desc_has ? '✓' : '✗'
                ),
                ($hits >= 2) ? '' : __('Use the target keyword in the title, H1, and meta description.', 'novamira-adrianv2')
            );
        }

        return [
            'score'   => self::score($checks),
            'checks'  => $checks,
            'summary' => self::summary($checks),
        ];
    }

    /**
     * Frequency keyword + bigram extraction.
     *
     * @param array $ex    Content_Extractor output.
     * @param int   $limit Max keywords.
     * @return array
     */
    public static function rank_keywords(array $ex, int $limit = 20): array {
        $text = '';
        foreach ($ex['headings'] as $h) {
            $text .= ' ' . $h['text'];
        }
        foreach ($ex['text_blocks'] as $t) {
            $text .= ' ' . $t['text'];
        }

        $tokens = \Novamira\AdrianV2\Helpers\Audit_Helpers::tokenize($text);
        $total  = count($tokens);

        // Unigrams (stop-word filtered, length >= 3).
        $freq = [];
        foreach ($tokens as $w) {
            if (mb_strlen($w) < 3 || \Novamira\AdrianV2\Helpers\Audit_Helpers::is_stopword($w)) {
                continue;
            }
            $freq[$w] = ($freq[$w] ?? 0) + 1;
        }
        arsort($freq);

        $keywords = [];
        foreach (array_slice($freq, 0, $limit, true) as $term => $count) {
            $keywords[] = [
                'term'  => $term,
                'count' => $count,
                'score' => $total > 0 ? round($count / $total, 4) : 0,
            ];
        }

        // Bigrams (neither word a stop-word).
        $bg = [];
        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $a = $tokens[$i];
            $b = $tokens[$i + 1];
            if (mb_strlen($a) < 3 || mb_strlen($b) < 3 || \Novamira\AdrianV2\Helpers\Audit_Helpers::is_stopword($a) || \Novamira\AdrianV2\Helpers\Audit_Helpers::is_stopword($b)) {
                continue;
            }
            $key      = $a . ' ' . $b;
            $bg[$key] = ($bg[$key] ?? 0) + 1;
        }
        arsort($bg);
        $bigrams = [];
        foreach (array_slice($bg, 0, $limit, true) as $term => $count) {
            if ($count < 2) {
                continue; // Only surface phrases that recur.
            }
            $bigrams[] = ['term' => $term, 'count' => $count];
        }

        return [
            'keywords'    => $keywords,
            'bigrams'     => $bigrams,
            'total_words' => $total,
        ];
    }

    /**
     * Proposes an SEO title + meta description from page content.
     *
     * @param array  $ex        Content_Extractor output.
     * @param array  $seo       Seo_Meta output.
     * @param string $target    Optional target keyword.
     * @param string $site_name Optional site name to append to the title.
     * @return array
     */
    public static function propose_meta(array $ex, array $seo, string $target = '', string $site_name = ''): array {
        $notes = [];

        // Base title: first H1, else existing SEO title, else first heading.
        $base = '';
        foreach ($ex['headings'] as $h) {
            if (1 === (int) $h['level']) {
                $base = $h['text'];
                break;
            }
        }
        if ('' === $base) {
            $base = trim((string) ($seo['title'] ?? ''));
        }
        if ('' === $base && !empty($ex['headings'])) {
            $base = $ex['headings'][0]['text'];
        }
        if ('' === $base) {
            $base = __('Untitled', 'novamira-adrianv2');
            $notes[] = __('No heading found — title falls back to a placeholder.', 'novamira-adrianv2');
        }

        $title = $base;
        if ('' !== $site_name && (\Novamira\AdrianV2\Helpers\Audit_Helpers::mb_len($base) + 3 + \Novamira\AdrianV2\Helpers\Audit_Helpers::mb_len($site_name)) <= 60) {
            $title = $base . ' | ' . $site_name;
        }
        $title = \Novamira\AdrianV2\Helpers\Audit_Helpers::truncate($title, 60);

        // Description: first substantial text block, keyword-front-loaded.
        $body = '';
        foreach ($ex['text_blocks'] as $t) {
            if (\Novamira\AdrianV2\Helpers\Audit_Helpers::mb_len($t['text']) >= 40) {
                $body = $t['text'];
                break;
            }
        }
        if ('' === $body && !empty($ex['text_blocks'])) {
            $body = $ex['text_blocks'][0]['text'];
        }

        $desc = $body;
        if ('' !== $target && false === mb_strpos(\Novamira\AdrianV2\Helpers\Audit_Helpers::mb_lower($desc), \Novamira\AdrianV2\Helpers\Audit_Helpers::mb_lower($target))) {
            $desc = rtrim(ucfirst($target), '.') . ': ' . $desc;
            $notes[] = __('Target keyword front-loaded into the description.', 'novamira-adrianv2');
        }
        $desc = \Novamira\AdrianV2\Helpers\Audit_Helpers::truncate($desc, 155);
        if ('' === $desc) {
            $notes[] = __('No body text found to build a description from.', 'novamira-adrianv2');
        }

        return [
            'proposed_title'       => $title,
            'proposed_description' => $desc,
            'title_length'         => \Novamira\AdrianV2\Helpers\Audit_Helpers::mb_len($title),
            'description_length'   => \Novamira\AdrianV2\Helpers\Audit_Helpers::mb_len($desc),
            'notes'                => $notes,
        ];
    }

    /**
     * Builds JSON-LD structured data.
     *
     * @param string $type     Requested type or 'auto'.
     * @param array  $ex       Content_Extractor output.
     * @param array  $seo      Seo_Meta output.
     * @param array  $business Business NAP object.
     * @param array  $faqs     FAQ pairs.
     * @param string $url      Page URL.
     * @return array
     */
    public static function build_jsonld(string $type, array $ex, array $seo, array $business, array $faqs, string $url): array {
        $notes = [];
        $name  = '';
        foreach ($ex['headings'] as $h) {
            if (1 === (int) $h['level']) {
                $name = $h['text'];
                break;
            }
        }
        if ('' === $name) {
            $name = trim((string) ($seo['title'] ?? ''));
        }

        // Resolve 'auto'.
        if ('' === $type || 'auto' === $type) {
            if (!empty($business)) {
                $type = 'LocalBusiness';
            } elseif (!empty($faqs)) {
                $type = 'FAQPage';
            } else {
                $type = 'Article';
            }
            $notes[] = sprintf(/* translators: %s: type */ __('Auto-detected schema type: %s.', 'novamira-adrianv2'), $type);
        }

        switch ($type) {
            case 'LocalBusiness':
                if (empty($business)) {
                    $notes[] = __('LocalBusiness needs a business object (name/address/phone) — emitting a minimal stub.', 'novamira-adrianv2');
                }
                $schema = [
                    '@context' => 'https://schema.org',
                    '@type'    => 'LocalBusiness',
                    'name'     => $business['name'] ?? $name,
                ];
                if (!empty($business['phone'])) {
                    $schema['telephone'] = (string) $business['phone'];
                }
                if (!empty($business['url']) || '' !== $url) {
                    $schema['url'] = (string) ($business['url'] ?? $url);
                }
                if (!empty($business['price_range'])) {
                    $schema['priceRange'] = (string) $business['price_range'];
                }
                $address = array_filter([
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $business['street'] ?? '',
                    'addressLocality' => $business['locality'] ?? '',
                    'addressRegion'   => $business['region'] ?? '',
                    'postalCode'      => $business['postal_code'] ?? '',
                    'addressCountry'  => $business['country'] ?? '',
                ]);
                if (count($address) > 1) {
                    $schema['address'] = $address;
                }
                break;

            case 'FAQPage':
                $entities = [];
                foreach ($faqs as $f) {
                    if (!is_array($f) || empty($f['question']) || empty($f['answer'])) {
                        continue;
                    }
                    $entities[] = [
                        '@type'          => 'Question',
                        'name'           => (string) $f['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) $f['answer']],
                    ];
                }
                if (empty($entities)) {
                    $notes[] = __('FAQPage needs a faqs array of {question, answer} — none provided.', 'novamira-adrianv2');
                }
                $schema = [
                    '@context'   => 'https://schema.org',
                    '@type'      => 'FAQPage',
                    'mainEntity' => $entities,
                ];
                break;

            case 'Product':
                $schema = [
                    '@context'    => 'https://schema.org',
                    '@type'       => 'Product',
                    'name'        => $name,
                    'description' => (string) ($seo['description'] ?? ''),
                ];
                break;

            case 'Service':
                $schema = [
                    '@context'    => 'https://schema.org',
                    '@type'       => 'Service',
                    'name'        => $name,
                    'description' => (string) ($seo['description'] ?? ''),
                ];
                break;

            case 'Article':
            default:
                $type   = 'Article';
                $schema = [
                    '@context'         => 'https://schema.org',
                    '@type'            => 'Article',
                    'headline'         => $name,
                    'description'      => (string) ($seo['description'] ?? ''),
                ];
                if ('' !== $url) {
                    $schema['mainEntityOfPage'] = $url;
                }
                break;
        }

        $jsonld = function_exists('wp_json_encode')
            ? wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return [
            'detected_type' => $type,
            'jsonld'        => is_string($jsonld) ? $jsonld : '',
            'insert_hint'   => __('Insert inside a <script type="application/ld+json"> tag in the page head or via your SEO plugin.', 'novamira-adrianv2'),
            'notes'         => $notes,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal scoring utilities (not shared — differ from A11Y)
    // -------------------------------------------------------------------------

    /**
     * Computes a 0-100 score (pass=1, warn=0.5, fail=0).
     */
    private static function score(array $checks): int {
        if (empty($checks)) {
            return 0;
        }
        $sum = 0.0;
        foreach ($checks as $c) {
            $sum += ('pass' === $c['status']) ? 1.0 : ('warn' === $c['status'] ? 0.5 : 0.0);
        }
        return (int) round(100 * $sum / count($checks));
    }

    /**
     * Tallies pass/warn/fail counts.
     */
    private static function summary(array $checks): array {
        $s = ['passes' => 0, 'warnings' => 0, 'failures' => 0];
        foreach ($checks as $c) {
            if ('pass' === $c['status']) {
                $s['passes']++;
            } elseif ('warn' === $c['status']) {
                $s['warnings']++;
            } else {
                $s['failures']++;
            }
        }
        return $s;
    }
}
