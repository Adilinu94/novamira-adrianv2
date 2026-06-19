<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Diagnostics — centralized per-group registration log.
 *
 * Each Phase-4/5 group calls Diagnostics::record() in its try/catch wrapper.
 * The admin can read the last failure per group via Diagnostics::summary()
 * (exposed later through a wp-admin status page in Phase 6).
 *
 * @since 1.0.0
 */
final class Diagnostics {

    /** @var array<string, array{group: string, class: string, message: string, file: string, line: int, time: int}> */
    private static array $errors = [];

    /**
     * Records a Throwable caught during an ability-group register() call.
     */
    public static function record(string $group, string $class, \Throwable $e): void {
        self::$errors[$group] = [
            'group'   => $group,
            'class'   => $class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => (int) $e->getLine(),
            'time'    => time(),
        ];

        // Always log to the PHP error log so it shows up in wp-content/debug.log.
        if (function_exists('error_log')) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf(
                '[Novamira AdrianV2] group=%s class=%s: %s in %s:%d',
                $group,
                $class,
                $e->getMessage(),
                $e->getFile(),
                (int) $e->getLine()
            ));
        }
    }

    /**
     * All recorded errors, keyed by ability group.
     *
     * @return array<string, array{group: string, class: string, message: string, file: string, line: int, time: int}>
     */
    public static function errors(): array {
        return self::$errors;
    }

    /**
     * Whether at least one group has recorded an error.
     */
    public static function has_errors(): bool {
        return [] !== self::$errors;
    }

    /**
     * Clears the in-memory error log (useful for tests and re-runs).
     */
    public static function clear(): void {
        self::$errors = [];
    }
}
