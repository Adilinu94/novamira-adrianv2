<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// a11y abilities - class_exists guards + require_once + register
$novamira_adrianv2_a11y_files = [
    __DIR__ . '/class-a11y.php',
];

foreach ( $novamira_adrianv2_a11y_files as $novamira_adrianv2_a11y_file ) {
    if ( file_exists( $novamira_adrianv2_a11y_file ) ) {
        require_once $novamira_adrianv2_a11y_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\A11y\A11y' ) && method_exists( 'Novamira\AdrianV2\Abilities\A11y\A11y', 'register' ) ) {
            Novamira\AdrianV2\Abilities\A11y\A11y::register();
        }
