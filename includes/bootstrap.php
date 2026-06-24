<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

error_log( '[V2-BOOTSTRAP] fired at ' . __FILE__ . ' | wp_abilities_api_init fired=' . ( function_exists( 'did_action' ) ? did_action( 'wp_abilities_api_init' ) : 'n/a' ) );

use Novamira\AdrianV2\Helpers\Diagnostics;

// Top-Bootstrap: Per-Group wp_abilities_api_init mit Try/Catch (loest Single-Closure-Bug).
// Fehler in einer Sub-Domain blockieren nicht die anderen.

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/a11y/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'a11y', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/atomic/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'atomic', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/audit/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'audit', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/custom-code/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'custom-code', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/elementor/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'elementor', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/global-classes/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'global-classes', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/media/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'media', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/php-sandbox/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'php-sandbox', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/seo/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'seo', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/utilities/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'utilities', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/variables/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'variables', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/wpcode/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'wpcode', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/design-audit/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'design-audit', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/design-utilities/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'design-utilities', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/elementor-templates/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'elementor-templates', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/elementor-site-tools/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'elementor-site-tools', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/elementor-pro/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'elementor-pro', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/v4-management/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'v4-management', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/clonerlabs/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'clonerlabs', '?', $e );
    }
}, 20 );

add_action( 'wp_abilities_api_init', static function () {
    try {
        require_once __DIR__ . '/abilities/woocommerce/bootstrap.php';
    } catch ( \Throwable $e ) {            \Novamira\AdrianV2\Helpers\Diagnostics::record( 'woocommerce', '?', $e );
    }
}, 20 );
