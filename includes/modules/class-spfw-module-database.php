<?php
/**
 * Module: Database cleanup and optimization.
 *
 * @package Simple_Performance_For_WordPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scans and cleans post revisions, auto-drafts, trashed content, spam
 * comments, transients, and fragmented tables. Optionally schedules
 * recurring cleanup via WP-Cron.
 */
class SPFW_Module_Database implements SPFW_Module {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'spfw_database_optimization';

	/**
	 * Rows processed per batch inside cleanup loops.
	 */
	const BATCH_SIZE = 500;

	/**
	 * Valid cleanup target keys.
	 *
	 * @var string[]
	 */
	const TARGETS = array(
		'post_revisions',
		'post_auto_drafts',
		'trashed_posts',
		'spam_comments',
		'trashed_comments',
		'expired_transients',
		'all_transients',
		'tables',
	);

	/**
	 * Attach cron hooks when a schedule is configured.
	 */
	public function register() {
		$db = SPFW_Settings::group( 'database' );

		$schedule = isset( $db['optimize_schedule'] ) ? $db['optimize_schedule'] : 'off';

		if ( 'off' !== $schedule ) {
			add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
			add_action( 'init', array( $this, 'maybe_schedule_event' ) );
			add_action( self::CRON_HOOK, array( $this, 'run_scheduled_optimization' ) );
		}

		// Clear the cron job when the schedule changes to 'off' or a
		// different cadence.
		add_filter( 'pre_update_option_spfw_settings', array( $this, 'watch_schedule_change' ), 10, 2 );
	}

	/**
	 * Register weekly and monthly cron intervals (daily is built-in).
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$db       = SPFW_Settings::group( 'database' );
		$schedule = isset( $db['optimize_schedule'] ) ? $db['optimize_schedule'] : 'off';

		if ( 'weekly' === $schedule && ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'simple-performance-for-wordpress' ),
			);
		}

		if ( 'monthly' === $schedule && ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => MONTH_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'simple-performance-for-wordpress' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule the cron event if it does not already exist.
	 */
	public function maybe_schedule_event() {
		$db       = SPFW_Settings::group( 'database' );
		$schedule = isset( $db['optimize_schedule'] ) ? $db['optimize_schedule'] : 'off';

		if ( 'off' !== $schedule && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $schedule, self::CRON_HOOK );
		}
	}

	/**
	 * Run all enabled targets on the cron schedule.
	 */
	public function run_scheduled_optimization() {
		$db      = SPFW_Settings::group( 'database' );
		$targets = array();

		foreach ( self::TARGETS as $target ) {
			if ( ! empty( $db[ $target ] ) ) {
				$targets[] = $target;
			}
		}

		if ( ! empty( $targets ) ) {
			$this->optimize( $targets );
		}
	}

	/**
	 * Clear the scheduled event when the cadence changes or is turned off.
	 *
	 * @param array $new_value New settings being saved.
	 * @param array $old_value Current settings.
	 * @return array
	 */
	public function watch_schedule_change( $new_value, $old_value ) {
		$new_schedule = isset( $new_value['database']['optimize_schedule'] ) ? $new_value['database']['optimize_schedule'] : 'off';
		$old_schedule = isset( $old_value['database']['optimize_schedule'] ) ? $old_value['database']['optimize_schedule'] : 'off';

		if ( $new_schedule !== $old_schedule ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		return $new_value;
	}

	/**
	 * Human-readable labels for each cleanup target.
	 *
	 * @return array<string,string>
	 */
	public static function get_target_labels() {
		return array(
			'post_revisions'     => __( 'Revisions', 'simple-performance-for-wordpress' ),
			'post_auto_drafts'   => __( 'Auto Drafts', 'simple-performance-for-wordpress' ),
			'trashed_posts'      => __( 'Trashed Posts', 'simple-performance-for-wordpress' ),
			'spam_comments'      => __( 'Spam Comments', 'simple-performance-for-wordpress' ),
			'trashed_comments'   => __( 'Trashed Comments', 'simple-performance-for-wordpress' ),
			'expired_transients' => __( 'Expired Transients', 'simple-performance-for-wordpress' ),
			'all_transients'     => __( 'All Transients', 'simple-performance-for-wordpress' ),
			'tables'             => __( 'Table Optimization', 'simple-performance-for-wordpress' ),
		);
	}

	/**
	 * Count the items matching each cleanup target.
	 *
	 * @return array<string,int>
	 */
	public function scan() {
		global $wpdb;

		$data = array();

		// Post revisions.
		$data['post_revisions'] = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		// Auto drafts.
		$data['post_auto_drafts'] = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
		);

		// Trashed posts.
		$data['trashed_posts'] = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		// Spam comments.
		$data['spam_comments'] = (int) $wpdb->get_var(
			"SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);

		// Trashed comments.
		$data['trashed_comments'] = (int) $wpdb->get_var(
			"SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved IN ('trash', 'post-trashed')"
		);

		// Expired transients.
		$time = isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time();
		$data['expired_transients'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(option_name) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout' ) . '%',
				$time
			)
		);

		// All transients.
		$data['all_transients'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(option_id) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_' ) . '%'
			)
		);

		// Fragmented tables (non-InnoDB with reclaimable space).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$data['tables'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(table_name) FROM information_schema.tables WHERE table_schema = %s AND Engine <> 'InnoDB' AND data_free > 0",
				DB_NAME
			)
		);

		return $data;
	}

	/**
	 * Run cleanup for the requested targets.
	 *
	 * @param string[] $targets Target keys to process.
	 * @return array<string,int> Count of items removed per target.
	 */
	public function optimize( array $targets ) {
		global $wpdb;

		$results = array();

		foreach ( $targets as $target ) {
			if ( ! in_array( $target, self::TARGETS, true ) ) {
				continue;
			}

			switch ( $target ) {
				case 'post_revisions':
					$results[ $target ] = $this->clean_post_revisions();
					break;

				case 'post_auto_drafts':
					$results[ $target ] = $this->clean_posts_by_status( 'auto-draft' );
					break;

				case 'trashed_posts':
					$results[ $target ] = $this->clean_posts_by_status( 'trash' );
					break;

				case 'spam_comments':
					$results[ $target ] = $this->clean_comments_by_status( 'spam' );
					break;

				case 'trashed_comments':
					$results[ $target ] = $this->clean_trashed_comments();
					break;

				case 'expired_transients':
					$results[ $target ] = $this->clean_expired_transients();
					break;

				case 'all_transients':
					$results[ $target ] = $this->clean_all_transients();
					break;

				case 'tables':
					$results[ $target ] = $this->optimize_tables();
					break;
			}
		}

		return $results;
	}

	/**
	 * Delete all post revisions in batches.
	 *
	 * @return int
	 */
	private function clean_post_revisions() {
		global $wpdb;

		$count = 0;

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT %d",
					self::BATCH_SIZE
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$deleted = wp_delete_post_revision( (int) $id );
				if ( $deleted instanceof WP_Post ) {
					$count++;
				}
			}
		} while ( count( $ids ) === self::BATCH_SIZE );

		return $count;
	}

	/**
	 * Delete posts matching a given status in batches (bypass trash).
	 *
	 * @param string $status Post status to target.
	 * @return int
	 */
	private function clean_posts_by_status( $status ) {
		global $wpdb;

		$count = 0;

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s LIMIT %d",
					$status,
					self::BATCH_SIZE
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$deleted = wp_delete_post( (int) $id, true );
				if ( $deleted instanceof WP_Post ) {
					$count++;
				}
			}
		} while ( count( $ids ) === self::BATCH_SIZE );

		return $count;
	}

	/**
	 * Delete comments matching a given approved status in batches.
	 *
	 * @param string $status Comment approved value (e.g. 'spam').
	 * @return int
	 */
	private function clean_comments_by_status( $status ) {
		global $wpdb;

		$count = 0;

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = %s LIMIT %d",
					$status,
					self::BATCH_SIZE
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$count += (int) wp_delete_comment( (int) $id, true );
			}
		} while ( count( $ids ) === self::BATCH_SIZE );

		return $count;
	}

	/**
	 * Delete trashed and post-trashed comments in batches.
	 *
	 * @return int
	 */
	private function clean_trashed_comments() {
		global $wpdb;

		$count = 0;

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('trash', 'post-trashed') LIMIT %d",
					self::BATCH_SIZE
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$count += (int) wp_delete_comment( (int) $id, true );
			}
		} while ( count( $ids ) === self::BATCH_SIZE );

		return $count;
	}

	/**
	 * Delete expired transients in batches.
	 *
	 * @return int
	 */
	private function clean_expired_transients() {
		global $wpdb;

		$count = 0;
		$time  = isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time();

		do {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d",
					$wpdb->esc_like( '_transient_timeout' ) . '%',
					$time,
					self::BATCH_SIZE
				)
			);

			if ( empty( $names ) ) {
				break;
			}

			foreach ( $names as $name ) {
				$key = str_replace( '_transient_timeout_', '', $name );
				$count += (int) delete_transient( $key );
			}
		} while ( count( $names ) === self::BATCH_SIZE );

		return $count;
	}

	/**
	 * Delete all transients (regular and site) in batches.
	 *
	 * @return int
	 */
	private function clean_all_transients() {
		global $wpdb;

		$count = 0;

		do {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT %d",
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_' ) . '%',
					self::BATCH_SIZE
				)
			);

			if ( empty( $names ) ) {
				break;
			}

			foreach ( $names as $name ) {
				if ( false !== strpos( $name, '_site_transient_' ) ) {
					$count += (int) delete_site_transient( str_replace( '_site_transient_', '', $name ) );
				} else {
					$count += (int) delete_transient( str_replace( '_transient_', '', $name ) );
				}
			}
		} while ( count( $names ) === self::BATCH_SIZE );

		return $count;
	}

	/**
	 * Optimize fragmented non-InnoDB tables.
	 *
	 * @return int
	 */
	private function optimize_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND Engine <> 'InnoDB' AND data_free > 0",
				DB_NAME
			)
		);

		if ( empty( $tables ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->query( "OPTIMIZE TABLE {$table->table_name}" );
			if ( false !== $result ) {
				$count++;
			}
		}

		return $count;
	}
}
