<?php
/**
 * PHPUnit Bootstrap — novamira-adrianv2
 *
 * Lädt WordPress-Testumgebung oder fällt auf Mock-Modus zurück.
 */

// Wenn WordPress-Tests verfügbar sind
$wp_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
if (file_exists($wp_tests_dir . '/includes/bootstrap.php')) {
    require_once $wp_tests_dir . '/includes/bootstrap.php';
} else {
    // Mock-Modus: WordPress-Funktionen simulieren
    require_once __DIR__ . '/mock-functions.php';
}

// Plugin Bootstrap laden
require_once dirname(__DIR__) . '/novamira-adrianv2.php';
