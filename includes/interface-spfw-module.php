<?php
/**
 * Module interface for Simple Performance for WordPress.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every feature module implements this. register() reads pre-loaded
 * settings and conditionally attaches WordPress hooks; it must not
 * perform any of its own DB work.
 */
interface SPFW_Module {

	/**
	 * Attach hooks for this module based on current settings.
	 */
	public function register();
}
