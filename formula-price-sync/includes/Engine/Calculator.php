<?php
/**
 * Core price calculation engine (Gold guild + Currency).
 *
 * Canonical monetary unit: RIAL (ریال).
 * API providers return rates in Rial. Store prices are always written in Rial.
 * Toman conversion is presentation-only.
 *
 * Iranian Gold (صنفی) formula:
 * Tax is applied ONLY on (Wage + Profit), NEVER on Raw Gold value.
 *
 * Currency formula:
 * Final = (BaseForeign × RateIRR × (1 + Profit%/100)) + FixedFee
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Engine;

use FormulaPriceSync\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Calculator
 *
 * Calculates final product price from meta data and live source rate (Rial).
 */
class Calculator {

	/**
	 * Default tax percent for Iranian gold guild (9%).
	 *
	 * @var float
	 */
	const DEFAULT_TAX_PERCENT = 9.0;

	/**
	 * Calculate final price in Rial.
	 *
	 * Expected $meta_data keys (all optional with safe defaults):
	 * - source_type        : 'gold_18k' | 'gold_24k' | 'coin' | 'currency' | 'custom_formula'
	 * - weight             : float (grams) – gold path
	 * - base_foreign_price : float – currency path (e.g. 700 USD)
	 * - wage_percent       : float – gold only
	 * - profit_percent     : float
	 * - tax_percent        : float (defaults to 9; 0 valid)
	 * - tax_mode           : 'total' | 'profit_only' – currency path
	 * - fixed_fee          : float (Rial / store unit)
	 * - rounding_rule      : string
	 *
	 * @param array $meta_data   Product meta values.
	 * @param float $source_rate Live rate in Rial (gold per gram or IRR per foreign unit).
	 * @return array{
	 *   final_price: float,
	 *   breakdown: array,
	 *   raw_total: float
	 * }
	 */
	public static function calculate_price( array $meta_data, float $source_rate ): array {
		$source_type = isset( $meta_data['source_type'] ) ? sanitize_key( $meta_data['source_type'] ) : 'gold_18k';

		$wage_percent   = isset( $meta_data['wage_percent'] ) ? (float) $meta_data['wage_percent'] : 0.0;
		$profit_percent = isset( $meta_data['profit_percent'] ) ? (float) $meta_data['profit_percent'] : 0.0;
		$tax_percent    = isset( $meta_data['tax_percent'] ) ? (float) $meta_data['tax_percent'] : self::DEFAULT_TAX_PERCENT;
		$fixed_fee      = isset( $meta_data['fixed_fee'] ) ? (float) $meta_data['fixed_fee'] : 0.0;
		$rounding_rule  = isset( $meta_data['rounding_rule'] ) ? sanitize_key( $meta_data['rounding_rule'] ) : 'none';

		$wage_percent   = max( 0.0, $wage_percent );
		$profit_percent = max( 0.0, $profit_percent );
		$tax_percent    = max( 0.0, $tax_percent );
		$fixed_fee      = max( 0.0, $fixed_fee );
		$source_rate    = (float) $source_rate;

		if ( ! is_finite( $source_rate ) || $source_rate <= 0 ) {
			Logger::get_instance()->warning(
				'Calculator rejected invalid source rate.',
				array(
					'source'      => 'calculator',
					'source_type' => $source_type,
					'rate'        => is_finite( $source_rate ) ? $source_rate : 'non_finite',
				)
			);
			return array(
				'final_price' => 0.0,
				'breakdown'   => array( 'error' => 'invalid_source_rate' ),
				'raw_total'   => 0.0,
			);
		}

		$breakdown = array();
		$raw_total = 0.0;

		if ( in_array( $source_type, array( 'gold_18k', 'gold_24k', 'coin' ), true ) ) {
			$result    = self::calculate_gold(
				$meta_data,
				$source_rate,
				$wage_percent,
				$profit_percent,
				$tax_percent,
				$fixed_fee
			);
			$raw_total = $result['total'];
			$breakdown = $result['breakdown'];
		} elseif ( 'currency' === $source_type ) {
			$result    = self::calculate_currency(
				$meta_data,
				$source_rate,
				$profit_percent,
				$tax_percent,
				$fixed_fee
			);
			$raw_total = $result['total'];
			$breakdown = $result['breakdown'];
		} elseif ( 'custom_formula' === $source_type ) {
			$result    = self::calculate_custom(
				$meta_data,
				$source_rate,
				$wage_percent,
				$profit_percent,
				$tax_percent,
				$fixed_fee
			);
			$raw_total = $result['total'];
			$breakdown = $result['breakdown'];
		} else {
			$raw_total = 0.0;
			$breakdown = array(
				'error' => 'unsupported_source_type',
			);
			Logger::get_instance()->warning(
				'Calculator received unsupported source_type.',
				array(
					'source'      => 'calculator',
					'source_type' => $source_type,
				)
			);
		}

		if ( ! is_finite( $raw_total ) || $raw_total < 0 ) {
			Logger::get_instance()->error(
				'Calculator produced non-finite or negative total; coercing to zero.',
				array(
					'source'      => 'calculator',
					'source_type' => $source_type,
					'raw_total'   => is_finite( $raw_total ) ? $raw_total : 'non_finite',
				)
			);
			$raw_total = 0.0;
			$breakdown['error'] = 'non_finite_total';
		}

		$final_price = Rounding::apply( $raw_total, $rounding_rule );

		// An invalid calculation is a hard safety boundary. Extensions must not be able
		// to turn an errored/unsafe result into a positive financial mutation.
		if ( isset( $breakdown['error'] ) ) {
			return array(
				'final_price' => 0.0,
				'breakdown'   => $breakdown,
				'raw_total'   => 0.0,
			);
		}

		/**
		 * Filter the final calculated price (Rial / store unit).
		 *
		 * @param float $final_price Final price after rounding.
		 * @param array $meta_data   Original meta.
		 * @param float $source_rate Source rate used.
		 * @param array $breakdown  Calculation breakdown.
		 */
		$final_price = (float) apply_filters( 'fps_calculated_price', $final_price, $meta_data, $source_rate, $breakdown );
		if ( ! is_finite( $final_price ) || $final_price < 0 ) {
			$final_price = 0.0;
		}

		return array(
			'final_price' => $final_price,
			'breakdown'   => $breakdown,
			'raw_total'   => $raw_total,
		);
	}

	/**
	 * Iranian Gold guild calculation (صنفی) — all amounts in Rial.
	 *
	 * Formula:
	 *   Raw_Gold     = Weight_Grams × Rate_IRR_per_gram
	 *   Wage_Amount  = Raw_Gold × (Wage_Percent / 100)
	 *   Profit_Amount= (Raw_Gold + Wage_Amount) × (Profit_Percent / 100)
	 *   Tax_Amount   = (Wage_Amount + Profit_Amount) × (Tax_Percent / 100)   ← NEVER on Raw Gold
	 *   Final        = Raw_Gold + Wage + Profit + Tax + Fixed_Fee
	 *
	 * @param array $meta_data      Meta data.
	 * @param float $rate           Gold rate per gram in Rial.
	 * @param float $wage_percent   Wage %.
	 * @param float $profit_percent Profit %.
	 * @param float $tax_percent    Tax % (default 9).
	 * @param float $fixed_fee      Fixed fee in Rial.
	 * @return array{total: float, breakdown: array}
	 */
	private static function calculate_gold(
		array $meta_data,
		float $rate,
		float $wage_percent,
		float $profit_percent,
		float $tax_percent,
		float $fixed_fee
	): array {
		$weight = isset( $meta_data['weight'] ) ? (float) $meta_data['weight'] : 0.0;
		$weight = max( 0.0, $weight );

		// Shared meta key: base_foreign_price may hold weight for gold products.
		if ( $weight <= 0 && isset( $meta_data['base_foreign_price'] ) ) {
			$weight = max( 0.0, (float) $meta_data['base_foreign_price'] );
		}

		$raw_gold = $weight * $rate;

		$wage_amount   = $raw_gold * ( $wage_percent / 100 );
		$profit_amount = ( $raw_gold + $wage_amount ) * ( $profit_percent / 100 );

		// Critical guild rule: Tax ONLY on (Wage + Profit) — never on raw gold.
		$tax_amount = ( $wage_amount + $profit_amount ) * ( $tax_percent / 100 );

		$total = $raw_gold + $wage_amount + $profit_amount + $tax_amount + $fixed_fee;

		$breakdown = array(
			'source_type'           => isset( $meta_data['source_type'] ) ? $meta_data['source_type'] : 'gold_18k',
			'unit'                  => 'rial',
			'weight'                => $weight,
			'rate'                  => $rate,
			'raw_gold'              => round( $raw_gold, 2 ),
			'wage_percent'          => $wage_percent,
			'wage_amount'           => round( $wage_amount, 2 ),
			'profit_percent'        => $profit_percent,
			'profit_amount'         => round( $profit_amount, 2 ),
			'tax_percent'           => $tax_percent,
			'tax_amount'            => round( $tax_amount, 2 ),
			'fixed_fee'             => $fixed_fee,
			'total_before_rounding' => round( $total, 2 ),
		);

		return array(
			'total'     => $total,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Currency / imported goods calculation — all amounts in Rial.
	 *
	 * Formula:
	 *   Base_Rial = Base_Foreign_Price × Exchange_Rate_IRR
	 *   Final     = (Base_Rial × (1 + Profit_Percent / 100)) + Fixed_Fee
	 *
	 * Example: 700 USD × 2_060_000 IRR = 1_442_000_000 Rial (before profit).
	 *
	 * @param array $meta_data      Meta data.
	 * @param float $exchange_rate  IRR per foreign unit (e.g. Rial per 1 USD).
	 * @param float $profit_percent Profit %.
	 * @param float $fixed_fee      Fixed fee in Rial.
	 * @return array{total: float, breakdown: array}
	 */
	/**
	 * Currency calculation — all amounts in Rial.
	 *
	 * Base_Rial = Base_Foreign × Rate
	 * Profit    = Base_Rial × (profit%/100)
	 *
	 * Tax modes (meta tax_mode):
	 * - total       : tax on (Base_Rial + Profit)
	 * - profit_only : tax on Profit only (gold-style analogue)
	 * Tax_Percent = 0 is valid (no tax).
	 */
	private static function calculate_currency(
		array $meta_data,
		float $exchange_rate,
		float $profit_percent,
		float $tax_percent,
		float $fixed_fee
	): array {
		$base_foreign = isset( $meta_data['base_foreign_price'] ) ? (float) $meta_data['base_foreign_price'] : 0.0;
		$base_foreign = max( 0.0, $base_foreign );

		$tax_mode = isset( $meta_data['tax_mode'] ) ? sanitize_key( (string) $meta_data['tax_mode'] ) : 'total';
		if ( ! in_array( $tax_mode, array( 'total', 'profit_only' ), true ) ) {
			$tax_mode = 'total';
		}

		$base_rial     = $base_foreign * $exchange_rate;
		$profit_amount = $base_rial * ( $profit_percent / 100 );

		if ( 'profit_only' === $tax_mode ) {
			$tax_amount = $profit_amount * ( $tax_percent / 100 );
		} else {
			$tax_amount = ( $base_rial + $profit_amount ) * ( $tax_percent / 100 );
		}

		$total = $base_rial + $profit_amount + $tax_amount + $fixed_fee;

		$breakdown = array(
			'source_type'           => 'currency',
			'unit'                  => 'rial',
			'base_foreign'          => $base_foreign,
			'exchange_rate'         => $exchange_rate,
			'base_rial'             => round( $base_rial, 2 ),
			'profit_percent'        => $profit_percent,
			'profit_amount'         => round( $profit_amount, 2 ),
			'tax_mode'              => $tax_mode,
			'tax_percent'           => $tax_percent,
			'tax_amount'            => round( $tax_amount, 2 ),
			'fixed_fee'             => $fixed_fee,
			'total_before_rounding' => round( $total, 2 ),
		);

		return array(
			'total'     => $total,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Custom formula calculation via Formula_Parser (Rial context).
	 *
	 * @param array $meta_data      Meta data (must include custom_formula string).
	 * @param float $rate           Source rate in Rial.
	 * @param float $wage_percent   Wage %.
	 * @param float $profit_percent Profit %.
	 * @param float $tax_percent    Tax %.
	 * @param float $fixed_fee      Fixed fee.
	 * @return array{total: float, breakdown: array}
	 */
	private static function calculate_custom(
		array $meta_data,
		float $rate,
		float $wage_percent,
		float $profit_percent,
		float $tax_percent,
		float $fixed_fee
	): array {
		$weight = isset( $meta_data['weight'] ) ? (float) $meta_data['weight'] : 0.0;
		$weight = max( 0.0, $weight );
		if ( $weight <= 0 && isset( $meta_data['base_foreign_price'] ) ) {
			$weight = max( 0.0, (float) $meta_data['base_foreign_price'] );
		}

		$raw_gold      = $weight * $rate;
		$wage_amount   = $raw_gold * ( $wage_percent / 100 );
		$profit_amount = ( $raw_gold + $wage_amount ) * ( $profit_percent / 100 );
		$tax_amount    = ( $wage_amount + $profit_amount ) * ( $tax_percent / 100 );
		$base_rial     = $weight * $rate;

		$formula = isset( $meta_data['custom_formula'] ) ? (string) $meta_data['custom_formula'] : '';
		if ( '' === trim( $formula ) ) {
			// Fallback to standard gold guild formula.
			$total = $raw_gold + $wage_amount + $profit_amount + $tax_amount + $fixed_fee;
		} else {
			$vars = array(
				'weight'         => $weight,
				'rate'           => $rate,
				'wage_percent'   => $wage_percent,
				'profit_percent' => $profit_percent,
				'tax_percent'    => $tax_percent,
				'fixed_fee'      => $fixed_fee,
				'raw_gold'       => $raw_gold,
				'base_rial'      => $base_rial,
				'wage_amount'    => $wage_amount,
				'profit_amount'  => $profit_amount,
				'tax_amount'     => $tax_amount,
			);
			$parsed = Formula_Parser::evaluate_detailed( $formula, $vars );
			if ( ! $parsed['valid'] ) {
				return array( 'total' => 0.0, 'breakdown' => array( 'source_type' => 'custom_formula', 'formula' => $formula, 'error' => 'invalid_custom_formula', 'reason' => $parsed['reason'] ) );
			}
			$total = (float) $parsed['result'];
		}

		$breakdown = array(
			'source_type'           => 'custom_formula',
			'unit'                  => 'rial',
			'formula'               => $formula,
			'weight'                => $weight,
			'rate'                  => $rate,
			'total_before_rounding' => round( $total, 2 ),
		);

		return array(
			'total'     => $total,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Quick helper to get only the final price (no breakdown).
	 *
	 * @param array $meta_data   Product meta.
	 * @param float $source_rate Live rate in Rial.
	 * @return float
	 */
	public static function get_final_price( array $meta_data, float $source_rate ): float {
		$result = self::calculate_price( $meta_data, $source_rate );
		return $result['final_price'];
	}
}
