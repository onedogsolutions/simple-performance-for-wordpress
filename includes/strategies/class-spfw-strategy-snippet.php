<?php
/**
 * The vhost-snippet hardening strategy (nginx, IIS, and anything unrecognised).
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates the server configuration that would enforce the enabled toggles,
 * and writes nothing at all.
 *
 * This is the honest answer for every rule a plugin running as an unprivileged
 * PHP process cannot put into force by itself. On nginx that is most of them:
 * there is no per-directory configuration file, and there never will be, since
 * honoring one would cost a `stat()` walk up the tree on every request. Config
 * is read at startup or on SIGHUP and a reload needs root. It is also the only
 * answer for static files on any server without `.htaccess` — `readme.html`,
 * `license.txt`, a stray `*.sql` or `.env` are served without PHP ever running,
 * so no `auto_prepend_file` guard can see the request.
 *
 * The plugin's contribution is therefore the snippet plus the enforcement
 * probe: the snippet says what to paste, the probe says whether it took. Until
 * it does, the UI reports "not enforced" rather than a green badge over a file
 * nothing reads.
 *
 * For `readme.html` and `license.txt` there is a better option than either,
 * which needs no server access and works identically everywhere: delete them.
 * See SPFW_Module_Hardening::removable_files().
 */
class SPFW_Strategy_Snippet implements SPFW_Hardening_Strategy {

	/**
	 * PHP-family extension alternation, mirroring the `.htaccess` payloads in
	 * SPFW_Htaccess so a site cannot be protected against a different set of
	 * extensions depending on which server it runs.
	 *
	 * @var string
	 */
	const PHP_EXTENSIONS = 'php[0-9]*|phtml|phps|phar|inc';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function key() {
		return 'snippet';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return 'server configuration snippet';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The fallback: it covers a target precisely when nothing that can write a
	 * file covers it.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function supports( $target ) {
		if ( SPFW_Server::supports_htaccess() ) {
			return false;
		}

		if ( 'uploads' === $target ) {
			// A .user.ini blocks PHP execution here without any server access,
			// so the snippet is only the fallback when that is unavailable.
			return ! SPFW_Server::supports_user_ini();
		}

		return in_array( $target, array( 'plugins', 'root' ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Nothing to write. Reports success because the toggle itself is valid —
	 * the intent is stored and the snippet is surfaced — while `status()`
	 * reports `advisory` so no part of the UI can mistake this for enforcement.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function apply( $target ) {
		unset( $target );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function revert( $target ) {
		unset( $target );

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return string `advisory` — a rule exists on paper and nowhere else.
	 */
	public function status( $target ) {
		if ( ! $this->supports( $target ) ) {
			return 'unsupported';
		}

		return $this->target_enabled( $target ) ? 'advisory' : 'disabled';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function needs_resync( $target ) {
		unset( $target );

		return false;
	}

	/**
	 * Whether the toggles behind a target are on.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	private function target_enabled( $target ) {
		$h = SPFW_Settings::group( 'hardening' );

		if ( 'plugins' === $target ) {
			return ! empty( $h['plugins_htaccess'] );
		}

		if ( 'uploads' === $target ) {
			return ! empty( $h['uploads_htaccess'] );
		}

		return ! empty( $h['protect_sensitive_files'] ) || ! empty( $h['block_xmlrpc_file'] );
	}

	/**
	 * The URI path prefix for this install: '/' at the docroot, '/blog/' for a
	 * subdirectory install. Matches SPFW_Htaccess's own derivation so the
	 * snippet and the rewrite rules describe the same URLs.
	 *
	 * @return string
	 */
	private static function uri_base() {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		$path = is_string( $path ) ? trim( $path, '/' ) : '';

		return '/' . ( '' !== $path ? $path . '/' : '' );
	}

	/**
	 * The nginx `server { }` fragment for the enabled toggles.
	 *
	 * @return string Empty when no toggle needs server help.
	 */
	public static function nginx() {
		$h     = SPFW_Settings::group( 'hardening' );
		$base  = self::uri_base();
		$ext   = self::PHP_EXTENSIONS;
		$lines = array();

		if ( ! empty( $h['uploads_htaccess'] ) ) {
			$lines[] = '# Block direct PHP execution in wp-content/uploads.';
			$lines[] = 'location ~* ^' . $base . 'wp-content/uploads/.*\.(' . $ext . ')$ {';
			$lines[] = '    deny all;';
			$lines[] = '}';
			$lines[] = '';
		}

		if ( ! empty( $h['plugins_htaccess'] ) ) {
			$lines[] = '# Block direct PHP execution in wp-content/plugins.';

			foreach ( self::whitelisted_uris( 'plugins/' ) as $uri ) {
				$lines[] = '# Whitelisted, must stay reachable: ' . $uri;
				$lines[] = 'location = ' . $uri . ' {';
				$lines[] = '    include fastcgi_params;';
				$lines[] = '    # fastcgi_pass … — match your existing PHP location block.';
				$lines[] = '}';
			}

			$lines[] = 'location ~* ^' . $base . 'wp-content/plugins/.*\.(' . $ext . ')$ {';
			$lines[] = '    deny all;';
			$lines[] = '}';
			$lines[] = '';
		}

		if ( ! empty( $h['protect_sensitive_files'] ) ) {
			$lines[] = '# Sensitive files that leak version info, credentials or dumps.';
			$lines[] = 'location ~* ^' . $base . '(readme\.html|license\.txt|wp-config-sample\.php)$ {';
			$lines[] = '    deny all;';
			$lines[] = '}';
			$lines[] = 'location ~* \.(log|sql|bak|old|orig|env)$ {';
			$lines[] = '    deny all;';
			$lines[] = '}';
			$lines[] = '';
		}

		if ( ! empty( $h['block_xmlrpc_file'] ) ) {
			$lines[] = '# XML-RPC endpoint.';
			$lines[] = 'location = ' . $base . 'xmlrpc.php {';
			$lines[] = '    deny all;';
			$lines[] = '}';
			$lines[] = '';
		}

		if ( empty( $lines ) ) {
			return '';
		}

		// A .user.ini is plain text sitting under the document root, and nginx
		// will happily serve it on request. It holds no secrets — one
		// auto_prepend_file line — but it advertises the guard's exact path,
		// and the guard itself is a .php file inside uploads that the deny rule
		// above would already refuse. Denying both keeps the pair from being
		// enumerated.
		$lines[] = '# This plugin\'s own per-directory files (plain text under the docroot).';
		$lines[] = 'location ~* /\.user\.ini$ {';
		$lines[] = '    deny all;';
		$lines[] = '}';

		$header = array(
			'# Simple Performance for WordPress — nginx configuration.',
			'#',
			'# Paste inside the server { } block for this site, BEFORE the location',
			'# block that hands .php files to PHP-FPM: nginx evaluates regex locations',
			'# in the order they appear and the first match wins, so a deny rule placed',
			'# after the fastcgi_pass block never runs.',
			'#',
			'# Then: nginx -t && nginx -s reload   (needs root — PHP cannot do this).',
			'# Afterwards, run "Verify enforcement" here to confirm it took effect.',
			'',
		);

		return implode( "\n", array_merge( $header, $lines ) ) . "\n";
	}

	/**
	 * The IIS `web.config` rewrite rules for the enabled toggles.
	 *
	 * @return string Empty when no toggle needs server help.
	 */
	public static function iis() {
		$h     = SPFW_Settings::group( 'hardening' );
		$ext   = self::PHP_EXTENSIONS;
		$rules = array();

		if ( ! empty( $h['uploads_htaccess'] ) ) {
			$rules[] = self::iis_rule( 'spfw-uploads-php', '^wp-content/uploads/.*\.(' . $ext . ')$' );
		}

		if ( ! empty( $h['plugins_htaccess'] ) ) {
			$rules[] = self::iis_rule( 'spfw-plugins-php', '^wp-content/plugins/.*\.(' . $ext . ')$' );
		}

		if ( ! empty( $h['protect_sensitive_files'] ) ) {
			$rules[] = self::iis_rule( 'spfw-sensitive', '^(readme\.html|license\.txt|wp-config-sample\.php)$' );
			$rules[] = self::iis_rule( 'spfw-sensitive-ext', '\.(log|sql|bak|old|orig|env)$' );
		}

		if ( ! empty( $h['block_xmlrpc_file'] ) ) {
			$rules[] = self::iis_rule( 'spfw-xmlrpc', '^xmlrpc\.php$' );
		}

		if ( empty( $rules ) ) {
			return '';
		}

		$rules[] = self::iis_rule( 'spfw-user-ini', '(^|/)\.user\.ini$' );

		return "<!-- Simple Performance for WordPress — IIS rules.\n"
			. "     Merge these <rule> elements into the <rewrite><rules> section of the\n"
			. "     site's web.config, above the WordPress catch-all rule. Then run\n"
			. "     \"Verify enforcement\" here to confirm they took effect. -->\n"
			. implode( "\n", $rules ) . "\n";
	}

	/**
	 * One IIS deny rule.
	 *
	 * @param string $name    Rule name.
	 * @param string $pattern URL pattern.
	 * @return string
	 */
	private static function iis_rule( $name, $pattern ) {
		return '<rule name="' . esc_attr( $name ) . '" stopProcessing="true">' . "\n"
			. '    <match url="' . esc_attr( $pattern ) . '" ignoreCase="true" />' . "\n"
			. '    <action type="CustomResponse" statusCode="403" statusReason="Forbidden" statusDescription="Forbidden" />' . "\n"
			. '</rule>';
	}

	/**
	 * Whitelisted PHP URIs under a wp-content subdirectory, so the generated
	 * snippet keeps reachable what the admin explicitly allowed.
	 *
	 * @param string $prefix wp-content-relative prefix, e.g. 'plugins/'.
	 * @return string[] Absolute URI paths.
	 */
	private static function whitelisted_uris( $prefix ) {
		$uris = array();

		foreach ( (array) SPFW_Htaccess::effective_whitelist() as $path ) {
			$path = (string) $path;

			if ( 0 !== strpos( $path, $prefix ) || ! preg_match( '#^[A-Za-z0-9._/-]+$#', $path ) ) {
				continue;
			}

			$uris[] = self::uri_base() . 'wp-content/' . $path;
		}

		return array_values( array_unique( $uris ) );
	}

	/**
	 * The snippet for the detected server, with the syntax it uses.
	 *
	 * @return array{format:string,body:string}
	 */
	public static function snippet() {
		if ( SPFW_Server::IIS === SPFW_Server::detect() ) {
			return array(
				'format' => 'xml',
				'body'   => self::iis(),
			);
		}

		return array(
			'format' => 'nginx',
			'body'   => self::nginx(),
		);
	}
}
