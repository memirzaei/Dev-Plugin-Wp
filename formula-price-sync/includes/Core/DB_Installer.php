<?php
/**
 * Database installer and schema management.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DB_Installer
 *
 * Handles creation and maintenance of custom database tables.
 */
class DB_Installer {

	/**
	 * Database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '2.0.1';

	/**
	 * Option key for stored DB version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'fps_db_version';

	/**
	 * Option flag: lock-meta backfill has completed at least once.
	 *
	 * @var string
	 */
	const LOCK_META_MIGRATED_OPTION = 'fps_lock_meta_migrated';
	const LOCK_META_MIGRATION_STATE = 'fps_lock_meta_migration_state';
	const MIGRATION_BATCH_SIZE = 250;
	const MIGRATION_HOOK = 'fps_lock_meta_migration_batch';

	/**
	 * Run on plugin activation.
	 *
	 * Creates the custom price logs table and stores the schema version.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		// Ensure Action Scheduler tables exist if the library is available.
		if ( function_exists( 'action_scheduler_register_post_type' ) ) {
			// Action Scheduler is already bootstrapped by WooCommerce in most installs.
		}

		self::initialize_lock_meta_migration();
		self::schedule_migration_batch( 1 );
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Currently does not drop tables to preserve audit history.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally left empty – we keep the logs table for historical data.
		// Scheduled actions will be cleaned by Action Scheduler itself.
	}

	/**
	 * Create or update the custom tables using dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->prefix . 'fps_price_logs';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			old_price DECIMAL(30,6) NOT NULL DEFAULT 0.000000,
			new_price DECIMAL(30,6) NOT NULL DEFAULT 0.000000,
			source_rate DECIMAL(30,6) NOT NULL DEFAULT 0.000000,
			snapshot_id VARCHAR(64) NOT NULL DEFAULT '',
			trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY snapshot_id (snapshot_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Maybe upgrade the database schema and run idempotent data migrations.
	 *
	 * Called from plugins_loaded (via fps_init) so upgrades run for every
	 * request after a version bump, without requiring reactivation.
	 *
	 * Upgrade path coverage:
	 * - Fresh install          → install() handles schema + lock-meta
	 * - 1.x → 2.0.0            → schema ensure + lock-meta backfill
	 * - Same version re-check  → no-op for schema; lock-meta only if flag missing
	 *
	 * All steps are idempotent:
	 * - dbDelta is safe to re-run
	 * - lock-meta uses add_post_meta( ..., unique=true )
	 * - version option is only written when advancing
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '0' );

		// Schema upgrade when stored version is behind the code version.
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			self::run_versioned_migrations( $installed, self::DB_VERSION );
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}

		// Lock-meta backfill: run once (or again if flag was cleared).
		// The migration itself is idempotent; the flag avoids a full-table
		// scan on every subsequent request after a successful pass.
		if ( ! get_option( self::LOCK_META_MIGRATED_OPTION, false ) ) {
			self::initialize_lock_meta_migration();
			self::run_migration_batch();
		}
	}

	/**
	 * Version-scoped migration hooks.
	 *
	 * Add future one-shot data transforms here. Each block must be safe
	 * to re-execute (idempotent) in case the version option write fails
	 * mid-upgrade.
	 *
	 * @param string $from Installed version before upgrade.
	 * @param string $to   Target version.
	 * @return void
	 */
	private static function run_versioned_migrations( string $from, string $to ): void {
		// 1.x → 2.0.0: ensure lock-meta exists for all formula-enabled products.
		if ( version_compare( $from, '2.0.0', '<' ) && version_compare( $to, '2.0.0', '>=' ) ) {
			self::initialize_lock_meta_migration();
			self::schedule_migration_batch( 1 );
		}

		// 2.0.0 → 2.0.1: audit logs gained snapshot_id. Re-run dbDelta
		// after the schema version bump so existing installations receive the
		// column and index without requiring reactivation.
		if ( version_compare( $from, '2.0.1', '<' ) && version_compare( $to, '2.0.1', '>=' ) ) {
			self::create_tables();
		}
	}

	/** Initialize migration state without scanning the full catalog. */
	private static function initialize_lock_meta_migration(): void {
		if ( get_option( self::LOCK_META_MIGRATED_OPTION, false ) ) {
			return;
		}
		$state = get_option( self::LOCK_META_MIGRATION_STATE, array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			update_option(
				self::LOCK_META_MIGRATION_STATE,
				array(
					'state'       => 'queued',
					'next_id'     => 0,
					'processed'   => 0,
					'inserted'    => 0,
					'created_at'  => time(),
					'updated_at'  => time(),
				),
				false
			);
		}
	}

	/** Schedule one bounded migration continuation. */
	private static function schedule_migration_batch( int $delay = 1 ): void {
		$timestamp = time() + max( 1, $delay );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::MIGRATION_HOOK, array(), 'fps-price-sync', true );
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $timestamp, self::MIGRATION_HOOK, array() );
		}
	}

	/** Register the migration continuation hook once. */
	public static function init_hooks(): void {
		add_action( self::MIGRATION_HOOK, array( self::class, 'run_migration_batch' ) );
	}

	/** Execute one bounded resumable migration batch. */
	public static function run_migration_batch(): bool {
		if ( get_option( self::LOCK_META_MIGRATED_OPTION, false ) ) {
			return true;
		}
		self::initialize_lock_meta_migration();
		$state = get_option( self::LOCK_META_MIGRATION_STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$next_id = absint( $state['next_id'] ?? 0 );
		$started = microtime( true );
		$processed = 0;
		$inserted = absint( $state['inserted'] ?? 0 );

		global $wpdb;
		$sql = "SELECT e.post_id\n			FROM {$wpdb->postmeta} e\n			LEFT JOIN {$wpdb->postmeta} l\n			  ON l.post_id = e.post_id\n			 AND l.meta_key = %s\n			WHERE e.meta_key = %s\n			  AND e.meta_value = %s\n			  AND e.post_id > %d\n			  AND l.post_id IS NULL\n			ORDER BY e.post_id ASC\n			LIMIT %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, '_fps_price_locked', '_fps_enable', 'yes', $next_id, self::MIGRATION_BATCH_SIZE ) );

		if ( empty( $ids ) ) {
			$state['state'] = 'completed';
			$state['updated_at'] = time();
			update_option( self::LOCK_META_MIGRATION_STATE, $state, false );
			update_option( self::LOCK_META_MIGRATED_OPTION, '1', false );
			return true;
		}

		foreach ( $ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( $post_id < 1 ) {
				continue;
			}
			++$processed;
			$next_id = max( $next_id, $post_id );
			if ( add_post_meta( $post_id, '_fps_price_locked', 'no', true ) ) {
				++$inserted;
			}
			if ( ( microtime( true ) - $started ) >= 5.0 ) {
				break;
			}
		}

		$state['state'] = 'queued';
		$state['next_id'] = $next_id;
		$state['processed'] = absint( $state['processed'] ?? 0 ) + $processed;
		$state['inserted'] = $inserted;
		$state['updated_at'] = time();
		update_option( self::LOCK_META_MIGRATION_STATE, $state, false );
		self::schedule_migration_batch( 1 );
		return false;
	}

	/**
	 * Backfill _fps_price_locked = 'no' for products that have _fps_enable
	 * but are missing the lock meta entirely.
	 *
	 * Missing meta is treated as unlocked by the LEFT JOIN filter; this
	 * migration makes the data explicit and guards against future INNER JOIN
	 * regressions.
	 *
	 * @return int Number of rows inserted.
	 */
	public static function migrate_missing_lock_meta(): int {
		$before = get_option( self::LOCK_META_MIGRATION_STATE, array() );
		self::initialize_lock_meta_migration();
		self::run_migration_batch();
		$after = get_option( self::LOCK_META_MIGRATION_STATE, array() );
		$before_inserted = is_array( $before ) ? absint( $before['inserted'] ?? 0 ) : 0;
		$after_inserted = is_array( $after ) ? absint( $after['inserted'] ?? 0 ) : 0;
		return max( 0, $after_inserted - $before_inserted );
	}

	/**
	 * Drop the custom table (used only for complete uninstall).
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'fps_price_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::LOCK_META_MIGRATED_OPTION );
		delete_option( self::LOCK_META_MIGRATION_STATE );
	}
}
