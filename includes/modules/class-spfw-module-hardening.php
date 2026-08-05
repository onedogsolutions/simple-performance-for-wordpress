<?php
/**
 * Module 3: directory-level and site security hardening.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies the runtime hardening toggles (file-editing lockdown, author
 * enumeration blocking, security headers), surfaces an admin notice when a
 * plugins/uploads hardening file is missing or altered, and keeps those files
 * in sync with their setting toggles.
 */
class SPFW_Module_Hardening implements SPFW_Module {

	/**
	 * .htaccess targets managed by this module, mapped to their toggle key.
	 *
	 * @var array<string,string>
	 */
	const HTACCESS_TARGETS = array(
		'plugins' => 'plugins_htaccess',
		'uploads' => 'uploads_htaccess',
	);

	/**
	 * Root .htaccess toggles (composed into one marker block).
	 *
	 * @var string[]
	 */
	const ROOT_TOGGLES = array(
		'protect_sensitive_files',
		'block_xmlrpc_file',
	);

	/**
	 * Recommended baseline Content-Security-Policy. Deliberately permissive
	 * enough not to break a typical WordPress front end: WordPress and most
	 * themes/plugins emit inline <style>/<script> and data: images, so
	 * 'unsafe-inline' and data: are allowed for those directives. It still
	 * closes the highest-value holes — object-src 'none' (no Flash/plugins),
	 * base-uri 'self' (blocks <base> hijacking), frame-ancestors 'self'
	 * (clickjacking). Used whenever the admin has not supplied a custom policy.
	 *
	 * @var string
	 */
	const DEFAULT_CSP = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https: data:; font-src 'self' data: https:; connect-src 'self'; media-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'self';";

	/**
	 * Attach hooks: an admin-only integrity check, a settings-change listener
	 * that writes/removes the .htaccess files when a toggle flips, and the
	 * runtime hardening behaviors for the currently enabled toggles.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_root_self_check' ) );
		add_action( 'update_option_' . SPFW_Settings::OPTION_KEY, array( $this, 'handle_settings_change' ), 10, 2 );

		$h = SPFW_Settings::group( 'hardening' );

		// Remove the wp-admin theme/plugin code editor. DISALLOW_FILE_EDIT is
		// read when the editor screens load, well after `plugins_loaded`, so
		// defining it here (guarded, so wp-config.php always wins) is enough.
		if ( ! empty( $h['disable_file_editing'] ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// Block ?author=N / /author/slug/ username enumeration for anonymous
		// visitors. Priority 1 so it runs before redirect_canonical (which
		// would otherwise 301 ?author=1 to /author/slug/ and leak the login).
		if ( ! empty( $h['block_author_enum'] ) && ! is_admin() ) {
			add_action( 'template_redirect', array( $this, 'block_author_enumeration' ), 1 );

			// A5: close the sitemap author-enumeration leak. When author
			// enumeration is blocked but WP sitemaps remain enabled,
			// /wp-sitemap-users-1.xml still lists every author nicename.
			add_filter( 'wp_sitemaps_add_provider', array( $this, 'remove_users_sitemap_provider' ), 10, 2 );
		}

		// Disable Application Passwords (satisfies restapi.require_auth
		// bypass via app-password Basic Auth, which also skips 2FA).
		if ( ! empty( $h['disable_app_passwords'] ) ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}

		// Generic login error messages: prevent wp-login.php from disclosing
		// whether a username exists.
		if ( ! empty( $h['generic_login_errors'] ) ) {
			add_filter( 'login_errors', array( $this, 'generic_login_error' ) );
			add_filter( 'wp_login_errors', array( $this, 'generic_login_error' ) );
		}

		// Emit conservative security response headers on front-end / REST
		// responses (send_headers does not fire in wp-admin).
		if ( ! empty( $h['security_headers'] ) ) {
			add_action( 'send_headers', array( $this, 'add_security_headers' ) );

			// C3: send a safe subset in wp-admin too (nosniff + Referrer-Policy
			// only — never CSP or HSTS from this path).
			add_action( 'admin_init', array( $this, 'add_admin_security_headers' ) );
		}

		// Content-Security-Policy is a separate, opt-in toggle because it is
		// the one header that can break front-end rendering. send_headers does
		// not fire in wp-admin, so the dashboard is never affected.
		if ( ! empty( $h['csp_enabled'] ) ) {
			add_action( 'send_headers', array( $this, 'add_csp_header' ) );
		}

		// HSTS is a separate, opt-in toggle: once a browser sees it, it
		// enforces HTTPS for max-age seconds even if the admin later
		// disables it, so it warrants its own explicit consent like CSP.
		if ( ! empty( $h['hsts_enabled'] ) ) {
			add_action( 'send_headers', array( $this, 'add_hsts_header' ) );
		}

		// Disable XML-RPC at the PHP level unless the admin prefers the
		// server-level block (which is handled by the root .htaccess rule).
		if ( ! empty( $h['disable_xmlrpc'] ) && empty( $h['block_xmlrpc_file'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( $this, 'strip_pingback_methods' ) );
			add_filter( 'wp_headers', array( $this, 'strip_pingback_header' ) );
		}
	}

	/**
	 * Queue an admin notice if any managed hardening file is missing or
	 * altered.
	 */
	public function maybe_show_notice() {
		$targets = array_merge( array_keys( self::HTACCESS_TARGETS ), array( 'root' ) );

		foreach ( $targets as $target ) {
			if ( in_array( SPFW_Htaccess::status( $target ), array( 'missing', 'altered' ), true ) ) {
				add_action( 'admin_notices', array( $this, 'render_notice' ) );

				return;
			}
		}
	}

	/**
	 * Render the missing/altered admin notice, pointing at the Hardening tab
	 * (where the Restore action lives, via the REST controller).
	 */
	public function render_notice() {
		$message = __( 'Simple Performance: a directory hardening file is missing or has been modified.', 'simple-performance-for-wordpress' );

		$url = add_query_arg(
			array(
				'page' => 'spfw-settings',
				'tab'  => 'hardening',
			),
			admin_url( 'options-general.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( $url ),
			esc_html__( 'Go to the Hardening tab to restore it.', 'simple-performance-for-wordpress' )
		);
	}

	/**
	 * Write or remove the plugins/uploads hardening files when their toggles
	 * change. Also handles the root .htaccess composed block — rewrites it
	 * whenever either root toggle changes state OR when the composed content
	 * would differ (e.g. one group added while the other was already on).
	 *
	 * @param array $old_value Previous full settings array.
	 * @param array $new_value New full settings array.
	 */
	public function handle_settings_change( $old_value, $new_value ) {
		foreach ( self::HTACCESS_TARGETS as $target => $toggle ) {
			$was_on = ! empty( $old_value['hardening'][ $toggle ] );
			$is_on  = ! empty( $new_value['hardening'][ $toggle ] );

			if ( $is_on && ! $was_on ) {
				SPFW_Htaccess::write( $target );
			} elseif ( $was_on && ! $is_on ) {
				SPFW_Htaccess::remove( $target );
			}
		}

		// Root .htaccess: composed from two toggles.
		$this->handle_root_htaccess_change( $old_value, $new_value );
	}

	/**
	 * Handle root .htaccess changes. Rewrites the marker block whenever
	 * either toggle changes, or when the composed payload differs from
	 * what's on disk.
	 *
	 * @param array $old_value Previous full settings array.
	 * @param array $new_value New full settings array.
	 */
	private function handle_root_htaccess_change( $old_value, $new_value ) {
		$old_on = false;
		$new_on = false;

		foreach ( self::ROOT_TOGGLES as $toggle ) {
			if ( ! empty( $old_value['hardening'][ $toggle ] ) ) {
				$old_on = true;
			}
			if ( ! empty( $new_value['hardening'][ $toggle ] ) ) {
				$new_on = true;
			}
		}

		if ( $new_on && ! $old_on ) {
			// First enable — write and schedule self-check.
			SPFW_Htaccess::write( 'root' );
			update_option( 'spfw_root_htaccess_check', true );
		} elseif ( $old_on && ! $new_on ) {
			// All disabled — remove our block.
			SPFW_Htaccess::remove( 'root' );
		} elseif ( $new_on && $old_on ) {
			// Both were on but the combination changed (e.g. one added).
			// Rebuild the block with the new composition.
			$changed = false;

			foreach ( self::ROOT_TOGGLES as $toggle ) {
				$was = ! empty( $old_value['hardening'][ $toggle ] );
				$now = ! empty( $new_value['hardening'][ $toggle ] );

				if ( $was !== $now ) {
					$changed = true;
					break;
				}
			}

			if ( $changed ) {
				SPFW_Htaccess::write( 'root' );
				update_option( 'spfw_root_htaccess_check', true );
			}
		}
	}

	/**
	 * Safety net: after writing the root .htaccess, verify the site still
	 * responds. If a self-check request returns 500, auto-remove the block
	 * to prevent a permanent lockout.
	 */
	public function maybe_run_root_self_check() {
		if ( ! get_option( 'spfw_root_htaccess_check' ) ) {
			return;
		}

		delete_option( 'spfw_root_htaccess_check' );

		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 500 ) {
			// Site is broken — remove our block to restore access.
			SPFW_Htaccess::remove( 'root' );

			// Disable both toggles so it doesn't re-write on next save.
			SPFW_Settings::update(
				array(
					'hardening' => array(
						'protect_sensitive_files' => false,
						'block_xmlrpc_file'       => false,
					),
				)
			);

			add_action(
				'admin_notices',
				function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Simple Performance: the root .htaccess rules caused a server error and were automatically removed. Your site is back to normal.', 'simple-performance-for-wordpress' )
					);
				}
			);
		}
	}

	/**
	 * Redirect anonymous author-enumeration probes to the home page before
	 * WordPress can reveal a username via the canonical redirect.
	 */
	public function block_author_enumeration() {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		$query_string = isset( $_SERVER['QUERY_STRING'] )
			? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) )
			: '';

		$is_probe = is_author() || preg_match( '/(^|&)author=\d/i', $query_string );

		if ( $is_probe ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Remove the users sitemap provider so /wp-sitemap-users-1.xml 404s
	 * when author enumeration blocking is active.
	 *
	 * @param object|false $provider The sitemap provider object.
	 * @param string       $name     The sitemap name.
	 * @return object|false
	 */
	public function remove_users_sitemap_provider( $provider, $name ) {
		if ( 'users' === $name ) {
			return false;
		}

		return $provider;
	}

	/**
	 * Return a single generic error message for all login failures so
	 * bad-username and bad-password responses are byte-identical.
	 *
	 * @return string
	 */
	public function generic_login_error() {
		return __( 'Invalid username or password.', 'simple-performance-for-wordpress' );
	}

	/**
	 * Send a conservative set of security response headers.
	 */
	public function add_security_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Cross-Origin-Opener-Policy: same-origin' );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		header( 'X-Permitted-Cross-Domain-Policies: none' );
		header( 'Permissions-Policy: ' . $this->build_permissions_policy() );
	}

	/**
	 * Send a safe subset of security headers in wp-admin (nosniff and
	 * Referrer-Policy only). Never sends CSP or HSTS from this path.
	 */
	public function add_admin_security_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	/**
	 * Build the Permissions-Policy header value from the configured
	 * feature => allowlist map.
	 *
	 * @return string
	 */
	private function build_permissions_policy() {
		$h        = SPFW_Settings::group( 'hardening' );
		$features = isset( $h['permissions_policy'] ) && is_array( $h['permissions_policy'] )
			? $h['permissions_policy']
			: array();

		if ( empty( $features ) ) {
			return 'geolocation=(), microphone=(), camera=()';
		}

		$parts = array();

		foreach ( $features as $feature => $allowlist ) {
			if ( empty( $allowlist ) ) {
				$parts[] = $feature . '=()';
			} else {
				$parts[] = $feature . '=(' . implode( ' ', $allowlist ) . ')';
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Send the Content-Security-Policy header.
	 *
	 * Skipped for logged-in users when the exclusion toggle is on, since the
	 * block editor, customizer, and admin bar rely heavily on inline scripts a
	 * strict policy would block. Uses the report-only header while the admin is
	 * still testing so violations are logged without blocking anything.
	 *
	 * In Report-Only mode the policy also carries `report-uri`/`report-to`
	 * pointing at the plugin's violation-report endpoint, so blocked resources
	 * are collected centrally and surfaced in the admin. Enforcing (Report-Only
	 * off) sends no reporting directives — collection is a testing-phase
	 * behavior only.
	 */
	public function add_csp_header() {
		if ( headers_sent() ) {
			return;
		}

		$h = SPFW_Settings::group( 'hardening' );

		if ( ! empty( $h['csp_exclude_logged_in'] ) && is_user_logged_in() ) {
			return;
		}

		$mode = isset( $h['csp_mode'] ) ? $h['csp_mode'] : 'builder';

		if ( 'custom' === $mode ) {
			$policy = isset( $h['csp_policy'] ) ? trim( (string) $h['csp_policy'] ) : '';
		} else {
			$directives = isset( $h['csp_directives'] ) && is_array( $h['csp_directives'] ) ? $h['csp_directives'] : array();
			$policy     = self::build_policy_from_directives( $directives );
		}

		if ( '' === $policy ) {
			$policy = self::DEFAULT_CSP;
		}

		// Phase E: when script-src tightening is enabled and we have collected
		// hashes, replace 'unsafe-inline' in script-src with the hash list plus
		// 'strict-dynamic'. Hashes are stable across cache hits (unlike nonces),
		// so this is correct under full-page caching.
		if ( ! empty( $h['csp_tighten_script_src'] ) && ! empty( $h['csp_script_hashes'] ) && is_array( $h['csp_script_hashes'] ) ) {
			$policy = self::inject_script_hashes( $policy, $h['csp_script_hashes'] );
		}

		// Collect violations whenever CSP is enabled — in enforce mode too, so
		// real production breakage (a blocked resource) is still surfaced in the
		// admin, not just during Report-Only testing. Append report-uri so the
		// browser posts violations to our endpoint. We deliberately use
		// report-uri ALONE (not the newer report-to): when both are present
		// Chrome ignores report-uri and switches to the Reporting API, which
		// batches reports and delays them by up to a minute — so violations
		// appear to never arrive during interactive testing. report-uri is
		// deprecated but universally honored and fires immediately per
		// violation, which is exactly what this admin feedback loop needs.
		$report_url = self::csp_report_url();

		if ( '' !== $report_url ) {
			// When a CDN/proxy rewrites the report URL's origin so it differs
			// from the page's own origin ('self'), the browser would block the
			// report POST under connect-src. Inject the report origin into the
			// policy's connect-src so reports are never silently dropped.
			$policy = self::ensure_connect_src_allows( $policy, $report_url );

			$policy  = rtrim( $policy );
			$policy .= ( '' === $policy || ';' === substr( $policy, -1 ) ) ? '' : ';';
			$policy .= ' report-uri ' . $report_url . ';';
		}

		$header = ! empty( $h['csp_report_only'] )
			? 'Content-Security-Policy-Report-Only'
			: 'Content-Security-Policy';

		header( $header . ': ' . $policy );
	}

	/**
	 * Full URL of the CSP violation-report REST endpoint, adjusted for reverse
	 * proxies / CDNs (QUIC.cloud, Cloudflare, etc.) that terminate TLS at the
	 * edge and present a different public origin to the browser.
	 *
	 * `rest_url()` derives from the `siteurl` option, which behind a proxy may
	 * carry the wrong scheme (http vs https) or host (origin hostname vs public
	 * domain). The browser silently drops a mixed-content report-uri POST or one
	 * aimed at an unreachable host, so violations never arrive. This method
	 * rewrites the scheme and host from the same forwarded-header signals that
	 * `is_https_request()` uses, so the emitted report-uri always matches the
	 * origin the browser actually sees.
	 *
	 * @return string
	 */
	private static function csp_report_url() {
		$url = rest_url( 'spfw/v1/csp-report' );

		$origin = self::request_origin();

		if ( '' !== $origin['scheme'] && '' !== $origin['host'] ) {
			$parts = wp_parse_url( $url );

			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				// Rewrite scheme.
				$url = preg_replace( '#^https?://#', $origin['scheme'] . '://', $url, 1 );

				// Rewrite host (preserve port/path/query).
				$url = str_replace( '://' . $parts['host'], '://' . $origin['host'], $url );
			}
		}

		return esc_url_raw( $url );
	}

	/**
	 * Ensure the policy's connect-src directive allows the report endpoint's
	 * origin. When a CDN/proxy rewrites the report URL to a different origin
	 * than the page's 'self', the browser would block the violation-report POST
	 * under connect-src. This injects the report origin into connect-src (or
	 * creates the directive if absent) so reports are never silently dropped.
	 *
	 * No-op when the report origin matches the site's own origin (the common
	 * case without a proxy) or when connect-src already allows 'self' or 'https:'.
	 *
	 * @param string $policy     Policy string (may be empty).
	 * @param string $report_url Full report endpoint URL.
	 * @return string Possibly-modified policy string.
	 */
	private static function ensure_connect_src_allows( $policy, $report_url ) {
		$report_parts = wp_parse_url( $report_url );

		if ( ! is_array( $report_parts ) || empty( $report_parts['host'] ) ) {
			return $policy;
		}

		$report_origin = ( isset( $report_parts['scheme'] ) ? $report_parts['scheme'] : 'https' ) . '://' . $report_parts['host'];

		// Compare against the site's own origin (home_url).
		$home_parts = wp_parse_url( home_url() );
		$home_origin = '';

		if ( is_array( $home_parts ) && ! empty( $home_parts['host'] ) ) {
			$home_origin = ( isset( $home_parts['scheme'] ) ? $home_parts['scheme'] : 'https' ) . '://' . $home_parts['host'];
		}

		// Same origin — 'self' covers it, nothing to inject.
		if ( '' === $home_origin || strtolower( $report_origin ) === strtolower( $home_origin ) ) {
			return $policy;
		}

		// Check whether connect-src already exists in the policy.
		$directives = self::parse_policy_to_directives( $policy );

		if ( isset( $directives['connect-src'] ) ) {
			$tokens = $directives['connect-src'];

			// Already permissive enough.
			if ( in_array( "'self'", $tokens, true ) || in_array( 'https:', $tokens, true ) ) {
				return $policy;
			}

			// Already contains the report origin.
			if ( in_array( $report_origin, $tokens, true ) ) {
				return $policy;
			}

			$tokens[] = $report_origin;
			$directives['connect-src'] = $tokens;
		} else {
			// No connect-src directive — add one with 'self' + the report origin.
			$directives['connect-src'] = array( "'self'", $report_origin );
		}

		return self::build_policy_from_directives( $directives );
	}

	/**
	 * Serialize a structured directive map into a policy string.
	 *
	 * Empty directives are dropped entirely; a directive containing 'none'
	 * collapses to just 'none' (any other source there is meaningless).
	 *
	 * @param array<string,string[]> $directives Directive => list of source tokens.
	 * @return string
	 */
	public static function build_policy_from_directives( array $directives ) {
		$out = array();

		foreach ( $directives as $directive => $tokens ) {
			$directive = trim( (string) $directive );

			if ( '' === $directive || ! is_array( $tokens ) ) {
				continue;
			}

			$tokens = array_values(
				array_filter(
					array_map( 'trim', $tokens ),
					static function ( $t ) {
						return '' !== $t;
					}
				)
			);

			if ( empty( $tokens ) ) {
				continue;
			}

			if ( in_array( "'none'", $tokens, true ) ) {
				$tokens = array( "'none'" );
			}

			$out[] = $directive . ' ' . implode( ' ', $tokens );
		}

		return empty( $out ) ? '' : implode( '; ', $out ) . ';';
	}

	/**
	 * Replace 'unsafe-inline' in script-src with the collected sha256 hashes
	 * plus 'strict-dynamic'. This is the Phase E tightening step: hashes are
	 * stable across cache hits (unlike nonces), so they are correct under
	 * full-page caching.
	 *
	 * Note: 'strict-dynamic' changes how host allowlists are interpreted —
	 * once present, https: and host sources in script-src are IGNORED by
	 * supporting browsers. This is intentional: trust propagates from the
	 * hashed scripts to any scripts they load.
	 *
	 * @param string   $policy  The CSP policy string.
	 * @param string[] $hashes  Base64-encoded sha256 digests.
	 * @return string
	 */
	private static function inject_script_hashes( $policy, array $hashes ) {
		$directives = self::parse_policy_to_directives( $policy );

		if ( ! isset( $directives['script-src'] ) || ! is_array( $directives['script-src'] ) ) {
			return $policy;
		}

		$script_src = $directives['script-src'];

		// Remove 'unsafe-inline' — the hashes replace it.
		$script_src = array_filter(
			$script_src,
			static function ( $token ) {
				return "'unsafe-inline'" !== $token;
			}
		);

		// Remove host/scheme sources that 'strict-dynamic' would ignore anyway.
		// Keep 'self', 'none', and nonce/hash sources.
		$script_src = array_filter(
			$script_src,
			static function ( $token ) {
				// Keep keyword sources and existing hashes/nonces.
				if ( 0 === strpos( $token, "'" ) ) {
					return true;
				}
				// Drop bare scheme (https:) and host sources.
				return false;
			}
		);

		// Add the collected hashes.
		foreach ( $hashes as $hash ) {
			$script_src[] = "'sha256-" . $hash . "'";
		}

		// Add 'strict-dynamic' so trust propagates to scripts loaded by the
		// hashed scripts (common in analytics and tag managers).
		$script_src[] = "'strict-dynamic'";

		$directives['script-src'] = array_values( array_unique( $script_src ) );

		return self::build_policy_from_directives( $directives );
	}

	/**
	 * Parse a policy string back into a structured directive map. Best-effort:
	 * used to seed the builder from DEFAULT_CSP and to import a hand-written
	 * policy when the admin switches from Advanced (raw) back to Builder mode.
	 *
	 * @param string $policy Policy string.
	 * @return array<string,string[]>
	 */
	public static function parse_policy_to_directives( $policy ) {
		$result = array();

		foreach ( explode( ';', (string) $policy ) as $chunk ) {
			$chunk = trim( $chunk );

			if ( '' === $chunk ) {
				continue;
			}

			$parts     = preg_split( '/\s+/', $chunk );
			$directive = strtolower( array_shift( $parts ) );

			if ( '' === $directive ) {
				continue;
			}

			// Drop the reporting directives — they are managed automatically,
			// never surfaced as editable builder rows.
			if ( in_array( $directive, array( 'report-uri', 'report-to' ), true ) ) {
				continue;
			}

			$result[ $directive ] = array_values(
				array_filter(
					$parts,
					static function ( $t ) {
						return '' !== $t;
					}
				)
			);
		}

		return $result;
	}

	/**
	 * The recommended default policy expressed as a structured directive map
	 * (derived from DEFAULT_CSP so the two can never drift). Cached per request.
	 *
	 * @return array<string,string[]>
	 */
	public static function default_csp_directives() {
		static $cache = null;

		if ( null === $cache ) {
			$cache = self::parse_policy_to_directives( self::DEFAULT_CSP );
		}

		return $cache;
	}

	/**
	 * Send the Strict-Transport-Security header.
	 *
	 * Skipped entirely over plain HTTP: HSTS instructs the browser to force
	 * HTTPS for the given duration, so sending it on an HTTP response would
	 * be meaningless at best and a foot-gun at worst.
	 */
	public function add_hsts_header() {
		if ( headers_sent() || ! self::is_https_request() ) {
			return;
		}

		$h = SPFW_Settings::group( 'hardening' );

		$max_age = isset( $h['hsts_max_age'] ) ? absint( $h['hsts_max_age'] ) : 31536000;
		$value   = 'max-age=' . $max_age;

		if ( ! empty( $h['hsts_include_subdomains'] ) ) {
			$value .= '; includeSubDomains';
		}

		if ( ! empty( $h['hsts_preload'] ) ) {
			$value .= '; preload';
		}

		header( 'Strict-Transport-Security: ' . $value );
	}

	/**
	 * Whether the current request is HTTPS, including behind a reverse proxy
	 * (QUIC.cloud, Cloudflare, etc.) that terminates TLS at the edge — where
	 * is_ssl() alone sees only the plain-HTTP connection to the origin and
	 * would otherwise never let HSTS fire on a proxied site.
	 *
	 * @return bool
	 */
	private static function is_https_request() {
		return 'https' === self::request_origin()['scheme'];
	}

	/**
	 * Determine the scheme and host the browser sees, accounting for reverse
	 * proxies / CDNs (QUIC.cloud, Cloudflare, etc.) that terminate TLS at the
	 * edge and forward the original request details via standard headers.
	 *
	 * Returns the best-known {scheme, host} pair. When no proxy headers are
	 * present the values come from the direct connection (is_ssl() for scheme,
	 * HTTP_HOST for host), so non-proxied sites are unaffected.
	 *
	 * @return array{scheme:string,host:string}
	 */
	private static function request_origin() {
		// --- Scheme ---
		$scheme = is_ssl() ? 'https' : 'http';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$proto = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) );
			// X-Forwarded-Proto may be comma-separated (first = original client).
			$proto  = trim( explode( ',', $proto )[0] );
			$scheme = in_array( $proto, array( 'https', 'http' ), true ) ? $proto : $scheme;
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) {
			$ssl = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) );
			if ( in_array( $ssl, array( 'on', '1' ), true ) ) {
				$scheme = 'https';
			}
		} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_PORT'] ) && '443' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PORT'] ) ) ) {
			$scheme = 'https';
		}

		// --- Host ---
		$host = '';

		if ( isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
			// X-Forwarded-Host may be comma-separated (first = original client).
			$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) );
			$host = trim( explode( ',', $host )[0] );
		} elseif ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		}

		// Strip any port suffix from the host (e.g. "example.com:8080").
		$host = preg_replace( '/:\d+$/', '', $host );

		return array(
			'scheme' => $scheme,
			'host'   => $host,
		);
	}

	/**
	 * Remove pingback-related methods from the XML-RPC method list.
	 *
	 * @param array $methods XML-RPC methods.
	 * @return array
	 */
	public function strip_pingback_methods( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

		return $methods;
	}

	/**
	 * Remove the X-Pingback header from front-end responses.
	 *
	 * @param array $headers WordPress headers.
	 * @return array
	 */
	public function strip_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );

		return $headers;
	}
}
