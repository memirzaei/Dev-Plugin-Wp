<?php
/**
 * Lightweight Jalali (Persian) date helper — display + parse for admin UI.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Jalali
 *
 * Handles conversion between the Jalali (Persian) and Gregorian calendars,
 * plus formatting timestamps for display in admin screens.
 *
 * The classic 33-year Birashk cycle drifts around year 1404, where the
 * official Iranian calendar (updated 1403) marks a leap day that Birashk
 * does not. We keep an explicit leap-year table for such years and fall
 * back to the Birashk cycle everywhere else.
 */
class Jalali {

	/**
	 * Explicit leap-year overrides for years where the modern Iranian
	 * calendar diverges from the Birashk cycle.
	 *
	 * @var array<int,bool>
	 */
	private static $explicit_leap_years = array(
		1404 => true,
	);

	/**
	 * Format a unix timestamp or MySQL datetime as Jalali Y/m/d H:i.
	 *
	 * @param int|string $time   Unix timestamp or datetime string.
	 * @param string     $format Default jdate-like format using Y/m/d H:i tokens.
	 * @return string
	 */
	public static function format( $time, string $format = 'Y/m/d H:i' ): string {
		if ( is_string( $time ) && ! is_numeric( $time ) ) {
			$ts = strtotime( $time );
		} else {
			$ts = (int) $time;
		}
		if ( $ts <= 0 ) {
			return '';
		}

		$offset = (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );

		list( $jy, $jm, $jd ) = self::gregorian_to_jalali(
			(int) gmdate( 'Y', $ts + $offset ),
			(int) gmdate( 'n', $ts + $offset ),
			(int) gmdate( 'j', $ts + $offset )
		);

		// Prefer site timezone via wp_date for hour/minute/second.
		$hour = (int) wp_date( 'H', $ts );
		$min  = (int) wp_date( 'i', $ts );
		$sec  = (int) wp_date( 's', $ts );

		$map = array(
			'Y' => sprintf( '%04d', $jy ),
			'm' => sprintf( '%02d', $jm ),
			'd' => sprintf( '%02d', $jd ),
			'H' => sprintf( '%02d', $hour ),
			'i' => sprintf( '%02d', $min ),
			's' => sprintf( '%02d', $sec ),
		);

		$out = $format;
		foreach ( $map as $k => $v ) {
			$out = str_replace( $k, $v, $out );
		}
		return $out;
	}

	/**
	 * Convert Jalali Y/m/d (or Y-m-d) to MySQL date Y-m-d in site TZ calendar day.
	 *
	 * @param string $jalali Jalali date.
	 * @return string Empty string on failure.
	 */
	public static function to_gregorian_date( string $jalali ): string {
		$jalali = self::normalize_digits( trim( str_replace( array( '-', '.' ), '/', $jalali ) ) );
		if ( ! preg_match( '/^(\d{3,4})\/(\d{1,2})\/(\d{1,2})$/', $jalali, $m ) ) {
			return '';
		}

		$jy = (int) $m[1];
		$jm = (int) $m[2];
		$jd = (int) $m[3];

		if ( ! self::is_valid_jalali_date( $jy, $jm, $jd ) ) {
			return '';
		}

		list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $jy, $jm, $jd );
		if ( $gy < 1 ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/**
	 * Convert Persian/Arabic-Indic digits to ASCII.
	 *
	 * @param string $value Input string.
	 * @return string
	 */
	private static function normalize_digits( string $value ): string {
		static $from = null;
		static $to   = null;
		if ( null === $from ) {
			$from = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩' );
			$to   = array( '0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9' );
		}
		return str_replace( $from, $to, $value );
	}

	/**
	 * Check if a Jalali year is a leap year.
	 *
	 * Uses an explicit table for years where the modern Iranian calendar
	 * diverges from the classic 33-year Birashk cycle. Falls back to the
	 * Birashk cycle outside of the explicit table.
	 *
	 * @param int $jy Jalali year.
	 * @return bool
	 */
	private static function is_leap_jalali_year( int $jy ): bool {
		if ( isset( self::$explicit_leap_years[ $jy ] ) ) {
			return self::$explicit_leap_years[ $jy ];
		}
		$mod = $jy % 33;
		return in_array( $mod, array( 1, 5, 9, 13, 17, 22, 26, 30 ), true );
	}

	/**
	 * Validate a Jalali date (year/month/day ranges).
	 *
	 * @param int $jy Year.
	 * @param int $jm Month (1-12).
	 * @param int $jd Day (1-31).
	 * @return bool
	 */
	private static function is_valid_jalali_date( int $jy, int $jm, int $jd ): bool {
		if ( $jy < 1 || $jm < 1 || $jm > 12 || $jd < 1 ) {
			return false;
		}
		// Month 2 (Ordibehesht) never has 31 days.
		if ( 2 === $jm && 31 === $jd ) {
			return false;
		}
		if ( $jm <= 6 ) {
			$max_day = 31;
		} elseif ( $jm <= 11 ) {
			$max_day = 30;
		} else {
			// Month 12 (Esfand): 30 days in leap years, 29 otherwise.
			$max_day = self::is_leap_jalali_year( $jy ) ? 30 : 29;
		}
		return $jd <= $max_day;
	}

	/**
	 * Convert a Gregorian date to Jalali.
	 *
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month.
	 * @param int $gd Gregorian day.
	 * @return array{0:int,1:int,2:int} jy, jm, jd
	 */
	public static function gregorian_to_jalali( int $gy, int $gm, int $gd ): array {
		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;

		$days = 355666
			+ ( 365 * $gy )
			+ intdiv( $gy2 + 3, 4 )
			- intdiv( $gy2 + 99, 100 )
			+ intdiv( $gy2 + 399, 400 )
			+ $gd
			+ $g_d_m[ $gm - 1 ];

		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$jy   += intdiv( $days - 1, 365 );
			$days  = ( $days - 1 ) % 365;
		}
		$jm = ( $days < 186 ) ? 1 + intdiv( $days, 31 ) : 7 + intdiv( $days - 186, 30 );
		$jd = 1 + ( ( $days < 186 ) ? ( $days % 31 ) : ( ( $days - 186 ) % 30 ) );

		return array( $jy, $jm, $jd );
	}

	/**
	 * Convert a Jalali date to Gregorian.
	 *
	 * @param int $jy Jalali year.
	 * @param int $jm Jalali month.
	 * @param int $jd Jalali day.
	 * @return array{0:int,1:int,2:int} gy, gm, gd
	 */
	public static function jalali_to_gregorian( int $jy, int $jm, int $jd ): array {
		$jy_adj = $jy + 1595;

		$days = -355668
			+ ( 365 * $jy_adj )
			+ ( intdiv( $jy_adj, 33 ) * 8 )
			+ intdiv( ( $jy_adj % 33 ) + 3, 4 )
			+ $jd
			+ ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 + 186 ) );

		$gy   = 400 * intdiv( $days, 146097 );
		$days %= 146097;
		if ( $days > 36524 ) {
			$gy   += 100 * intdiv( --$days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				$days++;
			}
		}
		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;
		if ( $days > 365 ) {
			$gy   += intdiv( $days - 1, 365 );
			$days  = ( $days - 1 ) % 365;
		}
		$gd = $days + 1;

		$sal_a = array(
			0,
			31,
			( ( 0 === $gy % 4 && 0 !== $gy % 100 ) || ( 0 === $gy % 400 ) ) ? 29 : 28,
			31, 30, 31, 30, 31, 31, 30, 31, 30, 31,
		);

		$gm = 0;
		for ( $gm = 1; $gm <= 12 && $gd > $sal_a[ $gm ]; $gm++ ) {
			$gd -= $sal_a[ $gm ];
		}

		return array( $gy, $gm, $gd );
	}
}