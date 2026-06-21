<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ability_Registry — Shared trait for ability classes.
 *
 * Every ability class needs to track which abilities it registered for
 * introspection and debugging. This trait provides the get_ability_names()
 * method that every ability class needs.
 *
 * Usage in an ability class:
 *
 *   namespace Novamira\AdrianV2\Abilities\Atomic;
 *   use Novamira\AdrianV2\Helpers\Ability_Registry;
 *
 *   final class Atomic_Widgets {
 *       use Ability_Registry;
 *       private static array $ability_names = [];   // MUST be declared per class
 *       public static function register(): void {
 *           wp_register_ability('novamira-adrianv2/add-atomic-heading', [...]);
 *           self::$ability_names[] = 'novamira-adrianv2/add-atomic-heading';
 *       }
 *   }
 *
 * NOTE: Each using class MUST declare its own `private static array
 * $ability_names = [];` — PHP traits share static properties across all
 * classes, so the property cannot live in the trait itself.
 *
 * @since 1.0.0
 */
trait Ability_Registry {

    /**
     * @return string[]
     */
    public static function get_ability_names(): array {
        return self::$ability_names;
    }
}
