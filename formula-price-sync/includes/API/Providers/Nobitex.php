<?php
/**
 * Nobitex rate provider (secondary failover).
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
 * Class Nobitex
 */
class Nobitex implements Fetcher_Interface {

	const ENDPOINT = 'https://api.nobitex.ir/market/stats';
	const FAIL_KEY = 'fps_fail_nobitex';
	const FAIL_TTL = 60;

	/**
	 * @return array
	 */
	public function fetch_rates(): array {
		if ( get_transient( self::FAIL_KEY ) ) {
			return array();
		}

		$response = API_Manager::safe_remote_get( self::ENDPOINT );

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

		if ( ! is_array( $data ) || empty( $data['stats'] ) ) {
			set_transient( self::FAIL_KEY, 1, self::FAIL_TTL );
			return array();
		}

		$stats = $data['stats'];
		$usd   = 0.0;

		if ( isset( $stats['USDT-IRT']['latest'] ) ) {
			$usd = (float) $stats['USDT-IRT']['latest'];
		} elseif ( isset( $stats['USDTIRT']['latest'] ) ) {
			$usd = (float) $stats['USDTIRT']['latest'];
		}

		if ( ! is_finite( $usd ) || $usd <= 0 ) {
			set_transient( self::FAIL_KEY, 1, self::FAIL_TTL );
			return array();
		}

		return array(
			'usd'       => $usd,
			'eur'       => 0.0,
			'gold_18k'  => 0.0,
			'gold_24k'  => 0.0,
			'coin'      => 0.0,
			'source'    => 'nobitex',
			'timestamp' => time(),
		);
	}
}
