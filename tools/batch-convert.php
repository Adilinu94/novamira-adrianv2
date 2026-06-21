<?php
/**
 * Batch V3-to-V4 Page Converter
 * Usage: php batch-convert.php [--dry-run] [--execute]
 */
declare(strict_types=1);

$wp_load = dirname( __DIR__, 3 ) . '/wp-load.php';
require_once $wp_load;
require_once __DIR__ . '/includes/helpers/class-elementor-version-resolver.php';
require_once __DIR__ . '/includes/helpers/class-elementor-document-saver.php';
require_once __DIR__ . '/includes/helpers/class-helpers.php';
require_once __DIR__ . '/includes/helpers/class-v3-to-v4-converter.php';
require_once __DIR__ . '/includes/helpers/class-conversion-auditor.php';
require_once __DIR__ . '/includes/helpers/class-conversion-auto-fixer.php';
require_once __DIR__ . '/includes/abilities/elementor/class-kit-convert-v3-to-v4.php';
require_once __DIR__ . '/includes/abilities/elementor/class-convert-page-v3-to-v4.php';
require_once __DIR__ . '/includes/abilities/elementor/class-list-elementor-pages.php';

use Novamira\AdrianV2\Abilities\Elementor\Convert_Page_V3_To_V4;
use Novamira\AdrianV2\Abilities\Elementor\List_Elementor_Pages;

$dry_run_only = in_array('--dry-run', $argv ?? []);
$do_execute   = in_array('--execute', $argv ?? []);

// Step 1: List all V3 pages
echo "=== Step 1: Discovering V3 pages ===\n";
$list_result = List_Elementor_Pages::execute([
    'post_type'       => 'any',
    'status'          => 'publish',
    'limit'           => 200,
    'include_stats'   => true,
    'include_sections'=> false,
]);

if (empty($list_result['success'])) {
    echo "ERROR listing pages: " . json_encode($list_result) . "\n";
    exit(1);
}

// Filter to V3 pages (legacy_widget_count > 0)
$v3_pages = [];
foreach ($list_result['data']['pages'] as $page) {
    $stats = $page['stats'] ?? [];
    if (($stats['legacy_widget_count'] ?? 0) > 0) {
        $v3_pages[] = $page;
    }
}

echo "Found " . count($v3_pages) . " V3 pages out of " . $list_result['data']['count'] . " total.\n\n";

// Step 2: Dry-run preview
echo "=== Step 2: Dry-run preview of all V3 pages ===\n";
$dry_results = [];
foreach ($v3_pages as $page) {
    $pid = $page['id'];
    $title = $page['title'];
    
    try {
        $result = Convert_Page_V3_To_V4::execute([
            'post_id'       => $pid,
            'dry_run'       => true,
            'run_kit_convert'=> false, // Already ran
            'auto_fix'      => true,
        ]);
        
        if (is_wp_error($result)) {
            echo "  [$pid] $title — ERROR: " . $result->get_error_message() . "\n";
            $dry_results[$pid] = ['error' => $result->get_error_message()];
            continue;
        }
        
        $stats = $result['stats'] ?? [];
        $audit = $result['audit'] ?? [];
        $fixes = $result['fixes_applied'] ?? 0;
        
        echo sprintf("  [%d] %s — converted: %d, kept_v3: %d, fixes: %d, audit: %d issues (%dE/%dW/%dI)\n",
            $pid, $title,
            $stats['converted'] ?? 0,
            $stats['kept_v3'] ?? 0,
            $fixes,
            $audit['total_issues'] ?? 0,
            $audit['by_severity']['error'] ?? 0,
            $audit['by_severity']['warning'] ?? 0,
            $audit['by_severity']['info'] ?? 0
        );
        
        $dry_results[$pid] = [
            'title'     => $title,
            'converted' => $stats['converted'] ?? 0,
            'kept_v3'   => $stats['kept_v3'] ?? 0,
            'fixes'     => $fixes,
            'audit_total' => $audit['total_issues'] ?? 0,
            'audit_errors' => $audit['by_severity']['error'] ?? 0,
            'audit_warnings' => $audit['by_severity']['warning'] ?? 0,
            'audit_info' => $audit['by_severity']['info'] ?? 0,
        ];
    } catch (\Throwable $e) {
        echo "  [$pid] $title — EXCEPTION: " . $e->getMessage() . "\n";
        $dry_results[$pid] = ['error' => $e->getMessage()];
    }
}

// Summary
echo "\n=== Dry-Run Summary ===\n";
$total_converted = 0;
$total_kept = 0;
$total_fixes = 0;
$total_audit = 0;
$errors_count = 0;
foreach ($dry_results as $pid => $r) {
    if (isset($r['error'])) {
        $errors_count++;
        continue;
    }
    $total_converted += $r['converted'];
    $total_kept += $r['kept_v3'];
    $total_fixes += $r['fixes'];
    $total_audit += $r['audit_total'];
}

echo "Pages: " . count($dry_results) . " (" . $errors_count . " errors)\n";
echo "Total converted: $total_converted | kept_v3: $total_kept | fixes: $total_fixes | audit issues: $total_audit\n";

if ($dry_run_only) {
    echo "\nDry-run complete. Run with --execute to write changes.\n";
    exit(0);
}

if (!$do_execute) {
    echo "\nNo --execute flag. Add --execute to actually write changes.\n";
    exit(0);
}

// Step 3: Actual execution
echo "\n=== Step 3: Executing conversions (dry_run=false) ===\n";
$exec_results = [];
foreach ($v3_pages as $page) {
    $pid = $page['id'];
    $title = $page['title'];
    
    try {
        $result = Convert_Page_V3_To_V4::execute([
            'post_id'       => $pid,
            'dry_run'       => false,
            'run_kit_convert'=> false,
            'auto_fix'      => true,
        ]);
        
        if (is_wp_error($result)) {
            echo "  [$pid] $title — ERROR: " . $result->get_error_message() . "\n";
            $exec_results[$pid] = ['error' => $result->get_error_message()];
            continue;
        }
        
        $audit = $result['audit'] ?? [];
        $fixes = $result['fixes_applied'] ?? 0;
        
        echo sprintf("  [%d] %s — written ✓  fixes: %d, audit: %d issues\n",
            $pid, $title, $fixes, $audit['total_issues'] ?? 0
        );
        
        $exec_results[$pid] = [
            'title'  => $title,
            'fixes'  => $fixes,
            'audit'  => $audit['total_issues'] ?? 0,
            'success' => true,
        ];
    } catch (\Throwable $e) {
        echo "  [$pid] $title — EXCEPTION: " . $e->getMessage() . "\n";
        $exec_results[$pid] = ['error' => $e->getMessage()];
    }
}

echo "\n=== Execution Summary ===\n";
$success = 0;
$errors = 0;
$total_fixes_exec = 0;
foreach ($exec_results as $pid => $r) {
    if (!empty($r['success'])) {
        $success++;
        $total_fixes_exec += $r['fixes'];
    } else {
        $errors++;
    }
}
echo "Successful: $success | Errors: $errors | Total fixes: $total_fixes_exec\n";
echo "V3 backups saved in _novamira_v3_backup post meta.\n";
