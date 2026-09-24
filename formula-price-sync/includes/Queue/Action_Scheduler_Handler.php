<?php
/**
 * Action Scheduler batch price synchronization.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Queue;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\API\Rate_Snapshot_Store;
use FormulaPriceSync\Engine\Calculator;
use FormulaPriceSync\Engine\Product_Update_Result;
use FormulaPriceSync\Core\Cache_Purger;
use FormulaPriceSync\Core\Atomic_Option_Lock;
use FormulaPriceSync\Licensing\License_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Action_Scheduler_Handler
 *
 * Handles background bulk price updates via Action Scheduler in chunks of 50.
 */
class Action_Scheduler_Handler {

	/**
	 * Action hook name for processing a chunk.
	 *
	 * @var string
	 */
	const CHUNK_ACTION = 'fps_process_product_chunk';
	const CONTINUATION_ACTION = 'fps_process_queue_continuation';
	const MAX_CHUNKS_PER_KICK = 5;
	const MAX_KICK_SECONDS = 45;
	const DEFERRED_PREFIX = 'fps_queue_deferred_';
	const RUN_STATE_PREFIX = 'fps_queue_run_';
	const DEFERRED_MAX_BACKOFF = 3600;
	const DEFERRED_BASE_BACKOFF = 30;
	const PRODUCT_RETRY_HOOK = 'fps_retry_product_update';
	const PRODUCT_RETRY_MAX = 3;
	const PRODUCT_RETRY_BASE_BACKOFF = 30;
	const STATE_RETENTION_DAYS = 7;
	const STATE_CLEANUP_HOOK = 'fps_queue_state_cleanup';
	const STATE_CLEANUP_BATCH = 100;
	const STATE_CLEANUP_INTERVAL = DAY_IN_SECONDS;

	/**
	 * Chunk size.
	 *
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 50;
	const MIN_CHUNK_SIZE     = 10;
	const MAX_CHUNK_SIZE     = 100;

	/** Queue group used by Action Scheduler. */
	const ACTION_GROUP = 'fps-price-sync';

	/** Atomic option used as the active full-sync mutex. */
	const RUN_LOCK_OPTION = 'fps_queue_active_run';

	/** Active run lock lifetime. */
	const RUN_LOCK_TTL = 2 * HOUR_IN_SECONDS;

	/** Per-chunk lock lifetime. */
	const CHUNK_LOCK_TTL = 15 * MINUTE_IN_SECONDS;

	/** Completion marker option prefix. */
	const COMPLETION_PREFIX = 'fps_queue_complete_';

	/** Completion marker for the whole run. */
	const RUN_COMPLETION_PREFIX = 'fps_queue_run_completed_';

	/** Recurring Action Scheduler hook. */
	const SCHEDULE_HOOK = 'fps_scheduled_rate_sync';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register the worker.
		add_action( self::CHUNK_ACTION, array( __CLASS__, 'process_chunk' ), 10, 9 );
		add_action( self::CONTINUATION_ACTION, array( __CLASS__, 'process_queue_continuation' ), 10, 1 );
		add_action( self::PRODUCT_RETRY_HOOK, array( __CLASS__, 'process_product_retry' ), 10, 7 );
		add_action( self::STATE_CLEANUP_HOOK, array( __CLASS__, 'cleanup_old_run_state' ) );
		add_action( 'init', array( __CLASS__, 'ensure_state_cleanup_schedule' ), 25 );

		add_action( self::SCHEDULE_HOOK, array( __CLASS__, 'run_scheduled_sync' ) );

		// Reconcile the recurring Action Scheduler event with current settings.
		add_action( 'init', array( __CLASS__, 'sync_recurring_schedule' ), 20 );
		add_action( 'update_option_fps_options', array( __CLASS__, 'sync_recurring_schedule' ), 20, 3 );
	}

	/**
	 * Check whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Entry point when rates have been updated.
	 *
	 * Creates an immutable rate snapshot and starts a bounded cursor continuation.
	 *
	 * @param string $trigger_type How the update was triggered (scheduled|manual|api_webhook).
	 * @param array  $filters      Optional. { product_cats: int[], product_tags: int[] }
	 * @return int Number of scheduled chunks.
	 */
	public static function on_rates_updated( string $trigger_type = 'scheduled', array $filters = array() ): int {
		if ( License_Guard::should_block() ) {
			return 0;
		}

		$run_id = self::build_continuation_run_id( $trigger_type, $filters );
		if ( self::run_completion_exists( $run_id ) ) {
			return 0;
		}
		if ( ! self::acquire_run_lock( $run_id ) ) {
			return 0;
		}

		$snapshot_id = Rate_Snapshot_Store::get_for_run( $run_id );
		if ( '' === $snapshot_id ) {
			$rates = ( new API_Manager() )->force_refresh();
			$snapshot_id = Rate_Snapshot_Store::persist_for_run( $run_id, $rates );
		}
		if ( '' === $snapshot_id ) {
			self::release_run_lock( $run_id );
			return 0;
		}

		$state = get_option( self::RUN_STATE_PREFIX . $run_id, array() );
		$state = is_array( $state ) ? $state : array();
		if ( empty( $state ) ) {
			$state = array(
				'run_id' => $run_id,
				'trigger_type' => sanitize_key( $trigger_type ),
				'filters' => $filters,
				'snapshot_id' => $snapshot_id,
				'next_id' => 0,
				'chunks_completed' => 0,
				'products_seen' => 0,
				'products_updated' => 0,
				'outcomes' => array(),
				'state' => 'queued',
				'created_at' => time(),
			);
		} else {
			$state['snapshot_id'] = $snapshot_id;
			$state['filters'] = $filters;
			$state['trigger_type'] = sanitize_key( $trigger_type );
			$state['state'] = 'queued';
		}
		update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );

		if ( self::is_available() ) {
			return (bool) as_schedule_single_action( time() + 1, self::CONTINUATION_ACTION, array( 'run_id' => $run_id ), self::ACTION_GROUP, true ) ? 1 : 0;
		}

		return self::process_queue_continuation( $run_id ) ? 1 : 0;
	}

	/** Execute the configured recurring sync after refreshing rates. */
	public static function run_scheduled_sync(): void {
		self::on_rates_updated( 'scheduled' );
	}

	/** Reconcile recurring Action Scheduler schedule with plugin settings. */
	public static function sync_recurring_schedule(): void {
		if ( ! self::is_available() || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$options       = function_exists( 'get_option' ) ? get_option( 'fps_options', array() ) : array();
		$auto_update   = isset( $options['auto_update'] ) ? (bool) $options['auto_update'] : true;
		$schedule      = isset( $options['update_schedule'] ) ? (string) $options['update_schedule'] : 'hourly';
		$intervals     = array( 'hourly' => HOUR_IN_SECONDS, 'twicedaily' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS );

		if ( ! $auto_update || ! isset( $intervals[ $schedule ] ) ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::SCHEDULE_HOOK, array(), self::ACTION_GROUP );
			}
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::SCHEDULE_HOOK, array(), self::ACTION_GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + 60,
			$intervals[ $schedule ],
			self::SCHEDULE_HOOK,
			array(),
			self::ACTION_GROUP,
			true
		);
	}

	/**
	 * Resolve a safe chunk size. Legacy values remain accepted only within
	 * a bounded range so queue memory usage remains predictable.
	 *
	 * @return int
	 */
	private static function get_chunk_size(): int {
		$configured = function_exists( 'get_option' ) ? get_option( 'fps_options', array() ) : array();
		$configured = is_array( $configured ) ? absint( $configured['queue_chunk_size'] ?? 0 ) : 0;
		if ( $configured >= self::MIN_CHUNK_SIZE && $configured <= self::MAX_CHUNK_SIZE ) {
			return $configured;
		}

		$memory = function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : '';
		if ( is_string( $memory ) && preg_match( '/^(-?\d+(?:\.\d+)?)\s*([KMG])?$/i', trim( $memory ), $m ) ) {
			$value = (float) $m[1];
			$unit  = strtoupper( $m[2] ?? '' );
			if ( 'G' === $unit ) {
				$value *= 1024;
			} elseif ( 'K' === $unit ) {
				$value /= 1024;
			}
			if ( $value > 0 && $value <= 128 ) {
				return 25;
			}
		}
		return self::DEFAULT_CHUNK_SIZE;
	}

	/** Build a deterministic identity for duplicate trigger suppression. */
	private static function build_run_id( array $ids, string $trigger_type, array $filters ): string {
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		$bucket = (string) floor( time() / ( 5 * MINUTE_IN_SECONDS ) );
		return hash( 'sha256', wp_json_encode( array( $ids, sanitize_key( $trigger_type ), $filters, $bucket ) ) );
	}

	/** Build a bounded continuation run identity without materializing the catalog. */
	private static function build_continuation_run_id( string $trigger_type, array $filters ): string {
		$bucket = (string) floor( time() / ( 5 * MINUTE_IN_SECONDS ) );
		return hash( 'sha256', wp_json_encode( array( sanitize_key( $trigger_type ), $filters, $bucket ) ) );
	}

	/** Acquire the active run mutex. */
	private static function acquire_run_lock( string $run_id ): bool {
		return Atomic_Option_Lock::acquire( self::RUN_LOCK_OPTION, $run_id, self::RUN_LOCK_TTL );
	}

	/** Release only when this run still owns the mutex. */
	private static function release_run_lock( string $run_id ): void {
		Atomic_Option_Lock::release( self::RUN_LOCK_OPTION, $run_id );
	}

	/** Verify and extend the active run lock. */
	private static function renew_run_lock( string $run_id ): bool {
		return Atomic_Option_Lock::renew( self::RUN_LOCK_OPTION, $run_id, self::RUN_LOCK_TTL );
	}

	/**
	 * Ensure a worker owns the run mutex. A delayed Action Scheduler worker may
	 * arrive after the original scheduler request has exited or its lease expired.
	 * In that case one worker may atomically reclaim the expired lease; a live
	 * lease owned by another run is a retryable contention condition.
	 *
	 * @param string $run_id Deterministic run identity.
	 * @return bool
	 */
	private static function ensure_run_lock( string $run_id ): bool {
		$current = get_option( self::RUN_LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( $run_id, (string) $current['owner'] ) ) {
			// Best-effort lease extension; ownership is already verified.
			self::renew_run_lock( $run_id );
			return true;
		}

		if ( is_array( $current ) && isset( $current['expires_at'] ) && (int) $current['expires_at'] > time() ) {
			return false;
		}

		// Fresh acquire establishes ownership. Renew is best-effort only.
		if ( ! self::acquire_run_lock( $run_id ) ) {
			return false;
		}
		self::renew_run_lock( $run_id );
		return true;
	}

	/** Extend a chunk lock owned by this worker. */
	private static function renew_chunk_lock( string $run_id, int $chunk_index, string $owner ): bool {
		return Atomic_Option_Lock::renew( 'fps_queue_chunk_lock_' . $run_id . '_' . $chunk_index, $owner, self::CHUNK_LOCK_TTL );
	}

	/** Completion marker is unique per run + chunk. */
	private static function completion_key( string $run_id, int $chunk_index ): string {
		return self::COMPLETION_PREFIX . $run_id . '_' . $chunk_index;
	}

	private static function completion_marker_exists( string $run_id, int $chunk_index ): bool {
		return false !== get_option( self::completion_key( $run_id, $chunk_index ), false );
	}

	private static function deferred_key( string $run_id, int $chunk_index ): string {
		return self::DEFERRED_PREFIX . $run_id . '_' . $chunk_index;
	}

	/** Persist deferred state and schedule one bounded retry with exponential backoff. */
	private static function defer_chunk( string $run_id, int $chunk_index, array $args, string $reason ): bool {
		$key = self::deferred_key( $run_id, $chunk_index );
		$state = get_option( $key, array() );
		$attempt = is_array( $state ) ? absint( $state['attempt'] ?? 0 ) + 1 : 1;
		$delay = min( self::DEFERRED_MAX_BACKOFF, self::DEFERRED_BASE_BACKOFF * ( 2 ** min( 8, $attempt - 1 ) ) );
		$next_at = time() + $delay;
		$payload = array(
			'run_id' => $run_id,
			'chunk_index' => $chunk_index,
			'attempt' => $attempt,
			'reason' => sanitize_text_field( $reason ),
			'next_at' => $next_at,
			'updated_at' => time(),
		);
		update_option( $key, $payload, false );
		$run_state = get_option( self::RUN_STATE_PREFIX . $run_id, array() );
		$run_state = is_array( $run_state ) ? $run_state : array();
		$run_state = array_merge(
			$run_state,
			array(
				'run_id' => $run_id,
				'state' => 'deferred',
				'chunk_index' => $chunk_index,
				'reason' => $payload['reason'],
				'attempt' => $attempt,
				'next_at' => $next_at,
				'updated_at' => time(),
			)
		);
		update_option( self::RUN_STATE_PREFIX . $run_id, $run_state, false );

		if ( ! self::is_available() ) {
			if ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( $next_at, self::CHUNK_ACTION, $args );
				return true;
			}
			return false;
		}

		return (bool) as_schedule_single_action( $next_at, self::CHUNK_ACTION, $args, self::ACTION_GROUP, true );
	}

	private static function run_completion_key( string $run_id ): string {
		return self::RUN_COMPLETION_PREFIX . $run_id;
	}

	private static function run_completion_exists( string $run_id ): bool {
		return false !== get_option( self::run_completion_key( $run_id ), false );
	}

	/** Ensure old queue state cleanup remains scheduled once per day. */
	public static function ensure_state_cleanup_schedule(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::STATE_CLEANUP_HOOK, array(), self::ACTION_GROUP ) ) {
				as_schedule_recurring_action( time() + 300, self::STATE_CLEANUP_INTERVAL, self::STATE_CLEANUP_HOOK, array(), self::ACTION_GROUP, true );
			}
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && false === wp_next_scheduled( self::STATE_CLEANUP_HOOK ) ) {
			if ( function_exists( 'wp_schedule_event' ) ) {
				wp_schedule_event( time() + 300, 'daily', self::STATE_CLEANUP_HOOK, array() );
			}
		}
	}

	/** Schedule bounded cleanup of old queue state. */
	private static function schedule_state_cleanup( int $delay = 1 ): void {
		$timestamp = time() + max( 1, $delay );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $timestamp, self::STATE_CLEANUP_HOOK, array(), self::ACTION_GROUP, true );
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $timestamp, self::STATE_CLEANUP_HOOK, array() );
		}
	}

	/** Remove terminal queue/snapshot state older than the retention window. */
	public static function cleanup_old_run_state(): int {
		global $wpdb;
		$cutoff = time() - ( self::STATE_RETENTION_DAYS * DAY_IN_SECONDS );
		$like_parts = array(
			$wpdb->esc_like( self::COMPLETION_PREFIX ) . '%',
			$wpdb->esc_like( self::RUN_COMPLETION_PREFIX ) . '%',
			$wpdb->esc_like( self::RUN_STATE_PREFIX ) . '%',
			$wpdb->esc_like( self::DEFERRED_PREFIX ) . '%',
			$wpdb->esc_like( Rate_Snapshot_Store::PREFIX ) . '%',
			$wpdb->esc_like( Rate_Snapshot_Store::REF_PREFIX ) . '%',
		);
		$where = implode( ' OR ', array_map( static function ( $part ) { return "option_name LIKE '{$part}'"; }, $like_parts ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE {$where} ORDER BY option_id ASC LIMIT " . self::STATE_CLEANUP_BATCH );
		$deleted = 0;
		foreach ( (array) $rows as $row ) {
			$name = isset( $row->option_name ) ? (string) $row->option_name : '';
			$value = isset( $row->option_value ) ? maybe_unserialize( $row->option_value ) : array();
			$is_snapshot = strpos( $name, Rate_Snapshot_Store::PREFIX ) === 0;
			$is_snapshot_ref = strpos( $name, Rate_Snapshot_Store::REF_PREFIX ) === 0;
			if ( ! is_array( $value ) && ! $is_snapshot_ref ) { continue; }
			$terminal = is_array( $value ) && ( in_array( $value['state'] ?? '', array( 'completed', 'failed' ), true ) || strpos( $name, self::COMPLETION_PREFIX ) === 0 || strpos( $name, self::RUN_COMPLETION_PREFIX ) === 0 );
			$timestamp = is_array( $value ) ? absint( $value['completed_at'] ?? $value['updated_at'] ?? $value['created_at'] ?? 0 ) : 0;
			$remove = $terminal && $timestamp > 0 && $timestamp < $cutoff;
			if ( $is_snapshot && $timestamp > 0 && $timestamp < $cutoff ) {
				$remove = true;
			}
			if ( $is_snapshot_ref && is_string( $value ) && '' !== $value && false === get_option( Rate_Snapshot_Store::PREFIX . $value, false ) ) {
				$remove = true;
			}
			if ( $remove && delete_option( $name ) ) { ++$deleted; }
		}
		if ( count( (array) $rows ) >= self::STATE_CLEANUP_BATCH ) {
			self::schedule_state_cleanup( 5 );
		}
		return $deleted;
	}

	/** Return operator-facing run telemetry. */
	public static function get_run_health_summary( string $run_id ): array {
		$state = get_option( self::RUN_STATE_PREFIX . $run_id, array() );
		$state = is_array( $state ) ? $state : array();
		$created = absint( $state['created_at'] ?? 0 );
		$updated = absint( $state['updated_at'] ?? 0 );
		$products_seen = absint( $state['products_seen'] ?? 0 );
		$products_updated = absint( $state['products_updated'] ?? 0 );
		return array(
			'run_id' => $run_id,
			'state' => (string) ( $state['state'] ?? 'unknown' ),
			'trigger_type' => (string) ( $state['trigger_type'] ?? '' ),
			'snapshot_id' => (string) ( $state['snapshot_id'] ?? '' ),
			'next_id' => absint( $state['next_id'] ?? 0 ),
			'chunks_completed' => absint( $state['chunks_completed'] ?? 0 ),
			'products_seen' => $products_seen,
			'products_updated' => $products_updated,
			'outcomes' => isset( $state['outcomes'] ) && is_array( $state['outcomes'] ) ? $state['outcomes'] : array(),
			'last_error' => sanitize_text_field( (string) ( $state['last_error'] ?? $state['reason'] ?? '' ) ),
			'created_at' => $created,
			'updated_at' => $updated,
			'age_seconds' => $created > 0 ? max( 0, time() - $created ) : null,
			'progress' => $products_seen > 0 ? round( min( 100, ( $products_updated / $products_seen ) * 100 ), 2 ) : null,
		);
	}

	/** Return the most recently created/updated run state. */
	public static function get_latest_run_health_summary(): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::RUN_STATE_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 1", $like ) );
		if ( ! $row ) { return array(); }
		$name = (string) $row->option_name;
		$run_id = substr( $name, strlen( self::RUN_STATE_PREFIX ) );
		return self::get_run_health_summary( $run_id );
	}

	/** Acquire a per-chunk lock using atomic add_option(). */
	private static function acquire_chunk_lock( string $run_id, int $chunk_index ): string {
		$key   = 'fps_queue_chunk_lock_' . $run_id . '_' . $chunk_index;
		$owner = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		return Atomic_Option_Lock::acquire( $key, $owner, self::CHUNK_LOCK_TTL ) ? $owner : '';
	}

	private static function release_chunk_lock( string $run_id, int $chunk_index, string $owner ): void {
		if ( '' !== $owner ) {
			Atomic_Option_Lock::release( 'fps_queue_chunk_lock_' . $run_id . '_' . $chunk_index, $owner );
		}
	}

	private static function mark_chunk_complete( string $run_id, int $chunk_index, int $updated ): void {
		add_option( self::completion_key( $run_id, $chunk_index ), array( 'updated' => $updated, 'completed_at' => time() ), '', false );
		delete_option( self::deferred_key( $run_id, $chunk_index ) );
	}

	private static function maybe_complete_run( string $run_id, int $total_chunks, string $trigger_type = 'scheduled' ): void {
		$completed = 0;
		$prefix    = self::COMPLETION_PREFIX . $run_id . '_';
		global $wpdb;
		$like = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		foreach ( (array) $rows as $row ) {
			$completed += 1;
		}

		if ( $completed < $total_chunks ) {
			return;
		}

		$updated_total = 0;
		foreach ( (array) $rows as $row ) {
			$value = maybe_unserialize( $row->option_value );
			$updated_total += isset( $value['updated'] ) ? absint( $value['updated'] ) : 0;
		}

		// add_option() provides atomic compare-and-set semantics. Only one worker may
		// emit the completion event, even when the last chunks finish concurrently.
		$state = get_option( self::RUN_STATE_PREFIX . $run_id, array() );
		$state = is_array( $state ) ? $state : array();
		$state['state'] = 'completed';
		$state['completed_at'] = time();
		$state['products_updated'] = $updated_total;
		$state['updated_at'] = time();
		update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
		if ( ! add_option( self::run_completion_key( $run_id ), array( 'completed_at' => time(), 'updated_total' => $updated_total, 'snapshot_id' => (string) ( $state['snapshot_id'] ?? '' ) ), '', false ) ) {
			return;
		}
		\FormulaPriceSync\Core\Logger::get_instance()->info( 'Queue run completed.', array( 'run_id' => $run_id, 'chunks' => $total_chunks, 'products_updated' => $updated_total, 'snapshot_id' => (string) ( $state['snapshot_id'] ?? '' ) ) );

		do_action( 'fps_queue_run_completed', $run_id, $total_chunks, $updated_total, $trigger_type );
		self::schedule_state_cleanup( 60 );
		self::release_run_lock( $run_id );
	}


	/**
	 * Update price for a single product or variation.
	 *
	 * Public compatibility API remains bool; detailed consumers use
	 * update_single_product_result().
	 */
	public static function update_single_product( int $product_id, array $rates, string $trigger_type = 'scheduled', string $snapshot_id = '' ): bool {
		$result = self::update_single_product_result( $product_id, $rates, $trigger_type, $snapshot_id );
		return Product_Update_Result::UPDATED === $result['status'];
	}

	/**
	 * Return a structured outcome for one product mutation.
	 *
	 * @return array{status:string,reason:string,updated:bool}
	 */
	public static function update_single_product_result( int $product_id, array $rates, string $trigger_type = 'scheduled', string $snapshot_id = '' ): array {
		$owner = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$lock_key = 'fps_product_update_lock_' . absint( $product_id );
		if ( ! Atomic_Option_Lock::acquire( $lock_key, $owner, MINUTE_IN_SECONDS ) ) {
			return self::outcome( Product_Update_Result::RETRYABLE_ERROR, 'product_lock_contention' );
		}

		try {
			try {
				return self::perform_single_product_update( $product_id, $rates, $trigger_type, $snapshot_id );
			} catch ( \Throwable $e ) {
				return self::outcome( Product_Update_Result::RETRYABLE_ERROR, $e->getMessage() );
			}
		} finally {
			Atomic_Option_Lock::release( $lock_key, $owner );
		}
	}

	private static function outcome( string $status, string $reason = '' ): array {
		return array(
			'status'  => $status,
			'reason'  => sanitize_text_field( $reason ),
			'updated' => Product_Update_Result::UPDATED === $status,
		);
	}

	/** Perform mutation after product mutex acquisition. */
	private static function perform_single_product_update( int $product_id, array $rates, string $trigger_type = 'scheduled', string $snapshot_id = '' ): array {
		$enable = get_post_meta( $product_id, '_fps_enable', true );
		if ( 'yes' !== $enable ) {
			return self::outcome( Product_Update_Result::DISABLED );
		}

		if ( 'yes' === get_post_meta( $product_id, '_fps_price_locked', true ) ) {
			return self::outcome( Product_Update_Result::LOCKED );
		}

		$source_type = get_post_meta( $product_id, '_fps_source_type', true );
		if ( empty( $source_type ) ) {
			$source_type = 'gold_18k';
		}
		$currency_code = sanitize_key( (string) get_post_meta( $product_id, '_fps_currency_code', true ) );
		if ( 'currency' === $source_type && ! in_array( $currency_code, array( 'usd', 'eur' ), true ) ) {
			return self::outcome( Product_Update_Result::VALIDATION_ERROR, 'unsupported_currency_code' );
		}

		$source_rate = self::resolve_rate( $source_type, $rates, $currency_code );
		if ( ! is_finite( $source_rate ) || $source_rate <= 0 ) {
			return self::outcome( Product_Update_Result::INVALID_RATE, 'invalid_source_rate' );
		}

		$meta_data = array(
			'source_type'        => $source_type,
			'currency_code'      => $currency_code,
			'weight'             => (float) get_post_meta( $product_id, '_fps_base_foreign_price', true ),
			'base_foreign_price' => (float) get_post_meta( $product_id, '_fps_base_foreign_price', true ),
			'wage_percent'       => (float) get_post_meta( $product_id, '_fps_wage_percent', true ),
			'profit_percent'     => (float) get_post_meta( $product_id, '_fps_profit_percent', true ),
			'tax_percent'        => metadata_exists( 'post', $product_id, '_fps_tax_percent' ) ? (float) get_post_meta( $product_id, '_fps_tax_percent', true ) : Calculator::DEFAULT_TAX_PERCENT,
			'tax_mode'           => get_post_meta( $product_id, '_fps_tax_mode', true ) ?: 'total',
			'fixed_fee'          => (float) get_post_meta( $product_id, '_fps_fixed_fee', true ),
			'rounding_rule'      => get_post_meta( $product_id, '_fps_rounding_rule', true ) ?: 'none',
			'custom_formula'     => (string) get_post_meta( $product_id, '_fps_custom_formula', true ),
		);

		try {
			$calculation = Calculator::calculate_price( $meta_data, $source_rate );
		} catch ( \Throwable $e ) {
			return self::outcome( Product_Update_Result::VALIDATION_ERROR, 'calculator_exception: ' . $e->getMessage() );
		}

		if ( isset( $calculation['breakdown']['error'] ) ) {
			return self::outcome( Product_Update_Result::VALIDATION_ERROR, (string) $calculation['breakdown']['error'] );
		}
		$new_price = (float) $calculation['final_price'];
		if ( ! is_finite( $new_price ) || $new_price <= 0 ) {
			return self::outcome( Product_Update_Result::VALIDATION_ERROR, 'non_positive_calculated_price' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || (int) $product->get_id() !== $product_id ) {
			return self::outcome( Product_Update_Result::PERMANENT_ERROR, 'product_not_found' );
		}

		$post_type = get_post_type( $product_id );
		if ( 'product_variation' === $post_type && method_exists( $product, 'get_type' ) && 'variation' !== $product->get_type() ) {
			return self::outcome( Product_Update_Result::VALIDATION_ERROR, 'variation_type_mismatch' );
		}
		if ( 'product' === $post_type && method_exists( $product, 'get_type' ) && 'variation' === $product->get_type() ) {
			return self::outcome( Product_Update_Result::VALIDATION_ERROR, 'parent_type_mismatch' );
		}

		$old_price = (float) $product->get_price( 'edit' );
		if ( 'yes' === get_post_meta( $product_id, '_fps_price_locked', true ) ) {
			return self::outcome( Product_Update_Result::LOCKED );
		}

		if ( (string) $product->get_regular_price( 'edit' ) === (string) $new_price
			&& (string) $product->get_price( 'edit' ) === (string) $new_price ) {
			update_post_meta( $product_id, '_fps_last_synced', current_time( 'mysql' ) );
			return self::outcome( Product_Update_Result::UNCHANGED );
		}

		$product->set_regular_price( $new_price );
		$product->set_price( $new_price );
		$sale_price = $product->get_sale_price( 'edit' );
		if ( '' !== $sale_price && null !== $sale_price && (float) $sale_price > $new_price ) {
			$product->set_sale_price( '' );
		}
		$product->save();
		update_post_meta( $product_id, '_fps_last_synced', current_time( 'mysql' ) );
		self::insert_audit_log( $product_id, $old_price, $new_price, $source_rate, $trigger_type, $snapshot_id );
		Cache_Purger::purge_product( $product_id );
		do_action( 'fps_product_price_updated', $product_id, $old_price, $new_price, $calculation );

		return self::outcome( Product_Update_Result::UPDATED );
	}

	/** Increment and return a run-level outcome counter. */
	private static function record_outcome( string $run_id, string $status ): void {
		$key = self::RUN_STATE_PREFIX . $run_id;
		$state = get_option( $key, array() );
		$state = is_array( $state ) ? $state : array();
		$counts = isset( $state['outcomes'] ) && is_array( $state['outcomes'] ) ? $state['outcomes'] : array();
		if ( ! isset( $counts[ $status ] ) ) {
			$counts[ $status ] = 0;
		}
		++$counts[ $status ];
		$state['outcomes'] = $counts;
		$state['updated_at'] = time();
		update_option( $key, $state, false );
	}

	/** Schedule one bounded retry for a retryable product failure. */
	private static function schedule_product_retry( string $run_id, int $product_id, string $trigger_type, array $rates, int $attempt, int $chunk_index, string $snapshot_id = '' ): bool {
		if ( $attempt >= self::PRODUCT_RETRY_MAX || self::run_completion_exists( $run_id ) ) {
			self::record_outcome( $run_id, Product_Update_Result::PERMANENT_ERROR );
			return false;
		}
		$delay = min( 900, self::PRODUCT_RETRY_BASE_BACKOFF * ( 2 ** max( 0, $attempt - 1 ) ) );
		$args = array(
			'product_id' => $product_id,
			'trigger_type' => $trigger_type,
			'rates' => $rates,
			'run_id' => $run_id,
			'attempt' => $attempt + 1,
			'chunk_index' => $chunk_index,
			'snapshot_id' => $snapshot_id,
		);
		if ( self::is_available() ) {
			return (bool) as_schedule_single_action( time() + $delay, self::PRODUCT_RETRY_HOOK, $args, self::ACTION_GROUP, true );
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + $delay, self::PRODUCT_RETRY_HOOK, array_values( $args ) );
			return true;
		}
		return false;
	}

	/** Retry one product without re-running the whole chunk. */
	public static function process_product_retry( $product_id, $trigger_type = 'scheduled', $rates = array(), $run_id = '', $attempt = 1, $chunk_index = 0, $snapshot_id = '' ): void {
		$product_id = absint( $product_id );
		$attempt = max( 1, absint( $attempt ) );
		if ( $product_id <= 0 || '' === (string) $run_id || self::run_completion_exists( (string) $run_id ) ) {
			return;
		}
		if ( License_Guard::should_block() ) {
			return;
		}
		$snapshot_id = is_string( $snapshot_id ) ? $snapshot_id : '';
		if ( '' === $snapshot_id ) {
			$snapshot_id = Rate_Snapshot_Store::get_for_run( (string) $run_id );
		}
		if ( '' !== $snapshot_id ) {
			$rates = Rate_Snapshot_Store::get_rates( $snapshot_id );
		}
		if ( empty( $rates ) ) {
			self::record_outcome( (string) $run_id, Product_Update_Result::RETRYABLE_ERROR );
			return;
		}
		$result = self::update_single_product_result( $product_id, $rates, (string) $trigger_type, $snapshot_id );
		self::record_outcome( (string) $run_id, $result['status'] );
		if ( Product_Update_Result::is_retryable( $result['status'] ) ) {
			self::schedule_product_retry( (string) $run_id, $product_id, (string) $trigger_type, $rates, $attempt, absint( $chunk_index ), $snapshot_id );
		}
	}

	/**
	 * Resolve the numeric rate for a given source type.
	 *
	 * @param string $source_type Source type key.
	 * @param array  $rates       Rates array from API_Manager.
	 * @return float
	 */
	private static function resolve_rate( string $source_type, array $rates, string $currency_code = 'usd' ): float {
		switch ( $source_type ) {
			case 'gold_18k':
				return isset( $rates['gold_18k'] ) ? (float) $rates['gold_18k'] : 0.0;

			case 'gold_24k':
				return isset( $rates['gold_24k'] ) ? (float) $rates['gold_24k'] : 0.0;

			case 'coin':
				return isset( $rates['coin'] ) ? (float) $rates['coin'] : 0.0;

			case 'currency':
				if ( ! in_array( $currency_code, array( 'usd', 'eur' ), true ) ) {
					return 0.0;
				}
				return isset( $rates[ $currency_code ] ) && is_numeric( $rates[ $currency_code ] ) && (float) $rates[ $currency_code ] > 0
					? (float) $rates[ $currency_code ]
					: 0.0;

			case 'custom_formula':
				// Prefer gold_18k as default rate token for formulas.
				if ( ! empty( $rates['gold_18k'] ) ) {
					return (float) $rates['gold_18k'];
				}
				if ( ! empty( $rates['usd'] ) ) {
					return (float) $rates['usd'];
				}
				return 0.0;

			default:
				return 0.0;
		}
	}

	/**
	 * Insert a row into the audit log table.
	 *
	 * @param int    $product_id   Product or variation ID.
	 * @param float  $old_price    Previous price.
	 * @param float  $new_price    New price.
	 * @param float  $source_rate  Rate used for calculation.
	 * @param string $trigger_type Trigger type.
	 * @return void
	 */
	private static function insert_audit_log(
		int $product_id,
		float $old_price,
		float $new_price,
		float $source_rate,
		string $trigger_type,
		string $snapshot_id = ''
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'fps_price_logs';

		// Determine if this is a variation.
		$post_type     = get_post_type( $product_id );
		$variation_id  = 0;
		$parent_id     = $product_id;

		if ( 'product_variation' === $post_type ) {
			$variation_id = $product_id;
			$parent_id    = wp_get_post_parent_id( $product_id );
			if ( ! $parent_id ) {
				$parent_id = $product_id;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'product_id'   => $parent_id,
				'variation_id' => $variation_id,
				'old_price'    => $old_price,
				'new_price'    => $new_price,
				'source_rate'  => $source_rate,
				'snapshot_id'  => sanitize_text_field( $snapshot_id ),
				'trigger_type' => sanitize_key( $trigger_type ),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get all product and variation IDs that have auto-pricing enabled.
	 *
	 * @param array $args {
	 *     Optional. Filtering arguments.
	 *
	 *     @type int[] $product_cats Product category term IDs to restrict to.
	 *     @type int[] $product_tags Product tag term IDs to restrict to.
	 * }
	 * @return int[]
	 */
	/** Fetch one bounded catalog page using a monotonic post-ID cursor. */
	private static function get_next_enabled_product_ids( int $next_id, array $args, int $limit ): array {
		global $wpdb;
		$limit = min( self::MAX_CHUNK_SIZE, max( 1, $limit ) );
		$sql = "SELECT DISTINCT e.post_id FROM {$wpdb->postmeta} e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id LEFT JOIN {$wpdb->postmeta} l ON l.post_id = e.post_id AND l.meta_key = %s AND l.meta_value = %s WHERE e.meta_key = %s AND e.meta_value = %s AND e.post_id > %d AND p.post_type IN ('product','product_variation') AND l.post_id IS NULL";
		$params = array( '_fps_price_locked', 'yes', '_fps_enable', 'yes', $next_id );
		$cats = isset( $args['product_cats'] ) && is_array( $args['product_cats'] ) ? array_filter( array_map( 'absint', $args['product_cats'] ) ) : array();
		$tags = isset( $args['product_tags'] ) && is_array( $args['product_tags'] ) ? array_filter( array_map( 'absint', $args['product_tags'] ) ) : array();
		$clauses = array();
		if ( $cats ) {
			$ph = implode( ',', array_fill( 0, count( $cats ), '%d' ) );
			$clauses[] = "(tt.taxonomy = 'product_cat' AND tt.term_id IN ({$ph}))";
			$params = array_merge( $params, $cats );
		}
		if ( $tags ) {
			$ph = implode( ',', array_fill( 0, count( $tags ), '%d' ) );
			$clauses[] = "(tt.taxonomy = 'product_tag' AND tt.term_id IN ({$ph}))";
			$params = array_merge( $params, $tags );
		}
		if ( $clauses ) {
			$sql .= " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AND (" . implode( ' OR ', $clauses ) . "))";
		}
		$sql .= ' ORDER BY e.post_id ASC LIMIT %d';
		$params[] = $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) ) ) );
	}

	public static function get_enabled_product_ids( array $args = array() ): array {
		global $wpdb;
		$categories = isset( $args['product_cats'] ) && is_array( $args['product_cats'] ) ? array_filter( array_map( 'absint', $args['product_cats'] ) ) : array();
		$tags       = isset( $args['product_tags'] ) && is_array( $args['product_tags'] ) ? array_filter( array_map( 'absint', $args['product_tags'] ) ) : array();
		$params = array( '_fps_price_locked', 'yes', '_fps_enable', 'yes' );
		$sql = "SELECT DISTINCT e.post_id\n			FROM {$wpdb->postmeta} e\n			INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id\n			LEFT JOIN {$wpdb->postmeta} l\n			  ON l.post_id = e.post_id\n			 AND l.meta_key = %s\n			 AND l.meta_value = %s\n			WHERE e.meta_key = %s\n			  AND e.meta_value = %s\n			  AND p.post_type IN ('product','product_variation')\n			  AND l.post_id IS NULL";
		if ( ! empty( $categories ) || ! empty( $tags ) ) {
			$clauses = array();
			if ( ! empty( $categories ) ) {
				$ph = implode( ',', array_fill( 0, count( $categories ), '%d' ) );
				$clauses[] = "(tt.taxonomy = 'product_cat' AND tt.term_id IN ({$ph}))";
				$params = array_merge( $params, $categories );
			}
			if ( ! empty( $tags ) ) {
				$ph = implode( ',', array_fill( 0, count( $tags ), '%d' ) );
				$clauses[] = "(tt.taxonomy = 'product_tag' AND tt.term_id IN ({$ph}))";
				$params = array_merge( $params, $tags );
			}
			$sql .= " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END AND (" . implode( ' OR ', $clauses ) . "))";
		}
		$sql .= ' ORDER BY e.post_id ASC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}

	/**
	 * Filter a list of product/variation IDs by category and tag taxonomy.
	 *
	 * For variation IDs, the parent product's taxonomies are used.
	 *
	 * @param int[] $ids        Candidate IDs.
	 * @param int[] $categories Category term IDs (empty = no category filter).
	 * @param int[] $tags       Tag term IDs (empty = no tag filter).
	 * @return int[]|null Filtered IDs, or null when no candidates match.
	 */
	private static function filter_ids_by_taxonomy( array $ids, array $categories, array $tags ): ?array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) || ( empty( $categories ) && empty( $tags ) ) ) {
			return $ids;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$clauses = array();
		$params = $ids;
		if ( ! empty( $categories ) ) {
			$ph = implode( ',', array_fill( 0, count( $categories ), '%d' ) );
			$clauses[] = "(tt.taxonomy = 'product_cat' AND tt.term_id IN ({$ph}))";
			$params = array_merge( $params, $categories );
		}
		if ( ! empty( $tags ) ) {
			$ph = implode( ',', array_fill( 0, count( $tags ), '%d' ) );
			$clauses[] = "(tt.taxonomy = 'product_tag' AND tt.term_id IN ({$ph}))";
			$params = array_merge( $params, $tags );
		}
		$sql = "SELECT DISTINCT candidate.ID\n			FROM {$wpdb->posts} candidate\n			LEFT JOIN {$wpdb->posts} parent ON parent.ID = candidate.post_parent\n			INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = CASE WHEN candidate.post_type = 'product_variation' THEN parent.ID ELSE candidate.ID END\n			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id\n			WHERE candidate.ID IN ({$placeholders})\n			AND (" . implode( ' OR ', $clauses ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$matching = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
		$set = array_fill_keys( $matching, true );
		return array_values( array_filter( $ids, static function ( $id ) use ( $set ) { return isset( $set[ $id ] ); } ) );
	}

	/**
	 * Synchronous fallback when Action Scheduler is not available.
	 *
	 * @param string $trigger_type Trigger type.
	 * @param array  $filters      Optional filter arguments.
	 * @return int Number of chunks processed.
	 */
	private static function process_synchronously( string $trigger_type, array $filters = array(), string $run_id = '' ): int {
		return self::process_queue_continuation( $run_id ?: self::build_continuation_run_id( $trigger_type, $filters ) ) ? 1 : 0;
	}

	/** Process one bounded cursor continuation. */
	public static function process_queue_continuation( $run_id ): bool {
		$run_id = (string) $run_id;
		$state = get_option( self::RUN_STATE_PREFIX . $run_id, array() );
		if ( ! is_array( $state ) || empty( $state ) || self::run_completion_exists( $run_id ) ) { return false; }
		if ( License_Guard::should_block() ) {
			$state['state'] = 'deferred';
			$state['reason'] = 'license_blocked';
			update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
			self::schedule_continuation( $run_id, 60 );
			return true;
		}
		if ( ! self::ensure_run_lock( $run_id ) ) {
			self::schedule_continuation( $run_id, 15 );
			return false;
		}
		$snapshot_id = (string) ( $state['snapshot_id'] ?? '' );
		if ( empty( Rate_Snapshot_Store::get_rates( $snapshot_id ) ) ) {
			$state['state'] = 'failed'; $state['reason'] = 'invalid_snapshot';
			update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
			self::release_run_lock( $run_id );
			return false;
		}
		$started = microtime( true );
		$next_id = absint( $state['next_id'] ?? 0 );
		$chunk_index = absint( $state['chunks_completed'] ?? 0 );
		$filters = isset( $state['filters'] ) && is_array( $state['filters'] ) ? $state['filters'] : array();
		$processed = 0;
		$state['state'] = 'running';
		update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
		try {
			while ( $processed < self::MAX_CHUNKS_PER_KICK && ( microtime( true ) - $started ) < self::MAX_KICK_SECONDS ) {
				$ids = self::get_next_enabled_product_ids( $next_id, $filters, self::get_chunk_size() );
				if ( empty( $ids ) ) {
					$state['state'] = 'completed'; $state['completed_at'] = time();
					update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
					add_option( self::run_completion_key( $run_id ), array( 'completed_at' => time(), 'updated_total' => absint( $state['products_updated'] ?? 0 ), 'snapshot_id' => $snapshot_id ), '', false );
					self::release_run_lock( $run_id );
					return true;
				}
				$last_id = max( $ids );
				self::process_chunk( $ids, (string) ( $state['trigger_type'] ?? 'scheduled' ), $filters, $run_id, $chunk_index, PHP_INT_MAX, $snapshot_id, 1, $started + self::MAX_KICK_SECONDS, false );
				if ( ! self::completion_marker_exists( $run_id, $chunk_index ) ) {
					$state['state'] = 'queued'; update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
					self::release_run_lock( $run_id ); self::schedule_continuation( $run_id, 1 ); return true;
				}
				$marker = get_option( self::completion_key( $run_id, $chunk_index ), array() );
				$state['products_seen'] = absint( $state['products_seen'] ?? 0 ) + count( $ids );
				$state['products_updated'] = absint( $state['products_updated'] ?? 0 ) + absint( $marker['updated'] ?? 0 );
				$next_id = max( $next_id, $last_id );
				$chunk_index++; $state['next_id'] = $next_id; $state['chunks_completed'] = $chunk_index;
				update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
				$processed++;
			}
			$state['state'] = 'queued'; update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
			self::release_run_lock( $run_id ); self::schedule_continuation( $run_id, 1 ); return true;
		} catch ( \Throwable $e ) {
			$state['state'] = 'queued'; $state['last_error'] = $e->getMessage(); update_option( self::RUN_STATE_PREFIX . $run_id, $state, false );
			self::release_run_lock( $run_id ); self::schedule_continuation( $run_id, 15 ); return false;
		}
	}

	private static function schedule_continuation( string $run_id, int $delay = 1 ): bool {
		$timestamp = time() + max( 1, $delay );
		if ( self::is_available() ) { return (bool) as_schedule_single_action( $timestamp, self::CONTINUATION_ACTION, array( 'run_id' => $run_id ), self::ACTION_GROUP, true ); }
		if ( function_exists( 'wp_schedule_single_event' ) ) { wp_schedule_single_event( $timestamp, self::CONTINUATION_ACTION, array( $run_id ) ); return true; }
		return false;
	}

	/**
	 * Worker: process one chunk of product IDs.
	 *
	 * @param array  $product_ids  Array of product/variation IDs.
	 * @param string $trigger_type Trigger type for audit log.
	 * @param array  $filters      Optional filter arguments.
	 * @return void
	 */
	public static function process_chunk( $product_ids, $trigger_type = 'scheduled', $filters = array(), $run_id = '', $chunk_index = 0, $total_chunks = 1, $snapshot_id = '', $attempt = 1, $deadline = 0.0, $finalize_run = true ): void {
		// Action Scheduler may pass serialized args as strings; coerce before typed use.
		if ( is_string( $product_ids ) ) {
			$decoded = json_decode( $product_ids, true );
			$product_ids = is_array( $decoded ) ? $decoded : array();
		}
		$product_ids  = array_values( array_filter( array_map( 'absint', (array) $product_ids ) ) );
		$trigger_type = is_string( $trigger_type ) ? $trigger_type : 'scheduled';
		if ( is_string( $filters ) ) {
			$decoded = json_decode( $filters, true );
			$filters = is_array( $decoded ) ? $decoded : array();
		}
		$filters      = is_array( $filters ) ? $filters : array();
		$run_id       = is_string( $run_id ) ? $run_id : (string) $run_id;
		$chunk_index  = (int) $chunk_index;
		$total_chunks = max( 1, (int) $total_chunks );

		if ( empty( $product_ids ) ) {
			return;
		}

		$run_id = $run_id ?: self::build_run_id( $product_ids, $trigger_type, $filters );
		if ( self::completion_marker_exists( $run_id, $chunk_index ) ) {
			return;
		}

		if ( License_Guard::should_block() ) {
			self::defer_chunk(
				$run_id,
				$chunk_index,
				array(
					'product_ids' => $product_ids,
					'trigger_type' => $trigger_type,
					'filters' => $filters,
					'run_id' => $run_id,
					'chunk_index' => $chunk_index,
					'total_chunks' => $total_chunks,
				),
				'license_blocked'
			);
			return;
		}

		$lock_owner = self::acquire_chunk_lock( $run_id, $chunk_index );
		if ( '' === $lock_owner ) {
			return;
		}

		try {
			if ( self::run_completion_exists( $run_id ) ) {
				return;
			}
			if ( ! self::ensure_run_lock( $run_id ) ) {
				self::defer_chunk(
					$run_id,
					$chunk_index,
					array(
						'product_ids' => $product_ids,
						'trigger_type' => $trigger_type,
						'filters' => $filters,
						'run_id' => $run_id,
						'chunk_index' => $chunk_index,
						'total_chunks' => $total_chunks,
					),
					'run_lock_contention'
				);
				return;
			}

			$snapshot_id = is_string( $snapshot_id ) ? $snapshot_id : '';
			if ( '' === $snapshot_id ) {
				$snapshot_id = Rate_Snapshot_Store::get_for_run( $run_id );
			}
			$rates = '' !== $snapshot_id ? Rate_Snapshot_Store::get_rates( $snapshot_id ) : array();

			if ( empty( $rates ) ) {
				throw new \RuntimeException( 'Formula Price Sync queue received no valid rates; action must retry.' );
			}

			$updated = 0;

			foreach ( $product_ids as $product_index => $product_id ) {
				if ( $deadline > 0.0 && microtime( true ) >= $deadline ) {
					return;
				}
				if ( 0 === ( (int) $product_index % 5 ) ) {
					if ( ! self::renew_run_lock( $run_id ) || ! self::renew_chunk_lock( $run_id, $chunk_index, $lock_owner ) ) {
						throw new \RuntimeException( 'Formula Price Sync queue lock ownership was lost; action must retry.' );
					}
				}

				$product_id = absint( $product_id );
				if ( $product_id <= 0 ) {
					continue;
				}

				if ( self::completion_marker_exists( $run_id, $chunk_index ) ) {
					break;
				}

				$result = self::update_single_product_result( $product_id, $rates, $trigger_type, $snapshot_id );
				$status = (string) $result['status'];
				self::record_outcome( $run_id, $status );
				if ( Product_Update_Result::UPDATED === $status ) {
					++$updated;
				}
				if ( Product_Update_Result::is_retryable( $status ) ) {
					self::schedule_product_retry( $run_id, $product_id, $trigger_type, $rates, absint( $attempt ), $chunk_index, $snapshot_id );
				}
			}

			if ( $updated > 0 ) {
				Cache_Purger::purge_all();
			}

			self::mark_chunk_complete( $run_id, $chunk_index, $updated );
			do_action( 'fps_chunk_processed', $product_ids, $updated, $trigger_type, $run_id, $chunk_index, $total_chunks, $snapshot_id );
			if ( $finalize_run ) {
				self::maybe_complete_run( $run_id, $total_chunks, $trigger_type );
			}
		} finally {
			self::release_chunk_lock( $run_id, $chunk_index, $lock_owner );
		}
	}

	/**
	 * Manually trigger a full sync (for admin button).
	 *
	 * @param array $filters Optional. { product_cats: int[], product_tags: int[] }
	 * @return int Number of scheduled/processed chunks.
	 */
	public static function trigger_manual_sync( array $filters = array() ) {
		return self::on_rates_updated( 'manual', $filters );
	}
}
