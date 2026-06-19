<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// variables abilities - class_exists guards + require_once + register
$novamira_adrianv2_variables_files = [
    __DIR__ . '/class-batch-create-variables.php',
];

foreach ( $novamira_adrianv2_variables_files as $novamira_adrianv2_variables_file ) {
    if ( file_exists( $novamira_adrianv2_variables_file ) ) {
        require_once $novamira_adrianv2_variables_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Variables\Batch_Create_Variables' ) && method_exists( 'Novamira\AdrianV2\Abilities\Variables\Batch_Create_Variables', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Variables\Batch_Create_Variables::register();
        }
