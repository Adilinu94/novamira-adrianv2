<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// audit abilities - class_exists guards + require_once + register
$novamira_adrianv2_audit_files = [
    __DIR__ . '/class-class-audit.php',
    __DIR__ . '/class-layout-audit.php',
    __DIR__ . '/class-page-audit.php',
    __DIR__ . '/class-responsive-audit.php',
    __DIR__ . '/class-variable-audit.php',
    __DIR__ . '/class-visual-qa.php',
];

foreach ( $novamira_adrianv2_audit_files as $novamira_adrianv2_audit_file ) {
    if ( file_exists( $novamira_adrianv2_audit_file ) ) {
        require_once $novamira_adrianv2_audit_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Audit\Class_Audit' ) && method_exists( 'Novamira\AdrianV2\Abilities\Audit\Class_Audit', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Audit\Class_Audit::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Audit\Layout_Audit' ) && method_exists( 'Novamira\AdrianV2\Abilities\Audit\Layout_Audit', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Audit\Layout_Audit::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Audit\Page_Audit' ) && method_exists( 'Novamira\AdrianV2\Abilities\Audit\Page_Audit', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Audit\Page_Audit::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Audit\Responsive_Audit' ) && method_exists( 'Novamira\AdrianV2\Abilities\Audit\Responsive_Audit', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Audit\Responsive_Audit::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Audit\Variable_Audit' ) && method_exists( 'Novamira\AdrianV2\Abilities\Audit\Variable_Audit', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Audit\Variable_Audit::register();
        }
        if ( class_exists( 'Novamira\AdrianV2\Abilities\Audit\Visual_Qa' ) && method_exists( 'Novamira\AdrianV2\Abilities\Audit\Visual_Qa', 'register' ) ) {
            Novamira\AdrianV2\Abilities\Audit\Visual_Qa::register();
        }
