<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ClonerLabs_Media_Handler
 *
 * BUG #5 FIX: `e-svg` widgets export `settings.svg_content` (raw SVG HTML),
 * which is NOT a valid Elementor field. Must be sideloaded as an SVG file
 * and reformatted as V4 `src` object.
 *
 * FIX #17: Complete list of URL sources sideloaded:
 *   - `media_library.svgs[].dataUri`     (base64 data-URI → sideload)
 *   - `settings.image.url`               (external URL → download + sideload)
 *   - `settings.background_image.url`    (external URL → download + sideload)
 *   - `settings.svg.url`                 (external SVG URL → sideload)
 *   - `settings.svg_content`             (e-svg raw HTML → write to tmp → sideload → V4 format)
 *   - `settings.html` img src attrs      (data-URIs embedded in HTML widgets)
 *
 * All uploads deduplicated by URL/content hash to prevent double-uploads.
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class ClonerLabs_Media_Handler {

    /** @var array<string,string> old → new WP URL */
    private array $url_map = [];

    /** @var array<string,true> external URLs pending sideload */
    private array $pending_external = [];

    private int $uploaded = 0;
    private int $replaced = 0;

    /** @var string[] */
    private array $errors = [];

    // ── Entry Point ────────────────────────────────────────────────────────────

    /**
     * @param  array $media_library ClonerLabs `media_library` object.
     * @param  array $elements      Element tree (mutated in-place via reference).
     * @return array{uploaded:int, replaced:int, url_map:array, errors:array}
     */
    public static function process( array $media_library, array &$elements ): array {
        $instance = new self();

        // Step 1: SVG data-URIs from media_library manifest.
        foreach ( $media_library['svgs'] ?? [] as $svg ) {
            $instance->sideload_data_uri( $svg['dataUri'] ?? '', $svg['filename'] ?? 'icon.svg' );
        }

        // Step 2: Collect external URLs from element tree.
        $instance->collect_external_urls( $elements );

        // Step 3: Sideload collected external URLs.
        $instance->upload_external_urls();

        // Step 4: Replace URLs in tree (includes e-svg reformat — BUG #5).
        $elements = $instance->replace_in_tree( $elements );

        return [
            'uploaded' => $instance->uploaded,
            'replaced' => $instance->replaced,
            'url_map'  => $instance->url_map,
            'errors'   => $instance->errors,
        ];
    }

    // ── SVG Data-URI ──────────────────────────────────────────────────────────

    private function sideload_data_uri( string $data_uri, string $filename ): void {
        if ( empty( $data_uri ) || ! str_starts_with( $data_uri, 'data:' ) ) return;
        if ( isset( $this->url_map[ $data_uri ] ) ) return;

        $this->ensure_sideload_functions();

        if ( ! preg_match( '/^data:([^;]+);base64,(.+)$/s', $data_uri, $m ) ) {
            $this->errors[] = "Invalid data URI: {$filename}";
            return;
        }

        $raw = base64_decode( $m[2] );
        if ( $raw === false ) {
            $this->errors[] = "Base64 decode failed: {$filename}";
            return;
        }

        $new_url = $this->sideload_raw_bytes( $raw, $filename );
        if ( $new_url ) {
            $this->url_map[ $data_uri ] = $new_url;
        }
    }

    // ── External URL Collection ───────────────────────────────────────────────

    private function collect_external_urls( array $elements ): void {
        foreach ( $elements as $el ) {
            $s = $el['settings'] ?? [];

            foreach ( [
                $s['image']['url']            ?? '',
                $s['background_image']['url'] ?? '',
                $s['svg']['url']              ?? '',
            ] as $url ) {
                if ( self::is_external_url( $url ) && ! isset( $this->url_map[ $url ] ) ) {
                    $this->pending_external[ $url ] = true;
                }
            }

            if ( ! empty( $el['elements'] ) ) {
                $this->collect_external_urls( $el['elements'] );
            }
        }
    }

    // ── External URL Sideload ─────────────────────────────────────────────────

    private function upload_external_urls(): void {
        if ( empty( $this->pending_external ) ) return;
        $this->ensure_sideload_functions();

        foreach ( array_keys( $this->pending_external ) as $url ) {
            if ( isset( $this->url_map[ $url ] ) ) continue;

            // Already in this WP install.
            if ( attachment_url_to_postid( $url ) ) {
                $this->url_map[ $url ] = $url;
                continue;
            }

            $tmp = download_url( $url, 30 );
            if ( is_wp_error( $tmp ) ) {
                $this->errors[] = "Download failed ({$url}): " . $tmp->get_error_message();
                continue;
            }

            $filename      = sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ?? '' ) ?: 'image.jpg' );
            $attachment_id = media_handle_sideload( [ 'name' => $filename, 'tmp_name' => $tmp ], 0 );
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            @unlink( $tmp );

            if ( is_wp_error( $attachment_id ) ) {
                $this->errors[] = "Sideload failed ({$url}): " . $attachment_id->get_error_message();
                continue;
            }

            $new_url = wp_get_attachment_url( $attachment_id );
            if ( $new_url ) {
                $this->url_map[ $url ] = $new_url;
                $this->uploaded++;
            }
        }
    }

    // ── Tree Replacement ─────────────────────────────────────────────────────

    private function replace_in_tree( array $elements ): array {
        return array_map( function ( array $el ): array {
            if ( ! empty( $el['settings'] ) ) {
                $el = $this->replace_in_element( $el );
            }
            if ( ! empty( $el['elements'] ) ) {
                $el['elements'] = $this->replace_in_tree( $el['elements'] );
            }
            return $el;
        }, $elements );
    }

    /**
     * Replace URLs in one element's settings.
     *
     * BUG #5: Also handles `e-svg` + `svg_content` → sideload → V4 `src` format.
     */
    private function replace_in_element( array $el ): array {
        $s = $el['settings'];

        // BUG #5: e-svg with raw svg_content.
        if ( ( $el['widgetType'] ?? '' ) === 'e-svg' && ! empty( $s['svg_content'] ) && is_string( $s['svg_content'] ) ) {
            $svg_html = $s['svg_content'];
            $hash     = md5( $svg_html );

            if ( ! isset( $this->url_map[ 'svg_content_' . $hash ] ) ) {
                $this->ensure_sideload_functions();
                $new_url = $this->sideload_raw_bytes( $svg_html, "cloner-svg-{$hash}.svg" );
                if ( $new_url ) {
                    $this->url_map[ 'svg_content_' . $hash ] = $new_url;
                }
            }

            $attachment_url = $this->url_map[ 'svg_content_' . $hash ] ?? '';
            if ( $attachment_url ) {
                $attachment_id = attachment_url_to_postid( $attachment_url );
                // Reformat as V4 e-svg src.
                unset( $s['svg_content'] );
                $s['src'] = [
                    '$$type' => 'svg-src',
                    'value'  => [
                        'id'  => $attachment_id ?: 0,
                        'url' => [ '$$type' => 'url', 'value' => $attachment_url ],
                    ],
                ];
                $this->replaced++;
            } else {
                // Fallback: convert to html widget so content isn't lost.
                $el['widgetType'] = 'html';
                $s['html']        = $svg_html;
                unset( $s['svg_content'] );
                $this->errors[] = 'e-svg sideload failed — converted to html widget as fallback.';
            }
        }

        // Standard URL replacements.
        if ( isset( $s['image']['url'] ) ) {
            $s['image']['url'] = $this->swap( $s['image']['url'] );
        }
        if ( isset( $s['background_image']['url'] ) ) {
            $s['background_image']['url'] = $this->swap( $s['background_image']['url'] );
        }
        if ( isset( $s['svg']['url'] ) ) {
            $s['svg']['url'] = $this->swap( $s['svg']['url'] );
        }

        // HTML widget: replace data-URIs embedded in src/href.
        if ( isset( $s['html'] ) && is_string( $s['html'] ) ) {
            foreach ( $this->url_map as $old => $new ) {
                if ( $old !== $new && str_contains( $s['html'], $old ) ) {
                    $s['html'] = str_replace( $old, $new, $s['html'] );
                    $this->replaced++;
                }
            }
        }

        $el['settings'] = $s;
        return $el;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function swap( string $old ): string {
        if ( isset( $this->url_map[ $old ] ) && $this->url_map[ $old ] !== $old ) {
            $this->replaced++;
            return $this->url_map[ $old ];
        }
        return $old;
    }

    private function sideload_raw_bytes( string $bytes, string $filename ): ?string {
        $tmp = wp_tempnam( $filename );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $tmp, $bytes );
        $attachment_id = media_handle_sideload( [ 'name' => sanitize_file_name( $filename ), 'tmp_name' => $tmp ], 0 );
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        @unlink( $tmp );
        if ( is_wp_error( $attachment_id ) ) {
            $this->errors[] = "Sideload failed ({$filename}): " . $attachment_id->get_error_message();
            return null;
        }
        $url = wp_get_attachment_url( $attachment_id );
        if ( $url ) $this->uploaded++;
        return $url ?: null;
    }

    private static function is_external_url( mixed $v ): bool {
        return is_string( $v )
            && ( str_starts_with( $v, 'http://' ) || str_starts_with( $v, 'https://' ) );
    }

    private function ensure_sideload_functions(): void {
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }
}
