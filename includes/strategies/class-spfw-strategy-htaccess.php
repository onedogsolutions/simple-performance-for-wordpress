<?php
/**
 * The .htaccess hardening strategy (Apache and the LiteSpeed family).
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adapter over SPFW_Htaccess, which owns the payload generation, integrity
 * hashing, marker-block handling and legacy migrations.
 *
 * Nothing moved out of SPFW_Htaccess when the strategy layer landed. That class
 * is the only implementation validated against a real OpenLiteSpeed install,
 * and rewriting it to fit a new interface would have put the one server with
 * field evidence behind the same "reasoned from documentation" caveat as nginx.
 * So this is a thin adapter, and the behavior on a `.htaccess` server is
 * byte-identical to what it was before.
 */
class SPFW_Strategy_Htaccess implements SPFW_Hardening_Strategy {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function key() {
		return 'htaccess';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return '.htaccess';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function supports( $target ) {
		return SPFW_Server::supports_htaccess()
			&& in_array( $target, array( 'plugins', 'uploads', 'root' ), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function apply( $target ) {
		return SPFW_Htaccess::write( $target );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function revert( $target ) {
		return SPFW_Htaccess::remove( $target );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return string
	 */
	public function status( $target ) {
		return SPFW_Htaccess::status( $target );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function needs_resync( $target ) {
		return SPFW_Htaccess::needs_resync( $target );
	}
}
