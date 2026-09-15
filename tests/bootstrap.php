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

// The suite describes a server that reads .htaccess, which is what every
// assertion written before the strategy layer assumed implicitly. SPFW_Server
// now reads this, so it has to be stated rather than left to chance — and the
// server tests flip it deliberately via SPFW_Server::reset_detection().
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Unix)';

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

function sanitize_email( $email ) {
	// Mirrors core: an address that is not valid sanitizes to an empty string.
	$email = strtolower( trim( strip_tags( (string) $email ) ) );
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}

function is_email( $email ) {
	// Mirrors core: returns the address itself when valid, false otherwise.
	$valid = filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	return false === $valid ? false : $valid;
}

function absint( $n ) {
	return abs( (int) $n );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

global $spfw_test_home_url;
$spfw_test_home_url = 'http://example.com';

function home_url( $path = '' ) {
	global $spfw_test_home_url;
	return $spfw_test_home_url . $path;
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

// Filters resolve through an override registry so a test can describe an
// environment PHP itself cannot be talked into — a FastCGI SAPI, for one,
// which the CLI test runner is definitionally not.
global $spfw_test_filter_overrides;
$spfw_test_filter_overrides = array();

function apply_filters( $tag, $value ) {
	global $spfw_test_filter_overrides;

	if ( array_key_exists( $tag, $spfw_test_filter_overrides ) ) {
		return $spfw_test_filter_overrides[ $tag ];
	}

	return $value;
}

// do_action() records rather than discards: SPFW_Module_Fonts::purge_generated_css()
// fires four LiteSpeed purge hooks in a deliberate order (page cache last), and
// "fires them in that order" is only assertable if the stub keeps them.
global $spfw_test_actions_fired;
$spfw_test_actions_fired = array();

function do_action( $tag = '' ) {
	global $spfw_test_actions_fired;
	$spfw_test_actions_fired[] = $tag;
}

// Hook registrations are recorded so a test can assert which callback a module
// actually attached, not merely that the callback behaves when called by hand.
global $spfw_test_hooks;
$spfw_test_hooks = array();

function add_action( $tag, $callback = null, $priority = 10, $accepted_args = 1 ) {
	global $spfw_test_hooks;
	$spfw_test_hooks[] = array(
		'tag'      => $tag,
		'callback' => $callback,
		'priority' => $priority,
	);
}

global $spfw_test_filters;
$spfw_test_filters = array();

function add_filter( $tag, $callback = null, $priority = 10, $accepted_args = 1 ) {
	global $spfw_test_filters;
	$spfw_test_filters[] = array(
		'tag'      => $tag,
		'callback' => $callback,
		'priority' => $priority,
	);
}

function remove_action() {}

function remove_filter() {}

function is_admin() {
	return false;
}

// ---------------------------------------------------------------------------
// Enqueue-registry stubs. These record calls rather than model WP_Dependencies:
// what the tests need to pin is which removal API a module reaches for, since
// deregistering a handle other assets depend on silently drops those assets.
// ---------------------------------------------------------------------------
global $spfw_test_style_calls, $spfw_test_script_calls, $spfw_test_logged_in;
$spfw_test_style_calls  = array();
$spfw_test_script_calls = array();
$spfw_test_logged_in    = false;

function is_user_logged_in() {
	global $spfw_test_logged_in;
	return (bool) $spfw_test_logged_in;
}

function wp_dequeue_style( $handle ) {
	global $spfw_test_style_calls;
	$spfw_test_style_calls[] = array( 'dequeue', $handle );
}

function wp_deregister_style( $handle ) {
	global $spfw_test_style_calls;
	$spfw_test_style_calls[] = array( 'deregister', $handle );
}

function wp_dequeue_script( $handle ) {
	global $spfw_test_script_calls;
	$spfw_test_script_calls[] = array( 'dequeue', $handle );
}

function wp_deregister_script( $handle ) {
	global $spfw_test_script_calls;
	$spfw_test_script_calls[] = array( 'deregister', $handle );
}

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
// Minimal REST layer stubs. register_rest_route() records each registration
// so the controller's routes and permission callbacks can be asserted without
// standing up a REST server.
// ---------------------------------------------------------------------------
global $spfw_test_rest_routes, $spfw_test_capabilities;
$spfw_test_rest_routes  = array();
$spfw_test_capabilities = array( 'manage_options' => true );

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE   = 'GET';
		const CREATABLE  = 'POST';
		const EDITABLE   = 'POST, PUT, PATCH';
		const DELETABLE  = 'DELETE';
		const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
	global $spfw_test_rest_routes;
	$spfw_test_rest_routes[ $namespace . $route ] = $args;
	return true;
}

function current_user_can( $capability ) {
	global $spfw_test_capabilities;
	return ! empty( $spfw_test_capabilities[ $capability ] );
}

// ---------------------------------------------------------------------------
// Template conditionals and cron, for the CSP collection-coverage tests.
//
// $spfw_test_page holds the page type the next request should look like;
// every conditional below answers against it. Kept as one switch rather than
// a stub per function so a test can never accidentally describe a request that
// is both a 404 and the cart.
// ---------------------------------------------------------------------------
global $spfw_test_page, $spfw_test_cron;
$spfw_test_page = '';
$spfw_test_cron = array();

function is_404() {
	global $spfw_test_page;
	return '404' === $spfw_test_page;
}

function is_search() {
	global $spfw_test_page;
	return 'search' === $spfw_test_page;
}

function is_front_page() {
	global $spfw_test_page;
	return 'home' === $spfw_test_page;
}

function is_home() {
	global $spfw_test_page;
	return 'home' === $spfw_test_page;
}

function is_archive() {
	global $spfw_test_page;
	return 'archive' === $spfw_test_page;
}

function is_singular( $type = '' ) {
	global $spfw_test_page;

	if ( 'page' === $type ) {
		return 'page' === $spfw_test_page;
	}

	return in_array( $spfw_test_page, array( 'page', 'post' ), true );
}

function wp_clear_scheduled_hook( $hook ) {
	global $spfw_test_cron;
	unset( $spfw_test_cron[ $hook ] );
}

function wp_schedule_single_event( $timestamp, $hook ) {
	global $spfw_test_cron;
	$spfw_test_cron[ $hook ] = $timestamp;
	return true;
}

function wp_next_scheduled( $hook ) {
	global $spfw_test_cron;
	return isset( $spfw_test_cron[ $hook ] ) ? $spfw_test_cron[ $hook ] : false;
}

function is_ssl() {
	return false;
}

function rest_url( $path = '' ) {
	return 'http://example.com/wp-json/' . ltrim( (string) $path, '/' );
}

function wp_doing_ajax() {
	return defined( 'DOING_AJAX' ) && DOING_AJAX;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

// ---------------------------------------------------------------------------
// Minimal WP_Filesystem stub.
//
// SPFW_Htaccess::write() goes through WP_Filesystem, which previously could not
// run here at all — filesystem() would try to require wp-admin/includes/file.php
// and fatal. That left the whole write path untested, which is why the payload
// tests all pin a high stored version to keep migrations from writing. Direct
// filesystem calls are enough for what the write path actually does.
// ---------------------------------------------------------------------------
class SPFW_Test_Filesystem {

	public function is_dir( $path ) {
		return is_dir( $path );
	}

	public function mkdir( $path, $mode = false ) {
		return is_dir( $path ) || @mkdir( $path, false === $mode ? 0755 : $mode, true );
	}

	public function put_contents( $path, $contents, $mode = false ) {
		if ( ! is_dir( dirname( $path ) ) ) {
			return false;
		}

		return false !== file_put_contents( $path, $contents );
	}

	public function delete( $path ) {
		return file_exists( $path ) ? unlink( $path ) : true;
	}
}

function WP_Filesystem() {
	global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		$wp_filesystem = new SPFW_Test_Filesystem();
	}

	return true;
}

// ---------------------------------------------------------------------------
// Fonts-module stubs.
//
// SPFW_Module_Fonts reaches further into WordPress than the other modules: it
// renders markup, enqueues a stylesheet, and fetches over HTTP. The HTTP layer
// is a controllable map rather than a fixed fake, so a test can describe the
// exact remote conditions it means to reproduce (a page that returns nothing, a
// Google response with two weights, a font file that 404s) instead of asserting
// against whatever a generic stub happens to return.
// ---------------------------------------------------------------------------
function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' );
}

function esc_url( $url ) {
	// Mirrors core closely enough for assertions: core leaves a root-relative
	// path alone rather than absolutizing it, which is the property the
	// preload/stylesheet URL-parity test depends on.
	$url = trim( (string) $url );
	$url = str_replace( array( '"', "'", '<', '>' ), '', $url );
	return $url;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	return substr( str_repeat( 'abcdef0123456789', 8 ), 0, $length );
}

function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) {
		return $url;
	}

	$sep = ( false === strpos( (string) $url, '?' ) ) ? '?' : '&';

	return $url . $sep . http_build_query( $args );
}

function get_posts( $args = array() ) {
	return array();
}

function get_permalink( $post = 0 ) {
	return '';
}

class WP_Error {

	public $code;
	public $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

// URL => array( 'code' => int, 'body' => string ), or a WP_Error instance.
// Anything not listed returns a 404 with an empty body, so a test that forgets
// to describe a URL fails loudly rather than silently passing on a default.
global $spfw_test_http;
$spfw_test_http = array();

function wp_remote_get( $url, $args = array() ) {
	global $spfw_test_http;

	foreach ( $spfw_test_http as $match => $response ) {
		if ( false !== strpos( (string) $url, $match ) ) {
			return $response;
		}
	}

	return array(
		'code' => 404,
		'body' => '',
	);
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['code'] ) ? $response['code'] : 0;
}

// Enqueued styles, and the registry serve_local_fonts() walks looking for a
// Google Fonts src to dequeue.
class SPFW_Test_Styles {

	public $registered = array();
}

global $spfw_test_styles, $spfw_test_enqueued_styles;
$spfw_test_styles          = null;
$spfw_test_enqueued_styles = array();

function wp_styles() {
	global $spfw_test_styles;

	if ( ! $spfw_test_styles ) {
		$spfw_test_styles = new SPFW_Test_Styles();
	}

	return $spfw_test_styles;
}

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	global $spfw_test_enqueued_styles;
	$spfw_test_enqueued_styles[] = array(
		'handle' => $handle,
		'src'    => $src,
		'ver'    => $ver,
	);
}

function has_action( $tag, $callback = false ) {
	global $spfw_test_hooks;

	foreach ( (array) $spfw_test_hooks as $hook ) {
		if ( isset( $hook['tag'] ) && $hook['tag'] === $tag ) {
			return true;
		}
	}

	return false;
}

function delete_option( $key ) {
	global $spfw_test_options;

	unset( $spfw_test_options[ $key ] );

	return true;
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
}

// ---------------------------------------------------------------------------
// Load plugin classes under test.
// ---------------------------------------------------------------------------
require_once SPFW_PATH . 'includes/class-spfw-settings.php';
require_once SPFW_PATH . 'includes/class-spfw-server.php';
require_once SPFW_PATH . 'includes/class-spfw-htaccess.php';
require_once SPFW_PATH . 'includes/interface-spfw-hardening-strategy.php';
require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-htaccess.php';
require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-user-ini.php';
require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-snippet.php';
require_once SPFW_PATH . 'includes/class-spfw-hardening-strategies.php';
require_once SPFW_PATH . 'includes/interface-spfw-module.php';
require_once SPFW_PATH . 'includes/modules/class-spfw-module-core.php';
require_once SPFW_PATH . 'includes/modules/class-spfw-module-hardening.php';
require_once SPFW_PATH . 'includes/modules/class-spfw-module-fonts.php';
require_once SPFW_PATH . 'includes/modules/class-spfw-module-woocommerce.php';
require_once SPFW_PATH . 'includes/class-spfw-rest-settings.php';
