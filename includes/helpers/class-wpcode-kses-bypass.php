<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * WPCode_Kses_Bypass — safely writes content to a wpcode_snippet post.
 *
 * WPCode's `wpcode_snippet` post type runs the standard `content_save_pre` /
 * `title_save_pre` kses filters, which strip `<style>` and inline-event code
 * from `post_content` and `post_title`. To preserve CSS/JS body unchanged
 * while still firing every `save_post` hook (which WPCode needs so it can
 * rebuild its compiled-asset cache from `post_content`), this helper
 * temporarily removes the kses filters around the `wp_update_post` call.
 *
 * After the post write, the helper explicitly purges WPCode's
 * compiled-asset cache layers — those survive `save_post` (the PHP-only
 * `save_post` hook does not always bust them) because WPCode snapshots
 * compiled output to its own keys. Without that purge, the snippet editor
 * shows the new code but the rendered HTML still embeds the old compiled
 * asset (verified on a live treets.local site: CSS showed gap:20px while
 * the snippet stored gap:30px).
 *
 * Every method returns WP_Error on failure so callers can surface the
 * reason in the MCP response instead of silently dropping the write.
 *
 * @package Novamira_AdrianV2
 * @since   1.0.0
 */

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safely writes content to a wpcode_snippet post and busts its cache.
 *
 * @since 1.0.0
 */
final class WPCode_Kses_Bypass {

	/**
	 * WPCode compiled-asset cache keys. Allow-listed so a wildcard purge
	 * never reaches an unrelated option (e.g. user-edited plugin config).
	 *
	 * @var string[]
	 */
	private static $cache_keys = array(
		'wpcode_snippets',
		'wpcode_snippets_cache',
		'wpcode_global_js_css',
		'wpcode_assets',
		'wpcode_compiled_assets',
		'wpcode_compiled_snippets',
		'wpcode_snippets_data',
		'wpcode_lib',
		'wpcode_header_scripts',
		'wpcode_footer_scripts',
		'wpcode_css_print_method',
	);

	/**
	 * Per-snippet compiled-output meta key. WPCode stores its pre-minified
	 * output here on each save so subsequent renders skip recompilation.
	 *
	 * @var string[]
	 */
	private static $per_snippet_meta = array(
		'_wpcode_compiled_code',
		'_wpcode_compiled_snippet',
	);

	/**
	 * Updates a wpcode_snippet post's title and/or content.
	 *
	 * Pipeline (steps after one another, no silent skips):
	 *   1. Validate input (id, content, runtime helpers, post type).
	 *   2. Remove kses -> wp_update_post -> restore kses (order matters:
	 *      kses_remove_filters must be paired with kses_init_filters even on
	 *      failure, otherwise every subsequent content-write on the request
	 *      would skip sanitization).
	 *   3. clean_post_cache so object caches dropped the old payload.
	 *   4. invalidate_compiled_cache so WPCode rebuilds the snapshot on the
	 *      next request.
	 *   5. If step 4 came back as a "noop" (WPCode keys all already empty)
	 *      AND the snippet meta did not exist either, surface that as a
	 *      warning WP_Error so the caller knows the bump may not be live.
	 *
	 * @param int    $snippet_id The wpcode_snippet post ID.
	 * @param string $content    New post_content (raw CSS/JS/HTML/PHP).
	 * @param string $title      New post_title (empty string = leave unchanged).
	 * @param array  $extra      Extra wp_update_post args (post_status, etc.).
	 * @return int|\WP_Error     The post ID on success, WP_Error on failure.
	 */
	public static function edit_post( int $snippet_id, string $content, string $title = '', array $extra = array() ) {
		if ( $snippet_id <= 0 ) {
			return new \WP_Error(
				'wpcode_invalid_id',
				__( 'Snippet ID must be a positive integer.', 'novamira-adrianv2' )
			);
		}
		if ( '' === trim( $content ) ) {
			return new \WP_Error(
				'wpcode_empty_content',
				__( 'Snippet content cannot be empty.', 'novamira-adrianv2' )
			);
		}
		if ( ! function_exists( 'kses_remove_filters' ) || ! function_exists( 'kses_init_filters' ) ) {
			return new \WP_Error(
				'wpcode_kses_missing',
				__( 'WordPress kses helpers are unavailable in this runtime.', 'novamira-adrianv2' )
			);
		}
		if ( ! function_exists( 'wp_update_post' ) ) {
			return new \WP_Error(
				'wpcode_wp_unavailable',
				__( 'WordPress core is not loaded into this runtime.', 'novamira-adrianv2' )
			);
		}

		$post = function_exists( 'get_post' ) ? get_post( $snippet_id ) : null;
		if ( ! $post ) {
			return new \WP_Error(
				'wpcode_snippet_not_found',
				sprintf(
					/* translators: %d: post id */
					__( 'No snippet post with ID %d.', 'novamira-adrianv2' ),
					$snippet_id
				)
			);
		}
		if ( isset( $post->post_type ) && 'wpcode_snippet' !== $post->post_type ) {
			return new \WP_Error(
				'wpcode_wrong_post_type',
				sprintf(
					/* translators: 1: post id, 2: actual post type */
					__( 'Post %1$d is a %2$s, not a wpcode_snippet.', 'novamira-adrianv2' ),
					$snippet_id,
					(string) $post->post_type
				)
			);
		}

		$update = array(
			'ID'           => $snippet_id,
			'post_content' => function_exists( 'wp_slash' ) ? wp_slash( $content ) : $content,
		);
		if ( '' !== $title ) {
			$update['post_title'] = function_exists( 'wp_slash' ) ? wp_slash( $title ) : $title;
		}
		if ( ! empty( $extra ) && is_array( $extra ) ) {
			$update = array_merge( $update, $extra );
		}

		kses_remove_filters();
		try {
			$result = wp_update_post( $update, true );
		} finally {
			// Always re-init kses, even on WP_Error / thrown hook errors,
			// otherwise every later content-write in this request skips
			// sanitization (CVE-class regression).
			kses_init_filters();
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error(
				'wpcode_update_failed',
				sprintf(
					/* translators: %d: post id */
					__( 'wp_update_post returned no value for snippet %d.', 'novamira-adrianv2' ),
					$snippet_id
				)
			);
		}

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $snippet_id );
		}

		$purge = self::invalidate_compiled_cache( $snippet_id );
		if ( is_wp_error( $purge ) && 'wpcode_purge_noop' === $purge->get_error_code() ) {
			// Not a hard failure, but worth flagging so the caller can
			// decide whether to deactivate+reactivate WPCode via admin.
			return new \WP_Error(
				'wpcode_purge_noop',
				sprintf(
					/* translators: %d: post id */
					__( 'Snippet %d was written, but WPCode cache purge made no changes; the new content may not render until WPCode is deactivated and reactivated.', 'novamira-adrianv2' ),
					$snippet_id
				)
			);
		}

		return (int) $result;
	}

	/**
	 * Purges WPCode's compiled-asset cache layers.
	 *
	 * Algorithm:
	 *   1. For each allow-listed cache key, delete_option() (database store)
	 *      AND delete_transient() (object-cache aware).
	 *   2. If a snippet id is given, also delete every per-snippet compiled
	 *      meta key so WPCode recompiles on the next request.
	 *   3. Walk wp-content/uploads/wpcode/cache/ and remove every *.cache.php
	 *      file (best-effort; per-file failure is recorded).
	 *
	 * Returns stats on success. If WPCode appears completely uninitialised
	 * (every option already empty and every transient missing), returns a
	 * WP_Error('wpcode_purge_noop') so the caller can react.
	 *
	 * @param int|null $snippet_id Optional snippet to also purge per-snippet cache.
	 * @return array|\WP_Error     Stats array on success, WP_Error on no-op.
	 */
	public static function invalidate_compiled_cache( ?int $snippet_id = null ) {
		$stats = array(
			'options_deleted'    => 0,
			'options_missing'    => 0,
			'transients_deleted' => 0,
			'transients_missing' => 0,
			'files_removed'      => 0,
			'files_failed'       => 0,
			'meta_deleted'       => 0,
			'errors'             => array(),
		);

		if ( ! function_exists( 'delete_option' ) || ! function_exists( 'delete_transient' ) ) {
			return new \WP_Error(
				'wpcode_runtime_unavailable',
				__( 'WordPress option/transient helpers are unavailable in this runtime.', 'novamira-adrianv2' )
			);
		}

		foreach ( self::$cache_keys as $key ) {
			if ( delete_option( $key ) ) {
				++$stats['options_deleted'];
			} else {
				++$stats['options_missing'];
			}
			if ( delete_transient( $key ) ) {
				++$stats['transients_deleted'];
			} else {
				++$stats['transients_missing'];
			}
		}

		if ( null !== $snippet_id && $snippet_id > 0 && function_exists( 'delete_post_meta' ) ) {
			foreach ( self::$per_snippet_meta as $meta_key ) {
				$existing = function_exists( 'get_post_meta' ) ? get_post_meta( $snippet_id, $meta_key, true ) : '';
				if ( '' !== $existing && false !== $existing ) {
					delete_post_meta( $snippet_id, $meta_key );
					++$stats['meta_deleted'];
				}
			}
		}

		self::purge_cache_files( $stats );

		$did_anything = (
			$stats['options_deleted']
			+ $stats['transients_deleted']
			+ $stats['files_removed']
			+ $stats['meta_deleted']
		);

		if ( 0 === $did_anything ) {
			return new \WP_Error(
				'wpcode_purge_noop',
				__( 'WPCode cache purge made no changes; WPCode may not be active on this site.', 'novamira-adrianv2' )
			);
		}

		return $stats;
	}

	/**
	 * Removes *.cache.php files from wp-content/uploads/wpcode/cache/.
	 *
	 * Each file removal is wrapped in try/catch + @-suppressed unlink to
	 * keep going on permission errors rather than aborting the whole walk
	 * (so a single locked file does not block the rest of the purge).
	 *
	 * @param array $stats Stats accumulator (passed by reference).
	 */
	private static function purge_cache_files( array &$stats ): void {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			$stats['errors'][] = 'wp_upload_dir missing';
			return;
		}
		$uploads = wp_upload_dir();
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return;
		}
		$dir = $uploads['basedir'] . '/wpcode/cache';
		if ( ! is_dir( $dir ) ) {
			// Not an error: many installs keep the cache subdir empty or
			// absent. Record and move on.
			$stats['errors'][] = 'cache directory absent: ' . $dir;
			return;
		}
		$iter = @opendir( $dir );
		if ( ! $iter ) {
			$stats['errors'][] = 'cache directory unreadable: ' . $dir;
			return;
		}
		while ( false !== ( $entry = readdir( $iter ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			// Strict case-insensitive match: only compiled-asset snapshots.
			if ( ! preg_match( '/\.cache\.php$/i', $entry ) ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}
			try {
				if ( @unlink( $path ) ) {
					++$stats['files_removed'];
				} else {
					++$stats['files_failed'];
					$stats['errors'][] = 'unlink failed: ' . $path;
				}
			} catch ( \Throwable $e ) {
				++$stats['files_failed'];
				$stats['errors'][] = 'unlink threw for ' . $path . ': ' . $e->getMessage();
			}
		}
		closedir( $iter );
	}
}
