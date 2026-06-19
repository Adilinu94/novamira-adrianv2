<?php
/**
* Inject Elementor _elementor_data into a page via WP-CLI. Host-agnostic.
* Run from (or with --path pointing at) the site's webroot:
* wp eval-file inject.php <post_id> <json_path> [<elementor_version>] [<wp_page_template>]
*
* <elementor_version>: pass "$(wp plugin get elementor --field=version)" so the saved
* version matches the site (avoids the editor "regenerate" prompt).
* <wp_page_template>: elementor_header_footer (full width WITH theme nav/footer, default)
* or elementor_canvas (blank).
*
* Getting <json_path> onto the server (portable, works even when SFTP/scp is chrooted):
* ssh host "cat > ~/page.json" < local.json # then pass ~/page.json
* Locally (DDEV/LocalWP/etc.) just write the file and pass its path.
*/
$id = intval( $args[0] );
$path = $args[1];
$ver = isset( $args[2] ) ? $args[2] : '3.0.0';
$tpl = isset( $args[3] ) ? $args[3] : 'elementor_header_footer';
if ( ! $id || ! get_post( $id ) ) { WP_CLI::error( "No post for id=$id" ); }
if ( ! is_readable( $path ) ) { WP_CLI::error( "Cannot read JSON at $path" ); }
$data = json_decode( file_get_contents( $path ), true );
if ( ! is_array( $data ) ) { WP_CLI::error( "JSON at $path did not decode to an array" ); }
update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
update_post_meta( $id, '_elementor_edit_mode', 'builder' );
update_post_meta( $id, '_elementor_template_type', 'wp-page' );
update_post_meta( $id, '_wp_page_template', $tpl );
update_post_meta( $id, '_elementor_version', $ver );
// --- Optional SEO meta (uncomment / set per page) ---
// Rank Math:
// update_post_meta( $id, 'rank_math_focus_keyword', 'FOCUS KEYWORD' );
// update_post_meta( $id, 'rank_math_title', 'TITLE TAG' );
// update_post_meta( $id, 'rank_math_description', 'META DESCRIPTION' );
// update_post_meta( $id, 'rank_math_robots', array( 'noindex' ) ); // staging only
// Yoast:
// update_post_meta( $id, '_yoast_wpseo_title', 'TITLE TAG' );
// update_post_meta( $id, '_yoast_wpseo_metadesc', 'META DESCRIPTION' );
// update_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', '1' ); // staging only
echo "OK id=$id sections=" . count( $data ) . " tpl=$tpl ver=$ver\n";
// Then run: wp elementor flush-css
