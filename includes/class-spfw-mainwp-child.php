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
	 * Key the dashboard extension sets in a sync request's `othersData` to
	 * ask for the file monitor, and the key the answer goes back under.
	 */
	const SYNC_KEY = 'spfwFileMonitor';

	/**
	 * Hook the MainWP Child extra-execution and sync filters.
	 */
	public function __construct() {
		add_filter( 'mainwp_child_extra_execution', array( $this, 'handle' ), 10, 2 );
		add_filter( 'mainwp_site_sync_others_data', array( $this, 'sync' ), 10, 2 );
	}

	/**
	 * Answer a MainWP sync: scan, fingerprint every plugin, and send the
	 * review state, so the dashboard holds a current picture of every site
	 * without anyone opening its Hardening tab.
	 *
	 * Only when the dashboard asked (its extension sets SYNC_KEY), the caller
	 * is an administrator (the same bar as the file actions), and the monitor
	 * is on. With the monitor off the answer says so and carries the stored
	 * state; nothing is hashed or written. A scan stored in the last
	 * SPFW_Module_Hardening::SYNC_SCAN_INTERVAL seconds is reused, and the
	 * answer then has no fingerprints (`scanned` false). A failure is reported
	 * in the answer and never breaks the rest of the sync.
	 *
	 * @param array $information Sync response MainWP Child is building.
	 * @param array $data        The dashboard's decoded `othersData`.
	 * @return array
	 */
	public function sync( $information, $data = array() ) {
		if ( ! is_array( $information ) || ! is_array( $data ) || empty( $data[ self::SYNC_KEY ] ) || ! current_user_can( 'manage_options' ) ) {
			return $information;
		}

		$report = array(
			'version' => SPFW_VERSION,
			'enabled' => ! empty( SPFW_Settings::group( 'hardening' )['file_monitor_enabled'] ),
			'scanned' => false,
		);

		try {
			if ( $report['enabled'] ) {
				/**
				 * Filters how many seconds a stored scan stays fresh enough
				 * for a MainWP sync to reuse it. 0 scans on every sync.
				 *
				 * @param int $interval Seconds. Default 300.
				 */
				$interval = (int) apply_filters( 'spfw_mainwp_sync_scan_interval', SPFW_Module_Hardening::SYNC_SCAN_INTERVAL );
				$scan     = ( new SPFW_Module_Hardening() )->sync_scan( $interval );

				if ( null !== $scan ) {
					$report['scanned']         = true;
					$report['plugins']         = $scan['plugins'];
					$report['uploads_pending'] = self::uploads_pending();
				}
			}

			$report['file_monitor'] = SPFW_Rest_Settings::file_monitor_state();
		} catch ( Throwable $e ) {
			$report['error'] = 'file monitor scan failed';
		}

		$information[ self::SYNC_KEY ] = $report;

		return $information;
	}

	/**
	 * How many unexplained changes wait in uploads. Uploads have no versions
	 * to compare across sites; the dashboard only reports the count.
	 *
	 * @return int
	 */
	private static function uploads_pending() {
		$queued = 0;

		foreach ( SPFW_Module_Hardening::get_file_snapshot()['pending']['unexplained'] as $paths ) {
			foreach ( $paths as $path ) {
				if ( 0 === strpos( $path, 'uploads/' ) ) {
					++$queued;
				}
			}
		}

		return $queued;
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
				return array(
					'plugins'         => $module->plugin_fingerprints(),
					'uploads_pending' => self::uploads_pending(),
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
