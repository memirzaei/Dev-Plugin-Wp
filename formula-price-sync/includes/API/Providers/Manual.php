<?php
/**
 * Manual rates provider (final fallback).
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\API\Providers;

use FormulaPriceSync\API\Fetcher_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manual
 *
 * Returns rates stored in WordPress options by the store admin.
 */
class Manual implements Fetcher_Interface {

	/**
	 * Option key for manual rates.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'fps_manual_rates';

	/**
	 * Fetch rates from saved options.
	 *
	 * @return array
	 */
	public function fetch_rates(): array {
		$rates = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $rates ) || empty( $rates ) ) {
			return array();
		}

		return array(
			'usd'       => isset( $rates['usd'] ) ? (float) $rates['usd'] : 0.0,
			'eur'       => isset( $rates['eur'] ) ? (float) $rates['eur'] : 0.0,
			'gold_18k'  => isset( $rates['gold_18k'] ) ? (float) $rates['gold_18k'] : 0.0,
			'gold_24k'  => isset( $rates['gold_24k'] ) ? (float) $rates['gold_24k'] : 0.0,
			'coin'      => isset( $rates['coin'] ) ? (float) $rates['coin'] : 0.0,
			'source'    => 'manual',
			'timestamp' => isset( $rates['timestamp'] ) ? (int) $rates['timestamp'] : time(),
		);
	}

	/**
	 * Save manual rates (helper for admin settings).
	 *
	 * @param array $rates Rates to save.
	 * @return bool
	 */
	public static function save_rates( array $rates ): bool {
		$data = array(
			'usd'       => isset( $rates['usd'] ) ? (float) $rates['usd'] : 0.0,
			'eur'       => isset( $rates['eur'] ) ? (float) $rates['eur'] : 0.0,
			'gold_18k'  => isset( $rates['gold_18k'] ) ? (float) $rates['gold_18k'] : 0.0,
			'gold_24k'  => isset( $rates['gold_24k'] ) ? (float) $rates['gold_24k'] : 0.0,
			'coin'      => isset( $rates['coin'] ) ? (float) $rates['coin'] : 0.0,
			'timestamp' => time(),
		);

		return update_option( self::OPTION_KEY, $data, false );
	}
}
