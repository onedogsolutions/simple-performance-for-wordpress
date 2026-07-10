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
			'version'   => SPFW_VERSION,
			'core'      => array(
				'disable_emojis'         => true,
				'disable_embeds'         => true,
				'disable_dashicons'      => true,
				'disable_xmlrpc'         => true,
				'remove_rsd'             => true,
				'remove_wlwmanifest'     => true,
				'disable_feeds'          => false,
				'feed_redirect_home'     => true,
				'remove_query_strings'   => false,
				'heartbeat_mode'         => 'modify',
				'heartbeat_interval'     => 60,
				'disable_jquery_migrate' => true,
			),
			'restapi'   => array(
				'require_auth'        => false,
				'disabled_namespaces' => array( 'wp/v2/users', 'wp/v2/themes' ),
				'whitelist_routes'    => array( 'contact-form-7/v1', 'wc/v3', 'wc/store' ),
			),
			'hardening' => array(
				'plugins_htaccess' => false,
				'htaccess_hash'    => '',
			),
			'fonts'     => array(
				'localize_google' => false,
				'discovered'      => array(),
				'last_scan'       => 0,
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
	 * @param string $key One of core|restapi|hardening|fonts.
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

		$clean['core']['disable_emojis']         = self::to_bool( $core, 'disable_emojis', $defaults['core']['disable_emojis'] );
		$clean['core']['disable_embeds']         = self::to_bool( $core, 'disable_embeds', $defaults['core']['disable_embeds'] );
		$clean['core']['disable_dashicons']      = self::to_bool( $core, 'disable_dashicons', $defaults['core']['disable_dashicons'] );
		$clean['core']['disable_xmlrpc']         = self::to_bool( $core, 'disable_xmlrpc', $defaults['core']['disable_xmlrpc'] );
		$clean['core']['remove_rsd']             = self::to_bool( $core, 'remove_rsd', $defaults['core']['remove_rsd'] );
		$clean['core']['remove_wlwmanifest']     = self::to_bool( $core, 'remove_wlwmanifest', $defaults['core']['remove_wlwmanifest'] );
		$clean['core']['disable_feeds']          = self::to_bool( $core, 'disable_feeds', $defaults['core']['disable_feeds'] );
		$clean['core']['feed_redirect_home']     = self::to_bool( $core, 'feed_redirect_home', $defaults['core']['feed_redirect_home'] );
		$clean['core']['remove_query_strings']   = self::to_bool( $core, 'remove_query_strings', $defaults['core']['remove_query_strings'] );
		$clean['core']['disable_jquery_migrate'] = self::to_bool( $core, 'disable_jquery_migrate', $defaults['core']['disable_jquery_migrate'] );

		$heartbeat_mode                  = isset( $core['heartbeat_mode'] ) ? $core['heartbeat_mode'] : $defaults['core']['heartbeat_mode'];
		$clean['core']['heartbeat_mode'] = in_array( $heartbeat_mode, array( 'default', 'modify', 'disable' ), true )
			? $heartbeat_mode
			: 'modify';

		$interval                            = isset( $core['heartbeat_interval'] ) ? absint( $core['heartbeat_interval'] ) : $defaults['core']['heartbeat_interval'];
		$clean['core']['heartbeat_interval'] = max( 15, min( 300, $interval ) );

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
