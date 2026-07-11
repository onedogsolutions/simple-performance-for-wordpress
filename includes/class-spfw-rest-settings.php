<?php
/**
 * REST API controller for the plugin's own settings.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET/POST /spfw/v1/settings for the React admin app. Must load
 * unconditionally (REST requests are not admin context, so `rest_api_init`
 * never fires if this were only required inside an is_admin() branch).
 */
class SPFW_Rest_Settings {

	const NAMESPACE_ = 'spfw/v1';

	/**
	 * Register REST hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the settings routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/settings/restore-htaccess',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_htaccess' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/settings/scan-fonts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_fonts' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}

	/**
	 * Capability check shared by both routes.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET callback: current settings plus computed, read-only status
	 * fields the React app needs without a second round trip.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		$settings                             = SPFW_Settings::get();
		$settings['hardening_status']         = SPFW_Htaccess::status( 'plugins' );
		$settings['uploads_hardening_status'] = SPFW_Htaccess::status( 'uploads' );
		$settings['csp_default']              = SPFW_Module_Hardening::DEFAULT_CSP;

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * POST callback: persist submitted settings (sanitized internally by
	 * SPFW_Settings::update(), which ignores unknown top-level keys such
	 * as the computed hardening_status echoed back by get_settings())
	 * and return the refreshed state.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params = $request->get_json_params();

		SPFW_Settings::update( is_array( $params ) ? $params : array() );

		// Many toggles alter cached front-end HTML (head links, favicon,
		// Google Maps, WooCommerce assets), so purge LiteSpeed Cache. Harmless
		// no-op when LSCache is not installed (no listeners on the action).
		do_action( 'litespeed_purge_all' );

		return $this->get_settings();
	}

	/**
	 * POST callback: rewrite a hardening file (plugins or uploads) and
	 * return the refreshed state (used by the Hardening tab's Restore
	 * button — no page reload needed). Surfaces a 500 when the write fails
	 * (e.g. the server has no direct filesystem write access) so the button
	 * shows an actionable error instead of a false success.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function restore_htaccess( $request ) {
		$params = $request->get_json_params();
		$target = ( is_array( $params ) && isset( $params['target'] ) && 'uploads' === $params['target'] )
			? 'uploads'
			: 'plugins';

		if ( ! SPFW_Htaccess::write( $target ) ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Could not write the hardening file. The web server may not have direct write access to this directory.', 'simple-performance-for-wordpress' ),
				),
				500
			);
		}

		return $this->get_settings();
	}

	/**
	 * POST callback: run the Google Fonts discovery/download scan and
	 * return the refreshed state plus a `scan_result` summary (families,
	 * file count, and a human-readable message) so the React tab can show a
	 * "no fonts found" state without a second round trip.
	 *
	 * @return WP_REST_Response
	 */
	public function scan_fonts() {
		$result = ( new SPFW_Module_Fonts() )->scan();

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		$response                = $this->get_settings();
		$data                    = $response->get_data();
		$data['scan_result']     = $result;
		$response->set_data( $data );

		return $response;
	}
}
