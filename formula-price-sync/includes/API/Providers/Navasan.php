<?php
/**
 * Navasan rate provider (secondary failover).
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\API\Providers;

use FormulaPriceSync\API\Fetcher_Interface;
use FormulaPriceSync\API\API_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Navasan
 */
class Navasan implements Fetcher_Interface {

	const ENDPOINT = 'https://api.navasan.tech/latest/';
	const FAIL_KEY = 'fps_fail_navasan';
	const FAIL_TTL = 60;

	/**
	 * @return array
	 */
	public function fetch_rates(): array {
		if ( get_transient( self::FAIL_KEY ) ) {
			return array();
		}

		$api_key = (string) get_option( 'fps_navasan_api_key', '' );
		$headers = array();
		if ( '' !== $api_key ) {
			$headers['X-API-Key'] = $api_key;
		}

		$response = API_Manager::safe_remote_get( self::ENDPOINT, array( 'headers' => $headers ) );

		if ( is_wp_error( $response ) ) {
			set_transient( self::FAIL_KEY, 1, self::FAIL_TTL );
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			set_transient( self::FAIL_KEY, 1, self::FAIL_TTL );
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			set_transient( self::FAIL_KEY, 1, self::FAIL_TTL );
			return array();
		}

		$usd      = $this->extract( $data, array( 'usd', 'usd_sell', 'dollar' ) );
		$eur      = $this->extract( $data, array( 'eur', 'eur_sell', 'euro' ) );
		$gold_18k = $this->extract( $data, array( '18ayar', 'geram18', 'gold_18k' ) );
		$gold_24k = $this->extract( $data, array( '24ayar', 'geram24', 'gold_24k' ) );
		$coin     = $this->extract( $data, array( 'sekkeh', 'sekee', 'coin', 'emami' ) );

		if ( $usd <= 0 && $gold_18k <= 0 ) {
			set_transient( self::FAIL_KEY, 1, self::FAIL_TTL );
			return array();
		}

		return array(
			'usd'       => $usd,
			'eur'       => $eur,
			'gold_18k'  => $gold_18k,
			'gold_24k'  => $gold_24k,
			'coin'      => $coin,
			'source'    => 'navasan',
			'timestamp' => time(),
		);
	}

	/**
	 * @param array $data Source.
	 * @param array $keys Keys.
	 * @return float
	 */
	private function extract( array $data, array $keys ): float {
		foreach ( $keys as $key ) {
			$raw = null;
			if ( isset( $data[ $key ]['value'] ) ) {
				$raw = $data[ $key ]['value'];
			} elseif ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
				$raw = $data[ $key ];
			}
			if ( null === $raw ) {
				continue;
			}
			$value = (float) $raw;
			if ( is_finite( $value ) && $value > 0 ) {
				return $value;
			}
		}
		return 0.0;
	}
}
