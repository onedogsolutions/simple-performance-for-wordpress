<?php
/**
 * Module 4: Google Fonts localizer & discovery.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discovers Google Fonts referenced by the site — from the rendered HTML of a
 * sample of pages and from manual declarations — downloads the .woff2
 * files locally, and rewrites the frontend to serve them from
 * /uploads/ods-fonts/ instead of fonts.googleapis.com/fonts.gstatic.com.
 *
 * Discovery captures fonts from WordPress's own style pipeline while pages
 * render (the `style_loader_src` filter), not just by regex-scanning loopback
 * HTML — so it catches every enqueued Google Fonts stylesheet regardless of
 * protocol form (https/http/protocol-relative), API version (`css`/`css2`), or
 * the handle a theme happens to register it under. A broadened HTML/linked-CSS
 * pass runs alongside it to catch hard-coded `<link>`s, `@import`s, and CDN-
 * inlined `@font-face` blocks that don't flow through the enqueue system.
 *
 * Because OpenLiteSpeed + QUIC.cloud can serve an optimized, font-tag-stripped
 * copy of a page — hiding some weights from the scan — discovery is hardened
 * three ways: the loopback fetch sends cache-busting args and no-cache headers
 * to force a fresh render; the scan covers the homepage plus a sample of inner
 * templates (recent post/page) and admin-supplied URLs, not just the homepage;
 * and admins can declare families/weights manually, which are localized
 * directly from Google regardless of what the front end exposes.
 */
class SPFW_Module_Fonts implements SPFW_Module {

	/**
	 * Placeholder standing in for the local fonts directory inside the stored
	 * `discovered['css']`. The stored CSS therefore never contains a hostname,
	 * so it stays valid when the site moves domain (production → staging clone,
	 * http → https, www → apex). The token is expanded to a concrete base only
	 * when the CSS is written to disk — see render_css()/rendered_base().
	 *
	 * Keep in sync with the literal in SPFW_Settings' 1.13.0 portability
	 * migration, which runs before the module files are loaded and so cannot
	 * reference this constant.
	 */
	const FONTS_URL_TOKEN = '%%SPFW_FONTS_URL%%';

	/**
	 * Matches an absolute URL pointing into the local fonts directory, so
	 * previously-stored CSS can be folded back to the token form.
	 */
	const FONTS_URL_PATTERN = '#https?://[^\s"\'()]+/ods-fonts/#i';

	/**
	 * Guards the self-healing fonts.css rewrite so an unwritable filesystem
	 * cannot trigger a write attempt on every single front-end request.
	 */
	const REWRITE_LOCK_TRANSIENT = 'spfw_fonts_rewrite_lock';

	/**
	 * Lifetime (seconds) of the rewrite lock.
	 */
	const REWRITE_LOCK_TTL = 300;

	/**
	 * Transient holding the one-time token that authorizes enqueue capture
	 * during a scan's loopback request.
	 */
	const SCAN_TOKEN_TRANSIENT = 'spfw_font_scan_token';

	/**
	 * Transient the loopback render writes discovered Google CSS URLs into,
	 * read back by scan() in the originating request.
	 */
	const SCAN_URLS_TRANSIENT = 'spfw_font_scan_urls';

	/**
	 * Lifetime (seconds) of the scan token / captured-URL transients.
	 */
	const SCAN_TTL = 120;

	/**
	 * Cap on same-origin stylesheets fetched when following `@import`s.
	 */
	const MAX_LINKED_CSS = 10;

	/**
	 * Modern Chrome UA so Google returns .woff2 sources and the loopback
	 * request looks like a real browser.
	 */
	const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * Google Fonts stylesheet srcs captured during the current loopback
	 * render, flushed to the SCAN_URLS transient on shutdown.
	 *
	 * @var string[]
	 */
	private $captured_urls = array();

	/**
	 * Attach the frontend serve hooks (only when there's cached CSS to
	 * serve — otherwise the original Google enqueue is left untouched),
	 * during a scan's loopback request the enqueue-capture hooks, and — in
	 * the admin — a stale-CSS warning notice.
	 */
	public function register() {
		$is_scan = $this->maybe_capture_during_scan();

		$fonts = SPFW_Settings::group( 'fonts' );

		// Stand down during a scan's own loopback render. serve_local_fonts()
		// dequeues every Google Fonts stylesheet, and `style_loader_src` — the
		// filter discovery captures on — only fires for styles that actually get
		// printed. So with localization enabled the plugin was hiding the fonts
		// from its own scanner: the enqueue capture saw nothing, the HTML pass
		// found no <link> to match, and the stripped resource hints removed the
		// last trace. Rescanning could then only ever re-find fonts it had not
		// already localized, which is why disabling the toggle and rescanning
		// suddenly surfaced twice as many families.
		if ( ! $is_scan && ! empty( $fonts['localize_google'] ) && ! empty( $fonts['discovered']['css'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'serve_local_fonts' ), 99 );
			add_filter( 'wp_resource_hints', array( $this, 'remove_google_resource_hints' ), 10, 2 );
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_show_rescan_notice' ) );
		}
	}

	/**
	 * Queue an admin notice when previously-localized font CSS predates the
	 * 1.7.1 weight-collapse fix (see SPFW_Settings' 1.7.1 migration) and is
	 * still in active use, so an admin who never opens the Fonts tab still
	 * learns their live site may be rendering the wrong weights.
	 */
	public function maybe_show_rescan_notice() {
		$fonts = SPFW_Settings::group( 'fonts' );

		if ( ! empty( $fonts['needs_rescan'] ) && ! empty( $fonts['localize_google'] ) && current_user_can( 'manage_options' ) ) {
			add_action( 'admin_notices', array( $this, 'render_rescan_notice' ) );
		}
	}

	/**
	 * Render the stale-CSS admin notice, pointing at the Fonts tab where the
	 * Scan fonts now button lives.
	 */
	public function render_rescan_notice() {
		$message = __( 'Simple Performance: localized Google Fonts were generated by an older version of this plugin that could drop font weights, making text render bolder than specified.', 'simple-performance-for-wordpress' );

		$url = add_query_arg(
			array(
				'page' => 'spfw-settings',
				'tab'  => 'fonts',
			),
			admin_url( 'options-general.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( $url ),
			esc_html__( 'Go to the Fonts tab and click "Scan fonts now" to fix it.', 'simple-performance-for-wordpress' )
		);
	}

	/**
	 * When the current request is the scan loopback (identified by a valid
	 * one-time token), instrument the style pipeline to record every Google
	 * Fonts stylesheet WordPress prints. Inert on every other request.
	 *
	 * @return bool True when this request is an authorized scan loopback, so
	 *              the caller can leave the frontend rewrite switched off and
	 *              let the original Google enqueues through to be captured.
	 */
	private function maybe_capture_during_scan() {
		if ( is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared against a server-set transient below, not a form nonce.
		$token = isset( $_GET['spfw_font_scan'] ) ? sanitize_text_field( wp_unslash( $_GET['spfw_font_scan'] ) ) : '';

		if ( '' === $token ) {
			return false;
		}

		$expected = get_transient( self::SCAN_TOKEN_TRANSIENT );

		if ( ! $expected || ! hash_equals( (string) $expected, $token ) ) {
			return false;
		}

		add_filter( 'style_loader_src', array( $this, 'capture_style_src' ), PHP_INT_MAX );
		add_action( 'shutdown', array( $this, 'flush_captured_urls' ), 0 );

		return true;
	}

	/**
	 * Record (and pass through untouched) any stylesheet src that points at
	 * the Google Fonts CSS API.
	 *
	 * @param string $src Stylesheet source URL.
	 * @return string
	 */
	public function capture_style_src( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, '//fonts.googleapis.com/css' ) ) {
			$this->captured_urls[] = $src;
		}

		return $src;
	}

	/**
	 * Persist the URLs captured during this render for scan() to read back.
	 */
	public function flush_captured_urls() {
		if ( empty( $this->captured_urls ) ) {
			return;
		}

		$existing = get_transient( self::SCAN_URLS_TRANSIENT );
		$existing = is_array( $existing ) ? $existing : array();
		$merged   = array_values( array_unique( array_merge( $existing, $this->captured_urls ) ) );

		set_transient( self::SCAN_URLS_TRANSIENT, $merged, self::SCAN_TTL );
	}

	/**
	 * Discover Google Fonts (via an instrumented homepage render plus a
	 * broadened HTML/linked-CSS pass), download the referenced .woff2 files,
	 * and persist the rewritten CSS.
	 *
	 * @return array|WP_Error Result summary { families, files, message }, or
	 *                        WP_Error only when the homepage could not be loaded
	 *                        and nothing was captured.
	 */
	public function scan() {
		$token = wp_generate_password( 20, false );
		set_transient( self::SCAN_TOKEN_TRANSIENT, $token, self::SCAN_TTL );
		delete_transient( self::SCAN_URLS_TRANSIENT );

		// Every stage records what it saw into $diag, which is returned to the
		// admin UI. Discovery spans a loopback render, HTML/CSS scraping, the
		// Google API, and file downloads — any one of which can silently
		// contribute nothing — so a bare "localized N families" result gives an
		// admin no way to tell a CDN-stripped page from an unreachable Google
		// or a failed download. The per-stage counts below are the difference
		// between diagnosing that and guessing at it.
		$diag = array(
			'targets'         => array(),
			'captured'        => 0,
			'from_html'       => 0,
			'from_linked'     => 0,
			'inline_faces'    => 0,
			'manual_declared' => array(),
			'manual'          => array(),
			'css_urls'        => array(),
			'faces'           => 0,
			'downloads_ok'    => 0,
			'downloads_ko'    => 0,
		);

		// Scan the homepage plus a representative sample of inner templates and
		// any admin-specified extra URLs, so weights enqueued only on singular
		// posts/pages/products (not the homepage) are discovered too.
		$htmls    = array();
		$fetch_ok = false;

		foreach ( $this->scan_targets() as $target ) {
			$html = $this->fetch_page( $target, $token );
			$ok   = is_string( $html ) && '' !== $html;

			$diag['targets'][] = array(
				'url'   => $target,
				'ok'    => $ok,
				'bytes' => $ok ? strlen( $html ) : 0,
			);

			if ( $ok ) {
				$htmls[]  = $html;
				$fetch_ok = true;
			}
		}

		$captured = get_transient( self::SCAN_URLS_TRANSIENT );
		$captured = is_array( $captured ) ? $captured : array();

		delete_transient( self::SCAN_TOKEN_TRANSIENT );
		delete_transient( self::SCAN_URLS_TRANSIENT );

		$diag['captured'] = count( $captured );

		$css_urls     = $captured;
		$font_faces   = array();

		foreach ( $htmls as $html ) {
			$in_html   = $this->find_google_css_urls( $html );
			$in_linked = $this->find_google_in_linked_css( $html );

			$diag['from_html']   += count( $in_html );
			$diag['from_linked'] += count( $in_linked );

			$css_urls = array_merge( $css_urls, $in_html, $in_linked );

			// When a proxy/CDN inlines critical CSS it can strip the Google
			// <link> yet leave fully-resolved @font-face blocks pointing at
			// fonts.gstatic.com. Localize those directly — no Google CSS URL
			// is needed.
			foreach ( $this->find_inlined_gstatic_faces( $html ) as $key => $face ) {
				$font_faces[ $key ] = $face;
			}
		}

		$diag['inline_faces'] = count( $font_faces );

		// Manual declarations are proxy-proof: the admin states the exact
		// families/weights to localize, so a used weight (e.g. 400) is captured
		// even when the automated scan only ever sees an optimized page that
		// references another (e.g. 700).
		// Record what is *stored* separately from what could be *built* from it.
		// A declaration that never reaches the scan (a persistence problem) and
		// one the URL builder rejects (a parsing problem) both end up as "no
		// manual fonts", and only these two numbers side by side tell them apart.
		$stored_manual           = SPFW_Settings::value( 'fonts', 'manual_families', array() );
		$diag['manual_declared'] = is_array( $stored_manual ) ? array_values( $stored_manual ) : array();

		$manual_urls    = $this->manual_css_urls();
		$diag['manual'] = $manual_urls;
		$css_urls       = array_merge( $css_urls, $manual_urls );

		$css_urls = $this->normalize_css_urls( $css_urls );

		if ( empty( $css_urls ) && empty( $font_faces ) ) {
			if ( ! $fetch_ok && empty( $captured ) && empty( $manual_urls ) ) {
				$error = __( 'Could not load your homepage to scan for fonts. Your server may block loopback requests — check your site is reachable from itself, then try again.', 'simple-performance-for-wordpress' );

				// Persist the report even on the hard-failure path: this is the
				// case an admin most needs to see, and returning a bare WP_Error
				// would throw away every count that explains it.
				$this->store_scan_report( $error, $diag );

				return new WP_Error( 'spfw_fonts_fetch_failed', $error );
			}

			return $this->finish_scan(
				array(),
				__( 'No Google Fonts were detected. If your site uses a CDN/optimizer that strips font tags, add the families and weights manually below and scan again.', 'simple-performance-for-wordpress' ),
				'',
				$diag
			);
		}

		foreach ( $css_urls as $css_url ) {
			$css_body = $this->fetch_url_body( $css_url );
			$faces    = ( false !== $css_body ) ? $this->parse_font_faces( $css_body ) : array();

			$diag['css_urls'][] = array(
				'url'   => $css_url,
				'ok'    => false !== $css_body,
				'faces' => count( $faces ),
			);

			foreach ( $faces as $face ) {
				$font_faces[ $face['key'] ] = $face;
			}
		}

		$diag['faces'] = count( $font_faces );

		$files      = array();
		$families   = array();
		$rewritten  = '';
		$downloaded = array();

		foreach ( $font_faces as $face ) {
			$src_url = $face['src_url'];

			if ( ! array_key_exists( $src_url, $downloaded ) ) {
				$downloaded[ $src_url ] = $this->download_font( $src_url );

				if ( $downloaded[ $src_url ] ) {
					++$diag['downloads_ok'];
				} else {
					++$diag['downloads_ko'];
				}
			}

			$filename = $downloaded[ $src_url ];

			if ( ! $filename ) {
				continue;
			}

			$files[]    = $filename;
			$families[] = $this->family_label( $face );
			// Tokenized, not absolute: the stored CSS must stay valid if the
			// site later moves domain. Expanded at write time by render_css().
			$local_url  = self::FONTS_URL_TOKEN . '/' . $filename;
			$rewritten .= str_replace( $src_url, $local_url, $face['block'] ) . "\n";
		}

		if ( '' === $rewritten ) {
			return $this->finish_scan(
				array(),
				__( 'Google Fonts were detected but none of the font files could be downloaded. Check that your server can reach fonts.gstatic.com.', 'simple-performance-for-wordpress' ),
				'',
				$diag
			);
		}

		$discovered = array(
			'css'      => $rewritten,
			'families' => array_values( array_unique( $families ) ),
			'files'    => array_values( array_unique( $files ) ),
			'hash'     => sha1( $rewritten ),
		);

		// Record which base the on-disk file was rendered against, so
		// serve_local_fonts() can detect a later domain move and self-heal.
		$rendered_for = $this->write_css_file( $rewritten ) ? $this->rendered_base() : '';

		return $this->finish_scan(
			$discovered,
			sprintf(
				/* translators: 1: number of font families, 2: number of font files. */
				__( 'Localized %1$d font families (%2$d files).', 'simple-performance-for-wordpress' ),
				count( $discovered['families'] ),
				count( $discovered['files'] )
			),
			$rendered_for,
			$diag
		);
	}

	/**
	 * Persist the scan outcome and return a summary for the REST response.
	 * A found scan replaces `discovered`; an empty scan leaves any previous
	 * discovery intact (so a transient blip doesn't wipe working fonts) and
	 * only refreshes the timestamp. Either way, `needs_rescan` (set by the
	 * 1.7.1 migration for installs whose stored CSS predates the weight-
	 * collapse fix) clears: any scan run under the fixed generator
	 * supersedes the stale marker.
	 *
	 * @param array  $discovered   Discovered payload, or empty array when none found.
	 * @param string $message      Human-readable outcome for the admin UI.
	 * @param string $rendered_for Base the freshly-written fonts.css was rendered
	 *                             against, or '' when nothing was written.
	 * @param array  $diagnostics  Per-stage counts for the admin UI.
	 * @return array
	 */
	private function finish_scan( $discovered, $message, $rendered_for = '', $diagnostics = array() ) {
		$message = trim( $message . ' ' . $this->diag_summary( $diagnostics ) );

		$update = array(
			'fonts' => array(
				'last_scan'        => time(),
				'needs_rescan'     => false,
				'last_scan_report' => array(
					'message'     => $message,
					'diagnostics' => $diagnostics,
					'time'        => time(),
				),
			),
		);

		if ( ! empty( $discovered ) ) {
			$update['fonts']['discovered'] = $discovered;
		}

		if ( '' !== $rendered_for ) {
			$update['fonts']['rendered_for'] = $rendered_for;
		}

		SPFW_Settings::update( $update );

		// A hash change means cached pages — and any generated CSS derived from
		// the old stylesheet — must be rebuilt to pick up the rewrite.
		$this->purge_generated_css();

		return array(
			'families'    => empty( $discovered['families'] ) ? array() : $discovered['families'],
			'files'       => empty( $discovered['files'] ) ? array() : $discovered['files'],
			'message'     => $message,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * One-line summary of a scan's per-stage counts, appended to the outcome
	 * message so the headline alone says where fonts were lost. Without it an
	 * admin sees only "no fonts detected" and has to expand a panel — or ask —
	 * to learn whether the pages failed to load, the manual declarations were
	 * missing, or Google was unreachable.
	 *
	 * @param array $d Diagnostics array from scan().
	 * @return string
	 */
	private function diag_summary( $d ) {
		if ( empty( $d ) || ! is_array( $d ) ) {
			return '';
		}

		$targets = isset( $d['targets'] ) ? $d['targets'] : array();
		$ok      = 0;

		foreach ( $targets as $t ) {
			if ( ! empty( $t['ok'] ) ) {
				++$ok;
			}
		}

		$sheets = ( isset( $d['captured'] ) ? (int) $d['captured'] : 0 )
			+ ( isset( $d['from_html'] ) ? (int) $d['from_html'] : 0 )
			+ ( isset( $d['from_linked'] ) ? (int) $d['from_linked'] : 0 );

		return sprintf(
			/* translators: 1: pages loaded, 2: pages attempted, 3: stylesheets found on the site, 4: manual declarations used, 5: manual declarations stored, 6: @font-face blocks, 7: files downloaded, 8: files failed. */
			__( '[%1$d/%2$d pages loaded · %3$d Google stylesheets on the site · %4$d of %5$d manual declarations used · %6$d @font-face blocks · %7$d files downloaded, %8$d failed]', 'simple-performance-for-wordpress' ),
			$ok,
			count( $targets ),
			$sheets,
			isset( $d['manual'] ) ? count( $d['manual'] ) : 0,
			isset( $d['manual_declared'] ) ? count( $d['manual_declared'] ) : 0,
			isset( $d['faces'] ) ? (int) $d['faces'] : 0,
			isset( $d['downloads_ok'] ) ? (int) $d['downloads_ok'] : 0,
			isset( $d['downloads_ko'] ) ? (int) $d['downloads_ko'] : 0
		);
	}

	/**
	 * Persist a scan report without touching any other font state. Used by the
	 * WP_Error path, which has no discovery result to record but still needs
	 * its diagnostics to survive into the admin UI.
	 *
	 * @param string $message Outcome message.
	 * @param array  $diag    Diagnostics array.
	 */
	private function store_scan_report( $message, $diag ) {
		SPFW_Settings::update(
			array(
				'fonts' => array(
					'last_scan'        => time(),
					'last_scan_report' => array(
						'message'     => trim( $message . ' ' . $this->diag_summary( $diag ) ),
						'diagnostics' => $diag,
						'time'        => time(),
					),
				),
			)
		);
	}

	/**
	 * Build a human-readable "Family:weight[i]" label for a parsed face.
	 *
	 * @param array $face Parsed @font-face entry.
	 * @return string
	 */
	private function family_label( $face ) {
		$label = $face['family'] . ':' . $face['weight'];

		if ( 'italic' === $face['style'] ) {
			$label .= 'i';
		}

		return $label;
	}

	/**
	 * Dequeue any enqueued Google Fonts stylesheet (matched by src, not
	 * handle) and enqueue the locally hosted replacement.
	 *
	 * Self-heals the static CSS file when it is missing *or* when it was
	 * rendered against a different base than the one in effect now — which is
	 * what happens when a site is cloned to another domain (production →
	 * staging). Without that check the cloned site would keep serving the
	 * original site's absolute font URLs, and the browser would discard every
	 * cross-origin .woff2 for want of an Access-Control-Allow-Origin header.
	 *
	 * The staleness test is an in-memory string compare against the already
	 * loaded settings array — no per-request filesystem hashing. If the file
	 * can't be (re)written, the original Google enqueue is left untouched so
	 * the page still renders with the right fonts.
	 */
	public function serve_local_fonts() {
		$fonts    = SPFW_Settings::group( 'fonts' );
		$css_path = $this->fonts_dir() . '/fonts.css';
		$base     = $this->rendered_base();

		$rendered_for = isset( $fonts['rendered_for'] ) ? $fonts['rendered_for'] : '';
		$is_stale     = ( $rendered_for !== $base ) || ! file_exists( $css_path );

		if ( $is_stale && ! $this->refresh_css_file( $fonts['discovered']['css'], $base ) ) {
			return;
		}

		foreach ( wp_styles()->registered as $handle => $dependency ) {
			if ( isset( $dependency->src ) && is_string( $dependency->src ) && false !== strpos( $dependency->src, 'fonts.googleapis.com' ) ) {
				wp_dequeue_style( $handle );
			}
		}

		wp_enqueue_style( 'spfw-fonts', $this->fonts_url() . '/fonts.css', array(), $fonts['discovered']['hash'] );
	}

	/**
	 * Strip Google Fonts preconnect/dns-prefetch resource hints.
	 *
	 * @param array  $urls          Resource hint entries (strings or arrays with 'href').
	 * @param string $relation_type Hint relation type.
	 * @return array
	 */
	public function remove_google_resource_hints( $urls, $relation_type ) {
		if ( ! in_array( $relation_type, array( 'preconnect', 'dns-prefetch' ), true ) || ! is_array( $urls ) ) {
			return $urls;
		}

		return array_filter(
			$urls,
			function ( $url ) {
				$href = ( is_array( $url ) && isset( $url['href'] ) ) ? $url['href'] : $url;
				$href = (string) $href;

				return false === strpos( $href, 'fonts.googleapis.com' ) && false === strpos( $href, 'fonts.gstatic.com' );
			}
		);
	}

	/**
	 * Build the list of URLs to scan: always the homepage, plus a representative
	 * recent post and page (so weights on singular templates are seen), plus any
	 * admin-configured extra URLs. Same-origin only, deduped, and capped.
	 *
	 * @return string[]
	 */
	private function scan_targets() {
		$home    = home_url( '/' );
		$host    = wp_parse_url( $home, PHP_URL_HOST );
		$targets = array( $home );

		foreach ( $this->auto_sample_urls() as $url ) {
			$targets[] = $url;
		}

		$fonts = SPFW_Settings::group( 'fonts' );
		$extra = isset( $fonts['extra_scan_urls'] ) && is_array( $fonts['extra_scan_urls'] ) ? $fonts['extra_scan_urls'] : array();

		foreach ( $extra as $url ) {
			$abs = $this->absolutize( $url, home_url() );

			if ( ! $abs ) {
				continue;
			}

			$abs_host = wp_parse_url( $abs, PHP_URL_HOST );

			if ( $host && $abs_host && $abs_host !== $host ) {
				continue;
			}

			$targets[] = $abs;
		}

		return array_slice( array_values( array_unique( $targets ) ), 0, 12 );
	}

	/**
	 * Permalinks of the most recent published post and page, so a font weight
	 * that only appears on singular templates (never the homepage) is still
	 * discovered. Silently empty when the queries can't run.
	 *
	 * @return string[]
	 */
	private function auto_sample_urls() {
		$urls = array();

		foreach ( array( 'post', 'page' ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);

			if ( ! empty( $ids ) ) {
				$permalink = get_permalink( $ids[0] );

				if ( $permalink ) {
					$urls[] = $permalink;
				}
			}
		}

		return $urls;
	}

	/**
	 * Fetch one page's HTML through an instrumented loopback request. The scan
	 * token makes the render capture enqueued Google Fonts; the unique
	 * cache-buster arg plus explicit no-cache headers make LiteSpeed / QUIC.cloud
	 * serve a fresh, un-optimized render instead of a cached, font-tag-stripped
	 * copy.
	 *
	 * @param string $url   Absolute URL to fetch.
	 * @param string $token One-time scan token.
	 * @return string|false
	 */
	private function fetch_page( $url, $token ) {
		$url = add_query_arg(
			array(
				'spfw_font_scan' => $token,
				'spfw_nocache'   => (string) time(),
			),
			$url
		);

		$args = array(
			'timeout'     => 20,
			'redirection' => 5,
			'user-agent'  => self::CHROME_UA,
			'headers'     => array(
				'Cache-Control' => 'no-cache, no-store, must-revalidate',
				'Pragma'        => 'no-cache',
			),
		);

		$response = wp_remote_get( $url, $args );

		// Loopback TLS often fails on self-signed / mismatched certs; retry once
		// without verification (we're only reading our own page markup).
		if ( is_wp_error( $response ) ) {
			$args['sslverify'] = false;
			$response          = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Extract already-resolved @font-face blocks that point at
	 * fonts.gstatic.com from a chunk of markup (typically inlined critical CSS a
	 * CDN/optimizer left in the page after stripping the Google <link>). These
	 * can be downloaded and rewritten directly without a Google CSS URL.
	 *
	 * @param string $content HTML or CSS.
	 * @return array<string,array> Faces keyed by block-content identity (see
	 *                              parse_font_faces()), not by source URL —
	 *                              variable fonts share one URL across every
	 *                              requested weight/style.
	 */
	private function find_inlined_gstatic_faces( $content ) {
		$faces = array();

		foreach ( $this->parse_font_faces( $content ) as $face ) {
			if ( false !== strpos( $face['src_url'], 'fonts.gstatic.com' ) ) {
				$faces[ $face['key'] ] = $face;
			}
		}

		return $faces;
	}

	/**
	 * Turn the admin's manual `Family:weights` declarations into canonical
	 * Google Fonts `css2` stylesheet URLs, one per family. Because these are
	 * built from explicit input rather than scraped from a (possibly optimized)
	 * page, they reliably capture every requested weight — the primary fix for
	 * weights a CDN/proxy hides from automated discovery.
	 *
	 * @return string[]
	 */
	private function manual_css_urls() {
		$fonts  = SPFW_Settings::group( 'fonts' );
		$manual = isset( $fonts['manual_families'] ) && is_array( $fonts['manual_families'] ) ? $fonts['manual_families'] : array();
		$urls   = array();

		foreach ( $manual as $spec ) {
			$url = $this->build_google_css_url( $spec );

			if ( $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Build a `fonts.googleapis.com/css2` URL for one `Family:weights` spec
	 * (e.g. `Roboto Condensed:400,700` or `Roboto Condensed:400,400i,700`).
	 * Weights are validated to 100–900; a trailing `i` marks italic, emitted on
	 * the `ital,wght` axis. Returns false for an unusable spec.
	 *
	 * @param string $spec Normalized `Family:weights` declaration.
	 * @return string|false
	 */
	private function build_google_css_url( $spec ) {
		$parts  = explode( ':', (string) $spec, 2 );
		$family = trim( $parts[0] );

		if ( '' === $family ) {
			return false;
		}

		$weights = isset( $parts[1] ) ? preg_split( '/[,\s]+/', trim( $parts[1] ) ) : array( '400' );
		$normals = array();
		$italics = array();

		foreach ( $weights as $weight ) {
			$weight = trim( $weight );

			if ( preg_match( '/^([1-9]00)i$/', $weight, $m ) ) {
				$italics[] = (int) $m[1];
			} elseif ( preg_match( '/^([1-9]00)$/', $weight, $m ) ) {
				$normals[] = (int) $m[1];
			}
		}

		if ( empty( $normals ) && empty( $italics ) ) {
			$normals = array( 400 );
		}

		$family_param = rawurlencode( $family );
		$family_param = str_replace( '%20', '+', $family_param );

		if ( ! empty( $italics ) ) {
			$tuples = array();

			foreach ( array_unique( $normals ) as $w ) {
				$tuples[] = '0,' . $w;
			}

			foreach ( array_unique( $italics ) as $w ) {
				$tuples[] = '1,' . $w;
			}

			// Google requires the axis tuples in ascending order.
			sort( $tuples );
			$axis = 'ital,wght@' . implode( ';', $tuples );
		} else {
			$normals = array_unique( $normals );
			sort( $normals );
			$axis = 'wght@' . implode( ';', array_map( 'strval', $normals ) );
		}

		return 'https://fonts.googleapis.com/css2?family=' . $family_param . ':' . $axis . '&display=swap';
	}

	/**
	 * Find Google Fonts CSS API URLs (v1 `css` and v2 `css2`, over https,
	 * http, or protocol-relative) referenced anywhere in a chunk of markup
	 * or CSS.
	 *
	 * @param string $content HTML or CSS.
	 * @return string[]
	 */
	private function find_google_css_urls( $content ) {
		if ( ! preg_match_all( '#(?:https?:)?//fonts\.googleapis\.com/css2?\?[^\s"\'<>()]+#i', $content, $matches ) ) {
			return array();
		}

		return array_map( 'html_entity_decode', $matches[0] );
	}

	/**
	 * Follow same-origin `<link rel="stylesheet">` files and scan each for
	 * Google Fonts references (themes commonly `@import` Google Fonts inside
	 * their own compiled stylesheet, so they never appear in the page HTML).
	 * Bounded by MAX_LINKED_CSS to keep the scan cheap.
	 *
	 * @param string $html Homepage HTML.
	 * @return string[]
	 */
	private function find_google_in_linked_css( $html ) {
		if ( ! preg_match_all( '#<link\b[^>]*rel=[\'"]stylesheet[\'"][^>]*>#i', $html, $links ) ) {
			return array();
		}

		$home  = home_url();
		$host  = wp_parse_url( $home, PHP_URL_HOST );
		$found = array();
		$seen  = 0;

		foreach ( $links[0] as $tag ) {
			if ( $seen >= self::MAX_LINKED_CSS ) {
				break;
			}

			if ( ! preg_match( '#href=[\'"]([^\'"]+)[\'"]#i', $tag, $href_match ) ) {
				continue;
			}

			$href = html_entity_decode( $href_match[1] );

			// Google's own URLs are already handled by find_google_css_urls().
			if ( false !== strpos( $href, 'fonts.googleapis.com' ) ) {
				continue;
			}

			$abs = $this->absolutize( $href, $home );

			if ( ! $abs ) {
				continue;
			}

			// Only follow same-origin stylesheets.
			$abs_host = wp_parse_url( $abs, PHP_URL_HOST );

			if ( $host && $abs_host && $abs_host !== $host ) {
				continue;
			}

			++$seen;
			$body = $this->fetch_url_body( $abs );

			if ( false !== $body ) {
				$found = array_merge( $found, $this->find_google_css_urls( $body ) );
			}
		}

		return $found;
	}

	/**
	 * Resolve an href (absolute, protocol-relative, or root-relative) against
	 * the site home URL.
	 *
	 * @param string $href Raw href.
	 * @param string $base Site home URL.
	 * @return string|false
	 */
	private function absolutize( $href, $base ) {
		$href = trim( $href );

		if ( '' === $href ) {
			return false;
		}

		if ( 0 === strpos( $href, 'http://' ) || 0 === strpos( $href, 'https://' ) ) {
			return $href;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			return 'https:' . $href;
		}

		if ( 0 === strpos( $href, '/' ) ) {
			return untrailingslashit( $base ) . $href;
		}

		return untrailingslashit( $base ) . '/' . ltrim( $href, '/' );
	}

	/**
	 * Normalize a mixed list of Google CSS URLs: entity-decode, upgrade
	 * protocol-relative/http to https, keep only fonts.googleapis.com/css
	 * URLs, and dedupe.
	 *
	 * @param string[] $urls Raw URLs.
	 * @return string[]
	 */
	private function normalize_css_urls( $urls ) {
		$out = array();

		foreach ( $urls as $url ) {
			$url = trim( html_entity_decode( (string) $url ) );

			if ( '' === $url ) {
				continue;
			}

			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			$url = str_replace( 'http://fonts.googleapis.com', 'https://fonts.googleapis.com', $url );

			if ( false !== strpos( $url, 'fonts.googleapis.com/css' ) ) {
				$out[ $url ] = $url;
			}
		}

		return array_values( $out );
	}

	/**
	 * Parse @font-face blocks out of a Google Fonts CSS response, deduped by
	 * full block identity rather than source URL. Google serves variable
	 * fonts (e.g. Roboto Condensed) as a *single* .woff2 file shared across
	 * every requested weight/style for a given unicode-range subset — only
	 * the `font-weight`/`font-style` descriptors differ between blocks. A
	 * URL-keyed dedupe would collapse those distinct weights down to
	 * whichever block happened to be parsed last (Google emits them in
	 * ascending weight order, so that was always the heaviest one) — the
	 * root cause of localized text rendering bolder than specified. Keying
	 * on a hash of the whitespace-normalized block text keeps every distinct
	 * weight/style/subset combination while still deduping byte-identical
	 * blocks seen more than once (e.g. captured via both the enqueue
	 * pipeline and an HTML regex pass).
	 *
	 * @param string $css Google Fonts CSS.
	 * @return array[] Each entry: key, block, family, weight, style, src_url.
	 */
	private function parse_font_faces( $css ) {
		$faces = array();

		if ( ! preg_match_all( '/@font-face\s*\{([^}]*)\}/is', $css, $blocks, PREG_SET_ORDER ) ) {
			return $faces;
		}

		foreach ( $blocks as $block ) {
			$body = $block[1];

			if ( ! preg_match( '/font-family:\s*[\'"]?([^;\'"]+)[\'"]?\s*;/i', $body, $family_match ) ) {
				continue;
			}

			if ( ! preg_match( '/url\(\s*[\'"]?([^)\'"]+\.woff2)[\'"]?\s*\)/i', $body, $src_match ) ) {
				continue;
			}

			$weight = preg_match( '/font-weight:\s*([0-9 ]+)/i', $body, $weight_match ) ? trim( $weight_match[1] ) : '400';
			$style  = preg_match( '/font-style:\s*(italic|oblique)/i', $body, $style_match ) ? 'italic' : 'normal';
			$src    = trim( $src_match[1], "'\" " );
			$key    = sha1( preg_replace( '/\s+/', ' ', trim( $block[0] ) ) );

			$faces[ $key ] = array(
				'key'     => $key,
				'block'   => $block[0],
				'family'  => trim( $family_match[1] ),
				'weight'  => $weight,
				'style'   => $style,
				'src_url' => $src,
			);
		}

		return array_values( $faces );
	}

	/**
	 * Fetch an arbitrary URL body with a browser-like UA.
	 *
	 * @param string $url URL to fetch.
	 * @return string|false
	 */
	private function fetch_url_body( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => self::CHROME_UA,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Download a single .woff2 file into the local fonts directory.
	 *
	 * @param string $url Remote .woff2 URL.
	 * @return string|false Basename of the saved file, or false on failure.
	 */
	private function download_font( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body || ! $this->ensure_fonts_dir() ) {
			return false;
		}

		$fs = $this->filesystem();

		if ( ! $fs ) {
			return false;
		}

		$filename = sha1( $url ) . '.woff2';

		if ( ! $fs->put_contents( $this->fonts_dir() . '/' . $filename, $body, 0644 ) ) {
			return false;
		}

		return $filename;
	}

	/**
	 * Regenerate fonts.css for the current base and record what it was
	 * rendered against, so the check in serve_local_fonts() settles on the
	 * next request instead of rewriting the file every time.
	 *
	 * Wrapped in a short-lived transient lock: on a host where the uploads
	 * directory is not writable this would otherwise attempt (and fail) a
	 * write on every front-end request. The lock is released as soon as a
	 * write succeeds, so the healthy path is never delayed.
	 *
	 * @param string $css  Stored (tokenized or legacy absolute) CSS.
	 * @param string $base Base the file is being rendered against.
	 * @return bool
	 */
	private function refresh_css_file( $css, $base ) {
		if ( get_transient( self::REWRITE_LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::REWRITE_LOCK_TRANSIENT, 1, self::REWRITE_LOCK_TTL );

		if ( ! $this->write_css_file( $css ) ) {
			return false;
		}

		delete_transient( self::REWRITE_LOCK_TRANSIENT );

		SPFW_Settings::update( array( 'fonts' => array( 'rendered_for' => $base ) ) );

		$this->purge_generated_css();

		return true;
	}

	/**
	 * Invalidate every cache that may hold a *derived* copy of the font CSS.
	 *
	 * Purging the page cache alone is not enough, and assuming otherwise is
	 * what let this bug survive a re-scan on a live site: LiteSpeed's CSS
	 * combine output and QUIC.cloud's generated Critical CSS / Unique CSS are
	 * separate artifacts, written into /wp-content/litespeed/ and served in
	 * place of the original stylesheet. A UCSS file generated while fonts.css
	 * still held absolute URLs keeps serving those URLs to the browser no
	 * matter how many times fonts.css itself is regenerated — the observed
	 * failure on a cloned staging site, where the page's own markup was clean
	 * and a single UCSS file carried every stale font reference.
	 *
	 * Each hook is a plain do_action, so any name LSCache does not register
	 * (whether because the plugin is absent or the version differs) is a
	 * harmless no-op.
	 */
	private function purge_generated_css() {
		foreach ( array(
			'litespeed_purge_all_ucss',  // QUIC.cloud Unique CSS.
			'litespeed_purge_all_ccss',  // QUIC.cloud Critical CSS.
			'litespeed_purge_all_cssjs', // Combined/minified CSS + JS.
			'litespeed_purge_all',       // Page cache, last so it settles after the rest.
		) as $hook ) {
			do_action( $hook );
		}
	}

	/**
	 * Write the generated @font-face CSS to the static fonts.css file, and
	 * keep the directory's CORS rules in place alongside it.
	 *
	 * This is the single choke point where stored CSS becomes a served file,
	 * so it normalizes on the way through: any absolute font URL left over
	 * from an older version (or from a clone of another domain) is folded back
	 * to the token, then expanded for the base in effect now. Callers can pass
	 * stored CSS of either vintage and get correct URLs on disk.
	 *
	 * @param string $css CSS to write.
	 * @return bool
	 */
	private function write_css_file( $css ) {
		if ( ! $this->ensure_fonts_dir() ) {
			return false;
		}

		$fs = $this->filesystem();

		if ( ! $fs ) {
			return false;
		}

		$rendered = $this->render_css( $this->portable_css( $css ) );

		if ( ! $fs->put_contents( $this->fonts_dir() . '/fonts.css', $rendered, 0644 ) ) {
			return false;
		}

		$this->write_cors_htaccess();

		return true;
	}

	/**
	 * Fold any absolute URL pointing into the local fonts directory back to
	 * the portable token. Idempotent, and a no-op on already-tokenized CSS.
	 *
	 * @param string $css CSS to normalize.
	 * @return string
	 */
	private function portable_css( $css ) {
		return (string) preg_replace( self::FONTS_URL_PATTERN, self::FONTS_URL_TOKEN . '/', (string) $css );
	}

	/**
	 * Expand the portable token to the base this site should serve fonts from.
	 *
	 * @param string $css Tokenized CSS.
	 * @return string
	 */
	private function render_css( $css ) {
		return str_replace( self::FONTS_URL_TOKEN, $this->rendered_base(), (string) $css );
	}

	/**
	 * The base that font URLs inside the generated stylesheet resolve against.
	 *
	 * When uploads live on the site's own host — the overwhelmingly common
	 * case — this is a **root-relative path** (`/wp-content/uploads/ods-fonts`)
	 * rather than an absolute URL. That makes the generated CSS immune to every
	 * variant of the bug this exists to fix: moving domain, switching http to
	 * https, or adding/dropping `www` can no longer strand the font URLs on the
	 * old origin. It also survives LiteSpeed relocating the combined stylesheet,
	 * because a root-relative path resolves against the origin rather than the
	 * stylesheet's own directory.
	 *
	 * When uploads are offloaded to a different host (a CDN, or an explicit
	 * `upload_url_path`), root-relative would point at the wrong host, so the
	 * absolute URL is kept. Fonts are then genuinely cross-origin and depend on
	 * the Access-Control-Allow-Origin rules in write_cors_htaccess() — or, if
	 * that host isn't this server, on the CDN's own header configuration. The
	 * Fonts tab flags this case.
	 *
	 * @return string Base with no trailing slash.
	 */
	private function rendered_base() {
		$url          = $this->fonts_url();
		$uploads_host = wp_parse_url( $url, PHP_URL_HOST );
		$site_host    = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $uploads_host && $site_host && strtolower( $uploads_host ) === strtolower( $site_host ) ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );

			if ( is_string( $path ) && '' !== $path && '/' !== $path ) {
				return untrailingslashit( $path );
			}
		}

		return $url;
	}

	/**
	 * The .htaccess this plugin writes into the fonts directory.
	 *
	 * Fonts referenced from CSS are always fetched in CORS mode — that is not
	 * configurable from CSS and is unaffected by Content-Security-Policy — so a
	 * cross-origin .woff2 served without Access-Control-Allow-Origin is fetched
	 * successfully and then discarded by the browser. A wildcard origin is the
	 * right value here: these are public static assets with no credentials and
	 * no per-user variation.
	 *
	 * The <IfModule> wrapper is load-bearing. A bare `Header` directive returns
	 * a 500 on a server built without mod_headers, and this file is dropped into
	 * a live uploads directory — the same caution behind SPFW_Htaccess::payload()
	 * omitting `Options -Indexes`.
	 *
	 * @return string
	 */
	public static function cors_htaccess_payload() {
		return "# BEGIN Simple Performance for WordPress\n"
			. "# Allow cross-origin font loading (CDN / asset-host setups).\n"
			. "<IfModule mod_headers.c>\n"
			. "\t<FilesMatch \"\\.(woff2?|ttf|otf|eot)$\">\n"
			. "\t\tHeader set Access-Control-Allow-Origin \"*\"\n"
			. "\t\tHeader append Vary Origin\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n"
			. "# END Simple Performance for WordPress\n";
	}

	/**
	 * Drop the CORS rules into the fonts directory, skipping the write when the
	 * file is already byte-identical.
	 *
	 * Deliberately a separate file from the uploads-level deny-PHP .htaccess
	 * rather than an addition to SPFW_Htaccess::payload(): changing that shared
	 * payload would change its sha1 and flip every existing install's hardening
	 * status to `altered`, firing a false "file has been modified" notice. The
	 * two rule sets do not overlap.
	 *
	 * @return bool
	 */
	private function write_cors_htaccess() {
		$path    = $this->fonts_dir() . '/.htaccess';
		$payload = self::cors_htaccess_payload();

		if ( file_exists( $path ) && sha1_file( $path ) === sha1( $payload ) ) {
			return true;
		}

		$fs = $this->filesystem();

		return $fs && (bool) $fs->put_contents( $path, $payload, 0644 );
	}

	/**
	 * Read-only diagnostics for the Fonts tab: where fonts are actually being
	 * served from, and whether that is same-origin with the site. Surfacing
	 * this is what turns a silent cross-origin misconfiguration (a cloned site
	 * still pointing at the original domain, or an `upload_url_path` left
	 * behind by a migration) into something an admin can see.
	 *
	 * @return array
	 */
	public function runtime_info() {
		$url          = $this->fonts_url();
		$uploads_host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return array(
			'base'             => $this->rendered_base(),
			'base_url'         => $url,
			'site_host'        => $site_host,
			'uploads_host'     => $uploads_host,
			'same_origin'      => ( '' !== $uploads_host && '' !== $site_host && strtolower( $uploads_host ) === strtolower( $site_host ) ),
			'css_file_exists'  => file_exists( $this->fonts_dir() . '/fonts.css' ),
			'cors_file_exists' => file_exists( $this->fonts_dir() . '/.htaccess' ),
			'rendered_for'     => (string) SPFW_Settings::value( 'fonts', 'rendered_for', '' ),
		);
	}

	/**
	 * Ensure the local fonts directory exists.
	 *
	 * @return bool
	 */
	private function ensure_fonts_dir() {
		$fs = $this->filesystem();

		if ( ! $fs ) {
			return false;
		}

		$dir = $this->fonts_dir();

		return $fs->is_dir( $dir ) || $fs->mkdir( $dir, 0755 );
	}

	/**
	 * Local fonts directory (no trailing slash).
	 *
	 * @return string
	 */
	private function fonts_dir() {
		$upload_dir = wp_upload_dir();

		return untrailingslashit( $upload_dir['basedir'] ) . '/ods-fonts';
	}

	/**
	 * Local fonts directory URL (no trailing slash).
	 *
	 * @return string
	 */
	private function fonts_url() {
		$upload_dir = wp_upload_dir();

		return untrailingslashit( $upload_dir['baseurl'] ) . '/ods-fonts';
	}

	/**
	 * Initialize and return the WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		return $wp_filesystem;
	}
}
