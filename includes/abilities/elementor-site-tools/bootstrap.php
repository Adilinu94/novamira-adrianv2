<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorSiteTools;

if (!defined('ABSPATH')) {
    exit();
}

$files = [__DIR__ . '/class-site-tools.php'];
foreach ($files as $f) {
    if (file_exists($f)) require_once $f;
}

if (class_exists('Novamira\\AdrianV2\\Abilities\\ElementorSiteTools\\Site_Tools')
    && method_exists('Novamira\\AdrianV2\\Abilities\\ElementorSiteTools\\Site_Tools', 'register')) {
    \Novamira\AdrianV2\Abilities\ElementorSiteTools\Site_Tools::register();
}
