<?php
/**
 * MainWP child-side bridge.
 *
 * Lets the "MainWP for Simple Performance for WordPress" dashboard extension
 * read and update this plugin's settings, and review the file monitor, over
 * MainWP's signed dashboard-to-child channel.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles `spfw_action` requests from the dashboard extension.
 */
class SPFW_MainWP_Child {

	/**
	 * File-monitor actions. These read file hashes or move the verified
	 * baseline, so they carry the same manage_options bar as the REST routes.
	 */
	const FILE_ACTIONS = array( 'scan_files', 'verify_files', 'plugin_fingerprints', 'plugin_files', 'verify_consensus' );

	/**
	 * Slug / scope shape, identical to the REST verify-files check.
	 */
	const SLUG_PATTERN = '/^[A-Za-z0-9._-]+$/';

	/**
	 * Hook the MainWP Child extra-execution filter.
	 */
	public function __construct() {
		add_filter( 'mainwp_child_extra_execution', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * Route one dashboard request and wrap the result in the `spfw` envelope.
	 *
	 * @param array $information Response MainWP Child is building.
	 * @param array $post        Request fields (slashed).
	 * @return array
	 */
	public function handle( $information, $post ) {
		if ( ! is_array( $post ) || empty( $post['spfw_action'] ) ) {
			return $information;
		}

		$action = wp_unslash( $post['spfw_action'] );
		$data   = null;

		if ( 'get_settings' === $action ) {
			$settings                             = SPFW_Settings::get();
			$settings['hardening_status']         = SPFW_Htaccess::status( 'plugins' );
			$settings['uploads_hardening_status'] = SPFW_Htaccess::status( 'uploads' );
			$settings['root_hardening_status']    = SPFW_Htaccess::status( 'root' );
			$settings['file_monitor']             = SPFW_Rest_Settings::file_monitor_state();
		} elseif ( 'update_settings' === $action ) {
			$incoming = json_decode( isset( $post['settings'] ) ? wp_unslash( $post['settings'] ) : '', true );
			if ( ! is_array( $incoming ) ) {
				return $this->fail( $information, 'invalid settings payload' );
			}
			SPFW_Settings::update( $incoming );
			do_action( 'litespeed_purge_all' );
			$settings = SPFW_Settings::get();
		} elseif ( in_array( $action, self::FILE_ACTIONS, true ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return $this->fail( $information, 'insufficient permissions' );
			}

			$data = $this->file_action( $action, $post );

			if ( is_wp_error( $data ) ) {
				$information['spfw'] = array(
					'success' => false,
					'error'   => $data->get_error_message(),
					'code'    => $data->get_error_code(),
					'version' => SPFW_VERSION,
				);
				return $information;
			}

			$settings = null;
		} else {
			return $this->fail( $information, 'unknown action' );
		}

		$information['spfw'] = array(
			'success'            => true,
			'version'            => SPFW_VERSION,
			'woocommerce_active' => class_exists( 'WooCommerce' ),
		);

		if ( null !== $settings ) {
			$information['spfw']['settings'] = $settings;
		}

		if ( null !== $data ) {
			$information['spfw']['data'] = $data;
		}

		return $information;
	}

	/**
	 * Run one file-monitor action.
	 *
	 * @param string $action One of FILE_ACTIONS.
	 * @param array  $post   Request fields (slashed).
	 * @return array|WP_Error
	 */
	private function file_action( $action, array $post ) {
		$module = new SPFW_Module_Hardening();

		switch ( $action ) {
			case 'scan_files':
				return array(
					'scan_result'  => $module->scan_wp_content(),
					'file_monitor' => SPFW_Rest_Settings::file_monitor_state(),
				);

			case 'verify_files':
				$scope = $this->field( $post, 'scope' );
				$scope = '' !== $scope ? $scope : 'all';

				if ( 'all' !== $scope && 'uploads' !== $scope && ! preg_match( self::SLUG_PATTERN, $scope ) ) {
					return new WP_Error( 'spfw_bad_scope', 'unknown verification scope' );
				}

				$result = $module->verify_files( $this->field( $post, 'signature' ), $scope, get_current_user_id(), 'mainwp' );

				return is_wp_error( $result ) ? $result : array(
					'scan_result'  => $result,
					'file_monitor' => SPFW_Rest_Settings::file_monitor_state(),
				);

			case 'plugin_fingerprints':
				$unexplained    = SPFW_Module_Hardening::get_file_snapshot()['pending']['unexplained'];
				$uploads_queued = 0;

				foreach ( $unexplained as $paths ) {
					foreach ( $paths as $path ) {
						if ( 0 === strpos( $path, 'uploads/' ) ) {
							++$uploads_queued;
						}
					}
				}

				return array(
					'plugins'         => $module->plugin_fingerprints(),
					// Uploads have no versions to compare across sites; the
					// dashboard only reports how many changes wait there.
					'uploads_pending' => $uploads_queued,
				);

			case 'plugin_files':
				$slug = $this->field( $post, 'slug' );

				if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
					return new WP_Error( 'spfw_bad_scope', 'unknown plugin' );
				}

				return array(
					'slug'  => $slug,
					'files' => SPFW_Module_Hardening::plugin_relative_hashes( $slug ),
				);

			case 'verify_consensus':
				$slug = $this->field( $post, 'slug' );

				if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
					return new WP_Error( 'spfw_bad_scope', 'unknown plugin' );
				}

				$result = $module->verify_consensus(
					$slug,
					$this->field( $post, 'version' ),
					$this->field( $post, 'fingerprint' ),
					isset( $post['agreeing'] ) ? absint( $post['agreeing'] ) : 0,
					get_current_user_id()
				);

				return is_wp_error( $result ) ? $result : array(
					'scan_result'  => $result,
					'file_monitor' => SPFW_Rest_Settings::file_monitor_state(),
				);
		}

		return new WP_Error( 'spfw_unknown_action', 'unknown action' );
	}

	/**
	 * A scalar request field, unslashed and trimmed ('' when absent).
	 *
	 * @param array  $post Request fields.
	 * @param string $key  Field name.
	 * @return string
	 */
	private function field( array $post, $key ) {
		return isset( $post[ $key ] ) && is_scalar( $post[ $key ] ) ? trim( (string) wp_unslash( $post[ $key ] ) ) : '';
	}

	/**
	 * A failure envelope.
	 *
	 * @param array  $information Response MainWP Child is building.
	 * @param string $error       Message.
	 * @return array
	 */
	private function fail( $information, $error ) {
		$information['spfw'] = array(
			'success' => false,
			'error'   => $error,
		);
		return $information;
	}
}
