<?php
/**
 * Coverage for the portability of the localized-font stylesheet.
 *
 * The bug this file exists to prevent: font URLs were frozen into the stored
 * @font-face CSS at scan time as absolute, fully-qualified URLs. Cloning a site
 * to another domain — production to staging — therefore left every font URL
 * pointing at the original host. Fonts referenced from CSS are always fetched
 * in CORS mode (not configurable from CSS, and unaffected by CSP), so a
 * cross-origin .woff2 served without Access-Control-Allow-Origin is fetched
 * successfully and then discarded by the browser: `blocked by CORS policy`
 * alongside the contradictory-looking `ERR_FAILED 200 (OK)`.
 *
 * The fix stores a hostname-free token and expands it only on the way to disk,
 * to a root-relative path when uploads are on the site's own host. What the
 * tests below pin is not just that the expansion works, but the three ways it
 * can be quietly bypassed:
 *
 * - the preload tags, which are built separately from the stylesheet and would
 *   reintroduce the original bug in <head> while fonts.css itself looked right;
 * - the upgrade migration, whose version gate decides whether any existing
 *   install is ever healed at all;
 * - the scan's own loopback, which used to dequeue the very stylesheets it was
 *   scanning for.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for token-based font CSS storage, rendering, self-heal and migration.
 */
class Fonts_Portability_Test extends TestCase {

	/**
	 * Reset every recorded stub and the settings cache before each test.
	 */
	protected function setUp(): void {
		global $spfw_test_options, $spfw_test_home_url, $spfw_test_transients;
		global $spfw_test_actions_fired, $spfw_test_hooks, $spfw_test_filters;
		global $spfw_test_style_calls, $spfw_test_enqueued_styles, $spfw_test_styles, $spfw_test_http;

		$spfw_test_options         = array();
		$spfw_test_home_url        = 'http://example.com';
		$spfw_test_transients      = array();
		$spfw_test_actions_fired   = array();
		$spfw_test_hooks           = array();
		$spfw_test_filters         = array();
		$spfw_test_style_calls     = array();
		$spfw_test_enqueued_styles = array();
		$spfw_test_styles          = null;
		$spfw_test_http            = array();

		$this->reset_settings_cache();
		$this->clear_fonts_dir();
	}

	/**
	 * Remove the generated fonts directory so each test starts from a known
	 * on-disk state rather than inheriting the previous test's artifacts.
	 */
	protected function tearDown(): void {
		$this->clear_fonts_dir();
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/**
	 * Clear SPFW_Settings' static cache between tests.
	 */
	private function reset_settings_cache() {
		$prop = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Call a private/protected method on the real module.
	 *
	 * These are private for good reason — they are not API — but testing the
	 * real method is the point: a reimplementation in the test would pass while
	 * the shipped regex did something else.
	 *
	 * @param SPFW_Module_Fonts $module Module instance.
	 * @param string            $name   Method name.
	 * @param array             $args   Arguments.
	 * @return mixed
	 */
	private function call( $module, $name, array $args = array() ) {
		$method = new ReflectionMethod( 'SPFW_Module_Fonts', $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $module, $args );
	}

	/**
	 * Absolute path of the generated fonts directory.
	 *
	 * @return string
	 */
	private function fonts_dir() {
		$uploads = wp_upload_dir();

		return untrailingslashit( $uploads['basedir'] ) . '/ods-fonts';
	}

	/**
	 * Delete the generated fonts directory and everything in it.
	 */
	private function clear_fonts_dir() {
		$dir = $this->fonts_dir();

		// The unwritable-uploads test puts a regular FILE where the directory
		// belongs. If that test fails before its own cleanup runs, the file
		// survives and every later test silently fails to write — a poisoned
		// run that looks like a code regression. Clear that case here so the
		// suite cannot corrupt itself.
		if ( is_file( $dir ) ) {
			unlink( $dir );
			return;
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		rmdir( $dir );
	}

	/**
	 * A minimal two-face stylesheet with absolute URLs on the given host —
	 * the shape a pre-fix scan persisted.
	 *
	 * @param string $host Scheme and host, no trailing slash.
	 * @return string
	 */
	private function absolute_css( $host = 'http://example.com' ) {
		return "@font-face{font-family:'Open Sans';font-weight:400;"
			. "src:url({$host}/wp-content/uploads/ods-fonts/aaa.woff2) format('woff2');}\n"
			. "@font-face{font-family:'Open Sans';font-weight:700;"
			. "src:url({$host}/wp-content/uploads/ods-fonts/bbb.woff2) format('woff2');}\n";
	}

	/**
	 * Seed stored settings with localized fonts already discovered.
	 *
	 * @param string $css          Stored @font-face CSS.
	 * @param string $rendered_for Base the on-disk file was last rendered for.
	 * @param string $version      Stored plugin version.
	 */
	private function seed_fonts( $css, $rendered_for = '', $version = SPFW_VERSION ) {
		update_option(
			'spfw_settings',
			array(
				'version' => $version,
				'fonts'   => array(
					'localize_google' => true,
					'discovered'      => array(
						'css'      => $css,
						'hash'     => sha1( $css ),
						'families' => array( 'Open Sans:400', 'Open Sans:700' ),
						'files'    => array( 'aaa.woff2', 'bbb.woff2' ),
					),
					'rendered_for'    => $rendered_for,
				),
			)
		);

		$this->reset_settings_cache();
	}

	// -----------------------------------------------------------------------
	// Tokenizing and rendering.
	// -----------------------------------------------------------------------

	/**
	 * Absolute URLs fold back to the token, whatever host they carry.
	 */
	public function test_portable_css_replaces_absolute_urls_with_token() {
		$module = new SPFW_Module_Fonts();
		$out    = $this->call( $module, 'portable_css', array( $this->absolute_css( 'https://prod.example' ) ) );

		$this->assertStringNotContainsString(
			'prod.example',
			$out,
			'Stored CSS must never carry a hostname — that is what strands font URLs on the old origin after a domain move.'
		);
		$this->assertStringContainsString( '%%SPFW_FONTS_URL%%/aaa.woff2', $out );
		$this->assertStringContainsString( '%%SPFW_FONTS_URL%%/bbb.woff2', $out );
	}

	/**
	 * Tokenizing is idempotent, so CSS of either vintage can be pushed through
	 * write_css_file() without special-casing which one it is.
	 */
	public function test_portable_css_is_idempotent() {
		$module = new SPFW_Module_Fonts();

		$once  = $this->call( $module, 'portable_css', array( $this->absolute_css() ) );
		$twice = $this->call( $module, 'portable_css', array( $once ) );

		$this->assertSame( $once, $twice );
	}

	/**
	 * Same-host uploads render root-relative. This is the property that makes
	 * the whole class of bug impossible to recur: a root-relative path cannot
	 * name the wrong origin.
	 */
	public function test_render_css_emits_root_relative_urls_when_same_host() {
		$module = new SPFW_Module_Fonts();

		$tokenized = $this->call( $module, 'portable_css', array( $this->absolute_css() ) );
		$rendered  = $this->call( $module, 'render_css', array( $tokenized ) );

		$this->assertStringContainsString( 'url(/wp-content/uploads/ods-fonts/aaa.woff2)', $rendered );
		$this->assertStringNotContainsString( 'http://example.com', $rendered );
	}

	/**
	 * Uploads on a different host must stay absolute — root-relative would
	 * resolve against the site, which is the wrong server.
	 */
	public function test_render_css_keeps_absolute_urls_when_uploads_are_cross_host() {
		global $spfw_test_home_url;
		$spfw_test_home_url = 'https://site.example';

		$module = new SPFW_Module_Fonts();

		$tokenized = $this->call( $module, 'portable_css', array( $this->absolute_css() ) );
		$rendered  = $this->call( $module, 'render_css', array( $tokenized ) );

		// wp_upload_dir() reports example.com; the site is site.example.
		$this->assertStringContainsString( 'url(http://example.com/wp-content/uploads/ods-fonts/aaa.woff2)', $rendered );

		$runtime = $module->runtime_info();
		$this->assertFalse( $runtime['same_origin'], 'A cross-host uploads base must be reported as cross-origin so the Fonts tab can warn.' );
	}

	/**
	 * A domain move must not churn the stylesheet's cache-busting version.
	 * The hash describes the font set, not the site it is served from.
	 */
	public function test_hash_of_tokenized_css_is_identical_across_a_domain_move() {
		$module = new SPFW_Module_Fonts();

		$prod    = $this->call( $module, 'portable_css', array( $this->absolute_css( 'https://prod.example' ) ) );
		$staging = $this->call( $module, 'portable_css', array( $this->absolute_css( 'https://staging.prod.example' ) ) );

		$this->assertSame(
			sha1( $prod ),
			sha1( $staging ),
			'The same font set on two domains must hash identically, or every clone would bust its own font cache forever.'
		);
	}

	/**
	 * The rewrite is anchored on the fonts directory, so a family name,
	 * local() alias or unicode-range that happens to contain the substring is
	 * left alone.
	 */
	public function test_tokenizer_does_not_corrupt_unrelated_css_containing_the_substring() {
		$module = new SPFW_Module_Fonts();

		$css = "@font-face{font-family:'ods-fonts display';"
			. "src:local('ods-fonts'),url(http://example.com/wp-content/uploads/ods-fonts/aaa.woff2) format('woff2');"
			. "unicode-range:U+0000-00FF;}";

		$out = $this->call( $module, 'portable_css', array( $css ) );

		$this->assertStringContainsString( "font-family:'ods-fonts display'", $out );
		$this->assertStringContainsString( "local('ods-fonts')", $out );
		$this->assertStringContainsString( 'unicode-range:U+0000-00FF', $out );
		$this->assertStringContainsString( 'url(%%SPFW_FONTS_URL%%/aaa.woff2)', $out );
	}

	// -----------------------------------------------------------------------
	// Self-heal.
	// -----------------------------------------------------------------------

	/**
	 * The reported staging state, end to end: a cloned site whose stored CSS
	 * and on-disk stylesheet both carry the production host. The first
	 * front-end request must notice and regenerate, with no admin action.
	 */
	public function test_serve_local_fonts_regenerates_when_rendered_for_does_not_match() {
		global $spfw_test_actions_fired;

		$module = new SPFW_Module_Fonts();

		// Stored CSS still absolute-on-production, and the on-disk file was
		// rendered for production too.
		$this->seed_fonts( $this->absolute_css( 'https://prod.example' ), 'https://prod.example/wp-content/uploads/ods-fonts' );

		$module->serve_local_fonts();

		$on_disk = file_get_contents( $this->fonts_dir() . '/fonts.css' );

		$this->assertStringNotContainsString(
			'prod.example',
			$on_disk,
			'A cloned site must not keep serving the original domain\'s font URLs.'
		);
		$this->assertStringContainsString( 'url(/wp-content/uploads/ods-fonts/aaa.woff2)', $on_disk );

		$this->assertSame(
			'/wp-content/uploads/ods-fonts',
			SPFW_Settings::value( 'fonts', 'rendered_for', '' ),
			'rendered_for must record the base actually written, or the heal repeats on every request.'
		);
	}

	/**
	 * Purging the page cache alone is what let this bug survive a re-scan on
	 * the reporting site: QUIC.cloud UCSS/CCSS and the CSS/JS combine cache are
	 * separate artifacts holding their own copy of the font URLs. The page
	 * purge goes last so it settles after the rest.
	 */
	public function test_self_heal_purges_generated_css_before_the_page_cache() {
		global $spfw_test_actions_fired;

		$module = new SPFW_Module_Fonts();
		$this->seed_fonts( $this->absolute_css( 'https://prod.example' ), 'https://prod.example/wp-content/uploads/ods-fonts' );

		$module->serve_local_fonts();

		foreach ( array( 'litespeed_purge_all_ucss', 'litespeed_purge_all_ccss', 'litespeed_purge_all_cssjs', 'litespeed_purge_all' ) as $hook ) {
			$this->assertContains( $hook, $spfw_test_actions_fired, "Regenerating fonts.css must purge {$hook}." );
		}

		$ucss = array_search( 'litespeed_purge_all_ucss', $spfw_test_actions_fired, true );
		$page = array_search( 'litespeed_purge_all', $spfw_test_actions_fired, true );

		$this->assertLessThan( $page, $ucss, 'The page cache must be purged last, after the derived CSS artifacts.' );
	}

	/**
	 * Once healed, a steady-state request must not rewrite, re-purge or write
	 * an option — this runs on every front-end page load.
	 */
	public function test_second_request_does_no_work() {
		global $spfw_test_actions_fired;

		$module = new SPFW_Module_Fonts();
		$this->seed_fonts( $this->absolute_css( 'https://prod.example' ), 'https://prod.example/wp-content/uploads/ods-fonts' );

		$module->serve_local_fonts();
		$spfw_test_actions_fired = array();

		$module->serve_local_fonts();

		$this->assertSame(
			array(),
			$spfw_test_actions_fired,
			'A steady-state front-end request must not purge caches — that would re-run on every page load.'
		);
	}

	/**
	 * The stylesheet is swapped for the local copy and the Google one is
	 * dequeued (never deregistered — see Asset_Dequeue_Test for why).
	 */
	public function test_google_stylesheet_is_dequeued_and_local_one_enqueued() {
		global $spfw_test_style_calls, $spfw_test_enqueued_styles;

		$styles                          = wp_styles();
		$styles->registered['theme-fonts'] = (object) array( 'src' => 'https://fonts.googleapis.com/css2?family=Open+Sans' );
		$styles->registered['theme-main']  = (object) array( 'src' => 'https://example.com/style.css' );

		$module = new SPFW_Module_Fonts();
		$this->seed_fonts( $this->absolute_css() );

		$module->serve_local_fonts();

		$this->assertSame( array( array( 'dequeue', 'theme-fonts' ) ), $spfw_test_style_calls );
		$this->assertSame( 'spfw-fonts', $spfw_test_enqueued_styles[0]['handle'] );
	}

	/**
	 * An unwritable uploads directory must leave the original Google enqueue
	 * alone. Serving no fonts at all is worse than serving them from Google.
	 */
	public function test_unwritable_uploads_leaves_google_enqueue_untouched() {
		global $spfw_test_style_calls, $spfw_test_enqueued_styles;

		$styles                            = wp_styles();
		$styles->registered['theme-fonts'] = (object) array( 'src' => 'https://fonts.googleapis.com/css2?family=Open+Sans' );

		// A regular file where the fonts directory should be makes every write fail.
		$dir = $this->fonts_dir();
		if ( ! is_dir( dirname( $dir ) ) ) {
			mkdir( dirname( $dir ), 0755, true );
		}
		file_put_contents( $dir, 'not a directory' );

		$module = new SPFW_Module_Fonts();
		$this->seed_fonts( $this->absolute_css() );

		$module->serve_local_fonts();

		$this->assertSame( array(), $spfw_test_style_calls, 'The Google stylesheet must survive when the local copy cannot be written.' );
		$this->assertSame( array(), $spfw_test_enqueued_styles );

		unlink( $dir );
	}

	/**
	 * The CORS rules land next to the stylesheet, guarded so a server without
	 * mod_headers ignores them rather than returning 500 for the whole
	 * uploads tree.
	 */
	public function test_cors_htaccess_is_written_alongside_the_stylesheet() {
		$module = new SPFW_Module_Fonts();
		$this->seed_fonts( $this->absolute_css() );

		$module->serve_local_fonts();

		$path = $this->fonts_dir() . '/.htaccess';
		$this->assertFileExists( $path );

		$payload = file_get_contents( $path );
		$this->assertStringContainsString( 'Access-Control-Allow-Origin', $payload );
		$this->assertStringContainsString( '<IfModule mod_headers.c>', $payload );
		$this->assertStringContainsString( 'Vary Origin', $payload );
	}

	// -----------------------------------------------------------------------
	// Preload parity.
	// -----------------------------------------------------------------------

	/**
	 * The regression this test exists for: preload tags are built separately
	 * from the stylesheet, and `rel="preload" as="font"` is fetched in CORS
	 * mode. Building the href from the absolute uploads URL would put the old
	 * origin back into <head> on a moved site — the original bug, through a
	 * path the portability fix does not touch, while fonts.css looked correct.
	 */
	public function test_preload_href_matches_the_stylesheet_base_after_a_domain_move() {
		$module = new SPFW_Module_Fonts();
		$this->seed_fonts( $this->absolute_css( 'https://prod.example' ), 'https://prod.example/wp-content/uploads/ods-fonts' );

		$module->serve_local_fonts();
		$on_disk = file_get_contents( $this->fonts_dir() . '/fonts.css' );

		ob_start();
		$module->preload_local_fonts();
		$head = ob_get_clean();

		$this->assertStringNotContainsString(
			'prod.example',
			$head,
			'Preload hrefs must not name the old origin — a cross-origin font preload fails CORS exactly like the stylesheet reference does.'
		);
		$this->assertStringContainsString( 'href="/wp-content/uploads/ods-fonts/aaa.woff2"', $head );
		$this->assertStringContainsString( 'url(/wp-content/uploads/ods-fonts/aaa.woff2)', $on_disk );
	}

	/**
	 * Preload and stylesheet must name the same URL, or the browser fetches
	 * every font twice and logs "preloaded but not used".
	 */
	public function test_preload_and_stylesheet_agree_when_uploads_are_cross_host() {
		global $spfw_test_home_url;
		$spfw_test_home_url = 'https://site.example';

		$module = new SPFW_Module_Fonts();
		$this->seed_fonts( $this->absolute_css() );

		$module->serve_local_fonts();
		$on_disk = file_get_contents( $this->fonts_dir() . '/fonts.css' );

		ob_start();
		$module->preload_local_fonts();
		$head = ob_get_clean();

		$this->assertStringContainsString( 'href="http://example.com/wp-content/uploads/ods-fonts/aaa.woff2"', $head );
		$this->assertStringContainsString( 'url(http://example.com/wp-content/uploads/ods-fonts/aaa.woff2)', $on_disk );
	}

	// -----------------------------------------------------------------------
	// register(): what the module attaches, per request type.
	// -----------------------------------------------------------------------

	/**
	 * A normal front-end request localizes, strips the Google resource hints,
	 * and preloads.
	 */
	public function test_normal_request_attaches_all_three_front_end_hooks() {
		global $spfw_test_hooks, $spfw_test_filters;

		$this->seed_fonts( $this->absolute_css() );

		$module = new SPFW_Module_Fonts();
		$module->register();

		$tags = array_column( $spfw_test_hooks, 'tag' );

		$this->assertContains( 'wp_enqueue_scripts', $tags );
		$this->assertContains( 'wp_head', $tags );
		$this->assertContains( 'wp_resource_hints', array_column( $spfw_test_filters, 'tag' ) );
	}

	/**
	 * The 1.15.0 root cause: during the scan's own loopback the module used to
	 * dequeue the very Google stylesheets discovery captures on, so a re-scan
	 * could only ever re-find fonts it had NOT already localized. The font set
	 * froze at whatever the first scan caught.
	 *
	 * On an authorized loopback none of the three front-end hooks may attach,
	 * and the capture filter must.
	 */
	public function test_authorized_scan_loopback_stands_down_and_captures() {
		global $spfw_test_hooks, $spfw_test_filters;

		$this->seed_fonts( $this->absolute_css() );

		set_transient( 'spfw_font_scan_token', 'valid-token', 120 );
		$_GET['spfw_font_scan'] = 'valid-token';

		$module = new SPFW_Module_Fonts();
		$module->register();

		$action_tags = array_column( $spfw_test_hooks, 'tag' );
		$filter_tags = array_column( $spfw_test_filters, 'tag' );

		$this->assertNotContains( 'wp_enqueue_scripts', $action_tags, 'A scan that dequeues the Google stylesheets cannot see them.' );
		$this->assertNotContains( 'wp_head', $action_tags, 'Preload tags are waste in a throwaway loopback render.' );
		$this->assertNotContains( 'wp_resource_hints', $filter_tags );
		$this->assertContains( 'style_loader_src', $filter_tags, 'The loopback must capture the stylesheet srcs it is there to find.' );

		unset( $_GET['spfw_font_scan'] );
	}

	/**
	 * A forged or stale token must not be a way to switch localization off for
	 * a normal visitor.
	 */
	public function test_forged_scan_token_leaves_localization_active() {
		global $spfw_test_hooks, $spfw_test_filters;

		$this->seed_fonts( $this->absolute_css() );

		set_transient( 'spfw_font_scan_token', 'the-real-token', 120 );
		$_GET['spfw_font_scan'] = 'forged-token';

		$module = new SPFW_Module_Fonts();
		$module->register();

		$action_tags = array_column( $spfw_test_hooks, 'tag' );
		$filter_tags = array_column( $spfw_test_filters, 'tag' );

		$this->assertContains( 'wp_enqueue_scripts', $action_tags, 'A forged token must not disable localization.' );
		$this->assertNotContains( 'style_loader_src', $filter_tags, 'A forged token must not capture anything.' );

		unset( $_GET['spfw_font_scan'] );
	}

	// -----------------------------------------------------------------------
	// A fresh scan stores the token, not a hostname.
	// -----------------------------------------------------------------------

	/**
	 * The other half of the portability contract, and the half no other test
	 * here covers: write_css_file() normalizes on the way to disk, so a scan
	 * that regressed to baking absolute URLs into `discovered['css']` would
	 * still produce a correct fonts.css and pass every self-heal test above.
	 * What it would break is silent — the stored hash would then differ
	 * between production and staging, so a clone would bust its font cache
	 * and re-render on every deploy.
	 */
	public function test_a_fresh_scan_stores_tokenized_css_and_a_domain_agnostic_hash() {
		global $spfw_test_http;

		$google_css = "@font-face{font-family:'Open Sans';font-style:normal;font-weight:400;"
			. "src:url(https://fonts.gstatic.com/s/opensans/v1/abc.woff2) format('woff2');}";

		$spfw_test_http = array(
			// The loopback render of the site's own homepage.
			'example.com/?'                  => array(
				'code' => 200,
				'body' => '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400">',
			),
			'fonts.googleapis.com'           => array(
				'code' => 200,
				'body' => $google_css,
			),
			'fonts.gstatic.com/s/opensans'   => array(
				'code' => 200,
				'body' => 'WOFF2BINARY',
			),
		);

		$module = new SPFW_Module_Fonts();
		$result = $module->scan();

		$this->assertIsArray( $result, 'The scan should succeed against a reachable homepage and Google.' );

		$fonts = SPFW_Settings::group( 'fonts' );

		$this->assertNotEmpty( $fonts['discovered']['css'] );
		$this->assertStringContainsString(
			SPFW_Module_Fonts::FONTS_URL_TOKEN,
			$fonts['discovered']['css'],
			'A fresh scan must persist the portable token, never a hostname.'
		);
		$this->assertStringNotContainsString( 'example.com', $fonts['discovered']['css'] );
		$this->assertStringNotContainsString( 'fonts.gstatic.com', $fonts['discovered']['css'] );

		$this->assertSame(
			'/wp-content/uploads/ods-fonts',
			$fonts['rendered_for'],
			'The scan must record the base it rendered the file against, or the next request re-heals needlessly.'
		);

		$on_disk = file_get_contents( $this->fonts_dir() . '/fonts.css' );
		$this->assertStringContainsString( 'url(/wp-content/uploads/ods-fonts/', $on_disk );
	}

	// -----------------------------------------------------------------------
	// Migration.
	// -----------------------------------------------------------------------

	/**
	 * An install upgrading from a pre-2.14.0 version has its stored CSS
	 * tokenized in place, so it is healed without a re-scan.
	 *
	 * The gate matters more than it looks. This fix was originally written on
	 * a branch that forked before 2.x and gated on `< 1.13.0`; against any
	 * install that will actually run this code that comparison is false, so
	 * the migration would never fire — passing a fresh-install test while
	 * healing nothing in the field.
	 */
	public function test_upgrade_from_2_13_0_tokenizes_stored_css() {
		$this->seed_fonts( $this->absolute_css( 'https://prod.example' ), 'https://prod.example/wp-content/uploads/ods-fonts', '2.13.0' );

		$fonts = SPFW_Settings::group( 'fonts' );

		$this->assertStringNotContainsString( 'prod.example', $fonts['discovered']['css'] );
		$this->assertStringContainsString( '%%SPFW_FONTS_URL%%/aaa.woff2', $fonts['discovered']['css'] );
		$this->assertSame( '', $fonts['rendered_for'], 'Clearing rendered_for is what makes the next front-end request regenerate the file.' );
	}

	/**
	 * The hash is recomputed once on upgrade — that is the cache bust which
	 * evicts a browser- or LiteSpeed-held copy still carrying absolute URLs.
	 */
	public function test_migration_recomputes_the_hash_over_tokenized_css() {
		$css = $this->absolute_css( 'https://prod.example' );
		$this->seed_fonts( $css, '', '2.13.0' );

		$fonts = SPFW_Settings::group( 'fonts' );

		$this->assertNotSame( sha1( $css ), $fonts['discovered']['hash'] );
		$this->assertSame( sha1( $fonts['discovered']['css'] ), $fonts['discovered']['hash'] );
	}

	/**
	 * An install already at the target version is left alone.
	 */
	public function test_install_at_target_version_is_not_migrated() {
		$this->seed_fonts( $this->absolute_css( 'https://prod.example' ), 'https://prod.example/wp-content/uploads/ods-fonts', '2.14.0' );

		$fonts = SPFW_Settings::group( 'fonts' );

		$this->assertStringContainsString(
			'prod.example',
			$fonts['discovered']['css'],
			'A site already on the target version must not be re-migrated on every load.'
		);
	}

	/**
	 * An install with no discovered fonts has nothing to migrate and must not
	 * acquire an empty discovered array as a side effect.
	 */
	public function test_install_without_discovered_fonts_is_untouched() {
		update_option(
			'spfw_settings',
			array(
				'version' => '2.13.0',
				'fonts'   => array( 'localize_google' => false ),
			)
		);
		$this->reset_settings_cache();

		$fonts = SPFW_Settings::group( 'fonts' );

		$this->assertSame( array(), $fonts['discovered'] );
		$this->assertFalse( $fonts['localize_google'] );
	}

	// -----------------------------------------------------------------------
	// Scan report sanitization.
	// -----------------------------------------------------------------------

	/**
	 * The scan report is the one settings value partly derived from remote
	 * HTTP responses, and it re-enters sanitize() on every Save because it
	 * lives in a group the admin app round-trips. Unknown keys must not
	 * survive and counts must be coerced.
	 */
	public function test_scan_report_sanitization_drops_unknown_keys_and_coerces_counts() {
		// Through update(), not a raw option write: sanitize() runs on the way
		// in, and a Save from the admin app is exactly this path.
		SPFW_Settings::update(
			array(
				'fonts' => array(
					'last_scan_report' => array(
						'message'     => 'Scanned <b>3</b> pages',
						'time'        => '1700000000',
						'evil'        => 'should not survive',
						'diagnostics' => array(
							'captured'        => '7',
							'faces'           => -4,
							'manual_declared' => array( 'Open Sans:400,700' ),
							'targets'         => array(
								array(
									'url'   => 'https://example.com/',
									'ok'    => 1,
									'bytes' => '2048',
								),
							),
							'unexpected'      => array( 'nope' ),
						),
					),
				),
			)
		);

		$report = SPFW_Settings::value( 'fonts', 'last_scan_report', array() );

		$this->assertArrayNotHasKey( 'evil', $report );
		$this->assertArrayNotHasKey( 'unexpected', $report['diagnostics'] );
		$this->assertSame( 'Scanned 3 pages', $report['message'] );
		$this->assertSame( 1700000000, $report['time'] );
		$this->assertSame( 7, $report['diagnostics']['captured'] );
		$this->assertSame( 4, $report['diagnostics']['faces'] );
		$this->assertSame( 2048, $report['diagnostics']['targets'][0]['bytes'] );
		$this->assertTrue( $report['diagnostics']['targets'][0]['ok'] );
		$this->assertSame( array( 'Open Sans:400,700' ), $report['diagnostics']['manual_declared'] );
	}

	/**
	 * An absent report stays an empty array rather than becoming a populated
	 * skeleton the UI would render as a scan that never happened.
	 */
	public function test_absent_scan_report_stays_empty() {
		SPFW_Settings::update( array( 'fonts' => array( 'localize_google' => true ) ) );

		$this->assertSame( array(), SPFW_Settings::value( 'fonts', 'last_scan_report', 'unset' ) );
	}

	/**
	 * `rendered_for` survives a Save round trip. The admin app posts the whole
	 * fonts group back, so a key the front end writes has to come through
	 * sanitize() intact or every Save would undo the last self-heal.
	 */
	public function test_rendered_for_survives_a_save_round_trip() {
		SPFW_Settings::update( array( 'fonts' => array( 'rendered_for' => '/wp-content/uploads/ods-fonts' ) ) );

		$this->assertSame( '/wp-content/uploads/ods-fonts', SPFW_Settings::value( 'fonts', 'rendered_for', '' ) );
	}
}
