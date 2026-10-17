<?php
/**
 * Tests for the file-monitor snapshot's move out of the shared `spfw_settings`
 * option into its own dedicated option (2.17.0).
 *
 * The move is the structural fix for the phantom "file changed" alerts: while
 * the snapshot lived inside the shared option, every SPFW_Settings::update()
 * from any writer was a read-modify-write of the whole option from a
 * per-request cache, so overlapping requests could resurrect a stale snapshot
 * and the next scan re-reported the same diff — including files that no longer
 * existed on disk. These tests pin the new storage contract: the dedicated
 * option is the only home, both migration paths (version-gated in
 * SPFW_Settings::get(), lazy in get_file_snapshot()) copy an embedded snapshot
 * out exactly once, the shared option drops the keys on save, and the scan /
 * baseline-rebuild round-trip through the accessors.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Snapshot storage, migration, scan persistence, and notice-listing tests.
 */
class File_Monitor_Snapshot_Test extends TestCase {

	/**
	 * Reset the option store and static settings cache, and start from a
	 * clean filesystem slate under the bootstrap's temp WP_CONTENT_DIR.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options;
		$spfw_test_options = array();

		$this->reset_settings_cache();
		$this->cleanup_scan_dirs();
	}

	/**
	 * Remove scan fixtures and the snapshot option so state never leaks
	 * between tests.
	 */
	protected function tearDown(): void {
		$this->cleanup_scan_dirs();
		parent::tearDown();
	}

	/**
	 * Clear SPFW_Settings' private static cache so the next read re-derives
	 * from the option store.
	 */
	private function reset_settings_cache() {
		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );
	}

	/**
	 * Delete the fixture trees the scan tests create.
	 */
	private function cleanup_scan_dirs() {
		foreach ( array( WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/uploads' ) as $dir ) {
			$this->delete_tree( $dir );
		}
	}

	/**
	 * Recursively remove a directory tree (fixtures only — never called
	 * outside the bootstrap's temp dir).
	 *
	 * @param string $dir Directory to remove.
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
	 * Seed the shared settings option with a pre-2.17.0 hardening group that
	 * still carries the snapshot inside it.
	 *
	 * @param array  $entries Snapshot entries to embed.
	 * @param int    $time    Last-scan timestamp to embed.
	 * @param string $version Stored version to pin.
	 */
	private function seed_embedded_snapshot( array $entries, $time, $version = '2.16.0' ) {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version'   => $version,
			'hardening' => array(
				'file_monitor_snapshot'  => $entries,
				'file_monitor_last_scan' => $time,
			),
		);

		$this->reset_settings_cache();
	}

	/**
	 * Create a scannable PHP file under one of the scan roots.
	 *
	 * @param string $relative Path relative to WP_CONTENT_DIR.
	 * @param string $contents File contents.
	 */
	private function make_scan_file( $relative, $contents = "<?php // fixture\n" ) {
		$path = WP_CONTENT_DIR . '/' . $relative;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	// ---------------------------------------------------------------------
	// Migration: version-gated (SPFW_Settings::get()) and lazy (accessor).
	// ---------------------------------------------------------------------

	/**
	 * The first settings load after the upgrade copies an embedded snapshot
	 * into the dedicated option — before any sanitize() in the same request
	 * can drop the now-unknown keys — and the copy is exactly once: a second
	 * load never overwrites the dedicated option.
	 */
	public function test_settings_get_migrates_embedded_snapshot_once() {
		global $spfw_test_options;

		$entries = array(
			'plugins/akismet/akismet.php' => str_repeat( 'a', 64 ),
			'uploads/2026/01/evil.php'    => str_repeat( 'b', 64 ),
		);

		$this->seed_embedded_snapshot( $entries, 1700000000 );

		SPFW_Settings::get();

		$option_key = SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION;
		$this->assertArrayHasKey( $option_key, $spfw_test_options, 'the dedicated option was not created' );
		$this->assertSame( 1700000000, $spfw_test_options[ $option_key ]['time'] );
		$this->assertSame( $entries, $spfw_test_options[ $option_key ]['entries'] );

		// A later, legitimate scan result must survive subsequent get() calls
		// even though the old embedded keys are still in the stored option.
		SPFW_Module_Hardening::save_file_snapshot( array( 'plugins/fresh/fresh.php' => str_repeat( 'c', 64 ) ), 1800000000 );

		$this->reset_settings_cache();
		SPFW_Settings::get();

		$this->assertSame(
			1800000000,
			$spfw_test_options[ $option_key ]['time'],
			'the migration must not re-run over a newer dedicated-option value'
		);
	}

	/**
	 * The lazy fallback: a read that races ahead of the version-gated
	 * migration (or arrives with a stored version that never triggers it)
	 * still finds the embedded snapshot and moves it, rather than reporting
	 * an empty baseline and mass-emailing "removed" alerts.
	 */
	public function test_get_file_snapshot_lazily_migrates_embedded_snapshot() {
		global $spfw_test_options;

		$entries = array( 'plugins/hello/hello.php' => str_repeat( 'd', 64 ) );

		// Version 99.0.0 bypasses every version gate in get(), so only the
		// accessor's own fallback can perform the copy.
		$this->seed_embedded_snapshot( $entries, 1600000000, '99.0.0' );

		$snapshot = SPFW_Module_Hardening::get_file_snapshot();

		$this->assertSame( 1600000000, $snapshot['time'] );
		$this->assertSame( $entries, $snapshot['entries'] );

		$option_key = SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION;
		$this->assertArrayHasKey( $option_key, $spfw_test_options );
		$this->assertSame( $entries, $spfw_test_options[ $option_key ]['entries'] );
	}

	/**
	 * With the dedicated option populated, the accessor reads it and ignores
	 * any stale keys still sitting in the shared settings option.
	 */
	public function test_get_file_snapshot_prefers_dedicated_option_over_stale_settings_keys() {
		$this->seed_embedded_snapshot( array( 'plugins/stale/stale.php' => 'x' ), 1500000000, '99.0.0' );

		SPFW_Module_Hardening::save_file_snapshot( array( 'plugins/current/current.php' => 'y' ), 1700000000 );

		$snapshot = SPFW_Module_Hardening::get_file_snapshot();

		$this->assertSame( 1700000000, $snapshot['time'] );
		$this->assertSame( array( 'plugins/current/current.php' => 'y' ), $snapshot['entries'] );
	}

	/**
	 * A fresh install (no embedded snapshot anywhere) reads an empty baseline
	 * without error, so the first scan reports every file as added exactly
	 * once and then converges.
	 */
	public function test_get_file_snapshot_returns_empty_shape_on_fresh_install() {
		$snapshot = SPFW_Module_Hardening::get_file_snapshot();

		$this->assertSame( 0, $snapshot['time'] );
		$this->assertSame( array(), $snapshot['entries'] );
	}

	// ---------------------------------------------------------------------
	// The shared option no longer round-trips the snapshot keys.
	// ---------------------------------------------------------------------

	/**
	 * defaults() and sanitize() both dropped the keys, so a settings save can
	 * never write a snapshot back into the shared option — and an arriving
	 * pre-2.17.0 payload has its snapshot keys dropped on the floor instead
	 * of re-embedding a 2 MB value.
	 */
	public function test_settings_update_drops_snapshot_keys_from_the_shared_option() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array( 'version' => '99.0.0' );
		$this->reset_settings_cache();

		SPFW_Settings::update(
			array(
				'hardening' => array(
					'plugins_htaccess'       => true,
					'file_monitor_snapshot'  => array( 'plugins/x/x.php' => 'y' ),
					'file_monitor_last_scan' => 12345,
				),
			)
		);

		$stored = $spfw_test_options['spfw_settings']['hardening'];

		$this->assertArrayNotHasKey( 'file_monitor_snapshot', $stored );
		$this->assertArrayNotHasKey( 'file_monitor_last_scan', $stored );
		$this->assertTrue( $stored['plugins_htaccess'], 'real settings still save' );

		$defaults = SPFW_Settings::group( 'hardening' );
		$this->assertArrayNotHasKey( 'file_monitor_snapshot', $defaults );
		$this->assertArrayNotHasKey( 'file_monitor_last_scan', $defaults );
	}

	// ---------------------------------------------------------------------
	// Scan and baseline-rebuild round-trips.
	// ---------------------------------------------------------------------

	/**
	 * scan_wp_content() persists entries and timestamp to the dedicated
	 * option, never to the shared settings option, and a second scan over an
	 * unchanged tree reports no diff — the convergence the clobbering used to
	 * prevent.
	 */
	public function test_scan_persists_to_dedicated_option_and_converges() {
		global $spfw_test_options;

		$this->make_scan_file( 'plugins/hello-dolly/hello.php', "<?php // hello\n" );
		$this->make_scan_file( 'plugins/hello-dolly/readme.txt', "not php\n" );
		$this->make_scan_file( 'uploads/2026/01/dropper.php', "<?php // bad\n" );

		$module  = new SPFW_Module_Hardening();
		$changes = $module->scan_wp_content();

		$this->assertSameSets(
			array( 'plugins/hello-dolly/hello.php', 'uploads/2026/01/dropper.php' ),
			$changes['added'],
			'only PHP-family files are scanned'
		);
		$this->assertSame( array(), array_values( $changes['modified'] ) );
		$this->assertSame( array(), array_values( $changes['removed'] ) );

		$option_key = SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION;
		$this->assertArrayHasKey( $option_key, $spfw_test_options );
		$this->assertSame( $changes['snapshot_time'], $spfw_test_options[ $option_key ]['time'] );
		$this->assertSame(
			array( 'plugins/hello-dolly/hello.php', 'uploads/2026/01/dropper.php' ),
			array_keys( $spfw_test_options[ $option_key ]['entries'] )
		);
		$this->assertSame(
			hash_file( 'sha256', WP_CONTENT_DIR . '/uploads/2026/01/dropper.php' ),
			$spfw_test_options[ $option_key ]['entries']['uploads/2026/01/dropper.php']
		);

		// The scan must not have written a snapshot into the shared settings
		// option. (The option itself may exist: reading settings on a store
		// with no version runs the 1.1.1 fresh-install migration, which is
		// unrelated to the snapshot and writes only defaults.)
		$shared = isset( $spfw_test_options['spfw_settings']['hardening'] )
			? $spfw_test_options['spfw_settings']['hardening']
			: array();
		$this->assertArrayNotHasKey( 'file_monitor_snapshot', $shared );
		$this->assertArrayNotHasKey( 'file_monitor_last_scan', $shared );

		// Second scan over an unchanged tree: no diff at all.
		$again = $module->scan_wp_content();
		$this->assertSame( array(), array_values( $again['added'] ) );
		$this->assertSame( array(), array_values( $again['modified'] ) );
		$this->assertSame( array(), array_values( $again['removed'] ) );
	}

	/**
	 * A baseline rebuild after a legitimate plugin update splices fresh
	 * hashes into the snapshot stored in the dedicated option, drops entries
	 * for files the update removed, and keeps the recorded scan time (a
	 * rebuild is not a scan).
	 */
	public function test_rebuild_baseline_updates_dedicated_option_and_keeps_scan_time() {
		$this->make_scan_file( 'plugins/my-plugin/kept.php', "<?php // v1\n" );
		$this->make_scan_file( 'plugins/my-plugin/gone.php', "<?php // v1\n" );
		$this->make_scan_file( 'plugins/other-plugin/other.php', "<?php // untouched\n" );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		// Simulate the update: one file changed, one removed, one added.
		$this->make_scan_file( 'plugins/my-plugin/kept.php', "<?php // v2\n" );
		unlink( WP_CONTENT_DIR . '/plugins/my-plugin/gone.php' );
		$this->make_scan_file( 'plugins/my-plugin/new.php', "<?php // new\n" );

		$store_before = SPFW_Module_Hardening::get_file_snapshot();

		$ref = new ReflectionMethod( 'SPFW_Module_Hardening', 'accept_into_baseline' );
		$ref->invoke( $module, 'my-plugin', 'manual', 1 );

		$store = SPFW_Module_Hardening::get_file_snapshot();

		$this->assertSame( $store_before['time'], $store['time'], 'a rebuild must not move the scan time' );
		$this->assertArrayNotHasKey( 'plugins/my-plugin/gone.php', $store['entries'] );
		$this->assertArrayHasKey( 'plugins/my-plugin/new.php', $store['entries'] );
		$this->assertSame(
			hash_file( 'sha256', WP_CONTENT_DIR . '/plugins/my-plugin/kept.php' ),
			$store['entries']['plugins/my-plugin/kept.php']
		);

		// Entries outside the rebuilt plugin are untouched.
		$this->assertSame(
			$store_before['entries']['plugins/other-plugin/other.php'],
			$store['entries']['plugins/other-plugin/other.php']
		);
	}

	// ---------------------------------------------------------------------
	// The actionable notice.
	// ---------------------------------------------------------------------

	/**
	 * render_notice() keeps the Hardening-tab link and lists every affected
	 * file with its state, so the admin can verify the report on the server
	 * instead of being told only that "a file" changed.
	 */
	public function test_render_notice_lists_affected_files_and_keeps_tab_link() {
		$module = new SPFW_Module_Hardening();

		ob_start();
		$module->render_notice(
			array(
				'plugins' => 'missing',
				'root'    => 'altered',
			)
		);
		$html = ob_get_clean();

		// The link to the Hardening tab survives.
		$this->assertStringContainsString( 'tab=hardening', $html );
		$this->assertStringContainsString( 'Go to the Hardening tab to restore it.', $html );

		// Each offender's managed path is named with its state. On the
		// bootstrap's Apache stub the owning strategy is .htaccess, so the
		// paths are the .htaccess files themselves.
		$this->assertStringContainsString( WP_CONTENT_DIR . '/plugins/.htaccess', $html );
		$this->assertStringContainsString( SPFW_Htaccess::path( 'root' ), $html );
		$this->assertStringContainsString( 'missing', $html );
		$this->assertStringContainsString( 'modified', $html );

		// Well-formed list markup: one <li> per named file.
		$this->assertSame( 2, substr_count( $html, '<li>' ) );
		$this->assertSame( 1, substr_count( $html, '<ul>' ) );
		$this->assertSame( 1, substr_count( $html, '</ul>' ) );
	}

	/**
	 * With no offenders passed (a legacy or defensive call) the notice still
	 * renders its message and link, just without a file list.
	 */
	public function test_render_notice_without_offenders_omits_the_list() {
		$module = new SPFW_Module_Hardening();

		ob_start();
		$module->render_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'tab=hardening', $html );
		$this->assertStringNotContainsString( '<ul>', $html );
	}

	/**
	 * Assert two lists contain the same values, ignoring order.
	 *
	 * @param array  $expected Expected values.
	 * @param array  $actual   Actual values.
	 * @param string $message  Failure message.
	 */
	private function assertSameSets( array $expected, array $actual, $message = '' ) {
		sort( $expected );
		$actual = array_values( $actual );
		sort( $actual );

		$this->assertSame( $expected, $actual, $message );
	}
}
