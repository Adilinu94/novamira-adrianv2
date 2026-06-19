<?php

namespace Elementor\Core\Files\CSS {
    if (!class_exists('Elementor\Core\Files\CSS\Post')) {
        class Post {
            private $id;
            public function __construct($id) { $this->id = $id; }
            public static function create($id) { return new self($id); }
            public function delete() {
                $GLOBALS['_test_clean_post_cache'][] = $this->id;
            }
        }
    }
}

namespace Elementor\Core\Files {
    if (!class_exists('Elementor\Core\Files\Manager')) {
        class Manager {
            public function clear_cache() {
                $GLOBALS['_test_files_manager_clear_calls'] = ($GLOBALS['_test_files_manager_clear_calls'] ?? 0) + 1;
                return true;
            }
        }
    }
}

namespace Elementor {
    if (!class_exists('Elementor\Plugin')) {
        class Plugin {
            public static $instance;
            public static function instance(): self {
                if (!self::$instance) {
                    self::$instance = new self();
                }
                return self::$instance;
            }
            public $documents;
            public $files_manager;
            public $kits_manager;
            public $experiments;
            public function __construct() {
                $this->documents = new class {
                    public function get($id) {
                        $d = $GLOBALS['_test_elementor_docs'][$id] ?? null;
                        if (null === $d) {
                            $GLOBALS['_test_elementor_docs_missing'] = $GLOBALS['_test_elementor_docs_missing'] ?? array();
                            $GLOBALS['_test_elementor_docs_missing'][] = (int) $id;
                            return null;
                        }
                        return new class($d, $id) {
                            private $d;
                            private $id;
                            public function __construct($d, $i) { $this->d = $d; $this->id = $i; }
                            public function get_elements_data() { return $this->d; }
                            public function is_built_with_elementor(): bool { return true; }
                            public function update_json_meta($k, $v) {
                                $GLOBALS['_test_elementor_update_json_meta'] = $GLOBALS['_test_elementor_update_json_meta'] ?? array();
                                $GLOBALS['_test_elementor_update_json_meta'][] = array('k' => $k, 'v' => $v);
                                // Persist the new tree back into the docs map so
                                // subsequent read-after-write via ->get_elements_data()
                                // sees the post-mutation state, not the seed.
                                $GLOBALS['_test_elementor_docs'][(int) $this->id] = $v;
                                return true;
                            }
                        };
                    }
                };
                if (class_exists('Elementor\Core\Files\Manager')) {
                    $this->files_manager = new \Elementor\Core\Files\Manager();
                }
                // experiments stub: returns a simple object with is_feature_active.
                $this->experiments = new class {
                    public function is_feature_active(string $name): bool {
                        return $GLOBALS['_test_experiments'][$name] ?? false;
                    }
                };
                // kits_manager stub: returns whatever _test_kits_manager_active_id
                // is seeded with, so the production code's kit_id branch can be
                // exercised when tests need it.
                $this->kits_manager = new class {
                    public function get_active_id() {
                        return $GLOBALS['_test_kits_manager_active_id'] ?? null;
                    }
                };
            }
        }
    }
}

namespace {
if (!isset(\Elementor\Plugin::$instance)) {
        \Elementor\Plugin::$instance = new \Elementor\Plugin();
    }

    // ── Term helpers (used by WPCode fake) ──
    if (! function_exists( 'wp_set_post_terms' ) ) {
        function wp_set_post_terms( $post_id, $terms, $taxonomy = '', $append = false ) {
            $GLOBALS['_wpcode_terms']                       = $GLOBALS['_wpcode_terms'] ?? array();
            $GLOBALS['_wpcode_terms'][ $post_id ]           = $GLOBALS['_wpcode_terms'][ $post_id ] ?? array();
            $GLOBALS['_wpcode_terms'][ $post_id ][ $taxonomy ] = array_map( 'strval', (array) $terms );
            return (array) $terms;
        }
    }
    if (! function_exists( 'wp_get_post_terms' ) ) {
        function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
            $GLOBALS['_wpcode_terms'] = $GLOBALS['_wpcode_terms'] ?? array();
            if ( ! isset( $GLOBALS['_wpcode_terms'][ $post_id ][ $taxonomy ] ) ) {
                return array();
            }
            $slugs = $GLOBALS['_wpcode_terms'][ $post_id ][ $taxonomy ];
            $out   = array();
            foreach ( $slugs as $slug ) {
                $out[] = (object) array(
                    'slug' => $slug,
                    'name' => $slug,
                );
            }
            return $out;
        }
    }

    // ── Delete post (used by delete-wpcode-snippet) ──
    if (! function_exists( 'wp_delete_post' ) ) {
        function wp_delete_post( $post_id, $force = false ) {
            unset( $GLOBALS['_test_posts'][ (int) $post_id ] );
            unset( $GLOBALS['_wpcode_storage'][ (int) $post_id ] );
            unset( $GLOBALS['_wpcode_terms'][ (int) $post_id ] );
            unset( $GLOBALS['_wpcode_meta'][ (int) $post_id ] );
            return (object) array( 'ID' => (int) $post_id );
        }
    }

    // ── get_post_meta: per-call value override ──
    if (! function_exists( '_test_meta_set' ) ) {
        function _test_meta_set( int $post_id, string $key, $value ) {
            $GLOBALS['_wpcode_meta']                                  = $GLOBALS['_wpcode_meta'] ?? array();
            $GLOBALS['_wpcode_meta'][ $post_id ]                       = $GLOBALS['_wpcode_meta'][ $post_id ] ?? array();
            $GLOBALS['_wpcode_meta'][ $post_id ][ $key ]              = $value;
        }
    }
    if (! function_exists( 'get_post_meta' ) ) {
        function get_post_meta( $post_id, $key = '', $single = false ) {
            $GLOBALS['_wpcode_meta'] = $GLOBALS['_wpcode_meta'] ?? array();
            $value = $GLOBALS['_wpcode_meta'][ (int) $post_id ][ $key ] ?? '';
            return $single ? $value : array( $value );
        }
    }
    if (! function_exists( 'delete_post_meta' ) ) {
        function delete_post_meta( $post_id, $key ) {
            unset( $GLOBALS['_wpcode_meta'][ (int) $post_id ][ $key ] );
            return true;
        }
    }

    if (! function_exists( 'wp_slash' ) ) {
        function wp_slash( $value ) {
            if ( is_array( $value ) ) {
                return array_map( 'wp_slash', $value );
            }
            return addslashes( (string) $value );
        }
    }

    // ── Fake WPCode_Snippet ──
    // Mirrors the public API surface of the real WPCode_Snippet enough to drive
    // the new Novamira\AdrianV2\Abilities\WpCode\WpCode_Snippets abilities
    // without a real WordPress + WPCode install.
    if (! class_exists( 'WPCode_Snippet' ) ) {
        class WPCode_Snippet {
            public int    $id               = 0;
            public        $post_data        = null;
            public string $title            = '';
            public string $code             = '';
            public string $code_type        = '';
            public string $location         = '';
            public int    $auto_insert      = 0;
            public int    $insert_number    = 1;
            public bool   $active           = false;
            public array  $tags             = array();
            public int    $priority         = 10;
            public string $device_type      = 'any';
            public array  $schedule         = array( 'start' => '', 'end' => '' );
            public ?bool  $use_rules        = false;
            public array  $rules            = array();
            public string $custom_shortcode = '';
            public ?bool  $compress_output  = null;
            private ?array $_last_error     = null;

            public function __construct( $snippet ) {
                if ( is_int( $snippet ) ) {
                    $this->load_from_id( $snippet );
                } elseif ( is_array( $snippet ) ) {
                    $this->load_from_array( $snippet );
                } elseif ( $snippet instanceof WP_Post ) {
                    $this->post_data = $snippet;
                    $this->id        = (int) ( $snippet->ID ?? 0 );
                    $this->title     = (string) ( $snippet->post_title ?? '' );
                    $this->code      = (string) ( $snippet->post_content ?? '' );
                    $this->_load_terms( $this->id );
                    $this->_load_meta( $this->id );
                }
            }

            public function load_from_id( int $snippet_id ): void {
                $stored = $GLOBALS['_wpcode_storage'][ $snippet_id ] ?? null;
                if ( ! $stored ) {
                    return;
                }
                $this->post_data = (object) $stored;
                $this->id        = (int) ( $stored['ID'] ?? $snippet_id );
                $this->load_from_array( $stored );
                $this->_load_terms( $this->id );
                $this->_load_meta( $this->id );
            }

            public function load_from_array( array $data ): void {
                if ( isset( $data['post_title'] ) )    $this->title          = (string) $data['post_title'];
                if ( isset( $data['post_content'] ) )  $this->code           = (string) $data['post_content'];
                if ( isset( $data['code_type'] ) )     $this->code_type      = (string) $data['code_type'];
                if ( isset( $data['location'] ) )      $this->location       = (string) $data['location'];
                if ( isset( $data['auto_insert'] ) )   $this->auto_insert    = (int) $data['auto_insert'];
                if ( isset( $data['insert_number'] ) ) $this->insert_number  = (int) $data['insert_number'];
                if ( isset( $data['tags'] ) )          $this->tags           = array_map( 'strval', (array) $data['tags'] );
                if ( isset( $data['priority'] ) )      $this->priority       = (int) $data['priority'];
                if ( isset( $data['device_type'] ) )   $this->device_type    = (string) $data['device_type'];
                if ( isset( $data['schedule'] ) && is_array( $data['schedule'] ) ) {
                    $this->schedule = array(
                        'start' => isset( $data['schedule']['start'] ) ? (string) $data['schedule']['start'] : '',
                        'end'   => isset( $data['schedule']['end'] )   ? (string) $data['schedule']['end']   : '',
                    );
                }
                if ( isset( $data['use_rules'] ) )           $this->use_rules        = (bool) $data['use_rules'];
                if ( isset( $data['rules'] ) )               $this->rules            = (array) $data['rules'];
                if ( isset( $data['custom_shortcode'] ) )    $this->custom_shortcode = (string) $data['custom_shortcode'];
                if ( isset( $data['compress_output'] ) )     $this->compress_output  = (bool) $data['compress_output'];
                if ( isset( $data['active'] ) )              $this->active           = (bool) $data['active'];
                if ( isset( $data['post_status'] ) ) {
                    $this->active = ((string) $data['post_status'] === 'publish');
                }
            }

            private function _load_terms( int $id ): void {
                $t = $GLOBALS['_wpcode_terms'][ $id ] ?? array();
                if ( isset( $t['wpcode_type'][0] ) )     $this->code_type = (string) $t['wpcode_type'][0];
                if ( isset( $t['wpcode_location'][0] ) ) $this->location  = (string) $t['wpcode_location'][0];
                if ( isset( $t['wpcode_tags'] ) )        $this->tags      = array_map( 'strval', (array) $t['wpcode_tags'] );
            }

            private function _load_meta( int $id ): void {
                $m = $GLOBALS['_wpcode_meta'][ $id ] ?? array();
                if ( isset( $m['_wpcode_auto_insert'] ) )              $this->auto_insert    = (int) $m['_wpcode_auto_insert'];
                if ( isset( $m['_wpcode_auto_insert_number'] ) )       $this->insert_number  = (int) $m['_wpcode_auto_insert_number'];
                if ( isset( $m['_wpcode_priority'] ) )                 $this->priority       = (int) $m['_wpcode_priority'];
                if ( isset( $m['_wpcode_device_type'] ) )              $this->device_type    = (string) $m['_wpcode_device_type'];
                if ( isset( $m['_wpcode_schedule'] ) )                 $this->schedule       = (array) $m['_wpcode_schedule'];
                if ( isset( $m['_wpcode_conditional_logic_enabled'] ) ) $this->use_rules     = (bool) $m['_wpcode_conditional_logic_enabled'];
                if ( isset( $m['_wpcode_conditional_logic'] ) )        $this->rules          = (array) $m['_wpcode_conditional_logic'];
                if ( isset( $m['_wpcode_custom_shortcode'] ) )         $this->custom_shortcode = (string) $m['_wpcode_custom_shortcode'];
                if ( isset( $m['_wpcode_compress_output'] ) )          $this->compress_output  = (bool) $m['_wpcode_compress_output'];
            }

            public function get_id(): int { return (int) ( $this->id ?: ( $this->post_data->ID ?? 0 ) ); }
            public function get_post_data() { return $this->post_data; }
            public function get_title(): string { return $this->title ?: ( $this->post_data->post_title ?? '' ); }
            public function get_code(): string { return $this->code ?: ( $this->post_data->post_content ?? '' ); }
            public function is_active(): bool { return (bool) $this->active; }
            public function get_code_type(): string { return (string) $this->code_type; }
            public function get_location(): string { return (string) $this->location; }
            public function get_auto_insert(): int { return (int) $this->auto_insert; }
            public function get_auto_insert_number(): int { return (int) ( $this->insert_number ?: 1 ); }
            public function get_priority(): int { return (int) ( $this->priority ?: 10 ); }
            public function get_tags(): array { return (array) $this->tags; }
            public function get_conditional_rules(): array { return (array) $this->rules; }
            public function conditional_rules_enabled(): bool { return (bool) ( $this->use_rules ?? false ); }
            public function get_custom_shortcode(): string { return (string) ( $this->custom_shortcode ?? '' ); }
            public function get_device_type(): string { return (string) ( $this->device_type ?: 'any' ); }
            public function get_schedule(): array { return (array) ( $this->schedule ?: array( 'start' => '', 'end' => '' ) ); }
            public function maybe_compress_output(): bool { return (bool) $this->compress_output; }
            public function get_last_error(): array|false { return $this->_last_error ?: false; }
            public function set_last_error( array $e ): void { $this->_last_error = $e; }
            public function reset_last_error(): void { $this->_last_error = null; }
            public function get_edit_url(): string {
                return 'https://solar.local/wp-admin/admin.php?page=wpcode-snippet-manager&snippet_id=' . $this->get_id();
            }
            public function run_activation_checks(): void { /* no-op for the fake */ }
            public function rebuild_cache(): void { /* no-op for the fake */ }
            public function get_data_for_caching(): array {
                return array(
                    'id'        => $this->get_id(),
                    'title'     => $this->get_title(),
                    'code'      => $this->get_code(),
                    'code_type' => $this->get_code_type(),
                );
            }

            public function activate(): void { $this->active = true;  $this->save(); }
            public function deactivate(): void { $this->active = false; $this->save(); }
            public function duplicate(): void {
                $this->get_data_for_caching();
                $this->title = $this->get_title() . ' - Copy';
                $this->code  = wp_slash( (string) $this->code );
                $this->active = false;
                unset( $this->id );
                $this->save();
            }

            public function save(): int {
                if ( ! $this->id ) {
                    $this->id = 50000 + count( $GLOBALS['_wpcode_storage'] ?? array() );
                }
                $this->post_data = (object) array(
                    'ID'           => $this->id,
                    'post_title'   => $this->get_title(),
                    'post_content' => $this->get_code(),
                    'post_status'  => $this->is_active() ? 'publish' : 'draft',
                    'post_type'    => 'wpcode',
                );
                $GLOBALS['_wpcode_storage']                        = $GLOBALS['_wpcode_storage'] ?? array();
                $GLOBALS['_wpcode_meta']                           = $GLOBALS['_wpcode_meta']    ?? array();
                $GLOBALS['_wpcode_terms']                          = $GLOBALS['_wpcode_terms']   ?? array();
                $GLOBALS['_test_posts']                            = $GLOBALS['_test_posts']     ?? array();

                $GLOBALS['_wpcode_storage'][ $this->id ]          = (array) $this->post_data;
                $GLOBALS['_test_posts'][ $this->id ]               = (array) $this->post_data;
                $GLOBALS['_wpcode_terms'][ $this->id ]             = array(
                    'wpcode_type'     => $this->code_type ? array( $this->code_type ) : array(),
                    'wpcode_location' => $this->location  ? array( $this->location )  : array(),
                    'wpcode_tags'     => $this->tags,
                );
                $GLOBALS['_wpcode_meta'][ $this->id ]              = array_merge(
                    $GLOBALS['_wpcode_meta'][ $this->id ] ?? array(),
                    array(
                        '_wpcode_auto_insert'              => $this->auto_insert,
                        '_wpcode_auto_insert_number'       => $this->insert_number,
                        '_wpcode_priority'                 => $this->priority,
                        '_wpcode_device_type'              => $this->device_type,
                        '_wpcode_schedule'                 => $this->schedule,
                        '_wpcode_conditional_logic_enabled' => (bool) $this->use_rules,
                        '_wpcode_custom_shortcode'         => $this->custom_shortcode,
                    )
                );
                return $this->id;
            }
        }
    }

/**
 * Mock WordPress Functions — Minimal mocks for Unit-Tests without WordPress.
 *
 * @package Novamira\AdrianV2\Tests
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

// ── WordPress output constants (used by get_post default params) ──
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// ── i18n ──
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

// ── Ability Registration ──
// wp_register_ability is now a capturing mock: it stores every registered
// ability in $GLOBALS['_registered_abilities'] so tests can assert on the
// schema, label, permission_callback, etc. The original no-op behaviour is
// preserved when $GLOBALS['_registered_abilities'] is not initialised.
if (!function_exists('wp_register_ability')) {
    function wp_register_ability($name, $callable_or_array, $args = []) {
        if (! isset( $GLOBALS['_registered_abilities'] ) || ! is_array( $GLOBALS['_registered_abilities'] ) ) {
            return;
        }
        // Allow either WP-style 'function_name' strings OR the [__CLASS__, 'method']
        // array form we use in the new ability classes.
        $GLOBALS['_registered_abilities'][ $name ] = array(
            'callable' => $callable_or_array,
            'args'     => $args,
        );
    }
}

// ── Hooks ──
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { return null; }
}
if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {}
}
if (!function_exists('did_action')) {
    function did_action($hook) { return 0; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) { return $value; }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}

// ── Options ──
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $GLOBALS['_test_options'][$option] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) { return true; }
}

// ── Posts / CPT ──
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($args = [], $wp_error = false) { return 99999; }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($args = [], $wp_error = false) { return 99999; }
}
if (!function_exists('get_post')) {
    function get_post($post = null, $output = OBJECT, $filter = 'raw') {
        // Allow tests to inject fake posts via $GLOBALS['_test_posts'].
        $id = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
        if (isset($GLOBALS['_test_posts'][$id])) {
            return (object) $GLOBALS['_test_posts'][$id];
        }
        return null;
    }
}
if (!function_exists('get_posts')) {
    function get_posts($args = null) {
        $args  = is_array($args) ? $args : array();
        $type  = isset($args['post_type']) ? $args['post_type'] : null;
        $posts = $GLOBALS['_test_posts'] ?? array();
        $terms = $GLOBALS['_wpcode_terms'] ?? array();
        $out   = array();
        foreach ($posts as $id => $post) {
            if ($type && (!isset($post['post_type']) || $post['post_type'] !== $type)) {
                continue;
            }
            if (isset($args['post_status'])) {
                $status = $args['post_status'];
                $ps     = isset($post['post_status']) ? $post['post_status'] : '';
                if (is_array($status)) {
                    if (!in_array($ps, $status, true)) {
                        continue;
                    }
                } elseif ($ps !== $status) {
                    continue;
                }
            }
            if (!empty($args['tax_query']) && is_array($args['tax_query'])) {
                $keep = true;
                foreach ($args['tax_query'] as $t) {
                    if (!is_array($t) || !isset($t['taxonomy'], $t['terms'])) {
                        continue;
                    }
                    $slug = is_array($t['terms']) ? $t['terms'] : array($t['terms']);
                    $own  = isset($terms[$id][$t['taxonomy']]) ? $terms[$id][$t['taxonomy']] : array();
                    $hit  = false;
                    foreach ($slug as $s) {
                        if (in_array($s, $own, true)) { $hit = true; break; }
                    }
                    if (!$hit) { $keep = false; break; }
                }
                if (!$keep) { continue; }
            }
            $out[] = (object) $post;
        }
        return $out;
    }
}

// ── Paths / URLs ──
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return '/tmp/wp-content/plugins/novamira-adrianv2/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'https://solar.local/wp-content/plugins/novamira-adrianv2/'; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return 'novamira-adrianv2/novamira-adrianv2.php'; }
}

// ── REST-API ──
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false) { return true; }
}

// ── Meta ──
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) { return $single ? '' : []; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '') {
        // Capture every call so tests can assert on the keys/values written
        // (e.g. ability boot-meta seeding, post-meta mutations by helpers).
        $GLOBALS['_test_post_meta_update_calls']                                 = $GLOBALS['_test_post_meta_update_calls'] ?? array();
        $GLOBALS['_test_post_meta_update_calls'][]                                = array(
            'id'    => (int) $post_id,
            'key'   => (string) $meta_key,
            'value' => $meta_value,
        );
        // Also persist into the per-key/per-post storage so subsequent
        // get_post_meta() calls see the same shape WP would.
        $GLOBALS['_wpcode_meta']                                                 = $GLOBALS['_wpcode_meta'] ?? array();
        $GLOBALS['_wpcode_meta'][(int) $post_id]                                 = $GLOBALS['_wpcode_meta'][(int) $post_id] ?? array();
        $GLOBALS['_wpcode_meta'][(int) $post_id][(string) $meta_key]             = $meta_value;
        return true;
    }
}

// ── Admin / Activation ──
// current_user_can is now a per-capability mock. When $GLOBALS['_test_caps']
// is set to an associative array of cap => bool, each lookup returns the
// mapped value. When the global is unset the mock keeps the historical
// "everything is allowed" default so older tests stay green.
if (!function_exists('current_user_can')) {
    function current_user_can($capability, $object_id = null) {
        $GLOBALS['_test_caps'] = $GLOBALS['_test_caps'] ?? null;
        if ( null === $GLOBALS['_test_caps'] ) {
            return true;
        }
        $parts = explode( ',', (string) $capability );
        return ! empty( $GLOBALS['_test_caps'][ trim( $parts[0] ) ] );
    }
}
if (!function_exists('wp_admin_notice')) {
    function wp_admin_notice($message, $args = []) {}
}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) { exit(1); }
}
if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugins, $silent = false, $network_wide = null) {}
}

// ── Misc ──
if (!isset($wp_version)) {
    $wp_version = '6.9';
}
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return false; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) { return $data; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return $str; }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        // Strip path components like WordPress does
        $filename = preg_replace('#[\\/]+#', '', $filename);
        $filename = preg_replace('#\.\.+#', '', $filename);
        $filename = trim($filename, '. ');
        return $filename;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return $text; }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0) { return date('c'); }
}

// ── WP_Error stub ──
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];
        public function __construct($code = '', $message = '', $data = '') {
            $this->errors[$code] = [$message];
            $this->error_data[$code] = $data;
        }            public function get_error_message($code = '') {
                if ('' === $code) {
                    if (empty($this->errors)) {
                        return '';
                    }
                    $first = reset($this->errors);
                    return is_array($first) ? (string) ($first[0] ?? '') : (string) $first;
                }
                return (string) ($this->errors[$code][0] ?? '');
            }
    }
}

// ── WP_Post stub (for Elementor_Data_Helpers tests) ──
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID;
        public string $post_title = '';
        public function __construct(array $data) {
            $this->ID = (int) ($data['ID'] ?? 0);
            $this->post_title = (string) ($data['post_title'] ?? '');
        }
    }
}

// ── clean_post_cache stub (for write_page tests) ──
if (!function_exists('clean_post_cache')) {
    function clean_post_cache($post_id) { return true; }
}

    // ── sanitize_html_class (per-call override mock) ──
    // Default behavior: lowercase-trim of input. Tests inject per-class
    // overrides via $GLOBALS['_test_html_class_map'][$class] to drive the
    // "invalid after sanitisation" error path.
    if (!function_exists('sanitize_html_class')) {
        function sanitize_html_class($class, $fallback = '') {
            $GLOBALS['_test_html_class_map'] = $GLOBALS['_test_html_class_map'] ?? array();
            $key = (string) $class;
            if (array_key_exists($key, $GLOBALS['_test_html_class_map'])) {
                $override = (string) $GLOBALS['_test_html_class_map'][$key];
                return ('' === $override) ? (string) $fallback : strtolower($override);
            }
            $clean = strtolower(trim($key));
            return ('' === $clean) ? (string) $fallback : $clean;
        }
    }

    // ── get_permalink stub (used by batch-build-page) ──
    if (!function_exists('get_permalink')) {
        function get_permalink($post_id = 0, $leavename = false) {
            $id = (int) $post_id;
            return "https://solar.local/?p={$id}";
        }
    }

    // ── admin_url stub (used by batch-build-page) ──
    if (!function_exists('admin_url')) {
        function admin_url($path = '', $scheme = 'admin') {
            return "https://solar.local/wp-admin/{$path}";
        }
    }

    // ── sanitize_title stub (used by batch-build-page slug) ──
    if (!function_exists('sanitize_title')) {
        function sanitize_title($title, $fallback_title = '', $context = 'save') {
            $slug = strtolower(trim((string) $title));
            $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
            $slug = preg_replace('/\s+/', '-', $slug);
            return trim($slug, '-') ?: (string) $fallback_title;
        }
    }

    // ── wp_check_post_lock (per-page mock, default unlocked) ──
    // Tests flip a page into the locked state by setting
    // $GLOBALS['_test_post_lock_by_page'][$page_id] = true; the helper then
    // receives a truthy locked-user object back.
    if (!function_exists('wp_check_post_lock')) {
        function wp_check_post_lock($post_id) {
            $GLOBALS['_test_post_lock_by_page'] = $GLOBALS['_test_post_lock_by_page'] ?? array();
            if (!empty($GLOBALS['_test_post_lock_by_page'][(int) $post_id])) {
                return (object) array(
                    'user_id'   => 1,
                    'lock_time' => time(),
                );
            }
            return false;
        }
    }

    // ── inject_page_custom_css return control (per-page mock) ──
    // Tests inject either `true` or a WP_Error code into
    // $GLOBALS['_test_css_inject_outcome'][$page_id] to drive the success
    // / soft-fail branches inside the ability.
    if (!function_exists('_test_force_css_inject_outcome')) {
        function _test_force_css_inject_outcome(int $page_id, $outcome): void {
            $GLOBALS['_test_css_inject_outcome']                       = $GLOBALS['_test_css_inject_outcome'] ?? array();
            $GLOBALS['_test_css_inject_outcome'][(int) $page_id]       = $outcome;
        }
    }

    // ── save_data return control (per-page mock) ──
    // Tests inject either a {success,warnings} array or a WP_Error into
    // $GLOBALS['_test_save_data_outcome'][$page_id] to bypass the real
    // Elementor_Document_Saver workflow when the test wants to assert on
    // the ability's response shaping.
    if (!function_exists('_test_force_save_data_outcome')) {
        function _test_force_save_data_outcome(int $page_id, $outcome): void {
            $GLOBALS['_test_save_data_outcome']                       = $GLOBALS['_test_save_data_outcome'] ?? array();
            $GLOBALS['_test_save_data_outcome'][(int) $page_id]       = $outcome;
        }
    }

    // ── $wpdb stub (used by WpCode_Check_Setup::compiled_cache_layers + auto_demote_pending_count
    //     when smoke runs without a real WordPress DB load). Strictly opt-in: smoke harness
    //     is the only consumer; PHPUnit tests that load a real WP bootstrap do NOT
    //     trigger this branch because the bootstrap defines $wpdb first.
    if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new class {
            public $posts    = 'wp_posts';
            public $postmeta = 'wp_postmeta';
            public function get_var($sql) {
                // Smoke-mode: every SELECT COUNT(*) returns 0; every other scalar returns null.
                return (is_string($sql) && false !== stripos($sql, 'count(')) ? 0 : null;
            }
            public function get_results($sql, $output = ARRAY_A) { return []; }
            public function prepare($sql, ...$args) { return $sql; }
        };
    }

    // ── wp_upload_dir stub (used by WpCode_Check_Setup::compiled_cache_layers to derive cache_dir_present) ──
    // Returns a synthetic basedir that does NOT exist on disk so `is_dir()` legitimately
    // returns false in smoke = “no on-disk cache”. Real WP installs override this.
    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir() {
            $GLOBALS['_test_wp_upload_dir_calls'] = ($GLOBALS['_test_wp_upload_dir_calls'] ?? 0) + 1;
            return array(
                'basedir' => '/tmp/novamira-tests/wp-content/uploads',
                'baseurl' => 'https://solar.local/wp-content/uploads',
            );
        }
    }

    // ── taxonomy_exists + wp_count_posts + post_type_exists stubs (used by
    //     WpCode_Check_Setup::snippets_breakdown only when WPCode IS active;
    //     in the smoke WPCode is not active so this branch is unreachable,
    //     but the stubs prevent warnings if a future active-install smoke runs). ──
    if (!function_exists('taxonomy_exists')) {
        function taxonomy_exists($taxonomy) { return false; }
    }
    if (!function_exists('wp_count_posts')) {
        function wp_count_posts($type = 'post', $perm = '') {
            return (object) array();
        }
    }
    if (!function_exists('post_type_exists')) {
        function post_type_exists($type) { return false; }
    }

    // ── get_post_status mock (drives the publish-gate branch of
    //      Elementor_Inject_Calibrated_Page::check_inject_permission).
    //      Tests set $GLOBALS['_test_post_status'][(int) $post_id] = 'publish'
    //      to drive the negative branch (edit_published_pages required when
    //      status='publish'). Default is 'draft' so the publish-only check
    //      is skipped.
    if (!function_exists('get_post_status')) {
        function get_post_status($post_id = null) {
            $GLOBALS['_test_post_status'] = $GLOBALS['_test_post_status'] ?? array();
            $id = (int) $post_id;
            if (isset($GLOBALS['_test_post_status'][$id])) {
                return $GLOBALS['_test_post_status'][$id];
            }
            return 'draft';
        }
    }

    // ── stripslashes_deep stub (used by Elementor_Inject_Calibrated_Page::read_existing_tree).
    //      Mirrors WP core recursion: array → recurse elements; scalar → stripslashes.
    if (!function_exists('stripslashes_deep')) {
        function stripslashes_deep($value) {
            if (is_array($value)) {
                return array_map('stripslashes_deep', $value);
            }
            return stripslashes((string) $value);
        }
    }

    // ── NOVAMIRA_ADRIANV2_DIR constant (used by Self_Audit) ──
    // Point to the real plugin dir so BOM scan can actually find PHP files.
    if (!defined('NOVAMIRA_ADRIANV2_DIR')) {
        define('NOVAMIRA_ADRIANV2_DIR', dirname(__DIR__) . '/');
    }

    // ── NOVAMIRA_ADRIANV2_VERSION constant (used by installer/updates) ──
    if (!defined('NOVAMIRA_ADRIANV2_VERSION')) {
        define('NOVAMIRA_ADRIANV2_VERSION', '1.1.0');
    }

    // ── PHP_BINARY (used by Self_Audit strict_types probe) ──
    if (!defined('PHP_BINARY')) {
        define('PHP_BINARY', '/usr/bin/php');
    }

    // ── str_starts_with polyfill (PHP 8.0+) ──
    if (!function_exists('str_starts_with')) {
        function str_starts_with(string $haystack, string $needle): bool {
            return '' === $needle || 0 === strncmp($haystack, $needle, strlen($needle));
        }
    }

    // ── wp_cache_get / wp_cache_set / wp_cache_delete mocks ──
    // In-memory associative array keyed by cache group → key → value.
    // The production code uses cache group 'novamira' with TTL of 300 sec.
    // Our mock ignores TTL (tests can call _test_cache_clear() to expire).
    if (!function_exists('_test_cache_clear')) {
        function _test_cache_clear(): void {
            $GLOBALS['_test_wp_cache'] = [];
        }
    }
    if (!function_exists('wp_cache_get')) {
        function wp_cache_get($key, $group = 'default', $force = false, &$found = null) {
            $GLOBALS['_test_wp_cache'] = $GLOBALS['_test_wp_cache'] ?? [];
            $found = isset($GLOBALS['_test_wp_cache'][$group][$key]);
            return $found ? $GLOBALS['_test_wp_cache'][$group][$key] : false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        function wp_cache_set($key, $data, $group = 'default', $expire = 0): bool {
            $GLOBALS['_test_wp_cache'] = $GLOBALS['_test_wp_cache'] ?? [];
            $GLOBALS['_test_wp_cache'][$group][$key] = $data;
            return true;
        }
    }
    if (!function_exists('wp_cache_delete')) {
        function wp_cache_delete($key, $group = 'default'): bool {
            $GLOBALS['_test_wp_cache'] = $GLOBALS['_test_wp_cache'] ?? [];
            unset($GLOBALS['_test_wp_cache'][$group][$key]);
            return true;
        }
    }
    if (!function_exists('wp_cache_flush')) {
        function wp_cache_flush(): bool {
            $GLOBALS['_test_wp_cache'] = [];
            return true;
        }
    }

    // ── wp_get_abilities mock (used by Self_Audit ability count check) ──
    // Tests set $GLOBALS['_test_registered_abilities'] to an assoc array
    // keyed by ability name => whatever definition.  The default path returns
    // an empty array so the count is 0 unless a test seeds abilities.
    if (!function_exists('wp_get_abilities')) {
        function wp_get_abilities(): array {
            return $GLOBALS['_test_registered_abilities'] ?? [];
        }
    }

    // ── WP revision mocks (used by Rollback_Build) ──
    if (!function_exists('wp_save_post_revision')) {
        function wp_save_post_revision($post_id, $autosave = false) {
            $GLOBALS['_test_revisions'] = $GLOBALS['_test_revisions'] ?? [];
            $GLOBALS['_test_revision_counter'] = ($GLOBALS['_test_revision_counter'] ?? 0) + 1;
            $rev_id = 90000 + $GLOBALS['_test_revision_counter'];
            $GLOBALS['_test_revisions'][$rev_id] = [
                'ID'            => $rev_id,
                'post_parent'   => (int) $post_id,
                'post_type'     => 'revision',
                'post_content'  => $GLOBALS['_test_posts'][(int) $post_id]['post_content'] ?? '',
                'post_title'    => $GLOBALS['_test_posts'][(int) $post_id]['post_title'] ?? '',
            ];
            // Also register as a regular post so get_post() can find it.
            $GLOBALS['_test_posts'][$rev_id] = $GLOBALS['_test_revisions'][$rev_id];
            return $rev_id;
        }
    }
    if (!function_exists('wp_get_post_revisions')) {
        function wp_get_post_revisions($post_id, $args = []) {
            $GLOBALS['_test_revisions'] = $GLOBALS['_test_revisions'] ?? [];
            $GLOBALS['_test_revision_metadata'] = $GLOBALS['_test_revision_metadata'] ?? [];
            $out = [];
            foreach ($GLOBALS['_test_revisions'] as $rev_id => $rev) {
                if ((int) ($rev['post_parent'] ?? 0) !== (int) $post_id) {
                    continue;
                }
                // Filter by meta_key / meta_value if specified.
                if (!empty($args['meta_key'])) {
                    $meta_val = $GLOBALS['_test_revision_metadata'][$rev_id][$args['meta_key']] ?? null;
                    if (isset($args['meta_value'])) {
                        if ($meta_val !== $args['meta_value']) {
                            continue;
                        }
                    } elseif (null === $meta_val) {
                        continue;
                    }
                }
                $out[] = (object) $rev;
            }
            // Sort orderby/order.
            if (!empty($args['orderby']) && 'ID' === $args['orderby']) {
                usort($out, fn($a, $b) => 'DESC' === ($args['order'] ?? 'ASC')
                    ? $b->ID - $a->ID
                    : $a->ID - $b->ID);
            }
            // posts_per_page limit.
            if (!empty($args['posts_per_page']) && (int) $args['posts_per_page'] > 0) {
                $out = array_slice($out, 0, (int) $args['posts_per_page']);
            }
            return $out;
        }
    }
    if (!function_exists('wp_get_post_revision')) {
        function wp_get_post_revision($revision_id) {
            $GLOBALS['_test_revisions'] = $GLOBALS['_test_revisions'] ?? [];
            if (isset($GLOBALS['_test_revisions'][(int) $revision_id])) {
                return (object) $GLOBALS['_test_revisions'][(int) $revision_id];
            }
            return false;
        }
    }

    // ── get_metadata / update_metadata mocks (used by Rollback_Build for custom revision meta) ──
    if (!function_exists('get_metadata')) {
        function get_metadata($meta_type, $object_id, $meta_key = '', $single = false) {
            $GLOBALS['_test_revision_metadata'] = $GLOBALS['_test_revision_metadata'] ?? [];
            $value = $GLOBALS['_test_revision_metadata'][(int) $object_id][$meta_key] ?? '';
            return $single ? $value : [$value];
        }
    }
    if (!function_exists('update_metadata')) {
        function update_metadata($meta_type, $object_id, $meta_key, $meta_value, $prev_value = '') {
            $GLOBALS['_test_revision_metadata'] = $GLOBALS['_test_revision_metadata'] ?? [];
            $GLOBALS['_test_revision_metadata'][(int) $object_id][$meta_key] = $meta_value;
            return true;
        }
    }

    // ── Elementor experiments mock (used by Guards::ensure_markdown_rendering_active) ──
    if (!class_exists('Elementor\Core\Experiments\Manager')) {
        // Already handled via the Plugin stub — add experiments property.
    }
}    // closes the bare `namespace { ... }` block at the top of the file.
