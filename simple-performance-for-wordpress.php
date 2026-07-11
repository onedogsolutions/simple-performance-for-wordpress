<?php
/**
 * Plugin Name:       Simple Performance for WordPress
 * Description:       Ultra-lightweight performance, REST API, and hardening toolkit for OpenLiteSpeed + LiteSpeed Cache.
 * Version:           1.3.0
 * Author:            Ryan Waterbury
 * Author URI:        https://onedog.solutions/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       simple-performance-for-wordpress
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

define( 'SPFW_VERSION', '1.3.0' );
define( 'SPFW_FILE', __FILE__ );
define( 'SPFW_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPFW_URL', plugin_dir_url( __FILE__ ) );
define( 'SPFW_BASENAME', plugin_basename( __FILE__ ) );

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'simple-performance-for-wordpress', false, dirname( SPFW_BASENAME ) . '/languages' );
	}
);

require_once SPFW_PATH . 'includes/class-spfw-plugin.php';

add_action(
	'plugins_loaded',
	function () {
		SPFW_Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, array( 'SPFW_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SPFW_Plugin', 'deactivate' ) );
