<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\ClonerLabs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * ClonerLabs_Global_Styles
 *
 * BUG #2 FIX: ClonerLabs exports global colors/typography under `settings`, NOT
 * under `global_styles`. Correct paths:
 *   $cloner_data['settings']['system_colors']     → system colors
 *   $cloner_data['settings']['custom_colors']     → custom colors
 *   $cloner_data['settings']['system_typography'] → system typography
 *   $cloner_data['settings']['custom_typography'] → custom typography
 *
 * Accepts the raw `settings` sub-object from the ClonerLabs export.
 * Merges into the active Elementor kit using an upsert strategy (match by _id).
 *
 * FIX #12 (Google Fonts): Collects font families used in typography entries
 * and returns them in `fonts_to_verify` so the caller can warn the user.
 *
 * @package Novamira_AdrianV2
 * @since   1.3.0
 */
final class ClonerLabs_Global_Styles {

    /**
     * Apply ClonerLabs `settings` into the active Elementor kit.
     *
     * @param  array $raw_settings ClonerLabs `data['settings']` sub-object.
     * @return array{colors_applied:int, typography_applied:int, fonts_to_verify:string[], error?:string}
     */
    public static function apply( array $raw_settings ): array {
        $kit_id = (int) get_option( 'elementor_active_kit' );
        if ( ! $kit_id ) {
            return [
                'colors_applied'     => 0,
                'typography_applied' => 0,
                'fonts_to_verify'    => [],
                'error'              => 'No active Elementor kit found.',
            ];
        }

        $kit_settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
        if ( ! is_array( $kit_settings ) ) {
            $kit_settings = [];
        }

        // BUG #2: Correct keys from ClonerLabs export format.
        $all_colors = array_merge(
            $raw_settings['system_colors']  ?? [],
            $raw_settings['custom_colors']  ?? []
        );
        $all_typo   = array_merge(
            $raw_settings['system_typography']  ?? [],
            $raw_settings['custom_typography']  ?? []
        );

        $colors_applied     = self::merge_colors( $all_colors, $kit_settings );
        [ $typo_applied, $fonts_to_verify ] = self::merge_typography( $all_typo, $kit_settings );

        update_post_meta( $kit_id, '_elementor_page_settings', $kit_settings );
        delete_post_meta( $kit_id, '_elementor_css' );

        return [
            'colors_applied'     => $colors_applied,
            'typography_applied' => $typo_applied,
            'fonts_to_verify'    => $fonts_to_verify,
        ];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function merge_colors( array $entries, array &$kit_settings ): int {
        if ( empty( $entries ) ) return 0;

        $kit_colors = $kit_settings['system_colors'] ?? [];
        $applied    = 0;

        foreach ( $entries as $entry ) {
            $id    = trim( (string) ( $entry['_id']   ?? '' ) );
            $title = trim( (string) ( $entry['title'] ?? $id ) );
            $color = trim( (string) ( $entry['color'] ?? '' ) );
            if ( $id === '' || $color === '' ) continue;

            $found = false;
            foreach ( $kit_colors as &$existing ) {
                if ( ( $existing['_id'] ?? '' ) === $id ) {
                    $existing['color'] = $color;
                    if ( $title !== '' ) $existing['title'] = $title;
                    $found = true;
                    break;
                }
            }
            unset( $existing );

            if ( ! $found ) {
                $kit_colors[] = [ '_id' => $id, 'title' => $title ?: $id, 'color' => $color ];
            }
            $applied++;
        }

        $kit_settings['system_colors'] = $kit_colors;
        return $applied;
    }

    /**
     * @return array{int, string[]} [count_applied, fonts_to_verify]
     */
    private static function merge_typography( array $entries, array &$kit_settings ): array {
        if ( empty( $entries ) ) return [ 0, [] ];

        $kit_typo        = $kit_settings['system_typography'] ?? [];
        $applied         = 0;
        $fonts_to_verify = [];

        foreach ( $entries as $entry ) {
            $id    = trim( (string) ( $entry['_id']   ?? '' ) );
            $title = trim( (string) ( $entry['title'] ?? $id ) );
            if ( $id === '' ) continue;

            $typo_value = [ '_id' => $id, 'title' => $title ?: $id ];
            foreach ( [
                'font_family' => 'typography_font_family',
                'font_weight' => 'typography_font_weight',
                'font_size'   => 'typography_font_size',
                'line_height' => 'typography_line_height',
            ] as $src => $dest ) {
                if ( isset( $entry[ $src ] ) && $entry[ $src ] !== '' ) {
                    $typo_value[ $dest ] = $entry[ $src ];
                }
            }

            // FIX #12: Collect font families for the caller to warn about.
            if ( ! empty( $typo_value['typography_font_family'] ) ) {
                $fonts_to_verify[] = $typo_value['typography_font_family'];
            }

            $found = false;
            foreach ( $kit_typo as &$existing ) {
                if ( ( $existing['_id'] ?? '' ) === $id ) {
                    $existing = array_merge( $existing, $typo_value );
                    $found    = true;
                    break;
                }
            }
            unset( $existing );

            if ( ! $found ) $kit_typo[] = $typo_value;
            $applied++;
        }

        $kit_settings['system_typography'] = $kit_typo;
        return [ $applied, array_unique( $fonts_to_verify ) ];
    }
}
