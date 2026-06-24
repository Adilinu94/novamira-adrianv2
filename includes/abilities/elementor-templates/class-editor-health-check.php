<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ElementorTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit_Editor_Health — runtime checks for Elementor editor availability.
 *
 * Used after a kit import to confirm the editor is reachable and known
 * bugs are absent. All checks are read-only (no writes).
 *
 * @since 1.7.0
 */
class Kit_Editor_Health {

	/**
	 * Run all checks against a specific post ID.
	 *
	 * @param int $post_id  Any post with Elementor data.
	 * @return array {
	 *   rest_api:    bool,   // Elementor REST panel/menu returns 200
	 *   ajax:        bool,   // admin-ajax.php returns 400 (reachable, bad params expected)
	 *   checklist:   bool,   // checklist.js null-deref bug absent
	 *   hfe_css:     bool,   // HFE CSS paths resolve correctly
	 * }
	 */
	public static function check_editor( int $post_id ): array {
		return [
			'rest_api'  => self::rest_api_ok( $post_id ),
			'ajax'      => self::ajax_ok(),
			'checklist' => ! Kit_Self_Heal::checklist_bug_exists(),
			'hfe_css'   => self::hfe_css_ok(),
		];
	}

	/**
	 * Check that the Elementor editor REST endpoint is reachable.
	 * Expects HTTP 200. Requires a logged-in cookie, so this is a
	 * best-effort check (returns false on auth-gated installations).
	 */
	public static function rest_api_ok( int $post_id ): bool {
		$url      = rest_url( "elementor/v1/editor/{$post_id}/panel/menu" );
		$response = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => false ] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		// 200 = ok, 401/403 = reachable but auth needed (not a PHP fatal).
		return in_array( (int) $code, [ 200, 401, 403 ], true );
	}

	/**
	 * Check that admin-ajax.php is reachable.
	 * An empty elementor_ajax action returns HTTP 400, which means the
	 * endpoint is up and responding — not a PHP fatal or 500.
	 */
	public static function ajax_ok(): bool {
		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[ 'body' => [ 'action' => 'elementor_ajax' ], 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		// 400 = Elementor received and rejected the empty request (expected).
		// 200 = also acceptable (some builds return 200 with error body).
		return in_array( $code, [ 200, 400 ], true );
	}

	/**
	 * Check that HFE CSS paths are resolvable.
	 * Broken when elementor-pro dir is absent, pro-elements dir is present,
	 * AND Kit_Self_Heal's filter is not yet registered.
	 */
	public static function hfe_css_ok(): bool {
		$ep_missing = ! is_dir( WP_PLUGIN_DIR . '/elementor-pro' );
		$pe_present = is_dir( WP_PLUGIN_DIR . '/pro-elements' );

		if ( ! $ep_missing || ! $pe_present ) {
			return true; // No conflict scenario.
		}

		// Broken only if the rewrite filter is not active.
		return has_filter( 'plugins_url', [ Kit_Self_Heal::class, 'rewrite_pro_css_url' ] ) !== false;
	}

	// -------------------------------------------------------------------------
	// MCP Ability registration
	// -------------------------------------------------------------------------

	/**
	 * Register the check-editor-health MCP ability.
	 *
	 * @since 1.7.0
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/check-editor-health',
			[
				'label'       => 'Check Editor Health',
				'description' => 'Run 4 read-only readiness checks for the Elementor editor after a kit import: REST API reachability, admin-ajax availability, checklist.js null-deref bug detection, and HFE CSS path resolution. Returns a map of check names to boolean pass/fail. All checks are non-destructive.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'post_id' ],
					'properties' => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'ID of any Elementor-edited post to use for REST endpoint probing.',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'rest_api'  => [ 'type' => 'boolean', 'description' => 'Elementor REST panel endpoint returns 200/401/403.' ],
						'ajax'      => [ 'type' => 'boolean', 'description' => 'admin-ajax.php is reachable (returns 400 = expected for empty request).' ],
						'checklist' => [ 'type' => 'boolean', 'description' => 'No checklist.js null-deref bug detected.' ],
						'hfe_css'   => [ 'type' => 'boolean', 'description' => 'HFE CSS paths resolve correctly.' ],
						'all_pass'  => [ 'type' => 'boolean', 'description' => 'True if all 4 checks passed.' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
					],
				],
			]
		);
	}

	/**
	 * Execute check-editor-health.
	 *
	 * @param array|null $input
	 * @return array
	 */
	public static function execute( ?array $input ): array {
		$post_id = (int) ( $input['post_id'] ?? 0 );

		if ( $post_id <= 0 ) {
			return [ 'error' => 'post_id must be a positive integer.' ];
		}

		$results  = self::check_editor( $post_id );
		$all_pass = ! in_array( false, $results, true );

		return array_merge( $results, [ 'all_pass' => $all_pass ] );
	}
}
