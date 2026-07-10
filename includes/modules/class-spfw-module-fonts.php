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
 */
class SPFW_Module_Fonts implements SPFW_Module {

	/**
	 * Attach the frontend serve hooks, only when there's cached CSS to
	 * serve — otherwise the original Google enqueue is left untouched.
	 */
	public function register() {
		$fonts = SPFW_Settings::group( 'fonts' );

		if ( ! empty( $fonts['localize_google'] ) && ! empty( $fonts['discovered']['css'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'serve_local_fonts' ), 99 );
			add_filter( 'wp_resource_hints', array( $this, 'remove_google_resource_hints' ), 10, 2 );
		}
	}

	/**
	 * Fetch the homepage, discover Google Fonts CSS references, download
	 * the referenced .woff2 files, and persist the rewritten CSS.
	 *
	 * @return array|WP_Error Discovered fonts array, or WP_Error on total failure.
	 */
	public function scan() {
		$html = $this->fetch_homepage();

		if ( false === $html ) {
			return new WP_Error(
				'spfw_fonts_fetch_failed',
				__( 'Could not fetch the homepage to scan for fonts.', 'simple-performance-for-wordpress' )
			);
		}

		$font_faces = array();

		foreach ( $this->find_google_css_urls( $html ) as $css_url ) {
			$css_body = $this->fetch_google_css( $css_url );

			if ( false !== $css_body ) {
				$font_faces = array_merge( $font_faces, $this->parse_font_faces( $css_body ) );
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
			$families[] = $face['family'] . ':' . $face['weight'];
			$local_url  = $this->fonts_url() . '/' . $filename;
			$rewritten .= str_replace( $face['src_url'], $local_url, $face['block'] ) . "\n";
		}

		$discovered = array(
			'css'      => $rewritten,
			'families' => array_values( array_unique( $families ) ),
			'files'    => array_values( array_unique( $files ) ),
			'hash'     => sha1( $rewritten ),
		);

		if ( '' !== $rewritten ) {
			$this->write_css_file( $rewritten );
		}

		SPFW_Settings::update(
			array(
				'fonts' => array(
					'discovered' => $discovered,
					'last_scan'  => time(),
				),
			)
		);

		do_action( 'litespeed_purge_all' );

		return $discovered;
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
	 * Fetch the homepage HTML.
	 *
	 * @return string|false
	 */
	private function fetch_homepage() {
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Find fonts.googleapis.com CSS URLs referenced in HTML.
	 *
	 * @param string $html Homepage HTML.
	 * @return string[]
	 */
	private function find_google_css_urls( $html ) {
		if ( ! preg_match_all( '#https://fonts\.googleapis\.com/css2?\?[^\s"\'<>)]+#i', $html, $matches ) ) {
			return array();
		}

		$urls = array_map( 'html_entity_decode', $matches[0] );

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Fetch a Google Fonts CSS URL with a browser-like UA so Google
	 * returns .woff2 sources.
	 *
	 * @param string $url Google Fonts CSS URL.
	 * @return string|false
	 */
	private function fetch_google_css( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Parse @font-face blocks out of a Google Fonts CSS response.
	 *
	 * @param string $css Google Fonts CSS.
	 * @return array[] Each entry: block, family, weight, src_url.
	 */
	private function parse_font_faces( $css ) {
		$faces = array();

		if ( ! preg_match_all( '/@font-face\s*\{([^}]*)\}/is', $css, $blocks, PREG_SET_ORDER ) ) {
			return $faces;
		}

		foreach ( $blocks as $block ) {
			$body = $block[1];

			if ( ! preg_match( '/font-family:\s*[\'"]([^\'"]+)[\'"]/i', $body, $family_match ) ) {
				continue;
			}

			if ( ! preg_match( '/src:\s*url\(([^)]+\.woff2)\)/i', $body, $src_match ) ) {
				continue;
			}

			$weight = preg_match( '/font-weight:\s*(\d+)/i', $body, $weight_match ) ? $weight_match[1] : '400';

			$faces[] = array(
				'block'   => $block[0],
				'family'  => $family_match[1],
				'weight'  => $weight,
				'src_url' => trim( $src_match[1], '\'"' ),
			);
		}

		return $faces;
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
