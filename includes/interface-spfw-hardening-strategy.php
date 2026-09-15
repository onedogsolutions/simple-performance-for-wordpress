<?php
/**
 * Contract for the per-server mechanisms that enforce directory hardening.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * One way of making a hardening rule take effect on a particular server.
 *
 * The toggles the admin sees ("block direct PHP execution in uploads") are
 * statements of intent. How that intent is realised depends entirely on what
 * the server in front of PHP honors, and there is no single mechanism that
 * covers every server:
 *
 * - `.htaccess` works on Apache and the LiteSpeed family, and nowhere else.
 * - `.user.ini` + `auto_prepend_file` blocks PHP execution on any FastCGI
 *   stack, but cannot touch a static file, because no PHP runs for one.
 * - A vhost snippet works everywhere and applies nowhere until a human with
 *   root pastes it in.
 *
 * Each implementation answers for one of those. What deliberately does NOT live
 * behind this interface is the verdict: whether a rule is actually in force is
 * decided by the shared enforcement probe, which requests the canary URL over
 * HTTP and reads the status code. That probe is the most portable thing in the
 * subsystem — it knows nothing about servers or files — so "is it enforced"
 * never depends on which strategy ran.
 */
interface SPFW_Hardening_Strategy {

	/**
	 * Stable machine key for this strategy.
	 *
	 * @return string One of htaccess|user_ini|snippet.
	 */
	public function key();

	/**
	 * Short untranslated descriptor, in the style of the canary labels: the
	 * admin UI supplies its own translated copy.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether this strategy can act on a target on this server.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function supports( $target );

	/**
	 * Put the rule in place.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool True when the on-disk state now matches intent.
	 */
	public function apply( $target );

	/**
	 * Take the rule back out, but only where this strategy authored it.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool True when removed or already absent; false when a foreign
	 *              file blocks removal.
	 */
	public function revert( $target );

	/**
	 * Integrity of what this strategy wrote — never a claim about enforcement.
	 *
	 * `advisory` is the state that only exists here: the rule is enabled and
	 * this strategy can describe it, but describing it is all a plugin without
	 * root can do. It must never be rendered as protection.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return string One of ok|missing|altered|disabled|unsupported|advisory.
	 */
	public function status( $target );

	/**
	 * Whether authored content has drifted from what the current toggles
	 * require, so reconcile() should rewrite it.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function needs_resync( $target );
}
