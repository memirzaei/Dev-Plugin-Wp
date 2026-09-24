<?php
/**
 * Persian numeral and number-to-words helpers.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Formatter
 *
 * Provides Persian digit conversion and number-to-words formatting
 * for admin UI display.
 */
class Formatter {

	/**
	 * English (ASCII) to Persian digit map.
	 */
	const DIGIT_MAP = array(
		'0' => '۰',
		'1' => '۱',
		'2' => '۲',
		'3' => '۳',
		'4' => '۴',
		'5' => '۵',
		'6' => '۶',
		'7' => '۷',
		'8' => '۸',
		'9' => '۹',
	);

	/**
	 * Convert ASCII digits and separators to Persian equivalents.
	 *
	 * @param string $text Input text containing numbers.
	 * @return string Text with Persian digits and thousands separator.
	 */
	public static function to_persian_num( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		// Convert ASCII thousands separators between digit groups to Persian separator.
		$converted = preg_replace( '/(?<=[0-9]),(?=[0-9]{3})/', '٬', $text );
		if ( null !== $converted ) {
			$text = $converted;
		}
		return strtr( $text, self::DIGIT_MAP );
	}

	/**
	 * Format a price value with Persian digits and thousands separators.
	 *
	 * @param float $value Price value.
	 * @return string Formatted price string (no currency label).
	 */
	public static function format_price( float $value ): string {
		$formatted = number_format( (float) $value, 0, '.', ',' );
		return self::to_persian_num( $formatted );
	}

	/**
	 * Convert a number to Persian words (thin wrapper around NumberToWords).
	 *
	 * @param int|float|string $number Number to convert.
	 * @param string           $unit   Optional unit suffix (e.g. 'ریال').
	 * @return string Persian words representation.
	 */
	public static function num_to_words( $number, string $unit = '' ): string {
		return NumberToWords::convert( $number, $unit );
	}
}