<?php
/**
 * REST API controller for the Option Cleaner and Capability Cleaner.
 *
 * Registers endpoints under the simple-performance/v1 namespace for
 * scanning and deleting orphaned wp_options entries and ghost
 * capabilities left behind by uninstalled plugins.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes scan/delete routes for orphaned options and ghost
 * capabilities. Must load unconditionally — REST requests are not
 * admin context.
 */
class SPFW_Rest_Option_Cleaner {

	const NAMESPACE_ = 'simple-performance/v1';

	/**
	 * Register REST hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the option-cleaner and capability-cleaner routes.
	 */
	public function register_routes() {
		// GET /option-cleaner/scan — scan for orphaned option groups.
		register_rest_route(
			self::NAMESPACE_,
			'/option-cleaner/scan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'scan_options' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'prefix' => array(
						'description'       => 'Optional prefix to search for (manual mode).',
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// POST /option-cleaner/delete — bulk-delete option groups by prefix.
		register_rest_route(
			self::NAMESPACE_,
			'/option-cleaner/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'delete_options' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'prefixes' => array(
						'description'       => 'Array of option prefixes to delete.',
						'type'              => 'array',
						'required'          => true,
						'items'             => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		// GET /option-cleaner/capabilities — scan for ghost capabilities.
		register_rest_route(
			self::NAMESPACE_,
			'/option-cleaner/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'scan_capabilities' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// POST /option-cleaner/capabilities/delete — strip ghost capabilities by prefix.
		register_rest_route(
			self::NAMESPACE_,
			'/option-cleaner/capabilities/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'delete_capabilities' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'prefixes' => array(
						'description'       => 'Array of capability prefixes to strip.',
						'type'              => 'array',
						'required'          => true,
						'items'             => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Capability check shared by all routes.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET callback: scan for orphaned option groups.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function scan_options( $request ) {
		$prefix = $request->get_param( 'prefix' );
		$result = SPFW_Option_Cleaner::scan( $prefix );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST callback: delete options matching the given prefixes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_options( $request ) {
		$prefixes = $request->get_param( 'prefixes' );

		if ( ! is_array( $prefixes ) || empty( $prefixes ) ) {
			return new WP_REST_Response(
				array(
					'deleted' => 0,
					'message' => 'No prefixes provided.',
				),
				400
			);
		}

		$deleted = SPFW_Option_Cleaner::delete_prefixes( $prefixes );

		return new WP_REST_Response(
			array(
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: number of rows deleted */
					_n( '%d row deleted.', '%d rows deleted.', $deleted, 'simple-performance-for-wordpress' ),
					$deleted
				),
			),
			200
		);
	}

	/**
	 * GET callback: scan for orphaned (ghost) capabilities.
	 *
	 * @return WP_REST_Response
	 */
	public function scan_capabilities() {
		$result = SPFW_Capability_Cleaner::scan();

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST callback: strip capabilities matching the given prefixes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function delete_capabilities( $request ) {
		$prefixes = $request->get_param( 'prefixes' );

		if ( ! is_array( $prefixes ) || empty( $prefixes ) ) {
			return new WP_REST_Response(
				array(
					'removed' => 0,
					'message' => 'No prefixes provided.',
				),
				400
			);
		}

		$removed = SPFW_Capability_Cleaner::delete_by_prefix( $prefixes );

		return new WP_REST_Response(
			array(
				'removed' => $removed,
				'message' => sprintf(
					/* translators: %d: number of capabilities removed */
					_n( '%d capability removed.', '%d capabilities removed.', $removed, 'simple-performance-for-wordpress' ),
					$removed
				),
			),
			200
		);
	}
}
