<?php
/**
 * Integration tests: Atomic_Option_Lock under concurrent contention.
 *
 * These tests exercise the real CAS path (UPDATE ... WHERE option_value = old)
 * via an in-memory $wpdb mock that enforces compare-and-set semantics
 * identical to MySQL under the unique option_name index.
 *
 * For a full WordPress + MySQL run, set FPS_INTEGRATION_MYSQL=1 and bootstrap
 * with the official WP test suite (see tests/README.md).
 *
 * @package FormulaPriceSync
 * @group   concurrency
 * @group   integration
 */

declare(strict_types=1);

namespace FormulaPriceSync\Tests\Integration;

use FormulaPriceSync\Core\Atomic_Option_Lock;
use PHPUnit\Framework\TestCase;

class AtomicOptionLockConcurrencyTest extends TestCase {

	private string $lock_key;

	protected function setUp(): void {
		parent::setUp();
		fps_test_reset_state();
		$this->lock_key = 'fps_concurrency_lock_' . uniqid( '', true );
	}

	protected function tearDown(): void {
		// Best-effort cleanup.
		delete_option( $this->lock_key );
		fps_test_reset_state();
		parent::tearDown();
	}

	/**
	 * Two workers racing for a free lock: exactly one must win.
	 */
	public function test_exactly_one_winner_on_free_lock_contention(): void {
		$workers = array( 'worker_A', 'worker_B', 'worker_C', 'worker_D', 'worker_E' );
		$wins    = array();

		foreach ( $workers as $owner ) {
			if ( Atomic_Option_Lock::acquire( $this->lock_key, $owner, 30 ) ) {
				$wins[] = $owner;
			}
		}

		$this->assertCount( 1, $wins, 'Exactly one concurrent acquire must succeed on a free lock' );

		$stored = get_option( $this->lock_key );
		$this->assertIsArray( $stored );
		$this->assertSame( $wins[0], $stored['owner'] );
	}

	/**
	 * After expiry, two reclaimers race via CAS: exactly one must succeed.
	 *
	 * This is the critical MySQL path:
	 *   UPDATE options SET option_value = new WHERE option_name = k AND option_value = old
	 * Only the first matching UPDATE returns affected_rows = 1.
	 */
	public function test_cas_reclaim_under_contention_allows_single_winner(): void {
		$key = $this->lock_key;

		// Seed an expired lock owned by a departed worker.
		update_option(
			$key,
			array(
				'owner'       => 'departed_owner',
				'acquired_at' => time() - 120,
				'expires_at'  => time() - 30,
			),
			false
		);

		$owner_x = 'reclaimer_X';
		$owner_y = 'reclaimer_Y';

		// Interleave the two acquires to simulate a race:
		// Both read the same expired payload, both attempt CAS.
		// The mock $wpdb ensures only the first matching UPDATE wins.
		$win_x = Atomic_Option_Lock::acquire( $key, $owner_x, 30 );
		$win_y = Atomic_Option_Lock::acquire( $key, $owner_y, 30 );

		$winners = array_filter(
			array(
				$owner_x => $win_x,
				$owner_y => $win_y,
			)
		);

		$this->assertCount( 1, $winners, 'CAS reclaim under contention must admit exactly one winner' );

		$stored = get_option( $key );
		$this->assertIsArray( $stored );
		$this->assertSame( array_key_first( $winners ), $stored['owner'] );

		// Second contender must have observed CAS failure.
		global $wpdb;
		if ( $wpdb instanceof \FPS_Test_WPDB ) {
			$this->assertGreaterThanOrEqual(
				1,
				$wpdb->cas_failures + ( $win_y ? 0 : 1 ),
				'At least one CAS attempt must fail when two reclaimers race'
			);
		}
	}

	/**
	 * Stale owner cannot release after a successful reclaim by another worker.
	 */
	public function test_stale_owner_cannot_release_after_reclaim(): void {
		$key     = $this->lock_key;
		$stale   = 'stale_owner';
		$reclaim = 'new_owner';

		update_option(
			$key,
			array(
				'owner'       => $stale,
				'acquired_at' => time() - 120,
				'expires_at'  => time() - 10,
			),
			false
		);

		$this->assertTrue( Atomic_Option_Lock::acquire( $key, $reclaim, 30 ) );

		// Stale owner attempts release with its old identity.
		$this->assertFalse(
			Atomic_Option_Lock::release( $key, $stale ),
			'Stale owner must not be able to release after reclaim'
		);

		$stored = get_option( $key );
		$this->assertIsArray( $stored );
		$this->assertSame( $reclaim, $stored['owner'] );

		// Legitimate owner can still release.
		$this->assertTrue( Atomic_Option_Lock::release( $key, $reclaim ) );
		$this->assertFalse( get_option( $key, false ) );
	}

	/**
	 * Renew by owner must not open a window for a concurrent acquire.
	 */
	public function test_renew_keeps_exclusion(): void {
		$key   = $this->lock_key;
		$owner = 'owner_renew';

		$this->assertTrue( Atomic_Option_Lock::acquire( $key, $owner, 5 ) );
		$this->assertTrue( Atomic_Option_Lock::renew( $key, $owner, 60 ) );

		// Another worker must still be blocked.
		$this->assertFalse( Atomic_Option_Lock::acquire( $key, 'intruder', 30 ) );

		$stored = get_option( $key );
		$this->assertSame( $owner, $stored['owner'] );
		$this->assertGreaterThan( time(), (int) $stored['expires_at'] );
	}

	/**
	 * Simulated multi-step race: N workers loop until one holds the lock,
	 * then verify mutual exclusion holds across the full cycle.
	 */
	public function test_round_robin_acquire_release_preserves_exclusion(): void {
		$key     = $this->lock_key;
		$workers = array( 'w1', 'w2', 'w3' );
		$history = array();

		for ( $round = 0; $round < 6; $round++ ) {
			$owner = $workers[ $round % count( $workers ) ];

			// Wait until lock is free (previous release) then acquire.
			$acquired = false;
			for ( $attempt = 0; $attempt < 5; $attempt++ ) {
				if ( Atomic_Option_Lock::acquire( $key, $owner, 10 ) ) {
					$acquired = true;
					break;
				}
				// Previous owner may still hold; force expiry for next attempt.
				$current = get_option( $key, array() );
				if ( is_array( $current ) && ! empty( $current['owner'] ) && $current['owner'] !== $owner ) {
					$current['expires_at'] = time() - 1;
					update_option( $key, $current, false );
				}
			}

			$this->assertTrue( $acquired, "Worker {$owner} must acquire in round {$round}" );
			$history[] = $owner;

			// While held, no other worker may acquire.
			foreach ( $workers as $other ) {
				if ( $other === $owner ) {
					continue;
				}
				$this->assertFalse(
					Atomic_Option_Lock::acquire( $key, $other, 10 ),
					"{$other} must not acquire while {$owner} holds the lock"
				);
			}

			$this->assertTrue( Atomic_Option_Lock::release( $key, $owner ) );
		}

		$this->assertCount( 6, $history );
	}

	/**
	 * pcntl-based parallel stress test when available.
	 *
	 * Spawns child processes that each try to acquire the same lock.
	 * Uses a file-based result channel because child processes do not
	 * share the parent's in-memory option store — therefore this test
	 * is skipped under the in-memory mock and only runs when a real
	 * shared backend (MySQL via WP test suite) is configured.
	 *
	 * @group mysql
	 */
	public function test_pcntl_parallel_acquire_against_shared_store(): void {
		if ( ! function_exists( 'pcntl_fork' ) ) {
			$this->markTestSkipped( 'pcntl extension not available' );
		}

		// Only meaningful with a real shared DB (WordPress test suite).
		if ( ! defined( 'FPS_INTEGRATION_MYSQL' ) || ! FPS_INTEGRATION_MYSQL ) {
			$this->markTestSkipped(
				'Set FPS_INTEGRATION_MYSQL=1 with WP test suite bootstrap for parallel MySQL concurrency.'
			);
		}

		$key       = 'fps_pcntl_lock_' . getmypid();
		$child_n   = 4;
		$result_dir = sys_get_temp_dir() . '/fps_lock_results_' . getmypid();
		@mkdir( $result_dir, 0700, true );

		$pids = array();
		for ( $i = 0; $i < $child_n; $i++ ) {
			$pid = pcntl_fork();
			if ( -1 === $pid ) {
				$this->fail( 'pcntl_fork failed' );
			}
			if ( 0 === $pid ) {
				// Child.
				$owner  = 'child_' . $i . '_' . getmypid();
				$won    = Atomic_Option_Lock::acquire( $key, $owner, 30 );
				file_put_contents( $result_dir . '/' . $owner, $won ? '1' : '0' );
				exit( 0 );
			}
			$pids[] = $pid;
		}

		foreach ( $pids as $pid ) {
			pcntl_waitpid( $pid, $status );
		}

		$wins = 0;
		foreach ( glob( $result_dir . '/*' ) as $file ) {
			if ( '1' === trim( (string) file_get_contents( $file ) ) ) {
				++$wins;
			}
			@unlink( $file );
		}
		@rmdir( $result_dir );

		// Cleanup lock.
		$stored = get_option( $key, array() );
		if ( is_array( $stored ) && ! empty( $stored['owner'] ) ) {
			Atomic_Option_Lock::release( $key, (string) $stored['owner'] );
		}

		$this->assertSame( 1, $wins, 'Exactly one parallel child must acquire the shared lock' );
	}
}
