<?php
/**
 * Safe custom formula parser (no eval).
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Engine;

use FormulaPriceSync\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Formula_Parser
 *
 * Evaluates simple arithmetic formulas with allowed variables.
 * Example: {weight} * {rate} * 1.07 + {fixed_fee}
 */
class Formula_Parser {

	/**
	 * Allowed variable placeholders.
	 *
	 * @var string[]
	 */
	const ALLOWED_VARS = array(
		'weight',
		'rate',
		'wage_percent',
		'profit_percent',
		'tax_percent',
		'fixed_fee',
		'raw_gold',
		'base_rial',
		'wage_amount',
		'profit_amount',
		'tax_amount',
	);

	/**
	 * Evaluate a formula string with given variables.
	 *
	 * @param string               $formula Formula text.
	 * @param array<string, float> $vars    Variable map (without braces).
	 * @return float
	 */
	public static function evaluate( string $formula, array $vars ): float {
		$detail = self::evaluate_detailed( $formula, $vars );
		return (float) $detail['result'];
	}

	/**
	 * Evaluate and expose validity so a legitimate zero is distinguishable from
	 * an invalid/divide-by-zero expression.
	 *
	 * @return array{valid:bool,result:float,reason:string}
	 */
	public static function evaluate_detailed( string $formula, array $vars ): array {
		$formula = trim( $formula );
		if ( '' === $formula ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'empty_formula' );
		}

		if ( strlen( $formula ) > 4096 || ! self::is_safe( $formula ) ) {
			Logger::get_instance()->warning(
				'Formula_Parser rejected unsafe formula.',
				array( 'source' => 'formula_parser', 'formula' => substr( $formula, 0, 120 ) )
			);
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'unsafe_formula' );
		}

		foreach ( self::ALLOWED_VARS as $key ) {
			$value = isset( $vars[ $key ] ) ? (float) $vars[ $key ] : 0.0;
			if ( ! is_finite( $value ) ) {
				return array( 'valid' => false, 'result' => 0.0, 'reason' => 'non_finite_variable' );
			}
			$formula = str_replace( '{' . $key . '}', (string) $value, $formula );
		}

		if ( false !== strpos( $formula, '{' ) || false !== strpos( $formula, '}' ) ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'unresolved_placeholder' );
		}

		$math = self::safe_math( $formula );
		if ( ! $math['valid'] ) {
			Logger::get_instance()->warning(
				'Formula_Parser rejected invalid arithmetic expression.',
				array( 'source' => 'formula_parser', 'reason' => $math['reason'] )
			);
			return array( 'valid' => false, 'result' => 0.0, 'reason' => $math['reason'] );
		}

		$result = (float) $math['result'];
		if ( ! is_finite( $result ) || $result < 0 ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'negative_or_non_finite_result' );
		}

		return array( 'valid' => true, 'result' => $result, 'reason' => '' );
	}

	/**
	 * Check formula only contains safe characters and known vars.
	 *
	 * @param string $formula Formula.
	 * @return bool
	 */
	public static function is_safe( string $formula ): bool {
		// Allowed: digits, operators, dots, spaces, parentheses, braces, letters, underscore.
		if ( ! preg_match( '/^[0-9\s\+\-\*\/\.\(\)\{\}a-zA-Z_]+$/', $formula ) ) {
			return false;
		}

		// Extract {tokens} and verify against allow-list.
		if ( preg_match_all( '/\{([a-zA-Z_]+)\}/', $formula, $matches ) ) {
			foreach ( $matches[1] as $token ) {
				if ( ! in_array( $token, self::ALLOWED_VARS, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Safe arithmetic evaluation without eval().
	 * Supports + - * / and parentheses via shunting-yard + RPN.
	 *
	 * @param string $expr Expression with numbers only.
	 * @return float
	 */
	private static function safe_math( string $expr ): array {
		$expr = preg_replace( '/\s+/', '', $expr );
		if ( null === $expr || '' === $expr ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'empty_expression' );
		}

		// Only numbers and operators left.
		if ( ! preg_match( '/^[0-9\+\-\*\/\.\(\)]+$/', $expr ) ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'unsafe_expression' );
		}

		$tokens = self::tokenize( $expr );
		if ( empty( $tokens ) ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'invalid_tokens' );
		}

		$rpn = self::to_rpn( $tokens );
		if ( empty( $rpn ) ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'invalid_expression' );
		}
		return self::eval_rpn( $rpn );
	}

	/**
	 * Tokenize expression.
	 *
	 * @param string $expr Expression.
	 * @return array
	 */
	private static function tokenize( string $expr ): array {
		$tokens = array();
		$i      = 0;
		$len    = strlen( $expr );

		while ( $i < $len ) {
			$ch = $expr[ $i ];

			// Numbers must be parsed before generic operator handling so decimal
			// values such as 1.1 are kept as one token.
			if ( ctype_digit( $ch ) || '.' === $ch ) {
				$num        = '';
				$dot_count  = 0;
				$digit_count = 0;
				while ( $i < $len && ( ctype_digit( $expr[ $i ] ) || '.' === $expr[ $i ] ) ) {
					if ( '.' === $expr[ $i ] ) {
						++$dot_count;
					} else {
						++$digit_count;
					}
					$num .= $expr[ $i ];
					++$i;
				}
				if ( 1 < $dot_count || 0 === $digit_count || ! is_numeric( $num ) ) {
					return array();
				}
				$tokens[] = $num;
				continue;
			}

			if ( ')' === $ch || '(' === $ch || '+' === $ch || '*' === $ch || '/' === $ch ) {
				$tokens[] = $ch;
				++$i;
				continue;
			}

			// Unary or binary minus.
			if ( '-' === $ch ) {
				$prev = ! empty( $tokens ) ? end( $tokens ) : null;
				if ( null === $prev || '(' === $prev || '+' === $prev || '-' === $prev || '*' === $prev || '/' === $prev ) {
					++$i;
					$num         = '-';
					$dot_count   = 0;
					$digit_count = 0;
					while ( $i < $len && ( ctype_digit( $expr[ $i ] ) || '.' === $expr[ $i ] ) ) {
						if ( '.' === $expr[ $i ] ) {
							++$dot_count;
						} else {
							++$digit_count;
						}
						$num .= $expr[ $i ];
						++$i;
					}
					if ( strlen( $num ) > 64 || 1 < $dot_count || 0 === $digit_count || ! is_numeric( $num ) ) {
						return array();
					}
					$tokens[] = $num;
					continue;
				}
				$tokens[] = $ch;
				++$i;
				continue;
			}

			return array();
		}

		return $tokens;
	}

	/**
	 * Convert infix tokens to RPN (shunting-yard).
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	private static function to_rpn( array $tokens ): array {
		$out   = array();
		$stack = array();
		$prec  = array(
			'+' => 1,
			'-' => 1,
			'*' => 2,
			'/' => 2,
		);

		foreach ( $tokens as $token ) {
			if ( is_numeric( $token ) ) {
				$out[] = $token;
			} elseif ( isset( $prec[ $token ] ) ) {
				while (
					! empty( $stack )
					&& isset( $prec[ end( $stack ) ] )
					&& $prec[ end( $stack ) ] >= $prec[ $token ]
				) {
					$out[] = array_pop( $stack );
				}
				$stack[] = $token;
			} elseif ( '(' === $token ) {
				$stack[] = $token;
			} elseif ( ')' === $token ) {
				while ( ! empty( $stack ) && '(' !== end( $stack ) ) {
					$out[] = array_pop( $stack );
				}
				if ( empty( $stack ) ) {
					return array();
				}
				array_pop( $stack ); // pop '('
			}
		}

		while ( ! empty( $stack ) ) {
			$op = array_pop( $stack );
			if ( '(' === $op || ')' === $op ) {
				return array();
			}
			$out[] = $op;
		}

		return $out;
	}

	/**
	 * Evaluate RPN stack.
	 *
	 * @param array $rpn RPN tokens.
	 * @return float
	 */
	private static function eval_rpn( array $rpn ): array {
		$stack = array();

		foreach ( $rpn as $token ) {
			if ( is_numeric( $token ) ) {
				$stack[] = (float) $token;
				continue;
			}

			if ( count( $stack ) < 2 ) {
				return array( 'valid' => false, 'result' => 0.0, 'reason' => 'stack_underflow' );
			}

			$b = array_pop( $stack );
			$a = array_pop( $stack );

			switch ( $token ) {
				case '+':
					$stack[] = $a + $b;
					break;
				case '-':
					$stack[] = $a - $b;
					break;
				case '*':
					$stack[] = $a * $b;
					break;
				case '/':
					if ( 0.0 === (float) $b ) {
						return array( 'valid' => false, 'result' => 0.0, 'reason' => 'division_by_zero' );
					}
					$stack[] = $a / $b;
					break;
				default:
					return array( 'valid' => false, 'result' => 0.0, 'reason' => 'stack_underflow' );
			}
		}

		if ( 1 !== count( $stack ) ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'malformed_expression' );
		}

		$result = (float) $stack[0];
		if ( ! is_finite( $result ) ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'non_finite_result' );
		}
		if ( $result < 0 ) {
			return array( 'valid' => false, 'result' => 0.0, 'reason' => 'negative_result' );
		}
		return array( 'valid' => true, 'result' => $result, 'reason' => '' );
	}
}
