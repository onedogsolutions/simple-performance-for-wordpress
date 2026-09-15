<?php
/**
 * Core loader / dispatcher for Simple Performance for WordPress.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that loads settings once, boots each enabled module, and
 * conditionally loads the admin layer.
 */
class SPFW_Plugin {

	/**
	 * Explicit, deterministic module class => file map. Grows across
	 * Steps 4, 6, 7, 8.
	 *
	 * @var array<string,string>
	 */
	const MODULES = array(
		'SPFW_Module_Core'        => 'includes/modules/class-spfw-module-core.php',
		'SPFW_Module_RestApi'     => 'includes/modules/class-spfw-module-restapi.php',
		'SPFW_Module_Hardening'   => 'includes/modules/class-spfw-module-hardening.php',
		'SPFW_Module_Fonts'       => 'includes/modules/class-spfw-module-fonts.php',
		'SPFW_Module_WooCommerce' => 'includes/modules/class-spfw-module-woocommerce.php',
		'SPFW_Module_Database'    => 'includes/modules/class-spfw-module-database.php',
	);

	/**
	 * Singleton instance.
	 *
	 * @var SPFW_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return SPFW_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Load the hardening layer: server detection, the .htaccess writer, and the
	 * strategies that decide which of them this server can actually use.
	 *
	 * Shared by boot() and both lifecycle hooks, which run standalone — the
	 * activation and deactivation callbacks fire without the plugin's normal
	 * bootstrap, and SPFW_Htaccess::write() now asks SPFW_Server what the
	 * server honors, so loading one without the other would fatal.
	 */
	private static function load_hardening_layer() {
		require_once SPFW_PATH . 'includes/class-spfw-server.php';
		require_once SPFW_PATH . 'includes/class-spfw-htaccess.php';
		require_once SPFW_PATH . 'includes/interface-spfw-hardening-strategy.php';
		require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-htaccess.php';
		require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-user-ini.php';
		require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-snippet.php';
		require_once SPFW_PATH . 'includes/class-spfw-hardening-strategies.php';
	}

	/**
	 * Load dependencies, register enabled modules, and load the admin
	 * layer when in wp-admin. Called on `plugins_loaded`.
	 */
	public function boot() {
		require_once SPFW_PATH . 'includes/class-spfw-settings.php';
		require_once SPFW_PATH . 'includes/interface-spfw-module.php';
		self::load_hardening_layer();

		// REST requests are not admin context (is_admin() is false for
		// /wp-json/), so the settings API must load unconditionally for its
		// route to exist. It also reports SPFW_Htaccess::status(), so that
		// must be loaded first too.
		require_once SPFW_PATH . 'includes/class-spfw-rest-settings.php';
		new SPFW_Rest_Settings();

		// Option / capability cleaner REST controller (on-demand, no runtime hooks).
		require_once SPFW_PATH . 'includes/modules/class-spfw-option-cleaner.php';
		require_once SPFW_PATH . 'includes/modules/class-spfw-capability-cleaner.php';
		require_once SPFW_PATH . 'includes/api/class-rest-option-cleaner.php';
		new SPFW_Rest_Option_Cleaner();

		foreach ( self::MODULES as $class => $relative_path ) {
			$file = SPFW_PATH . $relative_path;

			if ( file_exists( $file ) ) {
				require_once $file;
			}

			if ( class_exists( $class ) ) {
				$module = new $class();

				if ( $module instanceof SPFW_Module ) {
					$module->register();
				}
			}
		}

		if ( is_admin() ) {
			$admin_file = SPFW_PATH . 'admin/class-spfw-admin.php';

			if ( file_exists( $admin_file ) ) {
				require_once $admin_file;
			}

			if ( class_exists( 'SPFW_Admin' ) ) {
				new SPFW_Admin();
			}
		}
	}

	/**
	 * Activation callback: seed default settings if absent, and write the
	 * hardening .htaccess if that setting is (or defaults to) on.
	 */
	public static function activate() {
		require_once SPFW_PATH . 'includes/class-spfw-settings.php';
		self::load_hardening_layer();

		if ( false === get_option( SPFW_Settings::OPTION_KEY ) ) {
			SPFW_Settings::update( array() );
		}

		if ( SPFW_Settings::value( 'hardening', 'plugins_htaccess', false ) ) {
			SPFW_Hardening_Strategies::apply( 'plugins' );
		}

		if ( SPFW_Settings::value( 'hardening', 'uploads_htaccess', false ) ) {
			SPFW_Hardening_Strategies::apply( 'uploads' );
		}
	}

	/**
	 * Deactivation callback: remove the hardening .htaccess, but only if
	 * this plugin authored it and it hasn't been altered.
	 */
	public static function deactivate() {
		require_once SPFW_PATH . 'includes/class-spfw-settings.php';
		self::load_hardening_layer();

		SPFW_Hardening_Strategies::revert( 'plugins' );
		SPFW_Hardening_Strategies::revert( 'uploads' );

		// Clear the scheduled database optimization cron event.
		wp_clear_scheduled_hook( 'spfw_database_optimization' );

		// Clear the file-monitor scan and the one-off CSP collection-window
		// close, so neither fires against a deactivated plugin.
		wp_clear_scheduled_hook( 'spfw_file_monitor_scan' );
		wp_clear_scheduled_hook( 'spfw_csp_collection_expired' );
		wp_clear_scheduled_hook( 'spfw_user_ini_verify' );
	}
}
