<?php
declare(strict_types=1);

/**
 * Ability: novamira-adrianv2/elementor-check-setup
 *
 * Read-only probe of the Elementor environment on this WordPress install.
 * Reports:
 *   - Elementor active state + version (core + pro)
 *   - Active kit ID + kit label
 *   - V3 vs V4 mode (detected via version + atomic-widget capability)
 *   - Global classes count
 *   - Global variables (Design Tokens) count
 *   - Current user permissions (edit_posts, manage_options)
 *   - A flat list of detected configuration issues
 *
 * Read-only — never writes to DB, never mutates options/meta/files.
 *
 * @package novamira-adrianv2
 * @since   1.1.0
 */

namespace Novamira\AdrianV2\Abilities\Elementor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor_Check_Setup {

	/**
	 * Register the `novamira-adrianv2/elementor-check-setup` ability.
	 * Idempotent — safe to call multiple times.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/elementor-check-setup',
			array(
				'label'       => __( 'Check Elementor Setup', 'novamira-adrianv2' ),
				'description' => __(
					'Probes the Elementor environment: plugin version, Pro status, active kit ID, V3 vs V4 mode, global-class count, design-token count, and current-user permissions. `issues` lists detected configuration problems. Call this before any elementor-* write ability to confirm the environment is ready.',
					'novamira-adrianv2'
				),
				'category'     => 'adrianv2-elementor',
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
						'pro_active'    => array( 'type' => 'boolean' ),
						'pro_version'   => array( 'type' => array( 'string', 'null' ) ),
						'mode'          => array(
							'type' => 'string',
							'enum' => array( 'v3', 'v4', 'unknown' ),
						),
						'kit' => array(
							'type'       => 'object',
							'properties' => array(
								'id'    => array( 'type' => array( 'integer', 'null' ) ),
								'label' => array( 'type' => array( 'string', 'null' ) ),
							),
						),
						'global_classes_count'   => array( 'type' => 'integer' ),
						'design_tokens_count'    => array( 'type' => 'integer' ),
						'permissions_ok'         => array( 'type' => 'boolean' ),
						'issues'                 => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required' => array(
						'active',
						'version',
						'pro_active',
						'pro_version',
						'mode',
						'kit',
						'global_classes_count',
						'design_tokens_count',
						'permissions_ok',
						'issues',
					),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'instructions' => __( 'Call before any elementor-* write ability. Check `active` (Elementor must be active), `mode` (v3 vs v4 — ability sets differ), `kit.id` (needed for design-token and global-class writes), `permissions_ok` (edit_posts required for write abilities). Use `issues` as a quick diagnostic list.', 'novamira-adrianv2' ),
						'readonly'     => true,
						'destructive'  => false,
						'idempotent'   => true,
					),
				),
			)
		);
	}

	/**
	 * Permission gate — read only; subscriber-and-above suffices.
	 *
	 * @return bool
	 */
	public static function check_permission(): bool {
		return current_user_can( 'read' );
	}

	/**
	 * Execute the Elementor setup probe.
	 *
	 * @param array<string, mixed> $input Ignored (no input properties).
	 * @return array<string, mixed>
	 */
	public static function execute( array $input ): array {
		$issues = array();

		// ---------------------------------------------------------------
		// 1. Elementor core presence + version
		// ---------------------------------------------------------------
		$elementor_active  = class_exists( '\\Elementor\\Plugin' );
		$elementor_version = null;

		if ( $elementor_active ) {
			$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null;
			if ( null === $elementor_version ) {
				$issues[] = 'ELEMENTOR_VERSION constant not defined (unusual; plugin may be partially loaded).';
			}
		} else {
			$issues[] = 'Elementor is not active or not installed. All elementor-* abilities will fail.';
		}

		// ---------------------------------------------------------------
		// 2. Elementor Pro
		// ---------------------------------------------------------------
		$pro_active  = defined( 'ELEMENTOR_PRO_VERSION' );
		$pro_version = $pro_active ? ELEMENTOR_PRO_VERSION : null;

		// ---------------------------------------------------------------
		// 3. V3 vs V4 mode detection
		// ---------------------------------------------------------------
		$mode = 'unknown';
		if ( $elementor_active && null !== $elementor_version ) {
			if ( version_compare( $elementor_version, '4.0.0', '>=' ) ) {
				$mode = 'v4';
			} elseif ( version_compare( $elementor_version, '3.0.0', '>=' ) ) {
				$mode = 'v3';
			}
		}

		// Cross-check with Elementor_Version_Resolver helper if available.
		if ( class_exists( '\\Novamira\\AdrianV2\\Helpers\\Elementor_Version_Resolver' ) ) {
			$site_is_v4 = \Novamira\AdrianV2\Helpers\Elementor_Version_Resolver::site_is_v4();
			// Resolver is authoritative when the version string alone is ambiguous.
			if ( $site_is_v4 && 'v3' === $mode ) {
				$mode     = 'v4';
				$issues[] = 'Version resolver reports V4 but ELEMENTOR_VERSION suggests V3. Check active experiments.';
			}
		}

		// ---------------------------------------------------------------
		// 4. Active Kit
		// ---------------------------------------------------------------
		$kit_id    = null;
		$kit_label = null;

		if ( $elementor_active ) {
			try {
				$kits_manager = \Elementor\Plugin::$instance->kits_manager ?? null;
				if ( $kits_manager && method_exists( $kits_manager, 'get_active_id' ) ) {
					$raw_id = $kits_manager->get_active_id();
					if ( is_numeric( $raw_id ) && (int) $raw_id > 0 ) {
						$kit_id    = (int) $raw_id;
						$kit_post  = get_post( $kit_id );
						$kit_label = $kit_post ? $kit_post->post_title : null;
					} else {
						$issues[] = 'kits_manager->get_active_id() returned an invalid kit ID. Design-token and global-class writes will fail.';
					}
				} else {
					$issues[] = 'kits_manager is not available on Elementor\\Plugin::$instance. Check Elementor initialization order.';
				}
			} catch ( \Throwable $e ) {
				$issues[] = 'Exception while reading active kit: ' . $e->getMessage();
			}
		}

		// ---------------------------------------------------------------
		// 5. Global classes count
		// ---------------------------------------------------------------
		$global_classes_count = 0;
		if ( $elementor_active && null !== $kit_id ) {
			try {
				$raw_classes = get_post_meta( $kit_id, '_elementor_global_classes', true );
				if ( is_array( $raw_classes ) ) {
					$global_classes_count = count( $raw_classes );
				}
			} catch ( \Throwable $e ) {
				$issues[] = 'Could not read global classes: ' . $e->getMessage();
			}
		}

		// ---------------------------------------------------------------
		// 6. Design tokens (global variables) count
		// ---------------------------------------------------------------
		$design_tokens_count = 0;
		if ( $elementor_active && null !== $kit_id ) {
			try {
				$kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
				if ( is_array( $kit_settings ) ) {
					// V4 stores global variables in system_colors, system_typography,
					// and custom_colors / custom_typography arrays.
					$token_arrays = array(
						$kit_settings['system_colors']       ?? array(),
						$kit_settings['custom_colors']       ?? array(),
						$kit_settings['system_typography']   ?? array(),
						$kit_settings['custom_typography']   ?? array(),
					);
					foreach ( $token_arrays as $token_array ) {
						if ( is_array( $token_array ) ) {
							$design_tokens_count += count( $token_array );
						}
					}
				}
			} catch ( \Throwable $e ) {
				$issues[] = 'Could not count design tokens: ' . $e->getMessage();
			}
		}

		// ---------------------------------------------------------------
		// 7. Permissions
		// ---------------------------------------------------------------
		$permissions_ok = current_user_can( 'edit_posts' ) && current_user_can( 'manage_options' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			$issues[] = 'Current user lacks edit_posts — elementor-* write abilities will be denied.';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$issues[] = 'Current user lacks manage_options — kit-level writes (design tokens, global classes) will be denied.';
		}

		return array(
			'active'               => $elementor_active,
			'version'              => $elementor_version,
			'pro_active'           => $pro_active,
			'pro_version'          => $pro_version,
			'mode'                 => $mode,
			'kit'                  => array(
				'id'    => $kit_id,
				'label' => $kit_label,
			),
			'global_classes_count' => $global_classes_count,
			'design_tokens_count'  => $design_tokens_count,
			'permissions_ok'       => $permissions_ok,
			'issues'               => $issues,
		);
	}
}
