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
	 * Register the settings route.
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
	 * GET callback: current settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return new WP_REST_Response( SPFW_Settings::get(), 200 );
	}

	/**
	 * POST callback: persist submitted settings (sanitized internally by
	 * SPFW_Settings::update()) and return the refreshed state.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params = $request->get_json_params();

		SPFW_Settings::update( is_array( $params ) ? $params : array() );

		return new WP_REST_Response( SPFW_Settings::get(), 200 );
	}
}
