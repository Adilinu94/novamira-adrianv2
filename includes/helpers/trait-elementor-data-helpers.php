<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Elementor_Data_Helpers — page read/write/find/update primitives.
 *
 * Every ability that touches an Elementor page goes through this trait so
 * the JSON shape is consistent (always `elements[]` at the top level, with
 * `error` instead of throwing).
 *
 * Public API (all `protected static` so consumers stay trait-coupled):
 *   - read_page(int)                → ['elements' => array, 'error' => ?string]
 *   - write_page(int, array)        → true|\WP_Error
 *   - find_element(array, string)   → ?array
 *   - update_element_settings(...)  → bool
 *   - generate_id()                 → string
 *
 * Internal (kept for backwards compat with ability-internal helpers):
 *   - elementor_get_document(int)   → ?\Elementor\Core\Base\Document
 *   - elementor_get_data(int)       → array
 *   - elementor_save_data(int, array) → bool
 */
trait Elementor_Data_Helpers {
    // -----------------------------------------------------------------
    // Low-level Elementor document access
    // -----------------------------------------------------------------

    protected static function elementor_get_document( int $post_id ) {
        if ( ! class_exists( '\Elementor\Plugin' ) ) { return null; }
        return \Elementor\Plugin::$instance->documents->get( $post_id );
    }

    protected static function elementor_get_data( int $post_id ): array {
        $doc = self::elementor_get_document( $post_id );
        if ( ! $doc ) { return []; }
        $data = $doc->get_elements_data();
        return is_array( $data ) ? $data : [];
    }

    protected static function elementor_save_data( int $post_id, array $data ): bool {
        $doc = self::elementor_get_document( $post_id );
        if ( ! $doc ) { return false; }
        $doc->update_json_meta( '_elementor_data', $data );
        return true;
    }

    // -----------------------------------------------------------------
    // High-level read/write API (used by A11y / Seo / Atomic / Custom-Code)
    // -----------------------------------------------------------------

    /**
     * Read a page's element tree, normalized as `{elements, error}`.
     *
     * - `error` is null on success.
     * - `error` is a human-readable string on failure (e.g. 'no_elementor_doc').
     * - `elements` is always an array (empty array if the page has no
     *   Elementor data yet).
     */
    protected static function read_page( int $post_id ): array {
        if ( $post_id <= 0 ) {
            return [ 'elements' => [], 'error' => 'invalid_post_id' ];
        }
        if ( ! function_exists( 'get_post' ) || null === get_post( $post_id ) ) {
            return [ 'elements' => [], 'error' => 'post_not_found' ];
        }
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return [ 'elements' => [], 'error' => 'elementor_not_active' ];
        }
        $doc = self::elementor_get_document( $post_id );
        if ( ! $doc ) {
            return [ 'elements' => [], 'error' => 'no_elementor_doc' ];
        }
        $data = $doc->get_elements_data();
        return [
            'elements' => is_array( $data ) ? $data : [],
            'error'    => null,
        ];
    }

    /**
     * Persist the element tree for a post, invalidating Elementor caches.
     *
     * Returns true on success, or a \WP_Error on failure.
     */
    protected static function write_page( int $post_id, array $elements ) {
        $saved = self::elementor_save_data( $post_id, $elements );
        if ( ! $saved ) {
            return new \WP_Error( 'save_failed', sprintf( 'Could not save Elementor data for post %d.', $post_id ) );
        }
        // Clear Elementor's per-post CSS cache.
        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            \Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
        }
        // Fire WordPress standard post-changed hooks so 3rd-party caches purge.
        if ( function_exists( 'clean_post_cache' ) ) {
            clean_post_cache( $post_id );
        }
        return true;
    }

    /**
     * Find a single element by id anywhere in the tree (depth-first walk).
     *
     * Returns the element array or null if not found. Use `find_element_ref`
     * when you need a reference for in-place mutation.
     */
    protected static function find_element( array $elements, string $element_id ): ?array {
        foreach ( $elements as $el ) {
            if ( ! is_array( $el ) ) { continue; }
            if ( isset( $el['id'] ) && (string) $el['id'] === $element_id ) {
                return $el;
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $found = self::find_element( $el['elements'], $element_id );
                if ( null !== $found ) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Update a single element's settings (shallow merge), by id, anywhere in the tree.
     *
     * Returns true if the element was found AND updated, false otherwise.
     */
    protected static function update_element_settings( array &$elements, string $element_id, array $settings ): bool {
        foreach ( $elements as &$el ) {
            if ( ! is_array( $el ) ) { continue; }
            if ( isset( $el['id'] ) && (string) $el['id'] === $element_id ) {
                if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
                    $el['settings'] = [];
                }
                $el['settings'] = array_merge( $el['settings'], $settings );
                return true;
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                if ( self::update_element_settings( $el['elements'], $element_id, $settings ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Generate a 7-character hex element id, the Elementor default.
     */
    protected static function generate_id(): string {
        try {
            $bytes = random_bytes( 4 );
        } catch ( \Throwable $e ) {
            $bytes = (string) microtime( true );
        }
        return substr( bin2hex( (string) $bytes ), 0, 7 );
    }

    /**
     * Insert an element under a given parent, or append at root if parent_id
     * is empty. Walks the tree to find the parent (depth-first).
     *
     * @param array   $elements   The element tree (passed by reference; mutated).
     * @param string  $parent_id  The parent's element id. Empty = root insert.
     * @param array   $new_element The element to insert.
     * @param int     $position   Zero-based sibling position; -1 = append.
     * @return bool True on successful insert, false if parent not found.
     */
    protected static function insert_element( array &$elements, string $parent_id, array $new_element, int $position = -1 ): bool {
        if ( '' === $parent_id ) {
            if ( $position < 0 || $position >= count( $elements ) ) {
                $elements[] = $new_element;
            } else {
                array_splice( $elements, max( 0, $position ), 0, [ $new_element ] );
            }
            return true;
        }
        foreach ( $elements as &$el ) {
            if ( ! is_array( $el ) ) { continue; }
            if ( isset( $el['id'] ) && (string) $el['id'] === $parent_id ) {
                if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
                    $el['elements'] = [];
                }
                if ( $position < 0 || $position >= count( $el['elements'] ) ) {
                    $el['elements'][] = $new_element;
                } else {
                    array_splice( $el['elements'], max( 0, $position ), 0, [ $new_element ] );
                }
                return true;
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                if ( self::insert_element( $el['elements'], $parent_id, $new_element, $position ) ) {
                    return true;
                }
            }
        }
        return false;
    }
}
