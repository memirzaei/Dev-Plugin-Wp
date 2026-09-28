<?php
/**
 * Unit tests for the Calculator engine.
 *
 * Covers Iranian gold guild formula, currency path, custom formula,
 * and edge cases for invalid rates.
 *
 * @package FormulaPriceSync
 */

declare(strict_types=1);

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Engine\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase {

	/**
	 * Gold 18k: tax must apply ONLY on (wage + profit), never on raw gold.
	 */
	public function test_gold_18k_tax_only_on_wage_and_profit(): void {
		$meta = array(
			'source_type'    => 'gold_18k',
			'weight'         => 10.0,   // grams
			'wage_percent'   => 7.0,
			'profit_percent' => 10.0,
			'tax_percent'    => 9.0,
			'fixed_fee'      => 0.0,
			'rounding_rule'  => 'none',
		);
		$rate = 5_000_000.0; // IRR per gram

		$result = Calculator::calculate_price( $meta, $rate );

		$this->assertArrayNotHasKey( 'error', $result['breakdown'] );
		$this->assertGreaterThan( 0, $result['final_price'] );

		$b = $result['breakdown'];

		// Raw gold = 10 * 5_000_000 = 50_000_000
		$this->assertEqualsWithDelta( 50_000_000.0, $b['raw_gold'], 0.01 );

		// Wage = 50_000_000 * 0.07 = 3_500_000
		$this->assertEqualsWithDelta( 3_500_000.0, $b['wage_amount'], 0.01 );

		// Profit = (50_000_000 + 3_500_000) * 0.10 = 5_350_000
		$this->assertEqualsWithDelta( 5_350_000.0, $b['profit_amount'], 0.01 );

		// Tax = (3_500_000 + 5_350_000) * 0.09 = 796_500  ← NEVER on raw gold
		$this->assertEqualsWithDelta( 796_500.0, $b['tax_amount'], 0.01 );

		$expected = 50_000_000 + 3_500_000 + 5_350_000 + 796_500;
		$this->assertEqualsWithDelta( $expected, $result['raw_total'], 0.01 );
		$this->assertEqualsWithDelta( $expected, $result['final_price'], 0.01 );
	}

	/**
	 * Gold 24k path uses the same guild formula.
	 */
	public function test_gold_24k_uses_same_guild_formula(): void {
		$meta = array(
			'source_type'    => 'gold_24k',
			'weight'         => 5.0,
			'wage_percent'   => 5.0,
			'profit_percent' => 8.0,
			'tax_percent'    => 9.0,
			'fixed_fee'      => 100_000.0,
			'rounding_rule'  => 'none',
		);
		$rate = 6_500_000.0;

		$result = Calculator::calculate_price( $meta, $rate );

		$this->assertArrayNotHasKey( 'error', $result['breakdown'] );
		$this->assertSame( 'gold_24k', $result['breakdown']['source_type'] );
		$this->assertGreaterThan( 0, $result['final_price'] );
		$this->assertEqualsWithDelta( 100_000.0, $result['breakdown']['fixed_fee'], 0.01 );
	}

	/**
	 * Coin source type follows the gold calculation path.
	 */
	public function test_coin_source_type(): void {
		$meta = array(
			'source_type'    => 'coin',
			'weight'         => 8.13, // typical Bahar Azadi weight approx
			'wage_percent'   => 0.0,
			'profit_percent' => 5.0,
			'tax_percent'    => 9.0,
			'fixed_fee'      => 0.0,
			'rounding_rule'  => 'none',
		);
		$rate = 70_000_000.0; // example coin rate per unit treated as gram-equivalent

		$result = Calculator::calculate_price( $meta, $rate );

		$this->assertArrayNotHasKey( 'error', $result['breakdown'] );
		$this->assertGreaterThan( 0, $result['final_price'] );
	}

	/**
	 * Currency path: base × rate × (1 + profit%) + tax + fixed fee.
	 */
	public function test_currency_path_with_profit_and_tax(): void {
		$meta = array(
			'source_type'        => 'currency',
			'base_foreign_price' => 100.0, // USD
			'profit_percent'     => 15.0,
			'tax_percent'        => 9.0,
			'tax_mode'           => 'total',
			'fixed_fee'          => 500_000.0,
			'rounding_rule'      => 'none',
		);
		$rate = 2_000_000.0; // IRR per 1 USD

		$result = Calculator::calculate_price( $meta, $rate );

		$this->assertArrayNotHasKey( 'error', $result['breakdown'] );

		$b = $result['breakdown'];
		// base_rial = 100 * 2_000_000 = 200_000_000
		$this->assertEqualsWithDelta( 200_000_000.0, $b['base_rial'], 0.01 );

		// profit = 200_000_000 * 0.15 = 30_000_000
		$this->assertEqualsWithDelta( 30_000_000.0, $b['profit_amount'], 0.01 );

		// tax on total = (200_000_000 + 30_000_000) * 0.09 = 20_700_000
		$this->assertEqualsWithDelta( 20_700_000.0, $b['tax_amount'], 0.01 );

		$expected = 200_000_000 + 30_000_000 + 20_700_000 + 500_000;
		$this->assertEqualsWithDelta( $expected, $result['final_price'], 0.01 );
	}

	/**
	 * Currency tax_mode = profit_only must tax only the profit component.
	 */
	public function test_currency_tax_mode_profit_only(): void {
		$meta = array(
			'source_type'        => 'currency',
			'base_foreign_price' => 50.0,
			'profit_percent'     => 10.0,
			'tax_percent'        => 9.0,
			'tax_mode'           => 'profit_only',
			'fixed_fee'          => 0.0,
			'rounding_rule'      => 'none',
		);
		$rate = 1_000_000.0;

		$result = Calculator::calculate_price( $meta, $rate );
		$b      = $result['breakdown'];

		// profit = 50_000_000 * 0.10 = 5_000_000
		// tax = 5_000_000 * 0.09 = 450_000
		$this->assertEqualsWithDelta( 450_000.0, $b['tax_amount'], 0.01 );
		$this->assertSame( 'profit_only', $b['tax_mode'] );
	}

	/**
	 * Custom formula path must evaluate a safe expression.
	 */
	public function test_custom_formula_path(): void {
		$meta = array(
			'source_type'    => 'custom_formula',
			'weight'         => 2.0,
			'wage_percent'   => 0.0,
			'profit_percent' => 0.0,
			'tax_percent'    => 0.0,
			'fixed_fee'      => 1000.0,
			'custom_formula' => '{weight} * {rate} + {fixed_fee}',
			'rounding_rule'  => 'none',
		);
		$rate = 4_000_000.0;

		$result = Calculator::calculate_price( $meta, $rate );

		$this->assertArrayNotHasKey( 'error', $result['breakdown'] );
		// 2 * 4_000_000 + 1000 = 8_001_000
		$this->assertEqualsWithDelta( 8_001_000.0, $result['final_price'], 0.01 );
	}

	/**
	 * Edge case: zero rate must return final_price = 0 and error marker.
	 */
	public function test_zero_rate_returns_zero_with_error(): void {
		$meta = array(
			'source_type' => 'gold_18k',
			'weight'      => 10.0,
		);

		$result = Calculator::calculate_price( $meta, 0.0 );

		$this->assertSame( 0.0, $result['final_price'] );
		$this->assertSame( 'invalid_source_rate', $result['breakdown']['error'] );
	}

	/**
	 * Edge case: negative rate must return final_price = 0.
	 */
	public function test_negative_rate_returns_zero_with_error(): void {
		$meta = array(
			'source_type' => 'currency',
			'base_foreign_price' => 100.0,
		);

		$result = Calculator::calculate_price( $meta, -1500.0 );

		$this->assertSame( 0.0, $result['final_price'] );
		$this->assertSame( 'invalid_source_rate', $result['breakdown']['error'] );
	}

	/**
	 * Edge case: non-finite rate (INF / NAN) must return final_price = 0.
	 */
	public function test_non_finite_rate_returns_zero_with_error(): void {
		$meta = array(
			'source_type' => 'gold_18k',
			'weight'      => 1.0,
		);

		$result_inf = Calculator::calculate_price( $meta, INF );
		$this->assertSame( 0.0, $result_inf['final_price'] );
		$this->assertSame( 'invalid_source_rate', $result_inf['breakdown']['error'] );

		$result_nan = Calculator::calculate_price( $meta, NAN );
		$this->assertSame( 0.0, $result_nan['final_price'] );
		$this->assertSame( 'invalid_source_rate', $result_nan['breakdown']['error'] );
	}

	/**
	 * Unsupported source_type must yield zero price and error marker.
	 */
	public function test_unsupported_source_type(): void {
		$meta = array(
			'source_type' => 'unknown_type_xyz',
			'weight'      => 1.0,
		);

		$result = Calculator::calculate_price( $meta, 1_000_000.0 );

		$this->assertSame( 0.0, $result['final_price'] );
		$this->assertSame( 'unsupported_source_type', $result['breakdown']['error'] );
	}
}
