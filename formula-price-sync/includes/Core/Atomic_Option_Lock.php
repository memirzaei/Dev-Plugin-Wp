<?php
/**
 * Database-backed atomic option locks.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides compare-and-set semantics over the WordPress options table.
 */
class Atomic_Option_Lock {
	/**
	 * Acquire or atomically reclaim an option lock.
	 *
	 * @param string $key     Option name.
	 * @param string $owner   Unique owner token.
	 * @param int    $ttl     Lifetime in seconds.
	 * @return bool
	 */
	public static function acquire( string $key, string $owner, int $ttl ): bool {
		global $wpdb;

		$now      = time();
		$new_lock = array(
			'owner'      => $owner,
			'acquired_at' => $now,
			'expires_at' => $now + max( 1, $ttl ),
		);

		if ( add_option( $key, $new_lock, '', false ) ) {
			return true;
		}

		$current = get_option( $key, array() );
		if ( ! is_array( $current ) ) {
			return false;
		}

		$expires = isset( $current['expires_at'] ) ? (int) $current['expires_at'] : 0;
		if ( $expires > $now ) {
			return false;
		}

		$old_serialized = maybe_serialize( $current );
		$new_serialized = maybe_serialize( $new_lock );

		// Compare-and-set. The unique option_name index serializes contenders at
		// the database level, avoiding the delete-then-add race of get/delete/add.
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new_serialized,
			$key,
			$old_serialized
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query( $sql );
		if ( 1 !== (int) $updated ) {
			return false;
		}

		$verified = get_option( $key, array() );
		return is_array( $verified ) && isset( $verified['owner'] ) && hash_equals( $owner, (string) $verified['owner'] );
	}

	/**
	 * Renew an existing lock using compare-and-set.
	 *
	 * @param string $key   Option name.
	 * @param string $owner Current owner token.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return bool
	 */
	public static function renew( string $key, string $owner, int $ttl ): bool {
		$current = get_option( $key, array() );
		if ( ! is_array( $current ) || empty( $current['owner'] ) || ! hash_equals( $owner, (string) $current['owner'] ) ) {
			return false;
		}

		$expires = isset( $current['expires_at'] ) ? (int) $current['expires_at'] : 0;
		if ( $expires <= time() ) {
			return false;
		}

		$old_serialized = maybe_serialize( $current );
		$current['expires_at'] = time() + max( 1, $ttl );
		// Ensure the stored payload changes even within the same second.
		$current['renewed_at'] = microtime( true );
		$new_serialized = maybe_serialize( $current );

		global $wpdb;
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new_serialized,
			$key,
			$old_serialized
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query( $sql );
		wp_cache_delete( $key, 'options' );

		if ( 1 !== (int) $updated ) {
			return false;
		}

		$verified = get_option( $key, array() );
		return is_array( $verified )
			&& isset( $verified['owner'] )
			&& hash_equals( $owner, (string) $verified['owner'] )
			&& (int) ( $verified['expires_at'] ?? 0 ) > time();
	}

	/**
	 * Release a lock only when the owner still matches.
	 *
	 * @param string $key   Option name.
	 * @param string $owner Owner token.
	 * @return bool
	 */
	public static function release( string $key, string $owner ): bool {
		global $wpdb;

		$current = get_option( $key, array() );
		if ( ! is_array( $current ) || empty( $current['owner'] ) || ! hash_equals( $owner, (string) $current['owner'] ) ) {
			return false;
		}

		$sql = $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$key,
			maybe_serialize( $current )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		return 1 === (int) $wpdb->query( $sql );
	}
}
