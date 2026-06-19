<?php
/**
 * Test: Setup_V4_Foundation
 *
 * @package Novamira\AdrianV2\Tests
 */

declare(strict_types=1);

use Novamira\AdrianV2\Abilities\Elementor\Setup_V4_Foundation;
use PHPUnit\Framework\TestCase;

/**
 * Initialer Smoke-Test — prueft Klassen- und Methoden-Existenz.
 */
#[CoversClass(Setup_V4_Foundation::class)]
class SetupV4FoundationTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(
            class_exists(Setup_V4_Foundation::class),
            'Setup_V4_Foundation class should be autoloaded'
        );
    }

    public function test_register_method_exists(): void
    {
        $this->assertTrue(
            method_exists(Setup_V4_Foundation::class, 'register'),
            'Setup_V4_Foundation must have a register() method'
        );
    }
}
