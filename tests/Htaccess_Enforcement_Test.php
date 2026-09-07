<?php
/**
 * Tests for the .htaccess enforcement-honesty work: the pure probe shaper that
 * decides what the admin is told about runtime enforcement, the drift detector
 * that catches an authored file that no longer matches the current toggles, the
 * restore-target mapping fix, and the REST route that exposes the probe.
 *
 * The shaper is the part worth pinning down: a false "enforced" is exactly the
 * false-assurance bug this feature corrects, and a false "not enforced" would
 * send the admin chasing a healthy vhost. The probe itself is not tested here
 * because the HTTP layer (wp_remote_get) is deliberately not stubbed in the
 * lightweight bootstrap — the same reason shape_upgrade_check_result() is the
 * tested seam of the upgrade check.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Enforcement shaping, drift detection, restore mapping, and route tests.
 */
class Htaccess_Enforcement_Test extends TestCase {

	/**
	 * Filesystem path SPFW_Htaccess resolves for the root marker block.
	 *
	 * @var string
	 */
	private $root_path;

	/**
	 * Filesystem path SPFW_Htaccess resolves for the plugins own-file.
	 *
	 * @var string
	 */
	private $plugins_path;

	/**
	 * Reset the option store, route/capability globals, and static cache, and
	 * start from a clean filesystem slate.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_rest_routes, $spfw_test_capabilities;
		$spfw_test_options      = array();
		$spfw_test_rest_routes  = array();
		$spfw_test_capabilities = array( 'manage_options' => true );

		$this->reset_settings_cache();

		// Ask the class where it reads/writes so the test never duplicates (and
		// drifts from) its path resolution.
		$this->root_path    = SPFW_Htaccess::path( 'root' );
		$this->plugins_path = SPFW_Htaccess::path( 'plugins' );

		wp_mkdir_p( dirname( $this->root_path ) );
		wp_mkdir_p( dirname( $this->plugins_path ) );

		$this->cleanup_files();
	}

	/**
	 * Remove any .htaccess files a test wrote so state never leaks between
	 * tests.
	 */
	protected function tearDown(): void {
		$this->cleanup_files();
		parent::tearDown();
	}

	/**
	 * Delete the two files the drift tests write.
	 */
	private function cleanup_files() {
		foreach ( array( $this->root_path, $this->plugins_path ) as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
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
	 * Seed the option store with a hardening group and reset the cache.
	 *
	 * The version is set above every migration gate on purpose: get() runs
	 * reconcile_htaccess_on_upgrade() (2.7.0) and run_payload_migration()
	 * (1.14.0) for older stored versions, and both call SPFW_Htaccess::write()
	 * — which needs insert_with_markers()/WP_Filesystem() that this bootstrap
	 * does not stub. Pinning a high version isolates the pure read-path
	 * (status/needs_resync/payload) that these tests exercise.
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
	 * Write a root .htaccess containing our marker block around $inner, exactly
	 * as write_marker_block() would leave it on disk.
	 *
	 * @param string $inner Block content (without markers).
	 */
	private function write_root_marker_block( $inner ) {
		$contents = '# BEGIN ' . SPFW_Htaccess::MARKER . "\n"
			. $inner . "\n"
			. '# END ' . SPFW_Htaccess::MARKER . "\n";

		file_put_contents( $this->root_path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * A raw probe row as probe_canary() produces it.
	 *
	 * @param string $target Canary key.
	 * @param int    $code   Observed HTTP status code.
	 * @param string $url    Probed URL.
	 * @return array
	 */
	private function probe_row( $target, $code, $url = 'http://example.com/canary' ) {
		return array(
			'target' => $target,
			'code'   => $code,
			'url'    => $url,
		);
	}

	// ---------------------------------------------------------------------
	// shape_enforcement_result(): per-target classification.
	// ---------------------------------------------------------------------

	/**
	 * A deny code (403) on a canary means the server applied the rule: the
	 * target is enforced and the headline is "honored". Label, expected code
	 * and the observed code all carry through for the evidence row.
	 */
	public function test_shape_marks_deny_code_as_enforced() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array( $this->probe_row( 'plugins', 403 ) ),
				'checked' => 1700000000,
			)
		);

		$this->assertSame( 'yes', $shaped['htaccess_honored'] );
		$this->assertCount( 1, $shaped['targets'] );
		$this->assertSame( 1700000000, $shaped['checked'] );

		$row = $shaped['targets'][0];
		$this->assertSame( 'plugins', $row['target'] );
		$this->assertSame( 'enforced', $row['state'] );
		$this->assertSame( 403, $row['observed_code'] );
		$this->assertSame( '403', $row['expected'] );
		$this->assertSame( 'wp-content/plugins/index.php', $row['label'] );
		$this->assertSame( 'http://example.com/canary', $row['url'] );
	}

	/**
	 * An allow code (200) on a rule that should deny means the request got
	 * through: the target is not enforced and the headline flips to "no".
	 */
	public function test_shape_marks_allow_code_as_not_enforced() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array( $this->probe_row( 'plugins', 200 ) ),
			)
		);

		$this->assertSame( 'no', $shaped['htaccess_honored'] );
		$this->assertSame( 'not_enforced', $shaped['targets'][0]['state'] );
	}

	/**
	 * xmlrpc.php accepts 405 as well as 200 when the server block is inert
	 * (WordPress answers a GET with 405), so both count as "got through". Its
	 * expected code is still the single deny code the rule emits.
	 */
	public function test_shape_treats_xmlrpc_405_as_not_enforced() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array( $this->probe_row( 'xmlrpc', 405 ) ),
			)
		);

		$this->assertSame( 'not_enforced', $shaped['targets'][0]['state'] );
		$this->assertSame( 'no', $shaped['htaccess_honored'] );
		$this->assertSame( '403', $shaped['targets'][0]['expected'] );
	}

	/**
	 * Only a clear deny/allow code classifies. Redirects, a missing canary
	 * (404), a connection failure (0), a server error, or any other code are
	 * inconclusive — never guessed at — so the target and the headline both
	 * read "unknown" (no false alarms).
	 */
	public function test_shape_marks_inconclusive_codes_as_unknown() {
		foreach ( array( 404, 301, 302, 0, 500, 418 ) as $code ) {
			$shaped = SPFW_Module_Hardening::shape_enforcement_result(
				array(
					'targets' => array( $this->probe_row( 'sensitive_files', $code ) ),
				)
			);

			$this->assertSame( 'unknown', $shaped['targets'][0]['state'], 'code ' . $code . ' should be unknown' );
			$this->assertSame( 'unknown', $shaped['htaccess_honored'], 'code ' . $code . ' headline should be unknown' );
		}
	}

	// ---------------------------------------------------------------------
	// shape_enforcement_result(): server-wide headline derivation.
	// ---------------------------------------------------------------------

	/**
	 * The headline is a vhost-level property: if the server ignores one rule we
	 * wrote, it ignores all. So any single clear bypass makes the whole verdict
	 * "no", even when another canary was enforced.
	 */
	public function test_shape_headline_is_no_when_any_target_bypassed() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array(
					$this->probe_row( 'plugins', 403 ),
					$this->probe_row( 'sensitive_files', 200 ),
				),
			)
		);

		$this->assertSame( 'no', $shaped['htaccess_honored'] );
		$this->assertSame( 'enforced', $shaped['targets'][0]['state'] );
		$this->assertSame( 'not_enforced', $shaped['targets'][1]['state'] );
	}

	/**
	 * With no bypass, a single enforced canary is enough to call the vhost
	 * honored; inconclusive siblings do not drag it back to "unknown".
	 */
	public function test_shape_headline_is_yes_when_enforced_and_none_bypassed() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array(
					$this->probe_row( 'plugins', 403 ),
					$this->probe_row( 'sensitive_files', 404 ),
				),
			)
		);

		$this->assertSame( 'yes', $shaped['htaccess_honored'] );
	}

	// ---------------------------------------------------------------------
	// shape_enforcement_result(): robustness.
	// ---------------------------------------------------------------------

	/**
	 * An empty payload is a valid call (no toggles on, or every canary absent)
	 * and must produce the full shape, inconclusive rather than erroring.
	 */
	public function test_shape_handles_empty_payload() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result( array() );

		$this->assertSame( 'unknown', $shaped['htaccess_honored'] );
		$this->assertSame( array(), $shaped['targets'] );
		$this->assertSame( 0, $shaped['checked'] );
	}

	/**
	 * The raw probe rows come from loosely-typed HTTP calls, so the shaper must
	 * coerce the code, url and timestamp to the strict types the UI and the
	 * verdict rely on — a numeric string code must still classify.
	 */
	public function test_shape_coerces_loose_values_to_strict_types() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array(
					array(
						'target' => 'plugins',
						'code'   => '403',
						'url'    => 12345,
					),
				),
				'checked' => '1700000000',
			)
		);

		$row = $shaped['targets'][0];
		$this->assertSame( 'enforced', $row['state'] );
		$this->assertSame( 403, $row['observed_code'] );
		$this->assertSame( '12345', $row['url'] );
		$this->assertSame( 1700000000, $shaped['checked'] );
	}

	/**
	 * A canary key the shaper does not know still produces a row, falling back
	 * to the key as its label and a 403-deny/200-allow expectation, so an
	 * unexpected probe target degrades gracefully instead of vanishing.
	 */
	public function test_shape_falls_back_for_unknown_target_key() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array( $this->probe_row( 'mystery', 403 ) ),
			)
		);

		$row = $shaped['targets'][0];
		$this->assertSame( 'mystery', $row['target'] );
		$this->assertSame( 'mystery', $row['label'] );
		$this->assertSame( '403', $row['expected'] );
		$this->assertSame( 'enforced', $row['state'] );
	}

	/**
	 * Malformed rows (a non-array, or a row with no target) are skipped rather
	 * than shaping a phantom entry; the valid row still survives.
	 */
	public function test_shape_skips_malformed_rows() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array(
					'not-an-array',
					array( 'code' => 403 ),
					$this->probe_row( 'plugins', 403 ),
				),
			)
		);

		$this->assertCount( 1, $shaped['targets'] );
		$this->assertSame( 'plugins', $shaped['targets'][0]['target'] );
	}

	// ---------------------------------------------------------------------
	// needs_resync(): the drift status() is blind to.
	// ---------------------------------------------------------------------

	/**
	 * The reported defect: a root marker block we authored (on-disk block still
	 * matches the stored hash, so status() says "ok") that lost its
	 * block_xmlrpc group. status() cannot see this — it compares disk to the
	 * stored hash — but needs_resync() compares disk to the payload the current
	 * toggles require, so it flags the drift.
	 */
	public function test_needs_resync_true_when_authored_root_block_drifted() {
		// Author a block with only the sensitive_files group on disk.
		$this->set_hardening(
			array(
				'protect_sensitive_files' => true,
				'block_xmlrpc_file'       => false,
			)
		);
		$authored = rtrim( SPFW_Htaccess::payload( 'root' ), "\n" );
		$this->write_root_marker_block( $authored );

		// Turn on block_xmlrpc_file and store the hash of what is actually on
		// disk: the file is now "authored but drifted" from the payload the
		// current toggles require (which includes the xmlrpc group).
		$this->set_hardening(
			array(
				'protect_sensitive_files' => true,
				'block_xmlrpc_file'       => true,
				'root_htaccess_hash'      => sha1( $authored ),
			)
		);

		$this->assertSame( 'ok', SPFW_Htaccess::status( 'root' ), 'hash-only status must still read ok' );
		$this->assertTrue( SPFW_Htaccess::needs_resync( 'root' ), 'drift the hash cannot see must be caught' );
	}

	/**
	 * An authored root block that already equals the payload the current
	 * toggles require needs no rewrite.
	 */
	public function test_needs_resync_false_when_authored_root_block_matches() {
		$this->set_hardening(
			array(
				'protect_sensitive_files' => true,
				'block_xmlrpc_file'       => true,
			)
		);
		$authored = rtrim( SPFW_Htaccess::payload( 'root' ), "\n" );
		$this->write_root_marker_block( $authored );

		$this->set_hardening(
			array(
				'protect_sensitive_files' => true,
				'block_xmlrpc_file'       => true,
				'root_htaccess_hash'      => sha1( $authored ),
			)
		);

		$this->assertSame( 'ok', SPFW_Htaccess::status( 'root' ) );
		$this->assertFalse( SPFW_Htaccess::needs_resync( 'root' ) );
	}

	/**
	 * A foreign edit (on-disk block no longer matches the stored hash) reads
	 * "altered", and needs_resync() must return false so reconcile() never
	 * clobbers content we did not author — that stays a manual Restore.
	 */
	public function test_needs_resync_false_when_root_block_foreign_edited() {
		$this->write_root_marker_block( '# hand-edited by someone else' );

		$this->set_hardening(
			array(
				'protect_sensitive_files' => true,
				'block_xmlrpc_file'       => true,
				'root_htaccess_hash'      => sha1( 'a block that is not on disk' ),
			)
		);

		$this->assertSame( 'altered', SPFW_Htaccess::status( 'root' ) );
		$this->assertFalse( SPFW_Htaccess::needs_resync( 'root' ) );
	}

	/**
	 * own_file mode (plugins/): an authored file whose bytes no longer match
	 * the current payload is drifted; the sha1-of-whole-file comparison catches
	 * it the same way the marker-block path does.
	 */
	public function test_needs_resync_detects_drift_for_own_file_target() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$stale = "# BEGIN Simple Performance for WordPress\n# a rule we wrote long ago\n# END Simple Performance for WordPress\n";
		file_put_contents( $this->plugins_path, $stale ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'htaccess_hash'    => sha1( $stale ),
			)
		);

		$this->assertSame( 'ok', SPFW_Htaccess::status( 'plugins' ) );
		$this->assertTrue( SPFW_Htaccess::needs_resync( 'plugins' ) );
	}

	/**
	 * own_file mode (plugins/): an authored file that matches the current
	 * payload needs no rewrite.
	 */
	public function test_needs_resync_false_when_own_file_target_matches() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$payload = SPFW_Htaccess::payload( 'plugins' );
		file_put_contents( $this->plugins_path, $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'htaccess_hash'    => sha1( $payload ),
			)
		);

		$this->assertSame( 'ok', SPFW_Htaccess::status( 'plugins' ) );
		$this->assertFalse( SPFW_Htaccess::needs_resync( 'plugins' ) );
	}

	/**
	 * A disabled target is never "drifted": with neither root toggle on,
	 * status() is "disabled" and needs_resync() short-circuits to false so
	 * reconcile() leaves an intentionally-off site alone.
	 */
	public function test_needs_resync_false_when_target_disabled() {
		$this->set_hardening(
			array(
				'protect_sensitive_files' => false,
				'block_xmlrpc_file'       => false,
			)
		);

		$this->assertSame( 'disabled', SPFW_Htaccess::status( 'root' ) );
		$this->assertFalse( SPFW_Htaccess::needs_resync( 'root' ) );
	}

	// ---------------------------------------------------------------------
	// restore-target mapping (the root Restore button fix).
	// ---------------------------------------------------------------------

	/**
	 * The three known targets map to themselves — crucially 'root' stays
	 * 'root', the case the old coercion dropped on the floor.
	 */
	public function test_resolve_restore_target_maps_known_targets() {
		$this->assertSame( 'root', SPFW_Rest_Settings::resolve_restore_target( 'root' ) );
		$this->assertSame( 'uploads', SPFW_Rest_Settings::resolve_restore_target( 'uploads' ) );
		$this->assertSame( 'plugins', SPFW_Rest_Settings::resolve_restore_target( 'plugins' ) );
	}

	/**
	 * Anything unrecognized — empty, unknown, null, or a non-scalar — falls
	 * back to 'plugins' rather than coercing 'root'/'uploads' into it.
	 */
	public function test_resolve_restore_target_defaults_to_plugins() {
		$this->assertSame( 'plugins', SPFW_Rest_Settings::resolve_restore_target( '' ) );
		$this->assertSame( 'plugins', SPFW_Rest_Settings::resolve_restore_target( 'nonsense' ) );
		$this->assertSame( 'plugins', SPFW_Rest_Settings::resolve_restore_target( null ) );
		$this->assertSame( 'plugins', SPFW_Rest_Settings::resolve_restore_target( array( 'root' ) ) );
	}

	// ---------------------------------------------------------------------
	// REST route registration.
	// ---------------------------------------------------------------------

	/**
	 * The verify-htaccess route must be registered as POST under the plugin
	 * namespace and gated by the same manage_options capability as every other
	 * settings route — it fires loopback requests and writes the cached
	 * verdict.
	 */
	public function test_verify_htaccess_route_registered_as_post_with_permission_callback() {
		$controller = new SPFW_Rest_Settings();
		$controller->register_routes();

		global $spfw_test_rest_routes;

		$key = 'spfw/v1/settings/verify-htaccess';

		$this->assertArrayHasKey( $key, $spfw_test_rest_routes, $key . ' was not registered' );

		$args = $spfw_test_rest_routes[ $key ];

		$this->assertSame( WP_REST_Server::CREATABLE, $args['methods'] );
		$this->assertSame( array( $controller, 'verify_htaccess' ), $args['callback'] );
		$this->assertSame( array( $controller, 'check_permissions' ), $args['permission_callback'] );
	}
}
