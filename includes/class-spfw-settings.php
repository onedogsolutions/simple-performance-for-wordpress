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
				'disable_password_meter' => false,
				'disable_comments'       => false,
				'remove_comment_urls'    => false,
				'blank_favicon'          => false,
				'heartbeat_control'      => 'default',
				'heartbeat_frequency'    => 0,
				'post_revisions'         => 'default',
				'autosave_interval'      => 0,
				'disable_jquery_migrate' => true,
			),
			'restapi'     => array(
				'require_auth'        => false,
				'disabled_namespaces' => array( 'wp/v2/users', 'wp/v2/themes' ),
				'whitelist_routes'    => array( 'contact-form-7/v1', 'wc/v3', 'wc/store' ),
			),
			'hardening'   => array(
				'plugins_htaccess' => false,
				'htaccess_hash'    => '',
			),
			'fonts'       => array(
				'localize_google' => false,
				'discovered'      => array(),
				'last_scan'       => 0,
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

		$stored       = get_option( self::OPTION_KEY, array() );
		self::$cache  = self::merge_recursive( self::defaults(), is_array( $stored ) ? $stored : array() );

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

		$result      = update_option( self::OPTION_KEY, $clean );
		self::$cache = null;

		return $result;
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
		);

		foreach ( $core_bools as $key ) {
			$clean['core'][ $key ] = self::to_bool( $core, $key, $defaults['core'][ $key ] );
		}

		// Heartbeat: control mode (default|disable|allow_posts) + optional
		// polling frequency, matching Perfmatters' two separate controls.
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

		$restapi = isset( $input['restapi'] ) && is_array( $input['restapi'] ) ? $input['restapi'] : array();

		$clean['restapi']['require_auth']        = self::to_bool( $restapi, 'require_auth', $defaults['restapi']['require_auth'] );
		$clean['restapi']['disabled_namespaces']  = self::sanitize_route_list(
			isset( $restapi['disabled_namespaces'] ) ? $restapi['disabled_namespaces'] : $defaults['restapi']['disabled_namespaces']
		);
		$clean['restapi']['whitelist_routes']     = self::sanitize_route_list(
			isset( $restapi['whitelist_routes'] ) ? $restapi['whitelist_routes'] : $defaults['restapi']['whitelist_routes']
		);

		$hardening = isset( $input['hardening'] ) && is_array( $input['hardening'] ) ? $input['hardening'] : array();

		$clean['hardening']['plugins_htaccess'] = self::to_bool( $hardening, 'plugins_htaccess', $defaults['hardening']['plugins_htaccess'] );

		$hash                                = isset( $hardening['htaccess_hash'] ) ? sanitize_text_field( $hardening['htaccess_hash'] ) : '';
		$clean['hardening']['htaccess_hash'] = preg_match( '/^[a-f0-9]{40}$/', $hash ) ? $hash : '';

		$fonts = isset( $input['fonts'] ) && is_array( $input['fonts'] ) ? $input['fonts'] : array();

		$clean['fonts']['localize_google'] = self::to_bool( $fonts, 'localize_google', $defaults['fonts']['localize_google'] );
		$clean['fonts']['discovered']      = isset( $fonts['discovered'] ) && is_array( $fonts['discovered'] ) ? $fonts['discovered'] : array();
		$clean['fonts']['last_scan']       = isset( $fonts['last_scan'] ) ? absint( $fonts['last_scan'] ) : 0;

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
}
