<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if (!defined('ABSPATH')) {
    exit();
}

$files = [__DIR__ . '/class-template-manager.php'];
foreach ($files as $f) {
    if (file_exists($f)) require_once $f;
}

if (class_exists('Novamira\\AdrianV2\\Abilities\\ElementorTemplates\\Template_Manager')
    && method_exists('Novamira\\AdrianV2\\Abilities\\ElementorTemplates\\Template_Manager', 'register')) {
    \Novamira\AdrianV2\Abilities\ElementorTemplates\Template_Manager::register();
}
