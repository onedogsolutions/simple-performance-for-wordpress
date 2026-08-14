<?php
/**
 * Lightweight test bootstrap — stubs the WordPress functions used by
 * SPFW_Settings and SPFW_Htaccess so the classes can be exercised without
 * a full WordPress install.
 *
 * @package Simple_Performance_For_WordPress
 */

// phpcs:ignoreFile

define( 'ABSPATH', sys_get_temp_dir() . '/spfw-test/' );
define( 'SPFW_VERSION', '2.1.0' );
define( 'SPFW_FILE', dirname( __DIR__ ) . '/simple-performance-for-wordpress.php' );
define( 'SPFW_PATH', dirname( __DIR__ ) . '/' );
define( 'SPFW_URL', 'http://example.com/wp-content/plugins/simple-performance-for-wordpress/' );
define( 'SPFW_BASENAME', 'simple-performance-for-wordpress/simple-performance-for-wordpress.php' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/spfw-test/wp-content' );

// ---------------------------------------------------------------------------
// In-memory option store (simulates wp_options table).
// ---------------------------------------------------------------------------
global $spfw_test_options;
$spfw_test_options = array();

function get_option( $key, $default = false ) {
	global $spfw_test_options;
	return array_key_exists( $key, $spfw_test_options ) ? $spfw_test_options[ $key ] : $default;
}

function update_option( $key, $value ) {
	global $spfw_test_options;
	$spfw_test_options[ $key ] = $value;
	return true;
}

// ---------------------------------------------------------------------------
// Minimal WordPress function stubs.
// ---------------------------------------------------------------------------
function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

function absint( $n ) {
	return abs( (int) $n );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( $path = '' ) {
	return 'http://example.com' . $path;
}

function esc_url_raw( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function wp_upload_dir() {
	return array(
		'basedir' => sys_get_temp_dir() . '/spfw-test/wp-content/uploads',
		'baseurl' => 'http://example.com/wp-content/uploads',
	);
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0755, true );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max );
}

function apply_filters( $tag, $value ) {
	return $value;
}

function do_action() {}

function add_action() {}

function add_filter() {}

// ---------------------------------------------------------------------------
// In-memory transient + object cache (simulates the wp_options / object-cache
// pair the CSP violation log is stored in).
// ---------------------------------------------------------------------------
global $spfw_test_transients, $spfw_test_cache;
$spfw_test_transients = array();
$spfw_test_cache      = array();

function get_transient( $key ) {
	global $spfw_test_transients;
	return array_key_exists( $key, $spfw_test_transients ) ? $spfw_test_transients[ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	global $spfw_test_transients;
	$spfw_test_transients[ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	global $spfw_test_transients;
	unset( $spfw_test_transients[ $key ] );
	return true;
}

function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) {
	global $spfw_test_cache;
	if ( isset( $spfw_test_cache[ "$group:$key" ] ) ) {
		return false;
	}
	$spfw_test_cache[ "$group:$key" ] = $value;
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	global $spfw_test_cache;
	unset( $spfw_test_cache[ "$group:$key" ] );
	return true;
}

// ---------------------------------------------------------------------------
// Load plugin classes under test.
// ---------------------------------------------------------------------------
require_once SPFW_PATH . 'includes/class-spfw-settings.php';
require_once SPFW_PATH . 'includes/class-spfw-htaccess.php';
require_once SPFW_PATH . 'includes/interface-spfw-module.php';
require_once SPFW_PATH . 'includes/modules/class-spfw-module-hardening.php';
require_once SPFW_PATH . 'includes/class-spfw-rest-settings.php';
