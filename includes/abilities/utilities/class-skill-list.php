<?php

declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Utilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Skill_List — lists all installed skills in the novamira-adrianv2 plugin.
 *
 * Scans the includes/skills/ directory, reads each SKILL.md frontmatter,
 * and returns a structured list of skill IDs, names, and descriptions.
 * Useful for MCP clients to discover available skills without reading files.
 *
 * Registered as: novamira-adrianv2/skill-list
 *
 * @since 1.1.0
 */
class Skill_List
{
    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/skill-list', [
            'label'       => 'List Installed Skills',
            'description' => 'Returns all skills installed in the novamira-adrianv2 plugin. Each skill entry includes its ID, name, description, and SKILL.md path.',
            'category'    => 'adrianv2-utilities',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'filter' => [
                        'type'        => 'string',
                        'description' => 'Optional substring to filter skill IDs or names (case-insensitive).',
                    ],
                    'include_content' => [
                        'type'        => 'boolean',
                        'description' => 'If true, include the full SKILL.md content for each skill. Default: false.',
                    ],
                ],
                'required' => [],
            ],
            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'count'   => ['type' => 'integer'],
                    'skills'  => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'          => ['type' => 'string'],
                                'name'        => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'path'        => ['type' => 'string'],
                                'content'     => ['type' => 'string', 'description' => 'Full SKILL.md content (only when include_content: true).'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => 'novamira_permission_callback',
            'meta' => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public static function execute($input = null): array
    {
        $filter          = strtolower((string) ($input['filter']          ?? ''));
        $include_content = (bool) ($input['include_content'] ?? false);

        $skills_dir = plugin_dir_path(dirname(dirname(__DIR__))) . 'includes/skills/';

        if (!is_dir($skills_dir)) {
            return [
                'success' => false,
                'count'   => 0,
                'skills'  => [],
                'error'   => 'Skills directory not found: ' . $skills_dir,
            ];
        }

        $skills = [];

        $entries = scandir($skills_dir);
        if ($entries === false) {
            return ['success' => false, 'count' => 0, 'skills' => []];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'installer.php') {
                continue;
            }

            $skill_dir  = $skills_dir . $entry;
            $skill_md   = $skill_dir . '/SKILL.md';

            if (!is_dir($skill_dir) || !file_exists($skill_md)) {
                continue;
            }

            $parsed = self::parse_skill_md($skill_md);
            $skill  = [
                'id'          => $entry,
                'name'        => $parsed['name']        ?? $entry,
                'description' => $parsed['description'] ?? '',
                'path'        => 'includes/skills/' . $entry . '/SKILL.md',
            ];

            if ($include_content) {
                $skill['content'] = file_get_contents($skill_md) ?: '';
            }

            // Apply filter if provided
            if ($filter !== '') {
                $haystack = strtolower($skill['id'] . ' ' . $skill['name'] . ' ' . $skill['description']);
                if (strpos($haystack, $filter) === false) {
                    continue;
                }
            }

            $skills[] = $skill;
        }

        // Sort alphabetically by ID
        usort($skills, static fn($a, $b) => strcmp($a['id'], $b['id']));

        return [
            'success' => true,
            'count'   => count($skills),
            'skills'  => $skills,
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Parse YAML frontmatter from a SKILL.md file.
     *
     * Only parses top-level scalar keys (name, description) from the
     * --- ... --- block. No YAML library required.
     *
     * @return array{name?: string, description?: string}
     */
    private static function parse_skill_md(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        // Must start with ---
        if (!str_starts_with($content, '---')) {
            return [];
        }

        $end = strpos($content, '---', 3);
        if ($end === false) {
            return [];
        }

        $frontmatter = substr($content, 3, $end - 3);
        $result      = [];

        foreach (explode("\n", $frontmatter) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $colon));
            $value = trim(substr($line, $colon + 1));

            // Strip surrounding quotes
            if (
                strlen($value) >= 2 &&
                (($value[0] === '"' && $value[-1] === '"') ||
                 ($value[0] === "'" && $value[-1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
