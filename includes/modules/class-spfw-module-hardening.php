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
		}

		// Emit conservative security response headers on front-end / REST
		// responses (send_headers does not fire in wp-admin).
		if ( ! empty( $h['security_headers'] ) ) {
			add_action( 'send_headers', array( $this, 'add_security_headers' ) );
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
	}

	/**
	 * Queue an admin notice if any managed hardening file is missing or
	 * altered.
	 */
	public function maybe_show_notice() {
		foreach ( array_keys( self::HTACCESS_TARGETS ) as $target ) {
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
	 * change.
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
	 * Send a conservative set of security response headers.
	 */
	public function add_security_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
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
	 * Full URL of the CSP violation-report REST endpoint.
	 *
	 * @return string
	 */
	private static function csp_report_url() {
		return esc_url_raw( rest_url( 'spfw/v1/csp-report' ) );
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
		if ( is_ssl() ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) ) ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_FORWARDED_PORT'] ) && '443' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PORT'] ) ) ) {
			return true;
		}

		return false;
	}
}
