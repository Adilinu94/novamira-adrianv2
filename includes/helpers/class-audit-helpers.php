<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Audit_Helpers — shared text/scoring utilities for SEO and A11Y audits.
 *
 * Was a trait in novamira-adrians-extra (consumed via `use Audit_Helpers;`).
 * Resolved into a static class in V2 so ability classes can call
 * Audit_Helpers::check() etc. without trait-import boilerplate.
 *
 * score() and summary() are NOT included — they differ between SEO
 * (no inconclusive) and A11Y (excludes inconclusive from denominator).
 *
 * @since 1.0.0
 */
final class Audit_Helpers {

    /**
     * @return array{id: string, label: string, status: string, detail: string, recommendation: string}
     */
    public static function check(string $id, string $label, string $status, string $detail, string $rec): array {
        return [
            'id'             => $id,
            'label'          => $label,
            'status'         => $status,
            'detail'         => $detail,
            'recommendation' => $rec,
        ];
    }

    public static function mb_lower(string $s): string {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    public static function mb_len(string $s): int {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    public static function truncate(string $s, int $max): string {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if (self::mb_len($s) <= $max) {
            return $s;
        }
        $cut = function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
        $sp = mb_strrpos($cut, ' ');
        if (false !== $sp && $sp > 0) {
            $cut = mb_substr($cut, 0, $sp, 'UTF-8');
        }
        return rtrim($cut, " ,.;:");
    }

    /**
     * @return string[]
     */
    public static function tokenize(string $text): array {
        $text   = self::mb_lower($text);
        $text   = (string) preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', trim($text));
        return array_values(array_filter((array) $tokens, static function ($t) {
            return '' !== $t;
        }));
    }

    public static function is_stopword(string $w): bool {
        static $stop = null;
        if (null === $stop) {
            $stop = array_flip([
                'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any',
                'can', 'her', 'was', 'one', 'our', 'out', 'his', 'has', 'had',
                'how', 'its', 'who', 'get', 'use', 'your', 'with', 'this', 'that',
                'from', 'they', 'will', 'have', 'what', 'when', 'were', 'them',
                'then', 'than', 'into', 'more', 'some', 'such', 'only', 'just',
                'also', 'over', 'most', 'been', 'here', 'their', 'there', 'about',
                'would', 'these', 'which', 'while', 'where', 'every', 'other',
                'could', 'should', 'after', 'before', 'because',
            ]);
        }
        return isset($stop[$w]);
    }
}
