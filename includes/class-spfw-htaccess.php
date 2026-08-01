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
	 * @return string
	 */
	public static function payload_deny_php() {
		return "# BEGIN Simple Performance for WordPress\n"
			. "# Block direct PHP execution in this directory (Apache / OLS-with-override).\n"
			. "<FilesMatch \"\\.(?i:php[0-9]*|phtml|phps|phar|inc)$\">\n"
			. "\tRequire all denied\n"
			. "</FilesMatch>\n"
			. "# Fallback for older Apache:\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "\t<FilesMatch \"\\.(?i:php[0-9]*|phtml|phps|phar|inc)$\">\n"
			. "\t\tOrder allow,deny\n"
			. "\t\tDeny from all\n"
			. "\t</FilesMatch>\n"
			. "</IfModule>\n"
			. "# END Simple Performance for WordPress\n";
	}

	/**
	 * The root .htaccess payload — composed from whichever rule-group toggles
	 * are enabled. Written via insert_with_markers() so WordPress' rewrite
	 * rules are preserved.
	 *
	 * @return string
	 */
	public static function payload_root() {
		$h     = SPFW_Settings::group( 'hardening' );
		$lines = array();

		if ( ! empty( $h['protect_sensitive_files'] ) ) {
			$lines[] = '# group: sensitive_files';
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

		return implode( "\n", $lines ) . ( ! empty( $lines ) ? "\n" : '' );
	}

	/**
	 * Get the payload for a target.
	 *
	 * @param string $target One of 'plugins'|'uploads'|'root'.
	 * @return string
	 */
	public static function payload( $target = 'plugins' ) {
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

		$lines   = '' !== $payload ? explode( "\n", rtrim( $payload, "\n" ) ) : array();
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
}
