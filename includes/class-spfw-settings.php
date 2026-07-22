<?php
/**
 * Settings storage for Simple Performance for WordPress.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the single `spfw_settings` option.
 */
class SPFW_Settings {

	const OPTION_KEY = 'spfw_settings';

	/**
	 * Statically cached, fully-merged settings array.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Canonical defaults / schema.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'version'     => SPFW_VERSION,
			'core'        => array(
				'disable_emojis'         => true,
				'disable_embeds'         => true,
				'disable_dashicons'      => true,
				'disable_xmlrpc'         => true,
				'remove_rsd'             => true,
				'remove_wlwmanifest'     => true,
				'hide_wp_version'        => true,
				'remove_shortlink'       => true,
				'remove_rest_api_links'  => false,
				'disable_feeds'          => false,
				'feed_redirect_home'     => true,
				'remove_feed_links'      => false,
				'disable_self_pingbacks' => true,
				'remove_query_strings'   => false,
				'disable_google_maps'    => false,
				'google_maps_exceptions' => array(),
				'disable_password_meter' => false,
				'disable_comments'       => false,
				'remove_comment_urls'    => false,
				'blank_favicon'          => false,
				'heartbeat_control'      => 'default',
				'heartbeat_frequency'    => 0,
				'post_revisions'         => 'default',
				'autosave_interval'      => 0,
				'disable_jquery_migrate' => true,
				'disable_wp_sitemaps'    => false,
				'remove_robots_max_image_preview' => false,
			),
			'restapi'     => array(
				'require_auth'        => false,
				'disabled_namespaces' => array( 'wp/v2/users', 'wp/v2/themes', 'wp/v2/comments', 'wp/v2/settings', 'wp/v2/taxonomies' ),
				'whitelist_routes'    => array( 'contact-form-7/v1', 'wc/v3', 'wc/store' ),
			),
			'hardening'   => array(
				'plugins_htaccess'      => false,
				'htaccess_hash'         => '',
				'uploads_htaccess'      => false,
				'uploads_htaccess_hash' => '',
				'disable_file_editing'  => false,
				'block_author_enum'     => false,
				'security_headers'      => false,
				'csp_enabled'             => false,
				'csp_report_only'         => true,
				'csp_exclude_logged_in'   => true,
				'csp_mode'                => 'builder',
				'csp_directives'          => array(
					'default-src'     => array( "'self'" ),
					'script-src'      => array( "'self'", "'unsafe-inline'", 'https:', 'data:' ),
					'style-src'       => array( "'self'", "'unsafe-inline'", 'https:' ),
					'img-src'         => array( "'self'", 'data:', 'https:' ),
					'font-src'        => array( "'self'", 'data:', 'https:' ),
					'connect-src'     => array( "'self'" ),
					'media-src'       => array( "'self'" ),
					'worker-src'      => array( "'self'", 'blob:' ),
					'object-src'      => array( "'none'" ),
					'base-uri'        => array( "'self'" ),
					'frame-ancestors' => array( "'self'" ),
				),
				'csp_policy'              => '',
				'hsts_enabled'            => false,
				'hsts_max_age'            => 31536000,
				'hsts_include_subdomains' => false,
				'hsts_preload'            => false,
			),
			'fonts'       => array(
				'localize_google' => false,
				'discovered'      => array(),
				'last_scan'       => 0,
				'manual_families' => array(),
				'extra_scan_urls' => array(),
				'needs_rescan'    => false,
			),
			'woocommerce' => array(
				'disable_cart_fragments' => false,
				'disable_scripts_styles' => false,
				'disable_status_widget'  => false,
				'disable_widgets'        => false,
				'disable_password_meter' => false,
				'disable_marketing_hub'  => false,
			),
		);
	}

	/**
	 * Get the full, merged settings array. Issues at most one `get_option`
	 * call per request via a static cache.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		// Run migrations if upgrading from an older version (e.g. 1.0.0).
		$stored_ver = isset( $stored['version'] ) ? $stored['version'] : '1.0.0';
		if ( version_compare( $stored_ver, '1.1.1', '<' ) ) {
			self::run_migrations( $stored );
			// Re-fetch stored options after migration.
			$stored = get_option( self::OPTION_KEY, array() );
			$stored = is_array( $stored ) ? $stored : array();
		}

		// Migration to 1.6.0: the CSP builder replaced the single raw policy.
		// An install that already stored a custom policy should keep using it,
		// so pin csp_mode to 'custom' rather than silently switching them to the
		// (possibly different) structured default. Only runs when csp_mode is
		// still absent, so it never overrides a deliberate later choice.
		if ( version_compare( $stored_ver, '1.6.0', '<' )
			&& ! isset( $stored['hardening']['csp_mode'] )
			&& ! empty( $stored['hardening']['csp_policy'] ) ) {
			self::run_csp_mode_migration( $stored );
			$stored = get_option( self::OPTION_KEY, array() );
			$stored = is_array( $stored ) ? $stored : array();
		}

		// Migration to 1.7.1: prior versions deduped discovered @font-face
		// blocks by .woff2 URL, which collapsed variable-font families (e.g.
		// Roboto Condensed) down to their single heaviest requested weight.
		// An install that already has localized CSS on disk is flagged so the
		// admin UI can prompt a re-scan to pick up the fixed generator.
		if ( version_compare( $stored_ver, '1.7.1', '<' )
			&& ! empty( $stored['fonts']['discovered']['css'] ) ) {
			self::run_font_rescan_migration( $stored );
			$stored = get_option( self::OPTION_KEY, array() );
			$stored = is_array( $stored ) ? $stored : array();
		}

		self::$cache = self::merge_recursive( self::defaults(), $stored );

		return self::$cache;
	}

	/**
	 * Get one settings group.
	 *
	 * @param string $key One of core|restapi|hardening|fonts|woocommerce.
	 * @return array
	 */
	public static function group( $key ) {
		$settings = self::get();

		return isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();
	}

	/**
	 * Get a single value from a group.
	 *
	 * @param string $group    Group key.
	 * @param string $key      Value key within the group.
	 * @param mixed  $fallback Fallback if absent.
	 * @return mixed
	 */
	public static function value( $group, $key, $fallback = null ) {
		$grp = self::group( $group );

		return array_key_exists( $key, $grp ) ? $grp[ $key ] : $fallback;
	}

	/**
	 * Merge new (possibly partial) settings on top of the current stored
	 * settings, sanitize the result, persist it, and invalidate the cache.
	 *
	 * @param array $new Partial or full settings array, grouped like defaults().
	 * @return bool True on success.
	 */
	public static function update( array $new ) {
		$current = self::get();
		$merged  = self::merge_recursive( $current, $new );
		$clean   = self::sanitize( $merged );

		// Seed the static cache with the sanitized result *before*
		// update_option() fires its synchronous `update_option_{$option}`
		// hook. Listeners on that hook (notably SPFW_Module_Hardening, which
		// writes the .htaccess and then calls update() again to store its
		// hash) may re-enter update() before this method returns. If the
		// cache still held the pre-save state, that nested update would merge
		// its change onto stale values and silently revert the toggle we are
		// persisting here — the root cause of the hardening toggle not
		// sticking. Seeding the cache first keeps every re-entrant get()
		// consistent with what we are about to write.
		self::$cache = $clean;

		return update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Recursively merge $override on top of $base, one level of nested
	 * group arrays deep. Scalars and lists in $override replace those in
	 * $base outright; missing keys in $override fall back to $base.
	 *
	 * @param array $base     Base array (defaults or current settings).
	 * @param array $override Values to layer on top.
	 * @return array
	 */
	private static function merge_recursive( array $base, array $override ) {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::is_assoc( $value ) ) {
				$base[ $key ] = self::merge_recursive( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Whether an array is associative (a settings group) rather than a
	 * plain list (e.g. disabled_namespaces).
	 *
	 * @param array $arr Array to check.
	 * @return bool
	 */
	private static function is_assoc( array $arr ) {
		if ( array() === $arr ) {
			return false;
		}

		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Sanitize a full settings array into the canonical shape.
	 *
	 * @param array $input Raw/merged settings array.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$clean    = $defaults;

		$core = isset( $input['core'] ) && is_array( $input['core'] ) ? $input['core'] : array();

		$core_bools = array(
			'disable_emojis',
			'disable_embeds',
			'disable_dashicons',
			'disable_xmlrpc',
			'remove_rsd',
			'remove_wlwmanifest',
			'hide_wp_version',
			'remove_shortlink',
			'remove_rest_api_links',
			'disable_feeds',
			'feed_redirect_home',
			'remove_feed_links',
			'disable_self_pingbacks',
			'remove_query_strings',
			'disable_google_maps',
			'disable_password_meter',
			'disable_comments',
			'remove_comment_urls',
			'blank_favicon',
			'disable_jquery_migrate',
			'disable_wp_sitemaps',
			'remove_robots_max_image_preview',
		);

		foreach ( $core_bools as $key ) {
			$clean['core'][ $key ] = self::to_bool( $core, $key, $defaults['core'][ $key ] );
		}

		// Heartbeat: control mode (default|disable|allow_posts) + optional
		// polling frequency, using two separate controls.
		$heartbeat_control                  = isset( $core['heartbeat_control'] ) ? $core['heartbeat_control'] : $defaults['core']['heartbeat_control'];
		$clean['core']['heartbeat_control'] = in_array( $heartbeat_control, array( 'default', 'disable', 'allow_posts' ), true )
			? $heartbeat_control
			: 'default';

		$frequency                            = isset( $core['heartbeat_frequency'] ) ? absint( $core['heartbeat_frequency'] ) : 0;
		$clean['core']['heartbeat_frequency'] = in_array( $frequency, array( 15, 30, 60, 120 ), true ) ? $frequency : 0;

		// Post revisions: 'default' (leave WP alone) | 'disable' | integer 1..30.
		$revisions = isset( $core['post_revisions'] ) ? $core['post_revisions'] : 'default';
		if ( 'disable' === $revisions ) {
			$clean['core']['post_revisions'] = 'disable';
		} elseif ( is_numeric( $revisions ) ) {
			$clean['core']['post_revisions'] = (string) max( 1, min( 30, absint( $revisions ) ) );
		} else {
			$clean['core']['post_revisions'] = 'default';
		}

		// Autosave interval in minutes: 0 = WordPress default, else 1..10.
		$autosave                            = isset( $core['autosave_interval'] ) ? absint( $core['autosave_interval'] ) : 0;
		$clean['core']['autosave_interval'] = ( 0 === $autosave ) ? 0 : max( 1, min( 10, $autosave ) );

		$clean['core']['google_maps_exceptions'] = self::sanitize_route_list(
			isset( $core['google_maps_exceptions'] ) ? $core['google_maps_exceptions'] : $defaults['core']['google_maps_exceptions']
		);

		$restapi = isset( $input['restapi'] ) && is_array( $input['restapi'] ) ? $input['restapi'] : array();

		$clean['restapi']['require_auth']        = self::to_bool( $restapi, 'require_auth', $defaults['restapi']['require_auth'] );
		$clean['restapi']['disabled_namespaces']  = self::sanitize_route_list(
			isset( $restapi['disabled_namespaces'] ) ? $restapi['disabled_namespaces'] : $defaults['restapi']['disabled_namespaces']
		);
		$clean['restapi']['whitelist_routes']     = self::sanitize_route_list(
			isset( $restapi['whitelist_routes'] ) ? $restapi['whitelist_routes'] : $defaults['restapi']['whitelist_routes']
		);

		$hardening = isset( $input['hardening'] ) && is_array( $input['hardening'] ) ? $input['hardening'] : array();

		$hardening_bools = array(
			'plugins_htaccess',
			'uploads_htaccess',
			'disable_file_editing',
			'block_author_enum',
			'security_headers',
			'csp_enabled',
			'csp_report_only',
			'csp_exclude_logged_in',
			'hsts_enabled',
			'hsts_include_subdomains',
			'hsts_preload',
		);

		foreach ( $hardening_bools as $key ) {
			$clean['hardening'][ $key ] = self::to_bool( $hardening, $key, $defaults['hardening'][ $key ] );
		}

		$hash                                = isset( $hardening['htaccess_hash'] ) ? sanitize_text_field( $hardening['htaccess_hash'] ) : '';
		$clean['hardening']['htaccess_hash'] = preg_match( '/^[a-f0-9]{40}$/', $hash ) ? $hash : '';

		$uploads_hash                                = isset( $hardening['uploads_htaccess_hash'] ) ? sanitize_text_field( $hardening['uploads_htaccess_hash'] ) : '';
		$clean['hardening']['uploads_htaccess_hash'] = preg_match( '/^[a-f0-9]{40}$/', $uploads_hash ) ? $uploads_hash : '';

		// CSP policy: a single header value. Flatten any line breaks (the UI
		// uses a textarea for readability), collapse the runs of whitespace
		// they leave behind, strip control characters, and cap the length. Do
		// NOT run sanitize_text_field() here — it would strip the single quotes
		// around CSP keywords like 'self'/'unsafe-inline' that the policy needs.
		$csp_policy                        = isset( $hardening['csp_policy'] ) ? (string) $hardening['csp_policy'] : '';
		$csp_policy                        = preg_replace( '/[\r\n\t]+/', ' ', $csp_policy );
		$csp_policy                        = preg_replace( '/[\x00-\x1F\x7F]/', '', $csp_policy );
		$csp_policy                        = trim( preg_replace( '/ {2,}/', ' ', $csp_policy ) );
		$clean['hardening']['csp_policy']  = substr( $csp_policy, 0, 2000 );

		// CSP mode: builder (structured toggles) or custom (raw policy above).
		$csp_mode                       = isset( $hardening['csp_mode'] ) ? $hardening['csp_mode'] : $defaults['hardening']['csp_mode'];
		$clean['hardening']['csp_mode'] = in_array( $csp_mode, array( 'builder', 'custom' ), true ) ? $csp_mode : 'builder';

		// CSP directives: structured source of truth for builder mode. Whitelist
		// directive names, filter each source token to a safe charset (dropping
		// anything with ';', whitespace, or control chars so a token can never
		// inject a new directive), keep the single quotes CSP keywords need,
		// dedupe, and cap counts/length.
		$clean['hardening']['csp_directives'] = self::sanitize_csp_directives(
			isset( $hardening['csp_directives'] ) ? $hardening['csp_directives'] : $defaults['hardening']['csp_directives']
		);

		// HSTS max-age: whitelist to the durations offered in the UI.
		$hsts_max_age                       = isset( $hardening['hsts_max_age'] ) ? absint( $hardening['hsts_max_age'] ) : $defaults['hardening']['hsts_max_age'];
		$clean['hardening']['hsts_max_age'] = in_array( $hsts_max_age, array( 86400, 604800, 2592000, 15768000, 31536000, 63072000 ), true )
			? $hsts_max_age
			: $defaults['hardening']['hsts_max_age'];

		$fonts = isset( $input['fonts'] ) && is_array( $input['fonts'] ) ? $input['fonts'] : array();

		$clean['fonts']['localize_google'] = self::to_bool( $fonts, 'localize_google', $defaults['fonts']['localize_google'] );
		$clean['fonts']['discovered']      = isset( $fonts['discovered'] ) && is_array( $fonts['discovered'] ) ? $fonts['discovered'] : array();
		$clean['fonts']['last_scan']       = isset( $fonts['last_scan'] ) ? absint( $fonts['last_scan'] ) : 0;
		$clean['fonts']['manual_families'] = self::sanitize_font_families(
			isset( $fonts['manual_families'] ) ? $fonts['manual_families'] : $defaults['fonts']['manual_families']
		);
		$clean['fonts']['extra_scan_urls'] = self::sanitize_scan_urls(
			isset( $fonts['extra_scan_urls'] ) ? $fonts['extra_scan_urls'] : $defaults['fonts']['extra_scan_urls']
		);
		$clean['fonts']['needs_rescan']    = self::to_bool( $fonts, 'needs_rescan', $defaults['fonts']['needs_rescan'] );

		$woo = isset( $input['woocommerce'] ) && is_array( $input['woocommerce'] ) ? $input['woocommerce'] : array();

		foreach ( array_keys( $defaults['woocommerce'] ) as $key ) {
			$clean['woocommerce'][ $key ] = self::to_bool( $woo, $key, $defaults['woocommerce'][ $key ] );
		}

		$clean['version'] = SPFW_VERSION;

		return $clean;
	}

	/**
	 * Strict boolean cast of a group value with a fallback default.
	 *
	 * @param array  $group    Source group array.
	 * @param string $key      Key to read.
	 * @param bool   $fallback Fallback if the key is absent.
	 * @return bool
	 */
	private static function to_bool( array $group, $key, $fallback ) {
		if ( ! array_key_exists( $key, $group ) ) {
			return (bool) $fallback;
		}

		return (bool) $group[ $key ];
	}

	/**
	 * Directive names the builder is allowed to manage. Anything outside this
	 * list is dropped (custom/exotic directives belong in Advanced raw mode).
	 *
	 * @var string[]
	 */
	const CSP_DIRECTIVES = array(
		'default-src',
		'script-src',
		'style-src',
		'img-src',
		'font-src',
		'connect-src',
		'media-src',
		'worker-src',
		'object-src',
		'frame-src',
		'frame-ancestors',
		'base-uri',
		'form-action',
	);

	/**
	 * Sanitize a structured CSP directive map. Whitelists directive names,
	 * filters each source token to a safe charset (rejecting anything with a
	 * ';', whitespace, or control character so a token can never inject a new
	 * directive), keeps the single quotes CSP keywords require, dedupes, and
	 * caps counts/length.
	 *
	 * @param mixed $raw Directive => list of source tokens.
	 * @return array<string,string[]>
	 */
	private static function sanitize_csp_directives( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$keywords = array( "'self'", "'none'", "'unsafe-inline'", "'unsafe-eval'", "'strict-dynamic'" );
		$clean    = array();

		foreach ( $raw as $directive => $tokens ) {
			$directive = strtolower( trim( (string) $directive ) );

			if ( ! in_array( $directive, self::CSP_DIRECTIVES, true ) || ! is_array( $tokens ) ) {
				continue;
			}

			$valid = array();

			foreach ( $tokens as $token ) {
				$token = trim( (string) $token );

				// Reject anything that could break out of the directive.
				if ( '' === $token || preg_match( '/[\s;,\x00-\x1F\x7F]/', $token ) ) {
					continue;
				}

				if ( in_array( $token, $keywords, true ) ) {
					$valid[] = $token;
					continue;
				}

				// Scheme sources (https:, data:, blob:, wss:, ...) and host
				// sources (example.com, *.example.com, https://example.com).
				if ( preg_match( '#^[A-Za-z][A-Za-z0-9+.-]*:$#', $token )
					|| preg_match( '#^(https?://)?(\*\.)?[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~%/-]*)?$#', $token ) ) {
					$valid[] = $token;
				}
			}

			// Empty token-lists are preserved (not dropped): the builder shows a
			// fixed set of rows and always submits every one, so storing a
			// cleared directive as [] is what lets the deletion stick instead of
			// the default value resurrecting on the next merge. Emit-time
			// serialization skips empty directives.
			$clean[ $directive ] = array_slice( array_values( array_unique( $valid ) ), 0, 15 );
		}

		return $clean;
	}

	/**
	 * Sanitize a route/namespace list: trim, restrict characters, strip
	 * surrounding slashes, drop empties, de-duplicate.
	 *
	 * @param mixed $raw String (newline-separated) or array of route prefixes.
	 * @return string[]
	 */
	private static function sanitize_route_list( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\r\n]+/', (string) $raw );
		$clean = array();

		foreach ( $items as $item ) {
			$item = trim( sanitize_text_field( $item ) );
			$item = trim( $item, '/' );

			if ( '' === $item ) {
				continue;
			}

			if ( ! preg_match( '/^[A-Za-z0-9\/_.-]+$/', $item ) ) {
				continue;
			}

			$clean[] = $item;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize a list of manual Google Fonts declarations. Each entry is a
	 * `Family:weights` spec (e.g. `Roboto Condensed:400,700` or, with italics,
	 * `Roboto Condensed:400,400i,700`); a bare `Family` defaults to weight 400.
	 * These let an admin declare exactly which weights to localize when the
	 * automated homepage scan is blinded by proxy/CDN optimization — the reason
	 * a used weight (e.g. 400) can be missing while another (700) is found.
	 *
	 * The output is normalized to `Family:w1,w2,...` strings so the fonts module
	 * can build canonical `fonts.googleapis.com/css2` URLs from them.
	 *
	 * @param mixed $raw String (newline-separated) or array of specs.
	 * @return string[]
	 */
	private static function sanitize_font_families( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\r\n]+/', (string) $raw );
		$clean = array();

		foreach ( $items as $item ) {
			$item = trim( (string) $item );

			if ( '' === $item ) {
				continue;
			}

			$parts  = explode( ':', $item, 2 );
			$family = trim( $parts[0] );

			// Family names are letters/numbers/spaces/hyphens (matches Google's
			// catalog); reject anything else so a spec can't smuggle URL syntax.
			if ( '' === $family || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 \-]*$/', $family ) ) {
				continue;
			}

			$weights = array();

			if ( isset( $parts[1] ) ) {
				foreach ( preg_split( '/[,\s]+/', $parts[1] ) as $weight ) {
					$weight = trim( $weight );

					// 100–900, optional trailing `i` for the italic style.
					if ( preg_match( '/^([1-9]00)(i?)$/', $weight, $m ) ) {
						$weights[] = $m[1] . ( '' !== $m[2] ? 'i' : '' );
					}
				}
			}

			if ( empty( $weights ) ) {
				$weights = array( '400' );
			}

			$weights = array_values( array_unique( $weights ) );
			$clean[] = $family . ':' . implode( ',', $weights );
		}

		return array_slice( array_values( array_unique( $clean ) ), 0, 30 );
	}

	/**
	 * Sanitize a list of extra page URLs to include in a font scan (beyond the
	 * homepage), so weights only enqueued on inner templates — a single post,
	 * a WooCommerce product, a custom page — are discovered too. Each entry may
	 * be a root-relative path (`/shop/`) or an absolute same-origin URL; foreign
	 * hosts are dropped and the list is capped.
	 *
	 * @param mixed $raw String (newline-separated) or array of URLs/paths.
	 * @return string[]
	 */
	private static function sanitize_scan_urls( $raw ) {
		$items = is_array( $raw ) ? $raw : preg_split( '/[\r\n]+/', (string) $raw );
		$home  = wp_parse_url( home_url(), PHP_URL_HOST );
		$clean = array();

		foreach ( $items as $item ) {
			$item = trim( (string) $item );

			if ( '' === $item ) {
				continue;
			}

			// Keep root-relative paths as-is; validate absolute URLs are same-origin.
			if ( 0 === strpos( $item, '/' ) && 0 !== strpos( $item, '//' ) ) {
				$path = esc_url_raw( $item );

				if ( '' !== $path ) {
					$clean[] = $path;
				}

				continue;
			}

			$url = esc_url_raw( $item );

			if ( '' === $url ) {
				continue;
			}

			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( $home && $host && $host !== $home ) {
				continue;
			}

			$clean[] = $url;
		}

		return array_slice( array_values( array_unique( $clean ) ), 0, 10 );
	}

	/**
	 * Run upgrade migrations on settings.
	 *
	 * @param array $stored Currently stored settings.
	 */
	private static function run_migrations( array $stored ) {
		$updated = $stored;

		// Migration to 1.1.0: Append new default REST namespaces if upgrading from older version.
		if ( ! isset( $updated['restapi'] ) ) {
			$updated['restapi'] = array();
		}
		if ( ! isset( $updated['restapi']['disabled_namespaces'] ) ) {
			$updated['restapi']['disabled_namespaces'] = array( 'wp/v2/users', 'wp/v2/themes', 'wp/v2/comments', 'wp/v2/settings', 'wp/v2/taxonomies' );
		} else {
			$new_defaults = array( 'wp/v2/comments', 'wp/v2/settings', 'wp/v2/taxonomies' );
			foreach ( $new_defaults as $ns ) {
				if ( ! in_array( $ns, $updated['restapi']['disabled_namespaces'], true ) ) {
					$updated['restapi']['disabled_namespaces'][] = $ns;
				}
			}
		}

		$updated['version'] = '1.1.1';

		// Sanitize and update directly to avoid any potential loops.
		$clean = self::sanitize( self::merge_recursive( self::defaults(), $updated ) );
		update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Migration to 1.6.0: an install that already had a custom CSP policy
	 * string keeps it by switching to 'custom' mode, so the builder defaults
	 * never silently replace a hand-tuned policy.
	 *
	 * @param array $stored Currently stored settings.
	 */
	private static function run_csp_mode_migration( array $stored ) {
		$updated = $stored;

		if ( ! isset( $updated['hardening'] ) || ! is_array( $updated['hardening'] ) ) {
			$updated['hardening'] = array();
		}

		$updated['hardening']['csp_mode'] = 'custom';

		$clean = self::sanitize( self::merge_recursive( self::defaults(), $updated ) );
		update_option( self::OPTION_KEY, $clean );
	}

	/**
	 * Migration to 1.7.1: flag existing localized-font CSS as stale so the
	 * admin UI prompts a re-scan (see the version_compare() call site for
	 * why — the previous generator could collapse variable-font weights).
	 *
	 * @param array $stored Currently stored settings.
	 */
	private static function run_font_rescan_migration( array $stored ) {
		$updated = $stored;

		if ( ! isset( $updated['fonts'] ) || ! is_array( $updated['fonts'] ) ) {
			$updated['fonts'] = array();
		}

		$updated['fonts']['needs_rescan'] = true;

		$clean = self::sanitize( self::merge_recursive( self::defaults(), $updated ) );
		update_option( self::OPTION_KEY, $clean );
	}
}
