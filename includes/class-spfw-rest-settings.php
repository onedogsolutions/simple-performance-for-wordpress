<?php
/**
 * REST API controller for the plugin's own settings.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes GET/POST /spfw/v1/settings for the React admin app. Must load
 * unconditionally (REST requests are not admin context, so `rest_api_init`
 * never fires if this were only required inside an is_admin() branch).
 */
class SPFW_Rest_Settings {

	const NAMESPACE_ = 'spfw/v1';

	/**
	 * Register REST hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the settings routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/settings/restore-htaccess',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_htaccess' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/settings/scan-fonts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_fonts' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// CSP violation reports. The POST route is intentionally public —
		// browsers send violation reports unauthenticated — but its callback
		// stores nothing unless CSP is enabled, so the endpoint is effectively
		// closed whenever CSP is off. GET/DELETE are admin-only (view / clear
		// the collected log).
		register_rest_route(
			self::NAMESPACE_,
			'/csp-report',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_csp_report' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_csp_reports' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_csp_reports' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);
	}

	/**
	 * Capability check shared by both routes.
	 *
	 * @return bool
	 */
	public function check_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET callback: current settings plus computed, read-only status
	 * fields the React app needs without a second round trip.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		$settings                             = SPFW_Settings::get();
		$settings['hardening_status']         = SPFW_Htaccess::status( 'plugins' );
		$settings['uploads_hardening_status'] = SPFW_Htaccess::status( 'uploads' );
		$settings['csp_default']              = SPFW_Module_Hardening::DEFAULT_CSP;
		$settings['csp_default_directives']   = SPFW_Module_Hardening::default_csp_directives();
		$settings['csp_reports']              = self::get_csp_reports();

		return new WP_REST_Response( $settings, 200 );
	}

	/**
	 * POST callback: persist submitted settings (sanitized internally by
	 * SPFW_Settings::update(), which ignores unknown top-level keys such
	 * as the computed hardening_status echoed back by get_settings())
	 * and return the refreshed state.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$params = $request->get_json_params();

		SPFW_Settings::update( is_array( $params ) ? $params : array() );

		// Many toggles alter cached front-end HTML (head links, favicon,
		// Google Maps, WooCommerce assets), so purge LiteSpeed Cache. Harmless
		// no-op when LSCache is not installed (no listeners on the action).
		do_action( 'litespeed_purge_all' );

		return $this->get_settings();
	}

	/**
	 * POST callback: rewrite a hardening file (plugins or uploads) and
	 * return the refreshed state (used by the Hardening tab's Restore
	 * button — no page reload needed). Surfaces a 500 when the write fails
	 * (e.g. the server has no direct filesystem write access) so the button
	 * shows an actionable error instead of a false success.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function restore_htaccess( $request ) {
		$params = $request->get_json_params();
		$target = ( is_array( $params ) && isset( $params['target'] ) && 'uploads' === $params['target'] )
			? 'uploads'
			: 'plugins';

		if ( ! SPFW_Htaccess::write( $target ) ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Could not write the hardening file. The web server may not have direct write access to this directory.', 'simple-performance-for-wordpress' ),
				),
				500
			);
		}

		return $this->get_settings();
	}

	/**
	 * POST callback: run the Google Fonts discovery/download scan and
	 * return the refreshed state plus a `scan_result` summary (families,
	 * file count, and a human-readable message) so the React tab can show a
	 * "no fonts found" state without a second round trip.
	 *
	 * @return WP_REST_Response
	 */
	public function scan_fonts() {
		$result = ( new SPFW_Module_Fonts() )->scan();

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}

		$response                = $this->get_settings();
		$data                    = $response->get_data();
		$data['scan_result']     = $result;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Transient key and limits for the collected violation log.
	 */
	const CSP_REPORTS_KEY = 'spfw_csp_reports';
	const CSP_REPORTS_MAX = 50;
	const CSP_REPORTS_TTL = 604800; // 7 days.

	/**
	 * Public POST callback: ingest a browser CSP violation report.
	 *
	 * Closed unless CSP is enabled, so the endpoint accepts (and stores)
	 * reports whenever a policy is active (Report-Only or enforce). Accepts both
	 * the legacy `application/csp-report` body and the modern Reporting API
	 * `application/reports+json` batch, caps the body size, dedupes into a
	 * bounded transient, and always answers 204 (browsers ignore the response).
	 *
	 * Sends explicit no-store headers so CDNs (QUIC.cloud, Cloudflare) and
	 * page-cache plugins never cache the 204/403 response — a cached 403 from
	 * a moment when CSP was briefly off would silently swallow all subsequent
	 * reports until the CDN cache expires.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function receive_csp_report( $request ) {
		// Prevent CDN / page-cache from caching this endpoint's response.
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'X-Robots-Tag: noindex, noarchive' );
		}

		$h = SPFW_Settings::group( 'hardening' );

		// Open whenever CSP is enabled (enforce mode included), so real blocked
		// resources are captured, not only Report-Only test violations. Closed
		// entirely when CSP is off.
		if ( empty( $h['csp_enabled'] ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$body = $request->get_body();

		// Ignore anything implausible for a violation report rather than error.
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > 8192 ) {
			return new WP_REST_Response( null, 204 );
		}

		$data = json_decode( $body, true );

		if ( is_array( $data ) ) {
			$violations = self::extract_violations( $data );

			if ( ! empty( $violations ) ) {
				self::store_violations( $violations );
			}
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Admin GET callback: the aggregated violation log.
	 *
	 * @return WP_REST_Response
	 */
	public function list_csp_reports() {
		return new WP_REST_Response( array( 'csp_reports' => self::get_csp_reports() ), 200 );
	}

	/**
	 * Admin DELETE callback: clear the collected violation log.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_csp_reports() {
		delete_transient( self::CSP_REPORTS_KEY );

		return new WP_REST_Response( array( 'csp_reports' => array() ), 200 );
	}

	/**
	 * Read the aggregated violation log, newest activity first.
	 *
	 * @return array[]
	 */
	public static function get_csp_reports() {
		$store = get_transient( self::CSP_REPORTS_KEY );

		if ( ! is_array( $store ) ) {
			return array();
		}

		$store = array_values( $store );

		usort(
			$store,
			static function ( $a, $b ) {
				return ( isset( $b['last_seen'] ) ? $b['last_seen'] : 0 ) <=> ( isset( $a['last_seen'] ) ? $a['last_seen'] : 0 );
			}
		);

		return $store;
	}

	/**
	 * Normalize one or many CSP violation reports (legacy or Reporting API
	 * shape) into a flat list of {directive, blocked_uri, document_uri}.
	 *
	 * @param array $data Decoded JSON body.
	 * @return array[]
	 */
	private static function extract_violations( array $data ) {
		$out = array();

		// Legacy application/csp-report: { "csp-report": { ... } }.
		if ( isset( $data['csp-report'] ) && is_array( $data['csp-report'] ) ) {
			$r     = $data['csp-report'];
			$out[] = array(
				'directive'    => isset( $r['effective-directive'] ) ? $r['effective-directive'] : ( isset( $r['violated-directive'] ) ? $r['violated-directive'] : '' ),
				'blocked_uri'  => isset( $r['blocked-uri'] ) ? $r['blocked-uri'] : '',
				'document_uri' => isset( $r['document-uri'] ) ? $r['document-uri'] : '',
			);
		}

		// Modern application/reports+json: [ { "type":"csp-violation", "body": {...} }, ... ].
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			foreach ( $data as $report ) {
				if ( ! is_array( $report ) ) {
					continue;
				}

				if ( isset( $report['type'] ) && 'csp-violation' !== $report['type'] ) {
					continue;
				}

				$b = isset( $report['body'] ) && is_array( $report['body'] ) ? $report['body'] : array();

				$out[] = array(
					'directive'    => isset( $b['effectiveDirective'] ) ? $b['effectiveDirective'] : ( isset( $b['effective-directive'] ) ? $b['effective-directive'] : '' ),
					'blocked_uri'  => isset( $b['blockedURL'] ) ? $b['blockedURL'] : ( isset( $b['blocked-uri'] ) ? $b['blocked-uri'] : '' ),
					'document_uri' => isset( $b['documentURL'] ) ? $b['documentURL'] : ( isset( $b['document-uri'] ) ? $b['document-uri'] : '' ),
				);
			}
		}

		return $out;
	}

	/**
	 * Merge violations into the bounded transient, deduping by
	 * (directive, blocked-origin) and bumping counts instead of appending.
	 *
	 * @param array[] $violations Normalized violations.
	 */
	private static function store_violations( array $violations ) {
		$store = get_transient( self::CSP_REPORTS_KEY );
		$store = is_array( $store ) ? $store : array();
		$now   = time();

		foreach ( $violations as $v ) {
			$directive = self::normalize_directive( $v['directive'] );

			if ( '' === $directive ) {
				continue;
			}

			$blocked = sanitize_text_field( (string) $v['blocked_uri'] );
			$blocked = '' === $blocked ? 'inline' : substr( $blocked, 0, 200 );
			$origin  = self::blocked_origin( $blocked );
			$key     = $directive . '|' . $origin;

			if ( isset( $store[ $key ] ) ) {
				$store[ $key ]['count']     = (int) $store[ $key ]['count'] + 1;
				$store[ $key ]['last_seen'] = $now;
				continue;
			}

			// Evict the least-recently-seen entry when full.
			if ( count( $store ) >= self::CSP_REPORTS_MAX ) {
				$oldest_key  = null;
				$oldest_seen = PHP_INT_MAX;
				foreach ( $store as $k => $entry ) {
					$seen = isset( $entry['last_seen'] ) ? $entry['last_seen'] : 0;
					if ( $seen < $oldest_seen ) {
						$oldest_seen = $seen;
						$oldest_key  = $k;
					}
				}
				if ( null !== $oldest_key ) {
					unset( $store[ $oldest_key ] );
				}
			}

			$store[ $key ] = array(
				'directive'      => $directive,
				'blocked_uri'    => $blocked,
				'blocked_origin' => $origin,
				'document_uri'   => substr( sanitize_text_field( (string) $v['document_uri'] ), 0, 200 ),
				'count'          => 1,
				'first_seen'     => $now,
				'last_seen'      => $now,
			);
		}

		set_transient( self::CSP_REPORTS_KEY, $store, self::CSP_REPORTS_TTL );
	}

	/**
	 * Effective-directive fallback aliases: browsers report the most specific
	 * directive that actually blocked the resource (e.g. `script-src-elem` for
	 * an inline/external <script> tag), which falls back to the coarser
	 * directive the builder exposes as a row. Collapsed here so violations
	 * group under — and "Allow" writes to — the directive the policy actually
	 * emits, instead of being orphaned into the "other" bucket.
	 *
	 * @var array<string,string>
	 */
	const DIRECTIVE_ALIASES = array(
		'script-src-elem' => 'script-src',
		'script-src-attr' => 'script-src',
		'style-src-elem'  => 'style-src',
		'style-src-attr'  => 'style-src',
	);

	/**
	 * Reduce a reported directive to its bare name (browsers sometimes send
	 * "script-src https://x" as violated-directive), then collapse a granular
	 * effective-directive fallback (script-src-elem, etc.) to its base directive.
	 *
	 * @param string $directive Raw directive value.
	 * @return string
	 */
	private static function normalize_directive( $directive ) {
		$directive = strtolower( trim( (string) $directive ) );
		$directive = preg_split( '/\s+/', $directive )[0];

		if ( ! preg_match( '/^[a-z-]{1,40}$/', $directive ) ) {
			return '';
		}

		return isset( self::DIRECTIVE_ALIASES[ $directive ] ) ? self::DIRECTIVE_ALIASES[ $directive ] : $directive;
	}

	/**
	 * The origin (scheme://host) of a blocked URI, used as the dedup key and as
	 * the value the admin's "Allow" action adds to a directive. Keyword blocks
	 * ('inline', 'eval', 'data') have no host and are returned as-is.
	 *
	 * @param string $uri Blocked URI or keyword.
	 * @return string
	 */
	private static function blocked_origin( $uri ) {
		$uri   = (string) $uri;
		$parts = wp_parse_url( $uri );

		if ( ! empty( $parts['host'] ) ) {
			$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
			return $scheme . $parts['host'];
		}

		return substr( $uri, 0, 60 );
	}
}
