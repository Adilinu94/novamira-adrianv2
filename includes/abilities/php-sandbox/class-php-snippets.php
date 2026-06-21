<?php
declare(strict_types=1);

/**
 * V4 PHP Snippet Abilities — ported from EMCP class-php-snippet-abilities.php.
 *
 * Provides 6 MCP abilities for AI-assisted PHP snippet authoring:
 *   - validate-php-snippet  (read-only — static analysis)
 *   - create-php-snippet    (write — stores as draft, validated)
 *   - update-php-snippet    (write — re-validates, re-compiles if active)
 *   - get-php-snippet       (read-only — full record + code + validation)
 *   - list-php-snippets     (read-only — summaries, filterable by status)
 *   - delete-php-snippet    (write — removes CPT + sandbox file)
 *
 * IMPORTANT: This class delegates to the local Novamira Sandbox Validator & Store.
 * It does not duplicate CPT registration, sandbox management, or token-scanning logic.
 * If the local Novamira classes are not available, all 6 abilities silently skip
 * registration with a descriptive error returned at execution time.
 *
 * Permission model (mirrors EMCP):
 *   - Read tools:  manage_options
 *   - Write tools: manage_options + unfiltered_html (same caps that permit
 *     editing plugin code)
 *
 * @package Extra
 * @since   1.5.0
 */

namespace Novamira\AdrianV2\Abilities\PhpSandbox;

use Novamira\AdrianV2\Helpers\V4_Props;
use Novamira\AdrianV2\Helpers\V4_Styles;
use Novamira\AdrianV2\Helpers\V4_Color_Contrast;
use Novamira\AdrianV2\Helpers\V4_Content_Extractor;
use Novamira\AdrianV2\Helpers\V4_Seo_Meta;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Store;
use Novamira\AdrianV2\Helpers\PHP_Sandbox_Validator;
use Novamira\AdrianV2\Helpers\Ability_Registry;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and implements the PHP snippet abilities.
 *
 * @since 1.5.0
 */
class PHP_Snippets {
    use Ability_Registry;

    /** @var string[] */
    private static array $ability_names = [];

    /**
     * Checks whether EMCP's Store and Validator classes are available.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists('Novamira\\AdrianV2\\Helpers\\PHP_Sandbox_Validator')
            && class_exists('Novamira\\AdrianV2\\Helpers\\PHP_Sandbox_Store');
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Registers all 6 PHP snippet abilities. Silently skips if EMCP is not active.
     *
     * @since 1.5.0
     */
    public static function register(): void {
        if (!self::is_available()) {
            return;
        }

        self::register_validate();
        self::register_create();
        self::register_update();
        self::register_get();
        self::register_list();
        self::register_delete();
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
    // -------------------------------------------------------------------------

    /**
     * Read permission: manage_options (same as novamira_permission_callback).
     *
     * @return bool
     */
    public static function check_read_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Edit permission: manage_options + unfiltered_html.
     *
     * The same capability set that already permits editing plugin code in
     * WordPress core. This is the real safety boundary — validating at the
     * capability gate, not just the code scanner.
     *
     * @return bool
     */
    public static function check_edit_permission(): bool {
        return current_user_can('manage_options') && current_user_can('unfiltered_html');
    }

    // -------------------------------------------------------------------------
    // Schema helpers
    // -------------------------------------------------------------------------

    /**
     * JSON Schema fragment for a validation report (shared by several tools).
     *
     * @return array
     */
    private static function validation_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'valid'       => ['type' => 'boolean'],
                'safe'        => ['type' => 'boolean'],
                'parse_error' => ['type' => 'string'],
                'findings'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'severity' => ['type' => 'string'],
                            'rule'     => ['type' => 'string'],
                            'message'  => ['type' => 'string'],
                            'line'     => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Common snippet input properties (title/code/context/hook/priority).
     *
     * @return array
     */
    private static function snippet_input_props(): array {
        return [
            'title'    => [
                'type'        => 'string',
                'description' => __('A label for the snippet.', 'novamira-adrianv2'),
            ],
            'code'     => [
                'type'        => 'string',
                'description' => __('The PHP code (no <?php tags needed). Runs inside an isolated function. Use return or echo for shortcode output.', 'novamira-adrianv2'),
            ],
            'context'  => [
                'type'        => 'string',
                'enum'        => ['shortcode', 'hook', 'both'],
                'description' => __('How the snippet runs: "shortcode" via [novamira_snippet id="N"], "hook" on a WordPress action, or "both". Default: shortcode.', 'novamira-adrianv2'),
            ],
            'hook'     => [
                'type'        => 'string',
                'description' => __('WordPress action to attach to when context is hook/both (e.g. wp_footer, init).', 'novamira-adrianv2'),
            ],
            'priority' => [
                'type'        => 'integer',
                'description' => __('Hook priority (default 10).', 'novamira-adrianv2'),
            ],
        ];
    }

    /**
     * Output schema for a snippet record.
     *
     * @return array
     */
    private static function snippet_output_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'snippet_id' => ['type' => 'integer'],
                'title'      => ['type' => 'string'],
                'status'     => ['type' => 'string'],
                'context'    => ['type' => 'string'],
                'hook'       => ['type' => 'string'],
                'priority'   => ['type' => 'integer'],
                'shortcode'  => ['type' => 'string'],
                'code'       => ['type' => 'string'],
                'last_error' => ['type' => 'string'],
                'validation' => self::validation_schema(),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // validate-php-snippet
    // -------------------------------------------------------------------------

    private static function register_validate(): void {
        wp_register_ability('novamira-adrianv2/validate-php-snippet', [
            'label'               => __('Validate PHP Snippet', 'novamira-adrianv2'),
            'description'         => __('Statically checks PHP snippet code WITHOUT storing or running it: confirms it parses, then scans for dangerous constructs (code execution, shell, file writes, network, obfuscation, destructive SQL). Returns a report of critical (blocking) and warning findings. Use this to iterate before create-php-snippet.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_validate'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'code' => [
                        'type'        => 'string',
                        'description' => __('The PHP code to validate.', 'novamira-adrianv2'),
                    ],
                ],
                'required'   => ['code'],
            ],
            'output_schema'       => self::validation_schema(),
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
        self::$ability_names[] = 'novamira-adrianv2/validate-php-snippet';
    }

    /**
     * @param array $input Input.
     * @return array|\WP_Error
     */
    public static function execute_validate($input) {
        $code = isset($input['code']) ? (string) $input['code'] : '';
        return PHP_Sandbox_Validator::validate($code);
    }

    // -------------------------------------------------------------------------
    // create-php-snippet
    // -------------------------------------------------------------------------

    private static function register_create(): void {
        wp_register_ability('novamira-adrianv2/create-php-snippet', [
            'label'               => __('Create PHP Snippet (draft)', 'novamira-adrianv2'),
            'description'         => __('Creates a PHP snippet as an INACTIVE DRAFT. It does NOT run: a site administrator must review and activate it in Novamira Tools → Sandbox before it executes. The code is validated first and rejected if it trips a critical security finding (the findings are returned so you can fix it).', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_create'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => self::snippet_input_props(),
                'required'   => ['code'],
            ],
            'output_schema'       => self::snippet_output_schema(),
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
        self::$ability_names[] = 'novamira-adrianv2/create-php-snippet';
    }

    /**
     * @param array $input Input.
     * @return array|\WP_Error
     */
    public static function execute_create($input) {
        $result = PHP_Sandbox_Store::create_draft(is_array($input) ? $input : []);
        return self::normalize_write_result($result, 'created');
    }

    // -------------------------------------------------------------------------
    // update-php-snippet
    // -------------------------------------------------------------------------

    private static function register_update(): void {
        wp_register_ability('novamira-adrianv2/update-php-snippet', [
            'label'               => __('Update PHP Snippet', 'novamira-adrianv2'),
            'description'         => __('Updates a snippet\'s code or settings. Re-validates and rejects critical findings. If the snippet is currently active it is re-compiled (or demoted to draft if it no longer passes). Activation still requires an admin.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_update'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => array_merge(
                    ['snippet_id' => ['type' => 'integer', 'description' => __('The snippet ID.', 'novamira-adrianv2')]],
                    self::snippet_input_props()
                ),
                'required'   => ['snippet_id'],
            ],
            'output_schema'       => self::snippet_output_schema(),
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
        self::$ability_names[] = 'novamira-adrianv2/update-php-snippet';
    }

    /**
     * @param array $input Input.
     * @return array|\WP_Error
     */
    public static function execute_update($input) {
        $id = isset($input['snippet_id']) ? absint($input['snippet_id']) : 0;
        if (!$id) {
            return new \WP_Error('missing_id', __('snippet_id is required.', 'novamira-adrianv2'));
        }
        $result = PHP_Sandbox_Store::update($id, is_array($input) ? $input : []);
        return self::normalize_write_result($result, 'updated');
    }

    // -------------------------------------------------------------------------
    // get-php-snippet
    // -------------------------------------------------------------------------

    private static function register_get(): void {
        wp_register_ability('novamira-adrianv2/get-php-snippet', [
            'label'               => __('Get PHP Snippet', 'novamira-adrianv2'),
            'description'         => __('Returns a snippet: its code, status (draft/active), run context, shortcode, and the latest validation report.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_get'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['snippet_id' => ['type' => 'integer', 'description' => __('The snippet ID.', 'novamira-adrianv2')]],
                'required'   => ['snippet_id'],
            ],
            'output_schema'       => self::snippet_output_schema(),
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
        self::$ability_names[] = 'novamira-adrianv2/get-php-snippet';
    }

    /**
     * @param array $input Input.
     * @return array|\WP_Error
     */
    public static function execute_get($input) {
        $id = isset($input['snippet_id']) ? absint($input['snippet_id']) : 0;
        if (!$id) {
            return new \WP_Error('missing_id', __('snippet_id is required.', 'novamira-adrianv2'));
        }
        return PHP_Sandbox_Store::get($id);
    }

    // -------------------------------------------------------------------------
    // list-php-snippets
    // -------------------------------------------------------------------------

    private static function register_list(): void {
        wp_register_ability('novamira-adrianv2/list-php-snippets', [
            'label'               => __('List PHP Snippets', 'novamira-adrianv2'),
            'description'         => __('Lists PHP snippets with their status (draft/active), run context, and shortcode.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_list'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => 'string',
                        'enum'        => ['active', 'draft', 'any'],
                        'description' => __('Filter by status. Default: any.', 'novamira-adrianv2'),
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'count'    => ['type' => 'integer'],
                    'snippets' => ['type' => 'array', 'items' => ['type' => 'object']],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
        self::$ability_names[] = 'novamira-adrianv2/list-php-snippets';
    }

    /**
     * @param array $input Input.
     * @return array|\WP_Error
     */
    public static function execute_list($input) {
        $status = isset($input['status']) && in_array($input['status'], ['active', 'draft', 'any'], true)
            ? (string) $input['status']
            : 'any';
        $snippets = PHP_Sandbox_Store::list_snippets($status);
        return [
            'count'    => count($snippets),
            'snippets' => $snippets,
        ];
    }

    // -------------------------------------------------------------------------
    // delete-php-snippet
    // -------------------------------------------------------------------------

    private static function register_delete(): void {
        wp_register_ability('novamira-adrianv2/delete-php-snippet', [
            'label'               => __('Delete PHP Snippet', 'novamira-adrianv2'),
            'description'         => __('Permanently deletes a PHP snippet and removes its sandbox file.', 'novamira-adrianv2'),
            'category'            => 'novamira-adrianv2',
            'execute_callback'    => [__CLASS__, 'execute_delete'],
            'permission_callback' => 'novamira_permission_callback',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['snippet_id' => ['type' => 'integer', 'description' => __('The snippet ID.', 'novamira-adrianv2')]],
                'required'   => ['snippet_id'],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success'    => ['type' => 'boolean'],
                    'snippet_id' => ['type' => 'integer'],
                ],
            ],
            'meta'                => [
                'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ]);
        self::$ability_names[] = 'novamira-adrianv2/delete-php-snippet';
    }

    /**
     * @param array $input Input.
     * @return array|\WP_Error
     */
    public static function execute_delete($input) {
        $id = isset($input['snippet_id']) ? absint($input['snippet_id']) : 0;
        if (!$id) {
            return new \WP_Error('missing_id', __('snippet_id is required.', 'novamira-adrianv2'));
        }
        return PHP_Sandbox_Store::delete($id);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Turns a validation-rejection WP_Error into a structured response carrying
     * the findings (so the agent can fix the code), and adds a reminder that the
     * draft still needs admin activation. Other errors pass through.
     *
     * @param array|\WP_Error $result Store result.
     * @param string          $_verb  'created' | 'updated' (unused, kept for EMCP signature parity).
     * @return array|\WP_Error
     */
    private static function normalize_write_result($result, string $_verb = '') {
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            if ('invalid_php' === $code || 'unsafe_php' === $code) {
                $data = $result->get_error_data();
                return [
                    'success'    => false,
                    'reason'     => $result->get_error_message(),
                    'validation' => is_array($data) && isset($data['validation']) ? $data['validation'] : [],
                ];
            }
            return $result;
        }

        $result['success'] = true;
        $result['note']    = __('Saved as an INACTIVE draft. A site administrator must activate it in Novamira Tools → Sandbox before it runs.', 'novamira-adrianv2');
        return $result;
    }
}
