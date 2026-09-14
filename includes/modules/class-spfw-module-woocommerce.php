<?php
/**
 * Module 5: WooCommerce performance toggles.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Optimizes WooCommerce: cart fragments, conditional script/style loading,
 * dashboard/widget bloat, and the Marketing hub. Every hook is gated behind
 * its own setting and the whole module no-ops when WooCommerce is inactive.
 */
class SPFW_Module_WooCommerce implements SPFW_Module {

	/**
	 * WooCommerce shortcode tags that mean a page renders store content even
	 * though it is not a WooCommerce *route*.
	 *
	 * `is_woocommerce()` is true only for the shop archive, product taxonomies,
	 * and single products. A landing page, the front page, or a post that
	 * embeds a product grid is none of those, yet it renders Add to Cart
	 * buttons that need WooCommerce's front-end assets. Sniffing the queried
	 * post's content for these catches the core block and shortcode cases.
	 *
	 * @var string[]
	 */
	const WOO_SHORTCODES = array(
		'add_to_cart',
		'add_to_cart_url',
		'best_selling_products',
		'featured_products',
		'product',
		'product_categories',
		'product_category',
		'product_page',
		'products',
		'recent_products',
		'related_products',
		'sale_products',
		'shop_messages',
		'top_rated_products',
		'woocommerce_cart',
		'woocommerce_checkout',
		'woocommerce_my_account',
		'woocommerce_order_tracking',
	);

	/**
	 * Styles dropped on a page with no store content.
	 *
	 * @var string[]
	 */
	const DEQUEUE_STYLES = array(
		'woocommerce-general',
		'woocommerce-layout',
		'woocommerce-smallscreen',
		'woocommerce-inline',
		'wc-blocks-style',
		'wc-blocks-vendors-style',
	);

	/**
	 * Scripts dropped on a page with no store content. This is where the
	 * toggle's savings actually come from: the stylesheets above plus
	 * `woocommerce.min.js` and the site-wide cart-fragments request.
	 *
	 * @var string[]
	 */
	const DEQUEUE_SCRIPTS = array(
		'woocommerce',
		'wc-cart-fragments',
	);

	/**
	 * The Add to Cart handler and its dependency chain — never dequeued by the
	 * "non-store pages" toggle, on any page.
	 *
	 * These were previously in the dequeue list. Page builders (Avada/Fusion,
	 * Divi, Elementor) render product grids through their own shortcodes, which
	 * no route conditional and no content sniff can reliably detect, so any
	 * heuristic that decides to drop `wc-add-to-cart` will eventually drop it on
	 * a page that has Add to Cart buttons — and the button then binds nothing
	 * and fails silently, with no console error to trace back.
	 *
	 * The old list was also internally inconsistent: it dropped `wc-add-to-cart`
	 * while leaving `wc-add-to-cart-variation` enqueued, so a variable product
	 * ran a script whose dependency and whose `wc_add_to_cart_params` data
	 * object had both been removed.
	 *
	 * The handler is a few KB. Correctness wins.
	 *
	 * @var string[]
	 */
	const KEEP_SCRIPTS = array(
		'wc-add-to-cart',
		'wc-add-to-cart-variation',
		'jquery-blockui',
		'js-cookie',
	);

	/**
	 * Attach hooks for every enabled WooCommerce toggle.
	 */
	public function register() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$w = SPFW_Settings::group( 'woocommerce' );

		if ( ! empty( $w['disable_cart_fragments'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_cart_fragments' ), 99 );
		}

		if ( ! empty( $w['disable_scripts_styles'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_scripts_styles' ), 99 );
		}

		if ( ! empty( $w['disable_status_widget'] ) ) {
			add_action( 'wp_dashboard_setup', array( $this, 'remove_status_widget' ), 100 );
		}

		if ( ! empty( $w['disable_widgets'] ) ) {
			add_action( 'widgets_init', array( $this, 'unregister_widgets' ), 20 );
		}

		if ( ! empty( $w['disable_password_meter'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'disable_password_meter' ), 100 );
		}

		if ( ! empty( $w['disable_marketing_hub'] ) ) {
			add_filter( 'woocommerce_admin_features', array( $this, 'remove_marketing_feature' ) );
			add_action( 'admin_menu', array( $this, 'remove_marketing_menu' ), 99 );
		}
	}

	/**
	 * Whether the current request is a page where WooCommerce's front-end
	 * assets are actually needed.
	 *
	 * Three layers, cheapest first: the route conditionals, then the queried
	 * post's content (a product grid embedded in an otherwise ordinary page),
	 * then a filter for layouts neither can see.
	 *
	 * @return bool
	 */
	private function is_woo_page() {
		$is_woo = function_exists( 'is_woocommerce' )
			&& ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );

		if ( ! $is_woo ) {
			$is_woo = self::content_has_woo_markup( self::queried_content() );
		}

		/**
		 * Filter whether the current request counts as a store page.
		 *
		 * The escape hatch for page-builder layouts that render products
		 * through their own shortcodes: return true and WooCommerce's assets
		 * are left alone on that page.
		 *
		 * @param bool $is_woo Whether WooCommerce's front-end assets are needed.
		 */
		return (bool) apply_filters( 'spfw_is_woo_page', $is_woo );
	}

	/**
	 * Post content of the queried object, or '' when the request has none
	 * (an archive, a 404, a term page).
	 *
	 * @return string
	 */
	private static function queried_content() {
		if ( ! function_exists( 'get_queried_object' ) ) {
			return '';
		}

		$object = get_queried_object();

		return ( $object instanceof WP_Post ) ? (string) $object->post_content : '';
	}

	/**
	 * Whether a block of post content renders WooCommerce store markup.
	 *
	 * Pure string inspection, so the detection rules can be unit-tested
	 * without standing up WordPress or WooCommerce.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function content_has_woo_markup( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		if ( false !== strpos( $content, 'wp:woocommerce/' ) ) {
			return true;
		}

		foreach ( self::WOO_SHORTCODES as $tag ) {
			// The trailing character class keeps `[products]` and
			// `[product id=1]` matching while `[productivity]` does not.
			if ( preg_match( '/\[' . preg_quote( $tag, '/' ) . '[\s\]\/]/', $content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Dequeue the AJAX cart-fragments script everywhere except the cart and
	 * checkout, killing the site-wide `?wc-ajax=get_refreshed_fragments`
	 * request that otherwise defeats full-page caching.
	 *
	 * Note the trade-off this makes deliberately: Add to Cart still works, but
	 * a themed cart counter in the header will not update without a page
	 * reload, because that count is exactly what the fragments request
	 * refreshes. The setting description says so.
	 */
	public function disable_cart_fragments() {
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return;
		}

		wp_dequeue_script( 'wc-cart-fragments' );
	}

	/**
	 * Dequeue WooCommerce styles and scripts on pages with no store content.
	 *
	 * The Add to Cart handler chain (self::KEEP_SCRIPTS) is never touched here
	 * — see the constant for why.
	 */
	public function disable_scripts_styles() {
		if ( $this->is_woo_page() ) {
			return;
		}

		foreach ( self::DEQUEUE_STYLES as $handle ) {
			wp_dequeue_style( $handle );
		}

		foreach ( self::DEQUEUE_SCRIPTS as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Remove the WooCommerce Status/Reviews dashboard meta boxes.
	 */
	public function remove_status_widget() {
		remove_meta_box( 'woocommerce_dashboard_status', 'dashboard', 'normal' );
		remove_meta_box( 'woocommerce_dashboard_recent_reviews', 'dashboard', 'normal' );
	}

	/**
	 * Unregister the legacy WooCommerce widgets.
	 */
	public function unregister_widgets() {
		$widgets = array(
			'WC_Widget_Cart',
			'WC_Widget_Layered_Nav',
			'WC_Widget_Layered_Nav_Filters',
			'WC_Widget_Price_Filter',
			'WC_Widget_Product_Categories',
			'WC_Widget_Product_Search',
			'WC_Widget_Product_Tag_Cloud',
			'WC_Widget_Products',
			'WC_Widget_Recently_Viewed',
			'WC_Widget_Top_Rated_Products',
			'WC_Widget_Recent_Reviews',
			'WC_Widget_Rating_Filter',
		);

		foreach ( $widgets as $widget ) {
			if ( class_exists( $widget ) ) {
				unregister_widget( $widget );
			}
		}
	}

	/**
	 * Dequeue the WooCommerce password-strength-meter script.
	 */
	public function disable_password_meter() {
		wp_dequeue_script( 'wc-password-strength-meter' );
	}

	/**
	 * Drop the "marketing" feature from WooCommerce Admin.
	 *
	 * @param array $features Enabled WooCommerce Admin feature slugs.
	 * @return array
	 */
	public function remove_marketing_feature( $features ) {
		if ( ! is_array( $features ) ) {
			return $features;
		}

		return array_values( array_diff( $features, array( 'marketing' ) ) );
	}

	/**
	 * Remove the WooCommerce → Marketing submenu page.
	 */
	public function remove_marketing_menu() {
		remove_submenu_page( 'woocommerce', 'wc-admin&path=/marketing' );
	}
}
