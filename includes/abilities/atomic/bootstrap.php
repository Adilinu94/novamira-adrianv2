<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// atomic abilities - class_exists guards + require_once + register
$novamira_adrianv2_atomic_files = [
    __DIR__ . '/class-atomic-layouts.php',
    __DIR__ . '/class-atomic-widgets.php',
];

foreach ( $novamira_adrianv2_atomic_files as $novamira_adrianv2_atomic_file ) {
    if ( file_exists( $novamira_adrianv2_atomic_file ) ) {
        require_once $novamira_adrianv2_atomic_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Atomic\Atomic_Layouts' ) && method_exists( 'Novamira\AdrianV2\Abilities\Atomic\Atomic_Layouts', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Atomic\Atomic_Layouts::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Atomic\Atomic_Widgets' ) && method_exists( 'Novamira\AdrianV2\Abilities\Atomic\Atomic_Widgets', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Atomic\Atomic_Widgets::register();
        }
