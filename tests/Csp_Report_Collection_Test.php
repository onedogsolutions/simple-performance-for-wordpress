<?php
/**
 * Coverage for the CSP violation-report collection path: the time-boxed
 * collection window, and the bounds that keep a public, unauthenticated write
 * endpoint from being expensive or abusable.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the CSP violation-collection window and report ingest bounds.
 */
class Csp_Report_Collection_Test extends TestCase {

	/**
	 * Reset option store, transients, cache, and the settings cache.
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
	 * Call a private static method on SPFW_Rest_Settings.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function call( $method, array $args = array() ) {
		$ref = new ReflectionMethod( 'SPFW_Rest_Settings', $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Store one violation through the ingest path.
	 *
	 * @param string $directive Directive name.
	 * @param string $uri       Blocked URI.
	 * @param array  $h         Hardening settings.
	 */
	private function report( $directive, $uri, array $h = array() ) {
		$this->call(
			'store_violations',
			array(
				array(
					array(
						'directive'    => $directive,
						'blocked_uri'  => $uri,
						'document_uri' => 'https://example.com/',
					),
				),
				$h,
			)
		);
	}

	/**
	 * Current stored entry map.
	 *
	 * @return array
	 */
	private function items() {
		$store = $this->call( 'read_store' );

		return $store['items'];
	}

	/**
	 * Force the coalescing window open so the next report persists.
	 */
	private function allow_write() {
		global $spfw_test_transients;

		$key = SPFW_Rest_Settings::CSP_REPORTS_KEY;

		if ( isset( $spfw_test_transients[ $key ]['meta']['last_write'] ) ) {
			$spfw_test_transients[ $key ]['meta']['last_write'] = time() - 3600;
		}
	}

	/**
	 * The collection window is what gates both the header and the endpoint.
	 */
	public function test_collection_window_open_and_closed() {
		$this->assertFalse(
			SPFW_Module_Hardening::collection_open( array( 'csp_collect_until' => 0 ) ),
			'A zero deadline means collection is closed.'
		);

		$this->assertFalse(
			SPFW_Module_Hardening::collection_open( array( 'csp_collect_until' => time() - 60 ) ),
			'An elapsed deadline means collection has closed itself.'
		);

		$this->assertTrue(
			SPFW_Module_Hardening::collection_open( array( 'csp_collect_until' => time() + 3600 ) )
		);

		$this->assertFalse(
			SPFW_Module_Hardening::collection_open( array() ),
			'Settings written before this feature existed must read as closed.'
		);
	}

	/**
	 * A stored or imported deadline can never leave collection open forever.
	 */
	public function test_collection_window_is_capped_and_sample_clamped() {
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_collect_until'  => time() + ( 400 * DAY_IN_SECONDS ),
					'csp_collect_sample' => 5000,
				),
			)
		);

		$h = SPFW_Settings::group( 'hardening' );

		$this->assertLessThanOrEqual( time() + SPFW_Settings::CSP_COLLECT_MAX, $h['csp_collect_until'] );
		$this->assertSame( 100, $h['csp_collect_sample'] );

		SPFW_Settings::update( array( 'hardening' => array( 'csp_collect_sample' => 0 ) ) );
		$this->assertSame( 1, SPFW_Settings::group( 'hardening' )['csp_collect_sample'] );
	}

	/**
	 * The endpoint is unauthenticated by necessity, so a flood of invented
	 * origins must not be able to fill the log.
	 */
	public function test_new_origins_are_rate_limited_per_minute() {
		for ( $i = 0; $i < 40; $i++ ) {
			$this->report( 'script-src', "https://flood-$i.example/x.js" );
		}

		$this->assertCount(
			SPFW_Rest_Settings::CSP_NEW_PER_MINUTE,
			$this->items(),
			'No more than CSP_NEW_PER_MINUTE new origins may enter the log in one minute.'
		);
	}

	/**
	 * Eviction must protect the violations that actually matter. Evicting the
	 * least-recently-seen entry (the old behavior) let a burst of one-off
	 * reports push out the established, high-count ones.
	 */
	public function test_eviction_drops_the_least_reported_entry() {
		$items = array(
			'script-src|https://busy.example'  => array(
				'count'     => 900,
				'last_seen' => time() - 600,
			),
			'script-src|https://rare.example'  => array(
				'count'     => 1,
				'last_seen' => time(),
			),
			'script-src|https://other.example' => array(
				'count'     => 40,
				'last_seen' => time(),
			),
		);

		$ref = new ReflectionMethod( 'SPFW_Rest_Settings', 'evict_one' );
		$ref->setAccessible( true );
		$ref->invokeArgs( null, array( &$items ) );

		$this->assertArrayHasKey( 'script-src|https://busy.example', $items );
		$this->assertArrayNotHasKey(
			'script-src|https://rare.example',
			$items,
			'The least-reported entry is the one that should go, even though it was seen most recently.'
		);
	}

	/**
	 * An origin the policy already permits is a stale report (a visitor on a
	 * cached page), not an outstanding problem — re-listing it would undo the
	 * admin's "Allow" on the next poll.
	 */
	public function test_already_allowed_origins_are_not_relisted() {
		$h = array(
			'csp_mode'       => 'builder',
			'csp_directives' => array(
				'script-src' => array( "'self'", 'https://www.googletagmanager.com' ),
			),
		);

		$this->report( 'script-src', 'https://www.googletagmanager.com/gtm.js', $h );
		$this->assertSame( array(), $this->items() );

		$this->report( 'script-src', 'https://not-yet-allowed.example/x.js', $h );
		$this->assertArrayHasKey( 'script-src|https://not-yet-allowed.example', $this->items() );
	}

	/**
	 * Keyword blocks ('inline', 'data') map onto the real CSP token the admin's
	 * Allow writes, so they must be recognised as allowed too.
	 */
	public function test_already_allowed_understands_keyword_tokens() {
		$h = array(
			'csp_mode'       => 'builder',
			'csp_directives' => array(
				'style-src' => array( "'self'", "'unsafe-inline'" ),
			),
		);

		$this->report( 'style-src', '', $h );

		$this->assertSame(
			array(),
			$this->items(),
			"A blocked inline style is already covered by 'unsafe-inline'."
		);
	}

	/**
	 * Raw-policy mode has no structured directive map to consult, so it must
	 * fall through rather than guess.
	 */
	public function test_custom_mode_does_not_suppress() {
		$h = array(
			'csp_mode'       => 'custom',
			'csp_directives' => array(
				'script-src' => array( 'https://www.googletagmanager.com' ),
			),
		);

		$this->report( 'script-src', 'https://www.googletagmanager.com/gtm.js', $h );

		$this->assertArrayHasKey( 'script-src|https://www.googletagmanager.com', $this->items() );
	}

	/**
	 * Repeat sightings coalesce: a counter bump is not worth an option write on
	 * every request from every visitor.
	 */
	public function test_repeat_sightings_coalesce_then_resume() {
		$uri = 'https://www.googletagmanager.com/gtm.js';

		$this->report( 'script-src', $uri );
		$items = $this->items();
		$this->assertSame( 1, $items['script-src|https://www.googletagmanager.com']['count'] );

		// Immediately repeated: inside the coalescing interval, not persisted.
		for ( $i = 0; $i < 25; $i++ ) {
			$this->report( 'script-src', $uri );
		}

		$items = $this->items();
		$this->assertSame(
			1,
			$items['script-src|https://www.googletagmanager.com']['count'],
			'Repeat reports inside the coalescing interval must not each cost a write.'
		);

		// Once the interval has elapsed, counting resumes.
		$this->allow_write();
		$this->report( 'script-src', $uri );

		$items = $this->items();
		$this->assertSame( 2, $items['script-src|https://www.googletagmanager.com']['count'] );
	}

	/**
	 * A newly-seen violation is the information the admin is waiting for, so it
	 * is always persisted immediately regardless of coalescing.
	 */
	public function test_new_violations_persist_immediately() {
		$this->report( 'script-src', 'https://a.example/x.js' );
		$this->report( 'img-src', 'https://b.example/y.png' );

		$items = $this->items();

		$this->assertArrayHasKey( 'script-src|https://a.example', $items );
		$this->assertArrayHasKey( 'img-src|https://b.example', $items );
	}

	/**
	 * Logs written before 2.1.0 stored the entry map with no { items, meta }
	 * envelope. They must still be readable, not silently dropped.
	 */
	public function test_legacy_store_shape_is_read() {
		global $spfw_test_transients;

		$spfw_test_transients[ SPFW_Rest_Settings::CSP_REPORTS_KEY ] = array(
			'script-src|https://legacy.example' => array(
				'directive'      => 'script-src',
				'blocked_uri'    => 'https://legacy.example/x.js',
				'blocked_origin' => 'https://legacy.example',
				'document_uri'   => 'https://example.com/',
				'count'          => 7,
				'first_seen'     => time() - 100,
				'last_seen'      => time() - 10,
			),
		);

		$reports = SPFW_Rest_Settings::get_csp_reports();

		$this->assertCount( 1, $reports );
		$this->assertSame( 'https://legacy.example', $reports[0]['blocked_origin'] );
		$this->assertSame( 7, $reports[0]['count'] );
	}

	/**
	 * The admin list is ordered by how much each violation actually happens,
	 * matching the eviction policy.
	 */
	public function test_reports_are_ordered_by_count() {
		global $spfw_test_transients;

		$spfw_test_transients[ SPFW_Rest_Settings::CSP_REPORTS_KEY ] = array(
			'items' => array(
				'script-src|https://rare.example' => array(
					'blocked_origin' => 'https://rare.example',
					'count'          => 2,
					'last_seen'      => time(),
				),
				'script-src|https://busy.example' => array(
					'blocked_origin' => 'https://busy.example',
					'count'          => 500,
					'last_seen'      => time() - 300,
				),
			),
			'meta'  => array(),
		);

		$reports = SPFW_Rest_Settings::get_csp_reports();

		$this->assertSame( 'https://busy.example', $reports[0]['blocked_origin'] );
	}
}
