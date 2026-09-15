<?php
/**
 * Web-server detection and per-directory-configuration capability reporting.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * What kind of web server is in front of PHP, and what per-directory
 * configuration mechanisms it actually honors.
 *
 * The hardening subsystem was written against a server that reads `.htaccess`.
 * On nginx that assumption is not weaker, it is absent: nginx has no
 * per-directory configuration file and will not get one, because honoring one
 * would cost a `stat()` walk up the tree on every request. Its configuration is
 * read at startup or on SIGHUP, and a reload needs root — which PHP does not
 * have and must not acquire. So a `.htaccess` written on an nginx host is a file
 * nothing reads, behind a UI implying the directory is protected.
 *
 * Everything this class reports is read from the live request and the live PHP
 * configuration rather than assumed, because the defaults are exactly what a
 * host is most likely to have changed:
 *
 * - `$_SERVER['SERVER_SOFTWARE']` is set by the server itself.
 * - `php_sapi_name()` distinguishes mod_php (`apache2handler`, which does NOT
 *   read `.user.ini`) from the FastCGI family (which does), and identifies
 *   LiteSpeed's own `litespeed` SAPI.
 * - `user_ini.filename` and `user_ini.cache_ttl` are read with ini_get(), never
 *   assumed to be `.user.ini` / 300. A host that sets `user_ini.filename` empty
 *   has disabled the mechanism entirely, and a `.user.ini` written there would
 *   be inert.
 */
class SPFW_Server {

	const APACHE        = 'apache';
	const LITESPEED     = 'litespeed';
	const OPENLITESPEED = 'openlitespeed';
	const NGINX         = 'nginx';
	const IIS           = 'iis';
	const UNKNOWN       = 'unknown';

	/**
	 * SAPIs that read per-directory `.user.ini` files.
	 *
	 * PHP reads `.user.ini` only under the CGI/FastCGI family. Under mod_php
	 * (`apache2handler`) the equivalent settings live in `.htaccess` instead,
	 * and under CLI there is no per-directory scan at all.
	 *
	 * Deliberately an allow-list rather than a deny-list: an unrecognised SAPI
	 * reports "no", so the plugin declines to write a file that may never be
	 * read. An inert `.user.ini` is not merely useless — it names an
	 * `auto_prepend_file` that no request would ever load, which is a claim of
	 * protection with nothing behind it.
	 *
	 * @var string[]
	 */
	const USER_INI_SAPIS = array( 'fpm-fcgi', 'cgi-fcgi', 'cgi', 'litespeed' );

	/**
	 * Cached detection result, so repeated calls in one request do not re-parse
	 * the server string. Null until first detect().
	 *
	 * @var string|null
	 */
	private static $detected = null;

	/**
	 * Clear the cached detection. Only needed by tests, which change
	 * `$_SERVER['SERVER_SOFTWARE']` between cases.
	 */
	public static function reset_detection() {
		self::$detected = null;
	}

	/**
	 * The raw server identification string, sanitized.
	 *
	 * @return string Empty when the server does not advertise itself.
	 */
	public static function software() {
		// Read-only capability probe: the value only decides which hardening
		// strategy this install can use. Nothing is written or authorized on
		// the strength of it, so no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification
		return sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) );
	}

	/**
	 * Which web server is in front of PHP.
	 *
	 * OpenLiteSpeed and LiteSpeed Enterprise both commonly advertise themselves
	 * as plain "LiteSpeed", so `openlitespeed` is only returned when the server
	 * says so explicitly. The two are treated identically everywhere strategy
	 * selection is concerned — both read `.htaccess` — so the ambiguity costs
	 * nothing but the label, and guessing would be worse than admitting it.
	 *
	 * @return string One of the class constants.
	 */
	public static function detect() {
		if ( null !== self::$detected ) {
			return self::$detected;
		}

		$software = strtolower( self::software() );
		$sapi     = strtolower( (string) php_sapi_name() );

		if ( false !== strpos( $software, 'openlitespeed' ) ) {
			self::$detected = self::OPENLITESPEED;
		} elseif ( false !== strpos( $software, 'litespeed' ) || 'litespeed' === $sapi ) {
			self::$detected = self::LITESPEED;
		} elseif ( false !== strpos( $software, 'nginx' ) ) {
			self::$detected = self::NGINX;
		} elseif ( false !== strpos( $software, 'apache' ) ) {
			self::$detected = self::APACHE;
		} elseif ( false !== strpos( $software, 'microsoft-iis' ) || false !== strpos( $software, 'iis/' ) ) {
			self::$detected = self::IIS;
		} elseif ( function_exists( 'apache_get_modules' ) ) {
			// mod_php exposes this even where SERVER_SOFTWARE has been blanked
			// by ServerTokens Prod or a reverse proxy rewriting the header.
			self::$detected = self::APACHE;
		} else {
			self::$detected = self::UNKNOWN;
		}

		/**
		 * Filter the detected web server.
		 *
		 * Detection reads what the server says about itself, and a reverse
		 * proxy, a container image or a `ServerTokens` setting can all make
		 * that wrong. A host that knows better can say so, and every strategy
		 * decision follows from this one value.
		 *
		 * @param string $kind One of apache|litespeed|openlitespeed|nginx|iis|unknown.
		 */
		self::$detected = (string) apply_filters( 'spfw_server_kind', self::$detected );

		return self::$detected;
	}

	/**
	 * Whether this server reads per-directory `.htaccess` files.
	 *
	 * True for the Apache and LiteSpeed families only. Note that "reads" is not
	 * "honors": an Apache vhost with `AllowOverride None`, or an OpenLiteSpeed
	 * vhost with "Auto Load from .htaccess" off, reads nothing either. That
	 * distinction is not knowable from PHP, which is why the enforcement probe
	 * — not this method — remains the single source of truth for whether a rule
	 * is actually in force.
	 *
	 * @return bool
	 */
	public static function supports_htaccess() {
		return in_array(
			self::detect(),
			array( self::APACHE, self::LITESPEED, self::OPENLITESPEED ),
			true
		);
	}

	/**
	 * The live `user_ini.filename` value.
	 *
	 * An empty string means the host has disabled per-directory PHP settings
	 * outright, and any file this plugin wrote under that name would be inert.
	 *
	 * @return string
	 */
	public static function user_ini_filename() {
		$name = ini_get( 'user_ini.filename' );

		return is_string( $name ) ? trim( $name ) : '';
	}

	/**
	 * The live `user_ini.cache_ttl`, in seconds.
	 *
	 * This is the whole reason `.user.ini` is interesting on nginx: unlike a
	 * rewrite-rule change, which needs a privileged reload, a `.user.ini` edit
	 * applies itself once this many seconds have passed. It is a delay, not a
	 * privileged action. The default is 300, but a host may have changed it, so
	 * the staleness clock the UI shows is built from the live value.
	 *
	 * @return int Seconds; 0 when the directive is unset or unparsable.
	 */
	public static function user_ini_cache_ttl() {
		$ttl = ini_get( 'user_ini.cache_ttl' );

		return is_string( $ttl ) && '' !== $ttl ? max( 0, (int) $ttl ) : 0;
	}

	/**
	 * Whether a `.user.ini` written into a directory would actually be read.
	 *
	 * @return bool
	 */
	public static function supports_user_ini() {
		$supported = '' !== self::user_ini_filename()
			&& in_array( strtolower( (string) php_sapi_name() ), self::USER_INI_SAPIS, true );

		/**
		 * Filter whether a `.user.ini` written here would be read.
		 *
		 * The SAPI allow-list is deliberately conservative, so a stack that
		 * does support per-directory ini files under a name this plugin does
		 * not recognise can opt in. Answering `true` where it is not true
		 * produces a file naming an `auto_prepend_file` nothing loads.
		 *
		 * @param bool $supported Whether `.user.ini` is read on this stack.
		 */
		return (bool) apply_filters( 'spfw_supports_user_ini', $supported );
	}

	/**
	 * How a configuration change on this server reaches the running process.
	 *
	 * This is the *config* staleness clock and nothing else — it says when a
	 * rule written to disk starts being applied. It is unrelated to the cache
	 * clock, which governs when already-rendered pages stop being served.
	 * Conflating the two produced the false lead recorded against Step 15.
	 *
	 * @return string One of immediate|restart|reload|ttl|unknown.
	 */
	public static function config_refresh() {
		switch ( self::detect() ) {
			case self::APACHE:
				// .htaccess is re-read per request.
				return 'immediate';
			case self::LITESPEED:
				// LiteSpeed Enterprise re-reads per request like Apache.
				return 'immediate';
			case self::OPENLITESPEED:
				// OLS parses .htaccess rewrite rules once per directory at
				// startup and caches them until a graceful restart.
				return 'restart';
			case self::NGINX:
				// Config is read at startup or SIGHUP; `nginx -s reload` needs
				// root, which PHP does not have.
				return 'reload';
			case self::IIS:
				return 'immediate';
			default:
				return 'unknown';
		}
	}

	/**
	 * Capability report for the REST layer and the admin UI.
	 *
	 * @return array{kind:string,software:string,supports_htaccess:bool,supports_user_ini:bool,user_ini_filename:string,user_ini_cache_ttl:int,config_refresh:string}
	 */
	public static function info() {
		return array(
			'kind'               => self::detect(),
			'software'           => self::software(),
			'sapi'               => (string) php_sapi_name(),
			'supports_htaccess'  => self::supports_htaccess(),
			'supports_user_ini'  => self::supports_user_ini(),
			'user_ini_filename'  => self::user_ini_filename(),
			'user_ini_cache_ttl' => self::user_ini_cache_ttl(),
			'config_refresh'     => self::config_refresh(),
		);
	}
}
