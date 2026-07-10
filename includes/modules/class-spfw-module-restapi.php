<?php
/**
 * Module 2: Advanced REST API controls.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whitelist-first REST API gating: optional global auth requirement,
 * per-namespace unregistration (anti-enumeration), and an always-on
 * whitelist that also protects the plugin's own settings API.
 */
class SPFW_Module_RestApi implements SPFW_Module {

	/**
	 * This plugin's own REST namespace — always exempt from every
	 * restriction below so the Settings screen can never lock itself out.
	 */
	const OWN_NAMESPACE = 'spfw/v1';

	/**
	 * Attach REST restriction filters based on current settings.
	 */
	public function register() {
		$r = SPFW_Settings::group( 'restapi' );

		if ( ! empty( $r['disabled_namespaces'] ) ) {
			add_filter( 'rest_endpoints', array( $this, 'unregister_disabled_namespaces' ) );
		}

		if ( ! empty( $r['require_auth'] ) || ! empty( $r['disabled_namespaces'] ) ) {
			add_filter( 'rest_authentication_errors', array( $this, 'authenticate_request' ) );
		}
	}

	/**
	 * Remove disabled-namespace routes from the REST route table entirely
	 * (unless whitelisted), so they never appear in the /wp-json/ index.
	 *
	 * @param array $endpoints Registered REST endpoints, keyed by route.
	 * @return array
	 */
	public function unregister_disabled_namespaces( $endpoints ) {
		$r = SPFW_Settings::group( 'restapi' );

		foreach ( $endpoints as $route => $handlers ) {
			$normalized = ltrim( $route, '/' );

			if ( $this->route_in_list( $normalized, $r['disabled_namespaces'] )
				&& ! $this->route_in_list( $normalized, $r['whitelist_routes'] )
			) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	/**
	 * Global auth gate plus a belt-and-suspenders 404 for disabled
	 * namespaces (in case a route slips past unregistration, e.g. one
	 * registered after this filter ran).
	 *
	 * @param WP_Error|null|true $result Prior authentication result.
	 * @return WP_Error|null|true
	 */
	public function authenticate_request( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$r     = SPFW_Settings::group( 'restapi' );
		$route = ltrim( isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '', '/' );

		if ( '' === $route || $this->route_in_list( $route, $r['whitelist_routes'] ) ) {
			return $result;
		}

		if ( ! empty( $r['require_auth'] ) && ! is_user_logged_in() ) {
			return new WP_Error(
				'spfw_rest_forbidden',
				__( 'REST API restricted to authenticated users.', 'simple-performance-for-wordpress' ),
				array( 'status' => 401 )
			);
		}

		if ( $this->route_in_list( $route, $r['disabled_namespaces'] ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.', 'simple-performance-for-wordpress' ),
				array( 'status' => 404 )
			);
		}

		return $result;
	}

	/**
	 * Whether a route equals or is prefixed by any entry in a list. The
	 * plugin's own settings namespace is always treated as whitelisted,
	 * regardless of what's configured.
	 *
	 * @param string   $route Normalized route (no leading slash).
	 * @param string[] $list  Prefixes to match against.
	 * @return bool
	 */
	private function route_in_list( $route, array $list ) {
		if ( self::OWN_NAMESPACE === $route || 0 === strpos( $route, self::OWN_NAMESPACE . '/' ) ) {
			return true;
		}

		foreach ( $list as $item ) {
			if ( $route === $item || 0 === strpos( $route, $item . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
