<?php
/**
 * Coverage for the CSP violation-collection loop and the script-hash scanner.
 *
 * All of this exists because of one field failure (2026-09-14): a site ran CSP
 * in Report-Only with a collection window, collected nothing, enforced on the
 * strength of that empty log, and immediately stopped logged-out visitors from
 * resetting their passwords — the reCAPTCHA widget could neither load its frame
 * nor fetch its token. The log was not wrong about what it received. Almost
 * nothing could reach it, and the default policy blocked the widget by
 * construction.
 *
 * Each test below pins one of the reasons that was possible.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for collection coverage, admin self-test, and inline-script hashing.
 */
class Csp_Collection_Test extends TestCase {

	/**
	 * Reset every global the stubs read, plus the settings cache.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_transients, $spfw_test_cache,
			$spfw_test_logged_in, $spfw_test_capabilities, $spfw_test_page,
			$spfw_test_cron;

		$spfw_test_options      = array();
		$spfw_test_transients   = array();
		$spfw_test_cache        = array();
		$spfw_test_logged_in    = false;
		$spfw_test_capabilities = array();
		$spfw_test_page         = '';
		$spfw_test_cron         = array();

		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );
	}

	/**
	 * Call a private/protected static method on a class.
	 *
	 * @param string $class  Class name.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call_static( $class, $method, array $args = array() ) {
		$ref = new ReflectionMethod( $class, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( null, $args );
	}

	// -----------------------------------------------------------------------
	// The inline-script hash scanner.
	// -----------------------------------------------------------------------

	/**
	 * The hash must be taken over the script's exact text content.
	 *
	 * This is the defect that made script-src tightening unusable: the scanner
	 * trimmed the body first, so the digest it stored was the digest of
	 * different bytes than the ones the browser hashes. Every inline script on
	 * the site was then blocked, and nothing anywhere said why — the scan
	 * reported a healthy count of plausible-looking hashes.
	 *
	 * The expected value here is computed the way a browser computes it: over
	 * the characters between the tags, leading newline and indentation
	 * included.
	 */
	public function test_hash_is_taken_over_the_exact_script_text() {
		$body = "\n\t\tconsole.log( 'hi' );\n\t";
		$html = "<html><body><script>{$body}</script></body></html>";

		$expected = base64_encode( hash( 'sha256', $body, true ) );

		$this->assertSame(
			array( $expected ),
			SPFW_Rest_Settings::extract_script_hashes( $html )
		);
	}

	/**
	 * The trimmed digest — what the old scanner produced — is a different
	 * value, so this is a real behavioral difference and not a refactor.
	 */
	public function test_trimmed_hash_is_not_what_we_store() {
		$body = "\n\t\tconsole.log( 'hi' );\n\t";
		$html = "<script>{$body}</script>";

		$trimmed = base64_encode( hash( 'sha256', trim( $body ), true ) );
		$actual  = SPFW_Rest_Settings::extract_script_hashes( $html );

		$this->assertNotContains( $trimmed, $actual );
	}

	/**
	 * External scripts are not hashed: script-src matches them by URL, and
	 * their body is not in our document anyway.
	 */
	public function test_external_scripts_are_skipped() {
		$html = '<script src="https://example.com/a.js"></script>';

		$this->assertSame( array(), SPFW_Rest_Settings::extract_script_hashes( $html ) );
	}

	/**
	 * An attribute that merely contains "src" is not a src attribute. A
	 * data-src carrier still holds inline script that needs hashing.
	 */
	public function test_data_src_attribute_does_not_look_like_an_external_script() {
		$html = '<script data-srcset="x">var a = 1;</script>';

		$this->assertSame(
			array( base64_encode( hash( 'sha256', 'var a = 1;', true ) ) ),
			SPFW_Rest_Settings::extract_script_hashes( $html )
		);
	}

	/**
	 * Non-executable script types are data, not script, and are never subject
	 * to script-src. The old type check was dead code — it looked the trimmed
	 * body up in the untrimmed match array, which always failed — so JSON-LD
	 * was hashed into the header on every site that emits it.
	 */
	public function test_non_executable_script_types_are_skipped() {
		$html = '<script type="application/ld+json">{"@type":"Thing"}</script>'
			. '<script type="text/template"><div></div></script>'
			. '<script type="application/json">{"a":1}</script>';

		$this->assertSame( array(), SPFW_Rest_Settings::extract_script_hashes( $html ) );
	}

	/**
	 * An explicit executable type, and a module, are still hashed.
	 */
	public function test_executable_types_are_hashed() {
		$html = '<script type="text/javascript">var a = 1;</script>'
			. '<script type="module">var b = 2;</script>';

		$this->assertCount( 2, SPFW_Rest_Settings::extract_script_hashes( $html ) );
	}

	/**
	 * Empty and whitespace-only scripts have nothing to execute.
	 */
	public function test_empty_scripts_are_skipped() {
		$html = '<script></script><script>   </script><script>' . "\n\n" . '</script>';

		$this->assertSame( array(), SPFW_Rest_Settings::extract_script_hashes( $html ) );
	}

	/**
	 * Identical scripts produce one hash, not one per occurrence.
	 */
	public function test_identical_scripts_are_de_duplicated() {
		$html = '<script>var a = 1;</script><script>var a = 1;</script>';

		$this->assertCount( 1, SPFW_Rest_Settings::extract_script_hashes( $html ) );
	}

	/**
	 * The hash count is bounded. Every hash is ~51 bytes in a header sent on
	 * every response, so an unbounded list eventually produces a response no
	 * proxy will accept — a failure that looks nothing like its cause.
	 */
	public function test_hash_count_is_capped() {
		$html = '';

		for ( $i = 0; $i < SPFW_Rest_Settings::CSP_MAX_HASHES + 20; $i++ ) {
			$html .= '<script>var a = ' . $i . ';</script>';
		}

		$this->assertCount(
			SPFW_Rest_Settings::CSP_MAX_HASHES,
			SPFW_Rest_Settings::extract_script_hashes( $html )
		);
	}

	// -----------------------------------------------------------------------
	// strict-dynamic is its own decision.
	// -----------------------------------------------------------------------

	/**
	 * Hashing alone must not touch the host allowlist.
	 *
	 * Bundling 'strict-dynamic' into the hashing toggle silently discarded
	 * every host the admin had added from the violation log, because supporting
	 * browsers ignore host sources once it is present. Turning on "tighten
	 * script-src" therefore blocked the very third-party scripts the allowlist
	 * existed to permit.
	 */
	public function test_hashes_alone_keep_the_host_allowlist() {
		$policy = "script-src 'self' 'unsafe-inline' https: https://www.google.com;";

		$out = $this->call_static(
			'SPFW_Module_Hardening',
			'inject_script_hashes',
			array( $policy, array( 'abc123' ), false )
		);

		$this->assertStringContainsString( 'https://www.google.com', $out );
		$this->assertStringContainsString( 'https:', $out );
		$this->assertStringContainsString( "'sha256-abc123'", $out );
		$this->assertStringNotContainsString( "'strict-dynamic'", $out );
		// The hashes replace 'unsafe-inline', which a browser would ignore
		// alongside them anyway.
		$this->assertStringNotContainsString( "'unsafe-inline'", $out );
	}

	/**
	 * Opting in drops the sources it would make the browser ignore, so the
	 * emitted header says what it means.
	 */
	public function test_strict_dynamic_drops_the_sources_it_voids() {
		$policy = "script-src 'self' 'unsafe-inline' https: https://www.google.com;";

		$out = $this->call_static(
			'SPFW_Module_Hardening',
			'inject_script_hashes',
			array( $policy, array( 'abc123' ), true )
		);

		$this->assertStringContainsString( "'strict-dynamic'", $out );
		$this->assertStringContainsString( "'self'", $out );
		$this->assertStringNotContainsString( 'https://www.google.com', $out );
		$this->assertStringNotContainsString( ' https:', $out );
	}

	// -----------------------------------------------------------------------
	// The default policy.
	// -----------------------------------------------------------------------

	/**
	 * The policy string constant and the builder's default directive map are
	 * the same policy. Two things both called "the default" that differ is how
	 * `blob:` came to be in one and not the other.
	 */
	public function test_the_two_defaults_are_the_same_policy() {
		$defaults = $this->call_static( 'SPFW_Settings', 'defaults' );

		$this->assertSame(
			SPFW_Module_Hardening::default_csp_directives(),
			$defaults['hardening']['csp_directives']
		);
	}

	/**
	 * The default policy does not break a reCAPTCHA widget.
	 *
	 * Both halves matter and both were missing. Without `frame-src` the
	 * challenge iframe falls back to `default-src 'self'` and is refused;
	 * without a third-party source in `connect-src` the token call is refused,
	 * and the visitor is told only that an anti-spam token is missing.
	 */
	public function test_default_policy_does_not_break_third_party_widgets() {
		$directives = SPFW_Module_Hardening::default_csp_directives();

		$this->assertArrayHasKey( 'frame-src', $directives );
		$this->assertContains( 'https:', $directives['frame-src'] );
		$this->assertContains( 'https:', $directives['connect-src'] );
	}

	/**
	 * Widening those two must not have quietly reopened the holes the default
	 * policy exists to close.
	 */
	public function test_default_policy_still_closes_the_high_value_holes() {
		$directives = SPFW_Module_Hardening::default_csp_directives();

		$this->assertSame( array( "'none'" ), $directives['object-src'] );
		$this->assertSame( array( "'self'" ), $directives['base-uri'] );
		$this->assertSame( array( "'self'" ), $directives['frame-ancestors'] );
		$this->assertSame( array( "'self'" ), $directives['default-src'] );
	}

	// -----------------------------------------------------------------------
	// Admin self-test.
	// -----------------------------------------------------------------------

	/**
	 * Settings shaped for an open (or closed) collection window.
	 *
	 * @param bool $open         Whether the window is open.
	 * @param bool $collect_admin Whether admin self-test is enabled.
	 * @return array
	 */
	private function window( $open, $collect_admin = true ) {
		return array(
			'csp_collect_until' => $open ? time() + 3600 : 0,
			'csp_collect_admin' => $collect_admin,
		);
	}

	/**
	 * An administrator inside an open window is included in the test.
	 *
	 * Without this the person running the test is the only visitor the test
	 * cannot see: they browse the site, no header is sent to them, nothing is
	 * violated, and the window closes on an empty log that reads as proof.
	 */
	public function test_admin_is_included_while_a_window_is_open() {
		global $spfw_test_logged_in, $spfw_test_capabilities;
		$spfw_test_logged_in    = true;
		$spfw_test_capabilities = array( 'manage_options' => true );

		$this->assertTrue(
			SPFW_Module_Hardening::admin_self_test_active( $this->window( true ) )
		);
	}

	/**
	 * Outside a window it is always off, so it cannot leak into normal
	 * operation — and a window closes itself.
	 */
	public function test_admin_self_test_is_off_outside_a_window() {
		global $spfw_test_logged_in, $spfw_test_capabilities;
		$spfw_test_logged_in    = true;
		$spfw_test_capabilities = array( 'manage_options' => true );

		$this->assertFalse(
			SPFW_Module_Hardening::admin_self_test_active( $this->window( false ) )
		);
	}

	/**
	 * A logged-in user without manage_options is not an administrator testing
	 * a policy — they are a subscriber, and the exclusion still applies.
	 */
	public function test_non_admins_are_not_included() {
		global $spfw_test_logged_in, $spfw_test_capabilities;
		$spfw_test_logged_in    = true;
		$spfw_test_capabilities = array();

		$this->assertFalse(
			SPFW_Module_Hardening::admin_self_test_active( $this->window( true ) )
		);
	}

	/**
	 * The setting still turns it off.
	 */
	public function test_admin_self_test_honors_its_setting() {
		global $spfw_test_logged_in, $spfw_test_capabilities;
		$spfw_test_logged_in    = true;
		$spfw_test_capabilities = array( 'manage_options' => true );

		$this->assertFalse(
			SPFW_Module_Hardening::admin_self_test_active( $this->window( true, false ) )
		);
	}

	// -----------------------------------------------------------------------
	// Window coverage.
	// -----------------------------------------------------------------------

	/**
	 * Coverage records which page types a window actually reached.
	 *
	 * Reports alone cannot answer this: a page that loads cleanly produces no
	 * report, so silence from a page nobody visited is indistinguishable from
	 * silence from a page that passed. That ambiguity is what let an empty log
	 * be read as evidence.
	 */
	public function test_coverage_records_the_page_types_a_window_reached() {
		global $spfw_test_page;

		$h = array( 'csp_collect_until' => time() + 3600 );

		$spfw_test_page = 'home';
		SPFW_Module_Hardening::record_collection_coverage( $h );

		$spfw_test_page = 'post';
		SPFW_Module_Hardening::record_collection_coverage( $h );

		SPFW_Settings::update( array( 'hardening' => array( 'csp_collect_until' => $h['csp_collect_until'] ) ) );

		$coverage = SPFW_Module_Hardening::collection_coverage();

		$this->assertTrue( $coverage['home'] );
		$this->assertTrue( $coverage['post'] );
		$this->assertFalse( $coverage['search'] );
		$this->assertFalse( $coverage['404'] );
	}

	/**
	 * Coverage belongs to one window. Opening a new one starts from nothing
	 * rather than inheriting last week's checkmarks — which would show pages as
	 * tested against a policy that has since changed.
	 */
	public function test_coverage_resets_when_a_new_window_opens() {
		global $spfw_test_page;

		$first          = time() + 3600;
		$spfw_test_page = 'home';
		SPFW_Module_Hardening::record_collection_coverage( array( 'csp_collect_until' => $first ) );

		SPFW_Settings::update( array( 'hardening' => array( 'csp_collect_until' => $first + 500 ) ) );

		$coverage = SPFW_Module_Hardening::collection_coverage();

		$this->assertFalse( $coverage['home'] );
	}

	/**
	 * Recording is idempotent per page type, so it stays off the hot path even
	 * with the page cache bypassed.
	 */
	public function test_coverage_writes_once_per_page_type() {
		global $spfw_test_page, $spfw_test_transients;

		$h              = array( 'csp_collect_until' => time() + 3600 );
		$spfw_test_page = 'home';

		SPFW_Module_Hardening::record_collection_coverage( $h );
		$after_first = $spfw_test_transients[ SPFW_Module_Hardening::CSP_COVERAGE_KEY ];

		SPFW_Module_Hardening::record_collection_coverage( $h );

		$this->assertSame(
			$after_first,
			$spfw_test_transients[ SPFW_Module_Hardening::CSP_COVERAGE_KEY ]
		);
	}

	/**
	 * Cart and checkout are ordinary pages as far as is_singular( 'page' ) is
	 * concerned, so the specific tests have to run first or the two templates
	 * that matter most on a commerce site are filed as "a page".
	 */
	public function test_page_type_classification_prefers_the_specific_test() {
		global $spfw_test_page;

		$spfw_test_page = 'page';
		$this->assertSame( 'page', SPFW_Module_Hardening::current_page_type() );

		$spfw_test_page = '404';
		$this->assertSame( '404', SPFW_Module_Hardening::current_page_type() );

		$spfw_test_page = 'search';
		$this->assertSame( 'search', SPFW_Module_Hardening::current_page_type() );
	}

	/**
	 * A site without WooCommerce is not asked to cover a checkout it does not
	 * have — a checklist that can never be completed teaches admins to ignore
	 * it.
	 */
	public function test_woo_page_types_are_dropped_without_woocommerce() {
		$types = SPFW_Module_Hardening::applicable_page_types();

		$this->assertContains( 'home', $types );
		$this->assertNotContains( 'checkout', $types );
		$this->assertNotContains( 'cart', $types );
	}

	/**
	 * Coverage must be recorded after the query has run, not while headers are
	 * being sent.
	 *
	 * WP::main() calls send_headers() BEFORE query_posts() and handle_404(), so
	 * at the point the CSP header goes out no template conditional is
	 * answerable yet: is_404(), is_singular() and WooCommerce's
	 * is_cart()/is_checkout() all read false, and every page in the window
	 * would be filed as 'other' — a checklist that silently measures nothing.
	 *
	 * This pins the hook the recording is deferred to. `wp_footer` or any other
	 * late hook would do; `send_headers` would not.
	 */
	public function test_coverage_is_recorded_after_the_query_runs() {
		global $spfw_test_hooks, $spfw_test_logged_in;

		$spfw_test_hooks     = array();
		$spfw_test_logged_in = false;

		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_enabled'           => true,
					'csp_collect_until'     => time() + 3600,
					'csp_exclude_logged_in' => true,
				),
			)
		);

		$module = new SPFW_Module_Hardening();
		$module->add_csp_header();

		$tags = array();

		foreach ( $spfw_test_hooks as $hook ) {
			if ( is_array( $hook['callback'] ) && 'record_coverage_for_request' === $hook['callback'][1] ) {
				$tags[] = $hook['tag'];
			}
		}

		$this->assertSame( array( 'template_redirect' ), $tags );
	}

	// -----------------------------------------------------------------------
	// The window that opens when enforcement begins.
	// -----------------------------------------------------------------------

	/**
	 * Invoke SPFW_Rest_Settings::maybe_open_enforcement_window().
	 *
	 * @param array $before Hardening group before the save.
	 * @param array $after  Hardening group after the save.
	 */
	private function enforce_transition( array $before, array $after ) {
		$rest = new SPFW_Rest_Settings();
		$ref  = new ReflectionMethod( 'SPFW_Rest_Settings', 'maybe_open_enforcement_window' );
		$ref->setAccessible( true );
		$ref->invokeArgs( $rest, array( $before, $after ) );
	}

	/**
	 * Leaving Report-Only opens a short window, so a policy that breaks
	 * something records what it broke.
	 *
	 * Until now the riskiest minutes in this feature's life — the ones just
	 * after a policy starts blocking for real — were the only ones with
	 * reporting switched off. A policy that broke checkout went silent exactly
	 * when it started costing orders.
	 */
	public function test_enforcing_opens_a_collection_window() {
		$this->enforce_transition(
			array( 'csp_report_only' => true ),
			array(
				'csp_report_only'   => false,
				'csp_enabled'       => true,
				'csp_collect_until' => 0,
			)
		);

		$h = SPFW_Settings::group( 'hardening' );

		$this->assertGreaterThan( time(), (int) $h['csp_collect_until'] );
		$this->assertLessThanOrEqual(
			time() + SPFW_Rest_Settings::CSP_ENFORCE_WINDOW,
			(int) $h['csp_collect_until']
		);
	}

	/**
	 * The expiry event is scheduled with it, or the window closes on paper
	 * while cached pages keep advertising report-uri.
	 */
	public function test_enforcing_schedules_the_window_expiry() {
		global $spfw_test_cron;

		$this->enforce_transition(
			array( 'csp_report_only' => true ),
			array(
				'csp_report_only'   => false,
				'csp_enabled'       => true,
				'csp_collect_until' => 0,
			)
		);

		$this->assertArrayHasKey(
			SPFW_Module_Hardening::CSP_EXPIRE_CRON,
			$spfw_test_cron
		);
	}

	/**
	 * A window the admin already has open is theirs — never shortened by this.
	 */
	public function test_an_open_window_is_left_alone() {
		$existing = time() + 40000;

		SPFW_Settings::update( array( 'hardening' => array( 'csp_collect_until' => $existing ) ) );

		$this->enforce_transition(
			array( 'csp_report_only' => true ),
			array(
				'csp_report_only'   => false,
				'csp_enabled'       => true,
				'csp_collect_until' => $existing,
			)
		);

		$this->assertSame(
			$existing,
			(int) SPFW_Settings::value( 'hardening', 'csp_collect_until', 0 )
		);
	}

	/**
	 * Saves that are not this transition do nothing. A settings write that
	 * happens to touch the hardening group must not keep reopening a window
	 * the admin closed.
	 */
	public function test_unrelated_saves_open_nothing() {
		$this->enforce_transition(
			array( 'csp_report_only' => false ),
			array(
				'csp_report_only'   => false,
				'csp_enabled'       => true,
				'csp_collect_until' => 0,
			)
		);

		$this->assertSame(
			0,
			(int) SPFW_Settings::value( 'hardening', 'csp_collect_until', 0 )
		);
	}

	/**
	 * Turning Report-Only off while CSP itself is disabled changes nothing a
	 * visitor sees, so there is nothing to collect.
	 */
	public function test_nothing_opens_when_csp_is_disabled() {
		$this->enforce_transition(
			array( 'csp_report_only' => true ),
			array(
				'csp_report_only'   => false,
				'csp_enabled'       => false,
				'csp_collect_until' => 0,
			)
		);

		$this->assertSame(
			0,
			(int) SPFW_Settings::value( 'hardening', 'csp_collect_until', 0 )
		);
	}
}
