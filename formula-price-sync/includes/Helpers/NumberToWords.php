<?php
/**
 * Convert numbers to Persian words.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NumberToWords
 *
 * Standalone helper for converting numeric values (including negative numbers,
 * Persian digits, and thousand separators) into Persian word representation.
 */
class NumberToWords {

	/**
	 * Convert a number to Persian words.
	 *
	 * Supports:
	 * - Negative numbers (prefix «منفی»)
	 * - String input with ASCII/Persian thousand separators (`,` / `٬`) and Persian digits
	 * - Optional unit suffix (e.g. «ریال»)
	 *
	 * @param int|float|string $number Number or numeric string to convert.
	 * @param string           $unit   Optional unit suffix appended after the words.
	 * @return string Persian words representation.
	 */
	public static function convert( $number, string $unit = '' ): string {
		$negative = false;

		if ( is_string( $number ) ) {
			// Strip Persian / ASCII thousand separators and convert Persian digits → ASCII.
			$normalized = strtr(
				$number,
				array(
					'۰' => '0',
					'۱' => '1',
					'۲' => '2',
					'۳' => '3',
					'۴' => '4',
					'۵' => '5',
					'۶' => '6',
					'۷' => '7',
					'۸' => '8',
					'۹' => '9',
					'٬' => '',
					',' => '',
					' ' => '',
				)
			);
			if ( 0 === strpos( $normalized, '-' ) || 0 === strpos( $normalized, '−' ) ) {
				$negative   = true;
				$normalized = ltrim( $normalized, '-−' );
			}
			$number = $normalized;
		} elseif ( is_numeric( $number ) && (float) $number < 0 ) {
			$negative = true;
		}

		$number = (int) floor( abs( (float) $number ) );

		if ( 0 === $number ) {
			$words = 'صفر';
		} else {
			$scales = array( '', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد' );
			$groups = array();
			$n      = $number;
			while ( $n > 0 ) {
				array_unshift( $groups, $n % 1000 );
				$n = intdiv( $n, 1000 );
			}

			$parts = array();
			$count = count( $groups );
			foreach ( $groups as $index => $group ) {
				if ( $group < 1 ) {
					continue;
				}
				$scale = $scales[ $count - 1 - $index ];
				if ( 1 === $group && 'هزار' === $scale ) {
					$parts[] = 'هزار';
				} else {
					$chunk   = self::three_digits_to_words( $group );
					$parts[] = '' === $scale ? $chunk : $chunk . ' ' . $scale;
				}
			}
			$words = implode( ' و ', $parts );
		}

		if ( $negative ) {
			$words = 'منفی ' . $words;
		}

		$unit = trim( $unit );
		if ( '' !== $unit ) {
			$words .= ' ' . $unit;
		}

		return $words;
	}

	/**
	 * Convert a three-digit number (1–999) to Persian words.
	 *
	 * @param int $number Number 1–999.
	 * @return string
	 */
	private static function three_digits_to_words( int $number ): string {
		$ones     = array( 1 => 'یک', 2 => 'دو', 3 => 'سه', 4 => 'چهار', 5 => 'پنج', 6 => 'شش', 7 => 'هفت', 8 => 'هشت', 9 => 'نه' );
		$teens    = array( 10 => 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده' );
		$tens     = array( 2 => 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود' );
		$hundreds = array( 1 => 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد' );

		$parts = array();
		$h     = intdiv( $number, 100 );
		$rest  = $number % 100;
		if ( $h > 0 ) {
			$parts[] = $hundreds[ $h ];
		}
		if ( $rest >= 10 && $rest < 20 ) {
			$parts[] = $teens[ $rest ];
		} else {
			$t = intdiv( $rest, 10 );
			$o = $rest % 10;
			if ( $t >= 2 ) {
				$parts[] = $tens[ $t ];
			}
			if ( $o > 0 ) {
				$parts[] = $ones[ $o ];
			}
		}
		return implode( ' و ', $parts );
	}
}
