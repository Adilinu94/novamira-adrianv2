<?php
/**
 * Bootstrap for design-audit ability category.
 *
 * Loads all design audit classes and registers them via their static register() methods.
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\DesignAudit;

if (!defined('ABSPATH')) {
    exit();
}

$novamira_adrianv2_design_audit_files = [
    __DIR__ . '/class-design-evaluator.php',
];

foreach ($novamira_adrianv2_design_audit_files as $novamira_adrianv2_design_audit_file) {
    if (file_exists($novamira_adrianv2_design_audit_file)) {
        require_once $novamira_adrianv2_design_audit_file;
    }
}

// Auto-register all abilities in this sub-domain.
if (class_exists('Novamira\\AdrianV2\\Abilities\\DesignAudit\\Design_Evaluator') && method_exists('Novamira\\AdrianV2\\Abilities\\DesignAudit\\Design_Evaluator', 'register')) {
    \Novamira\AdrianV2\Abilities\DesignAudit\Design_Evaluator::register();
}
