<?php
/**
 * Standalone reflection smoke test for the adrianv2-live-edit +
 * adrianv2-wpcode-check-setup abilities.
 *
 * Runs WITHOUT WP loaded: pulls in tests/mock-functions.php which stubs
 * wp_register_ability, current_user_can, WPCode_Snippet, etc., then loads
 * the three ability classes + helpers and asserts all three slugs register
 * (one directly observed, two reflected via the new WpCode_Check_Setup probe).
 */
declare(strict_types=1);

define('NOVAMIRA_SMOKE', true);
require __DIR__ . '/mock-functions.php';

// Load helpers (provides WPCode_Kses_Bypass, Elementor_Document_Saver, Elementor_CSS_Override).
require_once __DIR__ . '/../includes/helpers/bootstrap.php';

// Load the ability classes — both the original CRUD+meta pair AND the new
// readonly check-setup probe. Order matters: wpcode-snippets is needed for
// the existing reflection-based probe; wpcode-check-setup is independent
// (its detect_active() does NOT depend on WPCode_Snippet::class_exists() so
// it always registers even when WPCode the plugin is not installed — that is
// exactly what the agent needs when probing a misconfigured install).
require_once __DIR__ . '/../includes/abilities/wpcode/class-wpcode-snippets.php';
require_once __DIR__ . '/../includes/abilities/wpcode/class-wpcode-check-setup.php';
require_once __DIR__ . '/../includes/abilities/elementor/class-elementor-assign-class-to-containers.php';

// Ensure the elementor bootstrap pulled the helper trait in.
require_once __DIR__ . '/../includes/abilities/elementor/bootstrap.php';

$GLOBALS['_registered_abilities'] = [];

// Trigger registration. WPCode_Snippets::register() will be a no-op in this
// stub (WPCode class is mocked but not active); WPCode_Check_Setup::register()
// runs unconditionally because it only depends on WpCode_Kses_Bypass (which
// IS loaded via helpers/bootstrap.php) plus the $wpdb mock. The class always
// registers when the file is loaded.
\Novamira\AdrianV2\Abilities\WpCode\WpCode_Snippets::register();
\Novamira\AdrianV2\Abilities\WpCode\WpCode_Check_Setup::register();
\Novamira\AdrianV2\Abilities\Elementor\Elementor_Assign_Class_To_Containers::register();

$registered = array_keys($GLOBALS['_registered_abilities']);
sort($registered);

echo "--- Registered ability slugs (smoke) ---\n";
echo implode("\n", $registered) . "\n";

echo "\n--- Live-edit slugs present ---\n";
echo ( in_array('novamira-adrianv2/elementor-assign-class-to-containers', $registered, true)
    ? "YES: novamira-adrianv2/elementor-assign-class-to-containers\n"
    : "MISSING: novamira-adrianv2/elementor-assign-class-to-containers\n" );

echo ( in_array('novamira-adrianv2/wpcode-check-setup', $registered, true)
    ? "YES: novamira-adrianv2/wpcode-check-setup\n"
    : "MISSING: novamira-adrianv2/wpcode-check-setup\n" );

// For the WPCode update ability, we can't directly observe registration in this stub
// (WPCode_Snippets::register() skips when class_exists('WPCode_Snippet') is false), so
// we instead assert the schema flag exists by reflecting the private method.
echo "\n--- WPCode update bypass_kses branch reachable ---\n";
$rc = new ReflectionClass(\Novamira\AdrianV2\Abilities\WpCode\WpCode_Snippets::class);
foreach (['execute_update', 'execute_update_via_kses_bypass'] as $m) {
    echo ( $rc->hasMethod($m) ? "YES: $m() exists\n" : "MISSING: $m()\n" );
}

// Probe the new check-setup ability: call execute([]) directly and assert
// the documented shape comes back. This catches drift between the
// output_schema declared in wp_register_ability() and the actual PHP return.
echo "\n--- WpCode_Check_Setup execute([]) shape ---\n";
$probe = \Novamira\AdrianV2\Abilities\WpCode\WpCode_Check_Setup::execute([]);
$expected = ['active', 'version', 'snippets', 'helpers_loadable', 'compiled_cache_layers_present', 'auto_demote_pending', 'permissions_ok', 'issues'];
$missing = array_diff($expected, array_keys($probe));
echo ( empty($missing)
    ? "YES: all 8 top-level keys present (active/version/snippets/helpers_loadable/compiled_cache_layers_present/auto_demote_pending/permissions_ok/issues)\n"
    : "MISSING keys: " . implode(', ', $missing) . "\n" );

if (isset($probe['snippets'])) {
    $sub = ['total_count', 'active_count', 'drafts_count', 'by_code_type'];
    $sub_missing = array_diff($sub, array_keys($probe['snippets']));
    echo ( empty($sub_missing)
        ? "YES: snippets sub-keys all present\n"
        : "MISSING snippets sub-keys: " . implode(', ', $sub_missing) . "\n" );
}

echo "\n--- WpCode_Check_Setup raw response (json) ---\n";
echo json_encode($probe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
