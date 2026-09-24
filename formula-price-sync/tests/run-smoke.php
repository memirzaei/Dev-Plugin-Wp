<?php
/**
 * Lightweight smoke runner for environments without PHPUnit.
 *
 * Executes the pure-logic assertions that do not require a full
 * PHPUnit installation. Useful for quick CI gates or local checks.
 *
 * Usage: php tests/run-smoke.php
 *
 * @package FormulaPriceSync
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap/bootstrap.php';

// Load production classes via Composer autoload.
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "vendor/autoload.php missing – cannot load plugin classes.\n" );
	exit( 1 );
}
require_once $autoload;

use FormulaPriceSync\Core\Atomic_Option_Lock;
use FormulaPriceSync\Engine\Calculator;
use FormulaPriceSync\Licensing\License_Guard;

$failures = 0;
$passes   = 0;

function smoke_assert( bool $condition, string $message ): void {
	global $failures, $passes;
	if ( $condition ) {
		echo "[PASS] {$message}\n";
		++$passes;
	} else {
		echo "[FAIL] {$message}\n";
		++$failures;
	}
}

echo "=== Formula Price Sync – Smoke Tests ===\n\n";

// ------------------------------------------------------------------
// Atomic_Option_Lock
// ------------------------------------------------------------------
fps_test_reset_state();
$key   = 'smoke_lock';
$owner = 'smoke_owner';

smoke_assert(
	Atomic_Option_Lock::acquire( $key, $owner, 30 ) === true,
	'Atomic_Option_Lock::acquire succeeds on free lock'
);

smoke_assert(
	Atomic_Option_Lock::acquire( $key, 'other_owner', 30 ) === false,
	'Atomic_Option_Lock::acquire fails while lock is held'
);

smoke_assert(
	Atomic_Option_Lock::renew( $key, $owner, 60 ) === true,
	'Atomic_Option_Lock::renew succeeds for owner'
);

smoke_assert(
	Atomic_Option_Lock::release( $key, $owner ) === true,
	'Atomic_Option_Lock::release succeeds for owner'
);

// ------------------------------------------------------------------
// Calculator – gold guild tax rule
// ------------------------------------------------------------------
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

smoke_assert(
	isset( $result['breakdown']['tax_amount'] ) && abs( $result['breakdown']['tax_amount'] - 796_500.0 ) < 1.0,
	'Calculator gold 18k applies tax only on (wage + profit)'
);

smoke_assert(
	Calculator::calculate_price( $meta, 0.0 )['final_price'] === 0.0,
	'Calculator returns 0 for zero rate'
);

smoke_assert(
	Calculator::calculate_price( $meta, -1.0 )['final_price'] === 0.0,
	'Calculator returns 0 for negative rate'
);

// ------------------------------------------------------------------
// License_Guard – fail-closed default
// ------------------------------------------------------------------
fps_test_reset_state();
smoke_assert(
	License_Guard::should_block() === true,
	'License_Guard::should_block() is true by default (fail-closed)'
);

smoke_assert(
	License_Guard::is_valid() === false,
	'License_Guard::is_valid() is false by default'
);

// Masking
update_option( License_Guard::LICENSE_OPTION, 'ABCD1234EFGH5678', false );
$masked = License_Guard::get_license_key( true );
smoke_assert(
	strpos( $masked, '*' ) !== false && $masked !== 'ABCD1234EFGH5678',
	'License key is masked by default'
);

smoke_assert( License_Guard::normalize_boolean( 'true' ) === true, 'License boolean string true normalizes to true' );
smoke_assert( License_Guard::normalize_boolean( 'false' ) === false, 'License boolean string false stays false' );
smoke_assert( \FormulaPriceSync\Licensing\Rastchin_Adapter::is_product_token_configured() === false, 'Rastchin token is absent by default' );

// ------------------------------------------------------------------

// ------------------------------------------------------------------
// Atomic_Option_Lock – CAS concurrency (integration-level)
// ------------------------------------------------------------------
fps_test_reset_state();
$ckey = 'smoke_cas_lock';

// Seed expired lock
update_option($ckey, array(
	'owner' => 'old',
	'acquired_at' => time() - 120,
	'expires_at' => time() - 10,
), false);

$w1 = \FormulaPriceSync\Core\Atomic_Option_Lock::acquire($ckey, 'cas_A', 30);
$w2 = \FormulaPriceSync\Core\Atomic_Option_Lock::acquire($ckey, 'cas_B', 30);
$wins = (int) $w1 + (int) $w2;
smoke_assert($wins === 1, 'CAS reclaim under contention: exactly one winner');

$stored = get_option($ckey);
$winner = $w1 ? 'cas_A' : 'cas_B';
smoke_assert(is_array($stored) && $stored['owner'] === $winner, 'CAS winner owns the lock');

// Stale release must fail
smoke_assert(
	\FormulaPriceSync\Core\Atomic_Option_Lock::release($ckey, 'old') === false,
	'Stale owner cannot release after CAS reclaim'
);

echo "\n=== Summary: {$passes} passed, {$failures} failed ===\n";
exit( $failures > 0 ? 1 : 0 );
