<?php
/**
 * J1 Browser-Verification runner.
 *
 * One-off helper that flips post 5373 status draft -> publish (so anonymous
 * browser visits render through Elementor), invokes the C1 MCP ability
 * `novamira-adrianv2/elementor-inject-calibrated-page` against the
 * colour/typography test payload, captures the JSON response, and prints
 * branded STEP-N lines to stdout for the J1 report-feed.
 *
 * Cleanup (`_j1-revert.php`) re-applies B1 sample + status draft + deletes
 * per-post CSS orphan.
 *
 * Self-deletes on completion.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../../../../');
require ABSPATH . 'wp-load.php';
wp_set_current_user(1);

/** @var \wpdb $wpdb */
global $wpdb;

// -----------------------------------------------------------------------------
// STEP 0: discoverability / preconditions
// -----------------------------------------------------------------------------
$ability_class = 'Novamira\\AdrianV2\\Abilities\\Elementor\\Elementor_Inject_Calibrated_Page';
if (!class_exists($ability_class)) {
    // Safely load via the bootstrap autoloader if not already present.
    $bootstrap = __DIR__ . '/../includes/abilities/elementor/bootstrap.php';
    if (is_file($bootstrap)) {
        require_once $bootstrap;
    }
}

echo json_encode([
    'STEP'        => '0.0.preconditions',
    'ability_class_loaded' => class_exists($ability_class),
    'elementor_active'      => (bool) (\Elementor\Plugin::$instance ?? null),
    'wp_check_post_lock_exists' => function_exists('wp_check_post_lock'),
    'wp_time'               => gmdate('c'),
]) . "\n";

// -----------------------------------------------------------------------------
// STEP 1: flip post 5373 status -> publish + record baseline meta
// -----------------------------------------------------------------------------
$p = get_post(5373);
if (!$p) {
    fwrite(STDERR, "FATAL: post 5373 not found\n");
    exit(1);
}

$status_before = $p->post_status;
$payload_path  = __DIR__ . '/_j1-payload.json';
$payload_raw   = file_get_contents($payload_path);
$payload_arr   = json_decode($payload_raw, true);

if (!is_array($payload_arr)) {
    fwrite(STDERR, "FATAL: _j1-payload.json is not a JSON array\n");
    exit(1);
}

$pre_data   = (string) get_post_meta(5373, '_elementor_data', true);
$pre_status = (string) get_post_meta(5373, '_elementor_edit_mode', true);

echo json_encode([
    'STEP'                 => '1.0.baseline',
    'post_5373_status'     => $status_before,
    '_elementor_edit_mode' => $pre_status,
    '_elementor_data_bytes_before' => strlen($pre_data),
    '_elementor_data_sha256_before' => $pre_data === '' ? null : hash('sha256', $pre_data),
    '_elementor_data_ids_before'    => array_values(array_unique(array_merge(
        ...array_map(
            static function (string $haystack): array {
                preg_match_all('/"id"\s*:\s*"([a-z0-9]+)"/i', $haystack, $m);
                return $m[1] ?? [];
            },
            [$pre_data]
        )
    ))),
    'colour_typography_payload_bytes' => strlen($payload_raw),
    'colour_typography_payload_ids'    => array_values(array_unique(array_merge(
        ...array_map(
            static function (string $haystack): array {
                preg_match_all('/"id"\s*:\s*"([a-z0-9]+)"/i', $haystack, $m);
                return $m[1] ?? [];
            },
            [$payload_raw]
        )
    ))),
]) . "\n";

if ($status_before === 'draft') {
    wp_update_post([
        'ID'          => 5373,
        'post_status' => 'publish',
    ]);
    clean_post_cache(5373);
    echo json_encode(['STEP' => '1.1.status_flipped', 'from' => 'draft', 'to' => 'publish']) . "\n";
}

// -----------------------------------------------------------------------------
// STEP 2: invoke C1 MCP ability with colour/typography payload
// -----------------------------------------------------------------------------
if (!class_exists($ability_class)) {
    fwrite(STDERR, "FATAL: ability class {$ability_class} still missing after bootstrap\n");
    exit(1);
}

/** @var \Novamira\AdrianV2\Abilities\Elementor\Elementor_Inject_Calibrated_Page $ability */
$ability  = new $ability_class();
$response = $ability->execute([
    'post_id'          => 5373,
    '_elementor_data'  => $payload_arr,
    'elementor_version' => '3.0.0',
    'wp_page_template' => 'elementor_canvas',
    'mode'             => 'overwrite',
]);

echo json_encode([
    'STEP'     => '2.0.c1_response',
    'response' => $response,
], JSON_UNESCAPED_SLASHES) . "\n";

if (!is_array($response) || empty($response['success'])) {
    fwrite(STDERR, "FATAL: C1 ability did not return success=true: " . json_encode($response) . "\n");
    exit(2);
}

// -----------------------------------------------------------------------------
// STEP 3: post-inject reality check
// -----------------------------------------------------------------------------
clean_post_cache(5373);
$post_data = (string) get_post_meta(5373, '_elementor_data', true);
$upload_dir = wp_get_upload_dir();
$css_glob    = glob($upload_dir['basedir'] . '/elementor/css/post-5373*.css') ?: [];

echo json_encode([
    'STEP' => '3.0.post_inject',
    '_elementor_data_bytes_after'    => strlen($post_data),
    '_elementor_data_sha256_after'   => hash('sha256', $post_data),
    '_elementor_data_ids_after'      => array_values(array_unique(array_merge(
        ...array_map(
            static function (string $haystack): array {
                preg_match_all('/"id"\s*:\s*"([a-z0-9]+)"/i', $haystack, $m);
                return $m[1] ?? [];
            },
            [$post_data]
        )
    ))),
    '_elementor_data_first_200_bytes_after' => substr($post_data, 0, 200),
    'wp_options_elementor_css_post_5373_rows'  => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('elementor_css_post-5373') . '%'
    )),
    'per_post_css_files_on_disk'     => array_map(
        static fn(string $f) => ['basename' => basename($f), 'bytes' => filesize($f), 'mtime' => date('c', filemtime($f))],
        $css_glob
    ),
    'response_kit_id'                 => $response['kit_id'] ?? null,
    'response_sections_count'         => $response['sections_count'] ?? null,
    'response_blocks_invalidated'     => $response['blocks_invalidated'] ?? null,
    'response_warnings'               => $response['warnings'] ?? null,
    'response_saved_at'               => $response['saved_at'] ?? null,
]) . "\n";

echo json_encode(['STEP' => '4.0.done', 'next' => "BEFORE/AFTER browser visits via browser-use agent; then run _j1-revert.php"]) . "\n";

// Self-delete.
@unlink(__FILE__);
