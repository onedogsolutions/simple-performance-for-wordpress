<?php
/**
 * Coverage for the WooCommerce "non-store pages" asset dequeue.
 *
 * The regression this pins down: the dequeue list used to include
 * `wc-add-to-cart`, so on any page `is_woocommerce()` did not recognize — a
 * page-builder landing page with a product grid, the front page, a post with
 * a [products] shortcode — the Add to Cart button lost its handler and failed
 * silently, with no console error to trace back.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for store-content detection and the dequeue/keep asset lists.
 */
class Woo_Asset_Dequeue_Test extends TestCase {

	/**
	 * The Add to Cart handler is never dropped by the non-store-pages toggle.
	 */
	public function test_add_to_cart_handler_is_never_dequeued() {
		$this->assertNotContains(
			'wc-add-to-cart',
			SPFW_Module_WooCommerce::DEQUEUE_SCRIPTS,
			'wc-add-to-cart must never be dequeued: no heuristic can reliably tell whether a page builder rendered an Add to Cart button.'
		);

		$this->assertContains( 'wc-add-to-cart', SPFW_Module_WooCommerce::KEEP_SCRIPTS );
	}

	/**
	 * The variation script travels with its dependency chain. Previously
	 * `wc-add-to-cart` was dequeued while `wc-add-to-cart-variation` was left
	 * enqueued, so a variable product ran a script whose dependency and
	 * `wc_add_to_cart_params` data object were both gone.
	 */
	public function test_variation_script_is_kept_with_its_dependencies() {
		foreach ( array( 'wc-add-to-cart-variation', 'jquery-blockui', 'js-cookie' ) as $handle ) {
			$this->assertContains( $handle, SPFW_Module_WooCommerce::KEEP_SCRIPTS );
		}
	}

	/**
	 * The two lists can never disagree about a handle.
	 */
	public function test_keep_and_dequeue_lists_are_disjoint() {
		$this->assertSame(
			array(),
			array_values(
				array_intersect(
					SPFW_Module_WooCommerce::KEEP_SCRIPTS,
					SPFW_Module_WooCommerce::DEQUEUE_SCRIPTS
				)
			)
		);
	}

	/**
	 * The savings this toggle actually exists for are still taken.
	 */
	public function test_stylesheets_and_bulk_scripts_are_still_dequeued() {
		$this->assertContains( 'woocommerce-general', SPFW_Module_WooCommerce::DEQUEUE_STYLES );
		$this->assertContains( 'wc-blocks-style', SPFW_Module_WooCommerce::DEQUEUE_STYLES );
		$this->assertContains( 'woocommerce', SPFW_Module_WooCommerce::DEQUEUE_SCRIPTS );
		$this->assertContains( 'wc-cart-fragments', SPFW_Module_WooCommerce::DEQUEUE_SCRIPTS );
	}

	/**
	 * Store content embedded in an ordinary page is recognized.
	 *
	 * @dataProvider store_content_provider
	 *
	 * @param string $content  Post content.
	 * @param bool   $expected Whether it should count as store content.
	 */
	public function test_content_detection( $content, $expected ) {
		$this->assertSame(
			$expected,
			SPFW_Module_WooCommerce::content_has_woo_markup( $content )
		);
	}

	/**
	 * Content samples and their expected verdict.
	 *
	 * @return array[]
	 */
	public static function store_content_provider() {
		return array(
			'empty'                => array( '', false ),
			'plain prose'          => array( '<p>About our company.</p>', false ),
			'woo block'            => array( '<!-- wp:woocommerce/all-products {"columns":3} -->', true ),
			'products shortcode'   => array( '[products limit="4" columns="4"]', true ),
			'bare products'        => array( 'Shop now: [products]', true ),
			'add_to_cart'          => array( '[add_to_cart id="99"]', true ),
			'product_page'         => array( '[product_page id="99"]', true ),
			'cart shortcode'       => array( '[woocommerce_cart]', true ),
			'self-closing'         => array( '[product/]', true ),
			// The tag must end at the bracket or whitespace, or every word
			// starting with "product" would keep WooCommerce's assets loaded.
			'prefix is not a tag'  => array( '[productivity_chart]', false ),
			'name in prose only'   => array( 'Our products are great.', false ),
			'non-string'           => array( null, false ),
		);
	}
}
