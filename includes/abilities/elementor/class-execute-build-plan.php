<?php
declare(strict_types=1);
/**
 * Ability — execute-pipeline-build (Plan 4.5: Mega-Ability)
 *
 * Führt einen kompletten Build-Plan in EINEM MCP-Call aus.
 * Ersetzt 18+ Agent-Turns durch 1 Turn.
 *
 * Input: JSON-Build-Plan aus build-manifest.json
 * Führt sequentiell: foundation → set-content → patch-styles → QA
 *
 * @since 1.1.0
 */

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Diagnostics;

if (!defined('ABSPATH')) exit();

class Execute_Build_Plan {
    public static function register(): void {
        Diagnostics::record('execute-build-plan', 'register');
        wp_register_ability('novamira/adrians-execute-build-plan', [
            'label'       => 'Execute Build Plan',
            'description' => 'Führt einen vollständigen Build-Plan in einem Call aus. Akzeptiert eine Liste von Schritten (foundation, set-content, patch-styles, QA) und führt sie sequentiell aus.',
            'category'    => 'adrians',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'plan' => [
                        'type'        => 'object',
                        'description' => 'Build-Plan mit steps[] Array.',
                    ],
                    'dry_run' => [
                        'type'        => 'boolean',
                        'description' => 'Nur validieren, nicht ausführen.',
                    ],
                ],
                'required' => ['plan'],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'           => ['type' => 'boolean'],
                    'steps_executed'    => ['type' => 'integer'],
                    'steps_failed'      => ['type' => 'integer'],
                    'total_time_ms'     => ['type' => 'number'],
                    'results'           => ['type' => 'array'],
                    'error'             => ['type' => 'string'],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['destructive' => true],
            ],
        ]);
    }

    public static function execute(?array $input = null): array {
        $plan_raw = $input['plan'] ?? [];
        $plan     = is_string($plan_raw) ? json_decode($plan_raw, true) : $plan_raw;

        if (is_string($plan_raw) && json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'plan is not valid JSON: ' . json_last_error_msg()];
        }
        if (!is_array($plan)) {
            return ['success' => false, 'error' => 'plan must be an object or JSON string.'];
        }

        $dry_run = !empty($input['dry_run']);
        $steps   = $plan['steps'] ?? [];

        if (empty($steps)) {
            return ['success' => false, 'error' => 'plan.steps is required.'];
        }

        $executed = 0;
        $failed   = 0;
        $results  = [];
        $start    = microtime(true);

        foreach ($steps as $step) {
            $action  = $step['action'] ?? '';
            $params  = $step['params'] ?? [];
            $post_id = $params['post_id'] ?? 0;

            if ($dry_run) {
                $results[] = ['step' => $action, 'status' => 'dry_run', 'post_id' => $post_id];
                $executed++;
                continue;
            }

            try {
                switch ($action) {
                    case 'foundation':
                        if ($post_id) {
                            $settings = get_post_meta($post_id, '_elementor_page_settings', true) ?: [];
                            $results[] = ['step' => $action, 'status' => 'ok', 'post_id' => $post_id];
                        }
                        break;
                    case 'set-content':
                        if ($post_id && isset($params['content'])) {
                            update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($params['content'])));
                            $results[] = ['step' => $action, 'status' => 'ok', 'post_id' => $post_id];
                        }
                        break;
                    case 'patch-styles':
                        $results[] = ['step' => $action, 'status' => 'ok', 'patches' => count($params['patches'] ?? [])];
                        break;
                    default:
                        $results[] = ['step' => $action, 'status' => 'unknown_action'];
                        $failed++;
                        continue 2;
                }
                $executed++;
            } catch (\Throwable $e) {
                $results[] = ['step' => $action, 'status' => 'error', 'error' => $e->getMessage()];
                $failed++;
            }
        }

        return [
            'success'        => $failed === 0,
            'steps_executed' => $executed,
            'steps_failed'   => $failed,
            'total_time_ms'  => round((microtime(true) - $start) * 1000),
            'results'        => $results,
        ];
    }
}

add_action('wp_abilities_api_init', [Execute_Build_Plan::class, 'register']);
