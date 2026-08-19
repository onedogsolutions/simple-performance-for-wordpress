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
		// the collected log). OPTIONS is registered explicitly so CORS
		// preflights (which arrive when a CDN rewrites the report URI to a
		// different origin) are answered before WordPress can 404 them.
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
					// CORS preflight. The callback emits the right headers and
					// returns 204; the real payload arrives as a POST.
					'methods'             => 'OPTIONS',
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
					'args'                => array(
						'directive' => array(
							'type'     => 'string',
							'required' => false,
						),
						'origin'    => array(
							'type'     => 'string',
							'required' => false,
						),
					),
				),
			)
		);

		// Open / close the violation-collection window. A dedicated route
		// rather than a plain setting so the deadline is computed from server
		// time (the browser's clock may be minutes off, and a skewed deadline
		// either closes collection early or leaves it open too long).
		register_rest_route(
			self::NAMESPACE_,
			'/csp-report/collect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_csp_collection' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Settings export / import (D2).
		register_rest_route(
			self::NAMESPACE_,
			'/settings/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_settings' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/settings/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_settings' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Configuration presets (D3).
		register_rest_route(
			self::NAMESPACE_,
			'/settings/presets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_presets' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_preset' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// CSP script hash scan (Phase E).
		register_rest_route(
			self::NAMESPACE_,
			'/settings/scan-script-hashes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_script_hashes' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Database scan: count items matching each cleanup target.
		register_rest_route(
			self::NAMESPACE_,
			'/settings/database-scan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'database_scan' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Database optimize: run cleanup on the requested targets.
		register_rest_route(
			self::NAMESPACE_,
			'/settings/database-optimize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'database_optimize' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// File integrity scan: on-demand scan of wp-content for PHP file
		// changes (added / modified / removed since last snapshot).
		register_rest_route(
			self::NAMESPACE_,
			'/settings/scan-files',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_files' ),
				'permission_callback' => array( $this, 'check_permissions' ),
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
		$settings['root_hardening_status']    = SPFW_Htaccess::status( 'root' );
		$settings['csp_default']              = SPFW_Module_Hardening::DEFAULT_CSP;
		$settings['csp_default_directives']   = SPFW_Module_Hardening::default_csp_directives();
		$settings['csp_reports']              = self::get_csp_reports();
		$settings['csp_report_stats']         = self::get_csp_report_stats();
		// Admin email for the file monitor placeholder (not stored in settings).
		$settings['admin_email']              = get_option( 'admin_email', '' );
		// The policy string as the front-end header will actually carry it
		// (including report-uri when collecting), so the admin can compare
		// directly against DevTools without guessing.
		$settings['csp_emitted_policy']       = SPFW_Module_Hardening::get_emitted_policy_preview();
		// File monitor metadata for the Hardening tab UI.
		$settings['file_monitor_last_scan']      = SPFW_Settings::value( 'hardening', 'file_monitor_last_scan', 0 );
		$fm_snapshot                             = SPFW_Settings::value( 'hardening', 'file_monitor_snapshot', array() );
		$settings['file_monitor_snapshot_count'] = is_array( $fm_snapshot ) ? count( $fm_snapshot ) : 0;

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
	const CSP_REPORTS_MAX = 100;
	const CSP_REPORTS_TTL = 604800; // 7 days.

	/**
	 * Largest body we will parse. Generous enough for a batched Reporting API
	 * payload (the old 8 KB cap silently discarded whole batches), still
	 * bounded so the endpoint can't be used to make us parse megabytes.
	 */
	const CSP_BODY_MAX = 32768;

	/**
	 * Minimum seconds between persisted count updates. Repeat sightings of a
	 * violation we already know about are worth very little — the admin needs
	 * to know *that* it happens, not the exact number — so bumping a counter is
	 * not worth an option write per request. A newly-seen violation always
	 * writes immediately; everything else coalesces into this interval, which
	 * caps writes at ~12/minute regardless of how hard the endpoint is driven.
	 *
	 * Counts are therefore a lower bound ("recorded occurrences"), not an exact
	 * tally. They were never exact anyway: the previous unlocked
	 * read-modify-write lost over half its increments under concurrency.
	 */
	const CSP_WRITE_INTERVAL = 5;

	/**
	 * Default ceiling on how many *new* (directive, origin) pairs may enter the
	 * log per minute. The endpoint is unauthenticated by necessity — browsers
	 * cannot authenticate a violation report — so without this an anonymous
	 * flood of invented origins evicts the entire real log in one burst and
	 * replaces it with attacker-chosen entries. Admins on tracker-heavy sites
	 * may raise this via the `csp_rate_limit` setting (capped at 60).
	 */
	const CSP_NEW_PER_MINUTE_DEFAULT = 10;

	/**
	 * Object-cache key used to serialize read-modify-write of the log.
	 */
	const CSP_LOCK_KEY   = 'spfw_csp_lock';
	const CSP_LOCK_GROUP = 'spfw';

	/**
	 * Public POST callback: ingest a browser CSP violation report.
	 *
	 * Open only while CSP is enabled AND the admin has an explicit collection
	 * window open (see SPFW_Module_Hardening::collection_open()); otherwise it
	 * answers 403 before doing any work. Accepts both the legacy
	 * `application/csp-report` body and the modern Reporting API
	 * `application/reports+json` batch, caps the body size, dedupes into a
	 * bounded transient, and always answers 204 (browsers ignore the response).
	 *
	 * Everything here runs on unauthenticated input on a public endpoint, so it
	 * is deliberately cheap and bounded: no write at all outside the window, at
	 * most one write per CSP_WRITE_INTERVAL for repeat violations, and a
	 * per-minute ceiling on new entries.
	 *
	 * Sends explicit no-store headers so CDNs (QUIC.cloud, Cloudflare) and
	 * page-cache plugins never cache the 204/403 response — a cached 403 from
	 * a moment when collection was closed would silently swallow all subsequent
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

			// CORS: browsers will make a cross-origin POST when a CDN or proxy
			// rewrites the report URI to a different origin than the page. Without
			// this header the preflight or POST itself is blocked and reports are
			// silently dropped.
			$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
			if ( '' !== $origin ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
				header( 'Access-Control-Allow-Headers: Content-Type' );
				header( 'Vary: Origin' );
			}
		}

		// Respond to CORS preflight immediately.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$h = SPFW_Settings::group( 'hardening' );

		// Fully closed unless CSP is on and a collection window is open. Bail
		// before touching the body so a closed endpoint costs nothing beyond
		// the settings read WordPress has already cached.
		if ( empty( $h['csp_enabled'] ) || ! SPFW_Module_Hardening::collection_open( $h ) ) {
			return new WP_REST_Response( null, 403 );
		}

		$body = $request->get_body();

		// Ignore anything implausible for a violation report rather than error.
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::CSP_BODY_MAX ) {
			return new WP_REST_Response( null, 204 );
		}

		$data = json_decode( $body, true );

		if ( is_array( $data ) ) {
			$violations = self::extract_violations( $data );

			if ( ! empty( $violations ) ) {
				self::store_violations( $violations, $h );
			}
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * POST callback: open or close the violation-collection window.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function set_csp_collection( $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$stop  = isset( $params['action'] ) && 'stop' === $params['action'];
		$hours = isset( $params['hours'] ) ? absint( $params['hours'] ) : 24;
		$hours = min( 168, max( 1, $hours ) );

		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_collect_until' => $stop ? 0 : time() + ( $hours * HOUR_IN_SECONDS ),
				),
			)
		);

		// The reporting directive lives in a response header, which full-page
		// caches store alongside the body — without a purge, cached pages would
		// keep advertising (or keep omitting) report-uri for the cache TTL.
		do_action( 'litespeed_purge_all' );

		return $this->get_settings();
	}

	/**
	 * Admin GET callback: the aggregated violation log.
	 *
	 * @return WP_REST_Response
	 */
	public function list_csp_reports() {
		return new WP_REST_Response(
			array(
				'csp_reports'      => self::get_csp_reports(),
				'csp_report_stats' => self::get_csp_report_stats(),
			),
			200
		);
	}

	/**
	 * Admin DELETE callback: clear the collected violation log, or drop a
	 * single entry when a directive/origin pair is supplied (used by the
	 * admin's "Allow" action, which has just written that origin into the
	 * policy and so no longer needs it listed as outstanding).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function clear_csp_reports( $request ) {
		$directive = (string) $request->get_param( 'directive' );
		$origin    = (string) $request->get_param( 'origin' );

		if ( '' === $directive || '' === $origin ) {
			delete_transient( self::CSP_REPORTS_KEY );

			return new WP_REST_Response( array( 'csp_reports' => array() ), 200 );
		}

		$held  = self::acquire_lock();
		$store = self::read_store();

		unset( $store['items'][ $directive . '|' . $origin ] );

		self::write_store( $store );
		self::release_lock( $held );

		return new WP_REST_Response( array( 'csp_reports' => self::get_csp_reports() ), 200 );
	}

	/**
	 * Read the aggregated violation log, most frequent first.
	 *
	 * Ordered by count (then recency) rather than recency alone so the entries
	 * that matter most sit at the top, and so the order matches the eviction
	 * policy — what the admin sees first is what survives longest.
	 *
	 * @return array[]
	 */
	public static function get_csp_reports() {
		$items = array_values( self::read_store()['items'] );

		usort(
			$items,
			static function ( $a, $b ) {
				$a_count = isset( $a['count'] ) ? (int) $a['count'] : 0;
				$b_count = isset( $b['count'] ) ? (int) $b['count'] : 0;

				if ( $a_count !== $b_count ) {
					return $b_count <=> $a_count;
				}

				return ( isset( $b['last_seen'] ) ? $b['last_seen'] : 0 ) <=> ( isset( $a['last_seen'] ) ? $a['last_seen'] : 0 );
			}
		);

		return $items;
	}

	/**
	 * Collection state for the admin card: enough to tell "collection is off"
	 * from "collection is on but nothing is arriving" without guessing.
	 *
	 * Returns extra diagnostic fields so the admin can see exactly why reports
	 * may not be arriving (wrong report URI, sampling too low, log full, etc.).
	 *
	 * @return array
	 */
	public static function get_csp_report_stats() {
		$h     = SPFW_Settings::group( 'hardening' );
		$store = self::read_store();

		$recorded = 0;
		foreach ( $store['items'] as $entry ) {
			$recorded += isset( $entry['count'] ) ? (int) $entry['count'] : 0;
		}

		$last_write = (int) $store['meta']['last_write'];
		$now        = time();

		// Build the report_uri the same way add_csp_header() does so the admin
		// can compare it to the header value shown in DevTools.
		$report_uri = SPFW_Module_Hardening::collection_open( $h )
			? SPFW_Module_Hardening::csp_report_url_public()
			: '';

		return array(
			'collecting'       => SPFW_Module_Hardening::collection_open( $h ),
			'collect_until'    => isset( $h['csp_collect_until'] ) ? (int) $h['csp_collect_until'] : 0,
			'now'              => $now,
			'entries'          => count( $store['items'] ),
			'recorded'         => $recorded,
			'last_report'      => $last_write,
			'last_report_age'  => $last_write > 0 ? max( 0, $now - $last_write ) : -1,
			'dropped'          => (int) $store['meta']['dropped'],
			'full'             => count( $store['items'] ) >= self::CSP_REPORTS_MAX,
			'report_uri'       => $report_uri,
			'sampling'         => isset( $h['csp_collect_sample'] ) ? (int) $h['csp_collect_sample'] : 100,
			'rate_limit'       => isset( $h['csp_rate_limit'] ) ? (int) $h['csp_rate_limit'] : self::CSP_NEW_PER_MINUTE_DEFAULT,
		);
	}

	/**
	 * Default metadata envelope stored alongside the violation entries.
	 *
	 * @return array
	 */
	private static function default_meta() {
		return array(
			'last_write' => 0,
			'minute'     => 0,
			'new_keys'   => 0,
			'dropped'    => 0,
		);
	}

	/**
	 * Read the violation log as a { items, meta } envelope.
	 *
	 * Transparently upgrades the pre-2.1.0 shape, which stored the entry map
	 * directly with no envelope. Entry keys are always "directive|origin", so a
	 * legacy map can never contain an 'items' key — the shape test is safe.
	 *
	 * @return array
	 */
	private static function read_store() {
		$raw = get_transient( self::CSP_REPORTS_KEY );

		if ( ! is_array( $raw ) ) {
			return array(
				'items' => array(),
				'meta'  => self::default_meta(),
			);
		}

		if ( ! isset( $raw['items'] ) || ! is_array( $raw['items'] ) ) {
			return array(
				'items' => $raw,
				'meta'  => self::default_meta(),
			);
		}

		return array(
			'items' => $raw['items'],
			'meta'  => isset( $raw['meta'] ) && is_array( $raw['meta'] )
				? array_merge( self::default_meta(), $raw['meta'] )
				: self::default_meta(),
		);
	}

	/**
	 * Persist the violation log envelope.
	 *
	 * @param array $store { items, meta } envelope.
	 */
	private static function write_store( array $store ) {
		$store['meta']['last_write'] = time();

		set_transient( self::CSP_REPORTS_KEY, $store, self::CSP_REPORTS_TTL );
	}

	/**
	 * Take a short lock around the log's read-modify-write.
	 *
	 * Without this, concurrent reports each read the same log, apply their own
	 * increment, and write it back — so all but one increment is discarded. A
	 * 150-request burst against the unlocked version recorded 70.
	 *
	 * wp_cache_add() is atomic against a persistent object cache (Redis /
	 * Memcached / LSMCD), which is what the busy sites this protects are
	 * running. With only the default in-memory cache there is nothing shared to
	 * contend on and this degrades to a no-op — no worse than before, and the
	 * write-coalescing above still bounds the damage. Never blocks the response
	 * for long: a handful of short spins, then proceed regardless.
	 *
	 * @return bool Whether the lock is held (and so must be released).
	 */
	private static function acquire_lock() {
		for ( $i = 0; $i < 5; $i++ ) {
			if ( wp_cache_add( self::CSP_LOCK_KEY, 1, self::CSP_LOCK_GROUP, 10 ) ) {
				return true;
			}

			usleep( 5000 );
		}

		return false;
	}

	/**
	 * Release the lock taken by acquire_lock().
	 *
	 * @param bool $held Whether the lock was actually acquired.
	 */
	private static function release_lock( $held ) {
		if ( $held ) {
			wp_cache_delete( self::CSP_LOCK_KEY, self::CSP_LOCK_GROUP );
		}
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
	 * Runs under a lock (see acquire_lock()) and only persists when it has
	 * something worth persisting: a violation the admin has not seen before, or
	 * a repeat sighting once the coalescing interval has elapsed.
	 *
	 * @param array[] $violations Normalized violations.
	 * @param array   $h          Hardening settings group.
	 */
	private static function store_violations( array $violations, array $h ) {
		$held  = self::acquire_lock();
		$store = self::read_store();
		$items = $store['items'];
		$meta  = $store['meta'];

		$now    = time();
		$minute = (int) floor( $now / 60 );

		// New rate-limit bucket.
		if ( (int) $meta['minute'] !== $minute ) {
			$meta['minute']   = $minute;
			$meta['new_keys'] = 0;
		}

		$has_new = false;

		foreach ( $violations as $v ) {
			$directive = self::normalize_directive( $v['directive'] );

			if ( '' === $directive ) {
				continue;
			}

			$blocked = sanitize_text_field( (string) $v['blocked_uri'] );
			$blocked = '' === $blocked ? 'inline' : substr( $blocked, 0, 200 );
			$origin  = self::blocked_origin( $blocked );
			$key     = $directive . '|' . $origin;

			if ( isset( $items[ $key ] ) ) {
				$items[ $key ]['count']     = (int) $items[ $key ]['count'] + 1;
				$items[ $key ]['last_seen'] = $now;
				continue;
			}

			// Already permitted by the live policy: a stale report from a page
			// the visitor had cached (or from before the admin pressed Allow).
			// Re-listing it would put an entry the admin has already actioned
			// straight back into their outstanding list.
			if ( self::origin_already_allowed( $h, $directive, $origin ) ) {
				continue;
			}

			// Bound how fast unauthenticated input can introduce new entries,
			// so a flood of invented origins cannot evict the real log. The admin
			// may raise the ceiling for tracker-heavy sites via csp_rate_limit.
			$rate_limit = isset( $h['csp_rate_limit'] ) ? (int) $h['csp_rate_limit'] : self::CSP_NEW_PER_MINUTE_DEFAULT;
			if ( $meta['new_keys'] >= $rate_limit ) {
				++$meta['dropped'];
				continue;
			}

			if ( count( $items ) >= self::CSP_REPORTS_MAX ) {
				self::evict_one( $items );
			}

			$items[ $key ] = array(
				'directive'      => $directive,
				'blocked_uri'    => $blocked,
				'blocked_origin' => $origin,
				'document_uri'   => substr( sanitize_text_field( (string) $v['document_uri'] ), 0, 200 ),
				'count'          => 1,
				'first_seen'     => $now,
				'last_seen'      => $now,
			);

			++$meta['new_keys'];
			$has_new = true;
		}

		// Coalesce repeat sightings: a counter bump is not worth an option
		// write on every request from every visitor.
		if ( $has_new || ( $now - (int) $meta['last_write'] ) >= self::CSP_WRITE_INTERVAL ) {
			self::write_store(
				array(
					'items' => $items,
					'meta'  => $meta,
				)
			);
		}

		self::release_lock( $held );
	}

	/**
	 * Drop one entry to make room, choosing the least-reported one (oldest
	 * sighting breaks ties).
	 *
	 * Deliberately not "least recently seen": that let a burst of one-off
	 * reports push out the established, high-count violations the admin
	 * actually needs to act on — which is exactly what an anonymous flood of
	 * invented origins produces.
	 *
	 * @param array $items Entry map, modified in place.
	 */
	private static function evict_one( array &$items ) {
		$victim_key   = null;
		$victim_count = PHP_INT_MAX;
		$victim_seen  = PHP_INT_MAX;

		foreach ( $items as $k => $entry ) {
			$count = isset( $entry['count'] ) ? (int) $entry['count'] : 0;
			$seen  = isset( $entry['last_seen'] ) ? (int) $entry['last_seen'] : 0;

			if ( $count < $victim_count || ( $count === $victim_count && $seen < $victim_seen ) ) {
				$victim_key   = $k;
				$victim_count = $count;
				$victim_seen  = $seen;
			}
		}

		if ( null !== $victim_key ) {
			unset( $items[ $victim_key ] );
		}
	}

	/**
	 * Keyword blocked-uri values mapped to the CSP source token that permits
	 * them. Mirrors KEYWORD_TOKENS in CspPolicyCard.jsx — the admin's "Allow"
	 * writes the token, this reads it back.
	 *
	 * @var array<string,string>
	 */
	const KEYWORD_TOKENS = array(
		'inline'      => "'unsafe-inline'",
		'eval'        => "'unsafe-eval'",
		'data'        => 'data:',
		'blob'        => 'blob:',
		'filesystem'  => 'filesystem:',
		'mediastream' => 'mediastream:',
	);

	/**
	 * Whether the live policy already permits this origin for this directive.
	 *
	 * Builder mode only: in raw-policy mode there is no structured directive
	 * map to consult, and parsing the hand-written string on every report would
	 * put real work back on the public path we are trying to keep cheap.
	 *
	 * Checks are performed in this order:
	 *  1. Keyword token mapping (inline → 'unsafe-inline', etc.).
	 *  2. Exact match against stored tokens.
	 *  3. Scheme-source coverage (https:, wss:, data:, blob: cover any matching
	 *     origin with that scheme).
	 *  4. Host-token normalization — https://example.com stored token covers a
	 *     reported origin of https://example.com, and a bare example.com token
	 *     covers the same.
	 *  5. Fallback to default-src when the directive has no explicit tokens.
	 *
	 * @param array  $h         Hardening settings group.
	 * @param string $directive Normalized directive name.
	 * @param string $origin    Blocked origin or keyword (scheme://host or bare word).
	 * @return bool
	 */
	private static function origin_already_allowed( array $h, $directive, $origin ) {
		if ( isset( $h['csp_mode'] ) && 'custom' === $h['csp_mode'] ) {
			return false;
		}

		$all_directives = isset( $h['csp_directives'] ) && is_array( $h['csp_directives'] ) ? $h['csp_directives'] : array();

		// Collect the token lists to check: the specific directive first, then
		// fall back to default-src if the directive has no explicit tokens.
		$candidates = array();

		if ( isset( $all_directives[ $directive ] ) && is_array( $all_directives[ $directive ] ) && ! empty( $all_directives[ $directive ] ) ) {
			$candidates[] = $all_directives[ $directive ];
		} elseif ( isset( $all_directives['default-src'] ) && is_array( $all_directives['default-src'] ) ) {
			// No explicit directive — browsers fall back to default-src.
			$candidates[] = $all_directives['default-src'];
		}

		if ( empty( $candidates ) ) {
			return false;
		}

		// Keyword mapping (inline, eval, data, blob, …).
		if ( isset( self::KEYWORD_TOKENS[ $origin ] ) ) {
			$keyword_token = self::KEYWORD_TOKENS[ $origin ];
			foreach ( $candidates as $tokens ) {
				if ( in_array( $keyword_token, $tokens, true ) ) {
					return true;
				}
			}
			return false;
		}

		// Determine scheme from the reported origin so we can test scheme-sources.
		$origin_parts  = wp_parse_url( $origin );
		$origin_scheme = isset( $origin_parts['scheme'] ) ? strtolower( $origin_parts['scheme'] ) : '';
		$origin_host   = isset( $origin_parts['host'] ) ? strtolower( $origin_parts['host'] ) : strtolower( $origin );

		foreach ( $candidates as $tokens ) {
			foreach ( $tokens as $token ) {
				$token = (string) $token;

				// Exact match.
				if ( $token === $origin ) {
					return true;
				}

				// Scheme-source: https:, wss:, data:, blob: etc. cover any origin
				// whose scheme matches.
				if ( preg_match( '#^[a-z][a-z0-9+.-]*:$#', $token ) && '' !== $origin_scheme ) {
					if ( rtrim( $token, ':' ) === $origin_scheme ) {
						return true;
					}
				}

				// Host-source normalization: both stored token and reported origin may
				// or may not include a scheme. Normalize both to bare host for
				// comparison.
				if ( '' !== $origin_host ) {
					$token_parts = wp_parse_url( $token );
					$token_host  = isset( $token_parts['host'] ) ? strtolower( $token_parts['host'] ) : '';

					if ( '' === $token_host ) {
						// Token has no recognized URL structure — treat as a bare
						// host or wildcard pattern (strip leading *.).
						$token_host = strtolower( ltrim( $token, '*.' ) );
					}

					if ( '' !== $token_host ) {
						// Exact bare-host match.
						if ( $token_host === $origin_host ) {
							return true;
						}

						// Wildcard subdomain: *.example.com covers sub.example.com.
						if ( 0 === strpos( $token, '*.' ) ) {
							$wildcard_base = ltrim( $token_host, '*.' );
							if ( $origin_host === $wildcard_base ||
								substr( $origin_host, -( strlen( $wildcard_base ) + 1 ) ) === '.' . $wildcard_base ) {
								return true;
							}
						}
					}
				}
			}
		}

		return false;
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

	/**
	 * GET callback: export settings as a portable JSON payload. Strips
	 * volatile keys (integrity hashes, font scan cache, version) that are
	 * site-specific and would corrupt another install's state detection.
	 *
	 * @return WP_REST_Response
	 */
	public function export_settings() {
		$settings = SPFW_Settings::get();

		// Strip volatile / site-specific keys.
		unset( $settings['version'] );
		unset( $settings['hardening']['htaccess_hash'] );
		unset( $settings['hardening']['uploads_htaccess_hash'] );
		unset( $settings['hardening']['root_htaccess_hash'] );
		unset( $settings['hardening']['file_monitor_snapshot'] );
		unset( $settings['hardening']['file_monitor_last_scan'] );
		unset( $settings['fonts']['discovered'] );
		unset( $settings['fonts']['last_scan'] );
		unset( $settings['fonts']['needs_rescan'] );

		return new WP_REST_Response(
			array(
				'plugin'   => 'simple-performance-for-wordpress',
				'exported' => gmdate( 'c' ),
				'site'     => home_url( '/' ),
				'settings' => $settings,
			),
			200
		);
	}

	/**
	 * POST callback: import a settings payload. Runs the incoming settings
	 * through SPFW_Settings::sanitize() (which strips unknown keys and
	 * clamps values) and re-derives integrity hashes locally so a foreign
	 * export never corrupts this site's .htaccess status detection.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_settings( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || empty( $params['settings'] ) || ! is_array( $params['settings'] ) ) {
			return new WP_Error(
				'spfw_invalid_import',
				__( 'Invalid import payload. Expected a JSON object with a "settings" key.', 'simple-performance-for-wordpress' ),
				array( 'status' => 400 )
			);
		}

		$incoming = $params['settings'];

		// Never import volatile keys — they are site-specific.
		unset( $incoming['version'] );
		unset( $incoming['hardening']['htaccess_hash'] );
		unset( $incoming['hardening']['uploads_htaccess_hash'] );
		unset( $incoming['hardening']['root_htaccess_hash'] );
		unset( $incoming['hardening']['file_monitor_snapshot'] );
		unset( $incoming['hardening']['file_monitor_last_scan'] );
		unset( $incoming['fonts']['discovered'] );
		unset( $incoming['fonts']['last_scan'] );
		unset( $incoming['fonts']['needs_rescan'] );

		SPFW_Settings::update( $incoming );

		// Re-derive integrity hashes for any enabled .htaccess targets so
		// the status detection reflects this site's actual files.
		$h = SPFW_Settings::group( 'hardening' );

		if ( ! empty( $h['plugins_htaccess'] ) ) {
			SPFW_Htaccess::write( 'plugins' );
		}

		if ( ! empty( $h['uploads_htaccess'] ) ) {
			SPFW_Htaccess::write( 'uploads' );
		}

		do_action( 'litespeed_purge_all' );

		return $this->get_settings();
	}

	/**
	 * GET callback: list available configuration presets with a summary
	 * of what each one enables.
	 *
	 * @return WP_REST_Response
	 */
	public function list_presets() {
		return new WP_REST_Response(
			array(
				'presets' => SPFW_Settings::get_presets(),
			),
			200
		);
	}

	/**
	 * POST callback: apply a named configuration preset. Runs through the
	 * normal SPFW_Settings::update() path so all sanitization, LSCache
	 * purging, and .htaccess sync fire unchanged.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_preset( $request ) {
		$params = $request->get_json_params();
		$name   = is_array( $params ) && isset( $params['preset'] ) ? sanitize_text_field( $params['preset'] ) : '';

		$presets = SPFW_Settings::get_presets();

		if ( ! isset( $presets[ $name ] ) ) {
			return new WP_Error(
				'spfw_invalid_preset',
				__( 'Unknown preset name.', 'simple-performance-for-wordpress' ),
				array( 'status' => 400 )
			);
		}

		SPFW_Settings::update( $presets[ $name ]['settings'] );

		do_action( 'litespeed_purge_all' );

		return $this->get_settings();
	}

	/**
	 * POST callback: scan representative pages for inline scripts and compute
	 * sha256 hashes for CSP script-src tightening. Reuses the same URL sample
	 * as the font scanner (homepage + recent post + recent page).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan_script_hashes() {
		$urls = $this->get_scan_urls();
		$hashes = array();
		$errors = array();

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'sslverify'   => false,
					'headers'     => array(
						'Cache-Control' => 'no-cache, no-store, must-revalidate',
						'Pragma'        => 'no-cache',
					),
					'redirection' => 3,
				)
			);

			if ( is_wp_error( $response ) ) {
				$errors[] = $url . ': ' . $response->get_error_message();
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				$errors[] = $url . ': HTTP ' . $code;
				continue;
			}

			$html = wp_remote_retrieve_body( $response );

			if ( empty( $html ) ) {
				continue;
			}

			// Extract inline script bodies (scripts without a src attribute).
			if ( preg_match_all( '/<script(?![^>]*\bsrc\b)[^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
				foreach ( $matches[1] as $body ) {
					$body = trim( $body );

					// Skip empty or whitespace-only scripts.
					if ( '' === $body || ! preg_match( '/\S/', $body ) ) {
						continue;
					}

					// Skip JSON-LD and other non-executable script types.
					if ( preg_match( '/<script[^>]*type\s*=\s*["\'](?:application\/ld\+json|application\/json|text\/template)["\']/i', $matches[0][ array_search( $body, $matches[1], true ) ] ?? '', $type_match ) ) {
						continue;
					}

					$hash     = base64_encode( hash( 'sha256', $body, true ) );
					$hashes[] = $hash;
				}
			}
		}

		$hashes = array_values( array_unique( $hashes ) );

		// Store the hashes.
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_script_hashes'  => $hashes,
					'csp_hash_last_scan' => time(),
				),
			)
		);

		return new WP_REST_Response(
			array(
				'hashes'     => $hashes,
				'count'      => count( $hashes ),
				'urls'       => $urls,
				'errors'     => $errors,
				'last_scan'  => time(),
			),
			200
		);
	}

	/**
	 * GET callback: scan the database and return counts for each
	 * cleanup target.
	 *
	 * @return WP_REST_Response
	 */
	public function database_scan() {
		$module = new SPFW_Module_Database();
		$counts = $module->scan();

		return new WP_REST_Response(
			array(
				'counts' => $counts,
			),
			200
		);
	}

	/**
	 * POST callback: run database cleanup on the requested targets and
	 * return per-target deletion counts.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function database_optimize( $request ) {
		$params  = $request->get_json_params();
		$targets = is_array( $params ) && isset( $params['targets'] ) && is_array( $params['targets'] )
			? $params['targets']
			: array();

		// Validate targets against the known whitelist.
		$targets = array_intersect( $targets, SPFW_Module_Database::TARGETS );

		if ( empty( $targets ) ) {
			return new WP_Error(
				'spfw_no_targets',
				__( 'No valid cleanup targets selected.', 'simple-performance-for-wordpress' ),
				array( 'status' => 400 )
			);
		}

		$module  = new SPFW_Module_Database();
		$results = $module->optimize( $targets );

		return new WP_REST_Response(
			array(
				'results' => $results,
				'labels'  => SPFW_Module_Database::get_target_labels(),
			),
			200
		);
	}

	/**
	 * Build the list of URLs to scan for inline scripts. Mirrors the font
	 * scanner's representative sample: homepage, most recent post, most
	 * recent page.
	 *
	 * @return string[]
	 */
	/**
	 * POST callback: run an on-demand file-integrity scan of wp-content and
	 * return the diff plus updated settings.
	 *
	 * @return WP_REST_Response
	 */
	public function scan_files() {
		$module  = new SPFW_Module_Hardening();
		$changes = $module->scan_wp_content();

		$response           = $this->get_settings();
		$data               = $response->get_data();
		$data['scan_result'] = $changes;
		$response->set_data( $data );

		return $response;
	}

	private function get_scan_urls() {
		$urls = array( home_url( '/' ) );

		$recent_post = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! empty( $recent_post ) ) {
			$urls[] = get_permalink( $recent_post[0] );
		}

		$recent_page = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! empty( $recent_page ) ) {
			$urls[] = get_permalink( $recent_page[0] );
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}
}
