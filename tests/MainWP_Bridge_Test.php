<?php
/**
 * Tests for the MainWP bridge's file-monitor actions (2.21.0).
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
}
