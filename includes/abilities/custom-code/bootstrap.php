<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// custom-code abilities - class_exists guards + require_once + register
$novamira_adrianv2_custom_code_files = [
    __DIR__ . '/class-custom-code.php',
];

foreach ( $novamira_adrianv2_custom_code_files as $novamira_adrianv2_custom_code_file ) {
    if ( file_exists( $novamira_adrianv2_custom_code_file ) ) {
        require_once $novamira_adrianv2_custom_code_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\CustomCode\Custom_Code' ) && method_exists( 'Novamira\AdrianV2\Abilities\CustomCode\Custom_Code', 'register' ) ) {
            Novamira\AdrianV2\Abilities\CustomCode\Custom_Code::register();
        }
