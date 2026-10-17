<?php
/**
 * Tests for telling a plugin update apart from tampering (2.19.0).
 *
 * Diagnosed live on test.onedog.solutions: a single Plugin_Upgrader::upgrade()
 * — the path WordPress auto-updates take — fires upgrader_process_complete
 * with `plugin` (singular), while handle_upgrader_complete() read only the
 * bulk `plugins` key. The baseline was never rebuilt, so the next twice-daily
 * scan (up to 12 hours later) emailed the update as an integrity alert. These
 * tests pin every hook shape, plugin deletion, and the scan-time version
 * attribution that catches updates no hook reports at all.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Upgrader hook shapes, version attribution, and alert routing.
 */
class Upgrader_Baseline_Test extends TestCase {

	/**
	 * Fresh option/transient/cron/mail state with the monitor switched on.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_transients, $spfw_test_cron, $spfw_test_mail;
		$spfw_test_options    = array(
			'spfw_settings' => array(
				'version'   => '99.0.0',
				'hardening' => array(
					'file_monitor_enabled' => true,
					'file_monitor_email'   => 'admin@example.com',
				),
			),
			'blogname'      => 'Example',
		);
		$spfw_test_transients = array();
		$spfw_test_cron       = array();
		$spfw_test_mail       = array();

		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );

		$this->cleanup();
	}

	/**
	 * Remove fixture trees.
	 */
	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	/**
	 * Delete the scan roots under the bootstrap's temp WP_CONTENT_DIR.
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
	 * Write a fixture file under WP_CONTENT_DIR.
	 *
	 * @param string $relative Path relative to WP_CONTENT_DIR.
	 * @param string $contents Contents.
	 */
	private function make_file( $relative, $contents ) {
		$path = WP_CONTENT_DIR . '/' . $relative;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Write a plugin main file carrying a Version header, plus one extra file.
	 *
	 * @param string $slug    Plugin directory.
	 * @param string $version Version header.
	 */
	private function make_plugin( $slug, $version ) {
		$this->make_file( "plugins/$slug/$slug.php", "<?php\n/*\nPlugin Name: $slug\nVersion: $version\n*/\n" );
		$this->make_file( "plugins/$slug/includes/core.php", "<?php // $slug $version\n" );
	}

	/**
	 * Expose the protected router.
	 *
	 * @param SPFW_Module_Hardening $module  Module.
	 * @param array                 $changes Scan diff.
	 */
	private function route( $module, array $changes ) {
		$ref = new ReflectionMethod( 'SPFW_Module_Hardening', 'route_file_changes' );
		$ref->invoke( $module, $changes );
	}

	/**
	 * The paths queued for the debounced plugins alert, flattened.
	 *
	 * @return string[]
	 */
	private function queued_paths() {
		$pending = get_transient( SPFW_Module_Hardening::FILE_MONITOR_PENDING );

		if ( ! is_array( $pending ) ) {
			return array();
		}

		return array_merge( $pending['added'], $pending['modified'], $pending['removed'] );
	}

	// ---------------------------------------------------------------------
	// upgrader_process_complete shapes.
	// ---------------------------------------------------------------------

	/**
	 * A single upgrade() — the auto-update path — passes `plugin` (string).
	 * The baseline must be rebuilt so the next scan reports nothing.
	 */
	public function test_single_update_shape_rebuilds_baseline() {
		$this->make_plugin( 'classic-editor', '1.6.5' );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->make_plugin( 'classic-editor', '1.7.0' );

		$module->handle_upgrader_complete(
			null,
			array(
				'plugin' => 'classic-editor/classic-editor.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$diff = $module->scan_wp_content();

		$this->assertSame( array(), $diff['modified'] );
		$this->assertSame( array(), $diff['updated_plugins'], 'the rebuild already recorded the new version' );
	}

	/**
	 * The bulk shape (`plugins` array) keeps working.
	 */
	public function test_bulk_update_shape_rebuilds_baseline() {
		$this->make_plugin( 'akismet', '5.0' );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->make_plugin( 'akismet', '5.1' );

		$module->handle_upgrader_complete(
			null,
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( 'akismet/akismet.php' ),
			)
		);

		$this->assertSame( array(), $module->scan_wp_content()['modified'] );
	}

	/**
	 * An install names its plugin only through plugin_info().
	 */
	public function test_install_shape_reads_plugin_info() {
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->make_plugin( 'fresh', '1.0' );

		$upgrader = new class() {
			/**
			 * The installed plugin's basename.
			 *
			 * @return string
			 */
			public function plugin_info() {
				return 'fresh/fresh.php';
			}
		};

		$module->handle_upgrader_complete(
			$upgrader,
			array(
				'type'   => 'plugin',
				'action' => 'install',
			)
		);

		$this->assertSame( array(), $module->scan_wp_content()['added'] );
	}

	/**
	 * A completed update clears that plugin's queued alert and cancels the
	 * dispatch when nothing else is queued.
	 */
	public function test_single_update_clears_queued_alert() {
		$module = new SPFW_Module_Hardening();

		$this->route(
			$module,
			array(
				'added'    => array(),
				'modified' => array( 'plugins/classic-editor/classic-editor.php' ),
				'removed'  => array(),
			)
		);

		$this->assertNotFalse( wp_next_scheduled( SPFW_Module_Hardening::FILE_MONITOR_DISPATCH_CRON ) );

		$module->handle_upgrader_complete(
			null,
			array(
				'plugin' => 'classic-editor/classic-editor.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$this->assertSame( array(), $this->queued_paths() );
		$this->assertFalse( wp_next_scheduled( SPFW_Module_Hardening::FILE_MONITOR_DISPATCH_CRON ) );
	}

	/**
	 * Theme updates and translation updates are not ours to handle.
	 */
	public function test_non_plugin_hooks_are_ignored() {
		$this->make_plugin( 'akismet', '5.0' );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();
		$before = SPFW_Module_Hardening::get_file_snapshot();

		$module->handle_upgrader_complete(
			null,
			array(
				'action' => 'update',
				'type'   => 'theme',
				'themes' => array( 'akismet' ),
			)
		);

		$this->assertSame( $before, SPFW_Module_Hardening::get_file_snapshot() );
	}

	// ---------------------------------------------------------------------
	// Deletion.
	// ---------------------------------------------------------------------

	/**
	 * Deleting a plugin from wp-admin drops its entries, so the next scan has
	 * nothing to report as removed.
	 */
	public function test_deleted_plugin_drops_entries() {
		$this->make_plugin( 'gone', '1.0' );
		$this->make_plugin( 'kept', '1.0' );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->delete_tree( WP_CONTENT_DIR . '/plugins/gone' );
		$module->handle_plugin_deleted( 'gone/gone.php', true );

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertArrayNotHasKey( 'plugins/gone/gone.php', $store['entries'] );
		$this->assertArrayHasKey( 'plugins/kept/kept.php', $store['entries'] );
		$this->assertArrayNotHasKey( 'gone', $store['versions'] );
		$this->assertSame( array(), $module->scan_wp_content()['removed'] );
	}

	/**
	 * A root-level single-file plugin is keyed by its file name and rebuilt
	 * as a single entry (it used to be skipped outright).
	 */
	public function test_single_file_plugin_rebuild() {
		$this->make_file( 'plugins/hello.php', "<?php\n/*\nPlugin Name: Hello\nVersion: 1.0\n*/\n" );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->make_file( 'plugins/hello.php', "<?php\n/*\nPlugin Name: Hello\nVersion: 1.1\n*/\n" );

		$module->handle_upgrader_complete(
			null,
			array(
				'plugin' => 'hello.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertSame( '1.1', $store['versions']['hello.php'] );
		$this->assertSame( array(), $module->scan_wp_content()['modified'] );
	}

	// ---------------------------------------------------------------------
	// Scan-time version attribution (no hook fired at all).
	// ---------------------------------------------------------------------

	/**
	 * An update applied outside WordPress' upgrader (SFTP, git deploy, a
	 * management tool) is recognized by its version change: the scan still
	 * reports the files, but nothing is queued or emailed.
	 */
	public function test_version_change_without_hook_is_not_alerted() {
		global $spfw_test_mail;

		$this->make_plugin( 'classic-editor', '1.6.5' );

		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->make_plugin( 'classic-editor', '1.7.0' );
		$this->make_file( 'plugins/classic-editor/js/new.php', "<?php // added in 1.7\n" );

		$diff = $module->scan_wp_content();
		$this->assertContains( 'plugins/classic-editor/includes/core.php', $diff['modified'] );
		$this->assertSame(
			array(
				'classic-editor' => array(
					'from' => '1.6.5',
					'to'   => '1.7.0',
				),
			),
			$diff['updated_plugins']
		);

		$this->route( $module, $diff );

		$this->assertSame( array(), $this->queued_paths() );
		$this->assertSame( array(), $spfw_test_mail );
	}

	/**
	 * A file changing inside a plugin whose version did not move is exactly
	 * the tamper signal, and still queues for the alert.
	 */
	public function test_same_version_modification_is_queued() {
		$this->make_plugin( 'classic-editor', '1.6.5' );
		$this->make_plugin( 'other', '2.0' );

		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		// classic-editor updates legitimately; other is tampered with.
		$this->make_plugin( 'classic-editor', '1.7.0' );
		$this->make_file( 'plugins/other/includes/core.php', "<?php eval(\$_POST['x']);\n" );

		$module->run_file_monitor_scan();

		$this->assertSame( array( 'plugins/other/includes/core.php' ), $this->queued_paths() );
	}

	/**
	 * A snapshot written before versions were recorded attributes nothing on
	 * its first scan, rather than guessing; the second scan has versions.
	 */
	public function test_legacy_snapshot_without_versions_attributes_nothing() {
		$this->make_plugin( 'classic-editor', '1.6.5' );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$store = SPFW_Module_Hardening::get_file_snapshot();
		update_option(
			SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION,
			array(
				'time'    => $store['time'],
				'entries' => $store['entries'],
			)
		);

		$this->make_plugin( 'classic-editor', '1.7.0' );

		$diff = $module->scan_wp_content();
		$this->assertSame( array(), $diff['updated_plugins'] );
		$this->assertSame( '1.7.0', SPFW_Module_Hardening::get_file_snapshot()['versions']['classic-editor'] );
	}

	/**
	 * The one exception on that first versioned scan: this plugin's own
	 * files. Updating to 2.19.0 is what produced the version-less baseline,
	 * and that update's hook ran the previous release's code.
	 */
	public function test_legacy_snapshot_attributes_our_own_update() {
		$slug = dirname( SPFW_BASENAME );
		$this->make_plugin( $slug, '2.18.0' );
		$this->make_plugin( 'other', '1.0' );

		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$store = SPFW_Module_Hardening::get_file_snapshot();
		update_option(
			SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION,
			array(
				'time'    => $store['time'],
				'entries' => $store['entries'],
			)
		);

		$this->make_plugin( $slug, '2.19.0' );
		$this->make_file( 'plugins/other/includes/core.php', "<?php // changed\n" );

		$module->run_file_monitor_scan();

		$this->assertSame( array( 'plugins/other/includes/core.php' ), $this->queued_paths() );
	}

	// ---------------------------------------------------------------------
	// Uploads strict mode and the email itself.
	// ---------------------------------------------------------------------

	/**
	 * A "Silence is golden" index.php dropped into uploads executes nothing
	 * and is not emailed; real code in uploads is, immediately, without the
	 * misleading whitelist label.
	 */
	public function test_uploads_stub_is_ignored_but_code_alerts() {
		global $spfw_test_mail;

		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->make_file( 'uploads/mainwp/index.php', "<?php\n// Silence is golden.\n" );
		$module->run_file_monitor_scan();

		$this->assertSame( array(), $spfw_test_mail, 'an inert stub must not alert' );

		$this->make_file( 'uploads/2026/10/shell.php', "<?php system(\$_GET['c']);\n" );
		$module->run_file_monitor_scan();

		$this->assertCount( 1, $spfw_test_mail );
		$this->assertStringContainsString( 'uploads/2026/10/shell.php', $spfw_test_mail[0]['message'] );
		$this->assertStringNotContainsString( 'uploads/mainwp/index.php', $spfw_test_mail[0]['message'] );
		$this->assertStringNotContainsString( 'NOT ON WHITELIST', $spfw_test_mail[0]['message'] );
	}
}
