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
	 * `script-src` carries `blob:` because LiteSpeed Cache's "Load JS Delayed"
	 * re-executes inline scripts through URL.createObjectURL(new Blob(...)).
	 * `blob:` is its own scheme — the `https:` source does not cover it — so
	 * without it every delayed script is refused and the page loses jQuery.
	 * It costs little here: this policy already allows 'unsafe-inline' and
	 * https:, so an attacker who could mint a blob URL already has script
	 * execution. It matters only under csp_strict_dynamic, where
	 * inject_script_hashes() drops bare schemes anyway.
	 *
	 * `connect-src` carries `https:` and `frame-src` is present at all because
	 * of what their absence did in the field (2026-09-14, maddogproducts.com):
	 * with `connect-src 'self'` and no `frame-src`, an enforcing default policy
	 * blocks the reCAPTCHA iframe (no frame-src means the `default-src 'self'`
	 * fallback applies) and blocks reCAPTCHA Enterprise's token call, so a
	 * logged-out visitor cannot complete a password reset — the form reports
	 * only "Anti-spam verification token is missing". The same two directives
	 * break every embedded video, map, and payment iframe on the internet.
	 *
	 * A default that breaks the site is not a safer default: admins turn the
	 * whole feature back off, which is strictly worse than a policy that still
	 * closes the high-value holes above while letting third-party HTTPS
	 * endpoints work. Narrowing these two is exactly what the policy builder
	 * and the violation collector are for, and an admin who narrows them has
	 * chosen to.
	 *
	 * Must stay in sync with the `csp_directives` default in SPFW_Settings —
	 * Csp_Header_Emission_Test asserts the two parse to the same map.
	 *
	 * @var string
	 */
	const DEFAULT_CSP = "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https: data: blob:; font-src 'self' data: https:; connect-src 'self' https:; media-src 'self'; worker-src 'self' blob:; frame-src 'self' https:; object-src 'none'; base-uri 'self'; frame-ancestors 'self';";

	/**
	 * Directives a browser ignores when the policy arrives in the report-only
	 * header, and logs a console error about on every page load.
	 *
	 * Per CSP Level 3, `frame-ancestors` and `sandbox` only take effect in an
	 * enforcing policy. Emitting them while the admin is still testing produces
	 * a steady stream of "directive ignored when delivered in a report-only
	 * policy" errors that reads exactly like a broken policy — so they are
	 * stripped from the report-only header and restored the moment it enforces.
	 * Clickjacking stays covered in the meantime by `X-Frame-Options:
	 * SAMEORIGIN` from the `security_headers` toggle.
	 *
	 * @var string[]
	 */
	const REPORT_ONLY_IGNORED = array( 'frame-ancestors', 'sandbox' );

	/**
	 * Cron hook name for the periodic file-integrity scan.
	 *
	 * @var string
	 */
	/**
	 * Filename requested when probing a directory that has no canary file on
	 * disk. Must not exist: the verdict rests on 403 (rule ran) versus 404
	 * (request reached the filesystem).
	 */
	const SYNTHETIC_CANARY = 'spfw-enforcement-probe.php';

	const FILE_MONITOR_CRON = 'spfw_file_monitor_scan';

	/**
	 * Hardening settings that change what a rendered page CONTAINS or what
	 * headers travel with it, as opposed to what the server refuses.
	 *
	 * Changing one of these makes already-cached pages wrong — they were stored
	 * with the old headers and will keep being served that way for the rest of
	 * the cache's TTL. That is cache staleness, and its remedy is a purge.
	 * It is a different clock from config staleness (rules on disk versus rules
	 * the server is running), whose remedy is a reload, a restart, or simply
	 * waiting out an ini TTL depending on the server. Reading a cache-stale
	 * symptom as a config-stale cause is exactly the false lead recorded
	 * against Step 15.
	 *
	 * @var string[]
	 */
	const OUTPUT_AFFECTING_KEYS = array(
		'security_headers',
		'csp_enabled',
		'csp_report_only',
		'csp_exclude_logged_in',
		'csp_mode',
		'csp_policy',
		'csp_directives',
		'csp_script_hashes',
		'csp_tighten_script_src',
		'csp_strict_dynamic',
		'csp_collect_until',
		'hsts_enabled',
		'hsts_max_age',
		'hsts_include_subdomains',
		'hsts_preload',
		'permissions_policy',
	);

	/**
	 * One-off cron hook that closes a lapsed violation-collection window.
	 *
	 * The window closing is not just a stored timestamp going stale: while it
	 * was open, every cached page was stored WITH `report-uri` in its header,
	 * and a full-page cache will keep serving those copies for the rest of its
	 * TTL. Visitors' browsers then keep POSTing reports to an endpoint that has
	 * closed and answers 403 — an uncacheable full WordPress bootstrap per
	 * report, which is exactly the cost the time-boxed window exists to avoid.
	 * So the deadline schedules a real event that zeroes the setting and purges
	 * the cache, rather than the window merely expiring on paper.
	 *
	 * @var string
	 */
	const CSP_EXPIRE_CRON = 'spfw_csp_collection_expired';

	/**
	 * Transient key for the file-monitor rate-limit cooldown (one alert
	 * email per hour maximum).
	 *
	 * @var string
	 */
	const FILE_MONITOR_COOLDOWN = 'spfw_file_monitor_cooldown';

	/**
	 * PHP file extensions the monitor scans for (same set the .htaccess blocks).
	 *
	 * @var string[]
	 */
	const MONITOR_EXTENSIONS = array( 'php', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar', 'inc' );

	/**
	 * Enforcement canaries for the .htaccess rule groups this plugin authors.
	 *
	 * Each entry maps a target key to the response codes that prove whether the
	 * web server actually applied the rule: 'deny' codes mean enforced (the
	 * server refused a request the rule targets), 'allow' codes mean the rule is
	 * on disk but the server served the request anyway (not_enforced). Any other
	 * code — a redirect, a 404 for an absent canary, a connection failure (0), a
	 * CDN interstitial — is classified 'unknown' by the shaper, so a proxied or
	 * unusual server never produces a false verdict.
	 *
	 * Labels are plain path descriptors (not translated) to keep
	 * shape_enforcement_result() pure; the admin UI supplies its own copy.
	 *
	 * @var array<string,array{label:string,deny:int[],allow:int[]}>
	 */
	const ENFORCEMENT_CANARIES = array(
		'plugins'           => array(
			'label' => 'wp-content/plugins/index.php',
			'deny'  => array( 403 ),
			'allow' => array( 200 ),
		),
		'uploads'           => array(
			'label' => 'wp-content/uploads/index.php',
			'deny'  => array( 403 ),
			'allow' => array( 200 ),
		),
		'sensitive_files'   => array(
			'label' => 'readme.html / license.txt',
			'deny'  => array( 403 ),
			'allow' => array( 200 ),
		),
		'xmlrpc'            => array(
			'label' => 'xmlrpc.php',
			'deny'  => array( 403 ),
			'allow' => array( 200, 405 ),
		),
		// Probed only when wp-content/uploads/index.php is absent, which is
		// common — WordPress does not reliably create it. Requests a path that
		// should not exist, because a deny rule fires on the URL before any
		// file-existence check: 403 proves the rule ran, 404 proves the request
		// reached the filesystem unimpeded. Without this the uploads rule was
		// simply never probed and reported "unverified" forever, on the one
		// directory where a planted script is most likely to land.
		'uploads_synthetic' => array(
			'label' => 'wp-content/uploads/ (synthetic .php path)',
			'deny'  => array( 403 ),
			'allow' => array( 404, 200 ),
		),
		// The `.user.ini` strategy's canary: a real file this plugin plants in
		// uploads so the guard has something to refuse. Unlike every other
		// entry here it declares 'broken' codes, because this is the one
		// mechanism whose failure mode is not "the rule did nothing" but "PHP
		// cannot serve this tree at all": a wrong or unreadable
		// auto_prepend_file path breaks every PHP request under it. A 5xx here
		// is therefore evidence, not noise, and must not be filed under
		// 'unknown' with the redirects and the CDN interstitials — it is what
		// triggers the automatic revert.
		'uploads_user_ini'  => array(
			'label'  => 'wp-content/uploads/ (PHP execution guard)',
			'deny'   => array( 403 ),
			'allow'  => array( 200 ),
			'broken' => array( 500, 502, 503 ),
		),
		// Inverted: this canary is a file the admin explicitly whitelisted, so
		// an allow code is the pass and a deny code is the failure. Its label
		// is supplied per row (one row per whitelisted path).
		'whitelist'         => array(
			'label' => 'whitelisted PHP file',
			'mode'  => 'allow',
			'deny'  => array( 403 ),
			'allow' => array( 200 ),
		),
	);

	/**
	 * PHP files that well-known plugins serve over HTTP from inside
	 * wp-content, and that the deny-PHP hardening therefore breaks unless
	 * they are whitelisted. Paths are relative to WP_CONTENT_DIR, matching
	 * the php_whitelist format.
	 *
	 * Used to surface a warning when such a file is installed but not
	 * whitelisted. Detection is by file existence rather than by an active-
	 * plugin or option check, so it keeps working across the vendors' own
	 * refactors and does not depend on their internal option names.
	 *
	 * @var array<string,string> Path => human label.
	 */
	const KNOWN_DIRECT_ACCESS_PHP = array(
		'plugins/litespeed-cache/guest.vary.php' => 'LiteSpeed Cache — Guest Mode vary cookie',
	);

	/**
	 * Known direct-access PHP files that are installed on this site but absent
	 * from the whitelist, while the .htaccess that would block them is on.
	 *
	 * Reported, never auto-applied: a hardening whitelist that grows without
	 * the admin seeing it is the wrong default, and the admin may legitimately
	 * prefer to turn the feature off in the other plugin instead.
	 *
	 * @return array<int,array{path:string,label:string}>
	 */
	public static function whitelist_suggestions() {
		$h = SPFW_Settings::group( 'hardening' );

		if ( empty( $h['plugins_htaccess'] ) && empty( $h['uploads_htaccess'] ) ) {
			return array();
		}

		// With auto-allow on, the payload already permits every known file that
		// is installed, so there is nothing to warn about — warning anyway
		// would send the admin to fix a problem they do not have. The
		// suggestion only earns its place when auto-allow has been turned off.
		if ( ! empty( $h['auto_allow_known_php'] ) ) {
			return array();
		}

		$whitelist   = isset( $h['php_whitelist'] ) && is_array( $h['php_whitelist'] ) ? $h['php_whitelist'] : array();
		$suggestions = array();

		foreach ( self::KNOWN_DIRECT_ACCESS_PHP as $path => $label ) {
			$toggle = 0 === strpos( $path, 'uploads/' ) ? 'uploads_htaccess' : 'plugins_htaccess';

			if ( empty( $h[ $toggle ] ) ) {
				continue;
			}

			if ( in_array( $path, $whitelist, true ) ) {
				continue;
			}

			if ( ! file_exists( WP_CONTENT_DIR . '/' . $path ) ) {
				continue;
			}

			$suggestions[] = array(
				'path'  => $path,
				'label' => $label,
			);
		}

		return $suggestions;
	}

	/**
	 * Attach hooks: an admin-only integrity check, a settings-change listener
	 * that writes/removes the .htaccess files when a toggle flips, and the
	 * runtime hardening behaviors for the currently enabled toggles.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_root_self_check' ) );
		add_action( 'admin_init', array( $this, 'maybe_reconcile_htaccess' ) );
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
		// whether a username exists. login_errors receives a rendered HTML
		// string; wp_login_errors receives the WP_Error object, so each hook
		// needs its own callback type.
		if ( ! empty( $h['generic_login_errors'] ) ) {
			add_filter( 'login_errors', array( $this, 'generic_login_error' ) );
			add_filter( 'wp_login_errors', array( $this, 'generic_login_wp_error' ) );
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

		// Disable XML-RPC at the PHP level whenever the admin has disabled it.
		// The server-level <Files xmlrpc.php> block (block_xmlrpc_file) is a
		// performance optimization layered on top — it 403s the request before
		// PHP boots — not a replacement for this filter: on a vhost that does
		// not honor .htaccess the server block is inert, so the PHP filter is
		// the real protection. Running both is harmless — when the server block
		// is honored the request never reaches PHP, so these filters never fire.
		if ( ! empty( $h['disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( $this, 'strip_pingback_methods' ) );
			add_filter( 'wp_headers', array( $this, 'strip_pingback_header' ) );
		}

		// Collection-window expiry. Registered unconditionally, not behind
		// csp_enabled: a window can outlive the toggle that opened it (disable
		// CSP mid-window and the deadline is still stored), and the cached
		// pages advertising report-uri outlive both.
		add_action( self::CSP_EXPIRE_CRON, array( $this, 'close_expired_collection' ) );
		add_action( 'admin_init', array( $this, 'close_expired_collection' ) );

		// Deferred verification of a freshly written `.user.ini`. Registered
		// unconditionally for the same reason the expiry above is: the event
		// can outlive the toggle that scheduled it, and an unhandled cron hook
		// would leave a broken auto_prepend_file in place with nothing watching.
		add_action( SPFW_Strategy_User_Ini::VERIFY_CRON, array( $this, 'run_user_ini_verification' ) );

		// File integrity monitor: schedule the twice-daily scan when enabled.
		// The cron callback scans wp-content for PHP file changes and sends
		// an email alert when non-whitelisted files appear or change.
		if ( ! empty( $h['file_monitor_enabled'] ) ) {
			if ( ! wp_next_scheduled( self::FILE_MONITOR_CRON ) ) {
				wp_schedule_event( time(), 'twicedaily', self::FILE_MONITOR_CRON );
			}
			add_action( self::FILE_MONITOR_CRON, array( $this, 'run_file_monitor_scan' ) );
		}
	}

	/**
	 * Queue an admin notice if any managed hardening file is missing or
	 * altered.
	 */
	public function maybe_show_notice() {
		if ( get_option( 'spfw_user_ini_reverted' ) ) {
			delete_option( 'spfw_user_ini_reverted' );

			add_action(
				'admin_notices',
				function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Simple Performance: the uploads PHP guard (.user.ini) made that directory return server errors, so it was removed automatically. Uploads hardening is still switched on — see the server configuration snippet on the Hardening tab for a way to enforce it that does not depend on PHP.', 'simple-performance-for-wordpress' )
					);
				}
			);
		}

		$targets = array_merge( array_keys( self::HTACCESS_TARGETS ), array( 'root' ) );

		foreach ( $targets as $target ) {
			// Asks whichever strategy owns the target, so a `.user.ini` install
			// gets the same missing/altered notice a `.htaccess` one does, and
			// a server that supports neither ('unsupported'/'advisory') gets no
			// notice about a file it was never going to have.
			if ( in_array( SPFW_Hardening_Strategies::status( $target ), array( 'missing', 'altered' ), true ) ) {
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
				SPFW_Hardening_Strategies::apply( $target );
			} elseif ( $was_on && ! $is_on ) {
				SPFW_Hardening_Strategies::revert( $target );
			}
		}

		// Rewrite .htaccess when the PHP whitelist changes so any
		// RewriteRule allow-then-deny directives stay in sync.
		$old_whitelist = isset( $old_value['hardening']['php_whitelist'] ) ? (array) $old_value['hardening']['php_whitelist'] : array();
		$new_whitelist = isset( $new_value['hardening']['php_whitelist'] ) ? (array) $new_value['hardening']['php_whitelist'] : array();

		if ( $old_whitelist !== $new_whitelist ) {
			foreach ( self::HTACCESS_TARGETS as $target => $toggle ) {
				if ( ! empty( $new_value['hardening'][ $toggle ] ) ) {
					SPFW_Hardening_Strategies::apply( $target );
				}
			}
		}

		// File monitor cron: schedule or clear when the toggle flips.
		$was_monitoring = ! empty( $old_value['hardening']['file_monitor_enabled'] );
		$is_monitoring  = ! empty( $new_value['hardening']['file_monitor_enabled'] );

		if ( $is_monitoring && ! $was_monitoring ) {
			if ( ! wp_next_scheduled( self::FILE_MONITOR_CRON ) ) {
				wp_schedule_event( time(), 'twicedaily', self::FILE_MONITOR_CRON );
			}
		} elseif ( $was_monitoring && ! $is_monitoring ) {
			wp_clear_scheduled_hook( self::FILE_MONITOR_CRON );
		}

		// Root .htaccess: composed from two toggles.
		$this->handle_root_htaccess_change( $old_value, $new_value );

		// Cache staleness clock: note when a setting that changes what a
		// rendered page carries was last touched, so the UI can report pages in
		// the cache as out of date separately from rules the server has not
		// picked up yet.
		$this->record_output_change( $old_value, $new_value );
	}

	/**
	 * Stamp the cache staleness clock when an output-affecting setting changed.
	 *
	 * @param array $old_value Previous full settings array.
	 * @param array $new_value New full settings array.
	 */
	private function record_output_change( $old_value, $new_value ) {
		$old_h = isset( $old_value['hardening'] ) && is_array( $old_value['hardening'] ) ? $old_value['hardening'] : array();
		$new_h = isset( $new_value['hardening'] ) && is_array( $new_value['hardening'] ) ? $new_value['hardening'] : array();

		foreach ( self::OUTPUT_AFFECTING_KEYS as $key ) {
			$before = isset( $old_h[ $key ] ) ? $old_h[ $key ] : null;
			$after  = isset( $new_h[ $key ] ) ? $new_h[ $key ] : null;

			if ( $before !== $after ) {
				SPFW_Settings::update( array( 'hardening' => array( 'output_changed' => time() ) ) );

				return;
			}
		}
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
			SPFW_Hardening_Strategies::apply( 'root' );
			update_option( 'spfw_root_htaccess_check', true );
		} elseif ( $old_on && ! $new_on ) {
			// All disabled — remove our block.
			SPFW_Hardening_Strategies::revert( 'root' );
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
				SPFW_Hardening_Strategies::apply( 'root' );
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

		// Defer — without consuming the flag — when this request is an update
		// or upload run. Those requests are already long and filesystem-heavy;
		// adding a blocking loopback here competes for the same PHP worker and
		// the same max_execution_time budget, and an update that runs out of
		// time mid-flight leaves half-finished state under wp-content/upgrade
		// and wp-content/upgrade-temp-backup. Every later update then fails
		// over the debris. The flag survives, so the check still runs on the
		// next ordinary admin request — seconds later, and the lockout safety
		// net is untouched.
		if ( self::is_update_request() ) {
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
			SPFW_Hardening_Strategies::revert( 'root' );

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

			return;
		}

		// One-shot enforcement read: this loopback already proved the site is
		// healthy after a root write, so record whether the server actually
		// honors the .htaccess rules now. No new per-load probe (that is the
		// blocking loopback 2.6.0 deliberately removed from update requests).
		// Cached so get_settings() returns the verdict without probing on load.
		$this->run_htaccess_enforcement_check();
	}

	/**
	 * Whether the current request is a plugin, theme, or core update/upload.
	 *
	 * Covers both shapes WordPress uses: the update-core.php / update.php
	 * screens (single installs, uploads, and bulk upgrades) and the
	 * admin-ajax.php actions the plugins screen fires per item during a bulk
	 * update.
	 *
	 * @return bool
	 */
	private static function is_update_request() {
		global $pagenow;

		if ( isset( $pagenow ) && in_array( $pagenow, array( 'update.php', 'update-core.php' ), true ) ) {
			return true;
		}

		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// Read-only routing decision: the value only chooses whether this
		// request defers its own self-check. Nothing is written, deleted, or
		// privileged on the strength of it, so no nonce is required.
		// phpcs:ignore WordPress.Security.NonceVerification
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

		return 1 === preg_match( '/^(update|upgrade|install|upload)-(plugin|theme|core)/', $action );
	}

	/**
	 * Self-heal authored .htaccess drift on ordinary admin requests.
	 *
	 * Reconcile re-reads each managed file and rewrites only the ones whose
	 * on-disk content still matches the stored hash (so we authored them) but no
	 * longer equals the payload the current toggles require. This is the drift
	 * status() cannot see: a root marker block that lost its block_xmlrpc group
	 * still reads 'ok' on a hash comparison yet is missing a rule the enabled
	 * toggle requires. Cheap on the common path (a read + hash per target) and
	 * writes only on real drift. Deferred on update/upload requests for the same
	 * reason the root self-check is (see maybe_run_root_self_check()).
	 */
	public function maybe_reconcile_htaccess() {
		if ( self::is_update_request() ) {
			return;
		}

		$rewritten = SPFW_Hardening_Strategies::reconcile();

		if ( in_array( 'root', $rewritten, true ) ) {
			add_action(
				'admin_notices',
				function () {
					printf(
						'<div class="notice notice-success"><p>%s</p></div>',
						esc_html__( 'Simple Performance: re-synced your root .htaccess to match your current hardening toggles.', 'simple-performance-for-wordpress' )
					);
				}
			);
		}
	}

	/**
	 * Run the .htaccess enforcement probe and cache the shaped result.
	 *
	 * Probe (impure) then shape (pure) then persist, so get_settings() can
	 * return the verdict without re-probing on every admin load. The stored
	 * result is what the admin UI reads until the next explicit "Verify
	 * enforcement" or the one-shot post-write read.
	 *
	 * @return array Shaped enforcement report (see shape_enforcement_result()).
	 */
	public function run_htaccess_enforcement_check() {
		$result = $this->probe_htaccess_enforcement();

		// Fingerprint the .htaccess files this verdict was measured against.
		// A later edit makes the verdict describe rules that are no longer on
		// disk, and on OpenLiteSpeed it also means the running server is still
		// serving the OLD rules: OLS parses .htaccess rewrite rules once, when
		// the directory is first accessed after startup, and caches them until
		// a graceful restart. Comparing this fingerprint to the current files
		// is what lets the admin screen say "changed since last verified"
		// instead of showing a stale green badge.
		$result['payload_hashes'] = self::current_htaccess_hashes();

		// The shaped result carries its own 'checked' timestamp, so no separate
		// time key is stored; get_settings() reads the timestamp from there.
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'htaccess_enforcement' => $result,
				),
			)
		);

		$this->maybe_revert_broken_strategy( $result );

		return $result;
	}

	/**
	 * Revert a mechanism that is returning 5xx for the tree it was meant to
	 * protect.
	 *
	 * This is the answer to the one objection that makes `auto_prepend_file`
	 * worth being careful about: a guard PHP cannot load takes down every PHP
	 * request under it, and the probe as originally written only ever asked
	 * whether a request was DENIED — a question to which "the whole tree is
	 * throwing 500s" reads as an inconclusive shrug. A canary that exists makes
	 * survival answerable, and once it is answerable the only defensible thing
	 * to do with a negative answer is undo the change, not report it and wait.
	 *
	 * Reverting is safe to do unattended because it removes only files this
	 * plugin authored and hash-verified, and because the state it restores —
	 * uploads unguarded — is the state the site was in before the toggle.
	 * The toggle itself is left ON: the intent was never wrong, only this
	 * server's ability to carry it out, and the UI falls back to reporting the
	 * snippet.
	 *
	 * @param array $result Shaped enforcement report.
	 */
	private function maybe_revert_broken_strategy( array $result ) {
		if ( empty( $result['strategy_broken'] ) ) {
			return;
		}

		$strategy = new SPFW_Strategy_User_Ini();
		$strategy->revert( 'uploads' );

		update_option( 'spfw_user_ini_reverted', time() );
	}

	/**
	 * Deferred verification of a freshly written `.user.ini`.
	 *
	 * Runs once, `user_ini.cache_ttl` plus a minute after the write. Until then
	 * PHP is still serving the cached result of its previous scan of that
	 * directory, so probing earlier cannot distinguish "not picked up yet" from
	 * "picked up and ignored" — which is precisely why the self-test is a
	 * scheduled event rather than a line at the end of apply().
	 */
	public function run_user_ini_verification() {
		$owner = SPFW_Hardening_Strategies::for_target( 'uploads' );

		if ( ! $owner || 'user_ini' !== $owner->key() ) {
			return;
		}

		if ( ! SPFW_Settings::value( 'hardening', 'uploads_htaccess', false ) ) {
			return;
		}

		$this->run_htaccess_enforcement_check();
	}

	/**
	 * Probe whether the web server actually applies the .htaccess rules this
	 * plugin authored, by requesting a canary each enabled rule group is meant
	 * to deny and reading the response code. A rule that should deny but returns
	 * an allow code is on disk yet inert (for example an OpenLiteSpeed vhost
	 * with "Auto Load from .htaccess" off); a deny code proves the server
	 * honored it.
	 *
	 * Only canaries whose toggle is enabled are probed, and only when the canary
	 * file exists on disk, so an absent canary degrades to 'unknown' rather than
	 * guessing. Impure: performs loopback HTTP. Classification is delegated to
	 * the pure shape_enforcement_result() so it stays unit-testable.
	 *
	 * @return array Shaped enforcement report (see shape_enforcement_result()).
	 */
	public function probe_htaccess_enforcement() {
		$h       = SPFW_Settings::group( 'hardening' );
		$targets = array();

		// plugins deny-PHP: index.php under plugins/ should be denied when the
		// rule is honored.
		if ( ! empty( $h['plugins_htaccess'] ) ) {
			$targets[] = $this->probe_canary( 'plugins', plugins_url( 'index.php' ) );
		}

		// uploads deny-PHP: prefer the real index.php canary, where a 200
		// proves the request was served. When it is absent — which is common,
		// since WordPress does not reliably create it — fall back to a path
		// that should not exist, where 403 vs 404 is just as decisive and
		// needs nothing on disk.
		if ( ! empty( $h['uploads_htaccess'] ) ) {
			$uploads = wp_upload_dir();
			$disk    = trailingslashit( $uploads['basedir'] ) . 'index.php';

			$owner = SPFW_Hardening_Strategies::for_target( 'uploads' );

			if ( $owner && 'user_ini' === $owner->key() && file_exists( SPFW_Strategy_User_Ini::canary_path() ) ) {
				// The guard runs only when the server hands PHP a script that
				// exists, so the absent-path canary below would read 404 —
				// "served, rule inert" — against a guard that is working
				// perfectly. This canary is a real file, which makes the answer
				// three-way: 403 refused, 200 not guarding yet, 5xx broken.
				$targets[] = $this->probe_canary( 'uploads_user_ini', SPFW_Strategy_User_Ini::canary_url() );
			} elseif ( file_exists( $disk ) ) {
				$targets[] = $this->probe_canary( 'uploads', trailingslashit( $uploads['baseurl'] ) . 'index.php' );
			} else {
				$targets[] = $this->probe_canary(
					'uploads_synthetic',
					trailingslashit( $uploads['baseurl'] ) . self::SYNTHETIC_CANARY
				);
			}
		}

		// Root sensitive-file block: readme.html, falling back to license.txt.
		if ( ! empty( $h['protect_sensitive_files'] ) ) {
			foreach ( array( 'readme.html', 'license.txt' ) as $candidate ) {
				if ( file_exists( ABSPATH . $candidate ) ) {
					$targets[] = $this->probe_canary( 'sensitive_files', home_url( '/' . $candidate ) );
					break;
				}
			}
		}

		// Root xmlrpc.php server block.
		if ( ! empty( $h['block_xmlrpc_file'] ) ) {
			$targets[] = $this->probe_canary( 'xmlrpc', home_url( '/xmlrpc.php' ) );
		}

		// Whitelisted files, probed in the opposite direction: these must be
		// reachable. Without this the probe reports a fully healthy site while
		// hardening 403s a file the admin explicitly allowed — the exact state
		// a LiteSpeed Guest Mode install lands in. Capped because each probe is
		// a loopback request with an 8s timeout.
		foreach ( $this->whitelist_probe_targets( $h ) as $probe ) {
			$targets[] = $this->probe_canary( 'whitelist', $probe['url'], $probe['path'] );
		}

		return self::shape_enforcement_result(
			array(
				'targets' => $targets,
				'checked' => time(),
			)
		);
	}

	/**
	 * Fetch one canary URL over loopback and record its response code.
	 *
	 * Reuses the font scanner's cache-busting and no-store pattern so a CDN or
	 * page cache in front of the origin cannot serve a stale code and skew the
	 * verdict. redirection=0 so a redirect is observed as-is and classified
	 * 'unknown', never 'enforced'. sslverify=false because loopback TLS often
	 * fails on self-signed or mismatched certs and we only read a status code.
	 *
	 * @param string $target Canary key (an ENFORCEMENT_CANARIES key).
	 * @param string $url    Absolute canary URL.
	 * @param string $label  Optional per-row label, overriding the canary's.
	 *                       Used by the whitelist rows, which share one key.
	 * @return array{target:string,url:string,code:int} Raw row; code 0 on error.
	 */
	private function probe_canary( $target, $url, $label = '' ) {
		$row = array(
			'target' => $target,
			'url'    => $url,
			'code'   => 0,
		);

		if ( '' !== $label ) {
			$row['label'] = $label;
		}

		$probe_url = add_query_arg( 'spfw_nocache', (string) time(), $url );

		$response = wp_remote_get(
			$probe_url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => false,
				'headers'     => array(
					'Cache-Control' => 'no-cache, no-store, must-revalidate',
					'Pragma'        => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $row;
		}

		$row['code'] = (int) wp_remote_retrieve_response_code( $response );

		return $row;
	}

	/**
	 * Fingerprint of the .htaccess content this plugin currently has on disk,
	 * keyed by target. Absent or unreadable targets map to an empty string, so
	 * a file appearing or disappearing also registers as a change.
	 *
	 * Pure-ish: reads the filesystem, no network. Used to tell "this verdict
	 * still describes the files on disk" from "the files changed after the
	 * verdict was measured".
	 *
	 * @return array<string,string>
	 */
	public static function current_htaccess_hashes() {
		$hashes = array();

		foreach ( array( 'plugins', 'uploads', 'root' ) as $target ) {
			$path = SPFW_Htaccess::path( $target );

			$hashes[ $target ] = file_exists( $path ) ? (string) sha1_file( $path ) : '';
		}

		// The `.user.ini` strategy's files belong in the same fingerprint: they
		// are config on disk that a verdict was measured against, and on that
		// strategy the gap between writing them and the server reading them is
		// wider than anywhere else — a whole `user_ini.cache_ttl`.
		foreach ( array(
			'user_ini'       => SPFW_Strategy_User_Ini::ini_path(),
			'user_ini_guard' => SPFW_Strategy_User_Ini::guard_path(),
		) as $key => $path ) {
			$hashes[ $key ] = ( '' !== $path && file_exists( $path ) ) ? (string) sha1_file( $path ) : '';
		}

		return $hashes;
	}

	/**
	 * Config staleness: whether the rules on disk are the rules the running
	 * server is applying.
	 *
	 * The first of the two clocks. Its remedy depends entirely on the server,
	 * and the spread is the whole reason this is reported rather than assumed:
	 * Apache and LiteSpeed Enterprise re-read `.htaccess` per request, so there
	 * is nothing to do but re-verify; OpenLiteSpeed caches the parsed rewrite
	 * rules until a graceful restart; nginx needs `nginx -s reload`, which
	 * needs root and which PHP will never have; and a `.user.ini` needs nobody
	 * at all — it applies itself once `user_ini.cache_ttl` has elapsed.
	 *
	 * That last case is a deadline rather than a task, so it is reported with
	 * the time it expires instead of an instruction.
	 *
	 * @return array{stale:bool,reason:string,remedy:string,applies_at:int,refresh:string}
	 */
	public static function config_staleness() {
		$report = array(
			'stale'      => false,
			'reason'     => '',
			'remedy'     => '',
			'applies_at' => 0,
			'refresh'    => SPFW_Server::config_refresh(),
		);

		$owner = SPFW_Hardening_Strategies::for_target( 'uploads' );

		if ( $owner && 'user_ini' === $owner->key() ) {
			$applies = SPFW_Strategy_User_Ini::applies_at();

			if ( $applies > time() ) {
				return array(
					'stale'      => true,
					'reason'     => 'awaiting_ttl',
					'remedy'     => 'wait',
					'applies_at' => $applies,
					'refresh'    => 'ttl',
				);
			}
		}

		if ( ! self::htaccess_changed_since_probe() ) {
			return $report;
		}

		$report['stale']  = true;
		$report['reason'] = 'changed_since_probe';

		switch ( $report['refresh'] ) {
			case 'restart':
				$report['remedy'] = 'restart';
				break;
			case 'reload':
				$report['remedy'] = 'reload';
				break;
			default:
				$report['remedy'] = 'verify';
		}

		return $report;
	}

	/**
	 * Cache staleness: whether already-rendered pages still describe current
	 * behavior.
	 *
	 * The second clock, and a different animal entirely. Nothing on disk is
	 * wrong and the server is applying exactly the rules it was given — the
	 * pages a visitor receives were simply stored earlier, with the previous
	 * headers baked in, and will keep being served that way until the cache
	 * turns over. The remedy is a purge, and the owner is whatever cache is in
	 * front of the site, not this plugin.
	 *
	 * Distinguishing the two matters because the symptoms are identical from a
	 * browser: a change that "did not take". Reading one as the other is the
	 * false lead recorded against Step 15.
	 *
	 * `purge_available` is the honest part. This plugin fires
	 * `litespeed_purge_all` after every save, which does exactly nothing when
	 * LiteSpeed Cache is not the cache in front of the site — an nginx
	 * FastCGI cache or a CDN will not hear it. When no listener exists the
	 * purge is the admin's to perform, and saying so beats a silent no-op.
	 *
	 * @return array{stale:bool,changed:int,purged:int,purge_available:bool,remedy:string}
	 */
	public static function cache_staleness() {
		$h       = SPFW_Settings::group( 'hardening' );
		$changed = isset( $h['output_changed'] ) ? (int) $h['output_changed'] : 0;
		$purged  = isset( $h['cache_purged'] ) ? (int) $h['cache_purged'] : 0;

		$available = function_exists( 'has_action' ) ? (bool) has_action( 'litespeed_purge_all' ) : false;

		return array(
			'stale'           => $changed > 0 && $changed > $purged,
			'changed'         => $changed,
			'purged'          => $purged,
			'purge_available' => $available,
			'remedy'          => $available ? 'purge' : 'purge_manual',
		);
	}

	/**
	 * Record that the page cache was cleared, closing the cache staleness gap.
	 */
	public static function note_cache_purged() {
		SPFW_Settings::update( array( 'hardening' => array( 'cache_purged' => time() ) ) );
	}

	/**
	 * Whether the .htaccess files changed after the cached enforcement verdict
	 * was measured, making that verdict describe rules that are no longer what
	 * is on disk.
	 *
	 * On OpenLiteSpeed this is also the signal that the running server is out
	 * of step with disk and needs a graceful restart. Returns false when no
	 * probe has run yet, or when the stored result predates this fingerprint
	 * (an upgrade) — an unknown is not a warning.
	 *
	 * @return bool
	 */
	public static function htaccess_changed_since_probe() {
		$enforcement = SPFW_Settings::value( 'hardening', 'htaccess_enforcement', array() );

		if ( ! is_array( $enforcement ) || empty( $enforcement['payload_hashes'] )
			|| ! is_array( $enforcement['payload_hashes'] ) ) {
			return false;
		}

		return self::current_htaccess_hashes() !== $enforcement['payload_hashes'];
	}

	/**
	 * Whitelisted paths worth probing: those under a directory whose deny-PHP
	 * rule is actually on, and whose file exists on disk (an absent file would
	 * 404 and prove nothing). Capped at five to bound the probe's wall time.
	 *
	 * URLs are built with content_url() and disk paths with WP_CONTENT_DIR,
	 * matching how SPFW_Htaccess builds the RewriteCond for the same entry.
	 *
	 * @param array $h Hardening settings group.
	 * @return array<int,array{path:string,url:string}>
	 */
	private function whitelist_probe_targets( array $h ) {
		$whitelist = isset( $h['php_whitelist'] ) && is_array( $h['php_whitelist'] ) ? $h['php_whitelist'] : array();
		$targets   = array();

		foreach ( $whitelist as $path ) {
			$path   = (string) $path;
			$toggle = 0 === strpos( $path, 'uploads/' ) ? 'uploads_htaccess' : 'plugins_htaccess';

			if ( empty( $h[ $toggle ] ) ) {
				continue;
			}

			if ( ! file_exists( WP_CONTENT_DIR . '/' . $path ) ) {
				continue;
			}

			$targets[] = array(
				'path' => $path,
				'url'  => content_url( '/' . $path ),
			);

			if ( count( $targets ) >= 5 ) {
				break;
			}
		}

		return $targets;
	}

	/**
	 * Classify raw canary response codes into an honest enforcement verdict.
	 *
	 * Pure: no filesystem, WordPress, network, or translation access, so the
	 * classification is unit-testable without an install (wp_remote_get() is not
	 * stubbed in tests/bootstrap.php).
	 * Conservative by design: only a code a rule is meant to deny proves
	 * 'enforced', only a clear allow code proves 'not_enforced', and everything
	 * else (a redirect, a 404 for an absent canary, a connection failure of 0, a
	 * CDN interstitial, any unexpected code) is 'unknown', so a proxied or
	 * unusual server never yields a false alarm.
	 *
	 * The headline htaccess_honored is a vhost-level property: if the server
	 * ignores one .htaccess we wrote it ignores all, so a single clear bypass
	 * (any 'not_enforced' target) makes it 'no'; otherwise any 'enforced' target
	 * makes it 'yes'; with nothing decisive it is 'unknown'.
	 *
	 * @param array $raw Raw probe output: 'targets' (rows of {target,url,code})
	 *                   and 'checked'.
	 * @return array Report: 'htaccess_honored' (yes|no|unknown),
	 *               'whitelist_blocked' (bool — a file the admin whitelisted is
	 *               being refused), 'strategy_broken' (bool — a mechanism this
	 *               plugin installed is returning 5xx for the tree it guards),
	 *               'targets' (rows of
	 *               {target,label,url,observed_code,expected,state}), and
	 *               'checked'.
	 */
	public static function shape_enforcement_result( array $raw ) {
		$raw_targets   = isset( $raw['targets'] ) && is_array( $raw['targets'] ) ? $raw['targets'] : array();
		$targets       = array();
		$any_enforced  = false;
		$any_bypassed  = false;
		$any_wl_broken = false;
		$any_broken    = false;

		foreach ( $raw_targets as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['target'] ) ) {
				continue;
			}

			$key    = (string) $row['target'];
			$canary = isset( self::ENFORCEMENT_CANARIES[ $key ] )
				? self::ENFORCEMENT_CANARIES[ $key ]
				: array(
					'label' => $key,
					'deny'  => array( 403 ),
					'allow' => array( 200 ),
				);

			$code   = isset( $row['code'] ) ? (int) $row['code'] : 0;
			$deny   = isset( $canary['deny'] ) ? (array) $canary['deny'] : array( 403 );
			$allow  = isset( $canary['allow'] ) ? (array) $canary['allow'] : array( 200 );
			$broken = isset( $canary['broken'] ) ? (array) $canary['broken'] : array();
			$mode   = isset( $canary['mode'] ) ? (string) $canary['mode'] : 'deny';

			if ( ! empty( $broken ) && in_array( $code, $broken, true ) ) {
				// Only canaries that declare 'broken' codes reach this branch,
				// which is why a 5xx elsewhere is still 'unknown': on a deny
				// rule a 500 could be anything, but on a mechanism that works
				// by making PHP load a file first, it is that file failing to
				// load. It moves neither $any_enforced nor $any_bypassed — the
				// question it answers is not whether the server honors a rule,
				// but whether the mechanism is safe to leave in place.
				$state      = 'broken';
				$any_broken = true;
				$expected   = $deny;
			} elseif ( 'allow' === $mode ) {
				// An allow-mode canary is a file that must stay reachable, so
				// the verdict inverts. It deliberately moves neither
				// $any_enforced nor $any_bypassed: reaching a whitelisted file
				// says nothing about whether the server honors .htaccess at
				// all, since an inert one would serve it too.
				if ( in_array( $code, $allow, true ) ) {
					$state = 'allowed';
				} elseif ( in_array( $code, $deny, true ) ) {
					$state         = 'whitelist_blocked';
					$any_wl_broken = true;
				} else {
					$state = 'unknown';
				}

				$expected = $allow;
			} elseif ( in_array( $code, $deny, true ) ) {
				$state        = 'enforced';
				$any_enforced = true;
				$expected     = $deny;
			} elseif ( in_array( $code, $allow, true ) ) {
				$state        = 'not_enforced';
				$any_bypassed = true;
				$expected     = $deny;
			} else {
				$state    = 'unknown';
				$expected = $deny;
			}

			$label = isset( $row['label'] ) && '' !== $row['label']
				? (string) $row['label']
				: ( isset( $canary['label'] ) ? (string) $canary['label'] : $key );

			$targets[] = array(
				'target'        => $key,
				'label'         => $label,
				'url'           => isset( $row['url'] ) ? (string) $row['url'] : '',
				'observed_code' => $code,
				'expected'      => implode( '/', array_map( 'strval', $expected ) ),
				'state'         => $state,
			);
		}

		if ( $any_bypassed ) {
			$honored = 'no';
		} elseif ( $any_enforced ) {
			$honored = 'yes';
		} else {
			$honored = 'unknown';
		}

		return array(
			'htaccess_honored'  => $honored,
			'whitelist_blocked' => $any_wl_broken,
			// A mechanism this plugin installed is actively breaking the tree
			// it was meant to protect. Reported separately from the enforcement
			// verdict because it calls for a different action — reverting,
			// not reconfiguring — and it is the one verdict acted on
			// automatically rather than shown and left to the admin.
			'strategy_broken'   => $any_broken,
			'targets'           => $targets,
			'checked'           => isset( $raw['checked'] ) ? (int) $raw['checked'] : 0,
		);
	}

	/**
	 * Core-shipped files that are better deleted than blocked.
	 *
	 * Blocking `readme.html` and `license.txt` needs a rule the server honors,
	 * and no rule a PHP process can write reaches them on nginx: they are
	 * static, so nothing this plugin installs ever runs for the request.
	 * Deleting them needs no server cooperation at all, works identically
	 * everywhere, and removes the information rather than hiding it. WordPress
	 * uses neither file for anything.
	 *
	 * The honest caveat, which the UI repeats: a core update restores both.
	 * That makes deletion a recurring chore rather than a one-time fix, which
	 * is why it is offered alongside the rule instead of replacing it.
	 *
	 * @return array<int,array{file:string,path:string,url:string,exists:bool}>
	 */
	public static function removable_files() {
		$files = array();

		foreach ( array( 'readme.html', 'license.txt' ) as $file ) {
			$files[] = array(
				'file'   => $file,
				'path'   => ABSPATH . $file,
				'url'    => home_url( '/' . $file ),
				'exists' => file_exists( ABSPATH . $file ),
			);
		}

		return $files;
	}

	/**
	 * Whether a filename is one of the files this plugin will delete.
	 *
	 * An allow-list, compared against the basename, because the alternative is
	 * a REST-exposed delete that takes a path.
	 *
	 * @param string $file Candidate filename.
	 * @return bool
	 */
	public static function is_removable_file( $file ) {
		$file = basename( (string) $file );

		foreach ( self::removable_files() as $candidate ) {
			if ( $candidate['file'] === $file ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete one core-shipped file and confirm over HTTP that it is gone.
	 *
	 * The same confirm-and-verify shape the toggles use: the action is taken,
	 * then the result is measured from outside rather than assumed from a
	 * successful `unlink()`. A 404 or 403 afterwards is proof; anything else
	 * (a CDN still holding a copy, a 200 from a cache) is reported as
	 * unverified rather than counted as success.
	 *
	 * @param string $file Filename from removable_files().
	 * @return array{file:string,deleted:bool,verified:bool,code:int,reason:string}
	 */
	public static function remove_file( $file ) {
		$file   = basename( (string) $file );
		$result = array(
			'file'     => $file,
			'deleted'  => false,
			'verified' => false,
			'code'     => 0,
			'reason'   => '',
		);

		if ( ! self::is_removable_file( $file ) ) {
			$result['reason'] = 'not_removable';

			return $result;
		}

		$path = ABSPATH . $file;

		if ( ! file_exists( $path ) ) {
			$result['deleted'] = true;
			$result['reason']  = 'already_absent';
		} else {
			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			if ( ! $wp_filesystem ) {
				WP_Filesystem();
			}

			if ( ! $wp_filesystem || ! $wp_filesystem->delete( $path ) ) {
				$result['reason'] = 'delete_failed';

				return $result;
			}

			$result['deleted'] = true;
		}

		$response = wp_remote_get(
			add_query_arg( 'spfw_nocache', (string) time(), home_url( '/' . $file ) ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => false,
				'headers'     => array(
					'Cache-Control' => 'no-cache, no-store, must-revalidate',
					'Pragma'        => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['reason'] = 'probe_failed';

			return $result;
		}

		$result['code']     = (int) wp_remote_retrieve_response_code( $response );
		$result['verified'] = in_array( $result['code'], array( 403, 404, 410 ), true );

		if ( ! $result['verified'] && '' === $result['reason'] ) {
			$result['reason'] = 'still_served';
		}

		return $result;
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
	 * Return the single generic error message string shown above the login
	 * form. Used on the login_errors filter.
	 *
	 * @return string
	 */
	public function generic_login_error() {
		return __( 'Invalid username or password.', 'simple-performance-for-wordpress' );
	}

	/**
	 * Replace specific login errors with a single generic WP_Error so the
	 * displayed message and form-shake behavior are identical for every
	 * failure type. Success messages (severity 'message') are preserved.
	 *
	 * @param WP_Error $errors WP_Error object passed by the wp_login_errors filter.
	 * @return WP_Error
	 */
	public function generic_login_wp_error( $errors ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}

		$error_codes = array();
		foreach ( $errors->get_error_codes() as $code ) {
			$severity = $errors->get_error_data( $code );
			if ( 'message' !== $severity ) {
				$error_codes[] = $code;
			}
		}

		if ( empty( $error_codes ) ) {
			return $errors;
		}

		foreach ( $error_codes as $code ) {
			$errors->remove( $code );
		}

		$errors->add( 'generic', __( 'Invalid username or password.', 'simple-performance-for-wordpress' ) );

		return $errors;
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
	 * While the admin has a violation-collection window open (either mode), the
	 * policy also carries `report-uri` pointing at the plugin's report endpoint
	 * so blocked resources are surfaced in the admin. Outside that window no
	 * reporting directive is emitted at all.
	 */
	public function add_csp_header() {
		if ( headers_sent() ) {
			return;
		}

		$h = SPFW_Settings::group( 'hardening' );

		// While a collection window is open, an administrator receives the
		// policy even though csp_exclude_logged_in would normally withhold it.
		// Without this the person running the test is the one visitor the test
		// cannot see: they browse the site, their browser is sent no CSP header
		// at all, nothing is violated, nothing is reported, and the window
		// closes on an empty log that reads as proof the policy is safe. The
		// interaction-gated paths that actually break — a login modal, a
		// checkout step — are precisely the ones only a human deliberately
		// exercises, so excluding that human from the policy removes the only
		// realistic way they get tested.
		$self_test = self::admin_self_test_active( $h );

		if ( ! empty( $h['csp_exclude_logged_in'] ) && is_user_logged_in() && ! $self_test ) {
			// This response deliberately carries no CSP. Left cacheable, that
			// headerless copy is stored by the page cache and then served to
			// logged-out visitors for the rest of its TTL — so the policy
			// silently stops applying to exactly the people it protects, and
			// no violation is ever reported because no header was sent. Mark
			// the response uncacheable so the omission cannot outlive this
			// request.
			self::prevent_page_caching( 'CSP header omitted for a logged-in user' );

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
			$policy = self::inject_script_hashes( $policy, $h['csp_script_hashes'], ! empty( $h['csp_strict_dynamic'] ) );
		}

		// Violation collection is a time-boxed diagnostic window, not a
		// permanent behavior. Emitting `report-uri` on every response makes
		// every visitor's browser POST to /wp-json/spfw/v1/csp-report on every
		// page view — an uncacheable full WordPress bootstrap per report, which
		// on a busy site makes the report endpoint the site's single busiest
		// "page" while adding no information (the log saturates in seconds).
		// So the directive is only attached while the admin has explicitly
		// opened a collection window, and only on the sampled share of
		// responses. Outside the window the policy is emitted with no reporting
		// directive at all and the endpoint is fully closed.
		//
		// We deliberately use report-uri ALONE (not the newer report-to): when
		// both are present Chrome ignores report-uri and switches to the
		// Reporting API, which batches reports and delays them by up to a
		// minute — so violations appear to never arrive during interactive
		// testing. report-uri is deprecated but universally honored and fires
		// immediately per violation, which is exactly what this feedback loop
		// needs.
		$report_only = ! empty( $h['csp_report_only'] );

		// Drop the directives a report-only policy ignores (see
		// REPORT_ONLY_IGNORED) before anything is appended, so the header we
		// send carries only directives the browser will actually act on.
		if ( $report_only ) {
			$policy = self::remove_directives( $policy, self::REPORT_ONLY_IGNORED );
		}

		// The admin's own page views are never sampled away: a sample rate is
		// there to bound the report volume from real traffic, and one
		// administrator clicking through the site is not a volume problem. It
		// is, however, the only traffic guaranteed to reach the pages that
		// matter, so discarding 90% of it would defeat the window.
		$report_url = self::collection_open( $h ) && ( $self_test || self::collection_sampled( $h ) )
			? self::csp_report_url()
			: '';

		if ( '' !== $report_url ) {
			// This response asks the browser to report violations, so it must
			// not be stored and replayed. The header is set here, in PHP, on
			// send_headers — which does not run at all for a full-page cache
			// hit. Left cacheable, what a window collects is limited to
			// whatever the cache happened to regenerate while it was open, and
			// the sampling coin-flip above is decided once per cache entry
			// rather than per visitor (so a single unlucky flip silences an
			// entire page for the whole window). On a LiteSpeed/QUIC.cloud site
			// — the stack this plugin targets — that is most of the front end.
			//
			// It costs real performance, which is why the window is time-boxed,
			// the admin opens it deliberately, and the UI says so.
			if ( ! empty( $h['csp_collect_nocache'] ) ) {
				self::prevent_page_caching( 'CSP violation-collection window open' );
			}

			// Coverage is recorded later in the same request, not here.
			// `send_headers` fires from WP::main() BEFORE query_posts() and
			// handle_404(), so no template conditional is answerable yet —
			// is_404(), is_singular() and WooCommerce's is_cart()/is_checkout()
			// would all read false and every page would be filed as 'other'.
			// Deferring to template_redirect, which runs once the query has
			// actually happened, is what makes the checklist mean anything.
			add_action( 'template_redirect', array( $this, 'record_coverage_for_request' ), 1 );

			// When a CDN/proxy rewrites the report URL's origin so it differs
			// from the page's own origin ('self'), the browser would block the
			// report POST under connect-src. Inject the report origin into the
			// policy's connect-src so reports are never silently dropped.
			$policy = self::ensure_connect_src_allows( $policy, $report_url );

			$policy  = rtrim( $policy );
			$policy .= ( '' === $policy || ';' === substr( $policy, -1 ) ) ? '' : ';';
			$policy .= ' report-uri ' . $report_url . ';';
		}

		header( self::csp_header_name( $h ) . ': ' . $policy );
	}

	/**
	 * Return the actual CSP header value that add_csp_header() would emit for
	 * the current request context (builder or custom mode, with script-hash
	 * injection if configured, but without the report-uri appended). Provided
	 * as a separate public method so the REST settings response can include it
	 * as a reference preview for the admin.
	 *
	 * @return string
	 */
	public static function get_emitted_policy_preview() {
		$h    = SPFW_Settings::group( 'hardening' );
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

		if ( ! empty( $h['csp_tighten_script_src'] ) && ! empty( $h['csp_script_hashes'] ) && is_array( $h['csp_script_hashes'] ) ) {
			$policy = self::inject_script_hashes( $policy, $h['csp_script_hashes'], ! empty( $h['csp_strict_dynamic'] ) );
		}

		// Mirror add_csp_header()'s report-only strip, or the admin would be
		// shown directives that are not in the header on the wire.
		if ( ! empty( $h['csp_report_only'] ) ) {
			$policy = self::remove_directives( $policy, self::REPORT_ONLY_IGNORED );
		}

		// Append the report-uri if a collection window is open, mirroring
		// add_csp_header() so the preview is accurate.
		if ( self::collection_open( $h ) ) {
			$report_url = self::csp_report_url_public();
			$policy     = self::ensure_connect_src_allows( $policy, $report_url );
			$policy     = rtrim( $policy );
			$policy    .= ( '' === $policy || ';' === substr( $policy, -1 ) ) ? '' : ';';
			$policy    .= ' report-uri ' . $report_url . ';';
		}

		return $policy;
	}

	/**
	 * Ask every full-page cache we know of not to store this response.
	 *
	 * `DONOTCACHEPAGE` is the de-facto constant honored by LiteSpeed Cache,
	 * W3 Total Cache, WP Rocket and WP Super Cache; LiteSpeed also has its own
	 * action, which is the one that works when the constant is checked too
	 * late. Both are cheap and neither errors when the cache is absent.
	 *
	 * Note what this does NOT fix: a cache entry generated for a logged-out
	 * visitor (and so carrying the header) being served to a logged-in user by
	 * a CDN that does not vary on the login cookie. Nothing in PHP can prevent
	 * that, because PHP never runs for a cache hit. The exclusion is therefore
	 * best-effort in that direction and exact in this one — which is the
	 * direction that matters, since it is the one where a visitor loses the
	 * policy rather than merely receiving it unexpectedly.
	 *
	 * @param string $reason Human-readable reason, surfaced in LiteSpeed's log.
	 */
	private static function prevent_page_caching( $reason ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party de-facto constant; the name is the contract other cache plugins read.
			define( 'DONOTCACHEPAGE', true );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook; a prefixed name would not reach it.
		do_action( 'litespeed_control_set_nocache', 'SPFW: ' . $reason );
	}

	/**
	 * Close a violation-collection window whose deadline has passed, and purge
	 * the page cache so no stored copy keeps advertising `report-uri`.
	 *
	 * Runs from its own one-off cron event and, as a catch-up for installs
	 * where WP-Cron is unreliable, on `admin_init`. Both paths are a single
	 * read of the already-cached settings array when there is nothing to do.
	 */
	public function close_expired_collection() {
		$h     = SPFW_Settings::group( 'hardening' );
		$until = isset( $h['csp_collect_until'] ) ? (int) $h['csp_collect_until'] : 0;

		// Nothing scheduled, or the window is genuinely still open.
		if ( $until <= 0 || $until > time() ) {
			return;
		}

		SPFW_Settings::update(
			array(
				'hardening' => array(
					'csp_collect_until' => 0,
				),
			)
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook; a prefixed name would not reach it.
		do_action( 'litespeed_purge_all' );
	}

	/**
	 * Name of the CSP header the current settings emit.
	 *
	 * Exposed so the admin UI can label the emitted-policy preview with the
	 * header it will actually be sent as. A policy string on its own gives the
	 * admin no way to tell a report-only policy from an enforcing one, which is
	 * the difference between "logging what would break" and "breaking it".
	 *
	 * @param array $h Hardening settings group.
	 * @return string
	 */
	public static function csp_header_name( array $h ) {
		return ! empty( $h['csp_report_only'] )
			? 'Content-Security-Policy-Report-Only'
			: 'Content-Security-Policy';
	}

	/**
	 * Remove named directives from a policy string.
	 *
	 * Splits on `;` and compares the first token of each directive, so a host
	 * that happens to contain a directive name is never mistaken for one.
	 *
	 * @param string   $policy Policy string.
	 * @param string[] $names  Lower-case directive names to drop.
	 * @return string
	 */
	private static function remove_directives( $policy, array $names ) {
		$kept = array();

		foreach ( explode( ';', (string) $policy ) as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			$tokens = preg_split( '/\s+/', $part );
			$name   = strtolower( isset( $tokens[0] ) ? $tokens[0] : '' );

			if ( ! in_array( $name, $names, true ) ) {
				$kept[] = $part;
			}
		}

		return empty( $kept ) ? '' : implode( '; ', $kept ) . ';';
	}

	/**
	 * Whether the admin's violation-collection window is currently open.
	 *
	 * Shared by the header (should we advertise report-uri?) and the REST
	 * endpoint (should we accept a report at all?), so the two can never
	 * disagree — an endpoint that stayed open after the window closed would
	 * keep accepting unauthenticated writes for no reason.
	 *
	 * @param array $h Hardening settings group.
	 * @return bool
	 */
	public static function collection_open( array $h ) {
		$until = isset( $h['csp_collect_until'] ) ? (int) $h['csp_collect_until'] : 0;

		return $until > time();
	}

	/**
	 * Whether this request should receive the policy despite being logged in,
	 * because an administrator is self-testing inside an open window.
	 *
	 * Deliberately narrow: it requires the window to be open, the setting to
	 * be on, and the user to hold `manage_options`. It cannot leak into normal
	 * operation, because outside a window it is always false — and a window
	 * closes itself.
	 *
	 * @param array $h Hardening settings group.
	 * @return bool
	 */
	public static function admin_self_test_active( array $h ) {
		if ( empty( $h['csp_collect_admin'] ) || ! self::collection_open( $h ) ) {
			return false;
		}

		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Transient holding which page types a collection window has actually
	 * reached.
	 *
	 * An empty violation log has two completely different meanings — "the
	 * policy is clean" and "nothing exercised the pages where it is not" — and
	 * nothing in the UI could tell them apart. Reports alone cannot close that
	 * gap: a page that loads cleanly produces no report, so silence is
	 * indistinguishable from absence. Recording which page types were served a
	 * reporting policy is the missing half.
	 *
	 * @var string
	 */
	const CSP_COVERAGE_KEY = 'spfw_csp_coverage';

	/**
	 * Page types the coverage checklist tracks, in the order the UI lists them.
	 *
	 * The WooCommerce entries are filtered out on sites without it — see
	 * applicable_page_types().
	 *
	 * @var string[]
	 */
	const COLLECTION_PAGE_TYPES = array(
		'home',
		'page',
		'post',
		'archive',
		'search',
		'404',
		'shop',
		'product',
		'cart',
		'checkout',
		'account',
	);

	/**
	 * WooCommerce-only entries of COLLECTION_PAGE_TYPES.
	 *
	 * @var string[]
	 */
	const WOO_PAGE_TYPES = array( 'shop', 'product', 'cart', 'checkout', 'account' );

	/**
	 * The page types worth asking this site's admin to cover.
	 *
	 * @return string[]
	 */
	public static function applicable_page_types() {
		if ( class_exists( 'WooCommerce' ) ) {
			return self::COLLECTION_PAGE_TYPES;
		}

		return array_values( array_diff( self::COLLECTION_PAGE_TYPES, self::WOO_PAGE_TYPES ) );
	}

	/**
	 * Classify the current request for the coverage checklist.
	 *
	 * Order matters: cart, checkout and account are ordinary pages as far as
	 * `is_singular( 'page' )` is concerned, so the specific tests come first.
	 *
	 * @return string One of COLLECTION_PAGE_TYPES, or 'other'.
	 */
	public static function current_page_type() {
		// A list of pairs rather than a type => callback map: PHP coerces a
		// numeric-string array key, so '404' would become the integer 404 and
		// this method would return an int where every caller expects a string.
		$tests = array(
			array( '404', 'is_404' ),
			array( 'search', 'is_search' ),
			array( 'cart', 'is_cart' ),
			array( 'checkout', 'is_checkout' ),
			array( 'account', 'is_account_page' ),
			array( 'product', 'is_product' ),
			array( 'shop', 'is_shop' ),
		);

		foreach ( $tests as $test ) {
			list( $type, $fn ) = $test;

			if ( function_exists( $fn ) && call_user_func( $fn ) ) {
				return $type;
			}
		}

		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return 'home';
		}

		if ( function_exists( 'is_home' ) && is_home() ) {
			return 'home';
		}

		if ( function_exists( 'is_singular' ) ) {
			if ( is_singular( 'page' ) ) {
				return 'page';
			}

			if ( is_singular() ) {
				return 'post';
			}
		}

		if ( function_exists( 'is_archive' ) && is_archive() ) {
			return 'archive';
		}

		return 'other';
	}

	/**
	 * Record coverage for the request that is currently being served.
	 *
	 * Runs on `template_redirect` rather than `send_headers` — see the note at
	 * the call site — and only for requests that were actually sent a reporting
	 * policy, so the checklist reports what was tested rather than what was
	 * merely visited.
	 */
	public function record_coverage_for_request() {
		self::record_collection_coverage( SPFW_Settings::group( 'hardening' ) );
	}

	/**
	 * Note that this page type was served a reporting policy during the current
	 * window.
	 *
	 * Writes at most once per page type per window: a type already recorded
	 * costs one cached transient read and nothing else, so this stays off the
	 * hot path even with the page cache bypassed. Coverage is keyed to the
	 * window's deadline, so opening a new window starts from nothing rather
	 * than inheriting last week's checkmarks.
	 *
	 * @param array $h Hardening settings group.
	 */
	public static function record_collection_coverage( array $h ) {
		$window = isset( $h['csp_collect_until'] ) ? (int) $h['csp_collect_until'] : 0;

		if ( $window <= 0 ) {
			return;
		}

		$type = self::current_page_type();

		if ( 'other' === $type ) {
			return;
		}

		$store = self::read_coverage( $window );

		if ( isset( $store['types'][ $type ] ) ) {
			return;
		}

		$store['types'][ $type ] = time();

		set_transient( self::CSP_COVERAGE_KEY, $store, max( 60, $window - time() ) + DAY_IN_SECONDS );
	}

	/**
	 * Read the coverage store, discarding it if it belongs to an earlier window.
	 *
	 * @param int $window Current window deadline.
	 * @return array{window:int,types:array<string,int>}
	 */
	private static function read_coverage( $window ) {
		$raw = get_transient( self::CSP_COVERAGE_KEY );

		if ( ! is_array( $raw ) || ! isset( $raw['window'] ) || (int) $raw['window'] !== (int) $window ) {
			return array(
				'window' => (int) $window,
				'types'  => array(),
			);
		}

		return array(
			'window' => (int) $raw['window'],
			'types'  => isset( $raw['types'] ) && is_array( $raw['types'] ) ? $raw['types'] : array(),
		);
	}

	/**
	 * Coverage for the current window, as { type => bool }, for the admin UI.
	 *
	 * @return array<string,bool>
	 */
	public static function collection_coverage() {
		$h      = SPFW_Settings::group( 'hardening' );
		$window = isset( $h['csp_collect_until'] ) ? (int) $h['csp_collect_until'] : 0;
		$store  = self::read_coverage( $window );

		$out = array();

		foreach ( self::applicable_page_types() as $type ) {
			$out[ $type ] = isset( $store['types'][ $type ] );
		}

		return $out;
	}

	/**
	 * Whether this particular response is in the sampled share that carries
	 * `report-uri`. Lets a high-traffic site collect a representative sample
	 * instead of one report POST per page view.
	 *
	 * Note under full-page caching (LiteSpeed Cache, QUIC.cloud): the sample
	 * decision is made when the page is generated and then cached along with
	 * the response, so the effective sampling granularity is per cache entry
	 * rather than per visitor. That only lowers the report volume further,
	 * which is the direction we want.
	 *
	 * @param array $h Hardening settings group.
	 * @return bool
	 */
	private static function collection_sampled( array $h ) {
		$sample = isset( $h['csp_collect_sample'] ) ? (int) $h['csp_collect_sample'] : 100;

		if ( $sample >= 100 ) {
			return true;
		}

		if ( $sample < 1 ) {
			return false;
		}

		return wp_rand( 1, 100 ) <= $sample;
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
		return self::csp_report_url_public();
	}

	/**
	 * Public alias of csp_report_url() so SPFW_Rest_Settings can include the
	 * URL in the diagnostic stats payload without duplicating the logic.
	 *
	 * @return string
	 */
	public static function csp_report_url_public() {
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
	 * The plan also calls for always injecting when the report endpoint uses a
	 * non-default port, even when the hostname matches home_url() — which can
	 * happen on dev/staging.
	 *
	 * No-op when connect-src already allows 'self' or 'https:'.
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

		// Compare against the site's own origin (home_url), also checking port.
		$home_parts = wp_parse_url( home_url() );
		$home_origin = '';

		if ( is_array( $home_parts ) && ! empty( $home_parts['host'] ) ) {
			$home_origin = ( isset( $home_parts['scheme'] ) ? $home_parts['scheme'] : 'https' ) . '://' . $home_parts['host'];
		}

		// Inject when: different origin OR when the report endpoint uses a
		// non-default port (dev/staging scenarios with an explicit port).
		$report_port = isset( $report_parts['port'] ) ? (int) $report_parts['port'] : 0;
		$default_ports = array( 80, 443, 0 );
		$same_origin   = '' !== $home_origin && strtolower( $report_origin ) === strtolower( $home_origin );
		$non_default_port = $report_port > 0 && ! in_array( $report_port, $default_ports, true );

		if ( $same_origin && ! $non_default_port ) {
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
	 * Replace 'unsafe-inline' in script-src with the collected sha256 hashes,
	 * and — only when the admin has separately opted in — add 'strict-dynamic'.
	 *
	 * Hashes are stable across cache hits (unlike nonces), so they are correct
	 * under full-page caching. That is the whole of what hashing buys, and it
	 * is safe: a policy that lists hashes alongside its existing host sources
	 * still loads every script it loaded before.
	 *
	 * 'strict-dynamic' is a different and much larger change, which is why it
	 * is now its own setting rather than an unannounced rider on this one. Once
	 * present, supporting browsers IGNORE https: and every host source in
	 * script-src — trust propagates only from the hashed scripts to whatever
	 * they themselves load. So any <script src> written directly into the HTML
	 * by a theme or a third party (reCAPTCHA's api.js, a hand-placed GTM
	 * snippet) is refused unless a hashed script loaded it.
	 *
	 * Bundling the two meant "tighten script-src" silently discarded the host
	 * allowlist the admin had just built from the violation log, and broke
	 * exactly the third-party widgets the allowlist existed to permit.
	 *
	 * @param string   $policy         The CSP policy string.
	 * @param string[] $hashes         Base64-encoded sha256 digests.
	 * @param bool     $strict_dynamic Whether to add 'strict-dynamic' and drop
	 *                                 the host/scheme sources it would void.
	 * @return string
	 */
	private static function inject_script_hashes( $policy, array $hashes, $strict_dynamic = false ) {
		$directives = self::parse_policy_to_directives( $policy );

		if ( ! isset( $directives['script-src'] ) || ! is_array( $directives['script-src'] ) ) {
			return $policy;
		}

		// Remove 'unsafe-inline' — the hashes replace it. (A browser that
		// understands hashes ignores 'unsafe-inline' in the same directive
		// anyway; dropping it keeps the emitted header honest about that.
		// See the note above on why 'strict-dynamic' is not bundled in here.
		$script_src = array_filter(
			$directives['script-src'],
			static function ( $token ) {
				return "'unsafe-inline'" !== $token;
			}
		);

		if ( $strict_dynamic ) {
			// Drop the host and scheme sources 'strict-dynamic' would make the
			// browser ignore, so the emitted policy says what it means. Keep
			// 'self', 'none', and existing nonce/hash sources.
			$script_src = array_filter(
				$script_src,
				static function ( $token ) {
					return 0 === strpos( $token, "'" );
				}
			);
		}

		foreach ( $hashes as $hash ) {
			$script_src[] = "'sha256-" . $hash . "'";
		}

		if ( $strict_dynamic ) {
			$script_src[] = "'strict-dynamic'";
		}

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

	/**
	 * Scan wp-content/plugins/ and wp-content/uploads/ for PHP files and
	 * compare against the stored snapshot. Returns the diff (added, modified,
	 * removed) and persists the new snapshot + timestamp.
	 *
	 * @return array{added:string[],modified:string[],removed:string[],snapshot_time:int}
	 */
	public function scan_wp_content() {
		$dirs = array();

		if ( is_dir( WP_CONTENT_DIR . '/plugins' ) ) {
			$dirs['plugins'] = WP_CONTENT_DIR . '/plugins';
		}

		$uploads = wp_upload_dir();

		if ( isset( $uploads['basedir'] ) && is_dir( $uploads['basedir'] ) ) {
			$dirs['uploads'] = $uploads['basedir'];
		}

		$snapshot    = array();
		$extensions  = self::MONITOR_EXTENSIONS;
		$ext_pattern = '/\.(' . implode( '|', array_map( 'preg_quote', $extensions ) ) . ')$/i';

		foreach ( $dirs as $prefix => $base_dir ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				$filename = $file->getFilename();

				if ( ! preg_match( $ext_pattern, $filename ) ) {
					continue;
				}

				$full_path   = $file->getPathname();
				$relative    = $prefix . '/' . ltrim( substr( $full_path, strlen( $base_dir ) ), '/\\' );
				$hash        = hash_file( 'sha256', $full_path );
				$snapshot[ $relative ] = $hash;
			}
		}

		// Compare against the stored snapshot.
		$old_snapshot = SPFW_Settings::value( 'hardening', 'file_monitor_snapshot', array() );
		$old_snapshot = is_array( $old_snapshot ) ? $old_snapshot : array();

		$added    = array_diff( array_keys( $snapshot ), array_keys( $old_snapshot ) );
		$removed  = array_diff( array_keys( $old_snapshot ), array_keys( $snapshot ) );
		$modified = array();

		foreach ( $snapshot as $path => $hash ) {
			if ( isset( $old_snapshot[ $path ] ) && $old_snapshot[ $path ] !== $hash ) {
				$modified[] = $path;
			}
		}

		$now = time();

		// Persist the new snapshot and scan timestamp.
		SPFW_Settings::update(
			array(
				'hardening' => array(
					'file_monitor_snapshot'  => $snapshot,
					'file_monitor_last_scan' => $now,
				),
			)
		);

		return array(
			'added'         => array_values( $added ),
			'modified'      => array_values( $modified ),
			'removed'       => array_values( $removed ),
			'snapshot_time' => $now,
		);
	}

	/**
	 * Send a consolidated email alert when file changes are detected. Flags
	 * non-whitelisted additions and modifications. Rate-limits to one alert
	 * per hour via a transient.
	 *
	 * @param array $changes Diff array from scan_wp_content().
	 */
	public function maybe_send_file_alert( $changes ) {
		$h = SPFW_Settings::group( 'hardening' );

		if ( empty( $h['file_monitor_enabled'] ) ) {
			return;
		}

		$total = count( $changes['added'] ) + count( $changes['modified'] ) + count( $changes['removed'] );

		if ( 0 === $total ) {
			return;
		}

		// Rate-limit: one alert per hour.
		if ( get_transient( self::FILE_MONITOR_COOLDOWN ) ) {
			return;
		}

		$email = ! empty( $h['file_monitor_email'] ) ? $h['file_monitor_email'] : get_option( 'admin_email' );

		if ( ! is_email( $email ) ) {
			return;
		}

		$whitelist  = isset( $h['php_whitelist'] ) && is_array( $h['php_whitelist'] ) ? $h['php_whitelist'] : array();
		$site_name  = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$subject    = sprintf(
			/* translators: %s: site name */
			__( '[%s] File integrity alert', 'simple-performance-for-wordpress' ),
			$site_name
		);

		$body  = sprintf(
			/* translators: %s: site name */
			__( "File integrity scan detected changes on %s:\n\n", 'simple-performance-for-wordpress' ),
			$site_name
		);

		if ( ! empty( $changes['added'] ) ) {
			$body .= __( "NEW FILES:\n", 'simple-performance-for-wordpress' );

			foreach ( $changes['added'] as $path ) {
				$flag = in_array( $path, $whitelist, true ) ? '' : ' [NOT ON WHITELIST]';
				$body .= "  + {$path}{$flag}\n";
			}

			$body .= "\n";
		}

		if ( ! empty( $changes['modified'] ) ) {
			$body .= __( "MODIFIED FILES:\n", 'simple-performance-for-wordpress' );

			foreach ( $changes['modified'] as $path ) {
				$flag = in_array( $path, $whitelist, true ) ? '' : ' [NOT ON WHITELIST]';
				$body .= "  ~ {$path}{$flag}\n";
			}

			$body .= "\n";
		}

		if ( ! empty( $changes['removed'] ) ) {
			$body .= __( "REMOVED FILES:\n", 'simple-performance-for-wordpress' );

			foreach ( $changes['removed'] as $path ) {
				$body .= "  - {$path}\n";
			}

			$body .= "\n";
		}

		$body .= __( "Review these files immediately if the changes were not expected.\n", 'simple-performance-for-wordpress' );

		wp_mail( $email, $subject, $body );

		set_transient( self::FILE_MONITOR_COOLDOWN, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Cron callback: run the file-integrity scan and send an alert email
	 * when changes are detected.
	 */
	public function run_file_monitor_scan() {
		$changes = $this->scan_wp_content();
		$this->maybe_send_file_alert( $changes );
	}

}
