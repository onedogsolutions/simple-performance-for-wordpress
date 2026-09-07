<?php
/**
 * Tests for the upgrade-compatibility probe: the pure result-shaping helper
 * that decides what the admin is told, and the REST routes that expose it.
 *
 * The probe exists because plugin install/update failures ("Could not move the
 * old version to the upgrade-temp-backup directory", "A directory could not be
 * read") were being attributed to the directory-hardening .htaccess rules,
 * which cannot cause them. The verdict logic is therefore the part worth
 * pinning down: a false "all clear" sends the admin back to blaming the wrong
 * thing, and a false failure sends them chasing a healthy filesystem.
 *
 * @package Simple_Performance_For_WordPress
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests for the upgrade-compatibility probe's verdict shaping and REST routes.
 */
class Upgrade_Compat_Check_Test extends TestCase {

	/**
	 * Reset the recorded route registrations and capabilities between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $spfw_test_rest_routes, $spfw_test_capabilities;
		$spfw_test_rest_routes  = array();
		$spfw_test_capabilities = array( 'manage_options' => true );
	}

	/**
	 * A raw probe row for a directory that did everything it was asked to.
	 *
	 * @return array
	 */
	private function healthy_row() {
		return array(
			'path'     => '/srv/www/wp-content/upgrade',
			'exists'   => true,
			'writable' => true,
			'movable'  => true,
			'readable' => true,
			'owner'    => 'ott_dev',
			'php_user' => 'ott_dev',
			'error'    => '',
			'stale'    => 0,
		);
	}

	/**
	 * A raw payload in which every probed directory is healthy.
	 *
	 * @param array $overrides Top-level overrides applied to the payload.
	 * @return array
	 */
	private function healthy_payload( array $overrides = array() ) {
		return array_merge(
			array(
				'directories'   => array(
					'upgrade'     => $this->healthy_row(),
					'temp_backup' => $this->healthy_row(),
					'plugins'     => $this->healthy_row(),
				),
				'upgrader_move' => array(
					'ok'    => true,
					'error' => '',
				),
				'fs_ready'      => true,
				'fs_method'     => 'direct',
				'php_user'      => 'ott_dev',
				'checked'       => 1700000000,
			),
			$overrides
		);
	}

	/**
	 * A fully healthy run passes, reports every probed directory in the
	 * documented order, and carries each directory's label and path through
	 * for the admin UI.
	 */
	public function test_shape_passes_when_every_directory_is_healthy() {
		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result( $this->healthy_payload() );

		$this->assertTrue( $shaped['pass'] );
		$this->assertTrue( $shaped['fs_ready'] );
		$this->assertSame( 'direct', $shaped['fs_method'] );
		$this->assertSame( 'ott_dev', $shaped['php_user'] );
		$this->assertSame( 1700000000, $shaped['checked'] );
		$this->assertSame( 0, $shaped['stale_total'] );
		$this->assertTrue( $shaped['upgrader_move']['ok'] );

		$this->assertSame(
			array( 'upgrade', 'temp_backup', 'plugins' ),
			array_column( $shaped['directories'], 'key' )
		);

		$this->assertSame(
			array_keys( SPFW_Module_Hardening::UPGRADE_DIRS ),
			array_column( $shaped['directories'], 'key' )
		);

		$this->assertSame(
			array_values( SPFW_Module_Hardening::UPGRADE_DIRS ),
			array_column( $shaped['directories'], 'label' )
		);

		foreach ( $shaped['directories'] as $dir ) {
			$this->assertTrue( $dir['ok'], $dir['key'] . ' should pass' );
			$this->assertSame( '', $dir['error'] );
			$this->assertSame( '/srv/www/wp-content/upgrade', $dir['path'] );
		}
	}

	/**
	 * The plugins → upgrade-temp-backup move is the operation behind the
	 * reported error, and it spans two parents. A directory-level pass must
	 * not mask its failure, or the admin gets an "all clear" on the exact
	 * thing that is broken.
	 */
	public function test_shape_fails_when_the_upgrader_move_fails_despite_healthy_directories() {
		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result(
			$this->healthy_payload(
				array(
					'upgrader_move' => array(
						'ok'    => false,
						'error' => 'A plugin directory could not be moved.',
					),
				)
			)
		);

		$this->assertFalse( $shaped['pass'] );
		$this->assertFalse( $shaped['upgrader_move']['ok'] );
		$this->assertSame( 'A plugin directory could not be moved.', $shaped['upgrader_move']['error'] );

		// Every directory still passes on its own — the failure is the pair.
		foreach ( $shaped['directories'] as $dir ) {
			$this->assertTrue( $dir['ok'] );
		}
	}

	/**
	 * Any single directory failing the check fails the whole run, and the
	 * failing directory keeps its error text and its false flags.
	 */
	public function test_shape_fails_when_one_directory_cannot_be_moved() {
		$payload = $this->healthy_payload();

		$payload['directories']['temp_backup'] = array(
			'path'     => '/srv/www/wp-content/upgrade-temp-backup',
			'exists'   => true,
			'writable' => true,
			'movable'  => false,
			'readable' => true,
			'owner'    => 'root',
			'php_user' => 'ott_dev',
			'error'    => 'A directory could not be moved out of this location.',
			'stale'    => 0,
		);

		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result( $payload );

		$this->assertFalse( $shaped['pass'] );

		$by_key = array();
		foreach ( $shaped['directories'] as $dir ) {
			$by_key[ $dir['key'] ] = $dir;
		}

		$this->assertFalse( $by_key['temp_backup']['ok'] );
		$this->assertFalse( $by_key['temp_backup']['movable'] );
		$this->assertSame( 'root', $by_key['temp_backup']['owner'] );
		$this->assertSame( 'A directory could not be moved out of this location.', $by_key['temp_backup']['error'] );
		$this->assertTrue( $by_key['upgrade']['ok'] );
		$this->assertTrue( $by_key['plugins']['ok'] );
	}

	/**
	 * Leftover debris is the actual cause found on the affected install, so it
	 * must be totaled and surfaced — but it is a warning, not a failed check:
	 * a directory holding orphaned backups is still writable, movable, and
	 * readable. Conflating the two would make the report unreadable.
	 */
	public function test_shape_totals_stale_leftovers_without_failing_the_check() {
		$payload = $this->healthy_payload();

		$payload['directories']['temp_backup']['stale'] = 5;
		$payload['directories']['upgrade']['stale']     = 2;

		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result( $payload );

		$this->assertTrue( $shaped['pass'] );
		$this->assertSame( 7, $shaped['stale_total'] );

		$by_key = array();
		foreach ( $shaped['directories'] as $dir ) {
			$by_key[ $dir['key'] ] = $dir;
		}

		$this->assertSame( 5, $by_key['temp_backup']['stale'] );
		$this->assertSame( 2, $by_key['upgrade']['stale'] );
		$this->assertSame( 0, $by_key['plugins']['stale'] );
	}

	/**
	 * Without the filesystem abstraction the upgrader cannot run at all, so
	 * the report must fail even though nothing was probed successfully.
	 */
	public function test_shape_fails_when_the_filesystem_abstraction_is_unavailable() {
		$payload = $this->healthy_payload(
			array(
				'fs_ready'  => false,
				'fs_method' => '',
			)
		);

		foreach ( $payload['directories'] as $key => $row ) {
			$payload['directories'][ $key ]['movable']  = false;
			$payload['directories'][ $key ]['readable'] = false;
			$payload['directories'][ $key ]['error']    = 'WP_Filesystem unavailable.';
		}

		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result( $payload );

		$this->assertFalse( $shaped['pass'] );
		$this->assertFalse( $shaped['fs_ready'] );
		$this->assertSame( '', $shaped['fs_method'] );

		foreach ( $shaped['directories'] as $dir ) {
			$this->assertFalse( $dir['ok'] );
		}
	}

	/**
	 * A directory missing entirely from the raw payload still produces an
	 * entry (failing, with the label the UI needs) rather than silently
	 * dropping out of the report.
	 */
	public function test_shape_emits_a_failing_entry_for_a_missing_directory() {
		$payload = $this->healthy_payload();
		unset( $payload['directories']['plugins'] );

		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result( $payload );

		$this->assertFalse( $shaped['pass'] );
		$this->assertCount( 3, $shaped['directories'] );

		$plugins = null;
		foreach ( $shaped['directories'] as $dir ) {
			if ( 'plugins' === $dir['key'] ) {
				$plugins = $dir;
			}
		}

		$this->assertIsArray( $plugins );
		$this->assertFalse( $plugins['ok'] );
		$this->assertFalse( $plugins['exists'] );
		$this->assertSame( 'wp-content/plugins', $plugins['label'] );
		$this->assertSame( '', $plugins['path'] );
	}

	/**
	 * The raw probe rows come from loosely-typed filesystem calls, so the
	 * shaper must coerce every field to the strict type the UI and the
	 * `pass` verdict rely on — a truthy int must not survive as an int.
	 */
	public function test_shape_coerces_loose_values_to_strict_types() {
		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result(
			array(
				'directories'   => array(
					'upgrade'     => array(
						'path'     => 12345,
						'exists'   => 1,
						'writable' => 'yes',
						'movable'  => 1,
						'readable' => 1,
						'owner'    => 1000,
						'php_user' => 1000,
						'error'    => null,
						'stale'    => '4',
					),
					'temp_backup' => $this->healthy_row(),
					'plugins'     => $this->healthy_row(),
				),
				'upgrader_move' => array( 'ok' => 1 ),
				'fs_ready'      => 1,
				'fs_method'     => 'direct',
				'php_user'      => 1000,
				'checked'       => '1700000000',
			)
		);

		$upgrade = $shaped['directories'][0];

		$this->assertSame( '12345', $upgrade['path'] );
		$this->assertTrue( $upgrade['exists'] );
		$this->assertTrue( $upgrade['writable'] );
		$this->assertTrue( $upgrade['movable'] );
		$this->assertTrue( $upgrade['readable'] );
		$this->assertSame( '1000', $upgrade['owner'] );
		$this->assertSame( '', $upgrade['error'] );
		$this->assertSame( 4, $upgrade['stale'] );
		$this->assertTrue( $upgrade['ok'] );

		$this->assertSame( 4, $shaped['stale_total'] );
		$this->assertSame( 1700000000, $shaped['checked'] );
		$this->assertSame( '1000', $shaped['php_user'] );
		$this->assertTrue( $shaped['upgrader_move']['ok'] );
		$this->assertSame( '', $shaped['upgrader_move']['error'] );
	}

	/**
	 * A negative leftover count is meaningless and must clamp to zero rather
	 * than drag the total down.
	 */
	public function test_shape_clamps_negative_leftover_counts() {
		$payload = $this->healthy_payload();

		$payload['directories']['upgrade']['stale']     = -3;
		$payload['directories']['temp_backup']['stale'] = 2;

		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result( $payload );

		$this->assertSame( 0, $shaped['directories'][0]['stale'] );
		$this->assertSame( 2, $shaped['stale_total'] );
	}

	/**
	 * An empty payload is a valid call (the probe may have bailed early) and
	 * must still produce the full shape, failing rather than erroring.
	 */
	public function test_shape_handles_an_empty_payload() {
		$shaped = SPFW_Module_Hardening::shape_upgrade_check_result( array() );

		$this->assertFalse( $shaped['pass'] );
		$this->assertFalse( $shaped['fs_ready'] );
		$this->assertSame( '', $shaped['fs_method'] );
		$this->assertSame( 0, $shaped['stale_total'] );
		$this->assertSame( 0, $shaped['checked'] );
		$this->assertFalse( $shaped['upgrader_move']['ok'] );
		$this->assertCount( 3, $shaped['directories'] );

		foreach ( $shaped['directories'] as $dir ) {
			$this->assertFalse( $dir['ok'] );
			$this->assertArrayHasKey( 'label', $dir );
			$this->assertArrayHasKey( 'error', $dir );
		}
	}

	/**
	 * Both new routes must be registered as POST under the plugin namespace
	 * and gated by the same manage_options capability as every other
	 * settings route — the probe touches the filesystem and the cleanup route
	 * deletes from it.
	 */
	public function test_upgrade_routes_are_registered_as_post_with_permission_callback() {
		$controller = new SPFW_Rest_Settings();
		$controller->register_routes();

		global $spfw_test_rest_routes;

		foreach ( array( 'upgrade-check', 'upgrade-cleanup' ) as $route ) {
			$key = 'spfw/v1/settings/' . $route;

			$this->assertArrayHasKey( $key, $spfw_test_rest_routes, $key . ' was not registered' );

			$args = $spfw_test_rest_routes[ $key ];

			$this->assertSame( WP_REST_Server::CREATABLE, $args['methods'] );
			$this->assertSame( array( $controller, 'check_permissions' ), $args['permission_callback'] );
		}
	}

	/**
	 * The permission callback must mirror current_user_can( 'manage_options' )
	 * in both directions, so the probe and cleanup are unreachable to editors
	 * and to logged-out requests.
	 */
	public function test_permission_callback_follows_manage_options() {
		global $spfw_test_capabilities;

		$controller = new SPFW_Rest_Settings();

		$spfw_test_capabilities = array( 'manage_options' => true );
		$this->assertTrue( $controller->check_permissions() );

		$spfw_test_capabilities = array( 'manage_options' => false );
		$this->assertFalse( $controller->check_permissions() );

		$spfw_test_capabilities = array();
		$this->assertFalse( $controller->check_permissions() );
	}
}
