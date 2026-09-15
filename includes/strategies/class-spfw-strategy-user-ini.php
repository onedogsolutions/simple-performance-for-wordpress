<?php
/**
 * The `.user.ini` + `auto_prepend_file` hardening strategy (any FastCGI stack).
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blocks direct PHP execution in the uploads directory on a server that has no
 * `.htaccess`.
 *
 * `auto_prepend_file` is `PHP_INI_PERDIR`, so a `.user.ini` inside
 * `wp-content/uploads/` makes every PHP request whose entry script lives in
 * that tree run a guard first. The guard refuses the request unless the script
 * is one the admin explicitly whitelisted. Wordfence's "Extended Protection" is
 * the precedent for the technique.
 *
 * Three constraints shape everything below.
 *
 * **It covers PHP execution only.** No PHP runs for `readme.html`, a `.sql`
 * dump or a `.env`, so a prepended guard can never see those requests. Static
 * files are the snippet strategy's problem, or the file is deleted; see
 * SPFW_Strategy_Snippet.
 *
 * **A wrong `auto_prepend_file` path breaks every PHP request in the tree.**
 * That is the reason this strategy is scoped to `uploads` and never to
 * `plugins`. Uploads has no legitimate PHP entry point — blocking all of it is
 * the entire goal — so the blast radius of a broken prepend is exactly the set
 * of requests the rule exists to refuse. `wp-content/plugins/` is the opposite:
 * real plugins serve real PHP from there (LiteSpeed's `guest.vary.php` among
 * them), so a broken prepend would take the front end down. On a server without
 * `.htaccess`, plugins/ gets the vhost snippet instead. Three further guards
 * apply before the file is trusted: every interpolated path is validated
 * against the whitelist sanitizer's character class and emitted with
 * `var_export()`, the generated source is parse-checked with
 * `token_get_all( …, TOKEN_PARSE )` before it is written, and the `.user.ini`
 * that names the guard is only written after the guard exists and is readable.
 * A 5xx from the protected tree afterwards is classified `broken` by the probe
 * (not `unknown`) and reverts this strategy automatically.
 *
 * **It is FastCGI-only and inert when the host disabled it.** SPFW_Server reads
 * the live `user_ini.filename` rather than assuming `.user.ini`, and the live
 * `user_ini.cache_ttl` rather than assuming 300 — the TTL is the config
 * staleness clock this strategy reports, and it is the strategy's one real
 * operational advantage: a delay that expires by itself, not a privileged
 * action someone has to perform.
 */
class SPFW_Strategy_User_Ini implements SPFW_Hardening_Strategy {

	/**
	 * Filename of the generated guard, written beside the `.user.ini` inside
	 * the directory it protects.
	 *
	 * Deliberately not inside the plugin directory: a path there would dangle
	 * the moment the plugin folder is renamed or deleted without deactivating
	 * first, and a dangling `auto_prepend_file` is the failure mode this whole
	 * class is arranged around. Living in the tree it guards means it moves
	 * with the uploads directory and survives anything done to the plugin.
	 *
	 * @var string
	 */
	const GUARD_FILENAME = '.spfw-uploads-guard.php';

	/**
	 * Filename of the probe canary this strategy plants in the directory it
	 * protects.
	 *
	 * The synthetic canary the `.htaccess` probe uses — a `.php` path that does
	 * not exist — cannot work here, and the reason is worth spelling out
	 * because it would otherwise read as a false negative forever. A deny rule
	 * in server config fires on the URL before anything touches the filesystem,
	 * so 403-versus-404 is decisive there. `auto_prepend_file` is not server
	 * config: it only runs when the server actually hands the request to PHP
	 * with a script to execute. Ask for a script that is not there and PHP-FPM
	 * answers "File not found" without ever loading the prepend, so a perfectly
	 * working guard would be reported as inert.
	 *
	 * A real file makes the reading three-way and unambiguous, which is also
	 * the survival test this strategy needs: 403 means the guard ran and
	 * refused, 200 means the guard is not running yet (or at all), and 5xx
	 * means the prepend itself is broken — the one failure mode that justifies
	 * reverting automatically rather than reporting.
	 *
	 * It holds a fixed string, reads nothing, and takes no input; when the
	 * guard is working it is refused like everything else in the tree.
	 *
	 * @var string
	 */
	const CANARY_FILENAME = 'spfw-user-ini-canary.php';

	/**
	 * One-off cron that re-probes after `user_ini.cache_ttl` has elapsed.
	 *
	 * A freshly written `.user.ini` is not read until PHP's per-directory cache
	 * for that directory expires, so probing immediately after the write proves
	 * nothing either way. This event is what turns "written" into "verified".
	 *
	 * @var string
	 */
	const VERIFY_CRON = 'spfw_user_ini_verify';

	/**
	 * Settings key holding the sha1 of the `.user.ini` this plugin authored.
	 *
	 * @var string
	 */
	const INI_HASH_KEY = 'user_ini_hash';

	/**
	 * Settings key holding the sha1 of the guard file this plugin authored.
	 *
	 * @var string
	 */
	const GUARD_HASH_KEY = 'user_ini_guard_hash';

	/**
	 * Settings key holding the sha1 of the probe canary.
	 *
	 * @var string
	 */
	const CANARY_HASH_KEY = 'user_ini_canary_hash';

	/**
	 * Settings key holding the unix time the `.user.ini` was last written.
	 *
	 * @var string
	 */
	const WRITTEN_KEY = 'user_ini_written';

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function key() {
		return 'user_ini';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label() {
		return '.user.ini + auto_prepend_file';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only ever the uploads target, only on a stack that reads `.user.ini`, and
	 * only where `.htaccess` is not available: where it is, the rewrite rules
	 * are the mechanism with field evidence behind them and this strategy would
	 * be a second, weaker copy of a rule that already works.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function supports( $target ) {
		return 'uploads' === $target
			&& ! SPFW_Server::supports_htaccess()
			&& SPFW_Server::supports_user_ini();
	}

	/**
	 * Absolute path of the uploads directory, with a trailing slash.
	 *
	 * @return string
	 */
	public static function uploads_dir() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] );
	}

	/**
	 * Absolute path of the generated guard file.
	 *
	 * @return string
	 */
	public static function guard_path() {
		return self::uploads_dir() . self::GUARD_FILENAME;
	}

	/**
	 * Absolute path of the probe canary.
	 *
	 * @return string
	 */
	public static function canary_path() {
		return self::uploads_dir() . self::CANARY_FILENAME;
	}

	/**
	 * Public URL of the probe canary.
	 *
	 * @return string
	 */
	public static function canary_url() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::CANARY_FILENAME;
	}

	/**
	 * The probe canary's contents.
	 *
	 * @return string
	 */
	public static function canary_payload() {
		return "<?php\n"
			. "// Simple Performance for WordPress — enforcement probe canary.\n"
			. "// Exists only so the uploads PHP guard has something real to refuse.\n"
			. "// Takes no input and reads nothing. Safe to delete; it will be recreated.\n"
			. "header( 'Content-Type: text/plain; charset=utf-8' );\n"
			. "echo 'spfw-user-ini-canary';\n";
	}

	/**
	 * Absolute path of the `.user.ini` (or whatever `user_ini.filename` says).
	 *
	 * @return string Empty string when the host disabled the mechanism.
	 */
	public static function ini_path() {
		$name = SPFW_Server::user_ini_filename();

		return '' !== $name ? self::uploads_dir() . $name : '';
	}

	/**
	 * Uploads-relative PHP files the admin has whitelisted, as absolute paths.
	 *
	 * Reuses SPFW_Htaccess::effective_whitelist() so a whitelist entry means
	 * the same thing whichever strategy is running, and re-applies the
	 * sanitizer's character class: these strings are interpolated into
	 * generated PHP, and a value stored before the sanitizer existed must not
	 * be trusted on the strength of having been stored.
	 *
	 * @return string[] Absolute paths, forward-slashed, deduplicated.
	 */
	public static function allowed_paths() {
		$whitelist = SPFW_Htaccess::effective_whitelist();
		$allowed   = array();

		foreach ( (array) $whitelist as $path ) {
			$path = (string) $path;

			if ( 0 !== strpos( $path, 'uploads/' ) ) {
				continue;
			}

			if ( ! preg_match( '#^[A-Za-z0-9._/-]+$#', $path ) || false !== strpos( $path, '..' ) ) {
				continue;
			}

			$absolute  = self::normalize( WP_CONTENT_DIR . '/' . $path );
			$allowed[] = $absolute;

			$real = realpath( $absolute );

			if ( is_string( $real ) && '' !== $real ) {
				// A symlinked uploads directory resolves to a different string
				// than the one built above, and the guard compares the resolved
				// script path, so both forms have to be present.
				$allowed[] = self::normalize( $real );
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Forward-slash a path so the guard's prefix test behaves the same way on
	 * every platform.
	 *
	 * @param string $path Filesystem path.
	 * @return string
	 */
	private static function normalize( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}

	/**
	 * The guard source.
	 *
	 * Standalone by design: it runs before WordPress on every PHP request in
	 * the tree, so it loads nothing, defines no functions and touches no
	 * database. The whitelist is baked in as a literal, which is also why
	 * refreshing the whitelist means regenerating this file — see
	 * needs_resync().
	 *
	 * @return string
	 */
	public static function guard_payload() {
		$root    = self::normalize( self::uploads_dir() );
		$real    = realpath( self::uploads_dir() );
		$roots   = array( $root );
		$allowed = self::allowed_paths();

		if ( is_string( $real ) && '' !== $real ) {
			$roots[] = trailingslashit( self::normalize( $real ) );
		}

		$roots = array_values( array_unique( $roots ) );

		return "<?php\n"
			. "/**\n"
			. " * Generated by Simple Performance for WordPress — do not edit.\n"
			. " *\n"
			. " * Loaded through auto_prepend_file by the .user.ini beside it, so it runs\n"
			. " * before any PHP script in this directory tree. It refuses every such request\n"
			. " * except the paths the site administrator whitelisted. It does nothing at all\n"
			. " * for a script outside the tree, or on CLI.\n"
			. " *\n"
			. " * Deleting this file does not weaken the site on its own — but leave the\n"
			. " * .user.ini beside it in place and PHP will be pointed at a file that is not\n"
			. " * there. Remove both, or turn the uploads hardening toggle off, which removes\n"
			. " * both for you.\n"
			. " *\n"
			. " * @package Simple_Performance_For_WordPress\n"
			. " */\n"
			. "\n"
			. "if ( defined( 'SPFW_UPLOADS_GUARD' ) ) {\n"
			. "\treturn;\n"
			. "}\n"
			. "\n"
			. "define( 'SPFW_UPLOADS_GUARD', true );\n"
			. "\n"
			. "( function () {\n"
			. "\t// A .user.ini is never read on CLI, so the guard can only get here\n"
			. "\t// under CLI if auto_prepend_file was set globally — WP-CLI and cron\n"
			. "\t// must not be refused. The env var is the plugin's own self-test\n"
			. "\t// opting back in; it can only make this guard stricter, never\n"
			. "\t// looser, and nothing reachable over HTTP can set it.\n"
			. "\tif ( 'cli' === PHP_SAPI && ! getenv( 'SPFW_GUARD_SELFTEST' ) ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\n"
			. "\t\$script = isset( \$_SERVER['SCRIPT_FILENAME'] ) ? (string) \$_SERVER['SCRIPT_FILENAME'] : '';\n"
			. "\n"
			. "\tif ( '' === \$script ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\n"
			. "\t\$resolved = realpath( \$script );\n"
			. "\t\$script   = str_replace( '\\\\', '/', \$script );\n"
			. "\t\$resolved = is_string( \$resolved ) && '' !== \$resolved ? str_replace( '\\\\', '/', \$resolved ) : \$script;\n"
			. "\n"
			. "\t\$roots = " . self::export( $roots ) . ";\n"
			. "\t\$inside = false;\n"
			. "\n"
			. "\tforeach ( \$roots as \$root ) {\n"
			. "\t\tif ( 0 === strpos( \$resolved, \$root ) || 0 === strpos( \$script, \$root ) ) {\n"
			. "\t\t\t\$inside = true;\n"
			. "\t\t\tbreak;\n"
			. "\t\t}\n"
			. "\t}\n"
			. "\n"
			. "\tif ( ! \$inside ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\n"
			. "\t\$allowed = " . self::export( $allowed ) . ";\n"
			. "\n"
			. "\tif ( in_array( \$resolved, \$allowed, true ) || in_array( \$script, \$allowed, true ) ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\n"
			. "\tif ( ! headers_sent() ) {\n"
			. "\t\theader( 'Content-Type: text/plain; charset=utf-8' );\n"
			. "\t\theader( 'X-Content-Type-Options: nosniff' );\n"
			. "\t\thttp_response_code( 403 );\n"
			. "\t}\n"
			. "\n"
			. "\techo 'Forbidden';\n"
			. "\texit;\n"
			. "} )();\n";
	}

	/**
	 * Render a list of validated path strings as a PHP array literal.
	 *
	 * Every element has already passed the whitelist character class, so
	 * var_export() here is belt and braces rather than the only defence — but
	 * generated code that interpolates strings gets both.
	 *
	 * @param string[] $values Path strings.
	 * @return string
	 */
	private static function export( array $values ) {
		if ( empty( $values ) ) {
			return 'array()';
		}

		$parts = array();

		foreach ( $values as $value ) {
			$parts[] = var_export( (string) $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		}

		return 'array( ' . implode( ', ', $parts ) . ' )';
	}

	/**
	 * The `.user.ini` contents.
	 *
	 * @return string
	 */
	public static function ini_payload() {
		$guard = self::normalize( self::guard_path() );

		return "; BEGIN Simple Performance for WordPress\n"
			. "; Runs the guard beside this file before any PHP script in this directory.\n"
			. "; Removing this line disables uploads PHP blocking on servers without .htaccess.\n"
			. 'auto_prepend_file = "' . $guard . "\"\n"
			. "; END Simple Performance for WordPress\n";
	}

	/**
	 * Whether the generated source is syntactically valid PHP.
	 *
	 * Parsed, never executed. A guard that would not compile must not reach
	 * disk: PHP would then fail to include it on every request in the tree,
	 * which is precisely the outcome this class is built to avoid.
	 *
	 * @param string $source Generated PHP source.
	 * @return bool
	 */
	public static function parses( $source ) {
		try {
			token_get_all( $source, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			return false;
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Order matters: guard first, `.user.ini` last. Between the two writes PHP
	 * is pointed at nothing, and after both it is pointed at a file that is
	 * known to exist and known to parse.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function apply( $target ) {
		if ( ! $this->supports( $target ) ) {
			return false;
		}

		$ini_path = self::ini_path();

		if ( '' === $ini_path ) {
			return false;
		}

		$guard_source = self::guard_payload();

		if ( ! self::parses( $guard_source ) ) {
			return false;
		}

		$fs = self::filesystem();

		if ( ! $fs ) {
			return false;
		}

		$guard_path = self::guard_path();

		if ( ! $this->is_ours( $guard_path, self::GUARD_HASH_KEY, $guard_source ) ) {
			// A file of that name we did not author. Refuse rather than
			// clobber, exactly as SPFW_Htaccess refuses a foreign .htaccess.
			return false;
		}

		if ( ! $this->put( $fs, $guard_path, $guard_source, self::GUARD_HASH_KEY ) ) {
			return false;
		}

		if ( ! file_exists( $guard_path ) || ! is_readable( $guard_path ) ) {
			return false;
		}

		$canary_source = self::canary_payload();

		if ( $this->is_ours( self::canary_path(), self::CANARY_HASH_KEY, $canary_source ) ) {
			// Best effort: the canary only exists so the probe has something to
			// ask for. Failing to plant it costs a verdict, not protection, so
			// it never blocks the write that does the protecting.
			$this->put( $fs, self::canary_path(), $canary_source, self::CANARY_HASH_KEY );
		}

		$ini_source = self::ini_payload();

		if ( ! $this->is_ours( $ini_path, self::INI_HASH_KEY, $ini_source ) ) {
			return false;
		}

		if ( ! $this->put( $fs, $ini_path, $ini_source, self::INI_HASH_KEY ) ) {
			return false;
		}

		SPFW_Settings::update( array( 'hardening' => array( self::WRITTEN_KEY => time() ) ) );
		self::schedule_verification();

		return true;
	}

	/**
	 * Schedule the deferred verification for just after the per-directory ini
	 * cache expires.
	 *
	 * The 60-second margin is there because the TTL is measured from when PHP
	 * last scanned the directory, not from when the file was written, so the
	 * true expiry is somewhere in the window and probing at exactly the TTL can
	 * land a moment early.
	 */
	public static function schedule_verification() {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		$when = time() + SPFW_Server::user_ini_cache_ttl() + MINUTE_IN_SECONDS;

		wp_schedule_single_event( $when, self::VERIFY_CRON );
	}

	/**
	 * When the rules on disk start being applied by the running PHP processes.
	 *
	 * This is the config staleness clock for this strategy, and the reason it
	 * is worth having on nginx at all: it is a deadline that passes by itself,
	 * where a rewrite rule would need someone with root to reload the server.
	 *
	 * @return int Unix time; 0 when nothing has been written.
	 */
	public static function applies_at() {
		$written = (int) SPFW_Settings::value( 'hardening', self::WRITTEN_KEY, 0 );

		return $written > 0 ? $written + SPFW_Server::user_ini_cache_ttl() : 0;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The `.user.ini` goes first: while it is present and the guard is not, PHP
	 * has a dangling prepend, so removal runs in the opposite order to apply().
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function revert( $target ) {
		if ( 'uploads' !== $target ) {
			return true;
		}

		$ini_path = self::ini_path();

		// Nothing this strategy authored is present. Return early rather than
		// writing settings and clearing crons on every revert of every target
		// on every server — the coordinator asks all three strategies to revert
		// so a site that changed servers still gets cleaned up, which means
		// this path runs on .htaccess installs too.
		$paths = array_filter( array( $ini_path, self::guard_path(), self::canary_path() ) );
		$found = false;

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return true;
		}

		$ok = true;

		if ( '' !== $ini_path ) {
			$ok = $this->delete( $ini_path, self::INI_HASH_KEY ) && $ok;
		}

		$ok = $this->delete( self::guard_path(), self::GUARD_HASH_KEY ) && $ok;
		$ok = $this->delete( self::canary_path(), self::CANARY_HASH_KEY ) && $ok;

		SPFW_Settings::update( array( 'hardening' => array( self::WRITTEN_KEY => 0 ) ) );

		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::VERIFY_CRON );
		}

		return $ok;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return string
	 */
	public function status( $target ) {
		if ( 'uploads' !== $target ) {
			return 'unsupported';
		}

		if ( ! SPFW_Server::supports_user_ini() ) {
			return 'unsupported';
		}

		if ( ! SPFW_Settings::value( 'hardening', 'uploads_htaccess', false ) ) {
			return 'disabled';
		}

		$ini_path = self::ini_path();

		if ( '' === $ini_path || ! file_exists( $ini_path ) || ! file_exists( self::guard_path() ) ) {
			return 'missing';
		}

		$ini_hash   = (string) SPFW_Settings::value( 'hardening', self::INI_HASH_KEY, '' );
		$guard_hash = (string) SPFW_Settings::value( 'hardening', self::GUARD_HASH_KEY, '' );

		if ( sha1_file( $ini_path ) !== $ini_hash || sha1_file( self::guard_path() ) !== $guard_hash ) {
			return 'altered';
		}

		return 'ok';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The guard bakes the whitelist in as a literal, so a whitelist edit leaves
	 * an authored guard that no longer matches the current settings — the same
	 * drift SPFW_Htaccess::needs_resync() catches for a rewrite payload.
	 *
	 * @param string $target One of plugins|uploads|root.
	 * @return bool
	 */
	public function needs_resync( $target ) {
		if ( 'ok' !== $this->status( $target ) ) {
			return false;
		}

		if ( sha1_file( self::guard_path() ) !== sha1( self::guard_payload() )
			|| sha1_file( self::ini_path() ) !== sha1( self::ini_payload() ) ) {
			return true;
		}

		// The canary is diagnostic rather than protective, so its absence is
		// deliberately not a 'missing' status — but reconcile() should quietly
		// put it back, or the probe loses its only decisive question.
		return ! file_exists( self::canary_path() );
	}

	/**
	 * Whether a path is absent, authored by this plugin, or already equal to
	 * what we are about to write.
	 *
	 * @param string $path     Absolute path.
	 * @param string $hash_key Settings key holding the authored hash.
	 * @param string $expected Content we intend to write.
	 * @return bool False when a foreign file occupies the path.
	 */
	private function is_ours( $path, $hash_key, $expected ) {
		if ( ! file_exists( $path ) ) {
			return true;
		}

		$disk   = (string) sha1_file( $path );
		$stored = (string) SPFW_Settings::value( 'hardening', $hash_key, '' );

		return ( '' !== $stored && $stored === $disk ) || sha1( $expected ) === $disk;
	}

	/**
	 * Write a file and record its hash, skipping a write that changes nothing.
	 *
	 * @param WP_Filesystem_Base $fs       Filesystem instance.
	 * @param string             $path     Absolute path.
	 * @param string             $contents File contents.
	 * @param string             $hash_key Settings key to record the hash under.
	 * @return bool
	 */
	private function put( $fs, $path, $contents, $hash_key ) {
		$hash = sha1( $contents );

		if ( file_exists( $path ) && sha1_file( $path ) === $hash ) {
			if ( SPFW_Settings::value( 'hardening', $hash_key, '' ) !== $hash ) {
				SPFW_Settings::update( array( 'hardening' => array( $hash_key => $hash ) ) );
			}

			return true;
		}

		$dir = dirname( $path );

		if ( ! $fs->is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! $fs->put_contents( $path, $contents, 0644 ) ) {
			return false;
		}

		SPFW_Settings::update( array( 'hardening' => array( $hash_key => $hash ) ) );

		return true;
	}

	/**
	 * Delete a file this plugin authored, leaving a foreign one alone.
	 *
	 * @param string $path     Absolute path.
	 * @param string $hash_key Settings key holding the authored hash.
	 * @return bool
	 */
	private function delete( $path, $hash_key ) {
		if ( ! file_exists( $path ) ) {
			SPFW_Settings::update( array( 'hardening' => array( $hash_key => '' ) ) );

			return true;
		}

		$stored = (string) SPFW_Settings::value( 'hardening', $hash_key, '' );

		if ( '' === $stored || sha1_file( $path ) !== $stored ) {
			return false;
		}

		$fs = self::filesystem();

		if ( ! $fs || ! $fs->delete( $path ) ) {
			return false;
		}

		SPFW_Settings::update( array( 'hardening' => array( $hash_key => '' ) ) );

		return true;
	}

	/**
	 * Initialize and return the WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		return $wp_filesystem;
	}
}
