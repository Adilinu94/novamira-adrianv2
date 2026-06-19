<?php
declare(strict_types=1);

/**
 * Ability: novamira-adrianv2/wpcode-check-setup
 *
 * Read-only probe of the WPCode environment on this WordPress install.
 * Reports:
 *   - WPCode plugin presence + version
 *   - Snippet counts total / active / drafts, plus a by-code-type snapshot
 *     (preferring the `wpcode_type` taxonomy, falling back to
 *     `wpcode_code_type` post-meta on legacy installs)
 *   - Whether the adrianv2 WPCode_Kses_Bypass helper is autoloadable
 *     (controls the bypass_kses pathway of update-wpcode-snippet)
 *   - Presence of the three compiled-asset cache layers:
 *     * `wpcode_compiled_snippets` option
 *     * `_wpcode_compiled_code` per-snippet post-meta (count)
 *     * `_wpcode_compiled_snippet` per-snippet post-meta (count)
 *     * on-disk `wp-content/uploads/wpcode/cache/` directory
 *   - Number of snippets carrying `_wpcode_auto_demote=1` (pending re-activation)
 *   - Current-user permission sanity for `wpcode_activate_snippets` + `edit_posts`
 *   - A list of detected configuration issues (WPCode inactive,
 *     missing helper, stale cache, permission gap, etc.)
 *
 * Read-only — never writes to the DB, never mutates options/meta,
 * never purges the cache, never hits the filesystem destructively.
 *
 * @package novamira-adrianv2
 * @since   1.1.0
 */

namespace Novamira\AdrianV2\Abilities\WpCode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpCode_Check_Setup {

	/**
	 * Register the single `novamira-adrianv2/wpcode-check-setup` ability
	 * against the WordPress Abilities API. Idempotent — safe to call
	 * multiple times in a request.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/wpcode-check-setup',
			array(
				'label'       => __( 'Check WPCode Setup', 'novamira-adrianv2' ),
				'description' => __(
					'Reports the WPCode environment: plugin version, snippet counts (total/active/drafts/by code type), helper-class reachability (WPCode_Kses_Bypass::invalidate_compiled_cache), presence of the three compiled-asset cache layers (option, post-meta, on-disk), count of snippets pending auto-demote reactivation, and the current user\'s permissions. `issues` is a list of detected configuration problems. Call this BEFORE any update-wpcode-snippet that needs bypass_kses=true so you know whether the kses bypass pathway will succeed and which cache layers you might still need to invalidate.',
					'novamira-adrianv2'
				),
				'category' => 'adrianv2-wpcode',
				// CRITICAL: properties MUST be an empty ARRAY (NOT stdClass []).
				// With additionalProperties:false, WP core\'s input validator
				// runs isset() on $schema[\'properties\'][$key] for every extra
				// key; a stdClass FATAL crashes the dispatch ("Cannot use
				// object of type stdClass as array"). The array form gives
				// the intended clean WP_Error rejection. See novamira-pro
				// aioseo-check-setup.php for the same workaround.
				'input_schema' => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'active'        => array( 'type' => 'boolean' ),
						'version'       => array( 'type' => array( 'string', 'null' ) ),
						'snippets'      => array(
							'type'       => 'object',
							'properties' => array(
								'total_count'   => array( 'type' => 'integer' ),
								'active_count'  => array( 'type' => 'integer' ),
								'drafts_count'  => array( 'type' => 'integer' ),
								'by_code_type'  => array(
									'type'                 => 'object',
									'additionalProperties' => array( 'type' => 'integer' ),
								),
							),
						),
						'helpers_loadable' => array( 'type' => 'boolean' ),
						'compiled_cache_layers_present' => array(
							'type'       => 'object',
							'properties' => array(
								'wpcode_compiled_snippets_option'   => array( 'type' => 'boolean' ),
								'wpcode_compiled_code_meta_count'   => array( 'type' => 'integer' ),
								'wpcode_compiled_snippet_meta_count' => array( 'type' => 'integer' ),
								'cache_dir_present'                 => array( 'type' => 'boolean' ),
							),
						),
						'auto_demote_pending' => array( 'type' => 'integer' ),
						'permissions_ok'      => array( 'type' => 'boolean' ),
						'issues'              => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required' => array(
						'active',
						'version',
						'snippets',
						'helpers_loadable',
						'compiled_cache_layers_present',
						'auto_demote_pending',
						'permissions_ok',
						'issues',
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'check_read_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						/* translators: keep this paragraph as a single string so MCP clients surface it verbatim. */
						'instructions' => __(
							'Call this BEFORE update-wpcode-snippet, set-wpcode-snippet-status, delete-wpcode-snippet, duplicate-wpcode-snippet, or any write with bypass_kses=true. Key signals: active (WPCode must be active for every wpcode-* ability); helpers_loadable (the kses-bypass helper must be reachable for the bypass_kses pathway — otherwise update-wpcode-snippet falls back to WPCode_Snippet::save() WITH kses, and the recent snippet write may have been kses-stripped silently); compiled_cache_layers_present (counts >0 mean future render may be served from cache — invalidate via WPCode_Kses_Bypass::invalidate_compiled_cache if you changed a snippet and need fresh output); permissions_ok (current user must have wpcode_activate_snippets AND edit_posts for write-side abilities). auto_demote_pending > 0 means some snippets were auto-demoted on save and need re-activation. The `issues` array is the shorthand for "something is broken; here is what".',
							'novamira-adrianv2'
						),
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Permission gate. Read-only abilities must still be permission-checked —
	 * we return `current_user_can( 'read' )` because every authenticated
	 * subscriber-and-above holds the `read` cap on a default WP install.
	 * This matches the read-only gating model used by novamira-pro/*-check-setup.
	 *
	 * @return bool
	 */
	public static function check_read_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Execute the check-setup probe.
	 *
	 * Public per the abilities API. Accepts the input array verbatim but
	 * ignores every key (the documented `properties => []` schema).
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function execute( array $input = array() ): array {
		$active = self::detect_active();
		$version = self::detect_version();

		$snippets = self::snippets_breakdown( $active );

		$helpers_loadable = (
			class_exists( 'Novamira\\AdrianV2\\Helpers\\WPCode_Kses_Bypass' )
			&& method_exists( 'Novamira\\AdrianV2\\Helpers\\WPCode_Kses_Bypass', 'invalidate_compiled_cache' )
		);

		$compiled_cache = self::compiled_cache_layers();

		$auto_demote_pending = self::auto_demote_pending_count();

		$permissions_ok = self::check_user_permissions();

		$issues = self::compose_issues(
			$active,
			$helpers_loadable,
			$compiled_cache,
			$auto_demote_pending,
			$permissions_ok
		);

		return array(
			'active'                           => $active,
			'version'                          => $version,
			'snippets'                         => $snippets,
			'helpers_loadable'                 => $helpers_loadable,
			'compiled_cache_layers_present'    => $compiled_cache,
			'auto_demote_pending'              => $auto_demote_pending,
			'permissions_ok'                   => $permissions_ok,
			'issues'                           => $issues,
		);
	}

	/**
	 * Detect whether the WPCode plugin is loaded. We do NOT trust
	 * `is_plugin_active()` because the plugin can be active in
	 * mu-plugins / network-wide / drop-in without that returning true.
	 * Instead we sniff for the class or the WPCODE_VERSION constant.
	 *
	 * @return bool
	 */
	private static function detect_active(): bool {
		if ( defined( 'WPCODE_VERSION' ) ) {
			return true;
		}
		if ( class_exists( 'WPCode' ) || class_exists( 'WPCode\\Plugin' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve the plugin version. Returns null when no version is
	 * available — never throw, since the check is supposed to be silent
	 * on misconfigured installs.
	 *
	 * @return string|null
	 */
	private static function detect_version(): ?string {
		if ( defined( 'WPCODE_VERSION' ) ) {
			return (string) constant( 'WPCODE_VERSION' );
		}
		foreach ( array( 'WPCode', 'WPCode\\Plugin' ) as $candidate ) {
			if ( ! class_exists( $candidate ) ) {
				continue;
			}
			try {
				$reflect = new \ReflectionClass( $candidate );
				if ( $reflect->hasConstant( 'VERSION' ) ) {
					return (string) $reflect->getConstant( 'VERSION' );
				}
			} catch ( \Throwable $e ) {
				// Reflect on a live plugin class can intermittently fail
				// during PHPUnit's tear-down. Treat as "version unknown".
				return null;
			}
		}
		return null;
	}

	/**
	 * @param bool $active
	 * @return array{total_count:int, active_count:int, drafts_count:int, by_code_type:object}
	 */
	private static function snippets_breakdown( bool $active ): array {
		if ( ! $active || ! post_type_exists( 'wpcode_snippet' ) ) {
			return array(
				'total_count'  => 0,
				'active_count' => 0,
				'drafts_count' => 0,
				'by_code_type' => (object) array(),
			);
		}

		$total = 0;
		$active_count = 0;
		$drafts_count = 0;
		$counts = wp_count_posts( 'wpcode_snippet' );
		if ( is_object( $counts ) ) {
			foreach ( (array) $counts as $status => $n ) {
				if ( ! is_numeric( $n ) ) {
					continue;
				}
				$total += (int) $n;
				if ( 'publish' === $status || 'active' === $status ) {
					// WPCode 2.x stores 'active'; legacy 1.x stores 'publish'.
					$active_count += (int) $n;
				}
				if ( 'draft' === $status ) {
					$drafts_count += (int) $n;
				}
			}
		}

		$by_type = array();

		// Prefer the v2.x taxonomy.
		if ( taxonomy_exists( 'wpcode_type' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'wpcode_type', 'hide_empty' => false ) );
			if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( $term instanceof \WP_Term ) {
						$by_type[ (string) $term->slug ] = (int) $term->count;
					}
				}
			}
		} else {
			// Fallback: SQL group-by on the legacy post-meta.
			global $wpdb;
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_value AS code_type, COUNT(*) AS n
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = %s AND pm.meta_key = %s
					 GROUP BY pm.meta_value",
					'wpcode_snippet',
					'wpcode_code_type'
				),
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$by_type[ (string) $row['code_type'] ] = (int) $row['n'];
				}
			}
		}

		// Cast to stdClass so JSON encoder emits `{}` when empty and
		// `{key: n, ...}` when non-empty. PHP array would emit `[]` for
		// empty, which violates the `type: object` constraint.
		return array(
			'total_count'  => $total,
			'active_count' => $active_count,
			'drafts_count' => $drafts_count,
			'by_code_type' => (object) $by_type,
		);
	}

	/**
	 * Check the three compiled-asset cache layers that the
	 * WPCode_Kses_Bypass helper purges. Pure read — does not call
	 * invalidate_compiled_cache (that would be destructive).
	 *
	 * @return array{
	 *     wpcode_compiled_snippets_option: bool,
	 *     wpcode_compiled_code_meta_count: int,
	 *     wpcode_compiled_snippet_meta_count: int,
	 *     cache_dir_present: bool
	 * }
	 */
	private static function compiled_cache_layers(): array {
		global $wpdb;

		$option_value = get_option( 'wpcode_compiled_snippets', null );
		$option_present = ( null !== $option_value && false !== $option_value && '' !== $option_value );

		$compiled_code_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wpcode_compiled_code'
			)
		);

		$compiled_snippet_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wpcode_compiled_snippet'
			)
		);

		$upload_dir     = wp_upload_dir();
		$cache_dir_path = trim( (string) ( $upload_dir['basedir'] ?? '' ), '/\\' ) . '/wpcode/cache';
		$cache_dir_present = is_dir( $cache_dir_path );

		return array(
			'wpcode_compiled_snippets_option'    => (bool) $option_present,
			'wpcode_compiled_code_meta_count'    => $compiled_code_count,
			'wpcode_compiled_snippet_meta_count' => $compiled_snippet_count,
			'cache_dir_present'                  => $cache_dir_present,
		);
	}

	/**
	 * Count snippets carrying `_wpcode_auto_demote = 1` post-meta so the
	 * MCP client knows whether `set-wpcode-snippet-status` will need to
	 * re-activate something.
	 *
	 * @return int
	 */
	private static function auto_demote_pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value = %s",
				'wpcode_snippet',
				'_wpcode_auto_demote',
				'1'
			)
		);
	}

	/**
	 * Sanity-check the current user's capabilities. Update/set-status/duplicate
	 * all need `wpcode_activate_snippets` (Pro-only) AND `edit_posts`.
	 *
	 * @return bool
	 */
	private static function check_user_permissions(): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		// wpcode_activate_snippets is only registered when WPCode Pro is loaded.
		// Treat "unknown cap" as "no" — current_user_can() returns false for
		// capabilities that are not registered against any role.
		return current_user_can( 'wpcode_activate_snippets' );
	}

	/**
	 * Aggregate the user-facing issues list. Always returns a list; empty
	 * when the install is clean.
	 *
	 * @param bool $active
	 * @param bool $helpers_loadable
	 * @param array{
	 *     wpcode_compiled_snippets_option: bool,
	 *     wpcode_compiled_code_meta_count: int,
	 *     wpcode_compiled_snippet_meta_count: int,
	 *     cache_dir_present: bool
	 * } $compiled_cache
	 * @param int $auto_demote_pending
	 * @param bool $permissions_ok
	 * @return list<string>
	 */
	private static function compose_issues(
		bool $active,
		bool $helpers_loadable,
		array $compiled_cache,
		int $auto_demote_pending,
		bool $permissions_ok
	): array {
		$issues = array();

		if ( ! $active ) {
			$issues[] = 'WPCode is not active on this site. Every adrianv2/wpcode-* ability will return WP_Error.';
			return $issues;
		}

		if ( ! $helpers_loadable ) {
			$issues[] = 'Novamira\\AdrianV2\\Helpers\\WPCode_Kses_Bypass is not autoloadable. update-wpcode-snippet with bypass_kses=true will return wpcode_helpers_not_loaded; only the older WPCode_Snippet::save() path is available, which means a recent write may have been kses-stripped silently.';
		}

		if ( ! $permissions_ok ) {
			$issues[] = 'Current user is missing wpcode_activate_snippets and/or edit_posts. Write-side abilities (update, set-status, duplicate, delete) will return permission_cancelled.';
		}

		if ( $compiled_cache['wpcode_compiled_snippets_option'] ) {
			$issues[] = "wpcode_compiled_snippets option is populated; render may be served from cache. After writing any snippet, call WPCode_Kses_Bypass::invalidate_compiled_cache() to force a rebuild.";
		}

		if ( $compiled_cache['cache_dir_present']
			&& ( $compiled_cache['wpcode_compiled_code_meta_count'] > 0
			|| $compiled_cache['wpcode_compiled_snippet_meta_count'] > 0 )
		) {
			$issues[] = sprintf(
				'On-disk cache dir present AND %d per-snippet compiled-code meta entries. If the next render shows stale frontend output, this is the cause.',
				(int) ( $compiled_cache['wpcode_compiled_code_meta_count']
				+ $compiled_cache['wpcode_compiled_snippet_meta_count'] )
			);
		}

		if ( $auto_demote_pending > 0 ) {
			$issues[] = sprintf(
				'%d snippet(s) carry _wpcode_auto_demote=1 and need re-activation. Use set-wpcode-snippet-status to bring them back online after the underlying problem is fixed.',
				$auto_demote_pending
			);
		}

		return $issues;
	}
}
