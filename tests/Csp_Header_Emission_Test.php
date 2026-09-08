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
}
