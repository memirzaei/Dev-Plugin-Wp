<?php
/**
 * R07 formula parser and financial safety regression suite.
 *
 * @package FormulaPriceSync
 */

declare(strict_types=1);

use FormulaPriceSync\Engine\Calculator;
use FormulaPriceSync\Engine\Formula_Parser;
use PHPUnit\Framework\TestCase;

final class FormulaParserSecurityTest extends TestCase {

	public function test_safe_arithmetic_supports_unary_minus_and_decimals(): void {
		$this->assertSame( 7.5, Formula_Parser::evaluate( '5 + 2.5', array() ) );
		$this->assertSame( 6.0, Formula_Parser::evaluate( '10 + 1 * -4', array() ) );
		$this->assertSame( 10.5, Formula_Parser::evaluate( '(2.5 + 1) * 3', array() ) );
	}

	/** @dataProvider invalidFormulaProvider */
	public function test_invalid_formulas_never_produce_positive_values( string $formula ): void {
		$result = Formula_Parser::evaluate( $formula, array(
			'weight' => 2,
			'rate' => 5000000,
			'fixed_fee' => 1000,
		) );
		$this->assertLessThanOrEqual( 0.0, $result );
	}

	public static function invalidFormulaProvider(): array {
		return array(
			'unknown placeholder' => array( '{weight} + {evil}' ),
			'php function text' => array( 'system(1)' ),
			'injection punctuation' => array( '{weight} . `whoami`' ),
			'malformed parentheses' => array( '(1 + 2' ),
			'dangling operator' => array( '1 + * 2' ),
			'division by zero' => array( '100 / 0' ),
			'negative final' => array( '1 - 2' ),
			'mismatched close' => array( '1 + 2)' ),
		);
	}

	public function test_huge_numeric_input_is_rejected_without_positive_result(): void {
		$huge = str_repeat( '9', 5000 );
		$this->assertSame( 0.0, Formula_Parser::evaluate( $huge, array() ) );
	}

	public function test_unknown_source_type_returns_zero(): void {
		$result = Calculator::calculate_price(
			array(
				'source_type' => 'unknown_source',
				'weight' => 1,
			),
			1000000
		);
		$this->assertSame( 0.0, $result['final_price'] );
		$this->assertSame( 'unsupported_source_type', $result['breakdown']['error'] );
	}

	public function test_zero_and_negative_rates_are_rejected(): void {
		$meta = array( 'source_type' => 'gold_18k', 'weight' => 1 );
		foreach ( array( 0.0, -1.0, INF, NAN ) as $rate ) {
			$result = Calculator::calculate_price( $meta, $rate );
			$this->assertSame( 0.0, $result['final_price'] );
			$this->assertSame( 'invalid_source_rate', $result['breakdown']['error'] );
		}
	}

	public function test_gold_tax_never_applies_to_raw_gold(): void {
		$result = Calculator::calculate_price(
			array(
				'source_type' => 'gold_18k',
				'weight' => 10,
				'wage_percent' => 7,
				'profit_percent' => 10,
				'tax_percent' => 9,
			),
			5000000
		);
		$b = $result['breakdown'];
		$this->assertSame( 50000000.0, $b['raw_gold'] );
		$this->assertSame( 796500.0, $b['tax_amount'] );
	}

	public function test_currency_tax_modes_and_zero_tax(): void {
		$total = Calculator::calculate_price(
			array(
				'source_type' => 'currency',
				'base_foreign_price' => 100,
				'profit_percent' => 10,
				'tax_percent' => 9,
				'tax_mode' => 'total',
			),
			1000000
		);
		$profit_only = Calculator::calculate_price(
			array(
				'source_type' => 'currency',
				'base_foreign_price' => 100,
				'profit_percent' => 10,
				'tax_percent' => 9,
				'tax_mode' => 'profit_only',
			),
			1000000
		);
		$zero_tax = Calculator::calculate_price(
			array(
				'source_type' => 'currency',
				'base_foreign_price' => 100,
				'profit_percent' => 10,
				'tax_percent' => 0,
				'fixed_fee' => 500,
			),
			1000000
		);
		$this->assertSame( 9900000.0, $total['breakdown']['tax_amount'] );
		$this->assertSame( 900000.0, $profit_only['breakdown']['tax_amount'] );
		$this->assertSame( 110000500.0, $zero_tax['final_price'] );
	}

	public function test_invalid_calculation_cannot_be_overridden_by_positive_filter(): void {
		add_filter(
			'fps_calculated_price',
			static function() {
				return 999999999.0;
			}
		);
		$result = Calculator::calculate_price(
			array( 'source_type' => 'custom_formula', 'weight' => 1, 'custom_formula' => '1 / 0' ),
			1000000
		);
		$this->assertSame( 0.0, $result['final_price'] );
		remove_all_filters( 'fps_calculated_price' );
	}
}
