<?php
/**
 * MainWP child-side bridge.
 *
 * Lets the "MainWP for Simple Performance for WordPress" dashboard extension
 * read and update this plugin's settings over MainWP's signed dashboard-to-child
 * channel.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

class SPFW_MainWP_Child {

    public function __construct() {
        add_filter( 'mainwp_child_extra_execution', array( $this, 'handle' ), 10, 2 );
    }

    public function handle( $information, $post ) {
        if ( ! is_array( $post ) || empty( $post['spfw_action'] ) ) {
            return $information;
        }

        $action = wp_unslash( $post['spfw_action'] );

        if ( 'get_settings' === $action ) {
            $settings = SPFW_Settings::get();
            $settings['hardening_status']         = SPFW_Htaccess::status( 'plugins' );
            $settings['uploads_hardening_status'] = SPFW_Htaccess::status( 'uploads' );
        } elseif ( 'update_settings' === $action ) {
            $incoming = json_decode( isset( $post['settings'] ) ? wp_unslash( $post['settings'] ) : '', true );
            if ( ! is_array( $incoming ) ) {
                $information['spfw'] = array(
                    'success' => false,
                    'error'   => 'invalid settings payload',
                );
                return $information;
            }
            SPFW_Settings::update( $incoming );
            do_action( 'litespeed_purge_all' );
            $settings = SPFW_Settings::get();
        } else {
            $information['spfw'] = array(
                'success' => false,
                'error'   => 'unknown action',
            );
            return $information;
        }

        $information['spfw'] = array(
            'success'            => true,
            'version'            => SPFW_VERSION,
            'woocommerce_active' => class_exists( 'WooCommerce' ),
            'settings'           => $settings,
        );

        return $information;
    }
}
