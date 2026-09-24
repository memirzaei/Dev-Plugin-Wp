<?php
/**
 * Immutable rate snapshot storage per synchronization run.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rate_Snapshot_Store {
	const PREFIX = 'fps_rate_snapshot_';
	const REF_PREFIX = 'fps_rate_snapshot_ref_';

	public static function persist_for_run( string $run_id, array $rates ): string {
		$existing = self::get_for_run( $run_id );
		if ( '' !== $existing ) {
			return $existing;
		}
		if ( ! self::validate( $rates ) ) {
			return '';
		}
		$snapshot_id = 'fpss_' . substr( hash( 'sha256', $run_id . '|' . microtime( true ) . '|' . wp_json_encode( $rates ) ), 0, 48 );
		$payload = array(
			'snapshot_id' => $snapshot_id,
			'run_id' => $run_id,
			'rates' => self::normalize( $rates ),
			'created_at' => time(),
		);
		if ( ! add_option( self::PREFIX . $snapshot_id, $payload, '', false ) ) {
			return '';
		}
		update_option( self::REF_PREFIX . hash( 'sha256', $run_id ), $snapshot_id, false );
		return $snapshot_id;
	}

	public static function get_for_run( string $run_id ): string {
		$id = get_option( self::REF_PREFIX . hash( 'sha256', $run_id ), '' );
		if ( ! is_string( $id ) || ! preg_match( '/^fpss_[a-f0-9]{48}$/', $id ) ) {
			return '';
		}
		return self::validate( self::get_rates( $id ) ) ? $id : '';
	}

	public static function get_rates( string $snapshot_id ): array {
		$payload = get_option( self::PREFIX . $snapshot_id, array() );
		if ( ! is_array( $payload ) || ! isset( $payload['rates'] ) || ! is_array( $payload['rates'] ) ) {
			return array();
		}
		return self::validate( $payload['rates'] ) ? self::normalize( $payload['rates'] ) : array();
	}

	public static function validate( array $rates ): bool {
		foreach ( array( 'usd', 'eur', 'gold_18k', 'gold_24k', 'coin' ) as $key ) {
			if ( ! array_key_exists( $key, $rates ) || ! is_numeric( $rates[ $key ] ) || ! is_finite( (float) $rates[ $key ] ) || (float) $rates[ $key ] < 0 ) {
				return false;
			}
		}
		return array_sum( array_map( 'floatval', array( $rates['usd'], $rates['eur'], $rates['gold_18k'], $rates['gold_24k'], $rates['coin'] ) ) ) > 0;
	}

	private static function normalize( array $rates ): array {
		return array(
			'usd' => (float) $rates['usd'],
			'eur' => (float) $rates['eur'],
			'gold_18k' => (float) $rates['gold_18k'],
			'gold_24k' => (float) $rates['gold_24k'],
			'coin' => (float) $rates['coin'],
			'source' => isset( $rates['source'] ) ? sanitize_key( (string) $rates['source'] ) : 'unknown',
			'timestamp' => isset( $rates['timestamp'] ) ? absint( $rates['timestamp'] ) : time(),
		);
	}
}
