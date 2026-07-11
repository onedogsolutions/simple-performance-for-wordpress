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
	const DEFAULT_CSP = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:; connect-src 'self'; media-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self';";

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
	 */
	public function add_csp_header() {
		if ( headers_sent() ) {
			return;
		}

		$h = SPFW_Settings::group( 'hardening' );

		if ( ! empty( $h['csp_exclude_logged_in'] ) && is_user_logged_in() ) {
			return;
		}

		$policy = isset( $h['csp_policy'] ) ? trim( (string) $h['csp_policy'] ) : '';

		if ( '' === $policy ) {
			$policy = self::DEFAULT_CSP;
		}

		$header = ! empty( $h['csp_report_only'] )
			? 'Content-Security-Policy-Report-Only'
			: 'Content-Security-Policy';

		header( $header . ': ' . $policy );
	}
}
