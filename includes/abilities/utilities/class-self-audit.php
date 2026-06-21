<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Utilities;

use Novamira\AdrianV2\Helpers\Elementor_Version_Resolver;

if (!defined('ABSPATH')) { exit; }

/**
 * Self_Audit — plugin health checks (BOM, strict_types, ability count).
 *
 * Runs a set of non-destructive health probes on the plugin itself.
 * Designed to be called before every build as a pre-flight check.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */
class Self_Audit {

    /** Expected ability count for this plugin version. */
    private const EXPECTED_ABILITY_COUNT = 60;

    /**
     * Register the ability.
     */
    public static function register(): void {
        wp_register_ability('novamira-adrianv2/self-audit', [
            'name'        => 'novamira-adrianv2/self-audit',
            'label'       => __('Self-Audit', 'novamira-adrianv2'),
            'description' => __('Plugin health check: BOM scan, strict_types probe, ability registration count.', 'novamira-adrianv2'),
            'category'    => 'adrianv2-utilities',
            'callback'    => [self::class, 'execute'],
            'schema'      => [
                'type'       => 'object',
                'properties' => [
                    'include_bom_check'     => ['type' => 'boolean', 'default' => true],
                    'include_strict_probe'  => ['type' => 'boolean', 'default' => true],
                    'include_ability_count' => ['type' => 'boolean', 'default' => true],
                ],
            ],
            'permission_callback' => fn() => current_user_can('manage_options'),
            'mcp' => ['public' => true, 'type' => 'tool'],
        ]);
    }

    /**
     * Execute: run all selected health checks.
     *
     * @param array|null $input
     * @return array
     */
    public static function execute($input = null): array {
        $checks = [];
        $statuses = [];

        if ($input['include_bom_check'] ?? true) {
            $checks[] = $result = self::check_bom();
            $statuses[] = $result['status'];
        }

        if ($input['include_strict_probe'] ?? true) {
            $checks[] = $result = self::check_strict_types();
            $statuses[] = $result['status'];
        }

        if ($input['include_ability_count'] ?? true) {
            $checks[] = $result = self::check_ability_count();
            $statuses[] = $result['status'];
        }

        // Overall status: error if any check errored, warning if any warned, ok otherwise.
        if (in_array('error', $statuses, true)) {
            $overall = 'error';
        } elseif (in_array('warning', $statuses, true)) {
            $overall = 'warning';
        } else {
            $overall = 'ok';
        }

        return [
            'success'       => true,
            'overall_status' => $overall,
            'checks'         => $checks,
            'summary'        => sprintf(
                'Self-audit %s: %d check(s), %d error(s), %d warning(s)',
                $overall,
                count($checks),
                count(array_keys($statuses, 'error', true)),
                count(array_keys($statuses, 'warning', true))
            ),
        ];
    }

    /**
     * Scan all plugin PHP files for UTF-8 BOM (0xEF 0xBB 0xBF).
     *
     * @return array
     */
    private static function check_bom(): array {
        $plugin_dir = NOVAMIRA_ADRIANV2_DIR;
        $files = self::collect_php_files($plugin_dir);
        $bom_files = [];

        foreach ($files as $file) {
            $handle = fopen($file, 'rb');
            if (!$handle) { continue; }
            $bytes = fread($handle, 3);
            fclose($handle);
            if ($bytes === "\xEF\xBB\xBF") {
                $bom_files[] = str_replace($plugin_dir, '', $file);
            }
        }

        $bom_count = count($bom_files);
        return [
            'name'          => 'bom_check',
            'status'        => $bom_count > 0 ? 'error' : 'ok',
            'files_checked' => count($files),
            'files_with_bom' => $bom_count,
            'details'       => $bom_count > 0
                ? sprintf('%d file(s) with BOM: %s', $bom_count, implode(', ', $bom_files))
                : 'No BOM-affected PHP files found.',
        ];
    }

    /**
     * Probe: create a temp file with declare(strict_types=1), run via php -l.
     *
     * @return array
     */
    private static function check_strict_types(): array {
        $tmp = tempnam(sys_get_temp_dir(), 'nvma_');
        if (!$tmp) {
            return ['name' => 'php_strict_probe', 'status' => 'error', 'details' => 'Could not create temp file.'];
        }

        file_put_contents($tmp, "<?php\ndeclare(strict_types=1);\necho 'ok';\n");

        // exec() may be disabled on some hosts.
        if (!function_exists('exec')) {
            unlink($tmp);
            return ['name' => 'php_strict_probe', 'status' => 'warning', 'details' => 'exec() is disabled — cannot run strict_types probe.'];
        }

        $output = [];
        $ret    = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellcmd(PHP_BINARY), escapeshellarg($tmp)), $output, $ret);

        // Also try to run it.
        $run_output = [];
        $run_ret    = 0;
        exec(sprintf('%s %s 2>&1', escapeshellcmd(PHP_BINARY), escapeshellarg($tmp)), $run_output, $run_ret);

        unlink($tmp);

        $lint_ok = 0 === $ret;
        $run_ok  = 0 === $run_ret && in_array('ok', $run_output, true);

        return [
            'name'   => 'php_strict_probe',
            'status' => ($lint_ok && $run_ok) ? 'ok' : 'error',
            'details' => $lint_ok && $run_ok
                ? 'Test file with declare(strict_types=1) parsed and executed without error.'
                : sprintf('Lint: %s (%s). Run: %s (%s).',
                    $ret ? 'FAIL' : 'ok', implode(' ', $output),
                    $run_ret ? 'FAIL' : 'ok', implode(' ', $run_output)
                ),
        ];
    }

    /**
     * Count registered abilities and compare against expected.
     *
     * @return array
     */
    private static function check_ability_count(): array {
        $expected = self::EXPECTED_ABILITY_COUNT;
        $all = function_exists('wp_get_abilities') ? wp_get_abilities() : [];
        $actual = is_array($all) ? count($all) : 0;

        // Filter to only novamira-adrianv2 abilities.
        $v2_abilities = [];
        if (is_array($all)) {
            foreach ($all as $name => $def) {
                if (str_starts_with($name, 'novamira-adrianv2/')) {
                    $v2_abilities[] = $name;
                }
            }
        }
        $v2_count = count($v2_abilities);
        $missing = [];
        if ($v2_count < $expected) {
            // Note: we can't know exactly which are missing without a canonical list.
            $missing = ['Use discover-abilities to compare against expected set.'];
        }

        return [
            'name'     => 'ability_count',
            'status'   => $v2_count >= $expected ? 'ok' : 'warning',
            'expected' => $expected,
            'total_registered' => $actual,
            'v2_registered' => $v2_count,
            'missing'   => $missing,
            'details'  => sprintf('%d novamira-adrianv2 abilities registered (expected >= %d). %d total across all plugins.',
                $v2_count, $expected, $actual),
        ];
    }

    /**
     * Recursively collect all .php files in a directory.
     *
     * @param string $dir
     * @return string[]
     */
    private static function collect_php_files(string $dir): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }
}
