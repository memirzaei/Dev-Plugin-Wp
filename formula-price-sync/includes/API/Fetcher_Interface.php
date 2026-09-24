<?php
/**
 * Interface for rate fetcher providers.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Fetcher_Interface
 *
 * All rate providers must implement this interface.
 */
interface Fetcher_Interface {

	/**
	 * Fetch current rates from the provider.
	 *
	 * Expected return structure:
	 * [
	 *   'usd'       => float,  // USD/IRR
	 *   'eur'       => float,  // EUR/IRR
	 *   'gold_18k'  => float,  // 18K gold per gram (IRR)
	 *   'gold_24k'  => float,  // 24K gold per gram (IRR)
	 *   'coin'      => float,  // Emami coin (IRR)
	 *   'source'    => string, // Provider name
	 *   'timestamp' => int,    // Unix timestamp
	 * ]
	 *
	 * @return array Associative array of rates or empty array on failure.
	 */
	public function fetch_rates(): array;
}
