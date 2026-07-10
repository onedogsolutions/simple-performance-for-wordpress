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
	 * Whether the current request is a WooCommerce-specific page where its
	 * scripts and styles are actually needed.
	 *
	 * @return bool
	 */
	private function is_woo_page() {
		return function_exists( 'is_woocommerce' )
			&& ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() );
	}

	/**
	 * Dequeue the AJAX cart-fragments script everywhere except the cart and
	 * checkout, killing the site-wide `?wc-ajax=get_refreshed_fragments`
	 * request that otherwise defeats full-page caching.
	 */
	public function disable_cart_fragments() {
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return;
		}

		wp_dequeue_script( 'wc-cart-fragments' );
	}

	/**
	 * Dequeue WooCommerce styles and scripts on non-WooCommerce pages.
	 */
	public function disable_scripts_styles() {
		if ( $this->is_woo_page() ) {
			return;
		}

		$styles = array(
			'woocommerce-general',
			'woocommerce-layout',
			'woocommerce-smallscreen',
			'woocommerce-inline',
			'wc-blocks-style',
			'wc-blocks-vendors-style',
		);

		foreach ( $styles as $handle ) {
			wp_dequeue_style( $handle );
		}

		$scripts = array(
			'woocommerce',
			'wc-cart-fragments',
			'wc-add-to-cart',
			'jquery-blockui',
			'js-cookie',
		);

		foreach ( $scripts as $handle ) {
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
