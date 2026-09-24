<?php
/** R08 bounded retry scheduling and run summary tests. */

declare(strict_types=1);

use FormulaPriceSync\Engine\Product_Update_Result;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;
use PHPUnit\Framework\TestCase;

final class FailureSemanticsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fps_test_reset_state();
	}

	public function test_retry_schedule_is_single_product_and_bounded(): void {
		$GLOBALS['fps_test_actionscheduler_available'] = true;
		$method = new ReflectionMethod( Action_Scheduler_Handler::class, 'schedule_product_retry' );
		$method->setAccessible( true );
		$run_id = 'r08-run';
		$rates = array( 'usd' => 100, 'eur' => 110, 'gold_18k' => 5000, 'gold_24k' => 6000, 'coin' => 7000000 );
		$scheduled = $method->invoke( null, $run_id, 101, 'manual', $rates, 1, 2 );
		$this->assertTrue( $scheduled );
		$this->assertCount( 1, $GLOBALS['fps_test_scheduled_actions'] );
		$this->assertSame( 'fps_retry_product_update', $GLOBALS['fps_test_scheduled_actions'][0]['hook'] );
		$this->assertSame( 101, $GLOBALS['fps_test_scheduled_actions'][0]['args']['product_id'] );
		$this->assertSame( 2, $GLOBALS['fps_test_scheduled_actions'][0]['args']['attempt'] );
	}

	public function test_max_retry_is_terminal_and_does_not_schedule_again(): void {
		$method = new ReflectionMethod( Action_Scheduler_Handler::class, 'schedule_product_retry' );
		$method->setAccessible( true );
		$run_id = 'r08-max';
		$rates = array( 'usd' => 100, 'eur' => 110, 'gold_18k' => 5000, 'gold_24k' => 6000, 'coin' => 7000000 );
		$this->assertFalse( $method->invoke( null, $run_id, 101, 'manual', $rates, 3, 0 ) );
		$this->assertSame( 0, count( $GLOBALS['fps_test_scheduled_actions'] ?? array() ) );
	}

	public function test_outcome_summary_is_incremented(): void {
		$method = new ReflectionMethod( Action_Scheduler_Handler::class, 'record_outcome' );
		$method->setAccessible( true );
		$method->invoke( null, 'r08-summary', Product_Update_Result::UPDATED );
		$method->invoke( null, 'r08-summary', Product_Update_Result::RETRYABLE_ERROR );
		$method->invoke( null, 'r08-summary', Product_Update_Result::RETRYABLE_ERROR );
		$state = get_option( 'fps_queue_run_r08-summary', array() );
		$this->assertSame( 1, $state['outcomes']['updated'] );
		$this->assertSame( 2, $state['outcomes']['retryable_error'] );
	}
}
