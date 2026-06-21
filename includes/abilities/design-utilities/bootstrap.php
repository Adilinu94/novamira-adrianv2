<?php
/**
 * Bootstrap for design-utilities ability category.
 *
 * Loads all design repair classes and registers them via their static register() methods.
 */
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\DesignUtilities;

if (!defined('ABSPATH')) {
    exit();
}

$novamira_adrianv2_design_utilities_files = [
    __DIR__ . '/class-design-repair.php',
];

foreach ($novamira_adrianv2_design_utilities_files as $novamira_adrianv2_design_utilities_file) {
    if (file_exists($novamira_adrianv2_design_utilities_file)) {
        require_once $novamira_adrianv2_design_utilities_file;
    }
}

// Auto-register all abilities in this sub-domain.
if (class_exists('Novamira\\AdrianV2\\Abilities\\DesignUtilities\\Design_Repair') && method_exists('Novamira\\AdrianV2\\Abilities\\DesignUtilities\\Design_Repair', 'register')) {
    \Novamira\AdrianV2\Abilities\DesignUtilities\Design_Repair::register();
}
