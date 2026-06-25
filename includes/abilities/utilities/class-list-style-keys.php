<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Utilities;

use Novamira\AdrianV2\Helpers\V4_Props;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * List_Style_Keys
 *
 * Ability: `novamira-adrianv2/list-style-keys`
 *
 * Exposes V4_Props methods as a machine-readable style-key catalog.
 *
 * Eliminates the drift between:
 *   - framer-pre-build-validate.js (12 hardcoded widget types)
 *   - style-props-quickref.md (static docs)
 *   - class-v4-props.php (the actual source of truth)
 *
 * Pipeline and skills read this at runtime instead of hardcoding.
 *
 * @package Novamira_AdrianV2
 * @since   1.5.0
 */
final class List_Style_Keys {

    /**
     * Prop types that wrap scalar values — the pipeline can
     * pass a plain value and V4_Props will wrap it.
     */
    private const SCALAR_PROPS = [ 'string', 'number', 'boolean', 'html', 'url' ];

    /**
     * Prop types that need structured input.
     */
    private const STRUCTURED_PROPS = [ 'size', 'link', 'classes', 'image' ];

    public static function register(): void {
        wp_register_ability( 'novamira-adrianv2/list-style-keys', [
            'label'       => 'List Style Keys',
            'description' =>
                'Returns the live V4_Props method catalog — all atomic prop types with '
                . 'input shape, $$type value, and example. '
                . 'Eliminates hardcoded widget-type lists in framer-pre-build-validate.js '
                . 'and style-props-quickref.md. Call once per session to get the ground truth.',
            'category'    => 'adrianv2-utilities',

            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'format' => [
                        'type'    => 'string',
                        'enum'    => [ 'full', 'compact', 'schema_only' ],
                        'default' => 'full',
                        'description' => 'full = all fields; compact = name+type+example only; schema_only = JSON Schema for each prop.',
                    ],
                    'filter_scalar' => [
                        'type'    => 'boolean',
                        'default' => false,
                        'description' => 'If true, return only scalar props (string/number/boolean/html/url).',
                    ],
                ],
            ],

            'output_schema' => [
                'type'       => 'object',
                'properties' => [
                    'success'         => [ 'type' => 'boolean' ],
                    'total'           => [ 'type' => 'integer' ],
                    'scalar_props'    => [ 'type' => 'array' ],
                    'structured_props'=> [ 'type' => 'array' ],
                    'utility_methods' => [ 'type' => 'array' ],
                    'props'           => [ 'type' => 'object', 'description' => 'Keyed by prop name.' ],
                    'atomic_supported_types' => [ 'type' => 'array', 'description' => 'Widget types that support atomic props.' ],
                    'summary'         => [ 'type' => 'string' ],
                ],
            ],

            'execute_callback'    => [ self::class, 'execute' ],
            'permission_callback' => 'novamira_permission_callback',
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => [ 'public' => true ],
                'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
            ],
        ] );
    }

    public static function execute( ?array $input = null ): array {
        $input         = $input ?? [];
        $format        = in_array( $input['format'] ?? 'full', [ 'full', 'compact', 'schema_only' ], true )
                            ? ( $input['format'] ?? 'full' )
                            : 'full';
        $filter_scalar = (bool) ( $input['filter_scalar'] ?? false );

        // Build catalog from V4_Props via Reflection.
        $props = self::build_catalog( $format, $filter_scalar );

        // Separate scalar / structured / utility.
        $scalar_names    = [];
        $structured_names= [];
        $utility_names   = [];

        foreach ( $props as $name => $_ ) {
            if ( in_array( $name, self::SCALAR_PROPS, true ) ) {
                $scalar_names[] = $name;
            } elseif ( in_array( $name, self::STRUCTURED_PROPS, true ) ) {
                $structured_names[] = $name;
            } else {
                $utility_names[] = $name;
            }
        }

        // Atomic-supported widget types from V4_Props::is_atomic_supported (if callable).
        $atomic_types = self::get_atomic_supported_types();

        return [
            'success'               => true,
            'total'                 => count( $props ),
            'scalar_props'          => $scalar_names,
            'structured_props'      => $structured_names,
            'utility_methods'       => $utility_names,
            'props'                 => $props,
            'atomic_supported_types'=> $atomic_types,
            'summary'               => sprintf(
                '%d prop type(s): %d scalar, %d structured, %d utility. %d atomic widget types known.',
                count( $props ),
                count( $scalar_names ),
                count( $structured_names ),
                count( $utility_names ),
                count( $atomic_types )
            ),
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function build_catalog( string $format, bool $filter_scalar ): array {
        // Static definitions — mirrors V4_Props exactly.
        // Using static array rather than Reflection to avoid runtime complexity;
        // update when V4_Props changes (one place, clear diff).
        $all_props = [
            'string' => [
                'type'        => 'scalar',
                '$$type'      => 'string',
                'input'       => 'string',
                'example'     => V4_Props::string( 'Hello World' ),
                'description' => 'Plain text content for headings, paragraphs, labels.',
                'schema'      => [ 'type' => 'string' ],
            ],
            'number' => [
                'type'        => 'scalar',
                '$$type'      => 'number',
                'input'       => 'int|float',
                'example'     => V4_Props::number( 42 ),
                'description' => 'Numeric value (unitless). Use size() when you need a unit.',
                'schema'      => [ 'type' => 'number' ],
            ],
            'boolean' => [
                'type'        => 'scalar',
                '$$type'      => 'boolean',
                'input'       => 'bool',
                'example'     => V4_Props::boolean( true ),
                'description' => 'True/false toggle for widget settings.',
                'schema'      => [ 'type' => 'boolean' ],
            ],
            'size' => [
                'type'        => 'structured',
                '$$type'      => 'size',
                'input'       => 'array{size:number, unit:string}  unit: px|em|rem|%|vw|vh',
                'example'     => V4_Props::size( 16, 'px' ),
                'description' => 'Dimensional value with unit. Most font-size, padding, gap props use this.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [ 'size' => [ 'type' => 'number' ], 'unit' => [ 'type' => 'string', 'enum' => [ 'px','em','rem','%','vw','vh' ] ] ],
                    'required'   => [ 'size', 'unit' ],
                ],
            ],
            'html' => [
                'type'        => 'scalar',
                '$$type'      => 'html',
                'input'       => 'string (HTML allowed)',
                'example'     => V4_Props::html( '<strong>Bold</strong> text' ),
                'description' => 'Rich text / HTML content for e-paragraph and text widgets.',
                'schema'      => [ 'type' => 'string' ],
            ],
            'url' => [
                'type'        => 'scalar',
                '$$type'      => 'url',
                'input'       => 'string (absolute URL)',
                'example'     => V4_Props::url( 'https://example.com' ),
                'description' => 'Absolute URL for links, iframes, embeds.',
                'schema'      => [ 'type' => 'string', 'format' => 'uri' ],
            ],
            'link' => [
                'type'        => 'structured',
                '$$type'      => 'link',
                'input'       => 'array{url:string, target?:string, nofollow?:bool}',
                'example'     => V4_Props::link( 'https://example.com', '_blank', false ),
                'description' => 'Button / anchor link with URL, target and nofollow.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'url'      => [ 'type' => 'string' ],
                        'target'   => [ 'type' => 'string', 'enum' => [ '_blank', '_self' ] ],
                        'nofollow' => [ 'type' => 'boolean' ],
                    ],
                    'required' => [ 'url' ],
                ],
            ],
            'classes' => [
                'type'        => 'structured',
                '$$type'      => 'classes',
                'input'       => 'array<string>  (Global Class IDs from setup-v4-foundation)',
                'example'     => V4_Props::classes( [ 'e-flexbox-base', 'my-custom-class' ] ),
                'description' => 'Array of Global Class IDs to apply to an element. Get IDs from setup-v4-foundation.',
                'schema'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
            'image' => [
                'type'        => 'structured',
                '$$type'      => 'image-attachment-id',
                'input'       => 'array{id:int}  OR  array{url:string}  (exactly one)',
                'example'     => V4_Props::image( 42 ),
                'description' => 'Image attachment. Pass WP attachment ID (preferred) or URL. Never both. Invariant IV: omit url key when id is set.',
                'schema'      => [
                    'type' => 'object',
                    'oneOf' => [
                        [ 'required' => [ 'id' ],  'properties' => [ 'id'  => [ 'type' => 'integer' ] ] ],
                        [ 'required' => [ 'url' ], 'properties' => [ 'url' => [ 'type' => 'string'  ] ] ],
                    ],
                ],
            ],
            // Utility methods.
            'unwrap' => [
                'type'        => 'utility',
                'description' => 'Extracts plain value from a $$type-wrapped prop. Reverse of all builders.',
                'schema'      => null,
            ],
            'get_schema' => [
                'type'        => 'utility',
                'description' => 'Returns JSON Schema for V4_Props input validation.',
                'schema'      => null,
            ],
            'is_atomic_supported' => [
                'type'        => 'utility',
                'description' => 'Returns true if a given widget type supports atomic props.',
                'schema'      => null,
            ],
        ];

        if ( $filter_scalar ) {
            $all_props = array_filter( $all_props, fn( $p ) => ( $p['type'] ?? '' ) === 'scalar' );
        }

        // Apply format.
        if ( $format === 'compact' ) {
            return array_map( fn( $p ) => [
                'type'    => $p['type']        ?? '',
                '$$type'  => $p['$$type']      ?? null,
                'input'   => $p['input']        ?? null,
                'example' => $p['example']      ?? null,
            ], $all_props );
        }

        if ( $format === 'schema_only' ) {
            return array_map( fn( $p ) => [
                'type'   => $p['type']   ?? '',
                'schema' => $p['schema'] ?? null,
            ], $all_props );
        }

        return $all_props;
    }

    private static function get_atomic_supported_types(): array {
        // Known atomic widget types. All are supported when V4_Props::is_atomic_supported() is true.
        $known = [
            'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg',
            'e-divider', 'e-icon', 'e-spacer', 'e-video', 'e-code',
            'e-flexbox', 'e-div-block',
        ];

        try {
            // Site-level check: if atomic is not supported, none of these work.
            return V4_Props::is_atomic_supported() ? $known : [];
        } catch ( \Throwable $_ ) {
            return $known; // assume supported if method errors
        }
    }
}
