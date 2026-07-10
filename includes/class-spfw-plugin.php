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
		'SPFW_Module_Core'      => 'includes/modules/class-spfw-module-core.php',
		'SPFW_Module_RestApi'   => 'includes/modules/class-spfw-module-restapi.php',
		'SPFW_Module_Hardening' => 'includes/modules/class-spfw-module-hardening.php',
		'SPFW_Module_Fonts'     => 'includes/modules/class-spfw-module-fonts.php',
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
	 * Load dependencies, register enabled modules, and load the admin
	 * layer when in wp-admin. Called on `plugins_loaded`.
	 */
	public function boot() {
		require_once SPFW_PATH . 'includes/class-spfw-settings.php';
		require_once SPFW_PATH . 'includes/interface-spfw-module.php';

		// REST requests are not admin context (is_admin() is false for
		// /wp-json/), so the settings API must load unconditionally for its
		// route to exist.
		require_once SPFW_PATH . 'includes/class-spfw-rest-settings.php';
		new SPFW_Rest_Settings();

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
	 * Activation callback: seed default settings if absent.
	 *
	 * Step 7 additionally writes the plugins-directory hardening
	 * .htaccess here when that setting defaults on (it defaults off, so
	 * this is currently a no-op beyond seeding).
	 */
	public static function activate() {
		require_once SPFW_PATH . 'includes/class-spfw-settings.php';

		if ( false === get_option( SPFW_Settings::OPTION_KEY ) ) {
			SPFW_Settings::update( array() );
		}
	}

	/**
	 * Deactivation callback.
	 *
	 * Step 7 adds removal of the authored hardening .htaccess here.
	 * No-op for now.
	 */
	public static function deactivate() {}
}
