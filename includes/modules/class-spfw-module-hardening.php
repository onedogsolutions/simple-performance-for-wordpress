<?php
/**
 * Module 3: directory-level security hardening.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces an admin notice when the plugins-directory hardening file is
 * missing or altered, and keeps it in sync with the setting toggle.
 */
class SPFW_Module_Hardening implements SPFW_Module {

	/**
	 * Attach hooks: an admin-only integrity check, and a settings-change
	 * listener that writes/removes the file when the toggle flips.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_show_notice' ) );
		add_action( 'update_option_' . SPFW_Settings::OPTION_KEY, array( $this, 'handle_settings_change' ), 10, 2 );
	}

	/**
	 * Queue an admin notice if the hardening file is missing or altered.
	 */
	public function maybe_show_notice() {
		$status = SPFW_Htaccess::status();

		if ( in_array( $status, array( 'missing', 'altered' ), true ) ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		}
	}

	/**
	 * Render the missing/altered admin notice, pointing at the Hardening
	 * tab (where the Restore action lives, via the REST controller).
	 */
	public function render_notice() {
		$status  = SPFW_Htaccess::status();
		$message = ( 'missing' === $status )
			? __( 'Simple Performance: the plugins-directory hardening file is missing.', 'simple-performance-for-wordpress' )
			: __( 'Simple Performance: the plugins-directory hardening file has been modified.', 'simple-performance-for-wordpress' );

		$url = add_query_arg(
			array(
				'page' => 'spfw-settings',
				'tab'  => 'hardening',
			),
			admin_url( 'options-general.php' )
		);

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( $url ),
			esc_html__( 'Go to the Hardening tab to restore it.', 'simple-performance-for-wordpress' )
		);
	}

	/**
	 * Write or remove the hardening file when its toggle changes.
	 *
	 * @param array $old_value Previous full settings array.
	 * @param array $new_value New full settings array.
	 */
	public function handle_settings_change( $old_value, $new_value ) {
		$was_on = ! empty( $old_value['hardening']['plugins_htaccess'] );
		$is_on  = ! empty( $new_value['hardening']['plugins_htaccess'] );

		if ( $is_on && ! $was_on ) {
			SPFW_Htaccess::write();
		} elseif ( $was_on && ! $is_on ) {
			SPFW_Htaccess::remove();
		}
	}
}
