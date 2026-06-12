<?php
/**
 * Novamira Ability: adrians-list-snippets
 *
 * Ability-Name:  novamira/adrians-list-snippets
 * Version:       1.0.0
 *
 * Listet alle vorhandenen WPCode-Snippets auf.
 *
 * Parameter (JSON):
 *   {
 *     "filter_type":   string  optional  - "css"|"js"|"html"|"php" (kein Filter = alle)
 *     "filter_tag":    string  optional  - Tag-Slug-Filter
 *     "limit":         int     optional  - max. Anzahl (Default: 50)
 *     "include_code":  bool    optional  - true = Code-Inhalt mitliefern (Default: false)
 *   }
 *
 * Rückgabe:
 *   { "success": true, "total": int, "snippets": [
 *     { "id": int, "title": str, "slug": str, "type": str, "location": str,
 *       "active": bool, "priority": int, "tags": [...], "code": str|null }
 *   ]}
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function novamira_adrians_list_snippets( array $params ): array {
    $filter_type  = isset( $params['filter_type'] )  ? sanitize_key( $params['filter_type'] )  : '';
    $filter_tag   = isset( $params['filter_tag'] )   ? sanitize_text_field( $params['filter_tag'] ) : '';
    $limit        = isset( $params['limit'] )        ? max( 1, min( 200, absint( $params['limit'] ) ) ) : 50;
    $include_code = ! empty( $params['include_code'] );

    if ( ! post_type_exists( 'wpcode_snippet' ) ) {
        return [ 'success' => false, 'message' => 'WPCode nicht aktiv.' ];
    }

    $query_args = [
        'post_type'      => 'wpcode_snippet',
        'post_status'    => [ 'publish', 'draft' ],
        'posts_per_page' => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => false,
    ];

    if ( $filter_tag ) {
        $query_args['tax_query'] = [ [
            'taxonomy' => 'wpcode_tag',
            'field'    => 'slug',
            'terms'    => $filter_tag,
        ] ];
    }

    $q = new WP_Query( $query_args );

    $snippets = [];
    foreach ( $q->posts as $post ) {
        $type     = get_post_meta( $post->ID, '_wpcode_snippet_type',         true ) ?: 'unknown';
        $location = get_post_meta( $post->ID, '_wpcode_auto_insert_location', true ) ?: '';
        $priority = (int) ( get_post_meta( $post->ID, '_wpcode_snippet_priority', true ) ?: 10 );

        if ( $filter_type && $type !== $filter_type ) {
            continue;
        }

        $tags = wp_get_post_terms( $post->ID, 'wpcode_tag', [ 'fields' => 'names' ] );

        $entry = [
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'slug'     => $post->post_name,
            'type'     => $type,
            'location' => $location,
            'active'   => $post->post_status === 'publish',
            'priority' => $priority,
            'tags'     => is_array( $tags ) ? $tags : [],
        ];

        if ( $include_code ) {
            $entry['code'] = $post->post_content;
        }

        $snippets[] = $entry;
    }

    return [
        'success'  => true,
        'total'    => count( $snippets ),
        'snippets' => $snippets,
    ];
}
