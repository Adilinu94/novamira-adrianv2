<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorPro;

if (!defined('ABSPATH')) {
    exit();
}

$files = [__DIR__ . '/class-pro-features.php'];
foreach ($files as $f) {
    if (file_exists($f)) require_once $f;
}

if (class_exists('Novamira\\AdrianV2\\Abilities\\ElementorPro\\Pro_Features')
    && method_exists('Novamira\\AdrianV2\\Abilities\\ElementorPro\\Pro_Features', 'register')) {
    \Novamira\AdrianV2\Abilities\ElementorPro\Pro_Features::register();
}
