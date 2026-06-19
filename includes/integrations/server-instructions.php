<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Server Instructions — hängt V2-spezifische Regeln an den
 * `novamira_discover_abilities_instructions`-Filter an.
 *
 * Wird vom MCP-Adapter beim `discover-abilities`-Call ausgegeben
 * und erscheint als `novamira_instructions`-Block im Output.
 * Nur sichtbar wenn das V2-Plugin aktiv ist.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */

namespace Novamira\AdrianV2\Integrations;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the V2 server-instructions filter.
 *
 * @since 1.1.0
 */
final class Server_Instructions {

    /**
     * Bootstrap: register the filter on `novamira_discover_abilities_instructions`.
     *
     * Called from the main plugin bootstrap during `plugins_loaded`.
     */
    public static function register(): void {
        add_filter(
            'novamira_discover_abilities_instructions',
            [self::class, 'append_v2_instructions'],
            10,
            1
        );
    }

    /**
     * Appends the V2-specific instruction block to the discover-abilities output.
     *
     * @param string $instructions Existing instructions from other plugins.
     * @return string Modified instructions with V2 block appended.
     */
    public static function append_v2_instructions(string $instructions): string {
        // Respect the disable-flag (stored as WP option, default: enabled).
        $enabled = get_option('novamira_adrianv2_server_instructions_enabled', '1');
        if ('1' !== $enabled) {
            return $instructions;
        }

        $v2_block = self::build_instruction_block();

        return $instructions . "\n\n" . $v2_block;
    }

    /**
     * Build the V2 instruction block as a markdown string.
     *
     * @return string
     */
    private static function build_instruction_block(): string {
        $version = defined('NOVAMIRA_ADRIANV2_VERSION') ? NOVAMIRA_ADRIANV2_VERSION : '1.1.0';
        $resolver_available = class_exists('\\Novamira\\AdrianV2\\Helpers\\Elementor_Version_Resolver');
        $site_v4 = $resolver_available
            ? \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4()
            : false;

        $lines = [
            '## novamira-adrianv2 Plugin Conventions (v' . $version . ')',
            '',
            '- **Plugin-Slug:** `novamira-adrianv2` (Adrian V2 — the second Adrian-built plugin; NOT Elementor V2)',
            '- **V3/V4 Trennung:** Each ability carries `category` and is matched against page-level `elementor_version`.',
            '  Use `adrianv2-v4-*` abilities only on pages detected as V4 via `detect-elementor-version`.',
            '- **MCP-Signature:** `{ ability_name: string, parameters: object }` — never `ability` or `abilityName`.',
            '- **Build-Call:** `elementor-set-content` with `content: [ARRAY!]` (never `adrians-batch-build-page` for Framer).',
            '- **V4-Invariants:** See `adrianv2-v4-invariants` skill (5 invariants every V4 write must respect).',
            '- **Gotchas:** See `novamira-adrianv2/docs/GOTCHAS.md` (XSS in page_js, MIME-spoofing, Path-Traversal, Image-Src url-key).',
            '',
            '### Active Categories',
            '',
            '| Category | elementor_version |',
            '|---|---|',
            '| `adrianv2-global-classes` | `v4` |',
            '| `adrianv2-v4-management` | `v4` |',
            '| `adrianv2-variables` | `v4` |',
            '| `adrianv2-atomic` | `v4` |',
            '| `adrianv2-elementor` | `mixed` |',
            '| `adrianv2-batch` | `mixed` |',
            '| `adrianv2-media` | `mixed` |',
            '| `adrianv2-audit` | `mixed` |',
            '| `adrianv2-design-audit` | `mixed` |',
            '| `adrianv2-design-utilities` | `mixed` |',
            '| `adrianv2-templates` | `mixed` |',
            '| `adrianv2-site-tools` | `mixed` |',
            '| `adrianv2-pro` | `mixed` |',
            '| `adrianv2-seo` | `mixed` |',
            '| `adrianv2-a11y` | `mixed` |',
            '| `adrianv2-php-sandbox` | `mixed` |',
            '| `adrianv2-custom-code` | `mixed` |',
            '| `adrianv2-wpcode` | `mixed` |',
            '| `adrianv2-live-edit` | `mixed` |',
            '| `adrianv2-utilities` | `mixed` |',
            '',
            '### Quick Reference',
            '',
            '```',
            'Site is V4: ' . ($site_v4 ? 'YES' : 'NO'),
            'Elementor Version Resolver: ' . ($resolver_available ? 'loaded' : 'NOT LOADED'),
            '```',
        ];

        return implode("\n", $lines);
    }
}
