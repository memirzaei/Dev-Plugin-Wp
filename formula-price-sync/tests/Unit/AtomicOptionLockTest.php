<?php
declare(strict_types=1);

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Core\Atomic_Option_Lock;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FormulaPriceSync\Core\Atomic_Option_Lock
 */
class AtomicOptionLockTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/bootstrap/bootstrap.php';
		fps_test_reset_state();
		require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	}

	public function test_acquire_succeeds_on_free_lock(): void {
		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner1', 30 ) );
	}

	public function test_acquire_fails_while_held(): void {
		Atomic_Option_Lock::acquire( 't_lock', 'owner1', 30 );
		$this->assertFalse( Atomic_Option_Lock::acquire( 't_lock', 'owner2', 30 ) );
	}

	public function test_renew_and_release(): void {
		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner1', 30 ) );
		$this->assertTrue( Atomic_Option_Lock::renew( 't_lock', 'owner1', 60 ) );
		$this->assertTrue( Atomic_Option_Lock::release( 't_lock', 'owner1' ) );
		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner2', 30 ) );
	}

	public function test_cas_reclaim_exactly_one_winner(): void {
		update_option( 'cas_lock', array(
			'owner'       => 'old',
			'acquired_at' => time() - 120,
			'expires_at'  => time() - 10,
		), false );

		$w1 = Atomic_Option_Lock::acquire( 'cas_lock', 'A', 30 );
		$w2 = Atomic_Option_Lock::acquire( 'cas_lock', 'B', 30 );
		$this->assertSame( 1, (int) $w1 + (int) $w2 );
	}

	public function test_renew_fails_for_non_owner(): void {
		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner1', 30 ) );

		$before = get_option( 't_lock' );

		$this->assertFalse( Atomic_Option_Lock::renew( 't_lock', 'owner2', 60 ) );
		$this->assertSame( $before['expires_at'], get_option( 't_lock' )['expires_at'] );
		$this->assertSame( 'owner1', get_option( 't_lock' )['owner'] );
	}

	public function test_release_fails_for_non_owner(): void {
		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner1', 30 ) );

		$before = get_option( 't_lock' );

		$this->assertFalse( Atomic_Option_Lock::release( 't_lock', 'owner2' ) );
		$this->assertSame( $before, get_option( 't_lock' ) );
		$this->assertSame( 'owner1', get_option( 't_lock' )['owner'] );
	}

	public function test_acquire_reclaims_expired_lock(): void {
		$expired_at = time() - 10;
		update_option( 't_lock', array(
			'owner'       => 'old',
			'acquired_at' => $expired_at - 90,
			'expires_at'  => $expired_at,
		), false );

		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner2', 30 ) );

		$lock = get_option( 't_lock' );
		$this->assertSame( 'owner2', $lock['owner'] );
		$this->assertGreaterThan( time(), $lock['expires_at'] );
		$this->assertNotSame( 'old', $lock['owner'] );
	}
	public function test_renew_fails_for_non_owner(): void {
		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner1', 30 ) );

		$before = get_option( 't_lock' );

		$this->assertFalse( Atomic_Option_Lock::renew( 't_lock', 'owner2', 60 ) );
		$this->assertSame( $before['expires_at'], get_option( 't_lock' )['expires_at'] );
		$this->assertSame( 'owner1', get_option( 't_lock' )['owner'] );
	}

	public function test_release_fails_for_non_owner(): void {
		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner1', 30 ) );

		$before = get_option( 't_lock' );

		$this->assertFalse( Atomic_Option_Lock::release( 't_lock', 'owner2' ) );
		$this->assertSame( $before, get_option( 't_lock' ) );
		$this->assertSame( 'owner1', get_option( 't_lock' )['owner'] );
	}

	public function test_acquire_reclaims_expired_lock(): void {
		$expired_at = time() - 10;
		update_option( 't_lock', array(
			'owner'       => 'old',
			'acquired_at' => $expired_at - 90,
			'expires_at'  => $expired_at,
		), false );

		$this->assertTrue( Atomic_Option_Lock::acquire( 't_lock', 'owner2', 30 ) );

		$lock = get_option( 't_lock' );
		$this->assertSame( 'owner2', $lock['owner'] );
		$this->assertGreaterThan( time(), $lock['expires_at'] );
		$this->assertNotSame( 'old', $lock['owner'] );
	}

}
