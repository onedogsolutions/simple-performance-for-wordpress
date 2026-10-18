<?php
/**
 * Tests for the MainWP bridge's file-monitor actions (2.21.0) and the
 * sync-time scan (2.21.1).
 *
 * The dashboard extension compares a plugin version's files across every
 * managed site to find the one that differs, and can verify the copies that
 * agree. These tests pin the wire envelope, the capability gate, slug and
 * scope rejection, the fingerprint, and every refusal path of consensus
 * verification: the child only accepts when its own disk matches the
 * fingerprint the majority reported.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

require_once SPFW_PATH . 'includes/class-spfw-mainwp-child.php';

/**
 * Bridge envelope, fingerprints, and consensus verification.
 */
class MainWP_Bridge_Test extends TestCase {

	/**
	 * Fresh option/transient state, monitor on, caller is an admin.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_transients, $spfw_test_cron, $spfw_test_mail, $spfw_test_http, $spfw_test_capabilities, $spfw_test_user_id;
		$spfw_test_options      = array(
			'spfw_settings' => array(
				'version'   => '99.0.0',
				'hardening' => array(
					'file_monitor_enabled' => true,
					'file_monitor_email'   => 'admin@example.com',
				),
			),
			'blogname'      => 'Example',
		);
		$spfw_test_transients   = array();
		$spfw_test_cron         = array();
		$spfw_test_mail         = array();
		$spfw_test_http         = array();
		$spfw_test_capabilities = array( 'manage_options' => true );
		$spfw_test_user_id      = 3;

		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );

		$this->cleanup();
	}

	/**
	 * Remove fixture trees and reset the caller.
	 */
	protected function tearDown(): void {
		global $spfw_test_capabilities, $spfw_test_user_id;
		$spfw_test_capabilities = array();
		$spfw_test_user_id      = 0;

		$this->cleanup();
		parent::tearDown();
	}

	/**
	 * Delete the scan roots.
	 */
	private function cleanup() {
		foreach ( array( WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/uploads' ) as $dir ) {
			$this->delete_tree( $dir );
		}
	}

	/**
	 * Recursively remove a fixture directory.
	 *
	 * @param string $dir Directory.
	 */
	private function delete_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->delete_tree( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Write a file, creating parents.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents Contents.
	 */
	private function put( $path, $contents ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Install a plugin version straight into wp-content/plugins.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version header.
	 */
	private function make_plugin( $slug, $version ) {
		$dir = WP_CONTENT_DIR . "/plugins/$slug";
		$this->put( "$dir/$slug.php", "<?php\n/*\nPlugin Name: $slug\nVersion: $version\n*/\n" );
		$this->put( "$dir/includes/core.php", "<?php // $slug $version\n" );
	}

	/**
	 * Run one bridge action and return its envelope.
	 *
	 * @param array $post Request fields.
	 * @return array
	 */
	private function call( array $post ) {
		$bridge = new SPFW_MainWP_Child();
		$out    = $bridge->handle( array(), $post );

		$this->assertArrayHasKey( 'spfw', $out );

		return $out['spfw'];
	}

	/**
	 * Copy a plugin in "by SFTP": a version change nothing can verify, so it
	 * is pending as an unverified update.
	 *
	 * @return SPFW_Module_Hardening
	 */
	private function pending_update() {
		$this->make_plugin( 'premium', '1.0' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->make_plugin( 'premium', '1.1' );
		$scan = $module->scan_wp_content();
		$this->assertArrayHasKey( 'premium', $scan['updates'] );

		return $module;
	}

	// ---------------------------------------------------------------------

	/**
	 * Settings reads now carry the root status and the review state.
	 */
	public function test_get_settings_carries_root_status_and_file_monitor() {
		$env = $this->call( array( 'spfw_action' => 'get_settings' ) );

		$this->assertTrue( $env['success'] );
		$this->assertArrayHasKey( 'root_hardening_status', $env['settings'] );
		$this->assertSame( array( 'last_scan', 'snapshot_count', 'pending', 'verified' ), array_keys( $env['settings']['file_monitor'] ) );
		$this->assertArrayHasKey( 'signatures', $env['settings']['file_monitor']['pending'] );
	}

	/**
	 * File actions need manage_options; settings actions are unchanged.
	 */
	public function test_file_actions_require_manage_options() {
		global $spfw_test_capabilities;
		$spfw_test_capabilities = array();

		foreach ( SPFW_MainWP_Child::FILE_ACTIONS as $action ) {
			$env = $this->call( array( 'spfw_action' => $action ) );
			$this->assertFalse( $env['success'], $action );
			$this->assertSame( 'insufficient permissions', $env['error'] );
		}
	}

	/**
	 * Slugs and scopes that could walk out of plugins/ are refused.
	 */
	public function test_bad_slug_and_scope_are_refused() {
		foreach ( array( '../wp-config.php', 'a/b', '' ) as $slug ) {
			$env = $this->call( array( 'spfw_action' => 'plugin_files', 'slug' => $slug ) );
			$this->assertFalse( $env['success'], $slug );
			$this->assertSame( 'spfw_bad_scope', $env['code'] );
		}

		$env = $this->call( array( 'spfw_action' => 'verify_files', 'scope' => '../x', 'signature' => 'x' ) );
		$this->assertSame( 'spfw_bad_scope', $env['code'] );

		$this->assertSame( array(), SPFW_Module_Hardening::plugin_relative_hashes( '..' ) );
	}

	/**
	 * Fingerprints are per plugin, site-independent, and change with content.
	 */
	public function test_fingerprints_list_every_plugin_and_track_content() {
		$this->make_plugin( 'alpha', '1.0' );
		$this->make_plugin( 'beta', '2.0' );

		$env     = $this->call( array( 'spfw_action' => 'plugin_fingerprints' ) );
		$plugins = $env['data']['plugins'];

		$this->assertSame( array( 'alpha', 'beta' ), array_keys( $plugins ) );
		$this->assertSame( '1.0', $plugins['alpha']['version'] );
		$this->assertSame( 2, $plugins['alpha']['count'] );
		$this->assertFalse( $plugins['alpha']['pending'] );
		$this->assertSame( 0, $env['data']['uploads_pending'] );

		// The fingerprint is a hash of relative paths, so two plugins with the
		// same relative files and contents would agree wherever they live.
		$files    = SPFW_Module_Hardening::plugin_relative_hashes( 'alpha' );
		$expected = array();
		foreach ( $files as $rel => $hash ) {
			$expected[] = "$rel:$hash";
		}
		sort( $expected );
		$this->assertSame( hash( 'sha256', implode( "\n", $expected ) ), $plugins['alpha']['fingerprint'] );
		$this->assertSame( array( 'alpha.php', 'includes/core.php' ), array_keys( $files ) );

		$this->put( WP_CONTENT_DIR . '/plugins/alpha/includes/core.php', "<?php // injected\n" );
		$after = SPFW_Module_Hardening::plugin_fingerprint( 'alpha' );
		$this->assertNotSame( $plugins['alpha']['fingerprint'], $after['fingerprint'] );
	}

	/**
	 * A pending update is flagged so the dashboard knows who needs verifying.
	 */
	public function test_fingerprints_flag_pending_plugins() {
		$this->pending_update();

		$env = $this->call( array( 'spfw_action' => 'plugin_fingerprints' ) );
		$this->assertTrue( $env['data']['plugins']['premium']['pending'] );
	}

	/**
	 * plugin_files returns relative paths for the drill-down diff.
	 */
	public function test_plugin_files_returns_relative_hashes() {
		$this->make_plugin( 'alpha', '1.0' );

		$env = $this->call( array( 'spfw_action' => 'plugin_files', 'slug' => 'alpha' ) );
		$this->assertTrue( $env['success'] );
		$this->assertSame( array( 'alpha.php', 'includes/core.php' ), array_keys( $env['data']['files'] ) );
	}

	/**
	 * A matching fingerprint at the right version is accepted, as consensus.
	 */
	public function test_consensus_accepts_matching_copy() {
		$this->pending_update();
		$fp = SPFW_Module_Hardening::plugin_fingerprint( 'premium' );

		$env = $this->call(
			array(
				'spfw_action' => 'verify_consensus',
				'slug'        => 'premium',
				'version'     => '1.1',
				'fingerprint' => $fp['fingerprint'],
				'agreeing'    => '3',
			)
		);

		$this->assertTrue( $env['success'] );
		$this->assertArrayNotHasKey( 'premium', $env['data']['scan_result']['updates'] );

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertSame( 'consensus', $store['verified']['source'] );
		$this->assertSame( 3, $store['verified']['user_id'] );
		$this->assertSame( '1.1', $store['versions']['premium'] );
	}

	/**
	 * Wrong fingerprint, wrong version, or too few agreeing sites: refused,
	 * and the update stays pending.
	 */
	public function test_consensus_refusals_leave_update_pending() {
		$this->pending_update();
		$fp = SPFW_Module_Hardening::plugin_fingerprint( 'premium' )['fingerprint'];

		$cases = array(
			'fingerprint' => array( '1.1', hash( 'sha256', 'other' ), 3, 'spfw_files_changed' ),
			'version'     => array( '1.0', $fp, 3, 'spfw_files_changed' ),
			'quorum'      => array( '1.1', $fp, 1, 'spfw_consensus_too_small' ),
		);

		foreach ( $cases as $label => $case ) {
			$env = $this->call(
				array(
					'spfw_action' => 'verify_consensus',
					'slug'        => 'premium',
					'version'     => $case[0],
					'fingerprint' => $case[1],
					'agreeing'    => $case[2],
				)
			);

			$this->assertFalse( $env['success'], $label );
			$this->assertSame( $case[3], $env['code'], $label );
		}

		// Edited after the comparison: the old fingerprint no longer matches.
		$this->put( WP_CONTENT_DIR . '/plugins/premium/includes/core.php', "<?php // edited\n" );
		$env = $this->call(
			array(
				'spfw_action' => 'verify_consensus',
				'slug'        => 'premium',
				'version'     => '1.1',
				'fingerprint' => $fp,
				'agreeing'    => 3,
			)
		);
		$this->assertSame( 'spfw_files_changed', $env['code'] );

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertArrayHasKey( 'premium', $store['pending']['updates'] );
	}

	/**
	 * Mark as verified from the dashboard records its own source.
	 */
	public function test_remote_verify_files_records_mainwp_source() {
		$module = $this->pending_update();
		$sig    = $module->scan_wp_content()['signatures']['plugins']['premium'];

		$env = $this->call( array( 'spfw_action' => 'verify_files', 'scope' => 'premium', 'signature' => $sig ) );
		$this->assertTrue( $env['success'] );
		$this->assertSame( 'mainwp', SPFW_Module_Hardening::get_file_snapshot()['verified']['source'] );

		$stale = $this->call( array( 'spfw_action' => 'verify_files', 'scope' => 'premium', 'signature' => $sig ) );
		$this->assertFalse( $stale['success'] );
		$this->assertSame( 'spfw_files_changed', $stale['code'] );
	}

	/**
	 * scan_files returns the scan plus the refreshed review state.
	 */
	public function test_scan_files_returns_scan_and_state() {
		$this->make_plugin( 'alpha', '1.0' );

		$env = $this->call( array( 'spfw_action' => 'scan_files' ) );
		$this->assertTrue( $env['success'] );
		$this->assertTrue( $env['data']['scan_result']['initial'] );
		$this->assertSame( 2, $env['data']['file_monitor']['snapshot_count'] );
	}

	/**
	 * The stored snapshot exactly as it sits in the option store.
	 *
	 * @return string
	 */
	private function snapshot_bytes() {
		global $spfw_test_options;

		return serialize( $spfw_test_options[ SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Push the stored scan time into the past, so any write a call makes
	 * (a scan stamps `time`) shows up in snapshot_bytes().
	 */
	private function age_snapshot() {
		global $spfw_test_options;

		$spfw_test_options[ SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION ]['time'] = 1000;
	}

	/**
	 * Run the sync filter the way MainWP Child does.
	 *
	 * @param array $others The dashboard's decoded othersData.
	 * @return array Sync response.
	 */
	private function sync( array $others = array( SPFW_MainWP_Child::SYNC_KEY => 1 ) ) {
		return ( new SPFW_MainWP_Child() )->sync( array( 'version' => '6.3' ), $others );
	}

	/**
	 * A stale signature is refused and the snapshot option is left
	 * byte-identical: no new time, signatures, pending, or entries. The
	 * refusal still re-hashed the disk, which is what caught the edit.
	 */
	public function test_refused_verify_files_writes_nothing() {
		$module = $this->pending_update();
		$sig    = $module->scan_wp_content()['signatures']['plugins']['premium'];

		// Edited after the review: the fresh re-hash must refuse.
		$this->put( WP_CONTENT_DIR . '/plugins/premium/includes/core.php', "<?php // edited after review\n" );
		$this->age_snapshot();
		$before = $this->snapshot_bytes();

		foreach ( array( 'premium' => $sig, 'all' => $sig, 'uploads' => $sig, 'nosuch' => $sig ) as $scope => $signature ) {
			$env = $this->call( array( 'spfw_action' => 'verify_files', 'scope' => $scope, 'signature' => $signature ) );
			$this->assertFalse( $env['success'], $scope );
			$this->assertSame( 'spfw_files_changed', $env['code'], $scope );
			$this->assertSame( $before, $this->snapshot_bytes(), $scope );
		}

		$env = $this->call( array( 'spfw_action' => 'verify_files', 'scope' => '../x', 'signature' => $sig ) );
		$this->assertSame( 'spfw_bad_scope', $env['code'] );
		$this->assertSame( $before, $this->snapshot_bytes() );

		// Directly, without the bridge.
		$this->assertInstanceOf( 'WP_Error', $module->verify_files( $sig, 'premium', 3 ) );
		$this->assertSame( $before, $this->snapshot_bytes() );
	}

	/**
	 * The signature is checked against a fresh re-hash, not the stored one:
	 * after a refusal the reviewer rescans, and the new signature verifies.
	 */
	public function test_verify_files_after_refusal_and_rescan_succeeds() {
		$module = $this->pending_update();
		$sig    = $module->scan_wp_content()['signatures']['plugins']['premium'];

		$this->put( WP_CONTENT_DIR . '/plugins/premium/includes/core.php', "<?php // edited after review\n" );
		$this->assertInstanceOf( 'WP_Error', $module->verify_files( $sig, 'premium', 3 ) );

		$fresh = $module->scan_wp_content()['signatures']['plugins']['premium'];
		$this->assertNotSame( $sig, $fresh );

		$result = $module->verify_files( $fresh, 'premium', 3 );
		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'premium', $result['updates'] );

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertSame( hash_file( 'sha256', WP_CONTENT_DIR . '/plugins/premium/includes/core.php' ), $store['entries']['plugins/premium/includes/core.php'] );
		$this->assertSame( '1.1', $store['versions']['premium'] );
	}

	/**
	 * Every consensus refusal leaves the snapshot option byte-identical.
	 */
	public function test_refused_consensus_writes_nothing() {
		$this->pending_update();
		$fp = SPFW_Module_Hardening::plugin_fingerprint( 'premium' )['fingerprint'];
		$this->age_snapshot();
		$before = $this->snapshot_bytes();

		$cases = array(
			'fingerprint' => array( 'premium', '1.1', hash( 'sha256', 'other' ), 3, 'spfw_files_changed' ),
			'version'     => array( 'premium', '1.0', $fp, 3, 'spfw_files_changed' ),
			'quorum'      => array( 'premium', '1.1', $fp, 1, 'spfw_consensus_too_small' ),
			'missing'     => array( 'nosuch', '1.1', $fp, 3, 'spfw_files_changed' ),
			'bad slug'    => array( '../x', '1.1', $fp, 3, 'spfw_bad_scope' ),
		);

		foreach ( $cases as $label => $case ) {
			$env = $this->call(
				array(
					'spfw_action' => 'verify_consensus',
					'slug'        => $case[0],
					'version'     => $case[1],
					'fingerprint' => $case[2],
					'agreeing'    => $case[3],
				)
			);

			$this->assertFalse( $env['success'], $label );
			$this->assertSame( $case[4], $env['code'], $label );
			$this->assertSame( $before, $this->snapshot_bytes(), $label );
		}
	}

	/**
	 * Sync only answers when the dashboard asks, and only to an admin.
	 */
	public function test_sync_needs_the_flag_and_an_admin() {
		global $spfw_test_options, $spfw_test_capabilities;

		$this->make_plugin( 'alpha', '1.0' );

		$this->assertSame( array( 'version' => '6.3' ), $this->sync( array() ) );
		$this->assertSame( array( 'version' => '6.3' ), $this->sync( array( SPFW_MainWP_Child::SYNC_KEY => 0 ) ) );

		$spfw_test_capabilities = array();
		$this->assertSame( array( 'version' => '6.3' ), $this->sync() );

		$this->assertArrayNotHasKey( SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION, $spfw_test_options );
	}

	/**
	 * With the monitor off, sync reports that and scans nothing.
	 */
	public function test_sync_with_monitor_off_does_not_scan() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings']['hardening']['file_monitor_enabled'] = false;
		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );

		$this->make_plugin( 'alpha', '1.0' );

		$out    = $this->sync();
		$report = $out[ SPFW_MainWP_Child::SYNC_KEY ];

		$this->assertSame( '6.3', $out['version'] );
		$this->assertFalse( $report['enabled'] );
		$this->assertFalse( $report['scanned'] );
		$this->assertArrayNotHasKey( 'plugins', $report );
		$this->assertSame( 0, $report['file_monitor']['last_scan'] );
		$this->assertSame( 0, $report['file_monitor']['snapshot_count'] );
	}

	/**
	 * Sync scans and fingerprints from one pass; the fingerprints match the
	 * ones plugin_fingerprints() computes by re-hashing each plugin.
	 */
	public function test_sync_scans_and_fingerprints() {
		$this->make_plugin( 'alpha', '1.0' );
		$this->make_plugin( 'beta', '2.0' );
		$this->put( WP_CONTENT_DIR . '/plugins/hello.php', "<?php\n/*\nPlugin Name: Hello\nVersion: 1.7\n*/\n" );

		$report = $this->sync()[ SPFW_MainWP_Child::SYNC_KEY ];

		$this->assertTrue( $report['enabled'] );
		$this->assertTrue( $report['scanned'] );
		$this->assertSame( SPFW_VERSION, $report['version'] );
		$this->assertSame( ( new SPFW_Module_Hardening() )->plugin_fingerprints(), $report['plugins'] );
		$this->assertSame( array( 'alpha', 'beta', 'hello.php' ), array_keys( $report['plugins'] ) );
		$this->assertSame( 0, $report['uploads_pending'] );
		$this->assertSame( 5, $report['file_monitor']['snapshot_count'] );
		$this->assertGreaterThan( 0, $report['file_monitor']['last_scan'] );
	}

	/**
	 * A scan stored a moment ago is reused: no re-hash, no write, no
	 * fingerprints. Once it ages past the interval, sync scans again.
	 */
	public function test_sync_reuses_a_recent_scan() {
		$this->pending_update();
		$before = $this->snapshot_bytes();

		$report = $this->sync()[ SPFW_MainWP_Child::SYNC_KEY ];
		$this->assertFalse( $report['scanned'] );
		$this->assertArrayNotHasKey( 'plugins', $report );
		$this->assertArrayHasKey( 'premium', $report['file_monitor']['pending']['updates'] );
		$this->assertSame( $before, $this->snapshot_bytes() );

		$this->age_snapshot();
		$report = $this->sync()[ SPFW_MainWP_Child::SYNC_KEY ];
		$this->assertTrue( $report['scanned'] );
		$this->assertTrue( $report['plugins']['premium']['pending'] );
		$this->assertGreaterThan( 1000, $report['file_monitor']['last_scan'] );
	}

	/**
	 * A sync scan alerts like the cron scan: an unexplained uploads PHP file
	 * is emailed once, and a second sync does not send it again.
	 */
	public function test_sync_scan_routes_alerts_once() {
		global $spfw_test_mail, $spfw_test_transients;

		$this->make_plugin( 'alpha', '1.0' );
		$this->sync();
		$this->assertSame( array(), $spfw_test_mail );

		$this->put( WP_CONTENT_DIR . '/uploads/2026/10/shell.php', "<?php system( \$_GET['c'] );\n" );
		$this->age_snapshot();
		$report = $this->sync()[ SPFW_MainWP_Child::SYNC_KEY ];

		$this->assertSame( 1, $report['uploads_pending'] );
		$this->assertCount( 1, $spfw_test_mail );

		$spfw_test_transients = array();
		$this->age_snapshot();
		$this->sync();
		$this->assertCount( 1, $spfw_test_mail );
	}
}
