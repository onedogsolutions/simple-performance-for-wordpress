<?php
/**
 * Directory .htaccess writer/verifier for the deny-PHP hardening files.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared utility for the hardening .htaccess files dropped into
 * /wp-content/plugins/, the uploads directory, and the site root.
 *
 * Two ownership modes:
 * - `own_file`: we author the entire file (plugins/, uploads/). Integrity =
 *   sha1 of the whole file.
 * - `marker_block`: we write via insert_with_markers() so WordPress' own
 *   rewrite rules are preserved (site root). Integrity = sha1 of the
 *   extracted block between our BEGIN/END markers, not the whole file.
 *
 * Only ever deletes a file it authored itself (sha1 match against the stored
 * hash) — never touches a foreign file.
 */
class SPFW_Htaccess {

	/**
	 * Marker name used for insert_with_markers() in marker_block mode.
	 */
	const MARKER = 'Simple Performance for WordPress';

	/**
	 * Per-target configuration map.
	 *
	 * Each entry: path (callable|string), toggle, hash_key, mode, payload_method.
	 *
	 * @return array<string,array>
	 */
	private static function targets() {
		return array(
			'plugins' => array(
				'path'           => WP_CONTENT_DIR . '/plugins/.htaccess',
				'toggle'         => 'plugins_htaccess',
				'hash_key'       => 'htaccess_hash',
				'mode'           => 'own_file',
				'payload_method' => 'payload_deny_php',
			),
			'uploads' => array(
				'path'           => null, // resolved dynamically via wp_upload_dir()
				'toggle'         => 'uploads_htaccess',
				'hash_key'       => 'uploads_htaccess_hash',
				'mode'           => 'own_file',
				'payload_method' => 'payload_deny_php',
			),
			'root'    => array(
				'path'           => null, // resolved dynamically via get_home_path()
				'toggle'         => null, // composed from two toggles
				'hash_key'       => 'root_htaccess_hash',
				'mode'           => 'marker_block',
				'payload_method' => 'payload_root',
			),
		);
	}

	/**
	 * Resolve the file path for a target.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return string
	 */
	private static function resolve_path( $target ) {
		if ( 'uploads' === $target ) {
			$uploads = wp_upload_dir();

			return trailingslashit( $uploads['basedir'] ) . '.htaccess';
		}

		if ( 'root' === $target ) {
			return self::get_home_path() . '.htaccess';
		}

		return WP_CONTENT_DIR . '/plugins/.htaccess';
	}

	/**
	 * Get the WordPress home path (filesystem, with trailing slash).
	 *
	 * @return string
	 */
	private static function get_home_path() {
		if ( function_exists( 'get_home_path' ) ) {
			return get_home_path();
		}

		// Fallback: derive from ABSPATH.
		return trailingslashit( ABSPATH );
	}

	/**
	 * Per-target configuration (legacy-compatible interface).
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return array{path:string,toggle:string,hash:string,mode:string}
	 */
	private static function config( $target ) {
		$targets = self::targets();
		$entry   = isset( $targets[ $target ] ) ? $targets[ $target ] : $targets['plugins'];

		return array(
			'path'   => self::resolve_path( $target ),
			'toggle' => $entry['toggle'],
			'hash'   => $entry['hash_key'],
			'mode'   => $entry['mode'],
		);
	}

	/**
	 * Path to a target's .htaccess file.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return string
	 */
	public static function path( $target = 'plugins' ) {
		return self::resolve_path( $target );
	}

	/**
	 * The deny-PHP payload for plugins/ and uploads/ directories.
	 *
	 * Uses FilesMatch with a PCRE pattern to cover .php, .php5, .php7,
	 * .phtml, .phps, .phar, and .inc — the extensions a dropper uses once
	 * .php is blocked. Deliberately does not emit `Options -Indexes`, which
	 * would additionally require `AllowOverride Options` and can 500 an
	 * Apache vhost that lacks it.
	 *
	 * A mod_rewrite rule is emitted first so OpenLiteSpeed (which honors
	 * RewriteRule in .htaccess but does not honor <FilesMatch>) still refuses
	 * direct PHP requests. The FilesMatch block is kept for Apache and older
	 * LiteSpeed installations.
	 *
	 * Note: payload() now routes plugins/uploads targets through
	 * payload_deny_php_for_target() which is whitelist-aware. This method
	 * is retained as the canonical blanket-deny payload for legacy callers
	 * and the prior-payload hash comparison used by run_payload_migration().
	 *
	 * @return string
	 */
	public static function payload_deny_php() {
		$ext_pattern_filesmatch = '\\\\.(?i:php[0-9]*|phtml|phps|phar|inc)$';
		$ext_pattern_rewrite    = '\\.(?i:php[0-9]*|phtml|phps|phar|inc)$';
		$files_match            = "<FilesMatch \"$ext_pattern_filesmatch\">\n"
			. "\tRequire all denied\n"
			. "</FilesMatch>\n"
			. "# Fallback for older Apache:\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\t<FilesMatch \"$ext_pattern_filesmatch\">\n"
			. "\t\tOrder allow,deny\n"
			. "\t\tDeny from all\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n";

		return "# BEGIN Simple Performance for WordPress\n"
			. "# Block direct PHP execution in this directory (Apache / OLS-with-override).\n"
			. "RewriteEngine On\n"
			. "RewriteRule $ext_pattern_rewrite - [F,L]\n"
			. $files_match
			. "# END Simple Performance for WordPress\n";
	}

	/**
	 * The URI path prefix for the current WordPress install, derived from
	 * home_url(). For a root install this is '/'; for a subdirectory install
	 * like https://example.com/blog it is '/blog/'. Used to build correct
	 * RewriteCond %{REQUEST_URI} patterns.
	 *
	 * @return string URI prefix with leading and trailing slash.
	 */
	private static function get_uri_base() {
		$path = wp_parse_url( home_url(), PHP_URL_PATH );
		$path = is_string( $path ) ? trim( $path, '/' ) : '';

		return '/' . ( '' !== $path ? $path . '/' : '' );
	}

	/**
	 * Build a root-level RewriteRule pattern that respects subdirectory installs.
	 *
	 * The pattern matches the REQUEST_URI with an optional leading slash, then
	 * the site path prefix, then the caller-supplied suffix. For a root install
	 * the prefix is empty; for https://example.com/blog the prefix is 'blog/'.
	 *
	 * @param string $suffix Regex fragment to append after the URI base.
	 * @return string Full pattern suitable for a RewriteRule first argument.
	 */
	private static function root_rewrite_pattern( $suffix ) {
		$base   = ltrim( self::get_uri_base(), '/' );
		$prefix = '' !== $base ? preg_quote( $base, '/' ) : '';

		return '^/?' . $prefix . $suffix;
	}

	/**
	 * The whitelist the payload generator actually uses: the admin's own
	 * php_whitelist, plus the PHP files that well-known plugins serve directly
	 * over HTTP and that are genuinely installed on this site.
	 *
	 * The auto-added half exists to make the FIRST write correct. Without it
	 * the sequence on a LiteSpeed site is: enable hardening, write, restart the
	 * server, discover the front end is broken because guest.vary.php now 403s,
	 * whitelist it, write again, restart again. On OpenLiteSpeed that is two
	 * restarts with a broken site in between, because OLS parses .htaccess
	 * rewrite rules once at startup and caches them — an edit is inert until
	 * the next graceful restart. Emitting the allowance up front collapses that
	 * to a single restart with no broken window.
	 *
	 * Gated on the file existing, so this is not a blanket hole: a site without
	 * LiteSpeed gets no LiteSpeed allowance. Gated again on the
	 * `auto_allow_known_php` toggle, so an admin who wants a total deny — for
	 * instance one who does not use Guest Mode — can turn it off and get the
	 * old behavior.
	 *
	 * @return string[] wp-content-relative paths.
	 */
	public static function effective_whitelist() {
		$whitelist = SPFW_Settings::value( 'hardening', 'php_whitelist', array() );
		$whitelist = is_array( $whitelist ) ? $whitelist : array();

		if ( ! SPFW_Settings::value( 'hardening', 'auto_allow_known_php', true ) ) {
			return $whitelist;
		}

		return array_values( array_unique( array_merge( $whitelist, self::auto_allowed_paths() ) ) );
	}

	/**
	 * Known direct-access PHP files that are actually present on disk.
	 *
	 * Guarded with class_exists so the .htaccess subsystem stays usable if the
	 * hardening module has not been loaded (uninstall, partial bootstraps).
	 *
	 * @return string[] wp-content-relative paths.
	 */
	public static function auto_allowed_paths() {
		if ( ! class_exists( 'SPFW_Module_Hardening' ) ) {
			return array();
		}

		$found = array();

		foreach ( array_keys( SPFW_Module_Hardening::KNOWN_DIRECT_ACCESS_PHP ) as $path ) {
			if ( file_exists( WP_CONTENT_DIR . '/' . $path ) ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * Build the deny-PHP payload for a specific target directory. This is the
	 * preferred entry point when the target is known — it avoids the call-stack
	 * inspection hack in current_dir_prefix() by accepting the target directly.
	 *
	 * @param string $target One of 'plugins'|'uploads'.
	 * @return string
	 */
	public static function payload_deny_php_for_target( $target ) {
		$whitelist  = self::effective_whitelist();
		$dir_prefix = $target . '/';
		$applicable = array();

		if ( is_array( $whitelist ) ) {
			foreach ( $whitelist as $path ) {
				$path = (string) $path;

				if ( 0 !== strpos( $path, $dir_prefix ) ) {
					continue;
				}

				// The path is interpolated into .htaccess directives, so a
				// quote or backslash in it would produce a syntactically
				// invalid file and 500 the directory. The sanitizer rejects
				// these on save; this guard also covers values stored before
				// it did.
				if ( ! preg_match( '#^[A-Za-z0-9._/-]+$#', $path ) ) {
					continue;
				}

				$applicable[] = $path;
			}
		}

		$applicable = array_slice( $applicable, 0, 20 );

		$ext_pattern_filesmatch = '\\\\.(?i:php[0-9]*|phtml|phps|phar|inc)$';
		$ext_pattern_rewrite    = '\\.(?i:php[0-9]*|phtml|phps|phar|inc)$';

		// Build the blanket-deny FilesMatch block.
		$deny_files = "<FilesMatch \"$ext_pattern_filesmatch\">\n"
			. "\tRequire all denied\n"
			. "</FilesMatch>\n"
			. "# Fallback for older Apache:\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\t<FilesMatch \"$ext_pattern_filesmatch\">\n"
			. "\t\tOrder allow,deny\n"
			. "\t\tDeny from all\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n";

		if ( empty( $applicable ) ) {
			return "# BEGIN Simple Performance for WordPress\n"
				. "# Block direct PHP execution in this directory (Apache / OLS-with-override).\n"
				. "RewriteEngine On\n"
				. "RewriteRule $ext_pattern_rewrite - [F,L]\n"
				. $deny_files
				. "# END Simple Performance for WordPress\n";
		}

		$uri_base = self::get_uri_base();
		$lines    = array();
		$lines[]  = '# BEGIN Simple Performance for WordPress';
		$lines[]  = '# Block direct PHP execution in this directory (Apache / OLS-with-override).';
		$lines[]  = '# Whitelisted PHP files are allowed through; everything else is denied.';
		$lines[]  = 'RewriteEngine On';

		foreach ( $applicable as $i => $path ) {
			$escaped     = preg_quote( $path, '/' );
			$has_more    = ( $i < count( $applicable ) - 1 );
			$flag_suffix = $has_more ? ' [OR]' : '';
			$lines[]     = 'RewriteCond %{REQUEST_URI} ^' . $uri_base . 'wp-content/' . $escaped . '$' . $flag_suffix;
		}

		// Allow whitelisted files through, then deny every PHP-family extension.
		// This ordering matters on OpenLiteSpeed, where <FilesMatch> is ignored.
		$lines[] = 'RewriteRule ' . $ext_pattern_rewrite . ' - [L]';
		$lines[] = '# Deny all other PHP execution';
		$lines[] = 'RewriteRule ' . $ext_pattern_rewrite . ' - [F,L]';
		$lines[] = '<FilesMatch "' . $ext_pattern_filesmatch . '">';
		$lines[] = "\tRequire all denied";
		$lines[] = '</FilesMatch>';
		$lines[] = '<IfModule !mod_authz_core.c>';
		$lines[] = "\t" . '<FilesMatch "' . $ext_pattern_filesmatch . '">';
		$lines[] = "\t\tOrder allow,deny";
		$lines[] = "\t\tDeny from all";
		$lines[] = "\t" . '</FilesMatch>';
		$lines[] = '</IfModule>';

		// Re-grant the whitelisted files at the authz layer. The RewriteRule
		// allow above is not enough on its own: mod_rewrite runs at URL-fixup
		// time and `[L]` only ends the rewrite pass, so a file it let through
		// is still refused by the <FilesMatch> deny when authorization runs.
		// That is why the whitelist appeared to work only on OpenLiteSpeed,
		// which ignores <FilesMatch> entirely.
		//
		// These sections MUST follow the deny block: Apache merges <Files> and
		// <FilesMatch> in the order they appear, so the last matching section
		// wins. <Files> matches a basename rather than a path, but the
		// RewriteCond chain above still answers the same basename at any other
		// path with [F,L], so the pair stays path-precise wherever mod_rewrite
		// is active. <If> would express the path directly but requires
		// `AllowOverride All` and 500s a vhost without it — the same reason
		// this payload omits `Options -Indexes`.
		$basenames = array_values( array_unique( array_map( 'basename', $applicable ) ) );

		$lines[] = '# Allow the whitelisted files (must follow the deny block to override it).';

		foreach ( $basenames as $basename ) {
			$lines[] = '<Files "' . $basename . '">';
			$lines[] = "\tRequire all granted";
			$lines[] = '</Files>';
		}

		$lines[] = '<IfModule !mod_authz_core.c>';

		foreach ( $basenames as $basename ) {
			$lines[] = "\t" . '<Files "' . $basename . '">';
			$lines[] = "\t\tOrder allow,deny";
			$lines[] = "\t\tAllow from all";
			$lines[] = "\t" . '</Files>';
		}

		$lines[] = '</IfModule>';
		$lines[] = '# END Simple Performance for WordPress';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The root .htaccess payload — composed from whichever rule-group toggles
	 * are enabled. Written via insert_with_markers() so WordPress' rewrite
	 * rules are preserved.
	 *
	 * Each group now includes a mod_rewrite fallback rule that OpenLiteSpeed
	 * honors in .htaccess, layered in front of the Apache authz directives so
	 * Apache installations continue to work unchanged.
	 *
	 * @return string
	 */
	public static function payload_root() {
		$h     = SPFW_Settings::group( 'hardening' );
		$lines = array();

		if ( ! empty( $h['protect_sensitive_files'] ) ) {
			$lines[] = '# group: sensitive_files';
			$lines[] = 'RewriteRule ' . self::root_rewrite_pattern( '(readme\.html|license\.txt|wp-config-sample\.php|.*\.(log|sql|bak|old|orig|env))$' ) . ' - [F,L]';
			$lines[] = '<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php|.*\.(log|sql|bak|old|orig|env))$">';
			$lines[] = "\tRequire all denied";
			$lines[] = '</FilesMatch>';
			$lines[] = '<IfModule !mod_authz_core.c>';
			$lines[] = "\t" . '<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php|.*\.(log|sql|bak|old|orig|env))$">';
			$lines[] = "\t\tOrder allow,deny";
			$lines[] = "\t\tDeny from all";
			$lines[] = "\t" . '</FilesMatch>';
			$lines[] = '</IfModule>';
		}

		if ( ! empty( $h['block_xmlrpc_file'] ) ) {
			if ( ! empty( $lines ) ) {
				$lines[] = '';
			}

			$lines[] = '# group: block_xmlrpc';
			$lines[] = 'RewriteRule ' . self::root_rewrite_pattern( 'xmlrpc\.php$' ) . ' - [F,L]';
			$lines[] = '<Files "xmlrpc.php">';
			$lines[] = "\tRequire all denied";
			$lines[] = '</Files>';
			$lines[] = '<IfModule !mod_authz_core.c>';
			$lines[] = "\t" . '<Files "xmlrpc.php">';
			$lines[] = "\t\tOrder allow,deny";
			$lines[] = "\t\tDeny from all";
			$lines[] = "\t" . '</Files>';
			$lines[] = '</IfModule>';
		}

		if ( ! empty( $lines ) ) {
			array_unshift( $lines, 'RewriteEngine On' );
		}

		return implode( "\n", $lines ) . ( ! empty( $lines ) ? "\n" : '' );
	}

	/**
	 * Get the payload for a target.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return string
	 */
	public static function payload( $target = 'plugins' ) {
		// For deny-PHP targets, use the whitelist-aware generator so the
		// payload includes RewriteRule allowances for any whitelisted PHP files
		// under this directory. The root target uses its own payload_root().
		if ( in_array( $target, array( 'plugins', 'uploads' ), true ) ) {
			return self::payload_deny_php_for_target( $target );
		}

		$targets = self::targets();
		$entry   = isset( $targets[ $target ] ) ? $targets[ $target ] : $targets['plugins'];
		$method  = $entry['payload_method'];

		return self::$method();
	}

	/**
	 * Known prior payload hashes for a target, used by the upgrade migration
	 * to detect files we authored under an older payload format and silently
	 * rewrite them.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return string[]
	 */
	public static function legacy_payload_hashes( $target = 'plugins' ) {
		if ( 'root' === $target ) {
			return array();
		}

		// The pre-1.14.0 payload used <Files *.php> instead of FilesMatch.
		$legacy = "# BEGIN Simple Performance for WordPress\n"
			. "# Block direct PHP execution in this directory (Apache / OLS-with-override).\n"
			. "<Files *.php>\n"
			. "\tRequire all denied\n"
			. "</Files>\n"
			. "# Fallback for older Apache:\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\t<Files *.php>\n"
			. "\t\tOrder allow,deny\n"
			. "\t\tDeny from all\n"
			. "\t</Files>\n"
			. "</IfModule>\n"
			. "# END Simple Performance for WordPress\n";

		return array( sha1( $legacy ) );
	}

	/**
	 * Initialize and return the WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	/**
	 * Write a target's .htaccess file and store its hash for integrity checks.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return bool
	 */
	public static function write( $target = 'plugins' ) {
		$config  = self::config( $target );
		$payload = self::payload( $target );

		if ( 'marker_block' === $config['mode'] ) {
			return self::write_marker_block( $config, $payload );
		}

		return self::write_own_file( $config, $payload );
	}

	/**
	 * Write a whole-file target (plugins/, uploads/).
	 *
	 * @param array  $config  Target config.
	 * @param string $payload File contents.
	 * @return bool
	 */
	private static function write_own_file( $config, $payload ) {
		// Skip a write that would not change a byte. On OpenLiteSpeed every
		// touch of an .htaccess costs a graceful restart before the rules take
		// effect again, so a needless rewrite is not free the way it is on
		// Apache: it desynchronizes the running server from disk for no reason.
		// Callers rewrite on any php_whitelist change, and that comparison is
		// order-sensitive, so merely reordering the list used to land here.
		// The stored hash is still refreshed, which also repairs an install
		// whose content is correct but whose recorded hash drifted.
		$payload_hash = sha1( $payload );

		if ( file_exists( $config['path'] ) && sha1_file( $config['path'] ) === $payload_hash ) {
			if ( SPFW_Settings::value( 'hardening', $config['hash'], '' ) !== $payload_hash ) {
				SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => $payload_hash ) ) );
			}

			return true;
		}

		$fs = self::filesystem();

		if ( ! $fs ) {
			return false;
		}

		$dir = dirname( $config['path'] );
		if ( ! $fs->is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$written = $fs->put_contents( $config['path'], $payload, 0644 );

		if ( $written ) {
			SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => sha1( $payload ) ) ) );
		}

		return (bool) $written;
	}

	/**
	 * Write a marker-block target (site root) via insert_with_markers().
	 *
	 * @param array  $config  Target config.
	 * @param string $payload Block contents (without BEGIN/END markers).
	 * @return bool
	 */
	private static function write_marker_block( $config, $payload ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$lines = '' !== $payload ? explode( "\n", rtrim( $payload, "\n" ) ) : array();

		// Same OpenLiteSpeed reasoning as write_own_file(): do not rewrite a
		// block that already matches, or the running server falls out of step
		// with disk until the next graceful restart for no gain.
		$current = self::extract_marker_block( $config['path'] );

		if ( '' !== $current && rtrim( $payload, "\n" ) === $current ) {
			if ( SPFW_Settings::value( 'hardening', $config['hash'], '' ) !== sha1( $current ) ) {
				SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => sha1( $current ) ) ) );
			}

			return true;
		}

		$written = insert_with_markers( $config['path'], self::MARKER, $lines );

		if ( $written ) {
			$block = self::extract_marker_block( $config['path'] );
			$hash  = '' !== $block ? sha1( $block ) : '';
			SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => $hash ) ) );
		}

		return (bool) $written;
	}

	/**
	 * Extract the content between our BEGIN/END markers from a file.
	 *
	 * @param string $path File path.
	 * @return string Extracted block (without markers), or empty string.
	 */
	private static function extract_marker_block( $path ) {
		if ( ! file_exists( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! is_string( $contents ) || '' === $contents ) {
			return '';
		}

		$begin = '# BEGIN ' . self::MARKER;
		$end   = '# END ' . self::MARKER;

		$begin_pos = strpos( $contents, $begin );

		if ( false === $begin_pos ) {
			return '';
		}

		$end_pos = strpos( $contents, $end, $begin_pos );

		if ( false === $end_pos ) {
			return '';
		}

		$block_start = $begin_pos + strlen( $begin );
		$block       = substr( $contents, $block_start, $end_pos - $block_start );

		return trim( $block, "\n" );
	}

	/**
	 * Delete a target's .htaccess file (own_file) or remove our marker block
	 * (marker_block), but only if we authored it.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return bool True if removed or already absent; false if a foreign
	 *              or altered file blocks removal.
	 */
	public static function remove( $target = 'plugins' ) {
		$config = self::config( $target );

		if ( 'marker_block' === $config['mode'] ) {
			return self::remove_marker_block( $config );
		}

		return self::remove_own_file( $config );
	}

	/**
	 * Remove a whole-file target.
	 *
	 * @param array $config Target config.
	 * @return bool
	 */
	private static function remove_own_file( $config ) {
		if ( ! file_exists( $config['path'] ) ) {
			return true;
		}

		$hash = SPFW_Settings::value( 'hardening', $config['hash'], '' );

		if ( '' === $hash || sha1_file( $config['path'] ) !== $hash ) {
			return false;
		}

		$fs = self::filesystem();

		if ( ! $fs ) {
			return false;
		}

		$deleted = $fs->delete( $config['path'] );

		if ( $deleted ) {
			SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => '' ) ) );
		}

		return (bool) $deleted;
	}

	/**
	 * Remove our marker block from a shared file (never deletes the file).
	 *
	 * @param array $config Target config.
	 * @return bool
	 */
	private static function remove_marker_block( $config ) {
		if ( ! file_exists( $config['path'] ) ) {
			return true;
		}

		$block = self::extract_marker_block( $config['path'] );

		if ( '' === $block ) {
			// No block present — nothing to remove.
			SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => '' ) ) );

			return true;
		}

		// Verify integrity before removing.
		$stored = SPFW_Settings::value( 'hardening', $config['hash'], '' );

		if ( '' !== $stored && sha1( $block ) !== $stored ) {
			return false;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// Writing an empty array removes the marker block.
		$removed = insert_with_markers( $config['path'], self::MARKER, array() );

		if ( $removed ) {
			SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => '' ) ) );
		}

		return (bool) $removed;
	}

	/**
	 * Current hardening status for a target.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return string One of ok|missing|altered|disabled.
	 */
	public static function status( $target = 'plugins' ) {
		$config    = self::config( $target );
		$hardening = SPFW_Settings::group( 'hardening' );

		// For root, check if either toggle is on.
		if ( 'root' === $target ) {
			if ( empty( $hardening['protect_sensitive_files'] ) && empty( $hardening['block_xmlrpc_file'] ) ) {
				return 'disabled';
			}
		} elseif ( ! empty( $config['toggle'] ) && empty( $hardening[ $config['toggle'] ] ) ) {
			return 'disabled';
		}

		if ( 'marker_block' === $config['mode'] ) {
			return self::status_marker_block( $config, $hardening );
		}

		if ( ! file_exists( $config['path'] ) ) {
			return 'missing';
		}

		$stored = isset( $hardening[ $config['hash'] ] ) ? $hardening[ $config['hash'] ] : '';

		if ( sha1_file( $config['path'] ) !== $stored ) {
			return 'altered';
		}

		return 'ok';
	}

	/**
	 * Status check for a marker-block target.
	 *
	 * @param array $config    Target config.
	 * @param array $hardening Hardening settings group.
	 * @return string
	 */
	private static function status_marker_block( $config, $hardening ) {
		$block = self::extract_marker_block( $config['path'] );

		if ( '' === $block ) {
			return 'missing';
		}

		$stored = isset( $hardening[ $config['hash'] ] ) ? $hardening[ $config['hash'] ] : '';

		if ( '' === $stored || sha1( $block ) !== $stored ) {
			return 'altered';
		}

		return 'ok';
	}

	/**
	 * Run the upgrade migration for pre-1.14.0 installs. For each enabled
	 * own_file target, if the on-disk sha1 matches a known legacy hash,
	 * silently rewrite with the new payload and store the new hash. On no
	 * match, leave it alone (genuine foreign edit).
	 */
	public static function run_payload_migration() {
		foreach ( array( 'plugins', 'uploads' ) as $target ) {
			$config    = self::config( $target );
			$hardening = SPFW_Settings::group( 'hardening' );

			if ( empty( $config['toggle'] ) || empty( $hardening[ $config['toggle'] ] ) ) {
				continue;
			}

			if ( ! file_exists( $config['path'] ) ) {
				continue;
			}

			$disk_hash = sha1_file( $config['path'] );
			$stored    = isset( $hardening[ $config['hash'] ] ) ? $hardening[ $config['hash'] ] : '';

			// Already on the new payload.
			if ( $disk_hash === $stored && $disk_hash === sha1( self::payload( $target ) ) ) {
				continue;
			}

			// Check against legacy hashes.
			if ( ! in_array( $disk_hash, self::legacy_payload_hashes( $target ), true ) ) {
				continue;
			}

			// Legacy file we authored — rewrite with new payload.
			self::write( $target );
		}
	}

	/**
	 * Whether a target we authored has drifted from the payload its current
	 * toggles require.
	 *
	 * "Authored" means the on-disk content still matches the stored integrity
	 * hash (status() === 'ok'), so we only ever resync our own files — a foreign
	 * edit stays 'altered' and is left for Restore. "Drifted" means that
	 * authored content no longer equals what payload() generates for the
	 * current settings. This is the gap status() cannot see: it compares disk
	 * to the *stored hash*, so a root marker block that lost its block_xmlrpc
	 * group still reads 'ok' even though the current toggles require that group.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return bool
	 */
	public static function needs_resync( $target = 'plugins' ) {
		if ( 'ok' !== self::status( $target ) ) {
			return false;
		}

		$config   = self::config( $target );
		$expected = self::payload( $target );

		if ( 'marker_block' === $config['mode'] ) {
			// The stored hash is the sha1 of the extracted block (markers and
			// surrounding newlines stripped), so compare against the payload
			// normalized the same way write_marker_block() stores it.
			$block = self::extract_marker_block( $config['path'] );

			return rtrim( $expected, "\n" ) !== $block;
		}

		if ( ! file_exists( $config['path'] ) ) {
			return false;
		}

		return sha1_file( $config['path'] ) !== sha1( $expected );
	}

	/**
	 * Self-heal authored drift: for every target, rewrite the file when
	 * needs_resync() reports the content we authored no longer matches what the
	 * current toggles require. Hash-gated — only ever rewrites content whose
	 * on-disk hash still matches the stored hash, so a foreign edit is never
	 * clobbered (it remains 'altered' for the admin to Restore). Includes the
	 * root marker block, which run_payload_migration() does not cover.
	 *
	 * @return string[] Targets that were rewritten.
	 */
	public static function reconcile() {
		$rewritten = array();

		foreach ( array( 'plugins', 'uploads', 'root' ) as $target ) {
			if ( ! self::needs_resync( $target ) ) {
				continue;
			}

			if ( self::write( $target ) ) {
				$rewritten[] = $target;

				// Re-arm the root safety self-check: reconcile can re-add a
				// rule group (e.g. block_xmlrpc) whose presence the server may
				// reject with a 500, exactly like a fresh enable. The self-check
				// rolls the block back automatically if that happens.
				if ( 'root' === $target ) {
					update_option( 'spfw_root_htaccess_check', true );
				}
			}
		}

		return $rewritten;
	}
}
