<?php
/**
 * Coverage for the dashicons removal on the front end.
 *
 * The regression this pins down: `maybe_dequeue_dashicons()` used to call
 * `wp_deregister_style( 'dashicons' )`. Deregistering a handle removes it from
 * the registry, and `WP_Dependencies::all_deps()` then silently skips every
 * enqueued stylesheet that lists it as a dependency ("item requires
 * dependencies that don't exist"), plus anything depending on those in turn.
 *
 * Because the removal is gated on the visitor being logged out, only anonymous
 * visitors lost those stylesheets — the page looked correct to the logged-in
 * admin inspecting it. On a WooCommerce product page that took out the add-on
 * and variation-swatch CSS, leaving bare unstyled selects the buyer could not
 * complete, so no order could be placed.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the logged-out dashicons removal.
 */
class Dashicons_Dequeue_Test extends TestCase {

	/**
	 * Reset the recorded stub state before each test.
	 */
	protected function setUp(): void {
		global $spfw_test_style_calls, $spfw_test_logged_in, $spfw_test_hooks;

		$spfw_test_style_calls = array();
		$spfw_test_logged_in   = false;
		$spfw_test_hooks       = array();
	}

	/**
	 * Dequeue, never deregister: deregistering takes dependent stylesheets
	 * down with it, and the failure is silent.
	 */
	public function test_logged_out_visitor_dequeues_rather_than_deregisters() {
		global $spfw_test_style_calls;

		$module = new SPFW_Module_Core();
		$module->maybe_dequeue_dashicons();

		$this->assertSame(
			array( array( 'dequeue', 'dashicons' ) ),
			$spfw_test_style_calls,
			'dashicons must be dequeued, not deregistered: deregistering silently drops every stylesheet that depends on it.'
		);
	}

	/**
	 * Logged-in users keep the stylesheet — the admin bar draws its icons
	 * from it.
	 */
	public function test_logged_in_user_keeps_the_stylesheet() {
		global $spfw_test_style_calls, $spfw_test_logged_in;

		$spfw_test_logged_in = true;

		$module = new SPFW_Module_Core();
		$module->maybe_dequeue_dashicons();

		$this->assertSame( array(), $spfw_test_style_calls );
	}

	/**
	 * The pre-2.12.2 method name still works, and gets the fixed behavior —
	 * a site that unhooked or called it by name is not left on the old path.
	 */
	public function test_legacy_method_name_delegates_to_the_fixed_behavior() {
		global $spfw_test_style_calls;

		$module = new SPFW_Module_Core();
		$module->maybe_deregister_dashicons();

		$this->assertSame( array( array( 'dequeue', 'dashicons' ) ), $spfw_test_style_calls );
	}

	/**
	 * The hook points at the fixed callback. Without this the behavioral
	 * tests above could pass while `register()` still attached the old one.
	 */
	public function test_register_attaches_the_dequeue_callback() {
		global $spfw_test_hooks;

		SPFW_Settings::update( array( 'core' => array( 'disable_dashicons' => true ) ) );

		$module = new SPFW_Module_Core();
		$module->register();

		$attached = array();

		foreach ( $spfw_test_hooks as $hook ) {
			if ( 'wp_enqueue_scripts' !== $hook['tag'] || ! is_array( $hook['callback'] ) ) {
				continue;
			}

			$attached[] = $hook['callback'][1];
		}

		$this->assertContains( 'maybe_dequeue_dashicons', $attached );
		$this->assertNotContains( 'maybe_deregister_dashicons', $attached );
	}
}
