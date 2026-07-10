<?php
/**
 * Module 1: Core performance toggles (bloat removal).
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Disables/limits core WordPress bloat: emojis, embeds, dashicons,
 * XML-RPC, feeds, query strings, heartbeat, jQuery Migrate.
 */
class SPFW_Module_Core implements SPFW_Module {

	/**
	 * Attach hooks for every enabled core toggle.
	 */
	public function register() {
		$c = SPFW_Settings::group( 'core' );

		if ( ! empty( $c['disable_emojis'] ) ) {
			add_action( 'init', array( $this, 'disable_emojis' ) );
		}

		if ( ! empty( $c['disable_embeds'] ) ) {
			add_action( 'init', array( $this, 'disable_embeds' ) );
			add_action( 'wp_footer', array( $this, 'deregister_embed_script' ), 1 );
		}

		if ( ! empty( $c['disable_dashicons'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_deregister_dashicons' ), 100 );
		}

		if ( ! empty( $c['disable_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( $this, 'strip_pingback_methods' ) );
			add_filter( 'wp_headers', array( $this, 'strip_pingback_header' ) );
		}

		if ( ! empty( $c['remove_rsd'] ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( ! empty( $c['remove_wlwmanifest'] ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( ! empty( $c['disable_feeds'] ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );

			$feed_hooks = array(
				'do_feed',
				'do_feed_rdf',
				'do_feed_rss',
				'do_feed_rss2',
				'do_feed_atom',
				'do_feed_rss2_comments',
				'do_feed_atom_comments',
			);

			foreach ( $feed_hooks as $hook ) {
				add_action( $hook, array( $this, 'handle_disabled_feed' ), 1 );
			}
		}

		if ( ! empty( $c['remove_query_strings'] ) && ! is_admin() ) {
			add_filter( 'script_loader_src', array( $this, 'remove_ver_query_arg' ), 15, 1 );
			add_filter( 'style_loader_src', array( $this, 'remove_ver_query_arg' ), 15, 1 );
		}

		$heartbeat_mode = isset( $c['heartbeat_mode'] ) ? $c['heartbeat_mode'] : 'default';

		if ( 'modify' === $heartbeat_mode ) {
			add_filter( 'heartbeat_settings', array( $this, 'modify_heartbeat_interval' ) );
		} elseif ( 'disable' === $heartbeat_mode ) {
			add_action( 'init', array( $this, 'disable_heartbeat' ), 1 );
		}

		if ( ! empty( $c['disable_jquery_migrate'] ) && ! is_admin() ) {
			add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
		}
	}

	/**
	 * Strip emoji detection scripts, styles, and DNS-prefetch hints.
	 */
	public function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', array( $this, 'remove_emoji_tinymce_plugin' ) );
		add_filter( 'wp_resource_hints', array( $this, 'remove_emoji_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Remove the wpemoji TinyMCE plugin.
	 *
	 * @param array $plugins TinyMCE plugin list.
	 * @return array
	 */
	public function remove_emoji_tinymce_plugin( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	/**
	 * Drop the emoji CDN dns-prefetch resource hint.
	 *
	 * @param array  $urls          Resource hint URLs.
	 * @param string $relation_type Hint relation type.
	 * @return array
	 */
	public function remove_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}

		return array_filter(
			$urls,
			function ( $url ) {
				return false === strpos( (string) $url, 's.w.org' );
			}
		);
	}

	/**
	 * Remove oEmbed discovery/registration hooks.
	 */
	public function disable_embeds() {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );

		add_filter( 'embed_oembed_discover', '__return_false' );
	}

	/**
	 * Deregister the wp-embed frontend script.
	 */
	public function deregister_embed_script() {
		wp_deregister_script( 'wp-embed' );
	}

	/**
	 * Deregister the dashicons stylesheet for logged-out visitors.
	 */
	public function maybe_deregister_dashicons() {
		if ( ! is_user_logged_in() ) {
			wp_deregister_style( 'dashicons' );
		}
	}

	/**
	 * Remove pingback methods from the XML-RPC method list.
	 *
	 * @param array $methods Registered XML-RPC methods.
	 * @return array
	 */
	public function strip_pingback_methods( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

		return $methods;
	}

	/**
	 * Strip the X-Pingback response header.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function strip_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );

		return $headers;
	}

	/**
	 * Redirect or block a disabled feed request.
	 */
	public function handle_disabled_feed() {
		$c = SPFW_Settings::group( 'core' );

		if ( ! empty( $c['feed_redirect_home'] ) ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}

		wp_die(
			esc_html__( 'Feeds are disabled on this site.', 'simple-performance-for-wordpress' ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Strip the `ver` query arg from an enqueued asset URL.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function remove_ver_query_arg( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, 'ver=' ) ) {
			return remove_query_arg( 'ver', $src );
		}

		return $src;
	}

	/**
	 * Override the Heartbeat API polling interval.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public function modify_heartbeat_interval( $settings ) {
		$c                    = SPFW_Settings::group( 'core' );
		$settings['interval'] = isset( $c['heartbeat_interval'] ) ? (int) $c['heartbeat_interval'] : 60;

		return $settings;
	}

	/**
	 * Fully deregister the Heartbeat API script.
	 */
	public function disable_heartbeat() {
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Remove jquery-migrate from jQuery's frontend dependencies.
	 *
	 * @param WP_Scripts $scripts Scripts registry.
	 */
	public function remove_jquery_migrate( $scripts ) {
		if ( isset( $scripts->registered['jquery'] ) ) {
			$scripts->registered['jquery']->deps = array_diff(
				$scripts->registered['jquery']->deps,
				array( 'jquery-migrate' )
			);
		}
	}
}
