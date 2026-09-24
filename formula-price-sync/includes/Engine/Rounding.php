<?php
/**
 * Price rounding rules for Iranian market (Toman).
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rounding
 *
 * Applies store-defined rounding rules to calculated prices.
 */
class Rounding {

	/**
	 * Supported rounding rules.
	 *
	 * @var string[]
	 */
	const RULES = array(
		'none',
		'ceil_1000',
		'round_1000',
		'round_10000',
	);

	/**
	 * Apply a rounding rule to a price.
	 *
	 * @param float  $price Calculated price.
	 * @param string $rule  Rounding rule key.
	 * @return float Rounded price.
	 */
	public static function apply( float $price, string $rule = 'none' ): float {
		$price = max( 0.0, (float) $price );
		$rule  = sanitize_key( $rule );

		switch ( $rule ) {
			case 'ceil_1000':
				// Round UP to nearest 1,000 Toman.
				return (float) ( ceil( $price / 1000 ) * 1000 );

			case 'round_1000':
				// Round to nearest 1,000 Toman (standard round half up).
				return (float) ( round( $price / 1000 ) * 1000 );

			case 'round_10000':
				// Round to nearest 10,000 Toman.
				return (float) ( round( $price / 10000 ) * 10000 );

			case 'none':
			default:
				// Keep original value (still ensure non-negative).
				return $price;
		}
	}

	/**
	 * Get human-readable labels for admin UI.
	 *
	 * @return array<string, string>
	 */
	public static function get_rule_labels(): array {
		return array(
			'none'        => __( 'بدون رند کردن', 'formula-price-sync' ),
			'ceil_1000'   => __( 'رند به بالا به نزدیک‌ترین ۱٬۰۰۰ (ریال/واحد)', 'formula-price-sync' ),
			'round_1000'  => __( 'رند به نزدیک‌ترین ۱٬۰۰۰ (ریال/واحد)', 'formula-price-sync' ),
			'round_10000' => __( 'رند به نزدیک‌ترین ۱۰٬۰۰۰ (ریال/واحد)', 'formula-price-sync' ),
		);
	}

	/**
	 * Check if a rule is valid.
	 *
	 * @param string $rule Rule key.
	 * @return bool
	 */
	public static function is_valid_rule( string $rule ): bool {
		return in_array( sanitize_key( $rule ), self::RULES, true );
	}
}
