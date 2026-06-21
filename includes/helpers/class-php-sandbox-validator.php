<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * PHP_Sandbox_Validator — static analysis for admin/AI-submitted PHP snippets.
 *
 * Two layers:
 *   1. PARSE — token_get_all(..., TOKEN_PARSE) so the snippet must be syntactically valid.
 *   2. SECURITY SCAN — token walk that flags dangerous constructs.
 *
 * CRITICAL findings block creation/activation; WARNING findings surface to the human reviewer.
 *
 * IMPORTANT: this is a GUARDRAIL, not a guarantee. PHP is expressive enough to
 * hide intent (variable functions, decoded strings, reflection), so static
 * analysis cannot prove arbitrary code is safe. The real safety boundary is the
 * capability gate (manage_options + unfiltered_html) plus the human approval
 * step: an AI can create a DRAFT and run the validator, but only an admin can
 * activate a snippet so it actually executes.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates PHP snippet source.
 *
 * @since 1.0.0
 */
final class PHP_Sandbox_Validator {

    /**
     * Function names that block a snippet outright (code exec, shell, dynamic
     * calls, file writes/deletes, network egress, obfuscation decoders, runtime
     * config). Maps lowercase function name => reviewer-facing reason.
     *
     * @var array<string,string>
     */
    private static $critical_funcs = [
        // Arbitrary code execution.
        'eval'                    => 'Executes arbitrary code (eval).',
        'assert'                  => 'Can execute arbitrary code (assert with a string).',
        'create_function'         => 'Creates and runs code from a string.',
        // Shell / process execution.
        'exec'                    => 'Runs an operating-system command.',
        'system'                  => 'Runs an operating-system command.',
        'shell_exec'              => 'Runs an operating-system command.',
        'passthru'                => 'Runs an operating-system command.',
        'proc_open'               => 'Spawns an operating-system process.',
        'popen'                   => 'Spawns an operating-system process.',
        'pcntl_exec'              => 'Executes a program in the current process.',
        'expect_popen'            => 'Spawns an operating-system process.',
        // Indirect / dynamic invocation.
        'call_user_func'          => 'Calls a function chosen at runtime (bypasses static checks).',
        'call_user_func_array'    => 'Calls a function chosen at runtime (bypasses static checks).',
        'forward_static_call'     => 'Calls a method chosen at runtime (bypasses static checks).',
        'forward_static_call_array' => 'Calls a method chosen at runtime (bypasses static checks).',
        'func_get_args'           => 'Unexpected in a snippet; often used to smuggle dynamic calls.',
        // File writes / deletes.
        'file_put_contents'       => 'Writes to the filesystem.',
        'fwrite'                  => 'Writes to the filesystem.',
        'fputs'                   => 'Writes to the filesystem.',
        'fputcsv'                 => 'Writes to the filesystem.',
        'ftruncate'               => 'Truncates a file.',
        'unlink'                  => 'Deletes a file.',
        'rmdir'                   => 'Removes a directory.',
        'rename'                  => 'Renames/moves a file.',
        'copy'                    => 'Copies a file.',
        'mkdir'                   => 'Creates a directory.',
        'chmod'                   => 'Changes file permissions.',
        'chown'                   => 'Changes file ownership.',
        'chgrp'                   => 'Changes file group.',
        'symlink'                 => 'Creates a symbolic link.',
        'link'                    => 'Creates a hard link.',
        'move_uploaded_file'      => 'Moves an uploaded file into the filesystem.',
        // Network egress.
        'curl_init'               => 'Makes an outbound network request.',
        'curl_exec'               => 'Makes an outbound network request.',
        'curl_setopt'             => 'Configures an outbound network request.',
        'fsockopen'               => 'Opens a network socket.',
        'pfsockopen'              => 'Opens a network socket.',
        'stream_socket_client'    => 'Opens a network socket.',
        'socket_create'           => 'Opens a network socket.',
        'socket_connect'          => 'Connects a network socket.',
        // Obfuscation decoders.
        'base64_decode'           => 'Decodes hidden data - a common malware obfuscation.',
        'gzinflate'               => 'Decompresses hidden data - a common malware obfuscation.',
        'gzuncompress'            => 'Decompresses hidden data - a common malware obfuscation.',
        'gzdecode'                => 'Decompresses hidden data - a common malware obfuscation.',
        'str_rot13'               => 'Decodes hidden data - a common malware obfuscation.',
        'convert_uudecode'        => 'Decodes hidden data - a common malware obfuscation.',
        'hex2bin'                 => 'Decodes hidden data - a common malware obfuscation.',
        // Runtime / environment manipulation.
        'dl'                      => 'Loads a PHP extension at runtime.',
        'putenv'                  => 'Changes environment variables.',
        'ini_set'                 => 'Changes PHP runtime configuration.',
        'ini_alter'               => 'Changes PHP runtime configuration.',
        'apache_setenv'           => 'Changes the web-server environment.',
        'virtual'                 => 'Performs an Apache sub-request.',
        'set_error_handler'       => 'Hijacks error handling.',
        'register_shutdown_function' => 'Schedules deferred code execution.',
        'register_tick_function'  => 'Schedules repeated code execution.',
        'extract'                 => 'Creates variables from arbitrary keys (variable injection).',
    ];

    /**
     * Function names that are flagged for the human reviewer but do not block.
     *
     * @var array<string,string>
     */
    private static $warn_funcs = [
        'fopen'             => 'Opens a file (could read or write).',
        'file_get_contents' => 'Reads a file or a remote URL.',
        'readfile'          => 'Reads and outputs a file.',
        'fread'             => 'Reads from a file handle.',
        'fgets'             => 'Reads from a file handle.',
        'scandir'           => 'Lists a directory.',
        'glob'              => 'Lists files by pattern.',
        'opendir'           => 'Opens a directory.',
        'define'            => 'Defines a constant (could override core/config constants).',
        'header'            => 'Sends an HTTP header (could redirect).',
        'setcookie'         => 'Sets a cookie.',
        'error_reporting'   => 'Changes error reporting.',
        'update_option'     => 'Writes a site option.',
        'delete_option'     => 'Deletes a site option.',
        'add_option'        => 'Adds a site option.',
        'wp_mail'           => 'Sends email.',
        'wp_delete_post'    => 'Deletes a post.',
        'wp_delete_user'    => 'Deletes a user.',
        'wp_insert_user'    => 'Creates a user.',
        'wp_update_user'    => 'Updates a user (could escalate privileges).',
        'switch_theme'      => 'Switches the active theme.',
        'activate_plugin'   => 'Activates a plugin.',
        'deactivate_plugins' => 'Deactivates plugins.',
        'do_action'         => 'Fires arbitrary hooks.',
    ];

    /**
     * Superglobals whose use is worth noting (request-derived input).
     *
     * @var string[]
     */
    private static $warn_superglobals = ['$_GET', '$_POST', '$_REQUEST', '$_FILES', '$_COOKIE', '$_SERVER', '$_ENV', '$GLOBALS'];

    /**
     * Validates a PHP snippet.
     *
     * @param string $code Raw snippet source (with or without PHP tags).
     * @return array{valid:bool,safe:bool,parse_error:string,findings:array<int,array{severity:string,rule:string,message:string,line:int}>}
     */
    public static function validate(string $code): array {
        $result = [
            'valid'       => true,
            'safe'        => true,
            'parse_error' => '',
            'findings'    => [],
        ];

        $clean = self::strip_tags($code);

        if ('' === trim($clean)) {
            $result['valid'] = false;
            $result['parse_error'] = __('The snippet is empty.', 'novamira-adrianv2');
            return $result;
        }

        // Reject an embedded closing tag: it would let code break out of the
        // wrapper into raw HTML/inline output we cannot reason about.
        if (false !== strpos($clean, '?>')) {
            $result['safe'] = false;
            $result['findings'][] = self::finding('critical', 'close_tag', __('A PHP closing tag ( ?> ) is not allowed in a snippet.', 'novamira-adrianv2'), 0);
        }

        // Wrap so top-level statements (return, etc.) are valid in a function context.
        $wrapped = '<?php function __novamira_snippet_validate() { ' . $clean . "\n}";

        $tokens = null;
        try {
            $tokens = token_get_all($wrapped, TOKEN_PARSE);
        } catch (\ParseError $e) {
            $result['valid'] = false;
            $result['parse_error'] = $e->getMessage();
            return $result;
        } catch (\Throwable $e) {
            $result['valid'] = false;
            $result['parse_error'] = $e->getMessage();
            return $result;
        }

        self::scan_tokens($tokens, $result);

        foreach ($result['findings'] as $f) {
            if ('critical' === $f['severity']) {
                $result['safe'] = false;
                break;
            }
        }

        return $result;
    }

    /**
     * Strips a single leading PHP open tag (and a trailing close tag) so callers
     * may submit code with or without tags.
     *
     * @param string $code Raw code.
     * @return string
     */
    public static function strip_tags(string $code): string {
        $code = trim($code);
        $code = preg_replace('/^<\?php\b/i', '', $code, 1);
        if (null === $code) {
            return '';
        }
        $code = preg_replace('/^<\?=?/', '', $code, 1);
        return null === $code ? '' : trim($code);
    }

    /**
     * Walks the token stream and records findings.
     *
     * @param array $tokens token_get_all() output.
     * @param array $result Result array (by reference).
     */
    private static function scan_tokens(array $tokens, array &$result): void {
        $sig = [];
        foreach ($tokens as $tok) {
            if (is_array($tok)) {
                if (T_WHITESPACE === $tok[0] || T_COMMENT === $tok[0] || T_DOC_COMMENT === $tok[0]) {
                    continue;
                }
                $sig[] = ['id' => $tok[0], 'text' => $tok[1], 'line' => (int) $tok[2]];
            } else {
                $sig[] = ['id' => null, 'text' => $tok, 'line' => 0];
            }
        }

        $count = count($sig);
        for ($i = 0; $i < $count; $i++) {
            $t    = $sig[$i];
            $id   = $t['id'];
            $text = $t['text'];
            $line = $t['line'];
            $prev = $i > 0 ? $sig[$i - 1] : null;
            $next = $i + 1 < $count ? $sig[$i + 1] : null;

            // Backtick shell execution: `...`
            if (null === $id && '`' === $text) {
                $result['findings'][] = self::finding('critical', 'backtick', __('Shell execution via the backtick operator.', 'novamira-adrianv2'), $line);
                continue;
            }

            // Dynamic include/require.
            if (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                $result['findings'][] = self::finding('critical', 'include', __('Loads and runs another PHP file (include/require).', 'novamira-adrianv2'), $line);
                continue;
            }

            if (defined('T_EVAL') && T_EVAL === $id) {
                $result['findings'][] = self::finding('critical', 'eval', __('Executes arbitrary code (eval).', 'novamira-adrianv2'), $line);
                continue;
            }

            if (T_VARIABLE === $id && $next && null === $next['id'] && '(' === $next['text']) {
                $result['findings'][] = self::finding('critical', 'variable_function', __('Calls a function named by a variable (bypasses static checks).', 'novamira-adrianv2'), $line);
                continue;
            }

            if (null === $id && '$' === $text && $next && T_VARIABLE === $next['id']) {
                $result['findings'][] = self::finding('warning', 'variable_variable', __('Uses a variable variable ($$x).', 'novamira-adrianv2'), $line);
                continue;
            }

            if (null === $id && '@' === $text) {
                $result['findings'][] = self::finding('warning', 'suppress', __('Suppresses errors with @ (can hide failures).', 'novamira-adrianv2'), $line);
                continue;
            }

            if (T_VARIABLE === $id && in_array($text, self::$warn_superglobals, true)) {
                $result['findings'][] = self::finding(
                    'warning',
                    'superglobal',
                    sprintf(
                        /* translators: %s: superglobal name */
                        __('Reads request/server input (%s).', 'novamira-adrianv2'),
                        $text
                    ),
                    $line
                );
                continue;
            }

            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                if (preg_match('/\b(DROP|TRUNCATE|ALTER)\s+(TABLE|DATABASE)\b/i', $text) || preg_match('/\bDELETE\s+FROM\b/i', $text)) {
                    $result['findings'][] = self::finding('critical', 'destructive_sql', __('Contains destructive SQL (DROP/TRUNCATE/ALTER/DELETE).', 'novamira-adrianv2'), $line);
                }
                continue;
            }

            if (T_STRING === $id && $next && null === $next['id'] && '(' === $next['text']) {
                $prev_id   = $prev ? $prev['id'] : null;
                $prev_text = $prev ? $prev['text'] : '';
                $is_member = (T_OBJECT_OPERATOR === $prev_id) || (T_DOUBLE_COLON === $prev_id)
                    || (defined('T_NULLSAFE_OBJECT_OPERATOR') && T_NULLSAFE_OBJECT_OPERATOR === $prev_id);
                $is_def    = (T_FUNCTION === $prev_id) || (T_NEW === $prev_id);
                if ($is_member || $is_def) {
                    continue;
                }
                $name = strtolower($text);
                if (isset(self::$critical_funcs[$name])) {
                    $result['findings'][] = self::finding('critical', 'function:' . $name, self::$critical_funcs[$name], $line);
                } elseif (isset(self::$warn_funcs[$name])) {
                    $result['findings'][] = self::finding('warning', 'function:' . $name, self::$warn_funcs[$name], $line);
                }
                continue;
            }

            if (in_array($id, [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE], true)) {
                if ($next && T_STRING === $next['id'] && '__novamira_snippet_validate' === $next['text']) {
                    continue;
                }
                $result['findings'][] = self::finding('warning', 'definition', __('Defines a function/class (re-runs may redeclare and fatal).', 'novamira-adrianv2'), $line);
                continue;
            }
        }
    }

    /**
     * Builds a finding row.
     *
     * @param string $severity 'critical' | 'warning'.
     * @param string $rule     Machine rule id.
     * @param string $message  Human message.
     * @param int    $line     1-based line in the snippet (0 = whole snippet).
     * @return array
     */
    private static function finding(string $severity, string $rule, string $message, int $line): array {
        return [
            'severity' => $severity,
            'rule'     => $rule,
            'message'  => $message,
            'line'     => max(0, $line),
        ];
    }
}
