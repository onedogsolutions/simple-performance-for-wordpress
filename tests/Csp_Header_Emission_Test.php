<?php
/**
 * Coverage for which CSP header the plugin emits and what it carries.
 *
 * Two regressions are pinned here. (1) The admin could not tell an enforcing
 * policy from a report-only one, because the emitted-policy preview was a bare
 * string with no header name — so `csp_header_name()` is now the single source
 * both the header and the UI read. (2) `frame-ancestors` was emitted in
 * report-only policies, where browsers ignore it and log a console error on
 * every page load, which reads exactly like a broken policy.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for CSP header naming and report-only directive stripping.
 */
class Csp_Header_Emission_Test extends TestCase {

	/**
	 * Reset the option store and the settings cache between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_transients, $spfw_test_cache;
		$spfw_test_options    = array();
		$spfw_test_transients = array();
		$spfw_test_cache      = array();

		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );
	}

	/**
	 * Call a private static method on SPFW_Module_Hardening.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call( $method, array $args = array() ) {
		$ref = new ReflectionMethod( 'SPFW_Module_Hardening', $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Report-only mode names the report-only header.
	 */
	public function test_header_name_follows_report_only_toggle() {
		$this->assertSame(
			'Content-Security-Policy-Report-Only',
			SPFW_Module_Hardening::csp_header_name( array( 'csp_report_only' => true ) )
		);

		$this->assertSame(
			'Content-Security-Policy',
			SPFW_Module_Hardening::csp_header_name( array( 'csp_report_only' => false ) )
		);
	}

	/**
	 * A named directive is dropped and the rest of the policy survives intact.
	 */
	public function test_remove_directives_drops_only_the_named_directive() {
		$policy = "default-src 'self'; frame-ancestors 'self'; object-src 'none';";

		$this->assertSame(
			"default-src 'self'; object-src 'none';",
			$this->call( 'remove_directives', array( $policy, array( 'frame-ancestors' ) ) )
		);
	}

	/**
	 * A host that merely contains a directive name is not a directive.
	 */
	public function test_remove_directives_matches_the_directive_token_only() {
		$policy = "default-src 'self' https://frame-ancestors.example.com; object-src 'none';";

		$this->assertSame( $policy, $this->call( 'remove_directives', array( $policy, array( 'frame-ancestors' ) ) ) );
	}

	/**
	 * Removing every directive yields an empty string, not a stray semicolon.
	 */
	public function test_remove_directives_can_empty_a_policy() {
		$this->assertSame(
			'',
			$this->call( 'remove_directives', array( "frame-ancestors 'self';", array( 'frame-ancestors' ) ) )
		);
	}

	/**
	 * The preview the admin reads drops the directives the report-only header
	 * drops, so it matches what DevTools shows byte for byte.
	 */
	public function test_preview_strips_ignored_directives_in_report_only_mode() {
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_enabled'       => true,
					'csp_report_only'   => true,
					'csp_collect_until' => 0,
				),
			)
		);

		$policy = SPFW_Module_Hardening::get_emitted_policy_preview();

		$this->assertStringNotContainsString( 'frame-ancestors', $policy );
		$this->assertStringContainsString( "object-src 'none'", $policy );
	}

	/**
	 * A commerce policy's connect-src must survive a save intact.
	 *
	 * The cap used to be 15, which an ordinary WooCommerce install running
	 * Analytics, Tag Manager and Clarity reaches on its own — so the payment
	 * origins added afterwards were silently dropped and checkout broke on
	 * enforce with nothing in the UI to explain it.
	 */
	public function test_connect_src_survives_a_realistic_commerce_policy() {
		$origins = array( "'self'" );

		for ( $i = 0; $i < 20; $i++ ) {
			$origins[] = 'https://host' . $i . '.example.com';
		}

		$origins[] = 'https://api.stripe.com';
		$origins[] = 'https://*.paypal.com';

		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_directives' => array( 'connect-src' => $origins ),
				),
			)
		);

		$stored = SPFW_Settings::value( 'hardening', 'csp_directives' );

		$this->assertContains( 'https://api.stripe.com', $stored['connect-src'] );
		$this->assertContains( 'https://*.paypal.com', $stored['connect-src'] );
		$this->assertCount( 23, $stored['connect-src'] );
	}

	/**
	 * The cap still exists — it is a bound, not a suggestion.
	 */
	public function test_token_cap_is_still_enforced() {
		$origins = array();

		for ( $i = 0; $i < 60; $i++ ) {
			$origins[] = 'https://host' . $i . '.example.com';
		}

		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_directives' => array( 'connect-src' => $origins ),
				),
			)
		);

		$stored = SPFW_Settings::value( 'hardening', 'csp_directives' );

		$this->assertCount( SPFW_Settings::CSP_MAX_TOKENS, $stored['connect-src'] );
	}

	/**
	 * Wildcard host sources survive sanitization — the vendors' own guidance
	 * (PayPal in particular) is written in terms of them, and they are how a
	 * policy stays under the token cap.
	 */
	public function test_wildcard_origins_are_preserved() {
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_directives' => array(
						'connect-src' => array( 'https://*.paypal.com', 'https://*.stripe.com' ),
					),
				),
			)
		);

		$stored = SPFW_Settings::value( 'hardening', 'csp_directives' );

		$this->assertSame(
			array( 'https://*.paypal.com', 'https://*.stripe.com' ),
			$stored['connect-src']
		);
	}

	/**
	 * Enforcing mode keeps them — that is where they take effect.
	 */
	public function test_preview_keeps_ignored_directives_when_enforcing() {
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_enabled'       => true,
					'csp_report_only'   => false,
					'csp_collect_until' => 0,
				),
			)
		);

		$this->assertStringContainsString(
			'frame-ancestors',
			SPFW_Module_Hardening::get_emitted_policy_preview()
		);
	}

	/**
	 * script-src must allow blob:. LiteSpeed Cache's "Load JS Delayed"
	 * re-executes inline scripts through URL.createObjectURL(new Blob(...)),
	 * and blob: is a scheme of its own that the https: source does not cover —
	 * without it every delayed script is refused and the page loses jQuery.
	 */
	public function test_default_policy_allows_blob_scripts() {
		$directives = SPFW_Module_Hardening::default_csp_directives();

		$this->assertContains( 'blob:', $directives['script-src'] );

		// worker-src already carried blob: and must keep it.
		$this->assertContains( 'blob:', $directives['worker-src'] );
	}

	/**
	 * The builder is seeded from DEFAULT_CSP, so the two can never disagree
	 * about blob: — a regression in either direction fails here.
	 */
	public function test_default_policy_string_and_directive_map_agree_on_blob() {
		$this->assertStringContainsString(
			'blob:',
			SPFW_Module_Hardening::DEFAULT_CSP
		);

		$parsed = SPFW_Module_Hardening::parse_policy_to_directives(
			SPFW_Module_Hardening::DEFAULT_CSP
		);

		$this->assertSame(
			$parsed['script-src'],
			SPFW_Module_Hardening::default_csp_directives()['script-src']
		);
	}

	/**
	 * The probe must strip every source that permits a class of request
	 * without naming any of it.
	 *
	 * This is the whole fix in one assertion. The shipped default carries
	 * `https:` in script-src, connect-src, frame-src, img-src, style-src and
	 * font-src, so Stripe, an analytics tag and the site's own assets are all
	 * permitted by construction and the browser has nothing to report — which
	 * is why the violation log stayed empty however long the window ran. Under
	 * the probe only the sources the admin actually named survive.
	 */
	public function test_discovery_policy_strips_scheme_sources_and_unsafe_keywords() {
		$probe  = SPFW_Module_Hardening::discovery_policy( SPFW_Module_Hardening::DEFAULT_CSP );
		$parsed = SPFW_Module_Hardening::parse_policy_to_directives( $probe );

		$this->assertNotSame( '', $probe, 'The probe must still be a policy.' );

		foreach ( array( 'default-src', 'img-src', 'style-src', 'script-src', 'font-src', 'connect-src', 'frame-src' ) as $directive ) {
			$this->assertArrayHasKey( $directive, $parsed, 'The probe must keep every directive the saved policy has.' );
			$this->assertNotContains( 'https:', $parsed[ $directive ], $directive . ' must not permit a whole scheme.' );
			$this->assertNotContains( 'data:', $parsed[ $directive ], $directive . ' must not permit a whole scheme.' );
			$this->assertNotContains( 'blob:', $parsed[ $directive ], $directive . ' must not permit a whole scheme.' );
			$this->assertNotContains( "'unsafe-inline'", $parsed[ $directive ] );
			$this->assertNotContains( "'unsafe-eval'", $parsed[ $directive ] );
		}
	}

	/**
	 * What the admin named by hand survives — otherwise the probe reports
	 * resources that are already allowed, and the log fills with noise the
	 * admin has to dismiss one entry at a time.
	 */
	public function test_discovery_policy_keeps_self_hosts_and_wildcards() {
		$policy = "default-src 'self'; script-src 'self' https://js.stripe.com 'sha256-abc123' https:; connect-src 'self' https://*.stripe.com; img-src 'self' data:;";

		$parsed = SPFW_Module_Hardening::parse_policy_to_directives(
			SPFW_Module_Hardening::discovery_policy( $policy )
		);

		$this->assertContains( "'self'", $parsed['default-src'] );
		$this->assertContains( "'self'", $parsed['script-src'] );
		$this->assertContains( 'https://js.stripe.com', $parsed['script-src'] );
		$this->assertContains( "'sha256-abc123'", $parsed['script-src'] );
		$this->assertNotContains( 'https:', $parsed['script-src'] );
		$this->assertContains( 'https://*.stripe.com', $parsed['connect-src'] );
		$this->assertSame( array( "'self'" ), $parsed['img-src'] );
	}

	/**
	 * A directive stripped to nothing becomes `'none'`, not a vanishing
	 * directive.
	 *
	 * build_policy_from_directives() skips empty token lists, so letting an
	 * emptied directive disappear would make it inherit default-src silently —
	 * an implicit fallback inside the one header whose purpose is to be read.
	 */
	public function test_discovery_policy_emits_none_when_everything_is_stripped() {
		$probe  = SPFW_Module_Hardening::discovery_policy( "default-src 'self'; media-src https:; object-src 'none';" );
		$parsed = SPFW_Module_Hardening::parse_policy_to_directives( $probe );

		$this->assertArrayHasKey( 'media-src', $parsed, 'An emptied directive must not disappear.' );
		$this->assertSame( array( "'none'" ), $parsed['media-src'] );
		$this->assertSame( array( "'none'" ), $parsed['object-src'] );
	}

	/**
	 * Discovery needs three things at once: CSP on, the setting on, and a
	 * window open. Any one missing leaves the header exactly as it was.
	 */
	public function test_discovery_is_inert_outside_a_window() {
		$closed = array(
			'csp_enabled'        => true,
			'csp_discovery_mode' => true,
			'csp_collect_until'  => 0,
		);

		$this->assertFalse( SPFW_Module_Hardening::discovery_active( $closed ) );

		$open = array_merge( $closed, array( 'csp_collect_until' => time() + 3600 ) );

		$this->assertTrue( SPFW_Module_Hardening::discovery_active( $open ) );
		$this->assertFalse(
			SPFW_Module_Hardening::discovery_active( array_merge( $open, array( 'csp_discovery_mode' => false ) ) ),
			'Switching discovery off must leave the window alone but the probe unsent.'
		);
		$this->assertFalse(
			SPFW_Module_Hardening::discovery_active( array_merge( $open, array( 'csp_enabled' => false ) ) )
		);
	}

	/**
	 * `'strict-dynamic'` already makes browsers ignore every host and scheme
	 * source, so the hashes alone decide what runs. Narrowing further would add
	 * noise and reveal nothing, so discovery stands down.
	 */
	public function test_discovery_is_inert_under_strict_dynamic() {
		$this->assertFalse(
			SPFW_Module_Hardening::discovery_active(
				array(
					'csp_enabled'        => true,
					'csp_discovery_mode' => true,
					'csp_strict_dynamic' => true,
					'csp_collect_until'  => time() + 3600,
				)
			)
		);
	}

	/**
	 * While a window is open the probe preview appears and the saved-policy
	 * preview is left exactly as it was — the admin can compare the two, which
	 * is the point of sending both.
	 */
	public function test_preview_switches_to_the_probe_while_collecting() {
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_enabled'        => true,
					'csp_report_only'    => true,
					'csp_discovery_mode' => true,
					'csp_strict_dynamic' => false,
					'csp_collect_until'  => 0,
				),
			)
		);

		$this->assertSame(
			'',
			SPFW_Module_Hardening::get_discovery_policy_preview(),
			'No window, no probe.'
		);

		SPFW_Settings::update(
			array(
				'hardening' => array( 'csp_collect_until' => time() + 3600 ),
			)
		);

		$probe = SPFW_Module_Hardening::get_discovery_policy_preview();

		$this->assertNotSame( '', $probe );
		$this->assertStringContainsString( 'report-uri', $probe, 'The probe is what the browser reports against.' );
		$this->assertNotContains(
			'https:',
			SPFW_Module_Hardening::parse_policy_to_directives( $probe )['script-src']
		);

		// The saved policy's preview is unchanged, and does not claim to report:
		// report-uri rides on the probe only, so a duplicate would make every
		// blocked resource appear twice in the log.
		$saved = SPFW_Module_Hardening::get_emitted_policy_preview();

		$this->assertStringNotContainsString( 'report-uri', $saved );
		$this->assertStringContainsString( 'https:', $saved );
		$this->assertStringContainsString( "'unsafe-inline'", $saved );
	}
}
