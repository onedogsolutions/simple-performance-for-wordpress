<?php
/**
 * Coverage for the front-end asset removals in the Core module.
 *
 * The contract these pin down: never `deregister` a shared handle to stop it
 * being printed. Deregistering removes it from the registry, and
 * `WP_Dependencies::all_deps()` then silently skips every enqueued item whose
 * dependencies are not all registered ("item requires dependencies that don't
 * exist"), plus anything depending on those in turn — no notice, no console
 * error, the asset simply never reaches the page.
 *
 * The regression that produced this file: `dashicons` was deregistered behind
 * a `! is_user_logged_in()` gate, so only anonymous visitors lost the
 * stylesheets that depend on it. The page looked correct to the logged-in
 * admin inspecting it. On a WooCommerce product page it took out the add-on
 * and variation-swatch CSS, leaving bare unstyled selects that could not be
 * completed, so no order could be placed. `wp-embed` carried the same defect
 * with a smaller blast radius and was fixed alongside it.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the dashicons and wp-embed removals.
 */
class Asset_Dequeue_Test extends TestCase {

	/**
	 * Reset the recorded stub state before each test.
	 */
	protected function setUp(): void {
		global $spfw_test_style_calls, $spfw_test_script_calls, $spfw_test_logged_in, $spfw_test_hooks;

		$spfw_test_style_calls  = array();
		$spfw_test_script_calls = array();
		$spfw_test_logged_in    = false;
		$spfw_test_hooks        = array();
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
	 * Same contract for the embed script. It runs on `wp_footer` at priority
	 * 1, ahead of `wp_print_footer_scripts()` at 20, so a footer script
	 * declaring `wp-embed` as a dependency was in range of the old call.
	 */
	public function test_embed_script_is_dequeued_rather_than_deregistered() {
		global $spfw_test_script_calls;

		$module = new SPFW_Module_Core();
		$module->dequeue_embed_script();

		$this->assertSame(
			array( array( 'dequeue', 'wp-embed' ) ),
			$spfw_test_script_calls,
			'wp-embed must be dequeued, not deregistered: deregistering silently drops every script that depends on it.'
		);
	}

	/**
	 * The pre-fix method names still work, and get the fixed behavior — a
	 * site that unhooked or called them by name is not left on the old path.
	 */
	public function test_legacy_method_names_delegate_to_the_fixed_behavior() {
		global $spfw_test_style_calls, $spfw_test_script_calls;

		$module = new SPFW_Module_Core();
		$module->maybe_deregister_dashicons();
		$module->deregister_embed_script();

		$this->assertSame( array( array( 'dequeue', 'dashicons' ) ), $spfw_test_style_calls );
		$this->assertSame( array( array( 'dequeue', 'wp-embed' ) ), $spfw_test_script_calls );
	}

	/**
	 * The hooks point at the fixed callbacks. Without this the behavioral
	 * tests above could pass while `register()` still attached the old ones.
	 */
	public function test_register_attaches_the_dequeue_callbacks() {
		global $spfw_test_hooks;

		SPFW_Settings::update(
			array(
				'core' => array(
					'disable_dashicons' => true,
					'disable_embeds'    => true,
				),
			)
		);

		$module = new SPFW_Module_Core();
		$module->register();

		$attached = array();

		foreach ( $spfw_test_hooks as $hook ) {
			if ( is_array( $hook['callback'] ) ) {
				$attached[] = $hook['callback'][1];
			}
		}

		$this->assertContains( 'maybe_dequeue_dashicons', $attached );
		$this->assertContains( 'dequeue_embed_script', $attached );
		$this->assertNotContains( 'maybe_deregister_dashicons', $attached );
		$this->assertNotContains( 'deregister_embed_script', $attached );
	}
}
