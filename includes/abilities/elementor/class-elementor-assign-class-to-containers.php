<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Elementor;

use Novamira\AdrianV2\Helpers\Diagnostics;
use Novamira\AdrianV2\Helpers\Elementor_Document_Saver;
use Novamira\AdrianV2\Helpers\Elementor_CSS_Override;
use Novamira\AdrianV2\Helpers\Elementor_Data_Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor_Assign_Class_To_Containers
 *
 * Surgically assigns (or appends) a CSS class to every container element whose
 * ancestor path passes through `container_selector` in a single Elementor page.
 *
 * Pipeline:
 *   1. Resolve the Elementor document through `Elementor\Plugin::instance()->
 *      documents->get($page_id)` (same path as `Elementor_Document_Saver::save_data`).
 *      `wp_check_post_lock()` bails early if a user is currently in the editor.
 *   2. Depth-first search the element tree for elements that match the
 *      `container_selector` (class-token match against the legacy `css_classes`
 *      / `_css_classes` settings string). For every match, walk its descendants
 *      and mutate each descendant's class list through
 *      `Elementor_Document_Saver::assign_class()`. That helper writes to all
 *      three slices: legacy `css_classes`, legacy `_css_classes`, and v4-atomic
 *      `settings.classes.value`.
 *   3. Persist the whole mutated tree through `Elementor_Document_Saver::save_data()`.
 *      That helper route flushes the per-post `_elementor_css` file, the global
 *      `files_manager` cache, and `clean_post_cache`, returning a structured
 *      `{success, warnings[]}` shape that carries any soft-fails into the MCP
 *      response payload.
 *   4. Optional: append `custom_css` to the page's `_elementor_page_custom_css`
 *      meta via `Elementor_CSS_Override::inject_page_custom_css()`. That helper
 *      bumps every top-level class selector to `html body …` (specificity 0,1,2)
 *      so the new rules win against Elementor 4.x's `.e-con` defaults in
 *      source-order tiebreak.
 *
 * Return shape:
 *   {
 *     success: bool,
 *     data: {
 *       page_id: int,
 *       container_selector: string,
 *       class: string,
 *       matched_containers: int,          // top-level matches found
 *       elements_modified_count: int,
 *       modified_ids: string[],           // Elementor element ids touched
 *       warnings: string[],               // soft-fails from save_data / css injection
 *     },
 *     error: string,
 *   }
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */
final class Elementor_Assign_Class_To_Containers {

	/**
	 * Registers the ability with the WP Abilities API on `wp_abilities_api_init`.
	 */
	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/elementor-assign-class-to-containers',
			array(
				'label'               => __( 'Elementor — Assign class to containers', 'novamira-adrianv2' ),
				'description'         => __( 'Surgically assigns (or appends) a CSS class to every Elementor element whose ancestor path passes through a container selector on one page. Routes through `Elementor_Document_Saver` so the Elementor Document API writes the change, `wp_check_post_lock` guards against overwriting a concurrent editor, and v4-atomic `settings.classes.value` gets the same class id as the legacy `css_classes` / `_css_classes` strings. Optionally appends `custom_css` to `_elementor_page_custom_css` with the `html body` specificity bump (0,1,0 → 0,1,2) that survives Elementor 4.x `.e-con` defaults.', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-live-edit',
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => 'novamira_permission_callback',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'page_id'            => array(
							'type'        => 'integer',
							'description' => __( 'Elementor page id (required).', 'novamira-adrianv2' ),
						),
						'container_selector' => array(
							'type'        => 'string',
							'description' => __( 'A single class selector without the leading dot, e.g. `productslider`. The ability finds every Elementor element whose `settings.css_classes` / `settings._css_classes` string contains this class and treats its subtree as the assignment target.', 'novamira-adrianv2' ),
							'pattern'     => '^[A-Za-z_\\-][A-Za-z0-9_\\-]{0,63}$',
						),
						'class'              => array(
							'type'        => 'string',
							'description' => __( 'The CSS class name to assign (no leading dot). Sanitised via `sanitize_html_class`.', 'novamira-adrianv2' ),
							'pattern'     => '^[A-Za-z_\\-][A-Za-z0-9_\\-]{0,63}$',
						),
						'append_to_existing' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'If true (default), append the new class to the existing class list. If false, replace the class list with just the new class on each touched element.', 'novamira-adrianv2' ),
						),
						'recursive'          => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'If true (default), descend into every matched container and assign the class to all descendants. If false, only the matched container itself receives the class.', 'novamira-adrianv2' ),
						),
						'custom_css'         => array(
							'type'        => 'string',
							'description' => __( 'Optional page-level custom CSS to append after the class assignment. The CSS gets the `html body …` specificity bump via `Elementor_CSS_Override::inject_page_custom_css`.', 'novamira-adrianv2' ),
						),
					),
					'required'   => array( 'page_id', 'container_selector', 'class' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
						'data'    => array(
							'type'       => 'object',
							'properties' => array(
								'page_id'                 => array( 'type' => 'integer' ),
								'container_selector'      => array( 'type' => 'string' ),
								'class'                   => array( 'type' => 'string' ),
								'matched_containers'      => array( 'type' => 'integer' ),
								'elements_modified_count' => array( 'type' => 'integer' ),
								'modified_ids'            => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'warnings'                => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
						'error'   => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string,mixed>|null $input Input shape.
	 * @return array<string,mixed>      Structured success / data / error payload.
	 */
	public static function execute( $input = null ) {
		$input              = is_array( $input ) ? $input : array();
		$page_id            = isset( $input['page_id'] ) ? (int) $input['page_id'] : 0;
		$container_selector = isset( $input['container_selector'] ) ? (string) $input['container_selector'] : '';
		$class              = isset( $input['class'] ) ? (string) $input['class'] : '';
		$append             = ! array_key_exists( 'append_to_existing', $input ) || (bool) $input['append_to_existing'];
		$recursive          = ! array_key_exists( 'recursive', $input ) || (bool) $input['recursive'];
		$custom_css         = isset( $input['custom_css'] ) ? (string) $input['custom_css'] : '';

		if ( $page_id <= 0 ) {
			return self::error_payload( __( 'page_id is required and must be a positive integer.', 'novamira-adrianv2' ) );
		}
		if ( '' === $container_selector ) {
			return self::error_payload( __( 'container_selector is required.', 'novamira-adrianv2' ) );
		}
		if ( '' === $class ) {
			return self::error_payload( __( 'class is required.', 'novamira-adrianv2' ) );
		}

		// Sanitize selector and class to valid HTML tokens.
		$container_selector_clean = function_exists( 'sanitize_html_class' ) ? sanitize_html_class( $container_selector ) : '';
		$class_clean              = function_exists( 'sanitize_html_class' ) ? sanitize_html_class( $class ) : '';
		if ( '' === $container_selector_clean || '' === $class_clean ) {
			return self::error_payload(
				sprintf(
					/* translators: 1: container_selector input, 2: class input */
					__( 'Invalid selector or class name after sanitisation (container_selector=%1$s, class=%2$s).', 'novamira-adrianv2' ),
					$container_selector,
					$class
				)
			);
		}

		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return self::error_payload( __( 'Elementor is not active on this site.', 'novamira-adrianv2' ) );
		}

		try {
			$doc = \Elementor\Plugin::$instance->documents->get( $page_id );
		} catch ( \Throwable $e ) {
			Diagnostics::record( 'elementor-assign-class', (string) $page_id, $e );
			return self::error_payload(
				sprintf(
					/* translators: %s: original exception message */
					__( 'Elementor document lookup threw: %s', 'novamira-adrianv2' ),
					$e->getMessage()
				)
			);
		}

		if ( ! $doc ) {
			return self::error_payload(
				sprintf(
					/* translators: %d: Elementor page id */
					__( 'No Elementor document for page_id %d.', 'novamira-adrianv2' ),
					$page_id
				)
			);
		}

		// wp_check_post_lock is run inside save_data below; we duplicate the early
		// bail here so we don't do pointless tree mutation when a human is editing.
		if ( function_exists( 'wp_check_post_lock' ) && wp_check_post_lock( $page_id ) ) {
			return self::error_payload( __( 'Another user is currently editing this post in Elementor. Aborting to avoid stomping the in-progress edit.', 'novamira-adrianv2' ) );
		}

		try {
			$data = $doc->get_elements_data();
		} catch ( \Throwable $e ) {
			return self::error_payload(
				sprintf(
					/* translators: %s: original exception message */
					__( 'Elementor get_elements_data threw: %s', 'novamira-adrianv2' ),
					$e->getMessage()
				)
			);
		}
		if ( ! is_array( $data ) ) {
			return self::error_payload(
				sprintf(
					/* translators: %d: Elementor page id */
					__( 'Elementor elements_data for page_id %d is not an array.', 'novamira-adrianv2' ),
					$page_id
				)
			);
		}

		$matched_containers = 0;
		$modified_ids       = array();
		$warnings           = array();

		self::collect_containers_recursive( $data, $container_selector_clean, $matched_containers );
		if ( $matched_containers < 1 ) {
			return self::error_payload(
				sprintf(
					/* translators: 1: container_selector, 2: page_id */
					__( 'No element on page %2$d carries the class `%1$s` in its `css_classes`/`_css_classes` settings.', 'novamira-adrianv2' ),
					$container_selector_clean,
					$page_id
				)
			);
		}

		// Mutate every element whose ancestor path passes through a matching
		// container. We walk the tree twice (once to find containers, once to
		// mutate their descendants) so the mutated tree doesn't accidentally
		// satisfy the selector itself and re-fire another pass.
		self::assign_recursive( $data, $container_selector_clean, $class_clean, $append, $recursive, $modified_ids );

		// Persist the mutated tree through the helper. save_data returns
		// {success, warnings}; we surface warnings in the response.
		$save_result = Elementor_Document_Saver::save_data( $page_id, $data );
		if ( ! is_array( $save_result ) || empty( $save_result['success'] ) ) {
			$error_msg = is_array( $save_result ) && isset( $save_result['warnings'][0] )
				? (string) $save_result['warnings'][0]
				: __( 'Elementor save_data reported no success path.', 'novamira-adrianv2' );
			return array(
				'success' => false,
				'data'    => array(
					'page_id'                 => $page_id,
					'container_selector'      => $container_selector_clean,
					'class'                   => $class_clean,
					'matched_containers'      => $matched_containers,
					'elements_modified_count' => count( $modified_ids ),
					'modified_ids'            => array_values( array_unique( $modified_ids ) ),
					'warnings'                => is_array( $save_result ) && isset( $save_result['warnings'] ) ? $save_result['warnings'] : array(),
				),
				'error'   => $error_msg,
			);
		}

		if ( is_array( $save_result ) && ! empty( $save_result['warnings'] ) ) {
			$warnings = array_merge( $warnings, $save_result['warnings'] );
		}

		// Optional: append custom CSS via the helper. Errors here are soft — the
		// class assignment already succeeded; we surface a warning instead.
		if ( '' !== $custom_css ) {
			$css_result = Elementor_CSS_Override::inject_page_custom_css( $page_id, $custom_css );
			if ( is_wp_error( $css_result ) ) {
				$warnings[] = sprintf(
					/* translators: %s: WP_Error message */
					__( 'inject_page_custom_css failed: %s', 'novamira-adrianv2' ),
					$css_result->get_error_message()
				);
			}
		}

		return array(
			'success' => true,
			'data'    => array(
				'page_id'                 => $page_id,
				'container_selector'      => $container_selector_clean,
				'class'                   => $class_clean,
				'matched_containers'      => $matched_containers,
				'elements_modified_count' => count( array_unique( $modified_ids ) ),
				'modified_ids'            => array_values( array_unique( $modified_ids ) ),
				'warnings'                => $warnings,
			),
		);
	}

	/**
	 * Depth-first walk that counts (but does NOT mutate) how many containers in
	 * the tree match the given selector. Useful to fail fast with a clear error
	 * when the selector matches nothing — better than silently writing 0 mutations
	 * to the page tree.
	 *
	 * @param array<int,mixed> $elements      Element tree (top-level array).
	 * @param string           $selector_clean Sanitised class selector (no dot).
	 * @param int              $counter       By-reference counter.
	 */
	private static function collect_containers_recursive( array $elements, string $selector_clean, int &$counter ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( self::element_has_class( $el, $selector_clean ) ) {
				++$counter;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::collect_containers_recursive( $el['elements'], $selector_clean, $counter );
			}
		}
	}

	/**
	 * Depth-first mutation. For every container matching the selector, walk its
	 * subtree (or just the container itself if $recursive=false) and call
	 * `Elementor_Document_Saver::assign_class` on each descendant.
	 *
	 * @param array<int,mixed> $elements      Element tree (passed by reference; mutated).
	 * @param string           $selector_clean Sanitised selector.
	 * @param string           $class_clean    Sanitised class.
	 * @param bool             $append         Append vs replace.
	 * @param bool             $recursive      Recurse into descendants.
	 * @param string[]         $modified_ids   By-reference list of touched element ids.
	 */
	private static function assign_recursive( array &$elements, string $selector_clean, string $class_clean, bool $append, bool $recursive, array &$modified_ids ): void {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$is_container = self::element_has_class( $el, $selector_clean );

			if ( $is_container && ! $recursive ) {
				Elementor_Document_Saver::assign_class( $el, $class_clean, $append );
				if ( isset( $el['id'] ) ) {
					$modified_ids[] = (string) $el['id'];
				}
				continue;
			}

			if ( $is_container && $recursive ) {
				// Mutate the container itself.
				Elementor_Document_Saver::assign_class( $el, $class_clean, $append );
				if ( isset( $el['id'] ) ) {
					$modified_ids[] = (string) $el['id'];
				}
				// Then mutate every descendant.
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					self::assign_to_descendants( $el['elements'], $class_clean, $append, $modified_ids );
				}
				continue;
			}

			// Not a container — descend.
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::assign_recursive( $el['elements'], $selector_clean, $class_clean, $append, $recursive, $modified_ids );
			}
		}
		unset( $el );
	}

	/**
	 * Helper: mutate every element in the subtree (depth-first, by-ref).
	 *
	 * @param array<int,mixed> $elements      Subtree (by reference; mutated).
	 * @param string           $class_clean    Sanitised class.
	 * @param bool             $append         Append vs replace.
	 * @param string[]         $modified_ids   By-reference touched id list.
	 */
	private static function assign_to_descendants( array &$elements, string $class_clean, bool $append, array &$modified_ids ): void {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			Elementor_Document_Saver::assign_class( $el, $class_clean, $append );
			if ( isset( $el['id'] ) ) {
				$modified_ids[] = (string) $el['id'];
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::assign_to_descendants( $el['elements'], $class_clean, $append, $modified_ids );
			}
		}
		unset( $el );
	}

	/**
	 * Whether an Elementor element's class-settings contain the given class token.
	 *
	 * @param array<string,mixed> $el Element.
	 * @param string              $class_clean Sanitised class.
	 * @return bool
	 */
	private static function element_has_class( array $el, string $class_clean ): bool {
		if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
			return false;
		}
		$candidates = array();
		foreach ( array( 'css_classes', '_css_classes' ) as $key ) {
			if ( isset( $el['settings'][ $key ] ) && is_string( $el['settings'][ $key ] ) ) {
				$candidates[] = $el['settings'][ $key ];
			}
		}
		foreach ( $candidates as $raw ) {
			$tokens = preg_split( '/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $tokens ) && in_array( $class_clean, $tokens, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds a structured error payload.
	 *
	 * @param string $msg Message.
	 * @return array<string,mixed>
	 */
	private static function error_payload( string $msg ): array {
		return array(
			'success' => false,
			'error'   => $msg,
			'data'    => array(
				'warnings' => array(),
			),
		);
	}
}

add_action( 'wp_abilities_api_init', array( Elementor_Assign_Class_To_Containers::class, 'register' ) );
