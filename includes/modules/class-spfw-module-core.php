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

		if ( ! empty( $c['hide_wp_version'] ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );

			if ( ! is_admin() ) {
				add_filter( 'script_loader_src', array( $this, 'strip_wp_version_arg' ), 9999 );
				add_filter( 'style_loader_src', array( $this, 'strip_wp_version_arg' ), 9999 );
			}
		}

		if ( ! empty( $c['remove_shortlink'] ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( ! empty( $c['remove_rest_api_links'] ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}

		if ( ! empty( $c['remove_feed_links'] ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( ! empty( $c['disable_self_pingbacks'] ) ) {
			add_action( 'pre_ping', array( $this, 'disable_self_pingbacks' ) );
		}

		if ( ! empty( $c['disable_google_maps'] ) && ! is_admin() ) {
			add_action( 'template_redirect', array( $this, 'start_google_maps_buffer' ) );
		}

		if ( ! empty( $c['disable_password_meter'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_password_meter' ), 100 );
		}

		if ( ! empty( $c['disable_comments'] ) ) {
			$this->register_disable_comments();
		}

		if ( ! empty( $c['remove_comment_urls'] ) ) {
			add_filter( 'comment_form_default_fields', array( $this, 'remove_comment_url_field' ) );
		}

		if ( ! empty( $c['blank_favicon'] ) ) {
			add_action( 'wp_head', array( $this, 'print_blank_favicon' ), 5 );
			add_action( 'login_head', array( $this, 'print_blank_favicon' ), 5 );
			add_action( 'admin_head', array( $this, 'print_blank_favicon' ), 5 );
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

		$heartbeat_control = isset( $c['heartbeat_control'] ) ? $c['heartbeat_control'] : 'default';

		if ( 'disable' === $heartbeat_control ) {
			add_action( 'init', array( $this, 'disable_heartbeat' ), 1 );
		} elseif ( 'allow_posts' === $heartbeat_control ) {
			add_action( 'init', array( $this, 'limit_heartbeat_to_posts' ), 1 );
		}

		$frequency = isset( $c['heartbeat_frequency'] ) ? (int) $c['heartbeat_frequency'] : 0;

		if ( $frequency > 0 && 'disable' !== $heartbeat_control ) {
			add_filter( 'heartbeat_settings', array( $this, 'modify_heartbeat_interval' ) );
		}

		$revisions = isset( $c['post_revisions'] ) ? $c['post_revisions'] : 'default';

		if ( 'default' !== $revisions ) {
			add_filter( 'wp_revisions_to_keep', array( $this, 'filter_revisions_to_keep' ), 10, 2 );
		}

		$autosave = isset( $c['autosave_interval'] ) ? (int) $c['autosave_interval'] : 0;

		// AUTOSAVE_INTERVAL is read by wp_functionality_constants(), which runs
		// after `plugins_loaded` (when this module registers), so defining it
		// here still wins over WordPress' default of 60 seconds.
		if ( $autosave > 0 && ! defined( 'AUTOSAVE_INTERVAL' ) ) {
			define( 'AUTOSAVE_INTERVAL', $autosave * MINUTE_IN_SECONDS );
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
	 * Override the Heartbeat API polling interval with the configured
	 * frequency (15/30/60/120 seconds).
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public function modify_heartbeat_interval( $settings ) {
		$c    = SPFW_Settings::group( 'core' );
		$freq = isset( $c['heartbeat_frequency'] ) ? (int) $c['heartbeat_frequency'] : 0;

		if ( $freq > 0 ) {
			$settings['interval'] = $freq;
		}

		return $settings;
	}

	/**
	 * Fully deregister the Heartbeat API script.
	 */
	public function disable_heartbeat() {
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Deregister Heartbeat everywhere except the post editor screens.
	 */
	public function limit_heartbeat_to_posts() {
		global $pagenow;

		if ( 'post.php' !== $pagenow && 'post-new.php' !== $pagenow ) {
			wp_deregister_script( 'heartbeat' );
		}
	}

	/**
	 * Filter the number of post revisions WordPress keeps.
	 *
	 * @param int          $num  Default number of revisions to keep.
	 * @param WP_Post|null $post Post being saved (unused, kept for signature).
	 * @return int
	 */
	public function filter_revisions_to_keep( $num, $post = null ) {
		unset( $post );

		$c   = SPFW_Settings::group( 'core' );
		$val = isset( $c['post_revisions'] ) ? $c['post_revisions'] : 'default';

		if ( 'disable' === $val ) {
			return 0;
		}

		if ( is_numeric( $val ) ) {
			return (int) $val;
		}

		return $num;
	}

	/**
	 * Strip the `ver` query arg from asset URLs when it equals the current
	 * WordPress version (hides the WP version without touching plugin/theme
	 * asset versions).
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function strip_wp_version_arg( $src ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		$version = get_bloginfo( 'version' );

		if ( $version && false !== strpos( $src, 'ver=' . $version ) ) {
			return remove_query_arg( 'ver', $src );
		}

		return $src;
	}

	/**
	 * Remove links pointing at this site from a pingback link list so the
	 * site never pings itself.
	 *
	 * @param string[] $links Links found in the post being published.
	 */
	public function disable_self_pingbacks( &$links ) {
		if ( ! is_array( $links ) ) {
			return;
		}

		$home = home_url();

		foreach ( $links as $index => $link ) {
			if ( 0 === strpos( (string) $link, $home ) ) {
				unset( $links[ $index ] );
			}
		}
	}

	/**
	 * Dequeue the password-strength-meter scripts on the front end (e.g.
	 * WooCommerce account/registration and comment-registration forms).
	 */
	public function dequeue_password_meter() {
		wp_dequeue_script( 'wc-password-strength-meter' );
		wp_dequeue_script( 'password-strength-meter' );
		wp_dequeue_script( 'zxcvbn-async' );
	}

	/**
	 * Remove the URL/website field from the comment form.
	 *
	 * @param array $fields Default comment form fields.
	 * @return array
	 */
	public function remove_comment_url_field( $fields ) {
		unset( $fields['url'] );

		return $fields;
	}

	/**
	 * Output an empty favicon link so browsers stop requesting /favicon.ico,
	 * unless a real Site Icon is configured.
	 */
	public function print_blank_favicon() {
		if ( function_exists( 'has_site_icon' ) && has_site_icon() ) {
			return;
		}

		echo '<link rel="icon" href="data:,">' . "\n";
	}

	/**
	 * Begin buffering the front-end response so Google Maps embeds can be
	 * stripped from the final HTML.
	 */
	public function start_google_maps_buffer() {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		// Check if the current page/post is excepted from Google Maps blocking.
		$c          = SPFW_Settings::group( 'core' );
		$exceptions = isset( $c['google_maps_exceptions'] ) && is_array( $c['google_maps_exceptions'] ) ? $c['google_maps_exceptions'] : array();

		if ( ! empty( $exceptions ) ) {
			$current_id   = (string) get_queried_object_id();
			$queried_obj  = get_queried_object();
			$current_slug = '';

			if ( $queried_obj instanceof WP_Post ) {
				$current_slug = $queried_obj->post_name;
			} elseif ( $queried_obj instanceof WP_Term ) {
				$current_slug = $queried_obj->slug;
			} elseif ( $queried_obj instanceof WP_User ) {
				$current_slug = $queried_obj->user_nicename;
			}

			$normalized_exceptions = array_map( 'strtolower', $exceptions );

			if ( in_array( strtolower( $current_id ), $normalized_exceptions, true ) ) {
				return;
			}
			if ( '' !== $current_slug && in_array( strtolower( $current_slug ), $normalized_exceptions, true ) ) {
				return;
			}
		}

		ob_start( array( $this, 'filter_google_maps' ) );
	}

	/**
	 * Strip Google Maps script and iframe embeds from buffered HTML.
	 *
	 * @param string $html Buffered page HTML.
	 * @return string
	 */
	public function filter_google_maps( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		// External Maps JS API / static scripts.
		$html = preg_replace( '#<script[^>]*src=["\'][^"\']*maps\.google(apis)?\.com[^"\']*["\'][^>]*>\s*</script>#i', '', $html );

		// Embedded map iframes.
		$html = preg_replace( '#<iframe[^>]*(maps\.google(apis)?\.com|google\.com/maps)[^>]*>.*?</iframe>#is', '', $html );

		return null === $html ? '' : $html;
	}

	/**
	 * Wire up the hooks that fully disable comments across front end and
	 * admin.
	 */
	private function register_disable_comments() {
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'comments_array', '__return_empty_array', 20 );

		add_action( 'init', array( $this, 'remove_comment_post_type_support' ), 100 );
		add_action( 'admin_menu', array( $this, 'remove_comments_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_comments_admin_page' ) );
		add_action( 'wp_before_admin_bar_render', array( $this, 'remove_comments_admin_bar' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_comments_dashboard_widget' ) );
	}

	/**
	 * Remove comment/trackback support from every post type.
	 */
	public function remove_comment_post_type_support() {
		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	/**
	 * Remove the Comments menu item from wp-admin.
	 */
	public function remove_comments_admin_menu() {
		remove_menu_page( 'edit-comments.php' );
	}

	/**
	 * Redirect anyone who lands on the comments admin screen back to the
	 * dashboard.
	 */
	public function redirect_comments_admin_page() {
		global $pagenow;

		if ( 'edit-comments.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	/**
	 * Remove the Comments node from the admin bar.
	 */
	public function remove_comments_admin_bar() {
		global $wp_admin_bar;

		if ( is_object( $wp_admin_bar ) ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	/**
	 * Remove the "Recent Comments" dashboard widget.
	 */
	public function remove_comments_dashboard_widget() {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
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
