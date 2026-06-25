<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Detach_Component
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/detach-component', [
            'label'               => 'Detach Component',
            'description'         => 'Detach a global widget or template instance from its source, converting it to regular elements. Operates on elements with a templateID reference (global widget instances). Also works on nested template containers by recursively detaching all referenced instances.',
            'category'            => 'adrianv2-elementor',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'post_id'    => [
                        'type'        => 'integer',
                        'description' => 'ID of the page containing the global widget/template instance.',
                    ],
                    'element_id' => [
                        'type'        => 'string',
                        'description' => 'ID of the specific template instance element to detach. If omitted, detaches ALL global widget instances found in the page.',
                    ],
                    'mode'       => [
                        'type'        => 'string',
                        'description' => 'How to handle the detached elements: "replace" replaces the instance with the template content, "unwrap" removes the wrapper but keeps children.',
                        'enum'        => ['replace', 'unwrap'],
                    ],
                ],
                'required'   => ['post_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'        => ['type' => 'boolean'],
                    'detached_count' => ['type' => 'integer'],
                    'detached_ids'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'details'        => ['type' => 'array', 'items' => ['type' => 'object']],
                    'message'        => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $post_id    = absint($input['post_id']);
        $element_id = $input['element_id'] ?? null;
        $mode       = $input['mode'] ?? 'replace';

        $post = get_post($post_id);
        if (!$post) {
            return ['success' => false, 'message' => 'Post not found.'];
        }

        $page_data = get_post_meta($post_id, '_elementor_data', true);
        if (is_string($page_data)) {
            $page_data = json_decode($page_data, true);
        }
        if (!is_array($page_data)) {
            return ['success' => false, 'message' => 'No Elementor data found.'];
        }

        $detached_ids   = [];
        $detached_count = 0;
        $details        = [];

        $process_element = function (&$el) use ($mode, &$details) {
            $template_id = $el['templateID'] ?? null;
            if (empty($template_id)) {
                return null;
            }

            $template_data = get_post_meta((int) $template_id, '_elementor_data', true);
            if (is_string($template_data)) {
                $template_data = json_decode($template_data, true);
            }

            $result = [
                'element_id'  => $el['id'],
                'template_id' => $template_id,
                'mode'        => $mode,
                'has_template_data' => !empty($template_data),
            ];

            if ($mode === 'replace' && !empty($template_data)) {
                // Regenerate IDs in template data
                $regenerate = function (&$elements) use (&$regenerate) {
                    foreach ($elements as &$child) {
                        $child['id'] = substr(md5($child['id'] . mt_rand()), 0, 7);
                        if (!empty($child['elements'])) {
                            $regenerate($child['elements']);
                        }
                    }
                };
                $regenerate($template_data);
                $el                = array_merge($el, $template_data[0]);
                $el['templateID']  = null;
                $result['action']  = 'replaced';
            } elseif ($mode === 'unwrap') {
                // Remove the templateID, keep element as-is
                unset($el['templateID']);
                $result['action'] = 'unwrapped';
            } else {
                // Fallback: just remove the templateID to break the reference
                unset($el['templateID']);
                $result['action'] = 'unlinked';
            }

            return $result;
        };

        if ($element_id) {
            $found = false;
            $walk  = function (&$elements) use (&$walk, $element_id, &$process_element, &$found, &$detached_ids, &$detached_count, &$details) {
                foreach ($elements as &$el) {
                    if ($found) {
                        break;
                    }
                    if ($el['id'] === $element_id) {
                        $found   = true;
                        $result  = $process_element($el);
                        if ($result) {
                            $detached_ids[] = $element_id;
                            $detached_count++;
                            $details[] = $result;
                        }
                        break;
                    }
                    if (!empty($el['elements'])) {
                        $walk($el['elements']);
                    }
                }
            };
            $walk($page_data);

            if (!$found) {
                return ['success' => false, 'message' => "Element '$element_id' not found in page."];
            }
            if ($detached_count === 0) {
                return ['success' => false, 'message' => "Element '$element_id' is not a template/global widget instance (no templateID)."];
            }
        } else {
            // Detach ALL global widget instances
            $walk_all = function (&$elements) use (&$walk_all, &$process_element, &$detached_ids, &$detached_count, &$details) {
                foreach ($elements as &$el) {
                    if (!empty($el['templateID'])) {
                        $result = $process_element($el);
                        if ($result) {
                            $detached_ids[] = $el['id'];
                            $detached_count++;
                            $details[] = $result;
                        }
                    }
                    if (!empty($el['elements'])) {
                        $walk_all($el['elements']);
                    }
                }
            };
            $walk_all($page_data);
        }

        if ($detached_count === 0) {
            return [
                'success'       => true,
                'detached_count' => 0,
                'detached_ids'   => [],
                'message'        => 'No global widget or template instances found in this page.',
            ];
        }

        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($page_data)));
        clean_post_cache($post_id);

        return [
            'success'        => true,
            'detached_count' => $detached_count,
            'detached_ids'   => $detached_ids,
            'details'        => $details,
            'message'        => sprintf('Detached %d global widget/template instance(s).', $detached_count),
        ];
    }
}

add_action('wp_abilities_api_init', [Detach_Component::class, 'register']);
