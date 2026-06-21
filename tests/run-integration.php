<?php
/**
 * Run the WordPress-integrated PHPUnit test for fix_kit_styles_for_page.
 *
 * Usage: php wp-content/plugins/novamira-adrianv2/tests/run-integration.php
 *
 * Works with PHPUnit 10.x — uses the CLI Application runner with a custom
 * bootstrap that loads WordPress.
 */

declare(strict_types=1);

// ── Bootstrap: load WordPress ──
$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (!file_exists($wp_load)) {
    echo "ERROR: WordPress not found at $wp_load\n";
    exit(1);
}
require_once $wp_load;

// Load required plugin classes.
require_once __DIR__ . '/../includes/helpers/class-conversion-auto-fixer.php';

// ── Run PHPUnit programmatically ──
// PHPUnit 10: use the Application class.
require_once __DIR__ . '/../vendor/autoload.php';

// Build arguments for the PHPUnit application.
$test_file = __DIR__ . '/FixKitStylesForPageIntegrationTest.php';
$bootstrap = __DIR__ . '/bootstrap-integration.php';

// Write a bootstrap file that "no-ops" since WP is already loaded.
file_put_contents($bootstrap, "<?php\n// WordPress already loaded by run-integration.php.\n");

$args = [
    'phpunit',
    '--no-configuration',
    '--bootstrap=' . $bootstrap,
    '--testdox',
    $test_file,
];

// Suppress deprecated warnings from WordPress.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$_SERVER['argv'] = $args;
$_SERVER['argc'] = count($args);

try {
    $app = new PHPUnit\TextUI\Application();
    $app->run($args);
} catch (\Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // Clean up temp bootstrap.
    @unlink($bootstrap);
}
