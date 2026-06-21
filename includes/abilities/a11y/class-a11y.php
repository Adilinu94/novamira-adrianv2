<?php
declare(strict_types=1);

/**
 * V4 Accessibility (A11Y) Abilities — a11y toolkit (3 read-only / dry-run tools).
 *
 *   - audit-page-a11y           (WCAG-oriented report: contrast, alts,
 *                                heading hierarchy, link text, form labels)
 *   - fix-color-contrast        (dry-run by default; apply:true to write)
 *   - add-alt-text-from-context (dry-run by default; apply:true to write)
 *
 * The fixers are dry-run-by-default — no destructive operations without
 * explicit opt-in. Contrast resolution is best-effort (inconclusive when
 * background can't be resolved).
 *
 * Dependencies (local, self-contained in NickWebdesign\Adrians):
 *   - V4_Content_Extractor::extract()  — content extraction
 *   - V4_Color_Contrast                — contrast math (hex_to_rgb,
 *     contrast_ratio, passes, suggest_adjusted)
 *
 * Architecture: Fully static. Uses Elementor_Data_Helpers trait for page
 * read/write/find/update.
 *
 * @package Extra
 * @since   1.8.0
 */

namespace Novamira\AdrianV2\Abilities\A11y;

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
 * Static ability registrar for accessibility toolkit operations.
 *
 * @since 1.8.0
 */
class A11y {
    use Elementor_Data_Helpers;
    use Ability_Registry;
    // NOTE: Audit_Helpers is a static helper class in Novamira\AdrianV2\Helpers (not a trait).
    // Call its methods via \Novamira\AdrianV2\Helpers\Audit_Helpers::method() if needed.

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * Generic / non-descriptive link phrases flagged by the audit.
     *
     * @var string[]
     */
    private static array $generic_link_text = [
        'click here', 'here', 'read more', 'learn more', 'more', 'this',
        'link', 'this link', 'click', 'go', 'details',
    ];

    /**
     * Register all A11Y abilities (Pro only).
     *
     * Call once from wp_abilities_api_init. Silently skips when
     * the local Color_Contrast class is unavailable (Pro gate).
     */
    public static function register(): void {
        if (!self::is_available()) {
            return;
        }

        self::register_audit_page_a11y();
        self::register_fix_color_contrast();
        self::register_add_alt_text();
    }

    /**
     * Whether the A11Y infrastructure is available (Pro gate).
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
     * Edit permission for the fixers: edit_posts (per-post ownership is
     * additionally enforced in the execute callback before any write).
     */
    public static function check_edit_permission(): bool {
        return current_user_can('edit_posts');
    }

    // -------------------------------------------------------------------------
    // Shared helper
    // -------------------------------------------------------------------------

    /**
     * Loads + extracts a page's normalized content.
     *
     * @return array|\WP_Error
     */
    private static function extracted(int $post_id) {
        if (!self::is_available()) {
            return new \WP_Error('unavailable', __('A11Y infrastructure not available.', 'novamira-adrianv2'));
        }
        $page = self::read_page($post_id);
        if ($page['error'] !== null) {
            return new \WP_Error('read_failed', $page['error']);
        }
        $host = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
        return V4_Content_Extractor::extract($page['elements'], $host);
    }

    // =========================================================================
    // audit-page-a11y
    // =========================================================================

    private static function register_audit_page_a11y(): void {
        $name = 'novamira-adrianv2/audit-page-a11y';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Audit Page Accessibility', 'novamira-adrianv2'),
            'description'         => __('Audits a page for accessibility issues: color contrast (best-effort), missing image alt text, heading hierarchy, generic link text, and form-label coverage. Read-only; returns a scored WCAG-oriented report.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_audit_page_a11y'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer', 'description' => __('The page/post ID to audit.', 'novamira-adrianv2')],
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
    public static function execute_audit_page_a11y($input) {
        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', __('A valid post_id is required.', 'novamira-adrianv2'));
        }
        $extracted = self::extracted($post_id);
        if (is_wp_error($extracted)) {
            return $extracted;
        }
        return self::build_a11y_report($extracted);
    }

    // =========================================================================
    // fix-color-contrast (dry-run by default; apply:true to write)
    // =========================================================================

    private static function register_fix_color_contrast(): void {
        $name = 'novamira-adrianv2/fix-color-contrast';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Fix Color Contrast', 'novamira-adrianv2'),
            'description'         => __('Proposes (and, with apply:true, writes) adjusted text colors so failing text/background pairs meet WCAG AA. Dry-run by default — returns the proposed changes without modifying the page unless apply is true. Reversible via Elementor revisions.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_fix_color_contrast'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'      => ['type' => 'integer'],
                    'element_id'   => ['type' => 'string', 'description' => __('Optional: only fix this element.', 'novamira-adrianv2')],
                    'target_ratio' => ['type' => 'number', 'description' => __('Target contrast ratio (default 4.5).', 'novamira-adrianv2')],
                    'apply'        => ['type' => 'boolean', 'description' => __('Write the changes. Defaults to false (dry-run preview).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'applied'  => ['type' => 'boolean'],
                    'count'    => ['type' => 'integer'],
                    'proposed' => ['type' => 'array'],
                    'changes'  => ['type' => 'array'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input
     * @return array|\WP_Error
     */
    public static function execute_fix_color_contrast($input) {
        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', __('A valid post_id is required.', 'novamira-adrianv2'));
        }
        $extracted = self::extracted($post_id);
        if (is_wp_error($extracted)) {
            return $extracted;
        }

        $element_id = isset($input['element_id']) ? sanitize_text_field((string) $input['element_id']) : '';
        $target     = isset($input['target_ratio']) ? (float) $input['target_ratio'] : V4_Color_Contrast::AA_NORMAL;
        $fixes      = self::propose_contrast_fixes($extracted, $element_id !== '' ? $element_id : null, $target);

        if (empty($input['apply'])) {
            return ['applied' => false, 'count' => count($fixes), 'proposed' => $fixes];
        }

        // Writes require per-post ownership.
        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_Error('forbidden', __('You do not have permission to edit this page.', 'novamira-adrianv2'));
        }

        $page    = self::read_page($post_id);
        $applied = 0;
        foreach ($fixes as $f) {
            if ('' === $f['color_key']) {
                continue;
            }
            if (self::update_element_settings($page['elements'], $f['element_id'], [$f['color_key'] => $f['to']])) {
                $applied++;
            }
        }
        if ($applied > 0) {
            $save = self::write_page($post_id, $page['elements']);
            if (is_wp_error($save)) {
                return $save;
            }
        }
        return ['applied' => true, 'count' => $applied, 'changes' => $fixes];
    }

    // =========================================================================
    // add-alt-text-from-context (dry-run by default; apply:true to write)
    // =========================================================================

    private static function register_add_alt_text(): void {
        $name = 'novamira-adrianv2/add-alt-text-from-context';
        self::$ability_names[] = $name;

        wp_register_ability($name, [
            'label'               => __('Add Alt Text from Context', 'novamira-adrianv2'),
            'description'         => __('Proposes (and, with apply:true, writes) alt text for images that lack it, derived from the image filename, the nearest heading, or the page title. No AI call. Dry-run by default; writes to the media library alt + the image widget when applied.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_add_alt_text'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => ['type' => 'integer'],
                    'apply'   => ['type' => 'boolean', 'description' => __('Write the alt text. Defaults to false (dry-run preview).', 'novamira-adrianv2')],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'applied'  => ['type' => 'boolean'],
                    'count'    => ['type' => 'integer'],
                    'proposed' => ['type' => 'array'],
                    'skipped'  => ['type' => 'array'],
                    'changes'  => ['type' => 'array'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
    }

    /**
     * @param array $input
     * @return array|\WP_Error
     */
    public static function execute_add_alt_text($input) {
        $post_id = absint($input['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', __('A valid post_id is required.', 'novamira-adrianv2'));
        }
        $extracted = self::extracted($post_id);
        if (is_wp_error($extracted)) {
            return $extracted;
        }

        $title     = function_exists('get_the_title') ? (string) get_the_title($post_id) : '';
        $proposals = self::propose_alt_texts($extracted, $title);

        if (empty($input['apply'])) {
            return ['applied' => false, 'count' => count($proposals), 'proposed' => $proposals];
        }

        if (!current_user_can('edit_post', $post_id)) {
            return new \WP_Error('forbidden', __('You do not have permission to edit this page.', 'novamira-adrianv2'));
        }

        $page    = self::read_page($post_id);
        $applied = 0;
        $skipped = [];
        $dirty   = false;

        foreach ($proposals as $p) {
            if ((int) $p['attachment_id'] > 0) {
                if (function_exists('update_post_meta')) {
                    update_post_meta((int) $p['attachment_id'], '_wp_attachment_image_alt', $p['proposed_alt']);
                }
                // Also set the widget-level alt so this specific image renders it.
                $el    = self::find_element($page['elements'], $p['element_id']);
                $image = (is_array($el) && isset($el['settings']['image']) && is_array($el['settings']['image']))
                    ? $el['settings']['image'] : null;
                if (null !== $image) {
                    $image['alt'] = $p['proposed_alt'];
                    if (self::update_element_settings($page['elements'], $p['element_id'], ['image' => $image])) {
                        $dirty = true;
                    }
                }
                $applied++;
            } else {
                // Raw <img> inside an HTML widget — can't be safely auto-written.
                $skipped[] = $p['element_id'];
            }
        }
        if ($dirty) {
            $save = self::write_page($post_id, $page['elements']);
            if (is_wp_error($save)) {
                return $save;
            }
        }
        return ['applied' => true, 'count' => $applied, 'skipped' => $skipped, 'changes' => $proposals];
    }

    // =========================================================================
    // Pure analysis helpers (unit-testable with fixtures)
    // =========================================================================

    /**
     * Builds a scored accessibility report from extracted content.
     *
     * Contrast resolution is best-effort: pairs whose background can't be
     * resolved are reported as inconclusive (never asserted as failures).
     *
     * @param array $ex Content_Extractor output.
     * @return array
     */
    public static function build_a11y_report(array $ex): array {
        $checks = [];

        // --- Color contrast --------------------------------------------------
        $pass = 0;
        $fail = 0;
        $inconclusive = 0;
        $worst = [];
        foreach ($ex['text_style_contexts'] as $ctx) {
            $bg = $ctx['background'] ?? null;
            if (null === $bg || '' === $bg) {
                $inconclusive++;
                continue;
            }
            $ratio = V4_Color_Contrast::contrast_ratio((string) $ctx['color'], (string) $bg);
            if (null === $ratio) {
                $inconclusive++;
                continue;
            }
            if (V4_Color_Contrast::passes($ratio)) {
                $pass++;
            } else {
                $fail++;
                $worst[] = sprintf('%s (%.2f:1)', $ctx['element_id'], $ratio);
            }
        }
        $total_ctx = $pass + $fail + $inconclusive;
        if (0 === $total_ctx) {
            $contrast_status = 'inconclusive';
            $contrast_detail = __('No resolvable text/background color pairs found (colors may use globals or theme defaults).', 'novamira-adrianv2');
        } elseif ($fail > 0) {
            $contrast_status = 'fail';
            $contrast_detail = sprintf(
                /* translators: 1: fail count, 2: worst list, 3: inconclusive count */
                __('%1$d text/background pair(s) below 4.5:1 — %2$s. %3$d pair(s) inconclusive.', 'novamira-adrianv2'),
                $fail,
                implode(', ', array_slice($worst, 0, 5)),
                $inconclusive
            );
        } elseif ($pass > 0) {
            $contrast_status = ($inconclusive > 0) ? 'warn' : 'pass';
            $contrast_detail = sprintf(
                /* translators: 1: pass count, 2: inconclusive count */
                __('%1$d pair(s) meet 4.5:1; %2$d inconclusive (couldn\'t resolve background).', 'novamira-adrianv2'),
                $pass,
                $inconclusive
            );
        } else {
            $contrast_status = 'inconclusive';
            $contrast_detail = sprintf(
                /* translators: %d: count */
                __('%d color pair(s) inconclusive — background could not be resolved.', 'novamira-adrianv2'),
                $inconclusive
            );
        }
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'color_contrast',
            __('Color contrast (WCAG AA)', 'novamira-adrianv2'),
            $contrast_status,
            $contrast_detail,
            ('fail' === $contrast_status)
                ? __('Increase text/background contrast to at least 4.5:1 (3:1 for large text).', 'novamira-adrianv2')
                : ''
        );

        // --- Image alt text --------------------------------------------------
        $missing = 0;
        foreach ($ex['images'] as $img) {
            if ('' === trim((string) $img['alt'])) {
                $missing++;
            }
        }
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'image_alts',
            __('Image alt text', 'novamira-adrianv2'),
            (0 === $missing) ? 'pass' : 'fail',
            sprintf(
                /* translators: 1: missing, 2: total */
                __('%1$d of %2$d images are missing alt text.', 'novamira-adrianv2'),
                $missing,
                count($ex['images'])
            ),
            (0 === $missing)
                ? ''
                : __('Add descriptive alt text (or empty alt="" for purely decorative images).', 'novamira-adrianv2')
        );

        // --- Heading hierarchy ----------------------------------------------
        $skip = false;
        $prev = 0;
        foreach ($ex['headings'] as $h) {
            $lvl = (int) $h['level'];
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
            $skip
                ? __('A heading level is skipped, which disorients screen-reader users.', 'novamira-adrianv2')
                : __('Headings are sequential.', 'novamira-adrianv2'),
            $skip
                ? __('Use heading levels in order (don\'t jump from H1 to H3).', 'novamira-adrianv2')
                : ''
        );

        // --- Link text quality ----------------------------------------------
        $generic = 0;
        $empty   = 0;
        foreach ($ex['links'] as $l) {
            $text = trim((string) $l['text']);
            if ('' === $text) {
                $empty++;
            } elseif (in_array(\Novamira\AdrianV2\Helpers\Audit_Helpers::mb_lower($text), self::$generic_link_text, true)) {
                $generic++;
            }
        }
        $bad     = $generic + $empty;
        $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
            'link_text_quality',
            __('Link text quality', 'novamira-adrianv2'),
            (0 === $bad) ? 'pass' : 'warn',
            sprintf(
                /* translators: 1: generic count, 2: empty count */
                __('%1$d generic ("click here"-style) and %2$d empty link text(s).', 'novamira-adrianv2'),
                $generic,
                $empty
            ),
            (0 === $bad)
                ? ''
                : __('Use descriptive link text that makes sense out of context.', 'novamira-adrianv2')
        );

        // --- Form label coverage --------------------------------------------
        $unlabeled = 0;
        foreach ($ex['form_fields'] as $f) {
            if ('' === trim((string) $f['label'])) {
                $unlabeled++;
            }
        }
        if (!empty($ex['form_fields'])) {
            $checks[] = \Novamira\AdrianV2\Helpers\Audit_Helpers::check(
                'form_label_coverage',
                __('Form label coverage', 'novamira-adrianv2'),
                (0 === $unlabeled) ? 'pass' : 'fail',
                sprintf(
                    /* translators: 1: unlabeled, 2: total */
                    __('%1$d of %2$d form fields have no label.', 'novamira-adrianv2'),
                    $unlabeled,
                    count($ex['form_fields'])
                ),
                (0 === $unlabeled)
                    ? ''
                    : __('Give every form field a visible label.', 'novamira-adrianv2')
            );
        }

        return [
            'score'   => self::score($checks),
            'checks'  => $checks,
            'summary' => self::summary($checks),
        ];
    }

    /**
     * Proposes adjusted text colors for failing contrast pairs.
     *
     * Only resolvable pairs (background known) that currently fail are returned;
     * inconclusive pairs are left alone.
     *
     * @param array       $ex         Content_Extractor output.
     * @param string|null $element_id Optional element filter.
     * @param float       $target     Target contrast ratio.
     * @return array[]
     */
    public static function propose_contrast_fixes(
        array $ex,
        ?string $element_id,
        float $target = 4.5
    ): array {
        $fixes = [];
        foreach ($ex['text_style_contexts'] as $ctx) {
            if (null !== $element_id && ($ctx['element_id'] ?? '') !== $element_id) {
                continue;
            }
            $bg = $ctx['background'] ?? null;
            if (null === $bg || '' === $bg) {
                continue;
            }
            $ratio = V4_Color_Contrast::contrast_ratio(
                (string) $ctx['color'],
                (string) $bg
            );
            if (null === $ratio || V4_Color_Contrast::passes($ratio)) {
                continue;
            }
            $suggest = V4_Color_Contrast::suggest_adjusted(
                (string) $ctx['color'],
                (string) $bg,
                $target
            );
            if (null === $suggest) {
                continue;
            }
            $new_ratio = V4_Color_Contrast::contrast_ratio($suggest, (string) $bg);
            $fixes[]   = [
                'element_id' => (string) $ctx['element_id'],
                'color_key'  => (string) ($ctx['color_key'] ?? ''),
                'background' => (string) $bg,
                'from'       => (string) $ctx['color'],
                'to'         => $suggest,
                'old_ratio'  => round((float) $ratio, 2),
                'new_ratio'  => null !== $new_ratio ? round((float) $new_ratio, 2) : null,
            ];
        }
        return $fixes;
    }

    /**
     * Proposes alt text for images that lack it, from filename → nearest
     * heading → page title.
     *
     * @param array  $ex         Content_Extractor output.
     * @param string $page_title Page title fallback.
     * @return array[]
     */
    public static function propose_alt_texts(array $ex, string $page_title): array {
        $out = [];
        foreach ($ex['images'] as $img) {
            if ('' !== trim((string) $img['alt'])) {
                continue;
            }
            $source = 'filename';
            $alt    = self::alt_from_filename((string) $img['url']);
            if ('' === $alt) {
                $alt    = trim((string) ($img['context_heading'] ?? ''));
                $source = 'heading';
            }
            if ('' === $alt) {
                $alt    = trim($page_title);
                $source = 'page_title';
            }
            if ('' === $alt) {
                continue;
            }
            $out[] = [
                'element_id'    => (string) $img['element_id'],
                'attachment_id' => (int) $img['attachment_id'],
                'url'           => (string) $img['url'],
                'proposed_alt'  => $alt,
                'source'        => $source,
                'writable'      => ((int) $img['attachment_id'] > 0),
            ];
        }
        return $out;
    }

    /**
     * Derives a descriptive phrase from an image filename.
     *
     * Returns '' if the filename is non-descriptive (camera codes like
     * IMG_1234, pure numbers, dimensions).
     *
     * @param string $url Image URL.
     * @return string
     */
    public static function alt_from_filename(string $url): string {
        $path = parse_url($url, PHP_URL_PATH);
        $base = is_string($path) && '' !== $path ? basename($path) : basename($url);
        $base = preg_replace('/\\.[a-z0-9]+$/i', '', (string) $base);
        $base = preg_replace('/[-_]+/', ' ', (string) $base);
        // Strip WordPress size/scale suffixes.
        $base = preg_replace('/\\b\\d{2,4}x\\d{2,4}\\b/i', ' ', (string) $base);
        $base = preg_replace('/\\bscaled\\b/i', ' ', (string) $base);
        $base = preg_replace('/\\be\\d{8,}\\b/i', ' ', (string) $base);

        $tokens = preg_split('/\\s+/', trim((string) $base));
        $words  = [];
        foreach ((array) $tokens as $t) {
            $t = trim((string) $t);
            if ('' === $t || preg_match('/^\\d+$/', $t)) {
                continue;
            }
            if (preg_match('/^(img|image|dsc|dscn|pxl|pic|photo|screenshot|untitled|final|copy|v\\d+)$/i', $t)) {
                continue;
            }
            $words[] = strtolower($t);
        }
        if (count($words) < 2) {
            return '';
        }
        return ucfirst(implode(' ', $words));
    }

    // -------------------------------------------------------------------------
    // Internal scoring utilities (not shared — differ from SEO)
    // -------------------------------------------------------------------------

    /**
     * 0-100 score. Inconclusive is neutral (excluded from denominator).
     */
    private static function score(array $checks): int {
        $sum   = 0.0;
        $count = 0;
        foreach ($checks as $c) {
            if ('inconclusive' === $c['status']) {
                continue;
            }
            $count++;
            $sum += ('pass' === $c['status']) ? 1.0 : ('warn' === $c['status'] ? 0.5 : 0.0);
        }
        return (0 === $count) ? 0 : (int) round(100 * $sum / $count);
    }

    /**
     * Tallies pass/warn/fail/inconclusive counts.
     */
    private static function summary(array $checks): array {
        $s = ['passes' => 0, 'warnings' => 0, 'failures' => 0, 'inconclusive' => 0];
        foreach ($checks as $c) {
            switch ($c['status']) {
                case 'pass':
                    $s['passes']++;
                    break;
                case 'warn':
                    $s['warnings']++;
                    break;
                case 'inconclusive':
                    $s['inconclusive']++;
                    break;
                default:
                    $s['failures']++;
            }
        }
        return $s;
    }

}
