<?php
declare(strict_types=1);

/**
 * Pipeline State Manager — persistent state tracking for external pipelines.
 *
 * Saves pipeline state snapshots from the V4-Pipeline (Node.js) and
 * site-clone-to-v3 (TypeScript) tools to a dedicated, protected directory
 * on the WordPress filesystem so pipeline runs can be resumed, audited,
 * and cross-referenced across the integrated ecosystem.
 *
 * States directory:  wp-content/novamira-states/
 * State file format:  pipeline-{id}.json
 *
 * Registered as MCP-Ability: novamira-adrianv2/pipeline-state
 *   Sub-abilities: save | load | cleanup | list
 *
 * @package Novamira_AdrianV2
 * @since   1.11.0
 */

namespace Novamira\AdrianV2\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pipeline_State_Manager {

	/**
	 * Base directory for pipeline state files.
	 *
	 * @var string
	 */
	private static string $state_dir = '';

	/**
	 * Get (and lazily create) the states directory.
	 *
	 * @return string Absolute path to wp-content/novamira-states/
	 */
	private static function state_dir(): string {
		if ( self::$state_dir !== '' ) {
			return self::$state_dir;
		}

		self::$state_dir = WP_CONTENT_DIR . '/novamira-states';

		if ( ! file_exists( self::$state_dir ) ) {
			wp_mkdir_p( self::$state_dir );
		}

		// Protect the directory with .htaccess (Apache 2.2 + 2.4).
		$htaccess = self::$state_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents(
				$htaccess,
				"# Novamira Pipeline State Manager — deny public access.\n" .
				"<IfModule mod_authz_core.c>\n" .
				"  Require all denied\n" .
				"</IfModule>\n" .
				"<IfModule !mod_authz_core.c>\n" .
				"  Order deny,allow\n" .
				"  Deny from all\n" .
				"</IfModule>\n"
			);
		}

		// Protect the directory with index.php (Nginx / IIS).
		$index = self::$state_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '<?php // Silence is golden.' . "\n" );
		}

		return self::$state_dir;
	}

	/**
	 * Build the absolute file path for a given pipeline id.
	 *
	 * Sanitises the pipeline id to prevent directory traversal.
	 *
	 * @param string $pipeline_id
	 * @return string Absolute path to the state file.
	 */
	private static function state_file_path( string $pipeline_id ): string {
		// Only allow alphanumeric, hyphens, and underscores.
		$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '_', $pipeline_id );
		if ( $sanitized === '' || $sanitized !== $pipeline_id ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Pipeline id must be non-empty [a-zA-Z0-9_-]. Received: "%s"',
					esc_html( $pipeline_id )
				)
			);
		}

		return self::state_dir() . '/pipeline-' . $sanitized . '.json';
	}

	// ────────────────────────────────────────────────────
	// Save
	// ────────────────────────────────────────────────────

	/**
	 * Persist a pipeline state snapshot to disk.
	 *
	 * @param string $pipeline_id Unique pipeline identifier.
	 * @param array  $state       Pipeline state object.
	 * @return array{success: bool, pipeline_id: string, state_file: string, timestamp: string}
	 */
	public static function save_state( string $pipeline_id, array $state ): array {
		$file_path = self::state_file_path( $pipeline_id );

		// Enrich with server-side metadata.
		$state['_server_timestamp'] = current_time( 'c' );
		if ( ! isset( $state['_plugin_version'] ) ) {
			$state['_plugin_version'] = NOVAMIRA_ADRIANV2_VERSION;
		}

		$json = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return [
				'success'     => false,
				'pipeline_id' => $pipeline_id,
				'error'       => 'json_encode failed: ' . json_last_error_msg(),
				'timestamp'   => current_time( 'c' ),
			];
		}

		$written = file_put_contents( $file_path, $json, LOCK_EX );

		return [
			'success'     => false !== $written,
			'pipeline_id' => $pipeline_id,
			'state_file'  => $file_path,
			'bytes'       => false !== $written ? $written : 0,
			'timestamp'   => current_time( 'c' ),
		];
	}

	// ────────────────────────────────────────────────────
	// Load
	// ────────────────────────────────────────────────────

	/**
	 * Load a previously saved pipeline state.
	 *
	 * @param string $pipeline_id Unique pipeline identifier.
	 * @return array{success: bool, pipeline_id: string, state?: array, error?: string, timestamp: string}
	 */
	public static function load_state( string $pipeline_id ): array {
		$file_path = self::state_file_path( $pipeline_id );

		if ( ! file_exists( $file_path ) ) {
			return [
				'success'     => false,
				'pipeline_id' => $pipeline_id,
				'error'       => 'State file not found for this pipeline id.',
				'timestamp'   => current_time( 'c' ),
			];
		}

		$raw = file_get_contents( $file_path );
		if ( false === $raw ) {
			return [
				'success'     => false,
				'pipeline_id' => $pipeline_id,
				'error'       => 'Could not read state file (permissions?).',
				'timestamp'   => current_time( 'c' ),
			];
		}

		$state = json_decode( $raw, true );
		if ( null === $state && JSON_ERROR_NONE !== json_last_error() ) {
			return [
				'success'     => false,
				'pipeline_id' => $pipeline_id,
				'error'       => 'Invalid JSON in state file: ' . json_last_error_msg(),
				'timestamp'   => current_time( 'c' ),
			];
		}

		// Strip server-side enrichment before returning to caller.
		unset( $state['_server_timestamp'], $state['_plugin_version'] );

		return [
			'success'     => true,
			'pipeline_id' => $pipeline_id,
			'state'       => $state,
			'timestamp'   => current_time( 'c' ),
		];
	}

	// ────────────────────────────────────────────────────
	// Cleanup
	// ────────────────────────────────────────────────────

	/**
	 * Delete pipeline states older than a given age.
	 *
	 * @param int $max_age_days Maximum age in days (default 7).
	 * @return array{success: bool, cleaned: int, files: string[], timestamp: string}
	 */
	public static function cleanup_old_states( int $max_age_days = 7 ): array {
		if ( $max_age_days < 1 ) {
			$max_age_days = 7;
		}

		$state_dir = self::state_dir();
		$cutoff    = time() - ( $max_age_days * DAY_IN_SECONDS );
		$cleaned   = [];
		$files     = glob( $state_dir . '/pipeline-*.json' );

		if ( ! is_array( $files ) ) {
			return [
				'success'   => true,
				'cleaned'   => 0,
				'files'     => [],
				'timestamp' => current_time( 'c' ),
			];
		}

		foreach ( $files as $file ) {
			$mtime = filemtime( $file );
			if ( false !== $mtime && $mtime < $cutoff ) {
				$filename = basename( $file );
				if ( unlink( $file ) ) {
					$cleaned[] = $filename;
				}
			}
		}

		return [
			'success'   => true,
			'cleaned'   => count( $cleaned ),
			'files'     => $cleaned,
			'timestamp' => current_time( 'c' ),
		];
	}

	// ────────────────────────────────────────────────────
	// List
	// ────────────────────────────────────────────────────

	/**
	 * List all active pipeline states with metadata.
	 *
	 * @return array{
	 *   success: bool,
	 *   pipelines: array<int, array{id: string, file: string, size: int, modified: string}>,
	 *   count: int,
	 *   timestamp: string
	 * }
	 */
	public static function list_active_pipelines(): array {
		$state_dir = self::state_dir();
		$files     = glob( $state_dir . '/pipeline-*.json' );

		if ( ! is_array( $files ) ) {
			return [
				'success'    => true,
				'pipelines'  => [],
				'count'      => 0,
				'timestamp'  => current_time( 'c' ),
			];
		}

		$pipelines = [];
		foreach ( $files as $file ) {
			$filename = basename( $file, '.json' );
			// Strip "pipeline-" prefix to recover the id.
			$pipeline_id = substr( $filename, 9 ); // strlen('pipeline-') === 9
			if ( '' === $pipeline_id ) {
				continue;
			}

			$stat   = @stat( $file );
			$size   = is_array( $stat ) ? ( $stat['size'] ?? 0 ) : 0;
			$mtime  = is_array( $stat ) ? ( $stat['mtime'] ?? 0 ) : 0;
			$pipelines[] = [
				'id'       => $pipeline_id,
				'file'     => $file,
				'size'     => $size,
				'modified' => $mtime > 0 ? gmdate( 'c', $mtime ) : null,
			];
		}

		// Sort by modification time, newest first.
		usort( $pipelines, function ( array $a, array $b ): int {
			return strcmp( (string) ( $b['modified'] ?? '' ), (string) ( $a['modified'] ?? '' ) );
		} );

		return [
			'success'   => true,
			'pipelines' => $pipelines,
			'count'     => count( $pipelines ),
			'timestamp' => current_time( 'c' ),
		];
	}

	// ────────────────────────────────────────────────────
	// MCP Ability Registration
	// ────────────────────────────────────────────────────

	/**
	 * Register the pipeline-state MCP ability.
	 *
	 * Sub-abilities are dispatched via the "action" parameter:
	 *   save   → save_state(pipeline_id, state)
	 *   load   → load_state(pipeline_id)
	 *   cleanup → cleanup_old_states(max_age_days?)
	 *   list   → list_active_pipelines()
	 */
	public static function register(): void {
		wp_register_ability( 'novamira-adrianv2/pipeline-state', [
			'label'               => 'Pipeline State Manager',
			'description'         => 'Persist and retrieve pipeline state snapshots for the ' .
			                         'Framer-V4-Pipeline and site-clone-to-v3 tools. ' .
			                         'Sub-abilities: save, load, cleanup, list.',
			'category'            => 'adrianv2-utilities',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'action'      => [
						'type'        => 'string',
						'description' => 'Operation: save, load, cleanup, or list.',
						'enum'        => [ 'save', 'load', 'cleanup', 'list' ],
					],
					'pipeline_id' => [
						'type'        => 'string',
						'description' => 'Unique pipeline identifier (required for save/load).',
					],
					'state'       => [
						'type'        => 'object',
						'description' => 'Pipeline state object (required for save).',
					],
					'max_age_days' => [
						'type'        => 'integer',
						'description' => 'Maximum age in days for cleanup (default 7).',
						'default'     => 7,
						'minimum'     => 1,
					],
				],
				'required'   => [ 'action' ],
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'success'     => [ 'type' => 'boolean' ],
					'pipeline_id' => [ 'type' => 'string' ],
					'state'       => [ 'type' => 'object' ],
					'error'       => [ 'type' => 'string' ],
					'cleaned'     => [ 'type' => 'integer' ],
					'pipelines'   => [ 'type' => 'array' ],
					'count'       => [ 'type' => 'integer' ],
					'timestamp'   => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => [
				'show_in_rest' => true,
				'mcp'          => [ 'public' => true ],
				'annotations'  => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				],
			],
		] );
	}

	/**
	 * MCP-Ability execute callback — dispatches to the correct sub-operation.
	 *
	 * @param array|null $input Input from MCP call.
	 * @return array
	 */
	public static function execute( $input = null ): array {
		if ( ! is_array( $input ) || empty( $input['action'] ) ) {
			return [
				'success'   => false,
				'error'     => 'Missing required parameter: "action". Must be one of: save, load, cleanup, list.',
				'timestamp' => current_time( 'c' ),
			];
		}

		return match ( $input['action'] ) {
			'save'    => self::execute_save( $input ),
			'load'    => self::execute_load( $input ),
			'cleanup' => self::execute_cleanup( $input ),
			'list'    => self::execute_list(),
			default   => [
				'success'   => false,
				'error'     => sprintf(
					'Unknown action "%s". Must be one of: save, load, cleanup, list.',
					esc_html( $input['action'] )
				),
				'timestamp' => current_time( 'c' ),
			],
		};
	}

	/**
	 * Execute the "save" sub-ability.
	 */
	private static function execute_save( array $input ): array {
		if ( empty( $input['pipeline_id'] ) || ! is_string( $input['pipeline_id'] ) ) {
			return [
				'success'   => false,
				'error'     => 'Missing required parameter: "pipeline_id" (non-empty string).',
				'timestamp' => current_time( 'c' ),
			];
		}

		if ( ! isset( $input['state'] ) || ! is_array( $input['state'] ) ) {
			return [
				'success'   => false,
				'error'     => 'Missing required parameter: "state" (object).',
				'timestamp' => current_time( 'c' ),
			];
		}

		try {
			return self::save_state( $input['pipeline_id'], $input['state'] );
		} catch ( \Throwable $e ) {
			return [
				'success'     => false,
				'pipeline_id' => $input['pipeline_id'] ?? 'unknown',
				'error'       => $e->getMessage(),
				'timestamp'   => current_time( 'c' ),
			];
		}
	}

	/**
	 * Execute the "load" sub-ability.
	 */
	private static function execute_load( array $input ): array {
		if ( empty( $input['pipeline_id'] ) || ! is_string( $input['pipeline_id'] ) ) {
			return [
				'success'   => false,
				'error'     => 'Missing required parameter: "pipeline_id" (non-empty string).',
				'timestamp' => current_time( 'c' ),
			];
		}

		try {
			return self::load_state( $input['pipeline_id'] );
		} catch ( \Throwable $e ) {
			return [
				'success'     => false,
				'pipeline_id' => $input['pipeline_id'],
				'error'       => $e->getMessage(),
				'timestamp'   => current_time( 'c' ),
			];
		}
	}

	/**
	 * Execute the "cleanup" sub-ability.
	 */
	private static function execute_cleanup( array $input ): array {
		$max_age_days = isset( $input['max_age_days'] ) && is_int( $input['max_age_days'] )
			? $input['max_age_days']
			: 7;

		try {
			return self::cleanup_old_states( $max_age_days );
		} catch ( \Throwable $e ) {
			return [
				'success'   => false,
				'error'     => $e->getMessage(),
				'timestamp' => current_time( 'c' ),
			];
		}
	}

	/**
	 * Execute the "list" sub-ability.
	 */
	private static function execute_list(): array {
		try {
			return self::list_active_pipelines();
		} catch ( \Throwable $e ) {
			return [
				'success'   => false,
				'error'     => $e->getMessage(),
				'timestamp' => current_time( 'c' ),
			];
		}
	}
}
