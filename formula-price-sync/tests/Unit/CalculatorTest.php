<?php
declare(strict_types=1);

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Engine\Calculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FormulaPriceSync\Engine\Calculator
 */
class CalculatorTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/bootstrap/bootstrap.php';
		fps_test_reset_state();
		require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	}

	public function test_gold_18k_tax_only_on_wage_and_profit(): void {
		$meta = array(
			'source_type'    => 'gold_18k',
			'weight'         => 10.0,
			'wage_percent'   => 7.0,
			'profit_percent' => 10.0,
			'tax_percent'    => 9.0,
			'fixed_fee'      => 0.0,
			'rounding_rule'  => 'none',
		);
		$result = Calculator::calculate_price( $meta, 5_000_000.0 );
		$this->assertArrayHasKey( 'final_price', $result );
		$this->assertArrayHasKey( 'breakdown', $result );
		$this->assertEqualsWithDelta( 796_500.0, $result['breakdown']['tax_amount'], 1.0 );
		$this->assertGreaterThan( 0, $result['final_price'] );
	}

	public function test_zero_rate_returns_zero(): void {
		$meta = array( 'source_type' => 'gold_18k', 'weight' => 10.0 );
		$this->assertSame( 0.0, Calculator::calculate_price( $meta, 0.0 )['final_price'] );
	}

	public function test_negative_rate_returns_zero(): void {
		$meta = array( 'source_type' => 'gold_18k', 'weight' => 10.0 );
		$this->assertSame( 0.0, Calculator::calculate_price( $meta, -100.0 )['final_price'] );
	}

	public function test_currency_profit_only_tax(): void {
		$meta = array(
			'source_type'        => 'currency',
			'base_foreign_price' => 50,
			'profit_percent'     => 10,
			'tax_percent'        => 9,
			'tax_mode'           => 'profit_only',
		);
		$result = Calculator::calculate_price( $meta, 1_000_000.0 );
		$this->assertEqualsWithDelta( 450_000.0, $result['breakdown']['tax_amount'], 1.0 );
	}
}
