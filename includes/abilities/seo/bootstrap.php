<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

$novamira_adrianv2_seo_files = [
    __DIR__ . '/class-seo.php',
    __DIR__ . '/class-yoast-check-setup.php',
    __DIR__ . '/class-rankmath-check-setup.php',
    __DIR__ . '/class-aioseo-check-setup.php',
    __DIR__ . '/class-seo-mutations.php',
];

foreach ( $novamira_adrianv2_seo_files as $novamira_adrianv2_seo_file ) {
    if ( file_exists( $novamira_adrianv2_seo_file ) ) {
        require_once $novamira_adrianv2_seo_file;
    }
}

if ( class_exists( 'Novamira\AdrianV2\Abilities\Seo\Seo' ) && method_exists( 'Novamira\AdrianV2\Abilities\Seo\Seo', 'register' ) ) {
    Novamira\AdrianV2\Abilities\Seo\Seo::register();
}

$novamira_adrianv2_seo_ability_classes = [
    'Novamira\AdrianV2\Abilities\Seo\Yoast_Check_Setup',
    'Novamira\AdrianV2\Abilities\Seo\Rankmath_Check_Setup',
    'Novamira\AdrianV2\Abilities\Seo\Aioseo_Check_Setup',
];
foreach ( $novamira_adrianv2_seo_ability_classes as $novamira_adrianv2_seo_class ) {
    if ( class_exists( $novamira_adrianv2_seo_class ) && method_exists( $novamira_adrianv2_seo_class, 'register' ) ) {
        $novamira_adrianv2_seo_class::register();
    }
}

if ( class_exists( 'Novamira\AdrianV2\Abilities\Seo\Seo_Mutations' ) ) {
    Novamira\AdrianV2\Abilities\Seo\Seo_Mutations::register_rankmath();
    Novamira\AdrianV2\Abilities\Seo\Seo_Mutations::register_aioseo();
}
