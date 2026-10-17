<?php
/**
 * Tests for the verified file baseline (2.20.0).
 *
 * The baseline only moves when something verified the files: the update
 * package WordPress unpacked (premium plugins included), wordpress.org's
 * published checksums, or an admin's Mark as verified. A version header alone
 * proves nothing — 2.19.0 trusted it, so editing a plugin and bumping its
 * version went unreported — and Scan now no longer accepts what it finds.
 * These tests pin the upgrader hook shapes (2.19.0's auto-update fix), each
 * verification source, the three pending groups, once-only notification, and
 * the signature check on Mark as verified.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Package manifests, wordpress.org checksums, classification, and verification.
 */
class File_Verification_Test extends TestCase {

	/**
	 * Where fixture "packages" are unpacked, outside the scanned tree.
	 *
	 * @var string
	 */
	private $pkg_root;

	/**
	 * Fresh option/transient/cron/mail/http state with the monitor on.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_transients, $spfw_test_cron, $spfw_test_mail, $spfw_test_http;
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
		$spfw_test_http       = array();

		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );

		$this->pkg_root = sys_get_temp_dir() . '/spfw-test/packages';
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
	 * Delete the scan roots and package dir.
	 */
	private function cleanup() {
		foreach ( array( WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/uploads', $this->pkg_root ) as $dir ) {
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
	 * Write a plugin's files into a directory.
	 *
	 * @param string $dir     Plugin directory.
	 * @param string $slug    Plugin slug.
	 * @param string $version Version header.
	 */
	private function plugin_files( $dir, $slug, $version ) {
		$this->put( "$dir/$slug.php", "<?php\n/*\nPlugin Name: $slug\nVersion: $version\n*/\n" );
		$this->put( "$dir/includes/core.php", "<?php // $slug $version\n" );
	}

	/**
	 * Install a plugin version straight into wp-content/plugins.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version header.
	 */
	private function make_plugin( $slug, $version ) {
		$this->plugin_files( WP_CONTENT_DIR . "/plugins/$slug", $slug, $version );
	}

	/**
	 * Run a WordPress-style update of a plugin: unpack the package, let the
	 * module fingerprint it, copy it into place (optionally tampering on the
	 * way), then fire upgrader_process_complete with the given shape.
	 *
	 * @param SPFW_Module_Hardening $module  Module.
	 * @param string                $slug    Plugin slug.
	 * @param string                $version New version.
	 * @param array                 $options Hook options.
	 * @param string|null           $tamper  Contents to write over core.php after install.
	 */
	private function upgrade( $module, $slug, $version, array $options, $tamper = null ) {
		$source = $this->pkg_root . '/' . $slug;
		$this->delete_tree( $source );
		$this->plugin_files( $source, $slug, $version );

		$module->capture_package_manifest( $source . '/', '', null, array( 'plugin' => "$slug/$slug.php" ) );

		$this->delete_tree( WP_CONTENT_DIR . "/plugins/$slug" );
		$this->make_plugin( $slug, $version );

		if ( null !== $tamper ) {
			$this->put( WP_CONTENT_DIR . "/plugins/$slug/includes/core.php", $tamper );
		}

		$module->handle_upgrader_complete( null, $options );
	}

	/**
	 * The paths queued for the debounced plugins alert, flattened.
	 *
	 * @return string[]
	 */
	private function queued_paths() {
		$pending = get_transient( SPFW_Module_Hardening::FILE_MONITOR_PENDING );

		return is_array( $pending ) ? array_merge( $pending['added'], $pending['modified'], $pending['removed'] ) : array();
	}

	/**
	 * Single-update hook shape (`plugin`).
	 *
	 * @param string $slug Plugin slug.
	 * @return array
	 */
	private function single( $slug ) {
		return array(
			'plugin' => "$slug/$slug.php",
			'type'   => 'plugin',
			'action' => 'update',
		);
	}

	// ---------------------------------------------------------------------
	// Package verification, every hook shape.
	// ---------------------------------------------------------------------

	/**
	 * A single upgrade() — the auto-update path — whose installed files equal
	 * the package is accepted as package-verified, silently.
	 */
	public function test_single_update_matching_package_is_verified() {
		global $spfw_test_mail;

		$this->make_plugin( 'classic-editor', '1.6.5' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->upgrade( $module, 'classic-editor', '1.7.0', $this->single( 'classic-editor' ) );

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertSame( 'package', $store['verified']['source'] );
		$this->assertSame( '1.7.0', $store['versions']['classic-editor'] );

		$module->run_file_monitor_scan();
		$this->assertSame( array(), SPFW_Module_Hardening::get_file_snapshot()['pending']['updates'] );
		$this->assertSame( array(), $this->queued_paths() );
		$this->assertSame( array(), $spfw_test_mail );
	}

	/**
	 * The bulk shape (`plugins`) is verified the same way.
	 */
	public function test_bulk_update_matching_package_is_verified() {
		$this->make_plugin( 'akismet', '5.0' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->upgrade(
			$module,
			'akismet',
			'5.1',
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
	public function test_install_matching_package_is_verified() {
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

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

		$source = $this->pkg_root . '/fresh';
		$this->plugin_files( $source, 'fresh', '1.0' );
		$module->capture_package_manifest(
			$source,
			'',
			null,
			array(
				'type'   => 'plugin',
				'action' => 'install',
			)
		);
		$this->make_plugin( 'fresh', '1.0' );

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
	 * Installed files that differ from the package are an immediate alert,
	 * and the baseline does not move.
	 */
	public function test_install_differing_from_package_alerts() {
		global $spfw_test_mail;

		$this->make_plugin( 'classic-editor', '1.6.5' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();
		$before = SPFW_Module_Hardening::get_file_snapshot()['entries'];

		$this->upgrade( $module, 'classic-editor', '1.7.0', $this->single( 'classic-editor' ), "<?php eval(\$_POST['x']);\n" );

		$this->assertCount( 1, $spfw_test_mail );
		$this->assertStringContainsString( 'plugins/classic-editor/includes/core.php', $spfw_test_mail[0]['message'] );
		$this->assertSame( $before, SPFW_Module_Hardening::get_file_snapshot()['entries'] );
	}

	/**
	 * Theme updates are not ours to handle, and capture nothing.
	 */
	public function test_theme_package_is_not_captured() {
		$source = $this->pkg_root . '/twentytwenty';
		$this->plugin_files( $source, 'twentytwenty', '1.0' );

		$module = new SPFW_Module_Hardening();
		$module->capture_package_manifest( $source, '', null, array( 'theme' => 'twentytwenty' ) );

		$ref = new ReflectionProperty( 'SPFW_Module_Hardening', 'package_manifests' );
		$this->assertSame( array(), $ref->getValue( $module ) );
	}

	// ---------------------------------------------------------------------
	// wordpress.org checksums.
	// ---------------------------------------------------------------------

	/**
	 * Describe a checksum response for the plugin as it sits on disk.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version.
	 * @param bool   $tamper  Publish a wrong hash for core.php.
	 */
	private function publish_checksums( $slug, $version, $tamper = false ) {
		global $spfw_test_http;

		$dir   = WP_CONTENT_DIR . "/plugins/$slug";
		$files = array(
			"$slug.php"         => array( 'sha256' => hash_file( 'sha256', "$dir/$slug.php" ) ),
			'includes/core.php' => array( 'sha256' => array( $tamper ? str_repeat( '0', 64 ) : hash_file( 'sha256', "$dir/includes/core.php" ) ) ),
			'readme.txt'        => array( 'sha256' => str_repeat( 'f', 64 ) ),
		);

		$spfw_test_http[ "plugin-checksums/$slug/$version.json" ] = array(
			'code' => 200,
			'body' => json_encode( array( 'files' => $files ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		);
	}

	/**
	 * An update copied in by hand (no hook) that matches wordpress.org's
	 * checksums is verified silently.
	 */
	public function test_unhooked_update_matching_wporg_is_verified() {
		global $spfw_test_mail;

		$this->make_plugin( 'classic-editor', '1.6.5' );
		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->make_plugin( 'classic-editor', '1.7.0' );
		$this->publish_checksums( 'classic-editor', '1.7.0' );

		$module->run_file_monitor_scan();

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertSame( 'wporg', $store['verified']['source'] );
		$this->assertSame( array(), $store['pending']['updates'] );
		$this->assertSame( array(), $spfw_test_mail );
	}

	/**
	 * Checksums that do not match leave the update unverified.
	 */
	public function test_wporg_mismatch_stays_unverified() {
		$this->make_plugin( 'classic-editor', '1.6.5' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->make_plugin( 'classic-editor', '1.7.0' );
		$this->publish_checksums( 'classic-editor', '1.7.0', true );

		$result = $module->scan_wp_content();
		$this->assertArrayHasKey( 'classic-editor', $result['updates'] );
		$this->assertSame( array(), $result['auto_verified'] );
	}

	// ---------------------------------------------------------------------
	// Unverified updates and unexplained changes.
	// ---------------------------------------------------------------------

	/**
	 * A version change nothing can check — a premium plugin over SFTP — is an
	 * unverified update: one low-key email, pending until verified, and not
	 * re-sent by later scans.
	 */
	public function test_unverifiable_update_emails_once_and_stays_pending() {
		global $spfw_test_mail;

		$this->make_plugin( 'bb-plugin', '2.11' );
		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->make_plugin( 'bb-plugin', '2.12' );
		$module->run_file_monitor_scan();

		$this->assertCount( 1, $spfw_test_mail );
		$this->assertStringContainsString( 'could not be verified', $spfw_test_mail[0]['subject'] );
		$this->assertStringContainsString( 'bb-plugin: 2.11 -> 2.12', $spfw_test_mail[0]['message'] );
		$this->assertSame( array(), $this->queued_paths(), 'an update is not a tamper alert' );

		$module->run_file_monitor_scan();
		$this->assertCount( 1, $spfw_test_mail, 'not re-sent while it waits for review' );

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertSame( '2.11', $store['versions']['bb-plugin'], 'the baseline did not move' );
		$this->assertSame( '2.12', $store['pending']['updates']['bb-plugin']['to'] );
	}

	/**
	 * Editing a file and bumping the version header — silent in 2.19.0 — is
	 * now reported.
	 */
	public function test_version_bump_with_edit_is_not_silent() {
		global $spfw_test_mail;

		$this->make_plugin( 'other', '1.0' );
		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->put( WP_CONTENT_DIR . '/plugins/other/other.php', "<?php\n/*\nPlugin Name: other\nVersion: 1.1\n*/\neval(\$_POST['x']);\n" );
		$module->run_file_monitor_scan();

		$this->assertCount( 1, $spfw_test_mail );
	}

	/**
	 * A same-version change is unexplained: queued, emailed at dispatch, and
	 * not re-queued by the next scan once reported.
	 */
	public function test_same_version_change_is_alerted_once() {
		global $spfw_test_mail;

		$this->make_plugin( 'other', '2.0' );
		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->put( WP_CONTENT_DIR . '/plugins/other/includes/core.php', "<?php eval(\$_POST['x']);\n" );
		$module->run_file_monitor_scan();

		$this->assertSame( array( 'plugins/other/includes/core.php' ), $this->queued_paths() );

		$module->dispatch_queued_alerts();
		$this->assertCount( 1, $spfw_test_mail );

		$module->run_file_monitor_scan();
		$this->assertSame( array(), $this->queued_paths() );
	}

	/**
	 * Scan now never moves the baseline: a tampered file is still reported on
	 * the second scan.
	 */
	public function test_scan_never_accepts_unverified_changes() {
		$this->make_plugin( 'other', '2.0' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->put( WP_CONTENT_DIR . '/plugins/other/includes/core.php', "<?php // changed\n" );

		$this->assertSame( array( 'plugins/other/includes/core.php' ), $module->scan_wp_content()['modified'] );
		$this->assertSame( array( 'plugins/other/includes/core.php' ), $module->scan_wp_content()['modified'] );
	}

	/**
	 * The first scan records the site as found and reports nothing.
	 */
	public function test_first_scan_is_silent() {
		global $spfw_test_mail;

		$this->make_plugin( 'other', '2.0' );
		$this->put( WP_CONTENT_DIR . '/uploads/2026/01/x.php', "<?php system('id');\n" );

		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->assertSame( array(), $spfw_test_mail );
		$this->assertSame( 'initial', SPFW_Module_Hardening::get_file_snapshot()['verified']['source'] );
	}

	/**
	 * On the first scan of a pre-2.19 baseline, this plugin's own changed
	 * files are an update (its upgrade's hook ran the old code), not
	 * tampering.
	 */
	public function test_legacy_baseline_treats_our_own_change_as_update() {
		$slug = dirname( SPFW_BASENAME );
		$this->make_plugin( $slug, '2.18.0' );

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

		$this->make_plugin( $slug, '2.20.0' );

		$result = $module->scan_wp_content();
		$this->assertArrayHasKey( $slug, $result['updates'] );
		$this->assertSame( array(), $result['unexplained']['modified'] );
	}

	// ---------------------------------------------------------------------
	// Mark as verified.
	// ---------------------------------------------------------------------

	/**
	 * A stale signature is refused with 409; the right per-plugin signature
	 * accepts that plugin only and records who verified.
	 */
	public function test_verify_files_checks_signature_and_scope() {
		$this->make_plugin( 'bb-plugin', '2.11' );
		$this->make_plugin( 'other', '2.0' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->make_plugin( 'bb-plugin', '2.12' );
		$this->put( WP_CONTENT_DIR . '/plugins/other/includes/core.php', "<?php // changed\n" );

		$scan = $module->scan_wp_content();
		$sig  = $scan['signatures']['plugins']['bb-plugin'];

		$stale = $module->verify_files( sha1( 'nope' ), 'bb-plugin', 7 );
		$this->assertInstanceOf( 'WP_Error', $stale );

		// A further edit after the review invalidates the reviewed signature.
		$this->put( WP_CONTENT_DIR . '/plugins/bb-plugin/includes/core.php', "<?php // edited after review\n" );
		$this->assertInstanceOf( 'WP_Error', $module->verify_files( $sig, 'bb-plugin', 7 ) );

		$sig    = $module->scan_wp_content()['signatures']['plugins']['bb-plugin'];
		$result = $module->verify_files( $sig, 'bb-plugin', 7 );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'bb-plugin', $result['updates'] );
		$this->assertSame( array( 'plugins/other/includes/core.php' ), $result['unexplained']['modified'], 'other scopes stay pending' );

		$store = SPFW_Module_Hardening::get_file_snapshot();
		$this->assertSame( 7, $store['verified']['user_id'] );
		$this->assertSame( 'manual', $store['verified']['source'] );
		$this->assertSame( '2.12', $store['versions']['bb-plugin'] );
	}

	/**
	 * Verifying everything clears the whole pending set.
	 */
	public function test_verify_all_clears_everything() {
		$this->make_plugin( 'other', '2.0' );
		$module = new SPFW_Module_Hardening();
		$module->scan_wp_content();

		$this->put( WP_CONTENT_DIR . '/plugins/other/includes/core.php', "<?php // changed\n" );
		$this->put( WP_CONTENT_DIR . '/uploads/2026/10/x.php', "<?php // dropped\n" );

		$sig    = $module->scan_wp_content()['signatures']['all'];
		$result = $module->verify_files( $sig, 'all', 1 );

		$this->assertSame( array(), $result['added'] );
		$this->assertSame( array(), $result['modified'] );
	}

	// ---------------------------------------------------------------------
	// Deletion and uploads.
	// ---------------------------------------------------------------------

	/**
	 * Deleting a plugin from wp-admin drops its entries.
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
	 * An inert uploads stub is accepted; real code in uploads alerts at once.
	 */
	public function test_uploads_stub_is_ignored_but_code_alerts() {
		global $spfw_test_mail;

		$module = new SPFW_Module_Hardening();
		$module->run_file_monitor_scan();

		$this->put( WP_CONTENT_DIR . '/uploads/mainwp/index.php', "<?php\n// Silence is golden.\n" );
		$module->run_file_monitor_scan();
		$this->assertSame( array(), $spfw_test_mail );

		$this->put( WP_CONTENT_DIR . '/uploads/2026/10/shell.php', "<?php system(\$_GET['c']);\n" );
		$module->run_file_monitor_scan();

		$this->assertCount( 1, $spfw_test_mail );
		$this->assertStringContainsString( 'uploads/2026/10/shell.php', $spfw_test_mail[0]['message'] );
		$this->assertStringNotContainsString( 'uploads/mainwp/index.php', $spfw_test_mail[0]['message'] );
		$this->assertStringNotContainsString( 'NOT ON WHITELIST', $spfw_test_mail[0]['message'] );
	}

	/**
	 * Mark as verified is a POST under the plugin namespace, gated on
	 * manage_options like every other settings route, and requires the
	 * reviewed signature.
	 */
	public function test_verify_files_route_registered_as_post_with_permission_callback() {
		$controller = new SPFW_Rest_Settings();
		$controller->register_routes();

		global $spfw_test_rest_routes;

		$key = 'spfw/v1/settings/verify-files';

		$this->assertArrayHasKey( $key, $spfw_test_rest_routes, $key . ' was not registered' );

		$args = $spfw_test_rest_routes[ $key ];

		$this->assertSame( WP_REST_Server::CREATABLE, $args['methods'] );
		$this->assertSame( array( $controller, 'verify_files' ), $args['callback'] );
		$this->assertSame( array( $controller, 'check_permissions' ), $args['permission_callback'] );
		$this->assertTrue( $args['args']['signature']['required'] );
	}
}
