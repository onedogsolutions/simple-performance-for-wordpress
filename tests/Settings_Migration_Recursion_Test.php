<?php
/**
 * Regression test: SPFW_Settings::get() must not infinitely recurse when
 * the stored option has a pre-1.14.0 version (triggering the payload
 * migration whose nested group() call re-enters get()).
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

class Settings_Migration_Recursion_Test extends TestCase {

	/**
	 * Reset the static cache and option store between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options;
		$spfw_test_options = array();

		// Reset the private static $cache via reflection.
		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );
	}

	/**
	 * A stored option shaped like a 1.10.0 install (pre-1.14.0) must not
	 * cause infinite recursion. Before the fix, run_payload_migration()
	 * called SPFW_Settings::group('hardening') → get() → migration → …
	 *
	 * The test passes if get() returns at all (PHP would fatal on stack
	 * exhaustion if the recursion were still present).
	 */
	public function test_get_returns_with_pre_114_stored_option() {
		global $spfw_test_options;

		// Simulate a 1.10.0-era stored option with hardening enabled.
		$spfw_test_options['spfw_settings'] = array(
			'version'   => '1.10.0',
			'core'      => array(
				'disable_emojis' => true,
			),
			'hardening' => array(
				'plugins_htaccess' => true,
				'htaccess_hash'    => sha1( 'legacy-payload' ),
			),
		);

		// This call would hang/crash before the fix.
		$result = SPFW_Settings::get();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'core', $result );
		$this->assertArrayHasKey( 'hardening', $result );
		// Defaults should be merged in.
		$this->assertArrayHasKey( 'restapi', $result );
		$this->assertArrayHasKey( 'fonts', $result );
		$this->assertArrayHasKey( 'woocommerce', $result );
	}

	/**
	 * A stored option at version 1.0.0 triggers ALL migrations (1.1.1,
	 * 1.6.0, 1.7.1, 1.14.0). Must still return without recursion.
	 */
	public function test_get_returns_with_100_stored_option_all_migrations() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version' => '1.0.0',
			'core'    => array(
				'disable_emojis' => false,
			),
		);

		$result = SPFW_Settings::get();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hardening', $result );
		// The 1.1.1 migration should have added restapi namespaces.
		$this->assertNotEmpty( $result['restapi']['disabled_namespaces'] );
	}

	/**
	 * A fresh install (no stored option) should return defaults without
	 * triggering any migration.
	 */
	public function test_get_returns_defaults_on_fresh_install() {
		$result = SPFW_Settings::get();

		$this->assertIsArray( $result );
		$this->assertSame( SPFW_VERSION, $result['version'] );
		$this->assertTrue( $result['core']['disable_emojis'] );
	}

	/**
	 * group() must work when called during a migration (the exact path
	 * run_payload_migration() takes).
	 */
	public function test_group_accessible_during_migration_path() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version'   => '1.12.0',
			'hardening' => array(
				'plugins_htaccess' => true,
				'htaccess_hash'    => 'abc123',
			),
		);

		$hardening = SPFW_Settings::group( 'hardening' );

		$this->assertIsArray( $hardening );
		$this->assertTrue( $hardening['plugins_htaccess'] );
	}

	/**
	 * The 2.0.2 migration must move a stored core.disable_xmlrpc value into
	 * the hardening group so existing choices are preserved.
	 */
	public function test_xmlrpc_migration_moves_core_value_to_hardening() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version' => '2.0.1',
			'core'    => array(
				'disable_xmlrpc' => true,
				'disable_emojis' => true,
			),
		);

		$settings = SPFW_Settings::get();

		$this->assertFalse( isset( $settings['core']['disable_xmlrpc'] ) );
		$this->assertTrue( $settings['hardening']['disable_xmlrpc'] );
		// Stored option should have been rewritten to the current version so the
		// migration does not re-run. Asserted against SPFW_VERSION rather than a
		// literal so a release bump doesn't fail an unrelated test.
		$this->assertSame( SPFW_VERSION, $spfw_test_options['spfw_settings']['version'] );
		$this->assertFalse( isset( $spfw_test_options['spfw_settings']['core']['disable_xmlrpc'] ) );
		$this->assertTrue( $spfw_test_options['spfw_settings']['hardening']['disable_xmlrpc'] );
	}

	/**
	 * Migration to 2.11.0: an install already using the policy builder has its
	 * own stored csp_directives, which the widened DEFAULT_CSP cannot reach. If
	 * the migration did not append `blob:` there, upgrading would leave that
	 * site's LiteSpeed delayed scripts blocked with nothing in the UI to
	 * explain why.
	 */
	public function test_pre_2110_stored_csp_directives_gain_blob_script_src() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version'   => '2.10.0',
			'hardening' => array(
				'csp_enabled'    => true,
				'csp_directives' => array(
					'script-src' => array( "'self'", "'unsafe-inline'", 'https:' ),
				),
			),
		);

		$result = SPFW_Settings::get();

		$this->assertContains(
			'blob:',
			$result['hardening']['csp_directives']['script-src']
		);

		// The admin's own sources survive untouched.
		$this->assertContains( "'self'", $result['hardening']['csp_directives']['script-src'] );
		$this->assertContains( 'https:', $result['hardening']['csp_directives']['script-src'] );
	}

	/**
	 * A script-src deliberately locked down to 'none' is a choice, not an
	 * oversight: the migration leaves it alone rather than reopening it.
	 */
	public function test_migration_leaves_a_none_script_src_alone() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version'   => '2.10.0',
			'hardening' => array(
				'csp_enabled'    => true,
				'csp_directives' => array(
					'script-src' => array( "'none'" ),
				),
			),
		);

		$result = SPFW_Settings::get();

		$this->assertSame(
			array( "'none'" ),
			$result['hardening']['csp_directives']['script-src']
		);
	}

	/**
	 * The migration is idempotent: an install already carrying blob: does not
	 * accumulate a duplicate.
	 */
	public function test_migration_does_not_duplicate_an_existing_blob_source() {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version'   => '2.10.0',
			'hardening' => array(
				'csp_enabled'    => true,
				'csp_directives' => array(
					'script-src' => array( "'self'", 'blob:' ),
				),
			),
		);

		$result = SPFW_Settings::get();
		$script = $result['hardening']['csp_directives']['script-src'];

		$this->assertSame( 1, count( array_keys( $script, 'blob:', true ) ) );
	}
}
