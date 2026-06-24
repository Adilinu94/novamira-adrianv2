<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// global-classes abilities - class_exists guards + require_once + register
$novamira_adrianv2_global_classes_files = [
    __DIR__ . '/class-global-classes.php',
    __DIR__ . '/class-create-global-class.php',
];

foreach ( $novamira_adrianv2_global_classes_files as $novamira_adrianv2_global_classes_file ) {
    if ( file_exists( $novamira_adrianv2_global_classes_file ) ) {
        require_once $novamira_adrianv2_global_classes_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\GlobalClasses\Global_Classes' ) && method_exists( 'Novamira\AdrianV2\Abilities\GlobalClasses\Global_Classes', 'register' ) ) {
            Novamira\AdrianV2\Abilities\GlobalClasses\Global_Classes::register();
        }

        if ( class_exists( 'Novamira\AdrianV2\Abilities\GlobalClasses\Create_Global_Class' ) && method_exists( 'Novamira\AdrianV2\Abilities\GlobalClasses\Create_Global_Class', 'register' ) ) {
            Novamira\AdrianV2\Abilities\GlobalClasses\Create_Global_Class::register();
        }
