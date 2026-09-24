<?php
/**
 * TGJU rate provider (primary).
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
 * Class TGJU
 */
class TGJU implements Fetcher_Interface {

	const ENDPOINT = 'https://call1.tgju.org/ajax.json';
	const FAIL_KEY = 'fps_fail_tgju';
	const FAIL_TTL = 60;

	/**
	 * Fetch rates from TGJU.
	 *
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

		if ( ! is_array( $data ) || empty( $data['current'] ) ) {
			set_transient( self::FAIL_KEY, 1, self::FAIL_TTL );
			return array();
		}

		$current  = $data['current'];
		$usd      = $this->extract_price( $current, array( 'price_dollar_rl', 'usd' ) );
		$eur      = $this->extract_price( $current, array( 'price_eur', 'eur' ) );
		$gold_18k = $this->extract_price( $current, array( 'geram18', 'gold_18k', 'sekee_geram18', 'gold_mini_size' ) );
		$gold_24k = $this->extract_price( $current, array( 'geram24', 'gold_24k' ) );
		$coin     = $this->extract_price( $current, array( 'sekee', 'coin_emami', 'sekeh' ) );

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
			'source'    => 'tgju',
			'timestamp' => time(),
		);
	}

	/**
	 * @param array $data Source data.
	 * @param array $keys Possible keys.
	 * @return float
	 */
	private function extract_price( array $data, array $keys ): float {
		foreach ( $keys as $key ) {
			$raw = null;
			if ( isset( $data[ $key ]['p'] ) ) {
				$raw = str_replace( ',', '', $data[ $key ]['p'] );
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
