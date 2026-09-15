<?php
/**
 * Uninstall cleanup for Simple Performance for WordPress.
 *
 * Runs standalone (WordPress loads this file directly, not the plugin's own
 * bootstrap), so it never assumes plugin classes are loaded — everything
 * needed is inlined here.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Initialize and return the WP_Filesystem instance.
 *
 * @return WP_Filesystem_Base|null
 */
function spfw_uninstall_filesystem() {
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
 * Delete a file this plugin authored, identified by the sha1 it recorded.
 *
 * Hash-gated exactly as the plugin's own removal paths are: a file whose
 * content no longer matches the recorded hash was edited by someone else and
 * is left alone.
 *
 * @param array  $settings Stored settings array (may be anything).
 * @param string $hash_key Key under the hardening group holding the sha1.
 * @param string $path     Absolute path of the file.
 */
function spfw_uninstall_delete_authored( $settings, $hash_key, $path ) {
	$hash = ( is_array( $settings ) && isset( $settings['hardening'][ $hash_key ] ) )
		? $settings['hardening'][ $hash_key ]
		: '';

	if ( '' === $hash || ! file_exists( $path ) || sha1_file( $path ) !== $hash ) {
		return;
	}

	$fs = spfw_uninstall_filesystem();

	if ( $fs ) {
		$fs->delete( $path );
	}
}

/**
 * Remove everything this plugin created for the current site: the
 * authored plugins-directory .htaccess (only if unaltered), the uploads
 * `.user.ini` guard, the localized fonts directory, and the settings option.
 */
function spfw_uninstall_cleanup_site() {
	$settings    = get_option( 'spfw_settings' );
	$stored_hash = ( is_array( $settings ) && isset( $settings['hardening']['htaccess_hash'] ) )
		? $settings['hardening']['htaccess_hash']
		: '';

	$htaccess_path = WP_CONTENT_DIR . '/plugins/.htaccess';

	if ( '' !== $stored_hash && file_exists( $htaccess_path ) && sha1_file( $htaccess_path ) === $stored_hash ) {
		$fs = spfw_uninstall_filesystem();

		if ( $fs ) {
			$fs->delete( $htaccess_path );
		}
	}

	$upload_dir = wp_upload_dir();

	$uploads_hash          = ( is_array( $settings ) && isset( $settings['hardening']['uploads_htaccess_hash'] ) )
		? $settings['hardening']['uploads_htaccess_hash']
		: '';
	$uploads_htaccess_path = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';

	if ( '' !== $uploads_hash && file_exists( $uploads_htaccess_path ) && sha1_file( $uploads_htaccess_path ) === $uploads_hash ) {
		$fs = spfw_uninstall_filesystem();

		if ( $fs ) {
			$fs->delete( $uploads_htaccess_path );
		}
	}

	// The `.user.ini` guard, in the order that never leaves PHP pointed at a
	// file that is gone: the ini naming the guard first, then the guard. A
	// dangling auto_prepend_file left behind by an uninstall would break every
	// PHP request under uploads on a site that no longer has this plugin to
	// explain why — the worst possible time to find out.
	$user_ini_name = ini_get( 'user_ini.filename' );
	$user_ini_name = is_string( $user_ini_name ) && '' !== trim( $user_ini_name ) ? trim( $user_ini_name ) : '.user.ini';

	spfw_uninstall_delete_authored(
		$settings,
		'user_ini_hash',
		trailingslashit( $upload_dir['basedir'] ) . $user_ini_name
	);
	spfw_uninstall_delete_authored(
		$settings,
		'user_ini_guard_hash',
		trailingslashit( $upload_dir['basedir'] ) . '.spfw-uploads-guard.php'
	);
	spfw_uninstall_delete_authored(
		$settings,
		'user_ini_canary_hash',
		trailingslashit( $upload_dir['basedir'] ) . 'spfw-user-ini-canary.php'
	);

	$fonts_dir = untrailingslashit( $upload_dir['basedir'] ) . '/ods-fonts';

	if ( is_dir( $fonts_dir ) ) {
		$fs = spfw_uninstall_filesystem();

		if ( $fs ) {
			$fs->delete( $fonts_dir, true );
		}
	}

	delete_option( 'spfw_settings' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		spfw_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	spfw_uninstall_cleanup_site();
}
