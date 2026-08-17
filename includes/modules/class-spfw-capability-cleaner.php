<?php
/**
 * Module: Ghost Capability Cleaner.
 *
 * Detects and removes orphaned capabilities from WordPress roles that
 * were left behind by uninstalled plugins. Scans all roles, groups
 * non-core capabilities by prefix, and flags groups not owned by any
 * installed plugin.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * On-demand capability cleaner — no runtime hooks. All operations are
 * triggered through the REST API by an administrator.
 */
class SPFW_Capability_Cleaner {

	/**
	 * Scans all roles for orphaned (ghost) capabilities.
	 *
	 * Groups non-core capabilities by prefix and excludes those owned
	 * by installed plugins. Returns groups with the roles they appear in.
	 *
	 * @return array {
	 *     @type array $groups Array of group arrays with prefix, count, roles, samples.
	 * }
	 */
	public static function scan() {
		global $wp_roles;

		$core_caps = self::get_core_capabilities();
		$owned     = self::get_owned_prefixes();
		$groups    = array();

		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			if ( empty( $role_data['capabilities'] ) || ! is_array( $role_data['capabilities'] ) ) {
				continue;
			}

			foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
				// Skip WordPress core capabilities.
				if ( isset( $core_caps[ $cap ] ) ) {
					continue;
				}

				$key = self::extract_group_key( $cap );

				if ( '' === $key ) {
					$key = strtolower( $cap );
				}

				// Skip capabilities owned by installed plugins.
				if ( in_array( $key, $owned, true ) ) {
					continue;
				}

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'prefix'  => $key,
						'count'   => 0,
						'roles'   => array(),
						'samples' => array(),
					);
				}

				$groups[ $key ]['count']++;

				if ( ! in_array( $role_slug, $groups[ $key ]['roles'], true ) ) {
					$groups[ $key ]['roles'][] = $role_slug;
				}

				if ( count( $groups[ $key ]['samples'] ) < 5 && ! in_array( $cap, $groups[ $key ]['samples'], true ) ) {
					$groups[ $key ]['samples'][] = $cap;
				}
			}
		}

		$orphans = array_values( $groups );

		// Sort by count descending.
		usort(
			$orphans,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array( 'groups' => $orphans );
	}

	/**
	 * Removes all capabilities matching the given prefixes from every role.
	 *
	 * @param array $prefixes Array of capability prefixes to strip.
	 * @return int Total number of capability removals.
	 */
	public static function delete_by_prefix( array $prefixes ) {
		global $wp_roles;

		$removed = 0;

		$sanitized = array_filter( array_map( 'sanitize_key', $prefixes ) );

		if ( empty( $sanitized ) ) {
			return 0;
		}

		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			$role = get_role( $role_slug );

			if ( ! $role || empty( $role_data['capabilities'] ) ) {
				continue;
			}

			foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
				foreach ( $sanitized as $prefix ) {
					if ( 0 === strpos( $cap, $prefix ) ) {
						$role->remove_cap( $cap );
						$removed++;
						break;
					}
				}
			}
		}

		return $removed;
	}

	/**
	 * Extracts the group key from a capability name.
	 *
	 * Uses the first underscore-delimited segment when it is 4+ chars;
	 * otherwise joins the first two segments.
	 *
	 * @param string $cap_name The capability name.
	 * @return string Group key or empty string.
	 */
	private static function extract_group_key( $cap_name ) {
		$parts = explode( '_', $cap_name );

		if ( count( $parts ) < 2 ) {
			return '';
		}

		if ( strlen( $parts[0] ) >= 4 ) {
			return strtolower( $parts[0] );
		}

		if ( isset( $parts[1] ) ) {
			return strtolower( $parts[0] . '_' . $parts[1] );
		}

		return '';
	}

	/**
	 * Builds the list of capability prefixes owned by installed plugins.
	 *
	 * @return array Normalized prefix strings.
	 */
	private static function get_owned_prefixes() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$owned   = array();
		$plugins = get_plugins();

		foreach ( $plugins as $file => $data ) {
			$dir = dirname( $file );

			if ( '.' !== $dir ) {
				$owned[] = str_replace( '-', '_', strtolower( $dir ) );
			}

			if ( ! empty( $data['TextDomain'] ) ) {
				$owned[] = str_replace( '-', '_', strtolower( $data['TextDomain'] ) );
			}

			$base = strtolower( basename( $file, '.php' ) );

			if ( ! empty( $base ) ) {
				$owned[] = str_replace( '-', '_', $base );
			}
		}

		$owned[] = 'spfw';

		return array_unique( $owned );
	}

	/**
	 * Returns the full set of WordPress core capabilities as a lookup map.
	 *
	 * @return array Associative array of cap => true.
	 */
	private static function get_core_capabilities() {
		$caps = array(
			// Meta / general.
			'read',
			'exist',
			'activate_plugins',
			'create_users',
			'delete_plugins',
			'delete_themes',
			'delete_users',
			'edit_dashboard',
			'edit_files',
			'edit_plugins',
			'edit_theme_options',
			'edit_themes',
			'edit_users',
			'export',
			'import',
			'install_plugins',
			'install_themes',
			'list_users',
			'manage_categories',
			'manage_links',
			'manage_options',
			'moderate_comments',
			'promote_users',
			'remove_users',
			'switch_themes',
			'unfiltered_html',
			'unfiltered_upload',
			'update_core',
			'update_plugins',
			'update_themes',
			'upload_plugins',
			'upload_themes',
			'customize',
			'delete_site',
			// Posts.
			'edit_posts',
			'edit_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_private_posts',
			'delete_published_posts',
			// Pages.
			'edit_pages',
			'edit_others_pages',
			'edit_private_pages',
			'edit_published_pages',
			'publish_pages',
			'read_private_pages',
			'delete_pages',
			'delete_others_pages',
			'delete_private_pages',
			'delete_published_pages',
			// Media.
			'upload_files',
			// Network / multisite.
			'manage_network',
			'manage_sites',
			'manage_network_users',
			'manage_network_plugins',
			'manage_network_themes',
			'manage_network_options',
			'upgrade_network',
			'setup_network',
		);

		return array_fill_keys( $caps, true );
	}
}
