<?php
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

/**
 * Bootstrap for V4 Management abilities.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */

add_action('wp_abilities_api_init', static function () {
    require_once __DIR__ . '/class-sync-schema.php';
    \Novamira\AdrianV2\Abilities\V4Management\Sync_Schema::register();

    require_once __DIR__ . '/class-rollback-build.php';
    \Novamira\AdrianV2\Abilities\V4Management\Rollback_Build::register();
}, 20);
