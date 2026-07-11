<?php
/**
 * Module 4: Google Fonts localizer & discovery.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Discovers Google Fonts referenced by the site, downloads the .woff2
 * files locally, and rewrites the frontend to serve them from
 * /uploads/ods-fonts/ instead of fonts.googleapis.com/fonts.gstatic.com.
 *
 * Discovery captures fonts from WordPress's own style pipeline while the
 * homepage renders (the `style_loader_src` filter), not just by regex-scanning
 * loopback HTML — so it catches every enqueued Google Fonts stylesheet
 * regardless of protocol form (https/http/protocol-relative), API version
 * (`css`/`css2`), or the handle a theme happens to register it under. A
 * broadened HTML/linked-CSS pass runs alongside it to catch hard-coded
 * `<link>`s and `@import`s that don't flow through the enqueue system.
 */
class SPFW_Module_Fonts implements SPFW_Module {

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
	 * serve — otherwise the original Google enqueue is left untouched) and,
	 * during a scan's loopback request, the enqueue-capture hooks.
	 */
	public function register() {
		$this->maybe_capture_during_scan();

		$fonts = SPFW_Settings::group( 'fonts' );

		if ( ! empty( $fonts['localize_google'] ) && ! empty( $fonts['discovered']['css'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'serve_local_fonts' ), 99 );
			add_filter( 'wp_resource_hints', array( $this, 'remove_google_resource_hints' ), 10, 2 );
		}
	}

	/**
	 * When the current request is the scan loopback (identified by a valid
	 * one-time token), instrument the style pipeline to record every Google
	 * Fonts stylesheet WordPress prints. Inert on every other request.
	 */
	private function maybe_capture_during_scan() {
		if ( is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared against a server-set transient below, not a form nonce.
		$token = isset( $_GET['spfw_font_scan'] ) ? sanitize_text_field( wp_unslash( $_GET['spfw_font_scan'] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		$expected = get_transient( self::SCAN_TOKEN_TRANSIENT );

		if ( ! $expected || ! hash_equals( (string) $expected, $token ) ) {
			return;
		}

		add_filter( 'style_loader_src', array( $this, 'capture_style_src' ), PHP_INT_MAX );
		add_action( 'shutdown', array( $this, 'flush_captured_urls' ), 0 );
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

		$html = $this->fetch_homepage( $token );

		$captured = get_transient( self::SCAN_URLS_TRANSIENT );
		$captured = is_array( $captured ) ? $captured : array();

		delete_transient( self::SCAN_TOKEN_TRANSIENT );
		delete_transient( self::SCAN_URLS_TRANSIENT );

		$css_urls = $captured;

		if ( is_string( $html ) && '' !== $html ) {
			$css_urls = array_merge( $css_urls, $this->find_google_css_urls( $html ) );
			$css_urls = array_merge( $css_urls, $this->find_google_in_linked_css( $html ) );
		}

		$css_urls = $this->normalize_css_urls( $css_urls );

		if ( empty( $css_urls ) ) {
			if ( false === $html && empty( $captured ) ) {
				return new WP_Error(
					'spfw_fonts_fetch_failed',
					__( 'Could not load your homepage to scan for fonts. Your server may block loopback requests — check your site is reachable from itself, then try again.', 'simple-performance-for-wordpress' )
				);
			}

			return $this->finish_scan(
				array(),
				__( 'No Google Fonts were detected on your homepage. Nothing to localize.', 'simple-performance-for-wordpress' )
			);
		}

		$font_faces = array();

		foreach ( $css_urls as $css_url ) {
			$css_body = $this->fetch_url_body( $css_url );

			if ( false !== $css_body ) {
				foreach ( $this->parse_font_faces( $css_body ) as $face ) {
					$font_faces[ $face['src_url'] ] = $face;
				}
			}
		}

		$files     = array();
		$families  = array();
		$rewritten = '';

		foreach ( $font_faces as $face ) {
			$filename = $this->download_font( $face['src_url'] );

			if ( ! $filename ) {
				continue;
			}

			$files[]    = $filename;
			$families[] = $this->family_label( $face );
			$local_url  = $this->fonts_url() . '/' . $filename;
			$rewritten .= str_replace( $face['src_url'], $local_url, $face['block'] ) . "\n";
		}

		if ( '' === $rewritten ) {
			return $this->finish_scan(
				array(),
				__( 'Google Fonts were detected but none of the font files could be downloaded. Check that your server can reach fonts.gstatic.com.', 'simple-performance-for-wordpress' )
			);
		}

		$discovered = array(
			'css'      => $rewritten,
			'families' => array_values( array_unique( $families ) ),
			'files'    => array_values( array_unique( $files ) ),
			'hash'     => sha1( $rewritten ),
		);

		$this->write_css_file( $rewritten );

		return $this->finish_scan(
			$discovered,
			sprintf(
				/* translators: 1: number of font families, 2: number of font files. */
				__( 'Localized %1$d font families (%2$d files).', 'simple-performance-for-wordpress' ),
				count( $discovered['families'] ),
				count( $discovered['files'] )
			)
		);
	}

	/**
	 * Persist the scan outcome and return a summary for the REST response.
	 * A found scan replaces `discovered`; an empty scan leaves any previous
	 * discovery intact (so a transient blip doesn't wipe working fonts) and
	 * only refreshes the timestamp.
	 *
	 * @param array  $discovered Discovered payload, or empty array when none found.
	 * @param string $message    Human-readable outcome for the admin UI.
	 * @return array
	 */
	private function finish_scan( $discovered, $message ) {
		$update = array( 'fonts' => array( 'last_scan' => time() ) );

		if ( ! empty( $discovered ) ) {
			$update['fonts']['discovered'] = $discovered;
		}

		SPFW_Settings::update( $update );

		// A hash change means cached pages must be re-rendered to pick up the
		// rewrite; harmless no-op when LSCache is not installed.
		do_action( 'litespeed_purge_all' );

		return array(
			'families' => empty( $discovered['families'] ) ? array() : $discovered['families'],
			'files'    => empty( $discovered['files'] ) ? array() : $discovered['files'],
			'message'  => $message,
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
	 * handle) and enqueue the locally hosted replacement. Self-heals the
	 * static CSS file if it's missing but cached CSS exists; if it can't
	 * be (re)written, leaves the original Google enqueue untouched.
	 */
	public function serve_local_fonts() {
		$fonts    = SPFW_Settings::group( 'fonts' );
		$css_path = $this->fonts_dir() . '/fonts.css';

		if ( ! file_exists( $css_path ) && ! $this->write_css_file( $fonts['discovered']['css'] ) ) {
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
	 * Fetch the homepage HTML through an instrumented loopback request. The
	 * scan token makes the render capture enqueued Google Fonts; the
	 * cache-buster arg makes LiteSpeed serve a fresh render.
	 *
	 * @param string $token One-time scan token.
	 * @return string|false
	 */
	private function fetch_homepage( $token ) {
		$url = add_query_arg(
			array(
				'spfw_font_scan' => $token,
				'spfw_nocache'   => (string) time(),
			),
			home_url( '/' )
		);

		$args = array(
			'timeout'     => 20,
			'redirection' => 5,
			'user-agent'  => self::CHROME_UA,
		);

		$response = wp_remote_get( $url, $args );

		// Loopback TLS often fails on self-signed / mismatched certs; retry once
		// without verification (we're only reading our own homepage markup).
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
	 * source URL.
	 *
	 * @param string $css Google Fonts CSS.
	 * @return array[] Each entry: block, family, weight, style, src_url.
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

			$faces[ $src ] = array(
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
	 * Write the generated @font-face CSS to the static fonts.css file.
	 *
	 * @param string $css CSS to write.
	 * @return bool
	 */
	private function write_css_file( $css ) {
		if ( ! $this->ensure_fonts_dir() ) {
			return false;
		}

		$fs = $this->filesystem();

		return $fs && (bool) $fs->put_contents( $this->fonts_dir() . '/fonts.css', $css, 0644 );
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
