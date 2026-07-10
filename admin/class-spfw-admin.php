<?php
/**
 * Admin settings screen for Simple Performance for WordPress.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Settings → Simple Performance page and mounts the React
 * admin app. All settings UI and persistence lives in src/ + the
 * spfw/v1/settings REST route; this class only wires the WordPress side.
 */
class SPFW_Admin {

	const PAGE_SLUG = 'spfw-settings';
	const HOOK_SUFFIX = 'settings_page_' . self::PAGE_SLUG;

	/**
	 * Attach admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Settings → Simple Performance submenu page.
	 */
	public function add_menu() {
		add_options_page(
			__( 'Simple Performance', 'simple-performance-for-wordpress' ),
			__( 'Simple Performance', 'simple-performance-for-wordpress' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the React app's mount point.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<div id="spfw-admin-root" class="spfw-admin-isolated">
				<p class="description">
					<?php esc_html_e( 'Loading settings…', 'simple-performance-for-wordpress' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue the compiled admin app only on our settings screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}

		$asset_file_path = SPFW_PATH . 'build/index.asset.php';
		$dependencies     = array( 'wp-element', 'wp-api-fetch', 'wp-i18n' );
		$version          = SPFW_VERSION;

		if ( file_exists( $asset_file_path ) ) {
			$asset_file   = include $asset_file_path;
			$dependencies = isset( $asset_file['dependencies'] ) ? $asset_file['dependencies'] : $dependencies;
			$version      = isset( $asset_file['version'] ) ? $asset_file['version'] : $version;
		}

		wp_enqueue_script( 'spfw-admin-js', SPFW_URL . 'build/index.js', $dependencies, $version, true );
		wp_enqueue_style( 'spfw-admin-css', SPFW_URL . 'build/index.css', array(), $version );

		wp_localize_script(
			'spfw-admin-js',
			'spfwAdminData',
			array(
				'restUrl'            => esc_url_raw( rest_url( 'spfw/v1' ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'settings'           => SPFW_Settings::get(),
				'woocommerceActive'  => class_exists( 'WooCommerce' ),
			)
		);
	}
}
