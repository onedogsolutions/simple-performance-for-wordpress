<?php
/**
 * Strategy resolution for directory hardening.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Picks the mechanism that can actually enforce each hardening target on this
 * server, and routes apply/revert/status through it.
 *
 * The toggles are intent; this decides how the intent is realised. The order in
 * strategies() is the preference order, and the first strategy that supports a
 * target owns it:
 *
 * 1. `.htaccess` — Apache and the LiteSpeed family. The only mechanism with
 *    field evidence behind it, and the only one that covers static files as
 *    well as PHP, so it wins wherever it is available. That includes
 *    OpenLiteSpeed: a `.user.ini` TTL is operationally nicer than a graceful
 *    restart, but swapping the mechanism on the one server this plugin has
 *    actually been validated against, to gain convenience rather than
 *    coverage, is a trade in the wrong direction. OLS behaves exactly as it
 *    did before this layer existed.
 * 2. `.user.ini` — uploads only, on a FastCGI stack with no `.htaccess`.
 * 3. snippet — everything left over, which on nginx is most of it.
 *
 * What is NOT decided here is whether a rule is in force. That is the
 * enforcement probe's job, and it works the same way for all three, because it
 * only ever asks the server over HTTP what it does with a URL.
 */
class SPFW_Hardening_Strategies {

	/**
	 * Every hardening target, in the order the UI shows them.
	 *
	 * @var string[]
	 */
	const TARGETS = array( 'plugins', 'uploads', 'root' );

	/**
	 * Instantiated strategies, in preference order. Rebuilt whenever the
	 * detected server changes (tests do that; a live site does not).
	 *
	 * @var SPFW_Hardening_Strategy[]|null
	 */
	private static $strategies = null;

	/**
	 * Drop the cached strategy instances. Only tests need this.
	 */
	public static function reset() {
		self::$strategies = null;
	}

	/**
	 * The strategies, in preference order.
	 *
	 * @return SPFW_Hardening_Strategy[]
	 */
	public static function strategies() {
		if ( null === self::$strategies ) {
			self::$strategies = array(
				new SPFW_Strategy_Htaccess(),
				new SPFW_Strategy_User_Ini(),
				new SPFW_Strategy_Snippet(),
			);
		}

		return self::$strategies;
	}

	/**
	 * The strategy that owns a target on this server.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return SPFW_Hardening_Strategy|null Null when nothing covers it, which
	 *                                      happens only for an unrecognised
	 *                                      target key.
	 */
	public static function for_target( $target ) {
		foreach ( self::strategies() as $strategy ) {
			if ( $strategy->supports( $target ) ) {
				return $strategy;
			}
		}

		return null;
	}

	/**
	 * Put a target's rule in place using whichever strategy owns it.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public static function apply( $target ) {
		$strategy = self::for_target( $target );

		return $strategy ? $strategy->apply( $target ) : false;
	}

	/**
	 * Take a target's rule back out.
	 *
	 * Every strategy is asked, not just the owner: a site that moved from
	 * Apache to nginx (or had `user_ini.filename` disabled under it) can be
	 * left holding a file the current owner knows nothing about, and turning
	 * the toggle off has to clean that up too.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool True when every strategy removed what it authored.
	 */
	public static function revert( $target ) {
		$ok = true;

		foreach ( self::strategies() as $strategy ) {
			$ok = $strategy->revert( $target ) && $ok;
		}

		return $ok;
	}

	/**
	 * Integrity status for a target, from the strategy that owns it.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return string One of ok|missing|altered|disabled|unsupported|advisory.
	 */
	public static function status( $target ) {
		$strategy = self::for_target( $target );

		return $strategy ? $strategy->status( $target ) : 'unsupported';
	}

	/**
	 * Rewrite any target whose authored content has drifted from what the
	 * current toggles require.
	 *
	 * @return string[] Targets that were rewritten.
	 */
	public static function reconcile() {
		$rewritten = array();

		foreach ( self::TARGETS as $target ) {
			$strategy = self::for_target( $target );

			if ( ! $strategy || ! $strategy->needs_resync( $target ) ) {
				continue;
			}

			if ( $strategy->apply( $target ) ) {
				$rewritten[] = $target;
			}
		}

		return $rewritten;
	}

	/**
	 * Per-target strategy report for the REST layer.
	 *
	 * @return array<string,array{strategy:string,label:string,status:string}>
	 */
	public static function summary() {
		$summary = array();

		foreach ( self::TARGETS as $target ) {
			$strategy = self::for_target( $target );

			$summary[ $target ] = array(
				'strategy' => $strategy ? $strategy->key() : 'none',
				'label'    => $strategy ? $strategy->label() : '',
				'status'   => $strategy ? $strategy->status( $target ) : 'unsupported',
			);
		}

		return $summary;
	}

	/**
	 * The server-configuration snippet, when this server needs one.
	 *
	 * Returned whenever any target falls through to the snippet strategy, and
	 * empty otherwise, so a `.htaccess` server never sees nginx config it has
	 * no use for.
	 *
	 * @return array{format:string,body:string}
	 */
	public static function snippet() {
		$needed = false;

		foreach ( self::TARGETS as $target ) {
			$strategy = self::for_target( $target );

			if ( $strategy && 'snippet' === $strategy->key() ) {
				$needed = true;
				break;
			}
		}

		if ( ! $needed ) {
			return array(
				'format' => '',
				'body'   => '',
			);
		}

		return SPFW_Strategy_Snippet::snippet();
	}
}
