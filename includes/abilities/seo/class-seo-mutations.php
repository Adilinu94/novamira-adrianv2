<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO mutation abilities: set-rankmath-meta, set-aioseo-meta
 *
 * Write SEO meta for Rank Math and AIOSEO to a specific post.
 * Both support dry_run:true (default) to preview what would be written.
 *
 * @since 1.8.0
 */
final class Seo_Mutations {

	// =========================================================================
	// set-rankmath-meta
	// =========================================================================

	public static function register_rankmath(): void {
		wp_register_ability(
			'novamira-adrianv2/set-rankmath-meta',
			[
				'label'       => 'Set Rank Math Meta',
				'description' => 'Write Rank Math SEO meta to a post (title, description, focus keyword, canonical, robots directives). dry_run:true (default) — returns what would be written without saving.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'post_id' ],
					'properties' => [
						'post_id'         => [ 'type' => 'integer' ],
						'title'           => [ 'type' => 'string', 'description' => 'SEO title (supports %%title%%, %%sitename%% tokens).' ],
						'description'     => [ 'type' => 'string', 'description' => 'Meta description (max ~155 chars recommended).' ],
						'focus_keyword'   => [ 'type' => 'string', 'description' => 'Primary focus keyword.' ],
						'canonical'       => [ 'type' => 'string', 'description' => 'Override canonical URL.' ],
						'robots_noindex'  => [ 'type' => 'boolean', 'description' => 'Set robots noindex.' ],
						'robots_nofollow' => [ 'type' => 'boolean', 'description' => 'Set robots nofollow.' ],
						'dry_run'         => [ 'type' => 'boolean', 'default' => true ],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'  => [ 'type' => 'boolean' ],
						'dry_run'  => [ 'type' => 'boolean' ],
						'post_id'  => [ 'type' => 'integer' ],
						'written'  => [ 'type' => 'object' ],
						'errors'   => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_rankmath' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta' => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}

	public static function execute_rankmath( $input = null ): array {
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$dry_run  = $input['dry_run'] ?? true;
		$errors   = [];

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return [ 'success' => false, 'dry_run' => $dry_run, 'post_id' => $post_id,
			         'written' => [], 'errors' => [ "Post {$post_id} not found." ] ];
		}

		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			$errors[] = 'Rank Math is not active.';
		}

		$meta_map = [];

		if ( isset( $input['title'] ) && '' !== $input['title'] ) {
			$meta_map['rank_math_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['description'] ) && '' !== $input['description'] ) {
			$meta_map['rank_math_description'] = sanitize_textarea_field( $input['description'] );
		}
		if ( isset( $input['focus_keyword'] ) && '' !== $input['focus_keyword'] ) {
			$meta_map['rank_math_focus_keyword'] = sanitize_text_field( $input['focus_keyword'] );
		}
		if ( isset( $input['canonical'] ) && '' !== $input['canonical'] ) {
			$meta_map['rank_math_canonical_url'] = esc_url_raw( $input['canonical'] );
		}
		if ( isset( $input['robots_noindex'] ) ) {
			// Rank Math stores robots as a comma-separated string in rank_math_robots.
			$existing_robots = explode( ',', get_post_meta( $post_id, 'rank_math_robots', true ) ?: '' );
			$existing_robots = array_filter( array_map( 'trim', $existing_robots ) );

			if ( $input['robots_noindex'] ) {
				$existing_robots[] = 'noindex';
			} else {
				$existing_robots = array_diff( $existing_robots, [ 'noindex' ] );
			}
			if ( isset( $input['robots_nofollow'] ) ) {
				if ( $input['robots_nofollow'] ) {
					$existing_robots[] = 'nofollow';
				} else {
					$existing_robots = array_diff( $existing_robots, [ 'nofollow' ] );
				}
			}
			$meta_map['rank_math_robots'] = implode( ', ', array_unique( $existing_robots ) );
		}

		if ( empty( $meta_map ) ) {
			$errors[] = 'No meta fields provided — nothing to write.';
		}

		if ( empty( $errors ) && ! $dry_run ) {
			foreach ( $meta_map as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		return [
			'success' => empty( $errors ),
			'dry_run' => $dry_run,
			'post_id' => $post_id,
			'written' => $meta_map,
			'errors'  => $errors,
		];
	}

	// =========================================================================
	// set-aioseo-meta
	// =========================================================================

	public static function register_aioseo(): void {
		wp_register_ability(
			'novamira-adrianv2/set-aioseo-meta',
			[
				'label'       => 'Set AIOSEO Meta',
				'description' => 'Write All in One SEO meta to a post (title, description, keywords, canonical, robots). dry_run:true (default) — returns what would be written without saving.',
				'category'    => 'novamira-adrianv2',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'post_id' ],
					'properties' => [
						'post_id'         => [ 'type' => 'integer' ],
						'title'           => [ 'type' => 'string' ],
						'description'     => [ 'type' => 'string' ],
						'keywords'        => [ 'type' => 'string', 'description' => 'Comma-separated keywords.' ],
						'canonical'       => [ 'type' => 'string' ],
						'robots_noindex'  => [ 'type' => 'boolean' ],
						'robots_nofollow' => [ 'type' => 'boolean' ],
						'og_title'        => [ 'type' => 'string', 'description' => 'Override OG title.' ],
						'og_description'  => [ 'type' => 'string', 'description' => 'Override OG description.' ],
						'dry_run'         => [ 'type' => 'boolean', 'default' => true ],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
						'dry_run' => [ 'type' => 'boolean' ],
						'post_id' => [ 'type' => 'integer' ],
						'written' => [ 'type' => 'object' ],
						'errors'  => [ 'type' => 'array' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute_aioseo' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta' => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
				],
			]
		);
	}

	public static function execute_aioseo( $input = null ): array {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$dry_run = $input['dry_run'] ?? true;
		$errors  = [];

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return [ 'success' => false, 'dry_run' => $dry_run, 'post_id' => $post_id,
			         'written' => [], 'errors' => [ "Post {$post_id} not found." ] ];
		}

		if ( ! defined( 'AIOSEO_VERSION' ) ) {
			$errors[] = 'All in One SEO is not active.';
		}

		// AIOSEO stores per-post data as a JSON blob in the aioseo_posts table.
		// For broad compat we write to the post meta keys AIOSEO reads as fallback.
		$meta_map = [];

		if ( isset( $input['title'] ) && '' !== $input['title'] ) {
			$meta_map['_aioseo_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['description'] ) && '' !== $input['description'] ) {
			$meta_map['_aioseo_description'] = sanitize_textarea_field( $input['description'] );
		}
		if ( isset( $input['keywords'] ) && '' !== $input['keywords'] ) {
			$meta_map['_aioseo_keywords'] = sanitize_text_field( $input['keywords'] );
		}
		if ( isset( $input['canonical'] ) && '' !== $input['canonical'] ) {
			$meta_map['_aioseo_canonical_url'] = esc_url_raw( $input['canonical'] );
		}
		if ( isset( $input['og_title'] ) && '' !== $input['og_title'] ) {
			$meta_map['_aioseo_og_title'] = sanitize_text_field( $input['og_title'] );
		}
		if ( isset( $input['og_description'] ) && '' !== $input['og_description'] ) {
			$meta_map['_aioseo_og_description'] = sanitize_textarea_field( $input['og_description'] );
		}
		if ( isset( $input['robots_noindex'] ) || isset( $input['robots_nofollow'] ) ) {
			$robots = [];
			if ( ! empty( $input['robots_noindex'] ) )  { $robots[] = 'noindex'; }
			if ( ! empty( $input['robots_nofollow'] ) ) { $robots[] = 'nofollow'; }
			$meta_map['_aioseo_robots_default'] = empty( $robots ) ? '1' : '0';
			$meta_map['_aioseo_robots']          = implode( ',', $robots );
		}

		if ( empty( $meta_map ) ) {
			$errors[] = 'No meta fields provided — nothing to write.';
		}

		if ( empty( $errors ) && ! $dry_run ) {
			foreach ( $meta_map as $key => $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		return [
			'success' => empty( $errors ),
			'dry_run' => $dry_run,
			'post_id' => $post_id,
			'written' => $meta_map,
			'errors'  => $errors,
		];
	}
}
