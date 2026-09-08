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
 * lightweight bootstrap.
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

		global $spfw_test_options, $spfw_test_rest_routes, $spfw_test_capabilities, $spfw_test_home_url;
		$spfw_test_options      = array();
		$spfw_test_rest_routes  = array();
		$spfw_test_capabilities = array( 'manage_options' => true );

		// The subdirectory-install tests set this and cannot restore it from
		// inside the test body, so every test defined after them used to
		// inherit a /blog install and its URI base. Reset to the bootstrap
		// default here so payload assertions are order-independent.
		$spfw_test_home_url = 'http://example.com';

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

	// ---------------------------------------------------------------------
	// OpenLiteSpeed-compatible RewriteRule payloads.
	// ---------------------------------------------------------------------

	/**
	 * The root payload for sensitive_files includes a RewriteRule that
	 * OpenLiteSpeed honors, in addition to the Apache FilesMatch block.
	 */
	public function test_root_payload_includes_rewrite_rule_for_sensitive_files() {
		$this->set_hardening( array( 'protect_sensitive_files' => true ) );
		$payload = SPFW_Htaccess::payload( 'root' );

		$this->assertStringContainsString( 'RewriteEngine On', $payload );
		$this->assertStringContainsString(
			'RewriteRule ^/?(readme\\.html|license\\.txt|wp-config-sample\\.php|.*\\.(log|sql|bak|old|orig|env))$ - [F,L]',
			$payload
		);
		$this->assertStringContainsString( '<FilesMatch', $payload );
	}

	/**
	 * The root payload for block_xmlrpc includes a RewriteRule for xmlrpc.php.
	 */
	public function test_root_payload_includes_rewrite_rule_for_xmlrpc() {
		$this->set_hardening( array( 'block_xmlrpc_file' => true ) );
		$payload = SPFW_Htaccess::payload( 'root' );

		$this->assertStringContainsString( 'RewriteEngine On', $payload );
		$this->assertStringContainsString( 'RewriteRule ^/?xmlrpc\\.php$ - [F,L]', $payload );
		$this->assertStringContainsString( '<Files "xmlrpc.php">', $payload );
	}

	/**
	 * The blanket deny-PHP payload includes a RewriteRule that refuses
	 * PHP-family extensions before the FilesMatch block.
	 */
	public function test_deny_php_payload_includes_rewrite_rule() {
		$payload = SPFW_Htaccess::payload_deny_php();

		$this->assertStringContainsString( 'RewriteEngine On', $payload );
		$this->assertStringContainsString(
			'RewriteRule \\.(?i:php[0-9]*|phtml|phps|phar|inc)$ - [F,L]',
			$payload
		);
		$this->assertStringContainsString( '<FilesMatch', $payload );
	}

	/**
	 * The whitelist-aware deny-PHP payload allows whitelisted files through
	 * with [L], then denies everything else with [F,L], so OpenLiteSpeed gets
	 * real enforcement even though <FilesMatch> is inert there.
	 */
	public function test_whitelist_payload_allows_then_denies_with_rewrite_rules() {
		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'php_whitelist'    => array( 'plugins/allowed.php' ),
			)
		);
		$payload = SPFW_Htaccess::payload( 'plugins' );

		$this->assertStringContainsString( 'RewriteEngine On', $payload );
		$this->assertStringContainsString(
			'RewriteCond %{REQUEST_URI} ^/wp-content/plugins\\/allowed\\.php$',
			$payload
		);

		// The allow rule must appear before the deny rule.
		$allow_pos = strpos( $payload, 'RewriteRule \\.(?i:php[0-9]*|phtml|phps|phar|inc)$ - [L]' );
		$deny_pos  = strpos( $payload, 'RewriteRule \\.(?i:php[0-9]*|phtml|phps|phar|inc)$ - [F,L]' );

		$this->assertNotFalse( $allow_pos, 'whitelist allow rule missing' );
		$this->assertNotFalse( $deny_pos, 'whitelist deny rule missing' );
		$this->assertLessThan( $deny_pos, $allow_pos, 'allow rule must precede deny rule' );
	}

	/**
	 * Root RewriteRule patterns include the site path prefix for subdirectory
	 * installs so /blog/readme.html is blocked on a /blog/ WordPress install.
	 */
	public function test_root_payload_rewrite_rules_respect_subdirectory_install() {
		global $spfw_test_home_url;
		$spfw_test_home_url = 'http://example.com/blog';

		$this->set_hardening(
			array(
				'protect_sensitive_files' => true,
				'block_xmlrpc_file'       => true,
			)
		);
		$payload = SPFW_Htaccess::payload( 'root' );

		$this->assertStringContainsString( 'RewriteRule ^/?blog\\/(readme\\.html|license\\.txt|wp-config-sample\\.php|.*\\.(log|sql|bak|old|orig|env))$ - [F,L]', $payload );
		$this->assertStringContainsString( 'RewriteRule ^/?blog\\/xmlrpc\\.php$ - [F,L]', $payload );
	}

	/**
	 * Whitelist RewriteCond patterns include the site path prefix for
	 * subdirectory installs.
	 */
	public function test_whitelist_payload_rewrite_conditions_respect_subdirectory_install() {
		global $spfw_test_home_url;
		$spfw_test_home_url = 'http://example.com/blog';

		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'php_whitelist'    => array( 'plugins/allowed.php' ),
			)
		);
		$payload = SPFW_Htaccess::payload( 'plugins' );

		$this->assertStringContainsString(
			'RewriteCond %{REQUEST_URI} ^/blog/wp-content/plugins\\/allowed\\.php$',
			$payload
		);
	}

	/**
	 * The whitelist allow chain is not enough on its own: mod_rewrite runs
	 * before authorization, so a file the RewriteRule let through is still
	 * refused by the <FilesMatch> deny on every server that honors it. The
	 * payload must therefore re-grant each whitelisted basename with a <Files>
	 * section placed AFTER the deny block, since Apache merges <Files> and
	 * <FilesMatch> in source order and the last matching section wins.
	 *
	 * This is the assertion whose absence let the LiteSpeed Guest Mode 403 ship
	 * in 2.10.0 — the payload looked correct on OpenLiteSpeed only because OLS
	 * ignores <FilesMatch> entirely.
	 */
	public function test_whitelist_payload_grants_authz_after_the_deny_block() {
		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'php_whitelist'    => array( 'plugins/litespeed-cache/guest.vary.php' ),
			)
		);
		$payload = SPFW_Htaccess::payload( 'plugins' );

		$deny_pos  = strpos( $payload, '<FilesMatch "\\\\.(?i:php[0-9]*|phtml|phps|phar|inc)$">' );
		$grant_pos = strpos( $payload, '<Files "guest.vary.php">' );

		$this->assertNotFalse( $deny_pos, 'FilesMatch deny block missing' );
		$this->assertNotFalse( $grant_pos, 'whitelist <Files> grant missing' );
		$this->assertLessThan(
			$grant_pos,
			$deny_pos,
			'the <Files> grant must follow the <FilesMatch> deny or Apache keeps denying'
		);
		$this->assertStringContainsString( 'Require all granted', $payload );

		// Pre-2.4 servers get the same exemption in the authz fallback block.
		$this->assertStringContainsString( 'Allow from all', $payload );
	}

	/**
	 * The blanket payload (no whitelist) grants nothing — the authz exemption
	 * exists only to serve whitelist entries.
	 */
	public function test_blanket_payload_grants_no_authz_exemption() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );
		$payload = SPFW_Htaccess::payload( 'plugins' );

		$this->assertStringNotContainsString( 'Require all granted', $payload );
		$this->assertStringNotContainsString( 'Allow from all', $payload );
	}

	/**
	 * A stored path carrying a character that could terminate a quoted
	 * .htaccess argument is dropped rather than interpolated, so a value that
	 * predates the sanitizer's charset check cannot produce a file that 500s
	 * the directory.
	 */
	public function test_whitelist_payload_drops_paths_with_unsafe_characters() {
		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'php_whitelist'    => array( 'plugins/evil".php', 'plugins/good.php' ),
			)
		);
		$payload = SPFW_Htaccess::payload( 'plugins' );

		$this->assertStringNotContainsString( 'evil', $payload );
		$this->assertStringContainsString( '<Files "good.php">', $payload );
	}

	// ---------------------------------------------------------------------
	// shape_enforcement_result(): allow-mode (whitelist) canaries.
	// ---------------------------------------------------------------------

	/**
	 * A whitelisted file that answers 200 is reachable — the pass condition for
	 * an allow-mode canary. Its expected code is the allow code, not the deny
	 * code, and the per-row label overrides the shared canary label.
	 */
	public function test_shape_marks_reachable_whitelist_file_as_allowed() {
		$row          = $this->probe_row( 'whitelist', 200 );
		$row['label'] = 'plugins/litespeed-cache/guest.vary.php';

		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array( 'targets' => array( $row ) )
		);

		$this->assertSame( 'allowed', $shaped['targets'][0]['state'] );
		$this->assertSame( '200', $shaped['targets'][0]['expected'] );
		$this->assertSame(
			'plugins/litespeed-cache/guest.vary.php',
			$shaped['targets'][0]['label']
		);
		$this->assertFalse( $shaped['whitelist_blocked'] );
	}

	/**
	 * A whitelisted file answering 403 is the failure this probe exists to
	 * catch, and it raises the report-level whitelist_blocked flag.
	 */
	public function test_shape_marks_blocked_whitelist_file() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array( 'targets' => array( $this->probe_row( 'whitelist', 403 ) ) )
		);

		$this->assertSame( 'whitelist_blocked', $shaped['targets'][0]['state'] );
		$this->assertTrue( $shaped['whitelist_blocked'] );
	}

	/**
	 * An allow-mode canary must not move the vhost-level htaccess_honored
	 * verdict in either direction: a whitelisted file is reachable both when
	 * the rules work as intended and when the server ignores .htaccess
	 * entirely, so it proves nothing about enforcement.
	 */
	public function test_shape_whitelist_canary_does_not_move_the_headline() {
		$reachable = SPFW_Module_Hardening::shape_enforcement_result(
			array( 'targets' => array( $this->probe_row( 'whitelist', 200 ) ) )
		);
		$this->assertSame( 'unknown', $reachable['htaccess_honored'] );

		$blocked = SPFW_Module_Hardening::shape_enforcement_result(
			array( 'targets' => array( $this->probe_row( 'whitelist', 403 ) ) )
		);
		$this->assertSame( 'unknown', $blocked['htaccess_honored'] );

		// A real deny canary alongside it still decides the headline.
		$mixed = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array(
					$this->probe_row( 'whitelist', 200 ),
					$this->probe_row( 'plugins', 403 ),
				),
			)
		);
		$this->assertSame( 'yes', $mixed['htaccess_honored'] );
	}

	// ---------------------------------------------------------------------
	// Auto-allow for known direct-access plugin endpoints.
	// ---------------------------------------------------------------------

	/**
	 * Create a known direct-access file on disk so the detector sees it.
	 *
	 * @return string Absolute path written.
	 */
	private function install_known_direct_access_file() {
		$path = WP_CONTENT_DIR . '/plugins/litespeed-cache/guest.vary.php';
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "<?php // stub\n" );

		return $path;
	}

	/**
	 * With auto-allow on (the default), an installed LiteSpeed Guest Mode file
	 * is permitted by the very first payload, without the admin whitelisting
	 * anything. This is what removes the two-restart broken window on
	 * OpenLiteSpeed, where an .htaccess edit is inert until a graceful restart.
	 */
	public function test_known_direct_access_file_is_allowed_without_being_whitelisted() {
		$path = $this->install_known_direct_access_file();

		try {
			$this->set_hardening( array( 'plugins_htaccess' => true ) );
			$payload = SPFW_Htaccess::payload( 'plugins' );

			$this->assertStringContainsString( 'guest.vary.php', $payload );
			$this->assertStringContainsString( '<Files "guest.vary.php">', $payload );
			$this->assertStringContainsString(
				'RewriteCond %{REQUEST_URI} ^/wp-content/plugins\\/litespeed\\-cache\\/guest\\.vary\\.php$',
				$payload
			);
		} finally {
			unlink( $path );
		}
	}

	/**
	 * The allowance is gated on the file existing: a site without LiteSpeed
	 * installed gets a blanket deny, not a standing hole for a path that is
	 * not there.
	 */
	public function test_known_direct_access_file_absent_yields_blanket_deny() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );
		$payload = SPFW_Htaccess::payload( 'plugins' );

		$this->assertStringNotContainsString( 'guest.vary.php', $payload );
		$this->assertStringNotContainsString( 'Require all granted', $payload );
	}

	/**
	 * Turning auto_allow_known_php off restores the total deny, so an admin who
	 * does not use Guest Mode is not stuck with the allowance.
	 */
	public function test_auto_allow_can_be_turned_off() {
		$path = $this->install_known_direct_access_file();

		try {
			$this->set_hardening(
				array(
					'plugins_htaccess'     => true,
					'auto_allow_known_php' => false,
				)
			);
			$payload = SPFW_Htaccess::payload( 'plugins' );

			$this->assertStringNotContainsString( 'guest.vary.php', $payload );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * An admin entry and the auto-added one never produce a duplicate rule.
	 */
	public function test_auto_allow_does_not_duplicate_an_explicit_whitelist_entry() {
		$path = $this->install_known_direct_access_file();

		try {
			$this->set_hardening(
				array(
					'plugins_htaccess' => true,
					'php_whitelist'    => array( 'plugins/litespeed-cache/guest.vary.php' ),
				)
			);
			$payload = SPFW_Htaccess::payload( 'plugins' );

			// Emitted twice by design — once for mod_authz_core and once in
			// the pre-2.4 <IfModule !mod_authz_core.c> fallback, which is
			// indented. Count the un-indented one to prove the path was not
			// added twice over (explicit entry plus auto-detected).
			$this->assertSame(
				1,
				substr_count( $payload, "\n<Files \"guest.vary.php\">" ),
				'the allowance must be emitted once, not once per source'
			);
			$this->assertSame(
				1,
				substr_count( $payload, 'RewriteCond' ),
				'one RewriteCond, not one per source'
			);
		} finally {
			unlink( $path );
		}
	}

	// ---------------------------------------------------------------------
	// No-op writes (every needless rewrite costs an OpenLiteSpeed restart).
	// ---------------------------------------------------------------------

	/**
	 * Writing a payload that already matches the file byte for byte must not
	 * touch it. On OpenLiteSpeed a rewrite desynchronizes the running server
	 * from disk until the next graceful restart, so a no-op write is not free.
	 * Callers rewrite on any php_whitelist change and that comparison is
	 * order-sensitive, so a mere reorder used to land here.
	 */
	public function test_write_does_not_touch_a_file_that_already_matches() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$this->assertTrue( SPFW_Htaccess::write( 'plugins' ) );
		$this->assertFileExists( $this->plugins_path );

		// Backdate so any rewrite is detectable by mtime.
		touch( $this->plugins_path, time() - 500 );
		clearstatcache();
		$before = filemtime( $this->plugins_path );

		$this->assertTrue( SPFW_Htaccess::write( 'plugins' ) );

		clearstatcache();
		$this->assertSame(
			$before,
			filemtime( $this->plugins_path ),
			'an identical payload must not rewrite the file'
		);
	}

	/**
	 * A genuinely different payload is still written.
	 */
	public function test_write_still_updates_a_file_whose_payload_changed() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );
		SPFW_Htaccess::write( 'plugins' );

		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'php_whitelist'    => array( 'plugins/allowed.php' ),
			)
		);
		$this->assertTrue( SPFW_Htaccess::write( 'plugins' ) );

		$this->assertStringContainsString(
			'<Files "allowed.php">',
			file_get_contents( $this->plugins_path )
		);
	}

	// ---------------------------------------------------------------------
	// Staleness: has .htaccess changed since the verdict was measured?
	// ---------------------------------------------------------------------

	/**
	 * With no probe ever run there is nothing to compare against, so this
	 * reports false. An unknown is not a warning.
	 */
	public function test_changed_since_probe_is_false_without_a_stored_probe() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$this->assertFalse( SPFW_Module_Hardening::htaccess_changed_since_probe() );
	}

	/**
	 * A stored result predating the fingerprint (an upgrade) also reports
	 * false rather than warning about a comparison it cannot make.
	 */
	public function test_changed_since_probe_is_false_for_a_pre_fingerprint_result() {
		$this->set_hardening(
			array(
				'plugins_htaccess'     => true,
				'htaccess_enforcement' => array(
					'htaccess_honored' => 'yes',
					'targets'          => array(),
					'checked'          => 1700000000,
				),
			)
		);

		$this->assertFalse( SPFW_Module_Hardening::htaccess_changed_since_probe() );
	}

	/**
	 * When the files still match the fingerprint the verdict is current.
	 */
	public function test_changed_since_probe_is_false_when_files_match() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );
		SPFW_Htaccess::write( 'plugins' );

		$this->set_hardening(
			array(
				'plugins_htaccess'     => true,
				'htaccess_enforcement' => array(
					'htaccess_honored' => 'yes',
					'targets'          => array(),
					'checked'          => 1700000000,
					'payload_hashes'   => SPFW_Module_Hardening::current_htaccess_hashes(),
				),
			)
		);

		$this->assertFalse( SPFW_Module_Hardening::htaccess_changed_since_probe() );
	}

	/**
	 * Editing .htaccess after the probe makes the cached verdict describe rules
	 * that are no longer on disk. On OpenLiteSpeed it also means the running
	 * server is still applying the previous rules until a graceful restart,
	 * which is the state this flag exists to surface instead of a stale green
	 * badge.
	 */
	public function test_changed_since_probe_is_true_after_the_file_changes() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );
		SPFW_Htaccess::write( 'plugins' );

		$stale = SPFW_Module_Hardening::current_htaccess_hashes();

		// A later edit — here, the admin adding a whitelist entry.
		$this->set_hardening(
			array(
				'plugins_htaccess' => true,
				'php_whitelist'    => array( 'plugins/allowed.php' ),
			)
		);
		SPFW_Htaccess::write( 'plugins' );

		$this->set_hardening(
			array(
				'plugins_htaccess'     => true,
				'php_whitelist'        => array( 'plugins/allowed.php' ),
				'htaccess_enforcement' => array(
					'htaccess_honored' => 'yes',
					'targets'          => array(),
					'checked'          => 1700000000,
					'payload_hashes'   => $stale,
				),
			)
		);

		$this->assertTrue( SPFW_Module_Hardening::htaccess_changed_since_probe() );
	}

	/**
	 * A file disappearing counts as a change too, not just an edit.
	 */
	public function test_changed_since_probe_is_true_when_the_file_is_removed() {
		$this->set_hardening( array( 'plugins_htaccess' => true ) );
		SPFW_Htaccess::write( 'plugins' );

		$hashes = SPFW_Module_Hardening::current_htaccess_hashes();
		unlink( $this->plugins_path );

		$this->set_hardening(
			array(
				'plugins_htaccess'     => true,
				'htaccess_enforcement' => array(
					'htaccess_honored' => 'yes',
					'targets'          => array(),
					'checked'          => 1700000000,
					'payload_hashes'   => $hashes,
				),
			)
		);

		$this->assertTrue( SPFW_Module_Hardening::htaccess_changed_since_probe() );
	}
}
