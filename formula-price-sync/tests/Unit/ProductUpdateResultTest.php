<?php
/** R08 retry/outcome contract tests. */

declare(strict_types=1);

use FormulaPriceSync\Engine\Product_Update_Result;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;
use PHPUnit\Framework\TestCase;

final class ProductUpdateResultTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fps_test_reset_state();
	}

	public function test_all_required_outcome_categories_exist(): void {
		$expected = array(
			'updated', 'unchanged', 'locked', 'disabled',
			'invalid_rate', 'validation_error', 'retryable_error', 'permanent_error',
		);
		$this->assertSame( $expected, Product_Update_Result::all() );
	}

	public function test_only_retryable_error_is_retryable(): void {
		$this->assertTrue( Product_Update_Result::is_retryable( Product_Update_Result::RETRYABLE_ERROR ) );
		$this->assertFalse( Product_Update_Result::is_retryable( Product_Update_Result::INVALID_RATE ) );
		$this->assertFalse( Product_Update_Result::is_retryable( Product_Update_Result::LOCKED ) );
		$this->assertFalse( Product_Update_Result::is_retryable( Product_Update_Result::PERMANENT_ERROR ) );
	}

	public function test_product_lock_contention_is_classified_as_retryable(): void {
		update_option( 'fps_product_update_lock_101', array(
			'owner' => 'someone-else',
			'acquired_at' => time(),
			'expires_at' => time() + 60,
		), false );
		$result = Action_Scheduler_Handler::update_single_product_result(
			101,
			array( 'gold_18k' => 5000000 ),
			'manual'
		);
		$this->assertSame( Product_Update_Result::RETRYABLE_ERROR, $result['status'] );
	}
}
