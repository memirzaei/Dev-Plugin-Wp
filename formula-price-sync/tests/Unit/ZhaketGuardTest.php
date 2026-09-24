<?php
/**
 * Unit tests for License_Guard (license fail-closed behaviour).
 *
 * @package FormulaPriceSync
 */

declare(strict_types=1);

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Licensing\License_Adapter_Interface;
use FormulaPriceSync\Licensing\License_Guard;
use PHPUnit\Framework\TestCase;

/**
 * Minimal stub adapter for controlled test results.
 */
class StubLicenseAdapter implements License_Adapter_Interface {
	private array $activate_result;
	private array $validate_result;

	public function __construct( array $activate_result = array(), array $validate_result = array() ) {
		$this->activate_result = $activate_result ?: array(
			'valid'      => false,
			'status'     => 'invalid',
			'message'    => 'stub failure',
			'expires_at' => null,
		);
		$this->validate_result = $validate_result ?: $this->activate_result;
	}

	public function activate( string $license_key ): array {
		return $this->activate_result;
	}

	public function validate( string $license_key ): array {
		return $this->validate_result;
	}
}

class ZhaketGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fps_test_reset_state();
		// Ensure no residual filter.
		remove_all_filters( 'fps_license_adapter' );
	}

	protected function tearDown(): void {
		fps_test_reset_state();
		remove_all_filters( 'fps_license_adapter' );
		parent::tearDown();
	}

	/**
	 * Helper: inject a stub adapter via the filter.
	 */
	private function inject_adapter( License_Adapter_Interface $adapter ): void {
		add_filter(
			'fps_license_adapter',
			static function () use ( $adapter ) {
				return $adapter;
			}
		);
	}

	/**
	 * Default state must be invalid → should_block() === true (fail-closed).
	 */
	public function test_default_state_is_invalid_and_blocks(): void {
		$this->assertFalse( License_Guard::is_valid() );
		$this->assertTrue( License_Guard::should_block() );
	}

	/**
	 * Masking must never expose the full key.
	 */
	public function test_license_key_masking(): void {
		$key = 'ABCD1234EFGH5678IJKL';
		update_option( License_Guard::LICENSE_OPTION, $key, false );

		$masked = License_Guard::get_license_key( true );
		$this->assertStringStartsWith( 'ABCD', $masked );
		$this->assertStringEndsWith( 'IJKL', $masked );
		$this->assertStringContainsString( '*', $masked );
		$this->assertNotSame( $key, $masked );

		$full = License_Guard::get_license_key( false );
		$this->assertSame( $key, $full );
	}

	/**
	 * Short keys are fully masked.
	 */
	public function test_short_key_fully_masked(): void {
		update_option( License_Guard::LICENSE_OPTION, 'SHORT', false );
		$masked = License_Guard::get_license_key( true );
		$this->assertSame( '*****', $masked );
	}

	/**
	 * Successful activation via a valid adapter result.
	 */
	public function test_activate_succeeds_with_valid_adapter_response(): void {
		$adapter = new StubLicenseAdapter(
			array(
				'valid'      => true,
				'status'     => 'valid',
				'message'    => 'OK',
				'expires_at' => time() + 86400 * 30,
			)
		);
		$this->inject_adapter( $adapter );

		$result = License_Guard::activate( 'VALID-LICENSE-KEY-123456' );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( License_Guard::is_valid() );
		$this->assertFalse( License_Guard::should_block() );
		$this->assertSame( 'valid', get_option( License_Guard::STATUS_OPTION ) );
	}

	/**
	 * Invalid adapter response must leave the guard in invalid state (fail-closed).
	 */
	public function test_activate_fails_closed_on_invalid_adapter_response(): void {
		$adapter = new StubLicenseAdapter(
			array(
				'valid'      => false,
				'status'     => 'invalid',
				'message'    => 'License rejected by server',
				'expires_at' => null,
			)
		);
		$this->inject_adapter( $adapter );

		$result = License_Guard::activate( 'BAD-LICENSE-KEY-999999' );

		$this->assertFalse( $result['success'] );
		$this->assertFalse( License_Guard::is_valid() );
		$this->assertTrue( License_Guard::should_block() );
		$this->assertSame( 'invalid', get_option( License_Guard::STATUS_OPTION ) );
	}

	/**
	 * Empty or too-short key must fail closed.
	 */
	public function test_activate_rejects_short_or_empty_key(): void {
		$result = License_Guard::activate( 'short' );
		$this->assertFalse( $result['success'] );
		$this->assertTrue( License_Guard::should_block() );

		$result2 = License_Guard::activate( '' );
		$this->assertFalse( $result2['success'] );
		$this->assertTrue( License_Guard::should_block() );
	}

	/**
	 * Rate-limit: after ACTIVATION_LIMIT attempts the next must be rejected.
	 */
	public function test_activation_rate_limit(): void {
		$adapter = new StubLicenseAdapter(
			array(
				'valid'      => false,
				'status'     => 'invalid',
				'message'    => 'rejected',
				'expires_at' => null,
			)
		);
		$this->inject_adapter( $adapter );

		$limit = License_Guard::ACTIVATION_LIMIT;

		for ( $i = 0; $i < $limit; $i++ ) {
			License_Guard::activate( 'ATTEMPT-KEY-' . str_pad( (string) $i, 12, '0' ) );
		}

		// Next attempt must be rate-limited.
		$result = License_Guard::activate( 'ATTEMPT-KEY-OVERFLOW' );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'تلاش', $result['message'] );
	}

	/**
	 * Revalidate with a valid stored key and successful adapter response.
	 */
	public function test_revalidate_success(): void {
		$adapter = new StubLicenseAdapter(
			array(), // activate unused
			array(
				'valid'      => true,
				'status'     => 'valid',
				'message'    => 'still valid',
				'expires_at' => time() + 86400,
			)
		);
		$this->inject_adapter( $adapter );

		update_option( License_Guard::LICENSE_OPTION, 'STORED-VALID-KEY-ABCDEF', false );
		update_option( License_Guard::STATUS_OPTION, 'valid', false );

		$ok = License_Guard::revalidate();
		$this->assertTrue( $ok );
		$this->assertTrue( License_Guard::is_valid() );
	}

	/**
	 * Revalidate must fail-closed when adapter rejects the key.
	 */
	public function test_revalidate_fails_closed(): void {
		$adapter = new StubLicenseAdapter(
			array(),
			array(
				'valid'      => false,
				'status'     => 'invalid',
				'message'    => 'expired or revoked',
				'expires_at' => null,
			)
		);
		$this->inject_adapter( $adapter );

		update_option( License_Guard::LICENSE_OPTION, 'STORED-KEY-TO-REJECT', false );
		update_option( License_Guard::STATUS_OPTION, 'valid', false );
		set_transient(
			License_Guard::VALIDATION_TRANSIENT,
			array( 'valid' => true, 'time' => time(), 'expires_at' => time() + 3600 ),
			3600
		);

		$ok = License_Guard::revalidate();
		$this->assertFalse( $ok );
		$this->assertFalse( License_Guard::is_valid() );
		$this->assertTrue( License_Guard::should_block() );
	}

	/**
	 * FPS_DEV_MODE forces invalid (even if options say valid).
	 * We cannot redefine a constant if already defined, so we only
	 * assert the documented behaviour when the constant is absent.
	 */
	public function test_boolean_normalization_is_explicit(): void {
		$this->assertTrue( License_Guard::normalize_boolean( true ) );
		$this->assertTrue( License_Guard::normalize_boolean( 1 ) );
		$this->assertTrue( License_Guard::normalize_boolean( '1' ) );
		$this->assertTrue( License_Guard::normalize_boolean( 'true' ) );
		$this->assertFalse( License_Guard::normalize_boolean( false ) );
		$this->assertFalse( License_Guard::normalize_boolean( 0 ) );
		$this->assertFalse( License_Guard::normalize_boolean( '0' ) );
		$this->assertFalse( License_Guard::normalize_boolean( 'false' ) );
		$this->assertFalse( License_Guard::normalize_boolean( 'yes' ) );
	}

	public function test_is_valid_respects_status_and_transient(): void {
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

		$this->assertTrue( License_Guard::is_valid() );
		$this->assertFalse( License_Guard::should_block() );
	}
}

// Filter helpers are provided by tests/bootstrap/bootstrap.php.
