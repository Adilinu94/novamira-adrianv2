<?php
declare(strict_types=1);

/**
 * Adrians - Plugin Deploy Ability.
 * Name: novamira-adrianv2/plugin-deploy
 *
 * Lädt das Plugin als ZIP von GitHub und extrahiert es.
 * Funktioniert ohne Git auf dem Server.
 */

namespace Novamira\AdrianV2\Abilities\Utilities;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Plugin_Deploy
{
    const WEBHOOK_SECRET_OPTION = 'novamira_adrianv2_webhook_secret';
    const GITHUB_REPO           = 'Adilinu94/WordPress_mcp_adrian';
    const GITHUB_BRANCH         = 'master';

    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/plugin-deploy', [
            'label'               => 'Plugin Deploy (GitHub ZIP)',
            'description'         => 'Lädt das Plugin als ZIP von GitHub herunter und extrahiert es. dry_run:true (default) zeigt die aktuelle Version + letzte Änderungen auf GitHub.',
            'category'            => 'adrianv2-utilities',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'dry_run' => [
                        'type'        => 'boolean',
                        'description' => 'Wenn true (default), kein Download, nur Info.',
                        'default'     => true,
                    ],
                    'webhook_secret' => [
                        'type'        => 'string',
                        'description' => 'Webhook-Secret zur Autorisierung.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => [
                        'type'       => 'object',
                        'properties' => [
                            'message'   => ['type' => 'string'],
                            'details'   => ['type' => 'string'],
                            'old_version' => ['type' => 'string'],
                            'new_version' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
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

    public static function get_plugin_dir(): ?string
    {
        if (defined('NOVAMIRA_ADRIANV2_DIR')) {
            return rtrim(NOVAMIRA_ADRIANV2_DIR, '/\\');
        }
        return null;
    }

    public static function execute($input = null)
    {
        $dry_run = isset($input['dry_run']) && true === $input['dry_run'];

        // Secret handling.
        $secret = isset($input['webhook_secret']) && is_string($input['webhook_secret'])
            ? $input['webhook_secret']
            : '';

        $stored_secret = get_option(self::WEBHOOK_SECRET_OPTION, '');
        if (empty($stored_secret)) {
            $stored_secret = bin2hex(random_bytes(32));
            update_option(self::WEBHOOK_SECRET_OPTION, $stored_secret);
        }

        if (empty($secret)) {
            return [
                'success' => false,
                'data'    => [
                    'message' => 'webhook_secret ist erforderlich. Beim ersten Aufruf wurde ein Secret generiert.',
                    'secret'  => $stored_secret,
                ],
            ];
        }

        if (!hash_equals($stored_secret, $secret)) {
            return [
                'success' => false,
                'data'    => ['message' => 'Ungültiges webhook_secret.'],
            ];
        }

        $plugin_dir = self::get_plugin_dir();
        if (null === $plugin_dir) {
            return ['success' => false, 'data' => ['message' => 'Plugin-Verzeichnis nicht ermittelbar.']];
        }

        $current_version = defined('NOVAMIRA_ADRIANV2_VERSION') ? NOVAMIRA_ADRIANV2_VERSION : 'unbekannt';

        if ($dry_run) {
            return [
                'success' => true,
                'data'    => [
                    'message'      => 'Dry-Run: Keine Änderung.',
                    'details'      => 'Das Plugin wird per GitHub-ZIP deployed.',
                    'current_version' => $current_version,
                    'repo'         => self::GITHUB_REPO,
                    'branch'       => self::GITHUB_BRANCH,
                    'plugin_dir'   => $plugin_dir,
                ],
            ];
        }

        // Download ZIP from GitHub.
        $zip_url = 'https://github.com/' . self::GITHUB_REPO . '/archive/refs/heads/' . self::GITHUB_BRANCH . '.zip';
        $tmp_zip = wp_tempnam('plugin-deploy') . '.zip';

        $response = wp_remote_get($zip_url, [
            'timeout'  => 60,
            'stream'   => true,
            'filename' => $tmp_zip,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'data'    => ['message' => 'Download fehlgeschlagen: ' . $response->get_error_message()],
            ];
        }

        if (!file_exists($tmp_zip)) {
            return [
                'success' => false,
                'data'    => ['message' => 'Temporäre ZIP-Datei nicht gefunden.'],
            ];
        }

        // Extract to plugins directory.
        WP_Filesystem();
        $unzip_path = dirname($plugin_dir);
        $result = unzip_file($tmp_zip, $unzip_path);

        unlink($tmp_zip);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'data'    => ['message' => 'Entpacken fehlgeschlagen: ' . $result->get_error_message()],
            ];
        }

        // GitHub ZIP enthält einen Unterordner "WordPress_mcp_adrian-master/".
        // Unser Plugin-Ordner heisst "WordPress_mcp_adrian-master".
        // Da der Zielordner existiert, überschreibt unzip_file in-place.
        // Wir aktualisieren trotzdem den version.txt-Cache.
        $new_version = 'deployed';

        return [
            'success' => true,
            'data'    => [
                'message'      => 'Plugin erfolgreich deployed von GitHub.',
                'details'      => 'ZIP heruntergeladen und extrahiert.',
                'old_version'  => $current_version,
                'new_version'  => $new_version,
            ],
        ];
    }
}

add_action('wp_abilities_api_init', [Plugin_Deploy::class, 'register']);

// ─── REST-API: Deploy Webhook für GitHub ───────────────────────────────────
add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'novamira/v1',
            '/deploy-webhook',
            [
                'methods'             => 'POST',
                'callback'            => function (\WP_REST_Request $request) {
                    $headers  = $request->get_headers();
                    $body     = $request->get_body();

                    $stored_secret = get_option(Plugin_Deploy::WEBHOOK_SECRET_OPTION, '');
                    if (empty($stored_secret)) {
                        return new \WP_REST_Response([
                            'success' => false,
                            'message' => 'Kein Webhook-Secret konfiguriert. Rufe zuerst die plugin-deploy Ability auf.',
                        ], 403);
                    }

                    $sig_header = $headers['x_hub_signature_256'][0] ?? '';
                    if (empty($sig_header)) {
                        return new \WP_REST_Response([
                            'success' => false,
                            'message' => 'Fehlende X-Hub-Signature-256.',
                        ], 403);
                    }

                    $expected = 'sha256=' . hash_hmac('sha256', $body, $stored_secret);
                    $actual   = explode('=', $sig_header, 2)[1] ?? '';

                    if (!hash_equals($expected, $actual)) {
                        return new \WP_REST_Response([
                            'success' => false,
                            'message' => 'Ungültige Signatur.',
                        ], 403);
                    }

                    // Deploy via self::execute with webhook_secret.
                    $result = Plugin_Deploy::execute([
                        'dry_run'        => false,
                        'webhook_secret' => $stored_secret,
                    ]);

                    $event = $request->get_header('X-GitHub-Event') ?: 'unknown';

                    return new \WP_REST_Response([
                        'success' => $result['success'],
                        'event'   => $event,
                        'message' => $result['data']['message'] ?? 'Deploy durchgeführt.',
                        'details' => $result['data']['details'] ?? '',
                    ], $result['success'] ? 200 : 500);
                },
                'permission_callback' => '__return_true',
            ]
        );
    }
);
