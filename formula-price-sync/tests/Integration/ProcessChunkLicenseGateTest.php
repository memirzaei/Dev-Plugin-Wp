<?php
/**
 * Integration-style test for Action_Scheduler_Handler::process_chunk
 * license gate behaviour.
 *
 * Verifies that when License_Guard::should_block() returns true the
 * method exits early and never attempts to update product prices.
 *
 * @package FormulaPriceSync
 */

declare(strict_types=1);

namespace FormulaPriceSync\Tests\Integration;

use FormulaPriceSync\Licensing\License_Guard;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;
use PHPUnit\Framework\TestCase;

class ProcessChunkLicenseGateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fps_test_reset_state();
	}

	protected function tearDown(): void {
		fps_test_reset_state();
		parent::tearDown();
	}

	/**
	 * When the license is invalid, process_chunk must return immediately
	 * without side-effects on products.
	 *
	 * We cannot fully mock WooCommerce product updates in this lightweight
	 * harness, therefore we assert the public contract:
	 *   - should_block() === true
	 *   - process_chunk returns void and does not throw
	 *   - no completion markers are written for the run
	 */
	public function test_process_chunk_exits_early_when_license_blocks(): void {
		// Ensure invalid license state (default after reset).
		$this->assertTrue( License_Guard::should_block(), 'Pre-condition: license must be blocking' );

		$product_ids = array( 101, 102, 103 );
		$run_id      = 'test-run-blocked-' . uniqid( '', true );

		// Must not throw and must return void.
		Action_Scheduler_Handler::process_chunk(
			$product_ids,
			'scheduled',
			array(),
			$run_id,
			0,
			1
		);

		// Completion marker must not exist because the method returned before any work.
		$marker_key = 'fps_queue_complete_' . $run_id . '_0';
		$this->assertFalse(
			get_option( $marker_key, false ),
			'No completion marker may be written when license blocks processing'
		);

		// Run-level lock / completion must also remain absent.
		$run_complete = 'fps_queue_run_completed_' . $run_id;
		$this->assertFalse(
			get_option( $run_complete, false ),
			'Run completion marker must not be written under a blocked license'
		);
	}

	/**
	 * Empty product list is a no-op even when license is valid.
	 * (Guards against accidental work when AS passes malformed args.)
	 */
	public function test_process_chunk_noop_on_empty_product_list(): void {
		// Force a valid-looking license state so we pass the first gate.
		update_option( License_Guard::STATUS_OPTION, 'valid', false );
		set_transient(
			License_Guard::VALIDATION_TRANSIENT,
			array(
				'valid'      => true,
				'time'       => time(),
				'expires_at' => time() + 86400,
			),
			86400
		);

		$this->assertFalse( License_Guard::should_block() );

		Action_Scheduler_Handler::process_chunk(
			array(),
			'scheduled',
			array(),
			'empty-run',
			0,
			1
		);

		// Still no markers.
		$this->assertFalse( get_option( 'fps_queue_complete_empty-run_0', false ) );
	}
}
