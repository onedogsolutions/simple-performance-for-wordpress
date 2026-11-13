<?php
/**
 * Coverage for the Command Palette removal in the Core module.
 *
 * Since WordPress 6.9 every admin screen enqueues the Command Palette
 * (Ctrl/Cmd+K) through `wp_enqueue_command_palette_assets()` on
 * `admin_enqueue_scripts`, pulling in ~40 block-editor scripts whether or
 * not the palette is ever opened. The toggle removes that callback on
 * classic admin screens and dequeues the handles late as a fallback.
 *
 * Two contracts these pin down:
 *  - Block-editor screens are skipped. `wp-commands` is a declared editor
 *    dependency, but `wp-core-commands` (the palette) is not, so removing
 *    the callback there would strip the editor's own palette.
 *  - Dequeue, never deregister — the same contract Asset_Dequeue_Test pins
 *    for dashicons/wp-embed: deregistering a shared handle silently drops
 *    every asset that depends on it.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Minimal screen double exposing the two members remove_command_palette()
 * reads: the screen id and is_block_editor().
 */
class SPFW_Test_Screen {

	/**
	 * Screen id.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Whether this screen uses the block editor.
	 *
	 * @var bool
	 */
	private $is_block;

	/**
	 * Constructor.
	 *
	 * @param string $id       Screen id.
	 * @param bool   $is_block Block-editor flag.
	 */
	public function __construct( $id, $is_block = false ) {
		$this->id       = $id;
		$this->is_block = $is_block;
	}

	/**
	 * Mirror WP_Screen::is_block_editor().
	 *
	 * @return bool
	 */
	public function is_block_editor() {
		return $this->is_block;
	}
}

/**
 * Tests for the Command Palette removal.
 */
class Command_Palette_Test extends TestCase {

	/**
	 * Reset the recorded stub state before each test.
	 */
	protected function setUp(): void {
		global $spfw_test_style_calls, $spfw_test_script_calls, $spfw_test_hooks,
			$spfw_test_removed_hooks, $spfw_test_is_admin, $spfw_test_screen;

		$spfw_test_style_calls   = array();
		$spfw_test_script_calls  = array();
		$spfw_test_hooks         = array();
		$spfw_test_removed_hooks = array();
		$spfw_test_is_admin      = false;
		$spfw_test_screen        = null;
	}

	/**
	 * Collect the method names of array callbacks attached to a hook.
	 *
	 * @param string $tag Hook name.
	 * @return string[]
	 */
	private function attached_methods( $tag ) {
		global $spfw_test_hooks;

		$methods = array();

		foreach ( $spfw_test_hooks as $hook ) {
			if ( $hook['tag'] === $tag && is_array( $hook['callback'] ) ) {
				$methods[] = $hook['callback'][1];
			}
		}

		return $methods;
	}

	/**
	 * Collect the callbacks removed from a hook.
	 *
	 * @param string $tag Hook name.
	 * @return array
	 */
	private function removed_callbacks( $tag ) {
		global $spfw_test_removed_hooks;

		$callbacks = array();

		foreach ( $spfw_test_removed_hooks as $hook ) {
			if ( $hook['tag'] === $tag ) {
				$callbacks[] = $hook['callback'];
			}
		}

		return $callbacks;
	}

	/**
	 * On a classic admin screen the core callback is unhooked and the late
	 * dequeue fallback is attached at PHP_INT_MAX.
	 */
	public function test_classic_screen_removes_core_callback_and_adds_fallback() {
		global $spfw_test_screen, $spfw_test_hooks;

		$spfw_test_screen = new SPFW_Test_Screen( 'options-general', false );

		$module = new SPFW_Module_Core();
		$module->remove_command_palette();

		$removed = $this->removed_callbacks( 'admin_enqueue_scripts' );
		$this->assertContains( 'wp_enqueue_command_palette_assets', $removed );
		$this->assertContains( 'wp_enqueue_command_palette', $removed );

		$fallback = null;
		foreach ( $spfw_test_hooks as $hook ) {
			if ( 'admin_enqueue_scripts' === $hook['tag']
				&& is_array( $hook['callback'] )
				&& 'dequeue_command_palette' === $hook['callback'][1] ) {
				$fallback = $hook;
			}
		}

		$this->assertNotNull( $fallback, 'The late dequeue fallback must be attached.' );
		$this->assertSame( PHP_INT_MAX, $fallback['priority'] );
	}

	/**
	 * A block-editor screen keeps the palette: nothing removed, no fallback.
	 */
	public function test_block_editor_screen_is_skipped() {
		global $spfw_test_screen, $spfw_test_removed_hooks;

		$spfw_test_screen = new SPFW_Test_Screen( 'post', true );

		$module = new SPFW_Module_Core();
		$module->remove_command_palette();

		$this->assertSame( array(), $spfw_test_removed_hooks );
		$this->assertNotContains( 'dequeue_command_palette', $this->attached_methods( 'admin_enqueue_scripts' ) );
	}

	/**
	 * Every block-based admin screen in the skip list keeps the palette,
	 * even when is_block_editor() does not flag it.
	 */
	public function test_skip_list_screens_keep_the_palette() {
		global $spfw_test_screen, $spfw_test_removed_hooks;

		$ids = array(
			'site-editor',
			'widgets',
			'customize',
			'appearance_page_gutenberg-edit-site',
			'toplevel_page_gutenberg-edit-site',
		);

		foreach ( $ids as $id ) {
			$spfw_test_screen        = new SPFW_Test_Screen( $id, false );
			$spfw_test_removed_hooks = array();

			$module = new SPFW_Module_Core();
			$module->remove_command_palette();

			$this->assertSame( array(), $spfw_test_removed_hooks, "Screen {$id} must keep the palette." );
			$this->assertNotContains( 'dequeue_command_palette', $this->attached_methods( 'admin_enqueue_scripts' ) );
		}
	}

	/**
	 * A null screen (e.g. admin-ajax) proceeds with the removal — it is
	 * harmless there and must not block the classic-screen path.
	 */
	public function test_null_screen_proceeds_with_removal() {
		global $spfw_test_screen;

		$spfw_test_screen = null;

		$module = new SPFW_Module_Core();
		$module->remove_command_palette();

		$this->assertContains( 'wp_enqueue_command_palette_assets', $this->removed_callbacks( 'admin_enqueue_scripts' ) );
	}

	/**
	 * The fallback dequeues — never deregisters — the palette handles.
	 */
	public function test_dequeue_uses_dequeue_not_deregister() {
		global $spfw_test_style_calls, $spfw_test_script_calls;

		$module = new SPFW_Module_Core();
		$module->dequeue_command_palette();

		$this->assertSame(
			array(
				array( 'dequeue', 'wp-core-commands' ),
				array( 'dequeue', 'wp-commands' ),
			),
			$spfw_test_script_calls,
			'Palette scripts must be dequeued, not deregistered.'
		);

		$this->assertSame(
			array( array( 'dequeue', 'wp-commands' ) ),
			$spfw_test_style_calls,
			'The palette stylesheet must be dequeued, not deregistered.'
		);
	}

	/**
	 * register() attaches the admin_init callback when the toggle is on and
	 * the request is an admin request.
	 */
	public function test_register_attaches_callback_when_enabled_and_admin() {
		global $spfw_test_is_admin;

		SPFW_Settings::update( array( 'core' => array( 'disable_command_palette' => true ) ) );
		$spfw_test_is_admin = true;

		$module = new SPFW_Module_Core();
		$module->register();

		$this->assertContains( 'remove_command_palette', $this->attached_methods( 'admin_init' ) );
	}

	/**
	 * register() attaches nothing when the toggle is off.
	 */
	public function test_register_does_not_attach_when_disabled() {
		global $spfw_test_is_admin;

		SPFW_Settings::update( array( 'core' => array( 'disable_command_palette' => false ) ) );
		$spfw_test_is_admin = true;

		$module = new SPFW_Module_Core();
		$module->register();

		$this->assertNotContains( 'remove_command_palette', $this->attached_methods( 'admin_init' ) );
	}

	/**
	 * register() attaches nothing on a front-end request, even when enabled.
	 */
	public function test_register_does_not_attach_on_frontend() {
		global $spfw_test_is_admin;

		SPFW_Settings::update( array( 'core' => array( 'disable_command_palette' => true ) ) );
		$spfw_test_is_admin = false;

		$module = new SPFW_Module_Core();
		$module->register();

		$this->assertNotContains( 'remove_command_palette', $this->attached_methods( 'admin_init' ) );
	}

	/**
	 * The setting defaults to false (opt-in) and sanitizes to a strict bool.
	 */
	public function test_setting_defaults_false_and_sanitizes_to_bool() {
		$defaults = SPFW_Settings::sanitize( array() );
		$this->assertFalse( $defaults['core']['disable_command_palette'] );

		$truthy = SPFW_Settings::sanitize( array( 'core' => array( 'disable_command_palette' => '1' ) ) );
		$this->assertTrue( $truthy['core']['disable_command_palette'] );

		$falsy = SPFW_Settings::sanitize( array( 'core' => array( 'disable_command_palette' => '' ) ) );
		$this->assertFalse( $falsy['core']['disable_command_palette'] );

		SPFW_Settings::update( array( 'core' => array( 'disable_command_palette' => true ) ) );
		$this->assertTrue( SPFW_Settings::group( 'core' )['disable_command_palette'] );
	}
}