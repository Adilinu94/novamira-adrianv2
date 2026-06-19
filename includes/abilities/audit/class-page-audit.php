<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Audit;

if (!defined('ABSPATH')) {
    exit();
}

class Page_Audit
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/page-audit', [
            'label'               => 'Page Audit',
            'description'         => 'Audits an Elementor page for issues: empty containers, missing image alt text, broken internal links, and heading hierarchy problems.',
            'category'            => 'novamira-adrianv2',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id' => [
                        'type'        => 'integer',
                        'description' => 'The page/post ID to audit.',
                    ],
                    'checks'  => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string', 'enum' => ['empty_containers', 'missing_alt_text', 'broken_links', 'heading_hierarchy']],
                        'description' => 'Which checks to run. Default: all.',
                    ],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => ['type' => 'object'],
                    'error'   => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $post_id = $input['post_id'];
        $checks  = $input['checks'] ?? ['empty_containers', 'missing_alt_text', 'broken_links', 'heading_hierarchy'];

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'error' => "Post $post_id not found."];
        }

        $raw  = get_post_meta($post_id, '_elementor_data', true);
        $tree = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($tree)) {
            return ['success' => false, 'error' => 'No Elementor data found on this post.'];
        }

        $issues = [];

        // Collect all internal URLs from link controls for broken-link check
        $internal_urls = [];

        // Heading list for hierarchy check
        $headings = [];

        $walk = function(&$els, $depth = 0) use (&$walk, &$issues, &$internal_urls, &$headings, $checks) {
            foreach ($els as &$el) {
                $el_id   = $el['id'] ?? null;
                $el_type = $el['elType'] ?? 'unknown';
                $widget  = $el['widgetType'] ?? null;
                $settings = $el['settings'] ?? [];
                $children = $el['elements'] ?? [];

                // --- Empty containers ---
                if (in_array('empty_containers', $checks) && in_array($el_type, ['container', 'e-flexbox', 'e-div-block'])) {
                    $has_content = false;

                    // Check for widgets/elements that produce visible content
                    if (!empty($children)) {
                        $has_content = true;
                    }

                    // Check for non-empty content controls (v3)
                    $content_keys = ['title', 'editor', 'text', 'description', 'html'];
                    foreach ($content_keys as $ck) {
                        if (!empty($settings[$ck])) {
                            $has_content = true;
                            break;
                        }
                    }

                    if (!$has_content && empty($children)) {
                        $issues[] = [
                            'severity'   => 'warning',
                            'type'       => 'empty_container',
                            'element_id' => $el_id,
                            'message'    => "Container '$el_id' has no children and no content. It may be unintentionally empty.",
                        ];
                    }
                }

                // --- Missing alt text ---
                if (in_array('missing_alt_text', $checks)) {
                    $image_widgets = ['image', 'e-image'];
                    if ($widget && in_array($widget, $image_widgets)) {
                        $alt = $settings['alt'] ?? '';
                        if (is_array($alt)) {
                            $alt = $alt['value'] ?? $alt['url'] ?? '';
                        }
                        if (empty($alt)) {
                            $issues[] = [
                                'severity'   => 'info',
                                'type'       => 'missing_alt_text',
                                'element_id' => $el_id,
                                'message'    => "Image '$el_id' has no alt text. Add descriptive alt text for accessibility.",
                            ];
                        }
                    }
                }

                // --- Collect links for broken-link check ---
                if (in_array('broken_links', $checks)) {
                    $link = $settings['link'] ?? $settings['button_link'] ?? null;
                    if ($link) {
                        $url = null;
                        if (is_array($link)) {
                            $url = $link['url'] ?? null;
                            // v4 atomic link format
                            if (!$url && isset($link['value']['url'])) {
                                $url = $link['value']['url'];
                            }
                        } elseif (is_string($link)) {
                            $url = $link;
                        }
                        if ($url && str_starts_with($url, home_url())) {
                            $internal_urls[$url] = $el_id;
                        }
                    }
                    // Also check v3 link controls
                    foreach (['link', 'read_more_link', 'button_link', 'form_action'] as $lk) {
                        $l = $settings[$lk] ?? null;
                        if (is_array($l) && !empty($l['url'])) {
                            $u = $l['url'];
                            if (is_string($u) && str_starts_with($u, home_url())) {
                                $internal_urls[$u] = $el_id;
                            }
                        }
                    }
                }

                // --- Collect headings for hierarchy check ---
                if (in_array('heading_hierarchy', $checks)) {
                    $heading_widgets = ['heading', 'e-heading'];
                    if ($widget && in_array($widget, $heading_widgets)) {
                        $tag = $settings['header_size'] ?? $settings['tag'] ?? null;
                        if (is_array($tag)) {
                            $tag = $tag['value'] ?? null;
                        }
                        $title = $settings['title'] ?? '';
                        if (is_array($title)) {
                            $title = $title['value'] ?? $title['text'] ?? '';
                        }
                        $headings[] = [
                            'element_id' => $el_id,
                            'tag'        => $tag ?: 'h2',
                            'title'      => is_string($title) ? substr($title, 0, 80) : '',
                        ];
                    }
                }

                if (!empty($children)) {
                    $walk($children, $depth + 1);
                }
            }
        };

        $walk($tree);

        // --- Broken-link check: verify internal URLs ---
        if (in_array('broken_links', $checks) && !empty($internal_urls)) {
            $unique_urls = array_unique(array_keys($internal_urls));
            foreach ($unique_urls as $url) {
                // Extract path from URL
                $path = wp_parse_url($url, PHP_URL_PATH);
                if (!$path || $path === '/') continue;

                // Try to find a post by slug
                $slug = trim($path, '/');
                // Check if it resolves to a post
                $resolved = get_page_by_path($slug, OBJECT, ['publish', 'draft']);
                if (!$resolved) {
                    $issues[] = [
                        'severity'   => 'error',
                        'type'       => 'broken_link',
                        'element_id' => $internal_urls[$url],
                        'message'    => "Link to '$url' appears broken — no page found at this path.",
                    ];
                }
            }
        }

        // --- Heading hierarchy check ---
        if (in_array('heading_hierarchy', $checks) && !empty($headings)) {
            $has_h1 = false;
            $prev_level = 0;

            foreach ($headings as $h) {
                $level = (int) substr($h['tag'], 1);

                if ($level === 1) {
                    $has_h1 = true;
                }

                // Check for skipped levels (e.g., h1 -> h3 skips h2)
                if ($prev_level > 0 && $level > $prev_level + 1) {
                    $issues[] = [
                        'severity'   => 'info',
                        'type'       => 'heading_skip',
                        'element_id' => $h['element_id'],
                        'message'    => "Heading '{$h['element_id']}' uses {$h['tag']} but previous heading was h{$prev_level} — heading level skipped from h{$prev_level} to {$h['tag']}.",
                    ];
                }

                $prev_level = $level;
            }

            if (!$has_h1 && count($headings) > 0) {
                $issues[] = [
                    'severity'   => 'warning',
                    'type'       => 'missing_h1',
                    'element_id' => null,
                    'message'    => 'Page has headings but no H1. Every page should have exactly one H1 for accessibility and SEO.',
                ];
            }

            // Count h1s
            $h1_count = count(array_filter($headings, fn($h) => $h['tag'] === 'h1'));
            if ($h1_count > 1) {
                $issues[] = [
                    'severity'   => 'warning',
                    'type'       => 'multiple_h1',
                    'element_id' => null,
                    'message'    => "Page has $h1_count H1 headings. Best practice is exactly one H1 per page.",
                ];
            }
        }

        // Summarize
        $by_severity = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $by_severity[$issue['severity']]++;
        }

        return [
            'success' => true,
            'data'    => [
                'post_id'      => $post_id,
                'post_title'   => $post->post_title,
                'checks_run'   => $checks,
                'total_issues' => count($issues),
                'by_severity'  => $by_severity,
                'issues'       => $issues,
                'heading_count'=> count($headings),
                'headings'     => $headings,
                'internal_links_checked' => count(array_unique(array_keys($internal_urls))),
            ],
        ];
    }
}

add_action('wp_abilities_api_init', [Page_Audit::class, 'register']);
