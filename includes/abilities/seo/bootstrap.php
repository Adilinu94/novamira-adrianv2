<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// seo abilities - class_exists guards + require_once + register
$novamira_adrianv2_seo_files = [
    __DIR__ . '/class-seo.php',
];

foreach ( $novamira_adrianv2_seo_files as $novamira_adrianv2_seo_file ) {
    if ( file_exists( $novamira_adrianv2_seo_file ) ) {
        require_once $novamira_adrianv2_seo_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Seo\Seo' ) && method_exists( 'Novamira\AdrianV2\Abilities\Seo\Seo', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Seo\Seo::register();
        }
