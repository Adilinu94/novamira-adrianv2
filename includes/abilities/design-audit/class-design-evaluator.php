<?php
/**
 * Ability: Design Evaluator
 *
 * Core design quality evaluation engine.
 * Ported from mcp-abilities-elementor v2.3.12 design audit suite.
 *
 * Registered abilities:
 * - novamira-adrianv2/evaluate-design       (orchestrator: runs all audits, produces 0-100 score)
 * - novamira-adrianv2/score-distinctiveness (standalone distinctiveness scoring)
 * - novamira-adrianv2/suggest-design-fixes  (translates audit issues into actionable fixes)
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\DesignAudit;

if (!defined('ABSPATH')) {
    exit();
}

class Design_Evaluator
{
    // Pattern weights for distinctiveness scoring
    private const PATTERN_WEIGHTS = [
        'standard_split_hero'     => 18,
        'uniform_multi_grid'      => 12,
        'three_up_grid'           => 10,
        'repeated_component_row'  => 10,
        'symmetric_two_column'    => 8,
    ];

    // Score deductions for issue types in the holistic evaluation
    private const ISSUE_DEDUCTIONS = [
        'section_rivalry'             => 14,
        'surface_overuse'             => 8,
        'emphasis_drift'              => 8,
        'composition_rhythm'          => 6,
        'column_pattern_repetition'   => 6,
        'separator_overuse'           => 5,
        'component_overuse'           => 4,
        'generic_layout_repetition'   => 4,
        'column_dominance'            => 3,
        'column_alignment_rhythm'     => 3,
        'column_imbalance'            => 3,
        'unnecessary_column_split'    => 3,
        'layout_mechanism_misfit'     => 2,
        'missed_native_widget'        => 2,
    ];

    /**
     * Register all design evaluator abilities.
     */
    public static function register(): void
    {
        // 1. evaluate-design (full orchestrator)
        wp_register_ability('novamira-adrianv2/evaluate-design', [
            'label'       => 'Evaluate Design',
            'description' => 'Runs a comprehensive design audit on an Elementor page. Analyzes layout patterns, column usage, composition rhythm, surface overuse, emphasis drift, section rivalry, separator discipline, component repetition, layout mechanism fit, and native widget opportunities. Produces a holistic 0-100 design score with detailed issues and actionable recommendations.',
            'category'    => 'adrianv2-design-audit',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the Elementor page to evaluate.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'                => ['type' => 'boolean'],
                    'post_id'                => ['type' => 'integer'],
                    'post_title'             => ['type' => 'string'],
                    'score'                  => ['type' => 'integer'],
                    'passes'                 => ['type' => 'boolean'],
                    'total_issues'           => ['type' => 'integer'],
                    'issues'                 => ['type' => 'array', 'items' => ['type' => 'object']],
                    'blocking_issue_types'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'recommendations'        => ['type' => 'array', 'items' => ['type' => 'string']],
                    'audits'                 => ['type' => 'object'],
                    'summary'                => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_evaluate_design'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        // 2. score-distinctiveness (standalone)
        wp_register_ability('novamira-adrianv2/score-distinctiveness', [
            'label'       => 'Score Distinctiveness',
            'description' => 'Computes a distinctiveness score (0-100) for an Elementor page based on pattern repetition and section signature uniqueness. Higher scores indicate more unique, varied layouts.',
            'category'    => 'adrianv2-design-audit',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the Elementor page to score.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'post_id'         => ['type' => 'integer'],
                    'score'           => ['type' => 'integer'],
                    'penalties'       => ['type' => 'array', 'items' => ['type' => 'object']],
                    'recommendations' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_score_distinctiveness'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);

        // 3. suggest-design-fixes
        wp_register_ability('novamira-adrianv2/suggest-design-fixes', [
            'label'       => 'Suggest Design Fixes',
            'description' => 'Translates design audit issues into actionable, human-oriented fix recommendations for an Elementor page.',
            'category'    => 'adrianv2-design-audit',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'ID of the Elementor page to analyze.',
                    ],
                ],
                'required' => ['post_id'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => ['type' => 'boolean'],
                    'problems'        => ['type' => 'array', 'items' => ['type' => 'object']],
                    'fixes'           => ['type' => 'array', 'items' => ['type' => 'object']],
                    'priority_order'  => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'execute_callback'    => [self::class, 'execute_suggest_fixes'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    // ============================================================
    // EXECUTE: evaluate-design (full orchestrator)
    // ============================================================

    public static function execute_evaluate_design($input = null)
    {
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'summary' => "Post {$post_id} not found."];
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode(is_string($raw) ? $raw : '[]', true);
        if (!is_array($data) || empty($data)) {
            return ['success' => false, 'summary' => 'No valid Elementor data found.'];
        }

        // --- Phase 1: Collect statistics from the element tree ---
        $generic_stats = ['patterns' => [], 'section_signatures' => []];
        $surface_collector = [];
        $column_stats = [];

        foreach ($data as $element) {
            if (!is_array($element)) continue;
            self::collect_generic_layout_stats($element, $generic_stats);
            self::collect_surface_signatures($element, $surface_collector);
            self::collect_column_audit_stats($element, $column_stats);
        }

        // --- Phase 2: Run all audits ---
        $audits = [];

        // Layout & pattern audits
        $generic_audit = self::finalize_generic_layout_audit($generic_stats);
        $audits['generic_layout'] = $generic_audit;

        $distinctiveness = self::score_distinctiveness_from_stats($generic_stats);
        $audits['distinctiveness'] = $distinctiveness;

        // Column audits
        $column_patterns = self::finalize_column_patterns_audit($column_stats);
        $audits['column_patterns'] = $column_patterns;

        $layout_mechanism = self::finalize_layout_mechanism_fit_audit($column_stats);
        $audits['layout_mechanism_fit'] = $layout_mechanism;

        $native_widget = self::finalize_native_widget_opportunity_audit($column_stats);
        $audits['native_widget_opportunities'] = $native_widget;

        $column_dominance = self::finalize_column_dominance_audit($column_stats);
        $audits['column_dominance'] = $column_dominance;

        $column_alignment = self::finalize_column_alignment_rhythm_audit($column_stats);
        $audits['column_alignment_rhythm'] = $column_alignment;

        $column_balance = self::finalize_column_balance_audit($column_stats);
        $audits['column_balance'] = $column_balance;

        $column_necessity = self::finalize_column_necessity_audit($column_stats);
        $audits['column_necessity'] = $column_necessity;

        // Composition audits (need emphasis/separator profiles)
        $emphasis_profiles = [];
        $separator_profiles = [];
        $top_level = self::get_top_level_containers($data);

        foreach ($top_level as $section) {
            $emphasis_profiles[] = self::compute_section_emphasis_profile($section);
            $separator_profiles[] = self::compute_section_separator_profile($section);
        }

        $component_profile = self::build_global_component_profile($data);

        $component_audit = self::finalize_component_overuse_audit($component_profile);
        $audits['component_overuse'] = $component_audit;

        $surface_audit = self::finalize_surface_overuse_audit($surface_collector);
        $audits['surface_overuse'] = $surface_audit;

        $emphasis_audit = self::finalize_emphasis_drift_audit($emphasis_profiles);
        $audits['emphasis_drift'] = $emphasis_audit;

        $section_rivalry = self::finalize_section_rivalry_audit($emphasis_profiles);
        $audits['section_rivalry'] = $section_rivalry;

        $composition_rhythm = self::finalize_composition_rhythm_audit($emphasis_profiles);
        $audits['composition_rhythm'] = $composition_rhythm;

        $separator_audit = self::finalize_separator_discipline_audit($separator_profiles);
        $audits['separator_discipline'] = $separator_audit;

        // --- Phase 3: Aggregate issues ---
        $issues = [];
        $blocking_types = [];

        foreach ($audits as $audit_key => $audit) {
            $audit_issues = $audit['issues'] ?? [];
            foreach ($audit_issues as $issue) {
                $issue['audit'] = $audit_key;
                $issues[] = $issue;
            }
        }

        // --- Phase 4: Calculate score ---
        $base_score = $distinctiveness['score'] ?? 100;
        $deductions = 0;

        foreach ($issues as $issue) {
            $type = $issue['type'] ?? '';
            if (isset(self::ISSUE_DEDUCTIONS[$type])) {
                $deductions += self::ISSUE_DEDUCTIONS[$type];
            }
        }

        $final_score = max(0, min(100, $base_score - $deductions));

        // --- Phase 5: Determine blocking issues ---
        foreach ($issues as $issue) {
            $type = $issue['type'] ?? '';
            if ($type === 'section_rivalry') {
                $blocking_types[] = 'section_rivalry';
            }
            if ($type === 'generic_layout_repetition' && ($distinctiveness['score'] ?? 100) <= 60) {
                $blocking_types[] = 'generic_layout_repetition';
            }
        }
        $blocking_types = array_values(array_unique($blocking_types));

        // --- Phase 6: Generate recommendations ---
        $recommendations = self::generate_recommendations($audits);

        $passes = empty($blocking_types);
        $total_issues = count($issues);

        $summary = $total_issues === 0
            ? "Design looks clean! Score: {$final_score}/100 on \"{$post->post_title}\"."
            : sprintf(
                '%d design issues found in "%s". Score: %d/100. %s',
                $total_issues,
                $post->post_title,
                $final_score,
                $passes ? 'No blocking issues.' : 'Blocking: ' . implode(', ', $blocking_types)
            );

        return [
            'success'              => true,
            'post_id'              => $post_id,
            'post_title'           => $post->post_title,
            'score'                => $final_score,
            'passes'               => $passes,
            'total_issues'         => $total_issues,
            'issues'               => $issues,
            'blocking_issue_types' => $blocking_types,
            'recommendations'      => $recommendations,
            'audits'               => $audits,
            'summary'              => $summary,
        ];
    }

    // ============================================================
    // EXECUTE: score-distinctiveness (standalone)
    // ============================================================

    public static function execute_score_distinctiveness($input = null)
    {
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'error' => "Post {$post_id} not found."];
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode(is_string($raw) ? $raw : '[]', true);
        if (!is_array($data) || empty($data)) {
            return ['success' => false, 'error' => 'No valid Elementor data found.'];
        }

        $stats = ['patterns' => [], 'section_signatures' => []];
        foreach ($data as $element) {
            if (!is_array($element)) continue;
            self::collect_generic_layout_stats($element, $stats);
        }

        $result = self::score_distinctiveness_from_stats($stats);

        return [
            'success'         => true,
            'post_id'         => $post_id,
            'score'           => $result['score'],
            'penalties'       => $result['penalties'] ?? [],
            'recommendations' => $result['recommendations'] ?? [],
        ];
    }

    // ============================================================
    // EXECUTE: suggest-design-fixes
    // ============================================================

    public static function execute_suggest_fixes($input = null)
    {
        $post_id = (int) $input['post_id'];
        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'error' => "Post {$post_id} not found."];
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $data = json_decode(is_string($raw) ? $raw : '[]', true);
        if (!is_array($data) || empty($data)) {
            return ['success' => false, 'error' => 'No valid Elementor data found.'];
        }

        // Run full evaluation to get issues
        $eval = self::execute_evaluate_design(['post_id' => $post_id]);
        $issues = $eval['issues'] ?? [];

        $problems = [];
        $fixes = [];
        $priority_order = [];

        $fix_map = [
            'generic_layout_repetition' => [
                'problem' => 'The page reuses generic layout patterns (split hero, equal grids, repeated component rows) across multiple sections.',
                'fix'     => 'Vary section structures: replace at least one equal-width grid with an asymmetric layout, stagger a hero section differently, or introduce a section where child components have unique internal structures.',
                'priority' => 'high',
            ],
            'section_rivalry' => [
                'problem' => 'Too many high-impact sections compete for attention. Multiple "peak" sections in sequence create visual fatigue.',
                'fix'     => 'Reduce high-emphasis sections. Insert at least one quieter, lower-contrast section between spotlight sections. Use fewer cards/buttons in adjacent sections.',
                'priority' => 'high',
            ],
            'surface_overuse' => [
                'problem' => 'The same visual surface treatment (card style) is repeated across many containers, making the page feel formulaic.',
                'fix'     => 'Consolidate repeated surfaces into fewer instances or introduce variation in border-radius, shadow depth, or background tone across different sections.',
                'priority' => 'medium',
            ],
            'emphasis_drift' => [
                'problem' => 'Sections have uniform visual emphasis. Without variation in weight, key content fails to stand out from supporting sections.',
                'fix'     => 'Introduce emphasis contrast: make at least one section dramatically different in scale, background tone, or content density from its neighbors.',
                'priority' => 'medium',
            ],
            'composition_rhythm' => [
                'problem' => 'Multiple adjacent sections share the same tonal weight, reducing the page\'s visual pacing.',
                'fix'     => 'Alternate section tones (light/dark/accent) or vary information density between adjacent sections to create a clear visual rhythm.',
                'priority' => 'medium',
            ],
            'separator_overuse' => [
                'problem' => 'Top-border separators appear on too many sections, creating a mechanically divided appearance.',
                'fix'     => 'Remove separators from at least half of the flagged sections. Use whitespace, background tone changes, or content hierarchy to separate sections instead.',
                'priority' => 'low',
            ],
            'column_pattern_repetition' => [
                'problem' => 'Column layouts repeat with the same ratio splits across multiple sections.',
                'fix'     => 'Vary column proportions between sections. Break the rhythm by using a single-column layout or an asymmetric ratio in at least one section.',
                'priority' => 'medium',
            ],
            'column_dominance' => [
                'problem' => 'Equal-width column splits contain unequal content weight, failing to reflect the actual content hierarchy.',
                'fix'     => 'Adjust column widths to match content importance: give more space to the column with more content, headings, or media.',
                'priority' => 'low',
            ],
            'column_imbalance' => [
                'problem' => 'Unequal column widths carry nearly identical content weight, creating asymmetry without clear purpose.',
                'fix'     => 'Either equalize the columns (same width for same-weight content) or differentiate the content so the unequal widths serve a clear visual purpose.',
                'priority' => 'low',
            ],
            'unnecessary_column_split' => [
                'problem' => 'A two-column split contains light content in both columns that could work in a single lane.',
                'fix'     => 'Collapse the split into a single column. If the content is truly distinct, add more substance to each side to justify the split.',
                'priority' => 'low',
            ],
            'layout_mechanism_misfit' => [
                'problem' => 'A flexbox layout is being used where a different layout mechanism (CSS Grid, single column) would be more appropriate.',
                'fix'     => 'Convert the flexbox container to CSS Grid (display:grid) or restructure to a single-column flow where the content doesn\'t require multi-column layout.',
                'priority' => 'medium',
            ],
            'missed_native_widget' => [
                'problem' => 'Ad-hoc container compositions are recreating functionality available in native Elementor widgets.',
                'fix'     => 'Replace custom-built patterns with native Elementor widgets (Accordion, Tabs, Icon List, Call to Action, etc.) for better accessibility and maintainability.',
                'priority' => 'medium',
            ],
            'component_overuse' => [
                'problem' => 'The same component pattern (e.g., card with heading + text + button) appears too many times.',
                'fix'     => 'Reduce repeated component instances. Consolidate information or introduce a structurally different component to break the monotony.',
                'priority' => 'medium',
            ],
        ];

        foreach ($issues as $issue) {
            $type = $issue['type'] ?? '';
            if (isset($fix_map[$type])) {
                $entry = $fix_map[$type];
                if (!in_array($type, $priority_order, true)) {
                    $priority_order[] = $type;
                }
                $problems[] = [
                    'type'     => $type,
                    'severity' => $issue['severity'] ?? 'warning',
                    'problem'  => $entry['problem'],
                ];
                $fixes[] = [
                    'type'     => $type,
                    'priority' => $entry['priority'],
                    'fix'      => $entry['fix'],
                ];
            }
        }

        // Sort priority_order: high first
        usort($priority_order, function ($a, $b) use ($fix_map) {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($order[$fix_map[$a]['priority']] ?? 99) - ($order[$fix_map[$b]['priority']] ?? 99);
        });

        return [
            'success'        => count($problems) > 0,
            'problems'       => $problems,
            'fixes'           => $fixes,
            'priority_order'  => $priority_order,
        ];
    }

    // ============================================================
    // COLLECTION FUNCTIONS
    // ============================================================

    /**
     * Recursively collects generic layout stats from the element tree.
     */
    private static function collect_generic_layout_stats(array $element, array &$stats, int $depth = 0, int $max_depth = -1): void
    {
        if ($max_depth >= 0 && $depth > $max_depth) {
            return;
        }

        if ($depth === 0) {
            $stats['section_signatures'][] = self::build_structure_signature($element, 1);
        }

        self::collect_generic_pattern_stats($element, $depth, $stats);

        $children = $element['elements'] ?? [];
        foreach ($children as $child) {
            if (is_array($child)) {
                self::collect_generic_layout_stats($child, $stats, $depth + 1, $max_depth);
            }
        }
    }

    /**
     * Detects generic layout patterns in a single element.
     */
    private static function collect_generic_pattern_stats(array $element, int $depth, array &$stats): void
    {
        $el_type = $element['elType'] ?? '';
        if ($el_type !== 'container' && $el_type !== 'e-flexbox' && $el_type !== 'e-div-block') {
            return;
        }

        $children = $element['elements'] ?? [];
        $n_kids = count($children);
        $el_id = (string) ($element['id'] ?? '');
        if ($el_id === '') return;

        // Detect patterns based on child structure
        $child_types = [];
        $has_image = false;
        $has_heading = false;
        $has_text = false;
        $has_button = false;

        foreach ($children as $child) {
            if (!is_array($child)) continue;
            $ct = ($child['elType'] ?? '') === 'widget' ? ($child['widgetType'] ?? 'unknown') : ($child['elType'] ?? 'unknown');
            $child_types[] = $ct;
            if ($ct === 'image' || $ct === 'e-image') $has_image = true;
            if ($ct === 'heading' || $ct === 'e-heading') $has_heading = true;
            if ($ct === 'text-editor' || $ct === 'e-paragraph') $has_text = true;
            if ($ct === 'button' || $ct === 'e-button') $has_button = true;
        }

        // standard_split_hero: 2 children, one is image/widget, other has heading+text+button
        if ($n_kids === 2) {
            $first_is_media = in_array($child_types[0] ?? '', ['image', 'e-image', 'container', 'e-flexbox', 'e-div-block'], true);
            $second_has_content = $has_heading || $has_text || $has_button;
            if ($first_is_media && $second_has_content) {
                $stats['patterns']['standard_split_hero'][] = $el_id;
            }
        }

        // symmetric_two_column: 2 children of same type (both containers or both text-editors)
        if ($n_kids === 2 && ($child_types[0] ?? '') === ($child_types[1] ?? '')) {
            $stats['patterns']['symmetric_two_column'][] = $el_id;
        }

        // three_up_grid: 3 children of same type
        if ($n_kids === 3 && count(array_unique($child_types)) === 1) {
            $stats['patterns']['three_up_grid'][] = $el_id;
        }

        // uniform_multi_grid: 4+ children of same type
        if ($n_kids >= 4 && count(array_unique($child_types)) <= 2) {
            $stats['patterns']['uniform_multi_grid'][] = $el_id;
        }

        // repeated_component_row: all children are containers with similar content
        if ($n_kids >= 2 && count(array_unique($child_types)) === 1 && in_array($child_types[0] ?? '', ['container', 'e-flexbox', 'e-div-block'], true)) {
            $stats['patterns']['repeated_component_row'][] = $el_id;
        }
    }

    /**
     * Builds a structure signature string from an element tree.
     */
    private static function build_structure_signature(array $element, int $max_depth = 1, int $depth = 0): string
    {
        $base = (string) ($element['elType'] ?? 'unknown');
        if ($base === 'widget') {
            $base .= ':' . (string) ($element['widgetType'] ?? 'unknown');
        }

        if ($depth >= $max_depth) {
            return $base;
        }

        $children_sigs = [];
        foreach ($element['elements'] ?? [] as $child) {
            if (is_array($child)) {
                $children_sigs[] = self::build_structure_signature($child, $max_depth, $depth + 1);
            }
        }

        if (empty($children_sigs)) {
            return $base;
        }

        sort($children_sigs);
        return $base . '[' . implode('|', $children_sigs) . ']';
    }

    /**
     * Collects surface signatures from the element tree.
     */
    private static function collect_surface_signatures(array $element, array &$collector): void
    {
        $el_type = $element['elType'] ?? '';
        if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true)) {
            $sig = self::build_surface_signature($element);
            if ($sig !== null) {
                $el_id = (string) ($element['id'] ?? '');
                if ($el_id !== '') {
                    $collector[$sig][] = $el_id;
                }
            }
        }

        foreach ($element['elements'] ?? [] as $child) {
            if (is_array($child)) {
                self::collect_surface_signatures($child, $collector);
            }
        }
    }

    /**
     * Builds a surface signature from a container's visual settings.
     */
    private static function build_surface_signature(array $element): ?string
    {
        $settings = $element['settings'] ?? [];
        $tone = self::classify_surface_tone($settings);

        // Extract style properties
        $radius = $settings['border_radius'] ?? $settings['_border_radius'] ?? '0';
        $padding = $settings['padding'] ?? $settings['_padding'] ?? '0';
        $has_border = !empty($settings['border_border']) || !empty($settings['_border_border']);
        $has_shadow = !empty($settings['box_shadow_box_shadow']) || !empty($settings['_box_shadow_box_shadow']);
        $has_bg = !empty($settings['background_color']) || !empty($settings['background_background']);

        // Only build signature if there's meaningful treatment
        if (!$has_bg && !$has_border && !$has_shadow) {
            return null;
        }

        $border_str = $has_border ? '1' : '0';
        $shadow_str = $has_shadow ? '1' : '0';

        if (is_array($radius)) {
            $radius = $radius['unit'] ?? $radius['size'] ?? '0';
        }
        if (is_array($padding)) {
            $padding = $padding['unit'] ?? $padding['size'] ?? '0';
        }

        return implode('|', [$tone, $radius, $padding, $border_str, $shadow_str]);
    }

    /**
     * Collects column audit statistics from the element tree.
     */
    private static function collect_column_audit_stats(array $element, array &$stats): void
    {
        $el_type = $element['elType'] ?? '';
        if (!in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true)) {
            // Recurse into children for non-containers
            foreach ($element['elements'] ?? [] as $child) {
                if (is_array($child)) {
                    self::collect_column_audit_stats($child, $stats);
                }
            }
            return;
        }

        $children = $element['elements'] ?? [];
        $n_kids = count($children);
        $el_id = (string) ($element['id'] ?? '');

        if ($n_kids >= 2 && $el_id !== '') {
            $container_children = array_filter($children, function ($c) {
                return in_array($c['elType'] ?? '', ['container', 'e-flexbox', 'e-div-block'], true);
            });

            if (count($container_children) === $n_kids) {
                $row_entry = [
                    'element_id' => $el_id,
                    'child_count' => $n_kids,
                    'children' => [],
                ];

                foreach ($children as $idx => $child) {
                    $child_id = (string) ($child['id'] ?? '');
                    $width = self::extract_column_width($child);
                    $content_score = self::score_column_content($child);
                    $role = self::classify_column_role($child);

                    $row_entry['children'][] = [
                        'element_id'    => $child_id,
                        'index'         => $idx,
                        'width'         => $width,
                        'content_score' => $content_score,
                        'role'          => $role,
                        'child_count'   => count($child['elements'] ?? []),
                        'has_image'     => self::subtree_contains_widget_type($child, 'image') || self::subtree_contains_widget_type($child, 'e-image'),
                        'has_heading'   => self::subtree_contains_widget_type($child, 'heading') || self::subtree_contains_widget_type($child, 'e-heading'),
                        'total_words'   => self::count_subtree_words($child),
                    ];
                }

                // Build ratio signature
                $widths = array_column($row_entry['children'], 'width');
                $ratio_sig = implode(':', array_map(function ($w) {
                    return is_numeric($w) ? (string) round((float) $w / 10) * 10 : 'var';
                }, $widths));

                $row_entry['ratio_signature'] = $ratio_sig;

                // Determine gap token
                $gap = self::extract_gap_token($element);
                $row_entry['gap_token'] = $gap;

                $stats['rows'][] = $row_entry;
            }
        }

        // Recurse into children
        foreach ($children as $child) {
            if (is_array($child)) {
                self::collect_column_audit_stats($child, $stats);
            }
        }
    }

    // ============================================================
    // FINALIZE FUNCTIONS (Audit runners)
    // ============================================================

    /**
     * Finalizes the generic layout audit from collected stats.
     */
    private static function finalize_generic_layout_audit(array $stats): array
    {
        $patterns = [];
        foreach ($stats['patterns'] ?? [] as $name => $ids) {
            $ids = array_values(array_unique(array_filter(array_map('strval', (array) $ids))));
            $patterns[$name] = [
                'count'       => count($ids),
                'element_ids' => $ids,
            ];
        }

        $section_signatures = array_values(array_filter((array) ($stats['section_signatures'] ?? [])));
        $signature_counts = array_count_values($section_signatures);
        arsort($signature_counts);
        $top_repeated = array_slice($signature_counts, 0, 5, true);

        $issues = [];
        $recommendations = [];

        if (!empty($patterns['standard_split_hero']['count'])) {
            $issues[] = [
                'type'     => 'generic_layout_repetition',
                'severity' => 'warning',
                'message'  => 'Standard split-hero pattern detected in ' . $patterns['standard_split_hero']['count'] . ' section(s).',
            ];
            $recommendations[] = 'Consider breaking the default split-hero formula by varying media placement, information density, or section sequencing.';
        }
        if (!empty($patterns['three_up_grid']['count']) || !empty($patterns['uniform_multi_grid']['count'])) {
            $issues[] = [
                'type'     => 'generic_layout_repetition',
                'severity' => 'warning',
                'message'  => 'Repeated equal-width grid patterns detected.',
            ];
            $recommendations[] = 'Reduce repeated equal-width grids. Keep at least one major section on a different column rhythm or card count.';
        }
        if (!empty($patterns['repeated_component_row']['count'])) {
            $issues[] = [
                'type'     => 'generic_layout_repetition',
                'severity' => 'warning',
                'message'  => 'Repeated component rows detected in ' . $patterns['repeated_component_row']['count'] . ' section(s).',
            ];
            $recommendations[] = 'Introduce at least one section whose child components do not all share the same internal structure.';
        }
        if (!empty($patterns['symmetric_two_column']['count']) && $patterns['symmetric_two_column']['count'] > 1) {
            $issues[] = [
                'type'     => 'generic_layout_repetition',
                'severity' => 'info',
                'message'  => 'Multiple symmetric two-column layouts detected.',
            ];
            $recommendations[] = 'Avoid stacking multiple 50/50 rows in sequence. Vary section ratios so the page does not settle into a repetitive beat.';
        }
        if (!empty($top_repeated) && max($top_repeated) > 1) {
            $issues[] = [
                'type'     => 'generic_layout_repetition',
                'severity' => 'info',
                'message'  => 'Top-level section composition is repeating.',
            ];
            $recommendations[] = 'Top-level section composition is repeating. Increase compositional contrast between adjacent sections.';
        }

        return [
            'patterns'           => $patterns,
            'section_signatures' => array_map(
                static function ($signature, $count) {
                    return ['signature' => (string) $signature, 'count' => (int) $count];
                },
                array_keys($top_repeated),
                array_values($top_repeated)
            ),
            'issues'             => $issues,
            'recommendations'    => array_values(array_unique($recommendations)),
        ];
    }

    /**
     * Scores distinctiveness from generic layout stats.
     */
    private static function score_distinctiveness_from_stats(array $stats): array
    {
        $total_penalty = 0;
        $penalties = [];

        // Pattern penalties
        foreach (self::PATTERN_WEIGHTS as $pattern => $weight) {
            $count = count(array_unique((array) ($stats['patterns'][$pattern] ?? [])));
            if ($count > 0) {
                $penalty = min(30, $count * $weight);
                $total_penalty += $penalty;
                $penalties[] = [
                    'pattern' => $pattern,
                    'count'   => $count,
                    'weight'  => $weight,
                    'penalty' => $penalty,
                ];
            }
        }

        // Top-level repetition penalty
        $section_signatures = array_values(array_filter((array) ($stats['section_signatures'] ?? [])));
        $sig_counts = array_count_values($section_signatures);
        $top_level_penalty = 0;
        $repeated_sigs = [];

        foreach ($sig_counts as $sig => $count) {
            if ($count > 1) {
                $sig_penalty = min(12, ($count - 1) * 6);
                $top_level_penalty += $sig_penalty;
                $repeated_sigs[] = ['signature' => $sig, 'count' => $count, 'penalty' => $sig_penalty];
            }
        }
        $top_level_penalty = min(24, $top_level_penalty);
        $total_penalty += $top_level_penalty;

        $total_penalty = min(90, $total_penalty);
        $score = max(0, 100 - $total_penalty);

        $recommendations = [];
        if ($score < 70) {
            $recommendations[] = 'The page relies heavily on repetitive layout patterns. Introduce more structural variety.';
        }
        if ($score < 50) {
            $recommendations[] = 'Consider a complete layout restructure — patterns are too uniform to feel distinctive.';
        }

        return [
            'score'           => $score,
            'penalties'       => $penalties,
            'top_repeated'    => $repeated_sigs,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Finalizes column patterns audit.
     */
    private static function finalize_column_patterns_audit(array $stats): array
    {
        $issues = [];
        $recommendations = [];
        $rows = $stats['rows'] ?? [];

        $ratio_groups = [];
        foreach ($rows as $row) {
            $sig = $row['ratio_signature'] ?? 'unknown';
            $ratio_groups[$sig][] = $row;
        }

        foreach ($ratio_groups as $sig => $group) {
            $count = count($group);
            if ($count >= 3) {
                $issues[] = [
                    'type'     => 'column_pattern_repetition',
                    'severity' => 'warning',
                    'message'  => "Column ratio '{$sig}' repeated {$count} times across sections.",
                    'ratio'    => $sig,
                    'count'    => $count,
                ];
            }
        }

        if (!empty($issues)) {
            $recommendations[] = 'Vary column ratios between sections to avoid predictable grid repetition.';
        }

        return [
            'issues'          => $issues,
            'ratio_groups'    => array_map('count', $ratio_groups),
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Finalizes layout mechanism fit audit.
     */
    private static function finalize_layout_mechanism_fit_audit(array $stats): array
    {
        $issues = [];
        $rows = $stats['rows'] ?? [];

        foreach ($rows as $row) {
            $n_kids = $row['child_count'] ?? 0;
            $children = $row['children'] ?? [];

            // Check for grid candidates: 3+ columns with equal widths
            if ($n_kids >= 3) {
                $widths = array_column($children, 'width');
                $unique_widths = array_unique($widths);
                if (count($unique_widths) === 1) {
                    $issues[] = [
                        'type'        => 'layout_mechanism_misfit',
                        'severity'    => 'info',
                        'message'     => "{$n_kids}-column flexbox row with equal widths — CSS Grid would be more appropriate.",
                        'element_id'  => $row['element_id'],
                        'suggestion'  => 'Use display:grid with grid-template-columns: repeat(' . $n_kids . ', 1fr)',
                    ];
                }
            }
        }

        return [
            'issues' => $issues,
        ];
    }

    /**
     * Finalizes native widget opportunity audit.
     * 
     * Detects ad-hoc container compositions that recreate functionality available
     * in native Elementor widgets (Accordion, Tabs, Icon List, Call to Action, etc.).
     */
    private static function finalize_native_widget_opportunity_audit(array $stats): array
    {
        $issues = [];
        $rows = $stats['rows'] ?? [];

        foreach ($rows as $row) {
            $children = $row['children'] ?? [];
            $n_kids = count($children);

            $heading_count = count(array_filter($children, fn($c) => $c['has_heading'] ?? false));
            $has_images = count(array_filter($children, fn($c) => $c['has_image'] ?? false));
            $has_text = count(array_filter($children, fn($c) => ($c['total_words'] ?? 0) > 10));

            // Accordion candidate: 3+ heading+text blocks (collapsible content sections)
            if ($n_kids >= 3 && $heading_count >= 3 && $has_text >= 3) {
                $issues[] = [
                    'type'       => 'missed_native_widget',
                    'severity'   => 'info',
                    'message'    => "{$n_kids} heading+text blocks — consider using native Accordion widget for collapsible content.",
                    'element_id' => $row['element_id'],
                    'widget'     => 'Accordion',
                ];
            }

            // Tabs candidate: 3+ heading blocks that could be tab navigation
            if ($n_kids >= 3 && $heading_count >= 3 && $has_images < $n_kids) {
                $issues[] = [
                    'type'       => 'missed_native_widget',
                    'severity'   => 'info',
                    'message'    => "{$n_kids} repeated heading blocks — consider using native Tabs widget for sectioned content.",
                    'element_id' => $row['element_id'],
                    'widget'     => 'Tabs',
                ];
            }

            // Icon List candidate: 3+ icon+heading pairs
            if ($n_kids >= 3 && $has_images >= 3 && $heading_count >= 3) {
                $issues[] = [
                    'type'       => 'missed_native_widget',
                    'severity'   => 'info',
                    'message'    => "{$n_kids} repeated icon+heading blocks — consider using native Icon List widget.",
                    'element_id' => $row['element_id'],
                    'widget'     => 'Icon List',
                ];
            }

            // Call to Action candidate: single child with heading+text content
            if ($n_kids === 1 && $heading_count >= 1 && $has_text >= 1) {
                $child = $children[0] ?? null;
                if ($child && (($child['total_words'] ?? 0) > 20 || ($child['has_image'] ?? false))) {
                    $issues[] = [
                        'type'       => 'missed_native_widget',
                        'severity'   => 'info',
                        'message'    => 'Container with heading+text content — consider native Call to Action widget for better conversion optimization.',
                        'element_id' => $row['element_id'],
                        'widget'     => 'Call to Action',
                    ];
                }
            }

            // Loop Grid candidate: 3+ card containers where ALL have headings
            if ($n_kids >= 3 && $heading_count === $n_kids && $has_text >= 2) {
                $issues[] = [
                    'type'       => 'missed_native_widget',
                    'severity'   => 'info',
                    'message'    => "{$n_kids} card containers where all have headings — consider a native Loop Grid widget if content is dynamic.",
                    'element_id' => $row['element_id'],
                    'widget'     => 'Loop Grid',
                ];
            }
        }

        return [
            'issues' => $issues,
        ];
    }

    /**
     * Finalizes column dominance audit.
     */
    private static function finalize_column_dominance_audit(array $stats): array
    {
        $issues = [];
        $rows = $stats['rows'] ?? [];

        foreach ($rows as $row) {
            $children = $row['children'] ?? [];
            if (count($children) !== 2) continue;

            $left = $children[0];
            $right = $children[1];

            $left_score = $left['content_score'] ?? 0;
            $right_score = $right['content_score'] ?? 0;
            $delta = abs($left_score - $right_score);

            $left_width = $left['width'] ?? '50';
            $right_width = $right['width'] ?? '50';

            $is_equal_split = ($left_width === $right_width) ||
                (is_numeric($left_width) && is_numeric($right_width) && abs((float) $left_width - (float) $right_width) <= 5);

            if ($is_equal_split && $delta >= 4) {
                $issues[] = [
                    'type'       => 'column_dominance',
                    'severity'   => 'info',
                    'message'    => "Equal column split despite significant content weight difference (delta: {$delta}).",
                    'element_id' => $row['element_id'],
                    'left_score' => $left_score,
                    'right_score' => $right_score,
                ];
            }
        }

        return ['issues' => $issues];
    }

    /**
     * Finalizes column alignment rhythm audit.
     */
    private static function finalize_column_alignment_rhythm_audit(array $stats): array
    {
        $issues = [];
        $rows = $stats['rows'] ?? [];

        $gap_groups = [];
        foreach ($rows as $row) {
            $gap = $row['gap_token'] ?? 'default';
            $sig = $row['ratio_signature'] ?? 'unknown';
            $key = $sig . '|' . $gap;
            $gap_groups[$key][] = $row['element_id'];
        }

        // Find rows with same ratio but different gaps
        $ratio_to_gaps = [];
        foreach ($rows as $row) {
            $sig = $row['ratio_signature'] ?? '';
            $gap = $row['gap_token'] ?? 'default';
            $ratio_to_gaps[$sig][$gap] = true;
        }

        foreach ($ratio_to_gaps as $sig => $gaps) {
            if (count($gaps) >= 2) {
                $issues[] = [
                    'type'     => 'column_alignment_rhythm',
                    'severity' => 'info',
                    'message'  => "Rows with same column ratio '{$sig}' use different gutter settings.",
                    'gaps'     => array_keys($gaps),
                ];
            }
        }

        return ['issues' => $issues];
    }

    /**
     * Finalizes column balance audit.
     */
    private static function finalize_column_balance_audit(array $stats): array
    {
        $issues = [];
        $rows = $stats['rows'] ?? [];

        foreach ($rows as $row) {
            $children = $row['children'] ?? [];
            if (count($children) !== 2) continue;

            $left = $children[0];
            $right = $children[1];

            $left_score = $left['content_score'] ?? 0;
            $right_score = $right['content_score'] ?? 0;
            $delta = abs($left_score - $right_score);

            $left_width = $left['width'] ?? '50';
            $right_width = $right['width'] ?? '50';

            $is_unequal = !(($left_width === $right_width) ||
                (is_numeric($left_width) && is_numeric($right_width) && abs((float) $left_width - (float) $right_width) <= 5));

            if ($is_unequal && $delta <= 1) {
                $issues[] = [
                    'type'       => 'column_imbalance',
                    'severity'   => 'info',
                    'message'    => 'Unequal column widths with nearly identical content weight — asymmetry without clear purpose.',
                    'element_id' => $row['element_id'],
                    'left_width' => $left_width,
                    'right_width' => $right_width,
                ];
            }
        }

        return ['issues' => $issues];
    }

    /**
     * Finalizes column necessity audit.
     */
    private static function finalize_column_necessity_audit(array $stats): array
    {
        $issues = [];
        $rows = $stats['rows'] ?? [];

        foreach ($rows as $row) {
            $children = $row['children'] ?? [];
            if (count($children) !== 2) continue;

            $left = $children[0];
            $right = $children[1];

            $both_light = ($left['role'] ?? '') === 'copy' && ($right['role'] ?? '') === 'copy';
            $both_copy = ($left['role'] ?? '') === 'copy' && ($right['role'] ?? '') === 'copy';
            $low_words = ($left['total_words'] ?? 0) + ($right['total_words'] ?? 0) <= 45;
            $no_images = !($left['has_image'] ?? false) && !($right['has_image'] ?? false);

            if ($both_copy && $low_words && $no_images) {
                $issues[] = [
                    'type'       => 'unnecessary_column_split',
                    'severity'   => 'info',
                    'message'    => 'Two-column split with light text content in both columns — consider collapsing to a single lane.',
                    'element_id' => $row['element_id'],
                ];
            }
        }

        return ['issues' => $issues];
    }

    /**
     * Builds a global component profile from the entire element tree.
     */
    private static function build_global_component_profile(array $elements): array
    {
        $widget_counts = ['button' => 0, 'image' => 0, 'heading' => 0, 'text-editor' => 0, 'icon' => 0,
                          'e-button' => 0, 'e-image' => 0, 'e-heading' => 0, 'e-paragraph' => 0, 'e-svg' => 0];
        $card_like_ids = [];

        $walker = null;
        $walker = static function (array $els) use (&$walker, &$widget_counts, &$card_like_ids) {
            foreach ($els as $el) {
                $el_type = $el['elType'] ?? '';
                if ($el_type === 'widget') {
                    $wt = $el['widgetType'] ?? '';
                    if (isset($widget_counts[$wt])) {
                        $widget_counts[$wt]++;
                    }
                }

                if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block'], true)) {
                    if (self::is_card_like_container($el)) {
                        $card_like_ids[] = (string) ($el['id'] ?? '');
                    }
                }

                $children = $el['elements'] ?? [];
                if (!empty($children)) {
                    $walker($children);
                }
            }
        };

        $walker($elements);

        // Normalize: merge e-* with legacy widget type counts
        $normalized = [
            'button'      => ($widget_counts['button'] ?? 0) + ($widget_counts['e-button'] ?? 0),
            'image'       => ($widget_counts['image'] ?? 0) + ($widget_counts['e-image'] ?? 0),
            'heading'     => ($widget_counts['heading'] ?? 0) + ($widget_counts['e-heading'] ?? 0),
            'text-editor' => ($widget_counts['text-editor'] ?? 0) + ($widget_counts['e-paragraph'] ?? 0),
            'icon'        => ($widget_counts['icon'] ?? 0) + ($widget_counts['e-svg'] ?? 0),
        ];

        return [
            'widget_counts'  => $normalized,
            'card_like_ids'  => $card_like_ids,
            'card_like_count' => count($card_like_ids),
        ];
    }

    /**
     * Determines if a container is "card-like" (has visual treatment + heading + text/button).
     */
    private static function is_card_like_container(array $element): bool
    {
        $settings = $element['settings'] ?? [];
        $has_bg = !empty($settings['background_color']) || !empty($settings['background_background']);
        $has_border = !empty($settings['border_border']) || !empty($settings['_border_border']);
        $has_shadow = !empty($settings['box_shadow_box_shadow']) || !empty($settings['_box_shadow_box_shadow']);
        $has_radius = !empty($settings['border_radius']) || !empty($settings['_border_radius']);

        if (!$has_bg && !$has_border && !$has_shadow) {
            return false;
        }

        $has_heading = self::subtree_contains_widget_type($element, 'heading') || self::subtree_contains_widget_type($element, 'e-heading');
        $has_text = self::subtree_contains_widget_type($element, 'text-editor') || self::subtree_contains_widget_type($element, 'e-paragraph');
        $has_button = self::subtree_contains_widget_type($element, 'button') || self::subtree_contains_widget_type($element, 'e-button');

        return $has_heading && ($has_text || $has_button);
    }

    /**
     * Finalizes component overuse audit.
     */
    private static function finalize_component_overuse_audit(array $profile): array
    {
        $issues = [];
        $recommendations = [];

        $button_count = $profile['widget_counts']['button'] ?? 0;
        $card_like_count = $profile['card_like_count'] ?? 0;

        if ($button_count >= 4) {
            $issues[] = [
                'type'     => 'component_overuse',
                'severity' => 'warning',
                'message'  => "{$button_count} buttons detected — consider using them more selectively.",
            ];
            $recommendations[] = 'Reduce the number of call-to-action buttons. Use buttons only for primary actions.';
        }

        if ($card_like_count >= 3) {
            $issues[] = [
                'type'     => 'component_overuse',
                'severity' => 'warning',
                'message'  => "{$card_like_count} card-like components detected — surface language may feel monotonous.",
            ];
            $recommendations[] = 'Reduce card repetition. Introduce at least one section without card-like surfaces.';
        }

        return [
            'widget_counts'   => $profile['widget_counts'] ?? [],
            'card_like_count' => $card_like_count,
            'card_like_ids'   => $profile['card_like_ids'] ?? [],
            'issues'          => $issues,
            'recommendations' => array_values(array_unique($recommendations)),
        ];
    }

    /**
     * Finalizes surface overuse audit.
     */
    private static function finalize_surface_overuse_audit(array $collector): array
    {
        $issues = [];
        $repeated = [];

        foreach ($collector as $sig => $ids) {
            $ids = array_values(array_unique(array_filter(array_map('strval', (array) $ids))));
            $count = count($ids);
            if ($count >= 3) {
                $repeated[] = [
                    'signature' => $sig,
                    'count'     => $count,
                    'element_ids' => array_slice($ids, 0, 5),
                ];
            }
        }

        usort($repeated, fn($a, $b) => $b['count'] - $a['count']);
        $repeated = array_slice($repeated, 0, 8);

        if (!empty($repeated)) {
            $issues[] = [
                'type'     => 'surface_overuse',
                'severity' => 'warning',
                'message'  => count($repeated) . ' surface signature(s) repeated across 3+ containers.',
            ];
        }

        return [
            'issues'          => $issues,
            'repeated_surfaces' => $repeated,
            'recommendations' => !empty($repeated)
                ? ['Assess whether repeated surface treatment feels formulaic. Introduce background and border-radius variation.']
                : [],
        ];
    }

    /**
     * Computes emphasis profile for a top-level section.
     */
    private static function compute_section_emphasis_profile(array $element): array
    {
        $settings = $element['settings'] ?? [];
        $tone = self::classify_surface_tone($settings);

        $has_h1 = self::subtree_contains_heading_tag($element, 'h1');
        $has_h2 = self::subtree_contains_heading_tag($element, 'h2');
        $has_media = self::subtree_contains_widget_type($element, 'image') || self::subtree_contains_widget_type($element, 'e-image');
        $has_btn = self::subtree_contains_widget_type($element, 'button') || self::subtree_contains_widget_type($element, 'e-button');
        $has_text = self::subtree_contains_text_editor($element);

        $component = self::build_global_component_profile([$element]);
        $widget_counts = $component['widget_counts'] ?? [];
        $card_like_ids = $component['card_like_ids'] ?? [];
        $button_count = (int) ($widget_counts['button'] ?? 0);
        $card_like_count = count($card_like_ids);

        $score = 0;
        $score += $has_h1 ? 4 : 0;
        $score += $has_h2 ? 3 : 0;
        $score += $has_media ? 2 : 0;
        $score += $has_btn ? 2 : 0;
        $score += $has_text ? 1 : 0;
        $score += in_array($tone, ['dark', 'accent'], true) ? 1 : 0;

        $spotlight_score = 0;
        $spotlight_score += $score >= 8 ? 2 : ($score >= 6 ? 1 : 0);
        $spotlight_score += in_array($tone, ['dark', 'accent'], true) ? 1 : 0;
        $spotlight_score += $button_count >= 2 ? 1 : 0;
        $spotlight_score += $card_like_count >= 1 ? 1 : 0;
        $spotlight_score += ($has_media && ($has_h1 || $has_h2)) ? 1 : 0;

        return [
            'element_id'      => (string) ($element['id'] ?? ''),
            'score'           => $score,
            'tone'            => $tone,
            'has_h1'          => $has_h1,
            'has_h2'          => $has_h2,
            'has_media'       => $has_media,
            'has_button'      => $has_btn,
            'has_text'        => $has_text,
            'button_count'    => $button_count,
            'card_like_count' => $card_like_count,
            'card_like_ids'   => $card_like_ids,
            'spotlight_score' => $spotlight_score,
        ];
    }

    /**
     * Finalizes emphasis drift audit.
     */
    private static function finalize_emphasis_drift_audit(array $profiles): array
    {
        $issues = [];
        $scores = array_column($profiles, 'score');
        $n = count($profiles);

        if ($n < 4) {
            return ['issues' => []];
        }

        $range = max($scores) - min($scores);
        $cta_sections = count(array_filter($profiles, fn($p) => $p['has_button'] ?? false));

        if ($range <= 2 && $cta_sections >= 3) {
            $issues[] = [
                'type'     => 'emphasis_drift',
                'severity' => 'warning',
                'message'  => "Flat emphasis across {$n} sections (range: {$range}). Key sections may lose impact.",
                'range'    => $range,
                'cta_count' => $cta_sections,
            ];
        }

        return ['issues' => $issues];
    }

    /**
     * Finalizes section rivalry audit.
     */
    private static function finalize_section_rivalry_audit(array $profiles): array
    {
        $issues = [];
        $n = count($profiles);

        if ($n < 4) {
            return ['issues' => []];
        }

        $peaks = [];
        foreach ($profiles as $i => $p) {
            $spotlight = $p['spotlight_score'] ?? 0;
            $score = $p['score'] ?? 0;
            $tone = $p['tone'] ?? 'light';
            $card_count = $p['card_like_count'] ?? 0;

            $is_peak = $spotlight >= 4;
            $is_peak = $is_peak || ($spotlight >= 3 && $score >= 7 && (in_array($tone, ['dark', 'accent'], true) || $card_count >= 1));

            if ($is_peak) {
                $peaks[] = $i;
            }
        }

        $peak_count = count($peaks);
        $has_adjacent = false;
        for ($i = 1; $i < count($peaks); $i++) {
            if ($peaks[$i] - $peaks[$i - 1] === 1) {
                $has_adjacent = true;
                break;
            }
        }

        $peak_ratio = $n > 0 ? $peak_count / $n : 0;

        if ($peak_count >= 3 && ($has_adjacent || $peak_ratio >= 0.5)) {
            $issues[] = [
                'type'       => 'section_rivalry',
                'severity'   => 'error',
                'message'    => "{$peak_count} peak sections out of {$n} total. " . ($has_adjacent ? 'Adjacent peaks detected.' : 'Peak ratio exceeds 50%.'),
                'peak_count' => $peak_count,
                'total'      => $n,
                'adjacent'   => $has_adjacent,
            ];
        }

        return ['issues' => $issues];
    }

    /**
     * Finalizes composition rhythm audit.
     */
    private static function finalize_composition_rhythm_audit(array $profiles): array
    {
        $issues = [];
        $n = count($profiles);

        if ($n < 3) {
            return ['issues' => []];
        }

        $tones = array_column($profiles, 'tone');
        $run_start = 0;
        for ($i = 1; $i <= $n; $i++) {
            if ($i < $n && $tones[$i] === $tones[$run_start]) {
                continue;
            }
            $run_length = $i - $run_start;
            if ($run_length >= 3) {
                $issues[] = [
                    'type'     => 'composition_rhythm',
                    'severity' => 'warning',
                    'message'  => "{$run_length} consecutive sections with same tone '{$tones[$run_start]}' — reduced visual pacing.",
                    'run_length' => $run_length,
                    'tone'       => $tones[$run_start],
                    'start_index' => $run_start,
                ];
            }
            $run_start = $i;
        }

        return ['issues' => $issues];
    }

    /**
     * Computes separator profile for a section.
     */
    private static function compute_section_separator_profile(array $element): array
    {
        return [
            'element_id' => (string) ($element['id'] ?? ''),
            'has_top_border' => self::has_visible_top_border($element),
            'tone' => self::classify_surface_tone($element['settings'] ?? []),
        ];
    }

    /**
     * Finalizes separator discipline audit.
     */
    private static function finalize_separator_discipline_audit(array $profiles): array
    {
        $issues = [];
        $n = count($profiles);

        $separator_count = count(array_filter($profiles, fn($p) => $p['has_top_border'] ?? false));

        if ($n >= 5 && $separator_count >= 4) {
            $issues[] = [
                'type'     => 'separator_overuse',
                'severity' => 'warning',
                'message'  => "{$separator_count} of {$n} sections have top-border separators — appears mechanically divided.",
                'separator_count' => $separator_count,
                'total' => $n,
            ];
        }

        // Check for consecutive separator runs
        $run_start = -1;
        for ($i = 0; $i <= $n; $i++) {
            $has_sep = $i < $n && ($profiles[$i]['has_top_border'] ?? false);
            if ($has_sep && $run_start === -1) {
                $run_start = $i;
            } elseif (!$has_sep && $run_start !== -1) {
                $run_length = $i - $run_start;
                if ($run_length >= 3) {
                    $issues[] = [
                        'type'     => 'separator_overuse',
                        'severity' => 'info',
                        'message'  => "{$run_length} consecutive sections with top-border separators.",
                        'run_length' => $run_length,
                    ];
                }
                $run_start = -1;
            }
        }

        return ['issues' => $issues];
    }

    // ============================================================
    // UTILITY FUNCTIONS
    // ============================================================

    /**
     * Gets top-level container elements from Elementor data.
     */
    private static function get_top_level_containers(array $data): array
    {
        $containers = [];
        foreach ($data as $el) {
            $el_type = $el['elType'] ?? '';
            if (in_array($el_type, ['container', 'e-flexbox', 'e-div-block', 'section'], true)) {
                $containers[] = $el;
            }
        }
        return $containers;
    }

    /**
     * Classifies the surface tone based on background color.
     */
    private static function classify_surface_tone(array $settings): string
    {
        $bg = $settings['background_color'] ?? '';
        
        // Also check background_background field (V4 format)
        if (empty($bg) && !empty($settings['background_background'])) {
            $bg = $settings['background_background'];
        }
        
        if (is_array($bg)) {
            $bg = $bg['value'] ?? $bg['color'] ?? '';
        }

        if (empty($bg)) {
            return 'light';
        }

        // Check for CSS variables
        if (is_string($bg) && strpos($bg, 'var(') === 0) {
            return 'neutral';
        }

        $hex = self::normalize_hex_color((string) $bg);
        if ($hex === null) {
            // Non-hex colors (rgba, hsl, named colors) — assume light background
            return 'light';
        }

        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        // Luminance approximation
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        if ($luminance < 0.25) return 'dark';
        if ($luminance < 0.55) return 'accent';
        return 'light';
    }

    /**
     * Normalizes a color string to hex format.
     */
    private static function normalize_hex_color(string $color): ?string
    {
        $color = trim($color);

        // Already hex
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
            if (strlen($color) === 4) {
                $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
            }
            return strtolower($color);
        }

        return null;
    }

    /**
     * Checks if an element has a visible top border.
     */
    private static function has_visible_top_border(array $element): bool
    {
        $settings = $element['settings'] ?? [];

        $border = $settings['border_border'] ?? $settings['_border_border'] ?? '';
        if (empty($border) && empty($settings['border_top'])) {
            return false;
        }

        $border_width = $settings['border_width'] ?? $settings['_border_width'] ?? [];
        if (is_array($border_width)) {
            $top = $border_width['top'] ?? $border_width['unit'] ?? '0';
        } else {
            $top = '0';
        }

        if ($top === '0' || $top === '0px' || $top === '') {
            return false;
        }

        // Check border color
        $border_color = $settings['border_color'] ?? $settings['_border_color'] ?? '';
        if (empty($border_color) || $border_color === 'transparent') {
            return false;
        }

        return true;
    }

    /**
     * Checks if a subtree contains a widget of a specific type.
     */
    private static function subtree_contains_widget_type(array $element, string $widget_type): bool
    {
        $el_type = $element['elType'] ?? '';
        if ($el_type === 'widget') {
            return ($element['widgetType'] ?? '') === $widget_type;
        }

        foreach ($element['elements'] ?? [] as $child) {
            if (is_array($child) && self::subtree_contains_widget_type($child, $widget_type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a subtree contains a heading with a specific tag.
     */
    private static function subtree_contains_heading_tag(array $element, string $tag): bool
    {
        $el_type = $element['elType'] ?? '';
        if ($el_type === 'widget') {
            $wt = $element['widgetType'] ?? '';
            if ($wt === 'heading' || $wt === 'e-heading') {
                $header_tag = $element['settings']['header_size'] ?? $element['settings']['tag'] ?? 'h2';
                return $header_tag === $tag;
            }
        }

        foreach ($element['elements'] ?? [] as $child) {
            if (is_array($child) && self::subtree_contains_heading_tag($child, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a subtree contains a text editor widget.
     */
    private static function subtree_contains_text_editor(array $element): bool
    {
        return self::subtree_contains_widget_type($element, 'text-editor')
            || self::subtree_contains_widget_type($element, 'e-paragraph');
    }

    /**
     * Counts total words in all text widgets within a subtree.
     */
    private static function count_subtree_words(array $element): int
    {
        $total = 0;

        $el_type = $element['elType'] ?? '';
        if ($el_type === 'widget') {
            $wt = $element['widgetType'] ?? '';
            if (in_array($wt, ['text-editor', 'e-paragraph', 'heading', 'e-heading'], true)) {
                $text = $element['settings']['editor'] ?? $element['settings']['title'] ?? '';
                if (is_string($text)) {
                    $text = wp_strip_all_tags($text);
                    $total += str_word_count($text);
                }
            }
        }

        foreach ($element['elements'] ?? [] as $child) {
            if (is_array($child)) {
                $total += self::count_subtree_words($child);
            }
        }

        return $total;
    }

    /**
     * Extracts column width from an element.
     */
    private static function extract_column_width(array $element): string
    {
        $settings = $element['settings'] ?? [];

        // V4 style-based width
        foreach ($element['styles'] ?? [] as $style) {
            foreach ($style['variants'] ?? [] as $variant) {
                if (($variant['meta']['breakpoint'] ?? 'desktop') !== 'desktop') continue;
                $width = $variant['props']['width'] ?? null;
                if ($width !== null) {
                    if (is_array($width)) {
                        return (string) ($width['value'] ?? $width['size'] ?? '50');
                    }
                    return (string) $width;
                }
            }
        }

        // V3 settings fallback
        $v3_width = $settings['_column_size'] ?? $settings['width'] ?? '50';
        if (is_array($v3_width)) {
            return (string) ($v3_width['size'] ?? $v3_width['value'] ?? '50');
        }
        return (string) $v3_width;
    }

    /**
     * Scores the content weight of a column.
     */
    private static function score_column_content(array $element): int
    {
        $score = 0;
        $score += self::subtree_contains_widget_type($element, 'heading') || self::subtree_contains_widget_type($element, 'e-heading') ? 3 : 0;
        $score += self::subtree_contains_widget_type($element, 'image') || self::subtree_contains_widget_type($element, 'e-image') ? 2 : 0;
        $score += self::subtree_contains_widget_type($element, 'button') || self::subtree_contains_widget_type($element, 'e-button') ? 2 : 0;
        $score += self::subtree_contains_text_editor($element) ? 1 : 0;

        $word_count = self::count_subtree_words($element);
        if ($word_count > 100) $score += 2;
        elseif ($word_count > 50) $score += 1;

        // Bonus for having child containers with content
        $child_containers = array_filter($element['elements'] ?? [], function ($c) {
            return in_array($c['elType'] ?? '', ['container', 'e-flexbox', 'e-div-block'], true);
        });
        $score += count($child_containers);

        return $score;
    }

    /**
     * Classifies the role of a column.
     */
    private static function classify_column_role(array $element): string
    {
        $has_media = self::subtree_contains_widget_type($element, 'image') || self::subtree_contains_widget_type($element, 'e-image');
        $has_heading = self::subtree_contains_widget_type($element, 'heading') || self::subtree_contains_widget_type($element, 'e-heading');
        $has_text = self::subtree_contains_text_editor($element);
        $has_button = self::subtree_contains_widget_type($element, 'button') || self::subtree_contains_widget_type($element, 'e-button');

        if ($has_media && ($has_heading || $has_text)) return 'media_content';
        if ($has_media && !$has_heading && !$has_text) return 'media_only';
        if ($has_heading && $has_button) return 'cta';
        if ($has_heading || $has_text) return 'copy';
        return 'empty';
    }

    /**
     * Extracts gap token from a container.
     */
    private static function extract_gap_token(array $element): string
    {
        foreach ($element['styles'] ?? [] as $style) {
            foreach ($style['variants'] ?? [] as $variant) {
                if (($variant['meta']['breakpoint'] ?? 'desktop') !== 'desktop') continue;
                $gap = $variant['props']['gap'] ?? $variant['props']['column-gap'] ?? null;
                if ($gap !== null) {
                    if (is_array($gap)) {
                        return (string) ($gap['value'] ?? $gap['size'] ?? 'default');
                    }
                    return (string) $gap;
                }
            }
        }

        // V3 setting fallback
        $v3_gap = $element['settings']['gap'] ?? $element['settings']['gap_between_elements'] ?? 'default';
        if (is_array($v3_gap)) {
            return (string) ($v3_gap['size'] ?? $v3_gap['value'] ?? 'default');
        }
        return (string) $v3_gap;
    }

    /**
     * Generates aggregated recommendations from all audits.
     */
    private static function generate_recommendations(array $audits): array
    {
        $all = [];
        foreach ($audits as $audit) {
            $recs = $audit['recommendations'] ?? [];
            foreach ($recs as $rec) {
                if (is_string($rec) && !empty($rec)) {
                    $all[] = $rec;
                }
            }
        }
        return array_values(array_unique($all));
    }
}

// Register via wp_abilities_api_init hook (defense-in-depth alongside bootstrap direct call)
add_action('wp_abilities_api_init', [Design_Evaluator::class, 'register']);
