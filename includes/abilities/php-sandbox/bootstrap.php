<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

// php-sandbox abilities - class_exists guards + require_once + register
$novamira_adrianv2_php_sandbox_files = [
    __DIR__ . '/class-php-snippets.php',
];

foreach ( $novamira_adrianv2_php_sandbox_files as $novamira_adrianv2_php_sandbox_file ) {
    if ( file_exists( $novamira_adrianv2_php_sandbox_file ) ) {
        require_once $novamira_adrianv2_php_sandbox_file;
    }
}

// Auto-register all abilities in this sub-domain
        if ( class_exists( 'Novamira\AdrianV2\Abilities\PhpSandbox\PHP_Snippets' ) && method_exists( 'Novamira\AdrianV2\Abilities\PhpSandbox\PHP_Snippets', 'register' ) ) {
            Novamira\AdrianV2\Abilities\PhpSandbox\PHP_Snippets::register();
        }
