<?php
declare(strict_types=1);

$wp_load = dirname(__DIR__, 3) . '/wp-load.php';
require_once $wp_load;

require_once __DIR__ . '/includes/helpers/class-elementor-version-resolver.php';
require_once __DIR__ . '/includes/helpers/class-elementor-document-saver.php';
require_once __DIR__ . '/includes/helpers/class-helpers.php';
require_once __DIR__ . '/includes/helpers/class-v3-to-v4-converter.php';
require_once __DIR__ . '/includes/helpers/class-conversion-auditor.php';
require_once __DIR__ . '/includes/helpers/class-conversion-auto-fixer.php';
require_once __DIR__ . '/includes/abilities/elementor/class-kit-convert-v3-to-v4.php';
require_once __DIR__ . '/includes/abilities/elementor/class-convert-page-v3-to-v4.php';

use Novamira\AdrianV2\Abilities\Elementor\Convert_Page_V3_To_V4;

foreach ([3598, 5368] as $pid) {
    $r = Convert_Page_V3_To_V4::execute(['post_id'=>$pid, 'dry_run'=>true, 'auto_fix'=>true]);
    $a = $r['audit'] ?? [];
    echo "Page $pid: fixes={$r['fixes_applied']} audit={$a['total_issues']}";
    if ($a['total_issues'] > 0) {
        echo " (E:{$a['by_severity']['error']} W:{$a['by_severity']['warning']} I:{$a['by_severity']['info']})";
        foreach ($a['issues'] as $i) echo "\n  [{$i['severity']}:{$i['type']}] {$i['message']}";
    }
    echo "\n";
}
echo ($a['total_issues'] ?? 1) ? "" : "BOTH CLEAN!\n";
