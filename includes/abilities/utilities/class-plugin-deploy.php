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

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private static function copy_overwrite(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $items = scandir($src);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $s = $src . DIRECTORY_SEPARATOR . $item;
            $d = $dst . DIRECTORY_SEPARATOR . $item;
            if (is_dir($s)) {
                self::rrmdir($d);
                if (!self::copy_overwrite($s, $d)) {
                    return false;
                }
            } else {
                copy($s, $d);
            }
        }
        return true;
    }

    public static function execute($input = null)
    {
        $dry_run = isset($input['dry_run']) && true === $input['dry_run'];

        // Secret handling.
        $secret = isset($input['webhook_secret']) && is_string($input['webhook_secret'])
            ? $input['webhook_secret']
            : '';

        $stored_secret = \get_option(self::WEBHOOK_SECRET_OPTION, '');
        if (empty($stored_secret)) {
            $stored_secret = bin2hex(random_bytes(32));
            \update_option(self::WEBHOOK_SECRET_OPTION, $stored_secret);
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
        $tmp_zip = tempnam(sys_get_temp_dir(), 'plugin-deploy-') . '.zip';

        $response = \wp_remote_get($zip_url, [
            'timeout'  => 60,
            'stream'   => true,
            'filename' => $tmp_zip,
        ]);

        if (\is_wp_error($response)) {
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

        // Extract to a temp directory first.
        $tmp_extract = tempnam(sys_get_temp_dir(), 'plugin-extract-');
        unlink($tmp_extract);
        mkdir($tmp_extract, 0755, true);

        if (!function_exists('\\WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        \WP_Filesystem();
        $result = \unzip_file($tmp_zip, $tmp_extract);

        unlink($tmp_zip);

        if (is_wp_error($result)) {
            self::rrmdir($tmp_extract);
            return [
                'success' => false,
                'data'    => ['message' => 'Entpacken fehlgeschlagen: ' . $result->get_error_message()],
            ];
        }

        // GitHub ZIP enthält z.B. "WordPress_mcp_adrian-master/" als Root.
        $entries = scandir($tmp_extract);
        $extracted_dir = null;
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (is_dir($tmp_extract . DIRECTORY_SEPARATOR . $entry)) {
                $extracted_dir = $tmp_extract . DIRECTORY_SEPARATOR . $entry;
                break;
            }
        }

        if (null === $extracted_dir || !is_dir($extracted_dir)) {
            self::rrmdir($tmp_extract);
            return [
                'success' => false,
                'data'    => ['message' => 'Kein Plugin-Ordner im ZIP gefunden.'],
            ];
        }

        // Dateien aus dem extrahierten Ordner in den aktiven Plugin-Ordner kopieren.
        $result = self::copy_overwrite($extracted_dir, $plugin_dir);

        // Temp aufräumen.
        self::rrmdir($tmp_extract);

        if (!$result) {
            return [
                'success' => false,
                'data'    => ['message' => 'Kopieren der Plugin-Dateien fehlgeschlagen.'],
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'message'      => 'Plugin erfolgreich deployed von GitHub.',
                'details'      => 'ZIP heruntergeladen und extrahiert.',
                'old_version'  => $current_version,
                'new_version'  => 'deployed',
            ],
        ];
    }
}

\add_action('wp_abilities_api_init', [Plugin_Deploy::class, 'register']);

// ─── REST-API: Deploy Webhook für GitHub ───────────────────────────────────
// REST-Route sofort registrieren (rest_api_init feuert vor Plugin-Ladung).
$register_webhook = function () {
    \register_rest_route(
        'novamira/v1',
        '/deploy-webhook',
        [
            'methods'             => 'POST',
            'callback'            => function (\WP_REST_Request $request) {
                $headers  = $request->get_headers();
                $body     = $request->get_body();

                $stored_secret = \get_option(Plugin_Deploy::WEBHOOK_SECRET_OPTION, '');
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
};

if (\did_action('rest_api_init')) {
    $register_webhook();
} else {
    \add_action('rest_api_init', $register_webhook);
}
