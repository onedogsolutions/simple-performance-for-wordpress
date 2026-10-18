<?php
/**
 * Boot-order regression guard.
 *
 * The 2.17.0 deploy took the whole site down with
 * `Class "SPFW_Module_Hardening" not found` in class-spfw-settings.php. The
 * 2.17.0 snapshot migration in SPFW_Settings::get() reached for
 * SPFW_Module_Hardening::FILE_MONITOR_SNAPSHOT_OPTION, but get() runs during
 * SPFW_Module_Core::register() — the first module boot() registers — which is
 * BEFORE the hardening module class is required (SPFW loads classes with an
 * explicit require_once chain, no autoloader). activate() and deactivate() are
 * even stricter: they call SPFW_Settings::update()/get() while loading the
 * hardening *layer* but *zero* module classes.
 *
 * tests/bootstrap.php requires every class up front, so a normal unit test can
 * never reproduce this: SPFW_Module_Hardening is always already defined by the
 * time an assertion runs, and the fatal only appears on a real deploy. These
 * tests close that gap two ways:
 *
 *  1. A subprocess mirrors boot()'s require order (settings + hardening layer,
 *     no module class) and runs the exact get()/update() chain that fataled,
 *     asserting it both survives and still performs the migration.
 *  2. A token scan of class-spfw-settings.php asserts no *executable*
 *     reference to any SPFW_Module_* class survives in the file — comments and
 *     string literals (lazy hook callbacks) are allowed, `::`/`new`/
 *     `instanceof` are not.
 *
 * A third test is a negative control: it proves the subprocess mechanism
 * really does surface an undefined-class fatal, so a silent no-op harness can
 * never masquerade as a pass.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Load-order safety tests for SPFW_Settings.
 */
class Boot_Order_Test extends TestCase {

	/**
	 * Temp files created during a test, removed in tearDown().
	 *
	 * @var array<int,string>
	 */
	private $temp_files = array();

	/**
	 * Remove any harness / seed / probe scripts written to the temp dir.
	 */
	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				@unlink( $file );
			}
		}
		$this->temp_files = array();
		parent::tearDown();
	}

	/**
	 * The real file boots, and still migrates, when no module class exists.
	 *
	 * Reproduces SPFW_Module_Core::register() reading settings during boot()
	 * before the hardening module is required. A reintroduced
	 * `SPFW_Module_Hardening::` reference in the migration path makes the
	 * subprocess fatal and this assertion fail with the PHP error in the
	 * message.
	 */
	public function test_settings_boot_before_hardening_module_migrates_without_fatal() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is unavailable; cannot run the boot-order subprocess.' );
		}

		$seed = array(
			'version'   => '2.16.0',
			'hardening' => array(
				'file_monitor_last_scan' => 1790017228,
				// Two entries stand in for the real 14k; the migration is
				// count-agnostic, and a small seed keeps argv/env tiny.
				'file_monitor_snapshot'  => array(
					'wp-content/plugins/foo/foo.php'  => array( 'hash' => 'abc123' ),
					'wp-content/themes/bar/style.css' => array( 'hash' => 'def456' ),
				),
			),
		);

		$seed_file = $this->write_temp( 'spfw-seed-', json_encode( $seed ) );
		$harness   = $this->write_temp( 'spfw-boot-', $this->harness_source() );

		$result = $this->run_php( $harness, array( __DIR__ . '/bootstrap.php', $seed_file ) );

		$this->assertSame(
			0,
			$result['exit'],
			"Boot-order harness exited {$result['exit']}.\nSTDERR:\n{$result['stderr']}\nSTDOUT:\n{$result['stdout']}"
		);
		$this->assertStringNotContainsString( 'Fatal error', $result['stderr'], 'The boot chain fataled: ' . $result['stderr'] );
		$this->assertStringContainsString( 'BOOT_OK', $result['stdout'], 'Harness did not reach its success marker.' );

		$payload = $this->decode_after_marker( $result['stdout'], 'BOOT_OK' );
		$this->assertIsArray( $payload, 'Could not decode the harness payload: ' . $result['stdout'] );
		$this->assertFalse( $payload['hardening_loaded'], 'Harness was invalid: a module class was already loaded.' );
		$this->assertSame( 2, $payload['migrated_entries'], 'The 2.17.0 migration did not copy the snapshot into its dedicated option.' );
		$this->assertSame( 1790017228, $payload['migrated_time'], 'The migration dropped the last-scan timestamp.' );
	}

	/**
	 * No executable SPFW_Module_* reference may live in class-spfw-settings.php.
	 *
	 * SPFW_Settings is used from activate()/deactivate(), which load no module
	 * class at all, so any `SPFW_Module_X::` / `new SPFW_Module_X` /
	 * `instanceof SPFW_Module_X` here is a latent deploy-time fatal. Comments
	 * (T_COMMENT / T_DOC_COMMENT) and string literals
	 * (T_CONSTANT_ENCAPSED_STRING, e.g. a lazy hook callback) are fine and are
	 * not T_STRING class-name tokens, so they never trip this scan.
	 */
	public function test_settings_file_has_no_executable_module_class_reference() {
		$file = dirname( __DIR__ ) . '/includes/class-spfw-settings.php';
		$this->assertFileExists( $file );

		$tokens   = token_get_all( file_get_contents( $file ) );
		$offenders = array();
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] ) {
				continue;
			}
			if ( 1 !== preg_match( '/^SPFW_Module_/', $tokens[ $i ][1] ) ) {
				continue;
			}

			// A bare T_STRING starting with SPFW_Module_ is always a class
			// reference in code. Record the line and the following operator so
			// a failure points straight at the offending usage.
			$next = $this->next_meaningful_token( $tokens, $i );
			$line = $tokens[ $i ][2];
			$kind = ( is_array( $next ) ? token_name( $next[0] ) : ( is_string( $next ) ? $next : '?' ) );
			$offenders[] = "line {$line}: {$tokens[ $i ][1]} (next token: {$kind})";
		}

		$this->assertSame(
			array(),
			$offenders,
			"class-spfw-settings.php references a module class in executable code. SPFW_Settings runs\n"
			. 'during boot() (before the hardening module loads) and during activate()/deactivate()\n'
			. "(which load no module at all), so this fatals the site on deploy. Use the literal option\n"
			. 'name and core functions instead. Offending reference(s):' . "\n  - "
			. implode( "\n  - ", $offenders )
		);
	}

	/**
	 * Negative control: the subprocess mechanism must surface a class fatal.
	 *
	 * Without this, a harness that silently never exercised the bad path would
	 * still "pass". A throwaway script that touches an undefined class must come
	 * back non-zero with the fatal on stderr and its success marker unprinted.
	 */
	public function test_subprocess_harness_detects_undefined_class_fatal() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open() is unavailable; cannot run the negative-control subprocess.' );
		}

		$probe = $this->write_temp(
			'spfw-probe-',
			"<?php\n"
			. "class SPFW_Boot_Order_Probe {\n"
			. "\tpublic static function go() { return SPFW_Definitely_Undefined_Class::SOME_CONST; }\n"
			. "}\n"
			. "SPFW_Boot_Order_Probe::go();\n"
			. "echo \"PROBE_SHOULD_NOT_REACH\\n\";\n"
			. "exit( 0 );\n"
		);

		$result = $this->run_php( $probe, array() );

		$this->assertNotSame( 0, $result['exit'], 'An undefined-class reference should fatal the subprocess, but it exited 0.' );
		$this->assertStringNotContainsString( 'PROBE_SHOULD_NOT_REACH', $result['stdout'], 'Execution continued past an undefined-class fatal.' );
		$this->assertMatchesRegularExpression( '/not found|Fatal error/i', $result['stderr'], 'Expected the class-not-found fatal on stderr, got: ' . $result['stderr'] );
	}

	/**
	 * The subprocess body: inherits the WP stubs from bootstrap (but loads no
	 * SPFW class), mirrors boot()'s require order up to the module loop, then
	 * runs the get()/update() chain that fataled on the 2.17.0 deploy.
	 *
	 * argv[1] = absolute path to tests/bootstrap.php
	 * argv[2] = absolute path to the JSON seed for the spfw_settings option
	 *
	 * @return string PHP source (no opening tag interpolation surprises).
	 */
	private function harness_source() {
		return <<<'PHP'
<?php
// Inherit the stub environment but NOT the up-front class loading, so this
// process starts exactly like a real request: no SPFW class defined yet.
define( 'SPFW_TEST_NO_AUTOLOAD_CLASSES', true );
require $argv[1];

global $spfw_test_options;
$seed = json_decode( file_get_contents( $argv[2] ), true );
if ( ! is_array( $seed ) ) {
	fwrite( STDERR, "HARNESS_BAD_SEED\n" );
	exit( 3 );
}
$spfw_test_options['spfw_settings'] = $seed;

// Mirror boot() right up to (but not including) the module loop: settings,
// the module interface, the hardening *layer*, and the REST controller are
// loaded; NO module class is. This is the exact class-availability state when
// SPFW_Module_Core::register() calls SPFW_Settings::get().
require_once SPFW_PATH . 'includes/class-spfw-settings.php';
require_once SPFW_PATH . 'includes/interface-spfw-module.php';
require_once SPFW_PATH . 'includes/class-spfw-server.php';
require_once SPFW_PATH . 'includes/class-spfw-htaccess.php';
require_once SPFW_PATH . 'includes/interface-spfw-hardening-strategy.php';
require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-htaccess.php';
require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-user-ini.php';
require_once SPFW_PATH . 'includes/strategies/class-spfw-strategy-snippet.php';
require_once SPFW_PATH . 'includes/class-spfw-hardening-strategies.php';
require_once SPFW_PATH . 'includes/class-spfw-rest-settings.php';

// Prove the harness really is module-less, or its assertions mean nothing.
if ( class_exists( 'SPFW_Module_Hardening', false ) || class_exists( 'SPFW_Module_Core', false ) ) {
	fwrite( STDERR, "HARNESS_INVALID_MODULE_LOADED\n" );
	exit( 2 );
}

// The chain that fataled on deploy. If class-spfw-settings.php references a
// module class here, PHP throws Class "..." not found and this process dies
// non-zero before reaching the marker.
$settings = SPFW_Settings::get();
SPFW_Settings::update( array() );

$migrated = get_option( 'spfw_file_monitor_snapshot', null );
echo 'BOOT_OK ' . json_encode( array(
	'hardening_loaded' => class_exists( 'SPFW_Module_Hardening', false ),
	'settings_is_array' => is_array( $settings ),
	'migrated_entries'  => ( is_array( $migrated ) && isset( $migrated['entries'] ) ) ? count( $migrated['entries'] ) : 0,
	'migrated_time'     => ( is_array( $migrated ) && isset( $migrated['time'] ) ) ? (int) $migrated['time'] : 0,
) ) . "\n";
exit( 0 );
PHP;
	}

	/**
	 * Write $contents to a uniquely-named temp file tracked for cleanup.
	 *
	 * @param string $prefix   tempnam() prefix.
	 * @param string $contents File body.
	 * @return string Absolute path.
	 */
	private function write_temp( $prefix, $contents ) {
		$path = tempnam( sys_get_temp_dir(), $prefix );
		$this->temp_files[] = $path;
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Run a PHP script in a clean subprocess via the same binary as this suite.
	 *
	 * proc_open() with an array command avoids shell quoting entirely, so the
	 * seed path and bootstrap path pass through verbatim on any platform.
	 *
	 * @param string             $script Absolute path to the PHP file.
	 * @param array<int,string>  $args   Extra argv entries.
	 * @return array{exit:int,stdout:string,stderr:string}
	 */
	private function run_php( $script, array $args ) {
		$cmd = array_merge( array( PHP_BINARY, $script ), $args );

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$proc = proc_open( $cmd, $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array(
				'exit'   => -1,
				'stdout' => '',
				'stderr' => 'proc_open() could not start the subprocess',
			);
		}

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit = proc_close( $proc );

		return array(
			'exit'   => $exit,
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		);
	}

	/**
	 * Decode the JSON object printed immediately after a stdout marker.
	 *
	 * @param string $stdout Raw subprocess stdout.
	 * @param string $marker Marker preceding the JSON (e.g. 'BOOT_OK').
	 * @return array|null Decoded payload, or null when absent/invalid.
	 */
	private function decode_after_marker( $stdout, $marker ) {
		$pos = strpos( $stdout, $marker );
		if ( false === $pos ) {
			return null;
		}
		$json = trim( substr( $stdout, $pos + strlen( $marker ) ) );
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The next non-whitespace, non-comment token after index $i.
	 *
	 * @param array<int,mixed> $tokens token_get_all() output.
	 * @param int              $i      Current index.
	 * @return mixed A token array, a literal string operator, or null at EOF.
	 */
	private function next_meaningful_token( array $tokens, $i ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $tokens[ $j ];
		}
		return null;
	}
}
