<?php
/**
 * Directory .htaccess writer/verifier for the deny-PHP hardening files.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared utility for the deny-PHP .htaccess files dropped into
 * /wp-content/plugins/ and the uploads directory. Only ever deletes a file
 * it authored itself (sha1 match against the stored hash) — never touches a
 * foreign file.
 */
class SPFW_Htaccess {

	/**
	 * Per-target configuration: the file path, the toggle setting that
	 * controls it, and the settings key its integrity hash is stored under.
	 *
	 * @param string $target One of 'plugins'|'uploads'.
	 * @return array{path:string,toggle:string,hash:string}
	 */
	private static function config( $target ) {
		if ( 'uploads' === $target ) {
			$uploads = wp_upload_dir();

			return array(
				'path'   => trailingslashit( $uploads['basedir'] ) . '.htaccess',
				'toggle' => 'uploads_htaccess',
				'hash'   => 'uploads_htaccess_hash',
			);
		}

		return array(
			'path'   => WP_CONTENT_DIR . '/plugins/.htaccess',
			'toggle' => 'plugins_htaccess',
			'hash'   => 'htaccess_hash',
		);
	}

	/**
	 * Path to a target's .htaccess file.
	 *
	 * @param string $target One of 'plugins'|'uploads'.
	 * @return string
	 */
	public static function path( $target = 'plugins' ) {
		$config = self::config( $target );

		return $config['path'];
	}

	/**
	 * The exact file contents this plugin writes.
	 *
	 * Deliberately limited to `<Files *.php>` deny rules only — this needs
	 * `AllowOverride Limit`/`AuthConfig` (or OLS "Allow Override"). We do not
	 * emit `Options -Indexes`, which would additionally require
	 * `AllowOverride Options` and can 500 an Apache vhost that lacks it.
	 *
	 * @return string
	 */
	public static function payload() {
		return "# BEGIN Simple Performance for WordPress\n"
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
	 * @param string $target One of 'plugins'|'uploads'.
	 * @return bool
	 */
	public static function write( $target = 'plugins' ) {
		$config = self::config( $target );
		$fs     = self::filesystem();

		if ( ! $fs ) {
			return false;
		}

		// The uploads basedir may not exist yet on a brand-new site.
		$dir = dirname( $config['path'] );
		if ( ! $fs->is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$written = $fs->put_contents( $config['path'], self::payload(), 0644 );

		if ( $written ) {
			SPFW_Settings::update( array( 'hardening' => array( $config['hash'] => sha1( self::payload() ) ) ) );
		}

		return (bool) $written;
	}

	/**
	 * Delete a target's .htaccess file, but only if it exists and matches the
	 * hash we last wrote (i.e. we authored it and it's unaltered).
	 *
	 * @param string $target One of 'plugins'|'uploads'.
	 * @return bool True if removed or already absent; false if a foreign
	 *              or altered file blocks removal.
	 */
	public static function remove( $target = 'plugins' ) {
		$config = self::config( $target );

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
	 * Current hardening status for a target.
	 *
	 * @param string $target One of 'plugins'|'uploads'.
	 * @return string One of ok|missing|altered|disabled.
	 */
	public static function status( $target = 'plugins' ) {
		$config    = self::config( $target );
		$hardening = SPFW_Settings::group( 'hardening' );

		if ( empty( $hardening[ $config['toggle'] ] ) ) {
			return 'disabled';
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
}
