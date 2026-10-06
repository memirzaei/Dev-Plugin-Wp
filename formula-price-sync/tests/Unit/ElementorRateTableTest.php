<?php
/**
 * Unit tests for the optional Elementor Rate Table widget.
 *
 * @package FormulaPriceSync
 */

declare(strict_types=1);

namespace FormulaPriceSync\Tests\Unit;

use FormulaPriceSync\Widgets\Elementor_Rate_Table;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/Elementor.php';
require_once __DIR__ . '/../../includes/Widgets/Elementor_Rate_Table.php';

/**
 * Verifies safe, read-only frontend rate presentation.
 */
final class ElementorRateTableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		fps_test_reset_state();
	}

	protected function tearDown(): void {
		fps_test_reset_state();
		parent::tearDown();
	}

	private function valid_license(): void {
		update_option( 'fps_license_status', 'valid', false );
		set_transient(
			'fps_license_validation',
			array(
				'valid'      => true,
				'time'       => time(),
				'expires_at' => time() + 3600,
			),
			3600
		);
	}

	private function render( array $settings ): string {
		$widget = new Elementor_Rate_Table();
		$widget->set_test_settings( $settings );
		$method = new \ReflectionMethod( $widget, 'render' );
		$method->setAccessible( true );
		ob_start();
		$method->invoke( $widget );
		return (string) ob_get_clean();
	}

	public function test_selected_rates_render_in_repeater_order_from_cache(): void {
		$this->valid_license();
		set_transient(
			'fps_rates_cache',
			array(
				'usd'       => 100000,
				'eur'       => 110000,
				'gold_18k'  => 5000000,
				'gold_24k'  => 6000000,
				'coin'      => 70000000,
				'source'    => 'tgju',
				'timestamp' => 1760000000,
			),
			3600
		);

		$html = $this->render(
			array(
				'title'            => 'آخرین نرخ‌ها',
				'rates'            => array(
					array( 'rate_key' => 'gold_18k' ),
					array( 'rate_key' => 'usd' ),
				),
				'show_source'      => 'yes',
				'show_last_update' => 'yes',
				'show_change'      => '',
				'dark_mode'        => '',
			)
		);

		$this->assertStringContainsString( 'fps-rate-table', $html );
		$this->assertStringContainsString( 'طلای ۱۸ عیار', $html );
		$this->assertStringContainsString( 'دلار', $html );
		$this->assertStringContainsString( '۵٬۰۰۰٬۰۰۰', $html );
		$this->assertStringContainsString( '۱۰۰٬۰۰۰', $html );
		$this->assertStringContainsString( 'TGJU', $html );
	}

	public function test_falls_back_to_existing_accepted_rates_without_provider_fetch(): void {
		$this->valid_license();
		update_option(
			'fps_last_accepted_rates',
			array(
				'usd'       => 200000,
				'eur'       => 210000,
				'gold_18k'  => 8000000,
				'gold_24k'  => 9000000,
				'coin'      => 90000000,
				'source'    => 'manual',
				'timestamp' => 1760000100,
			),
			false
		);

		$html = $this->render(
			array(
				'title'            => 'نرخ',
				'rates'            => array( array( 'rate_key' => 'usd' ) ),
				'show_source'      => 'yes',
				'show_last_update' => 'yes',
				'show_change'      => '',
				'dark_mode'        => '',
			)
		);

		$this->assertStringContainsString( '۲۰۰٬۰۰۰', $html );
		$this->assertStringContainsString( 'دستی', $html );
	}

	public function test_missing_selected_rate_degrades_gracefully(): void {
		$this->valid_license();
		set_transient(
			'fps_rates_cache',
			array(
				'usd'       => 100000,
				'source'    => 'tgju',
				'timestamp' => time(),
			),
			3600
		);

		$html = $this->render(
			array(
				'title'            => '',
				'rates'            => array( array( 'rate_key' => 'eur' ) ),
				'show_source'      => 'yes',
				'show_last_update' => 'yes',
				'show_change'      => '',
				'dark_mode'        => '',
			)
		);

		$this->assertStringContainsString( 'fps-rate-table__empty', $html );
		$this->assertStringContainsString( 'نرخ معتبری برای نمایش', $html );
	}

	public function test_blocked_license_never_exposes_rates(): void {
		set_transient(
			'fps_rates_cache',
			array(
				'usd'       => 123456789,
				'eur'       => 987654321,
				'gold_18k'  => 999999999,
				'gold_24k'  => 999999998,
				'coin'      => 777777777,
				'source'    => 'tgju',
				'timestamp' => time(),
			),
			3600
		);

		$html = $this->render(
			array(
				'rates' => array( array( 'rate_key' => 'usd' ) ),
			)
		);

		$this->assertStringContainsString( 'fps-rate-table--blocked', $html );
		$this->assertStringContainsString( 'لایسنس معتبر', $html );
		$this->assertStringNotContainsString( '۱۲۳٬۴۵۶٬۷۸۹', $html );
		$this->assertStringNotContainsString( 'TGJU', $html );
	}

	public function test_optional_change_is_rendered_only_when_snapshot_provides_it(): void {
		$this->valid_license();
		set_transient(
			'fps_rates_cache',
			array(
				'usd'                => 100000,
				'usd_change_percent' => 1.2345,
				'source'             => 'tgju',
				'timestamp'          => time(),
			),
			3600
		);

		$html = $this->render(
			array(
				'rates'       => array( array( 'rate_key' => 'usd' ) ),
				'show_change' => 'yes',
			)
		);

		$this->assertStringContainsString( '1.23%', $html );
	}
}
