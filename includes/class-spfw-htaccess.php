<?php
/**
 * Plugins-directory .htaccess writer/verifier.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared utility for the deny-PHP .htaccess dropped into
 * /wp-content/plugins/. Only ever deletes a file it authored itself
 * (sha1 match against the stored hash) — never touches a foreign file.
 */
class SPFW_Htaccess {

	/**
	 * Path to the plugins-directory .htaccess file.
	 *
	 * @return string
	 */
	public static function path() {
		return WP_CONTENT_DIR . '/plugins/.htaccess';
	}

	/**
	 * The exact file contents this plugin writes.
	 *
	 * @return string
	 */
	public static function payload() {
		return "# BEGIN Simple Performance for WordPress\n"
			. "# Block direct PHP execution in the plugins directory (Apache / OLS-with-override).\n"
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
	 * Write the .htaccess file and store its hash for integrity checks.
	 *
	 * @return bool
	 */
	public static function write() {
		$fs = self::filesystem();

		if ( ! $fs ) {
			return false;
		}

		$written = $fs->put_contents( self::path(), self::payload(), 0644 );

		if ( $written ) {
			SPFW_Settings::update( array( 'hardening' => array( 'htaccess_hash' => sha1( self::payload() ) ) ) );
		}

		return (bool) $written;
	}

	/**
	 * Delete the .htaccess file, but only if it exists and matches the
	 * hash we last wrote (i.e. we authored it and it's unaltered).
	 *
	 * @return bool True if removed or already absent; false if a foreign
	 *              or altered file blocks removal.
	 */
	public static function remove() {
		if ( ! file_exists( self::path() ) ) {
			return true;
		}

		$hash = SPFW_Settings::value( 'hardening', 'htaccess_hash', '' );

		if ( '' === $hash || sha1_file( self::path() ) !== $hash ) {
			return false;
		}

		$fs = self::filesystem();

		if ( ! $fs ) {
			return false;
		}

		$deleted = $fs->delete( self::path() );

		if ( $deleted ) {
			SPFW_Settings::update( array( 'hardening' => array( 'htaccess_hash' => '' ) ) );
		}

		return (bool) $deleted;
	}

	/**
	 * Current hardening status.
	 *
	 * @return string One of ok|missing|altered|disabled.
	 */
	public static function status() {
		$hardening = SPFW_Settings::group( 'hardening' );

		if ( empty( $hardening['plugins_htaccess'] ) ) {
			return 'disabled';
		}

		if ( ! file_exists( self::path() ) ) {
			return 'missing';
		}

		if ( sha1_file( self::path() ) !== $hardening['htaccess_hash'] ) {
			return 'altered';
		}

		return 'ok';
	}
}
