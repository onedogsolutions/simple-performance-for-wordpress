<?php
/**
 * Tests for the hardening missing/altered notice's two 2.18.0 behaviors:
 * the manage_options capability gate, and the dismiss/snooze that stops a
 * sticky foreign modification of a managed file from nagging on every admin
 * page load.
 *
 * The scenario these pin down is the one in the field: another tool
 * (a security plugin, the host) legitimately rewrites
 * wp-content/uploads/.htaccess on its own schedule. status() correctly and
 * deliberately keeps reporting that file as 'altered' — self-heal only
 * covers a stale hash over identical content — so without a snooze the
 * notice re-rendered for every wp-admin user on every screen, forever.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Capability gating, snooze persistence, dismiss handler, and notice markup.
 */
class Hardening_Notice_Test extends TestCase {

	/**
	 * Reset the option store, hook registry, capability/filter/referer
	 * globals, and static settings cache, and start from a clean filesystem
	 * slate under the bootstrap's temp WP_CONTENT_DIR.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_hooks, $spfw_test_capabilities,
			$spfw_test_nonces, $spfw_test_redirects, $spfw_test_referer,
			$spfw_test_filter_overrides;

		$spfw_test_options          = array();
		$spfw_test_hooks            = array();
		$spfw_test_capabilities     = array( 'manage_options' => true );
		$spfw_test_nonces           = array();
		$spfw_test_redirects        = array();
		$spfw_test_referer          = false;
		$spfw_test_filter_overrides = array();

		$this->reset_settings_cache();
		$this->cleanup_files();
	}

	/**
	 * Remove fixture files and superglobal state so nothing leaks between
	 * tests.
	 */
	protected function tearDown(): void {
		$this->cleanup_files();
		unset( $_POST['_wpnonce'] );
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
	 * Delete the fixture .htaccess files the tests write.
	 */
	private function cleanup_files() {
		foreach ( array( SPFW_Htaccess::path( 'uploads' ), SPFW_Htaccess::path( 'plugins' ) ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup.
			}
		}
	}

	/**
	 * Seed the option store with a hardening group and reset the cache.
	 *
	 * The version is pinned above every migration gate on purpose, so no
	 * upgrade path runs a write (the bootstrap does not stub
	 * insert_with_markers()).
	 *
	 * @param array $hardening Hardening group to store.
	 */
	private function set_hardening( array $hardening ) {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version'   => '99.0.0',
			'hardening' => $hardening,
		);

		$this->reset_settings_cache();
	}

	/**
	 * Put the uploads .htaccess into the field scenario: the toggle is on,
	 * a hash for the original authored content is stored, but another tool
	 * has rewritten the file with its own rules.
	 */
	private function seed_foreign_uploads_htaccess() {
		$path = SPFW_Htaccess::path( 'uploads' );

		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "# Rules written by another security plugin\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->set_hardening(
			array(
				'uploads_htaccess'      => true,
				'uploads_htaccess_hash' => sha1( 'the originally authored payload' ),
			)
		);
	}

	/**
	 * Arm one valid nonce for the dismiss action and seed it in $_POST.
	 */
	private function arm_valid_dismiss_nonce() {
		global $spfw_test_nonces;

		$spfw_test_nonces['spfw_dismiss_hardening_notice'] = 'abc123';
		$_POST['_wpnonce']                                 = 'abc123';
	}

	/**
	 * Count how many callbacks were registered on admin_notices this test.
	 */
	private function admin_notice_registrations() {
		global $spfw_test_hooks;

		$count = 0;

		foreach ( $spfw_test_hooks as $hook ) {
			if ( 'admin_notices' === $hook['tag'] ) {
				++$count;
			}
		}

		return $count;
	}

	// ---------------------------------------------------------------------
	// Capability gate.
	// ---------------------------------------------------------------------

	/**
	 * Without manage_options nothing renders — not even the .user.ini
	 * revert notice that shares the method. Editors and shop managers were
	 * seeing a warning that only an admin can act on, on every admin screen.
	 */
	public function test_notice_is_suppressed_for_users_without_manage_options() {
		global $spfw_test_capabilities, $spfw_test_options;

		$spfw_test_capabilities['manage_options']    = false;
		$spfw_test_options['spfw_user_ini_reverted'] = true;

		$this->seed_foreign_uploads_htaccess();

		$module = new SPFW_Module_Hardening();
		$module->maybe_show_notice();

		$this->assertSame( 0, $this->admin_notice_registrations() );
		$this->assertArrayHasKey( 'spfw_user_ini_reverted', $spfw_test_options, 'the flag must survive for an admin request' );
	}

	/**
	 * An administrator with a genuinely altered file still gets the notice:
	 * the gate hides it from non-admins, it does not suppress real reports.
	 */
	public function test_notice_registers_for_administrators_when_a_file_is_altered() {
		$this->seed_foreign_uploads_htaccess();

		$module = new SPFW_Module_Hardening();
		$module->maybe_show_notice();

		$this->assertSame( 1, $this->admin_notice_registrations() );
	}

	/**
	 * Nothing wrong on disk means no notice, even for an admin.
	 */
	public function test_notice_registers_nothing_when_all_targets_are_ok() {
		$module = new SPFW_Module_Hardening();
		$module->maybe_show_notice();

		$this->assertSame( 0, $this->admin_notice_registrations() );
	}

	// ---------------------------------------------------------------------
	// Dismiss / snooze.
	// ---------------------------------------------------------------------

	/**
	 * Dismissing stores the current offender set; the same set then stays
	 * quiet on subsequent requests instead of re-rendering every page load.
	 */
	public function test_dismissed_offender_set_stays_quiet_until_it_changes() {
		$this->seed_foreign_uploads_htaccess();

		$module = new SPFW_Module_Hardening();
		$module->store_dismiss_snooze();

		// Same request cycle: the snooze is live.
		$module->maybe_show_notice();

		$this->assertSame( 0, $this->admin_notice_registrations() );
	}

	/**
	 * A snooze whose window has lapsed no longer suppresses the notice.
	 */
	public function test_lapsed_snooze_shows_the_notice_again() {
		global $spfw_test_options;

		$this->seed_foreign_uploads_htaccess();

		$module = new SPFW_Module_Hardening();
		$module->store_dismiss_snooze();

		$snoozed          = $spfw_test_options[ SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION ];
		$snoozed['until'] = time() - 10;
		$spfw_test_options[ SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION ] = $snoozed;

		$module->maybe_show_notice();

		$this->assertSame( 1, $this->admin_notice_registrations() );
	}

	/**
	 * The snooze is per-signature: a second target going missing is a new
	 * situation and must alert immediately, not inherit the dismissal.
	 */
	public function test_changed_offender_set_rearms_the_notice() {
		$this->seed_foreign_uploads_htaccess();

		$module = new SPFW_Module_Hardening();
		$module->store_dismiss_snooze();

		// Now the plugins file goes missing on top of the foreign uploads
		// edit: toggle on, stored hash, no file on disk.
		$this->set_hardening(
			array(
				'uploads_htaccess'      => true,
				'uploads_htaccess_hash' => sha1( 'the originally authored payload' ),
				'plugins_htaccess'      => true,
				'htaccess_hash'         => sha1( 'the plugins payload' ),
			)
		);

		$module->maybe_show_notice();

		$this->assertSame( 1, $this->admin_notice_registrations() );
	}

	/**
	 * The snooze window is filterable so a site owner can tune how often a
	 * recurring foreign edit is allowed to remind them.
	 */
	public function test_snooze_window_honors_the_filter() {
		global $spfw_test_options, $spfw_test_filter_overrides;

		$this->seed_foreign_uploads_htaccess();

		$spfw_test_filter_overrides['spfw_hardening_notice_snooze'] = 42;

		$before = time();

		$module = new SPFW_Module_Hardening();
		$module->store_dismiss_snooze();

		$after = time();

		$snoozed = $spfw_test_options[ SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION ];
		$this->assertGreaterThanOrEqual( $before + 42, $snoozed['until'] );
		$this->assertLessThanOrEqual( $after + 42, $snoozed['until'] );
	}

	/**
	 * With nothing to report, dismissing persists nothing — an empty
	 * offender set must not snooze a future, real alert.
	 */
	public function test_dismiss_with_no_offenders_stores_nothing() {
		global $spfw_test_options;

		$module = new SPFW_Module_Hardening();
		$module->store_dismiss_snooze();

		$this->assertArrayNotHasKey( SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION, $spfw_test_options );
	}

	// ---------------------------------------------------------------------
	// The dismiss handler.
	// ---------------------------------------------------------------------

	/**
	 * A valid dismiss POST persists the snooze for the current offender set
	 * and redirects back to the page the admin was on.
	 */
	public function test_dismiss_stores_snooze_and_redirects_back() {
		global $spfw_test_options, $spfw_test_redirects, $spfw_test_referer;

		$this->seed_foreign_uploads_htaccess();
		$this->arm_valid_dismiss_nonce();

		$spfw_test_referer = 'http://example.com/wp-admin/update-core.php';

		$module = new SPFW_Module_Hardening();
		$module->dismiss_notice();

		$snoozed = isset( $spfw_test_options[ SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION ] )
			? $spfw_test_options[ SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION ]
			: null;

		$this->assertIsArray( $snoozed );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{40}$/', $snoozed['signature'] );
		$this->assertGreaterThan( time(), $snoozed['until'] );

		// And the behavioral contract: the notice is suppressed from here on.
		$module->maybe_show_notice();
		$this->assertSame( 0, $this->admin_notice_registrations() );

		$this->assertSame( array( 'http://example.com/wp-admin/update-core.php' ), $spfw_test_redirects );
	}

	/**
	 * No referer (direct POST): fall back to the dashboard instead of
	 * redirecting to an empty location.
	 */
	public function test_dismiss_redirects_to_dashboard_without_referer() {
		global $spfw_test_redirects;

		$this->seed_foreign_uploads_htaccess();
		$this->arm_valid_dismiss_nonce();

		$module = new SPFW_Module_Hardening();
		$module->dismiss_notice();

		$this->assertSame( array( 'http://example.com/wp-admin/index.php' ), $spfw_test_redirects );
	}

	/**
	 * A non-admin POST is refused and persists nothing.
	 */
	public function test_dismiss_bails_without_capability() {
		global $spfw_test_capabilities, $spfw_test_options;

		$spfw_test_capabilities['manage_options'] = false;

		$this->seed_foreign_uploads_htaccess();
		$this->arm_valid_dismiss_nonce();

		$module = new SPFW_Module_Hardening();

		try {
			$module->dismiss_notice();
			$this->fail( 'wp_die was not called' );
		} catch ( SPFW_Test_WP_Die_Exception $e ) {
			$this->assertSame( 'Sorry, you are not allowed to manage this notice.', $e->getMessage() );
		}

		$this->assertArrayNotHasKey( SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION, $spfw_test_options );
	}

	/**
	 * A stale or forged nonce is refused and persists nothing.
	 */
	public function test_dismiss_bails_on_bad_nonce() {
		global $spfw_test_options;

		$this->seed_foreign_uploads_htaccess();
		$_POST['_wpnonce'] = 'forged';

		$module = new SPFW_Module_Hardening();

		try {
			$module->dismiss_notice();
			$this->fail( 'wp_die was not called' );
		} catch ( SPFW_Test_WP_Die_Exception $e ) {
			$this->assertSame( 'The link you followed has expired.', $e->getMessage() );
		}

		$this->assertArrayNotHasKey( SPFW_Module_Hardening::NOTICE_SNOOZE_OPTION, $spfw_test_options );
	}

	// ---------------------------------------------------------------------
	// Notice markup.
	// ---------------------------------------------------------------------

	/**
	 * The rendered notice carries the foreign-writer hint and a POST form
	 * that dismisses (snoozes) the alert — no JavaScript required, since the
	 * notice shows on admin screens where this plugin's scripts never load.
	 */
	public function test_render_notice_includes_hint_and_dismiss_form() {
		$module = new SPFW_Module_Hardening();

		ob_start();
		$module->render_notice( array( 'uploads' => 'altered' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Another security plugin or your hosting provider may also manage these files', $html );
		$this->assertStringContainsString( '<form method="post" action="http://example.com/wp-admin/admin-post.php">', $html );
		$this->assertStringContainsString( 'name="action" value="spfw_dismiss_hardening_notice"', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		$this->assertStringContainsString( 'Dismiss for a few days', $html );

		// The pre-existing contract survives: link, file list, states.
		$this->assertStringContainsString( 'tab=hardening', $html );
		$this->assertStringContainsString( SPFW_Htaccess::path( 'uploads' ), $html );
		$this->assertStringContainsString( 'modified', $html );
	}
}
