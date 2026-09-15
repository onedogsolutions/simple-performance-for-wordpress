<?php
/**
 * Tests for the server-abstraction layer: which web server is detected, what
 * per-directory mechanisms it honors, which strategy ends up owning each
 * hardening target, and the two staleness clocks the admin UI reports.
 *
 * The point of the whole layer is that the plugin should stop assuming a server
 * that reads `.htaccess`. So most of what is asserted here is an absence: that
 * nothing is written on nginx, that a status reads 'unsupported' rather than
 * 'missing', that a snippet appears instead of a green badge. A false claim of
 * protection is the defect this exists to prevent, and it is the one a test can
 * actually catch — the real nginx behavior cannot be exercised in this build
 * environment at all.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Server detection, strategy resolution, staleness clocks, and file removal.
 */
class Server_Strategy_Test extends TestCase {

	/**
	 * The SERVER_SOFTWARE the bootstrap sets, restored after every test.
	 *
	 * @var string
	 */
	private $original_software;

	/**
	 * Reset the option store, the detection cache and the filter overrides.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_options, $spfw_test_filter_overrides, $spfw_test_home_url, $spfw_test_hooks;
		$spfw_test_options          = array();
		$spfw_test_filter_overrides = array();
		$spfw_test_hooks            = array();
		$spfw_test_home_url         = 'http://example.com';

		$this->original_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '';

		$this->reset_settings_cache();
		SPFW_Server::reset_detection();
		SPFW_Hardening_Strategies::reset();

		wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
		wp_mkdir_p( WP_CONTENT_DIR . '/plugins' );
		$this->cleanup_files();
	}

	/**
	 * Put the environment back so the rest of the suite still describes Apache.
	 */
	protected function tearDown(): void {
		$_SERVER['SERVER_SOFTWARE'] = $this->original_software;

		global $spfw_test_filter_overrides;
		$spfw_test_filter_overrides = array();

		SPFW_Server::reset_detection();
		SPFW_Hardening_Strategies::reset();
		$this->cleanup_files();

		parent::tearDown();
	}

	/**
	 * Remove every file the strategies can write.
	 */
	private function cleanup_files() {
		$paths = array(
			SPFW_Htaccess::path( 'plugins' ),
			SPFW_Htaccess::path( 'uploads' ),
			SPFW_Htaccess::path( 'root' ),
			SPFW_Strategy_User_Ini::guard_path(),
			SPFW_Strategy_User_Ini::canary_path(),
			WP_CONTENT_DIR . '/uploads/.user.ini',
		);

		foreach ( $paths as $path ) {
			if ( '' !== $path && file_exists( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * Clear SPFW_Settings' private static cache.
	 */
	private function reset_settings_cache() {
		$ref = new ReflectionProperty( 'SPFW_Settings', 'cache' );
		$ref->setValue( null, null );
	}

	/**
	 * Seed the hardening group. Version pinned above every migration gate for
	 * the same reason Htaccess_Enforcement_Test pins it.
	 *
	 * @param array $hardening Hardening group to store.
	 */
	private function set_hardening( array $hardening ) {
		global $spfw_test_options;

		$spfw_test_options['spfw_settings'] = array(
			'version'   => '99.0.0',
			'hardening' => $hardening,
		);

		$this->reset_settings_cache();
	}

	/**
	 * Point the whole layer at a given server.
	 *
	 * @param string $software SERVER_SOFTWARE value.
	 * @param bool   $user_ini Whether `.user.ini` is read (the CLI SAPI running
	 *                         these tests never reads one, so it is filtered).
	 */
	private function pretend_server( $software, $user_ini = false ) {
		global $spfw_test_filter_overrides;

		$_SERVER['SERVER_SOFTWARE']                        = $software;
		$spfw_test_filter_overrides['spfw_supports_user_ini'] = $user_ini;

		SPFW_Server::reset_detection();
		SPFW_Hardening_Strategies::reset();
	}

	// ---------------------------------------------------------------------
	// SPFW_Server::detect()
	// ---------------------------------------------------------------------

	/**
	 * Each server string maps to its own kind. OpenLiteSpeed is only claimed
	 * when the server says "openlitespeed" outright, because both LiteSpeed
	 * editions commonly advertise themselves as plain "LiteSpeed" and guessing
	 * which one would be worse than the honest shared label.
	 */
	public function test_detect_maps_server_software_to_a_kind() {
		$cases = array(
			'Apache/2.4.58 (Unix)'       => SPFW_Server::APACHE,
			'nginx/1.24.0'               => SPFW_Server::NGINX,
			'LiteSpeed'                  => SPFW_Server::LITESPEED,
			'openlitespeed/1.7.19'       => SPFW_Server::OPENLITESPEED,
			'Microsoft-IIS/10.0'         => SPFW_Server::IIS,
			'SomeExoticProxy/3'          => SPFW_Server::UNKNOWN,
		);

		foreach ( $cases as $software => $expected ) {
			$this->pretend_server( $software );

			$this->assertSame( $expected, SPFW_Server::detect(), $software );
		}
	}

	/**
	 * Only the Apache and LiteSpeed families read per-directory config files.
	 */
	public function test_supports_htaccess_only_for_the_htaccess_families() {
		$yes = array( 'Apache/2.4.58', 'LiteSpeed', 'openlitespeed/1.7.19' );
		$no  = array( 'nginx/1.24.0', 'Microsoft-IIS/10.0', 'SomeExoticProxy/3' );

		foreach ( $yes as $software ) {
			$this->pretend_server( $software );
			$this->assertTrue( SPFW_Server::supports_htaccess(), $software );
		}

		foreach ( $no as $software ) {
			$this->pretend_server( $software );
			$this->assertFalse( SPFW_Server::supports_htaccess(), $software );
		}
	}

	/**
	 * The config refresh clock is per-server and is not the cache clock.
	 */
	public function test_config_refresh_is_per_server() {
		$cases = array(
			'Apache/2.4.58'        => 'immediate',
			'LiteSpeed'            => 'immediate',
			'openlitespeed/1.7.19' => 'restart',
			'nginx/1.24.0'         => 'reload',
			'SomeExoticProxy/3'    => 'unknown',
		);

		foreach ( $cases as $software => $expected ) {
			$this->pretend_server( $software );

			$this->assertSame( $expected, SPFW_Server::config_refresh(), $software );
		}
	}

	/**
	 * The TTL and filename are read from the live PHP configuration, not
	 * assumed. A host that blanked `user_ini.filename` has switched the
	 * mechanism off, and a file written under that name would be inert.
	 */
	public function test_user_ini_capability_is_read_from_live_php_config() {
		$this->assertSame( ini_get( 'user_ini.filename' ), SPFW_Server::user_ini_filename() );
		$this->assertSame( (int) ini_get( 'user_ini.cache_ttl' ), SPFW_Server::user_ini_cache_ttl() );

		// The CLI SAPI running this suite is not in the allow-list, so the
		// unfiltered answer must be no regardless of the ini values above.
		$this->pretend_server( 'nginx/1.24.0' );
		global $spfw_test_filter_overrides;
		unset( $spfw_test_filter_overrides['spfw_supports_user_ini'] );

		$this->assertFalse( SPFW_Server::supports_user_ini() );
	}

	// ---------------------------------------------------------------------
	// SPFW_Htaccess gating
	// ---------------------------------------------------------------------

	/**
	 * The defect this step exists to fix: an nginx install was getting an
	 * .htaccess written into it, and a UI implying the directory was protected,
	 * for a file nothing on that server ever reads.
	 */
	public function test_write_is_refused_on_a_server_that_reads_no_htaccess() {
		$this->pretend_server( 'nginx/1.24.0' );
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$this->assertFalse( SPFW_Htaccess::write( 'plugins' ) );
		$this->assertFileDoesNotExist( SPFW_Htaccess::path( 'plugins' ) );
	}

	/**
	 * And still writes on a server that does read one, so the gate is about the
	 * server rather than a blanket refusal.
	 */
	public function test_write_still_happens_on_apache() {
		$this->pretend_server( 'Apache/2.4.58' );
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$this->assertTrue( SPFW_Htaccess::write( 'plugins' ) );
		$this->assertFileExists( SPFW_Htaccess::path( 'plugins' ) );
	}

	/**
	 * An enabled toggle on nginx reports 'unsupported', not 'missing'. The
	 * distinction is the whole point: 'missing' is a fault with a Restore
	 * button, and offering one for a file that was never going to exist sends
	 * the admin after a problem that is not there.
	 */
	public function test_status_is_unsupported_rather_than_missing_on_nginx() {
		$this->pretend_server( 'nginx/1.24.0' );
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$this->assertSame( 'unsupported', SPFW_Htaccess::status( 'plugins' ) );
	}

	/**
	 * A disabled toggle still reads 'disabled' everywhere, so the new state
	 * never masks the ordinary one.
	 */
	public function test_status_is_disabled_when_the_toggle_is_off() {
		$this->pretend_server( 'nginx/1.24.0' );
		$this->set_hardening( array( 'plugins_htaccess' => false ) );

		$this->assertSame( 'disabled', SPFW_Htaccess::status( 'plugins' ) );
	}

	// ---------------------------------------------------------------------
	// Strategy resolution
	// ---------------------------------------------------------------------

	/**
	 * Apache and both LiteSpeed editions keep the .htaccess writer for every
	 * target. This is the acceptance criterion for the OpenLiteSpeed decision:
	 * a `.user.ini` TTL is operationally nicer than a graceful restart, but
	 * swapping the mechanism on the one server with field evidence behind it,
	 * for convenience rather than coverage, is a trade in the wrong direction.
	 */
	public function test_htaccess_servers_keep_the_htaccess_strategy_everywhere() {
		foreach ( array( 'Apache/2.4.58', 'LiteSpeed', 'openlitespeed/1.7.19' ) as $software ) {
			$this->pretend_server( $software, true );

			foreach ( SPFW_Hardening_Strategies::TARGETS as $target ) {
				$this->assertSame(
					'htaccess',
					SPFW_Hardening_Strategies::for_target( $target )->key(),
					$software . ' / ' . $target
				);
			}
		}
	}

	/**
	 * On nginx with a FastCGI stack, uploads goes to the `.user.ini` guard and
	 * everything else falls through to the snippet. Uploads is the only target
	 * the guard takes, because it is the only one with no legitimate PHP entry
	 * point: a broken prepend there refuses requests that were to be refused
	 * anyway, whereas the same mistake in plugins/ would take the front end
	 * down with it.
	 */
	public function test_nginx_with_fastcgi_uses_user_ini_for_uploads_only() {
		$this->pretend_server( 'nginx/1.24.0', true );

		$this->assertSame( 'user_ini', SPFW_Hardening_Strategies::for_target( 'uploads' )->key() );
		$this->assertSame( 'snippet', SPFW_Hardening_Strategies::for_target( 'plugins' )->key() );
		$this->assertSame( 'snippet', SPFW_Hardening_Strategies::for_target( 'root' )->key() );
	}

	/**
	 * With `.user.ini` unavailable there is nothing left that writes anything,
	 * so every target is advisory.
	 */
	public function test_nginx_without_user_ini_falls_through_to_the_snippet() {
		$this->pretend_server( 'nginx/1.24.0', false );

		foreach ( SPFW_Hardening_Strategies::TARGETS as $target ) {
			$this->assertSame(
				'snippet',
				SPFW_Hardening_Strategies::for_target( $target )->key(),
				$target
			);
		}
	}

	/**
	 * An enabled target carried only by the snippet reports 'advisory' — a rule
	 * that exists on paper and nowhere else. It must never render as
	 * protection, which is why it is its own state rather than 'ok'.
	 */
	public function test_snippet_strategy_reports_advisory_not_ok() {
		$this->pretend_server( 'nginx/1.24.0', false );
		$this->set_hardening( array( 'plugins_htaccess' => true ) );

		$this->assertSame( 'advisory', SPFW_Hardening_Strategies::status( 'plugins' ) );
	}

	// ---------------------------------------------------------------------
	// The generated snippet
	// ---------------------------------------------------------------------

	/**
	 * The nginx snippet covers the enabled toggles and nothing else, and says
	 * where it has to go: regex locations are matched in source order, so a
	 * deny rule pasted after the fastcgi_pass block never runs.
	 */
	public function test_nginx_snippet_covers_enabled_toggles_only() {
		$this->pretend_server( 'nginx/1.24.0', false );
		$this->set_hardening(
			array(
				'uploads_htaccess'        => true,
				'protect_sensitive_files' => true,
			)
		);

		$snippet = SPFW_Strategy_Snippet::nginx();

		$this->assertStringContainsString( 'wp-content/uploads/', $snippet );
		$this->assertStringContainsString( 'readme\.html', $snippet );
		$this->assertStringContainsString( 'deny all;', $snippet );
		$this->assertStringContainsString( 'BEFORE the location', $snippet );
		$this->assertStringNotContainsString( 'wp-content/plugins/', $snippet );
		$this->assertStringNotContainsString( 'xmlrpc.php', $snippet );
	}

	/**
	 * Open question (c): a `.user.ini` is plain text under the document root
	 * and nginx will serve it to anyone who asks. It carries no secret, but it
	 * names the guard's exact path, so the snippet denies it.
	 */
	public function test_nginx_snippet_denies_the_user_ini_itself() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		$this->assertStringContainsString( '\.user\.ini$', SPFW_Strategy_Snippet::nginx() );
	}

	/**
	 * A subdirectory install gets rules for its own path, matching how
	 * SPFW_Htaccess builds the equivalent RewriteCond.
	 */
	public function test_nginx_snippet_respects_a_subdirectory_install() {
		global $spfw_test_home_url;
		$spfw_test_home_url = 'http://example.com/blog';

		$this->pretend_server( 'nginx/1.24.0', false );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		$this->assertStringContainsString( '^/blog/wp-content/uploads/', SPFW_Strategy_Snippet::nginx() );
	}

	/**
	 * No snippet is offered to a server that reads .htaccess: it has no use for
	 * nginx configuration and showing it would imply it needed one.
	 */
	public function test_no_snippet_on_an_htaccess_server() {
		$this->pretend_server( 'Apache/2.4.58' );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		$this->assertSame( '', SPFW_Hardening_Strategies::snippet()['body'] );
	}

	/**
	 * IIS gets rewrite rules rather than nginx location blocks.
	 */
	public function test_iis_gets_web_config_rules() {
		$this->pretend_server( 'Microsoft-IIS/10.0' );
		$this->set_hardening( array( 'block_xmlrpc_file' => true ) );

		$snippet = SPFW_Hardening_Strategies::snippet();

		$this->assertSame( 'xml', $snippet['format'] );
		$this->assertStringContainsString( '<rule name="spfw-xmlrpc"', $snippet['body'] );
		$this->assertStringContainsString( 'statusCode="403"', $snippet['body'] );
	}

	// ---------------------------------------------------------------------
	// The .user.ini guard
	// ---------------------------------------------------------------------

	/**
	 * The generated guard is valid PHP. This is not a formality: the file is
	 * loaded by `auto_prepend_file` on every PHP request in the tree, so one
	 * that will not compile breaks exactly the thing it was installed to
	 * protect. Generation checks this before the file reaches disk.
	 */
	public function test_generated_guard_is_syntactically_valid() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess' => true,
				'php_whitelist'    => array( 'uploads/allowed.php' ),
			)
		);

		$this->assertTrue( SPFW_Strategy_User_Ini::parses( SPFW_Strategy_User_Ini::guard_payload() ) );
	}

	/**
	 * And the parse check actually rejects something broken, so the guard above
	 * is not passing because the check is a no-op.
	 */
	public function test_parse_check_rejects_invalid_php() {
		$this->assertFalse( SPFW_Strategy_User_Ini::parses( '<?php if ( ' ) );
	}

	/**
	 * A whitelist entry means the same thing under either strategy, so the
	 * guard bakes the allowed paths in as literals — it runs before WordPress
	 * and cannot look anything up.
	 */
	public function test_guard_allows_whitelisted_uploads_paths() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess'     => true,
				'php_whitelist'        => array( 'uploads/allowed.php' ),
				'auto_allow_known_php' => false,
			)
		);

		$guard = SPFW_Strategy_User_Ini::guard_payload();

		$this->assertStringContainsString( 'uploads/allowed.php', $guard );
		$this->assertStringContainsString( 'http_response_code( 403 )', $guard );
	}

	/**
	 * A stored whitelist value that predates the sanitizer must not reach
	 * generated PHP. Interpolating one is how a hardening feature becomes the
	 * vulnerability.
	 */
	public function test_guard_drops_whitelist_paths_with_unsafe_characters() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess'     => true,
				'php_whitelist'        => array( "uploads/evil'.php", 'uploads/../../wp-config.php', 'uploads/fine.php' ),
				'auto_allow_known_php' => false,
			)
		);

		$guard = SPFW_Strategy_User_Ini::guard_payload();

		$this->assertStringNotContainsString( "evil'", $guard );
		$this->assertStringNotContainsString( '..', $guard );
		$this->assertStringContainsString( 'uploads/fine.php', $guard );
	}

	/**
	 * The `.user.ini` names the guard beside it by absolute path.
	 */
	public function test_ini_payload_points_at_the_generated_guard() {
		$this->pretend_server( 'nginx/1.24.0', true );

		$this->assertStringContainsString(
			'auto_prepend_file = "' . str_replace( '\\', '/', SPFW_Strategy_User_Ini::guard_path() ) . '"',
			SPFW_Strategy_User_Ini::ini_payload()
		);
	}

	/**
	 * The guard lives inside the directory it protects rather than in the
	 * plugin folder, so deleting or renaming the plugin cannot leave PHP
	 * pointed at a file that is no longer there.
	 */
	public function test_guard_lives_in_the_directory_it_protects() {
		$uploads = wp_upload_dir();

		$this->assertStringStartsWith(
			trailingslashit( $uploads['basedir'] ),
			SPFW_Strategy_User_Ini::guard_path()
		);
	}

	/**
	 * Open question (b), the survival half. A guard that cannot be loaded makes
	 * the whole tree return 5xx, and the probe as originally written classified
	 * any 5xx as 'unknown' — an inconclusive shrug over the one failure that
	 * needs acting on. The canary declares 'broken' codes, so that reading is
	 * available and distinct.
	 */
	public function test_five_hundred_on_the_user_ini_canary_is_broken_not_unknown() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array(
				'targets' => array(
					array(
						'target' => 'uploads_user_ini',
						'code'   => 500,
						'url'    => 'http://example.com/wp-content/uploads/spfw-user-ini-canary.php',
					),
				),
			)
		);

		$this->assertSame( 'broken', $shaped['targets'][0]['state'] );
		$this->assertTrue( $shaped['strategy_broken'] );

		// And it is not mistaken for evidence about .htaccess either way.
		$this->assertSame( 'unknown', $shaped['htaccess_honored'] );
	}

	/**
	 * The other two readings of the same canary. 403 is the guard refusing,
	 * 200 is the guard not running — which is what the absent-path canary used
	 * by the .htaccess strategy could never tell apart here, because PHP never
	 * loads a prepend for a script that does not exist.
	 */
	public function test_user_ini_canary_reads_403_as_enforced_and_200_as_inert() {
		$enforced = SPFW_Module_Hardening::shape_enforcement_result(
			array( 'targets' => array( array( 'target' => 'uploads_user_ini', 'code' => 403 ) ) )
		);
		$inert    = SPFW_Module_Hardening::shape_enforcement_result(
			array( 'targets' => array( array( 'target' => 'uploads_user_ini', 'code' => 200 ) ) )
		);

		$this->assertSame( 'enforced', $enforced['targets'][0]['state'] );
		$this->assertFalse( $enforced['strategy_broken'] );
		$this->assertSame( 'not_enforced', $inert['targets'][0]['state'] );
		$this->assertFalse( $inert['strategy_broken'] );
	}

	/**
	 * A 5xx on an ordinary .htaccess canary stays 'unknown'. Only a canary that
	 * declares broken codes opts into the stronger reading, because on a deny
	 * rule a 500 really could be anything.
	 */
	public function test_five_hundred_elsewhere_is_still_unknown() {
		$shaped = SPFW_Module_Hardening::shape_enforcement_result(
			array( 'targets' => array( array( 'target' => 'plugins', 'code' => 500 ) ) )
		);

		$this->assertSame( 'unknown', $shaped['targets'][0]['state'] );
		$this->assertFalse( $shaped['strategy_broken'] );
	}

	/**
	 * End to end: enabling the uploads toggle on an nginx/FastCGI stack writes
	 * the guard, the `.user.ini` that names it, and the probe canary — and no
	 * .htaccess, which is the file that used to appear here and do nothing.
	 */
	public function test_apply_writes_the_guard_the_ini_and_the_canary() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		$this->assertTrue( SPFW_Hardening_Strategies::apply( 'uploads' ) );

		$this->assertFileExists( SPFW_Strategy_User_Ini::guard_path() );
		$this->assertFileExists( SPFW_Strategy_User_Ini::ini_path() );
		$this->assertFileExists( SPFW_Strategy_User_Ini::canary_path() );
		$this->assertFileDoesNotExist( SPFW_Htaccess::path( 'uploads' ) );

		$this->assertSame( 'ok', SPFW_Hardening_Strategies::status( 'uploads' ) );
	}

	/**
	 * The write order matters: the guard has to exist before anything points
	 * PHP at it, or the window between the two writes is a tree of 500s.
	 */
	public function test_apply_writes_the_guard_before_the_ini_names_it() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		SPFW_Hardening_Strategies::apply( 'uploads' );

		$named = trim(
			str_replace(
				array( 'auto_prepend_file = "', '"' ),
				'',
				// The one directive line out of the generated ini.
				preg_replace( '/^(?!auto_prepend_file).*$/m', '', file_get_contents( SPFW_Strategy_User_Ini::ini_path() ) )
			)
		);

		$this->assertNotSame( '', $named );
		$this->assertFileExists( $named );
	}

	/**
	 * Turning the toggle off removes every file the strategy authored, and the
	 * `.user.ini` goes first: while it is present without the guard, PHP has a
	 * dangling prepend.
	 */
	public function test_revert_removes_everything_it_wrote() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		SPFW_Hardening_Strategies::apply( 'uploads' );
		$this->assertTrue( SPFW_Hardening_Strategies::revert( 'uploads' ) );

		$this->assertFileDoesNotExist( SPFW_Strategy_User_Ini::guard_path() );
		$this->assertFileDoesNotExist( SPFW_Strategy_User_Ini::ini_path() );
		$this->assertFileDoesNotExist( SPFW_Strategy_User_Ini::canary_path() );
	}

	/**
	 * A `.user.ini` someone else put there is left alone. Unlike an .htaccess
	 * in plugins/, this file has ordinary non-hardening uses, so it is much
	 * more likely to already exist for a reason that has nothing to do with us.
	 */
	public function test_apply_refuses_to_clobber_a_foreign_user_ini() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		$foreign = "upload_max_filesize = 64M\n";
		file_put_contents( SPFW_Strategy_User_Ini::ini_path(), $foreign ); // phpcs:ignore

		$this->assertFalse( SPFW_Hardening_Strategies::apply( 'uploads' ) );
		$this->assertSame( $foreign, file_get_contents( SPFW_Strategy_User_Ini::ini_path() ) );
	}

	/**
	 * A whitelist edit leaves an authored guard that no longer matches the
	 * settings, because the allowed paths are baked into it as literals.
	 * reconcile() is what closes that gap, exactly as it does for a rewrite
	 * payload.
	 */
	public function test_reconcile_regenerates_a_guard_that_drifted() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess'     => true,
				'auto_allow_known_php' => false,
			)
		);

		SPFW_Hardening_Strategies::apply( 'uploads' );

		global $spfw_test_options;
		$spfw_test_options['spfw_settings']['hardening']['php_whitelist'] = array( 'uploads/added.php' );
		$this->reset_settings_cache();

		$this->assertSame( array( 'uploads' ), SPFW_Hardening_Strategies::reconcile() );
		$this->assertStringContainsString(
			'uploads/added.php',
			file_get_contents( SPFW_Strategy_User_Ini::guard_path() )
		);
	}

	// ---------------------------------------------------------------------
	// The guard, actually executed
	// ---------------------------------------------------------------------

	/**
	 * Run a PHP script in a subprocess with the generated guard prepended,
	 * exactly as `auto_prepend_file` would.
	 *
	 * The guard declines to act under CLI, because a .user.ini is never read
	 * there and WP-CLI must not be refused; SPFW_GUARD_SELFTEST opts back in so
	 * the behavior can be observed rather than only argued about. Everything
	 * this strategy claims about nginx is reasoned from documentation — this is
	 * the one part that can be executed for real in CI, so it is.
	 *
	 * @param string $script Absolute path of the script to run.
	 * @return string Combined output.
	 */
	private function run_guarded( $script ) {
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open(
			array(
				PHP_BINARY,
				'-d',
				'auto_prepend_file=' . SPFW_Strategy_User_Ini::guard_path(),
				$script,
			),
			$descriptors,
			$pipes,
			null,
			array( 'SPFW_GUARD_SELFTEST' => '1' )
		);

		if ( ! is_resource( $process ) ) {
			$this->markTestSkipped( 'Could not spawn a PHP subprocess.' );
		}

		$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		return $out;
	}

	/**
	 * Write a throwaway PHP script that announces itself if it runs.
	 *
	 * @param string $path Absolute path to write.
	 * @return string The same path.
	 */
	private function write_script( $path ) {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "<?php\necho 'REACHED';\n" ); // phpcs:ignore

		return $path;
	}

	/**
	 * The guard refuses a script inside the tree it protects, and the script
	 * never runs — `exit` in the prepend ends the request before the entry
	 * point is compiled.
	 */
	public function test_guard_refuses_a_script_inside_uploads() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess'     => true,
				'auto_allow_known_php' => false,
			)
		);

		SPFW_Hardening_Strategies::apply( 'uploads' );

		$script = $this->write_script( SPFW_Strategy_User_Ini::uploads_dir() . 'planted.php' );
		$output = $this->run_guarded( $script );

		unlink( $script );

		$this->assertStringContainsString( 'Forbidden', $output );
		$this->assertStringNotContainsString( 'REACHED', $output );
	}

	/**
	 * A whitelisted path inside the tree still runs, so the whitelist means the
	 * same thing here as it does in the rewrite payload.
	 */
	public function test_guard_lets_a_whitelisted_script_through() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess'     => true,
				'php_whitelist'        => array( 'uploads/allowed.php' ),
				'auto_allow_known_php' => false,
			)
		);

		$script = $this->write_script( SPFW_Strategy_User_Ini::uploads_dir() . 'allowed.php' );

		SPFW_Hardening_Strategies::apply( 'uploads' );

		$output = $this->run_guarded( $script );

		unlink( $script );

		$this->assertStringContainsString( 'REACHED', $output );
		$this->assertStringNotContainsString( 'Forbidden', $output );
	}

	/**
	 * And a script outside the tree is untouched. PHP's own per-directory
	 * scoping should already ensure this, but the guard checks the path itself
	 * so that a globally-set auto_prepend_file cannot turn it into a
	 * site-wide deny.
	 */
	public function test_guard_ignores_a_script_outside_uploads() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening( array( 'uploads_htaccess' => true ) );

		SPFW_Hardening_Strategies::apply( 'uploads' );

		$script = $this->write_script( WP_CONTENT_DIR . '/plugins/outside.php' );
		$output = $this->run_guarded( $script );

		unlink( $script );

		$this->assertStringContainsString( 'REACHED', $output );
		$this->assertStringNotContainsString( 'Forbidden', $output );
	}

	/**
	 * The canary the probe asks for is itself refused — it exists only to give
	 * the guard something real to say no to.
	 */
	public function test_guard_refuses_its_own_probe_canary() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess'     => true,
				'auto_allow_known_php' => false,
			)
		);

		SPFW_Hardening_Strategies::apply( 'uploads' );

		$output = $this->run_guarded( SPFW_Strategy_User_Ini::canary_path() );

		$this->assertStringContainsString( 'Forbidden', $output );
		$this->assertStringNotContainsString( 'spfw-user-ini-canary', $output );
	}

	// ---------------------------------------------------------------------
	// The two staleness clocks
	// ---------------------------------------------------------------------

	/**
	 * Config staleness on OpenLiteSpeed asks for a restart; the same staleness
	 * on Apache asks for nothing but a re-verify. Same disk state, different
	 * remedy — which is why this is reported rather than hard-coded.
	 */
	public function test_config_staleness_remedy_follows_the_server() {
		$stale = array(
			'plugins_htaccess'     => true,
			'htaccess_enforcement' => array(
				'checked'        => 1700000000,
				'payload_hashes' => array( 'plugins' => 'deadbeef' ),
			),
		);

		$this->pretend_server( 'openlitespeed/1.7.19' );
		$this->set_hardening( $stale );
		$ols = SPFW_Module_Hardening::config_staleness();

		$this->pretend_server( 'Apache/2.4.58' );
		$this->set_hardening( $stale );
		$apache = SPFW_Module_Hardening::config_staleness();

		$this->assertTrue( $ols['stale'] );
		$this->assertSame( 'restart', $ols['remedy'] );
		$this->assertTrue( $apache['stale'] );
		$this->assertSame( 'verify', $apache['remedy'] );
	}

	/**
	 * The `.user.ini` clock is the interesting one: not instant, but a deadline
	 * that expires by itself rather than a privileged action someone has to
	 * perform. It is reported with the time it applies, not an instruction.
	 */
	public function test_user_ini_staleness_is_a_deadline_not_a_task() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess' => true,
				'user_ini_written' => time(),
			)
		);

		$staleness = SPFW_Module_Hardening::config_staleness();

		$this->assertTrue( $staleness['stale'] );
		$this->assertSame( 'wait', $staleness['remedy'] );
		$this->assertSame( 'ttl', $staleness['refresh'] );
		$this->assertGreaterThan( time(), $staleness['applies_at'] );
	}

	/**
	 * Once the TTL has passed the config clock stops complaining on its own.
	 */
	public function test_user_ini_staleness_clears_once_the_ttl_has_passed() {
		$this->pretend_server( 'nginx/1.24.0', true );
		$this->set_hardening(
			array(
				'uploads_htaccess' => true,
				'user_ini_written' => time() - SPFW_Server::user_ini_cache_ttl() - 60,
			)
		);

		$this->assertFalse( SPFW_Module_Hardening::config_staleness()['stale'] );
	}

	/**
	 * Cache staleness is a different clock with a different owner: nothing on
	 * disk is wrong, the pages were simply rendered earlier. It opens when an
	 * output-affecting setting changes and closes on a purge.
	 */
	public function test_cache_staleness_opens_on_output_change_and_closes_on_purge() {
		$this->set_hardening(
			array(
				'output_changed' => 1700000000,
				'cache_purged'   => 0,
			)
		);

		$this->assertTrue( SPFW_Module_Hardening::cache_staleness()['stale'] );

		$this->set_hardening(
			array(
				'output_changed' => 1700000000,
				'cache_purged'   => 1700000001,
			)
		);

		$this->assertFalse( SPFW_Module_Hardening::cache_staleness()['stale'] );
	}

	/**
	 * With no purge handler listening, the automatic purge this plugin fires
	 * reaches nothing, and the remedy passes to the admin. Saying so beats a
	 * silent no-op that leaves a cache-stale site reporting itself fresh.
	 */
	public function test_cache_staleness_reports_who_owns_the_purge() {
		$this->set_hardening( array( 'output_changed' => 1700000000 ) );

		$this->assertSame( 'purge_manual', SPFW_Module_Hardening::cache_staleness()['remedy'] );

		add_action( 'litespeed_purge_all', '__return_true' );

		$this->assertSame( 'purge', SPFW_Module_Hardening::cache_staleness()['remedy'] );
	}

	/**
	 * The two clocks are independent: a cache-stale site is not config-stale
	 * and must not be reported as one. Reading the second as the first is the
	 * false lead recorded against Step 15.
	 */
	public function test_the_two_clocks_are_independent() {
		$this->pretend_server( 'Apache/2.4.58' );
		$this->set_hardening( array( 'output_changed' => time() ) );

		$this->assertTrue( SPFW_Module_Hardening::cache_staleness()['stale'] );
		$this->assertFalse( SPFW_Module_Hardening::config_staleness()['stale'] );
	}

	// ---------------------------------------------------------------------
	// Delete instead of block
	// ---------------------------------------------------------------------

	/**
	 * Only the two files WordPress does not use are removable, and the check is
	 * an allow-list rather than a path sanitizer.
	 */
	public function test_only_the_two_core_files_are_removable() {
		$this->assertTrue( SPFW_Module_Hardening::is_removable_file( 'readme.html' ) );
		$this->assertTrue( SPFW_Module_Hardening::is_removable_file( 'license.txt' ) );
		$this->assertFalse( SPFW_Module_Hardening::is_removable_file( 'wp-config.php' ) );
		$this->assertFalse( SPFW_Module_Hardening::is_removable_file( '../wp-config.php' ) );
		$this->assertFalse( SPFW_Module_Hardening::is_removable_file( 'index.php' ) );
	}

	/**
	 * A path that resolves to a removable basename is still only ever applied
	 * to ABSPATH, so traversal cannot reach another directory.
	 */
	public function test_removable_files_are_reported_under_abspath() {
		foreach ( SPFW_Module_Hardening::removable_files() as $entry ) {
			$this->assertSame( ABSPATH . $entry['file'], $entry['path'] );
		}
	}

	/**
	 * Refusing a file outside the list reports why rather than failing silently.
	 */
	public function test_remove_file_refuses_anything_off_the_list() {
		$result = SPFW_Module_Hardening::remove_file( 'wp-config.php' );

		$this->assertFalse( $result['deleted'] );
		$this->assertSame( 'not_removable', $result['reason'] );
	}

	// ---------------------------------------------------------------------
	// Route registration
	// ---------------------------------------------------------------------

	/**
	 * The delete endpoint is a POST behind the same capability check as every
	 * other write route.
	 */
	public function test_remove_file_route_is_registered_as_post_with_permission_callback() {
		global $spfw_test_rest_routes;
		$spfw_test_rest_routes = array();

		$controller = new SPFW_Rest_Settings();
		$controller->register_routes();

		$key = 'spfw/v1/settings/remove-file';

		$this->assertArrayHasKey( $key, $spfw_test_rest_routes, $key . ' was not registered' );

		$args = $spfw_test_rest_routes[ $key ];

		$this->assertSame( WP_REST_Server::CREATABLE, $args['methods'] );
		$this->assertSame( array( $controller, 'remove_file' ), $args['callback'] );
		$this->assertSame( array( $controller, 'check_permissions' ), $args['permission_callback'] );
	}
}
