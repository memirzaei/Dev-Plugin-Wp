<?php
/**
 * Circuit Breaker to protect against anomalous rate spikes.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Circuit_Breaker
 *
 * Rejects rate updates that deviate more than 25% from the previously cached rate
 * to prevent pricing disasters.
 */
class Circuit_Breaker {

	/**
	 * Maximum allowed deviation percentage (25%).
	 *
	 * @var float
	 */
	const MAX_DEVIATION_PERCENT = 25.0;

	/**
	 * Option key for the last accepted rates.
	 *
	 * @var string
	 */
	const LAST_ACCEPTED_OPTION = 'fps_last_accepted_rates';

	/**
	 * Transient key for admin warning notice.
	 *
	 * @var string
	 */
	const WARNING_TRANSIENT = 'fps_circuit_breaker_warning';

	/**
	 * Validate new rates against previously accepted rates.
	 *
	 * @param array $new_rates Newly fetched rates.
	 * @return array{accepted: bool, rates: array, message: string}
	 */
	public function validate( array $new_rates ): array {
		$previous = get_option( self::LAST_ACCEPTED_OPTION, array() );

		if ( empty( $previous ) || empty( $new_rates ) ) {
			// First run or empty data – accept and store.
			$this->accept( $new_rates );
			return array(
				'accepted' => true,
				'rates'    => $new_rates,
				'message'  => 'First run or no previous data – rates accepted.',
			);
		}

		$keys_to_check = array( 'usd', 'eur', 'gold_18k', 'gold_24k', 'coin' );
		$rejected_keys = array();

		foreach ( $keys_to_check as $key ) {
			if ( ! isset( $new_rates[ $key ] ) || ! isset( $previous[ $key ] ) ) {
				continue;
			}

			$old_value = (float) $previous[ $key ];
			$new_value = (float) $new_rates[ $key ];

			if ( $old_value <= 0 ) {
				continue;
			}

			$deviation = abs( ( $new_value - $old_value ) / $old_value ) * 100;

			if ( $deviation > self::MAX_DEVIATION_PERCENT ) {
				$rejected_keys[] = sprintf(
					'%s (%.2f%% deviation: %.0f → %.0f)',
					$key,
					$deviation,
					$old_value,
					$new_value
				);
			}
		}

		if ( ! empty( $rejected_keys ) ) {
			$message = sprintf(
				/* translators: %s: list of rejected rate keys */
				__( 'Circuit Breaker activated! Anomalous rate change detected (>25%%): %s. Update rejected to protect store pricing.', 'formula-price-sync' ),
				implode( ', ', $rejected_keys )
			);

			$this->log_error( $message );
			$this->trigger_admin_warning( $message );

			return array(
				'accepted' => false,
				'rates'    => $previous, // Keep previous safe rates.
				'message'  => $message,
			);
		}

		// All rates within safe range – accept.
		$this->accept( $new_rates );

		return array(
			'accepted' => true,
			'rates'    => $new_rates,
			'message'  => 'Rates accepted – within safe deviation threshold.',
		);
	}

	/**
	 * Store accepted rates.
	 *
	 * @param array $rates Rates to store.
	 * @return void
	 */
	private function accept( array $rates ): void {
		if ( ! empty( $rates ) ) {
			update_option( self::LAST_ACCEPTED_OPTION, $rates, false );
		}
	}

	/**
	 * Log circuit breaker rejection.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function log_error( string $message ): void {
		$logger = \FormulaPriceSync\Core\Logger::get_instance();
		$logger->error( $message, array(
			'source' => 'circuit_breaker',
		) );

		// Also store in a transient for admin visibility (last 5 errors).
		$errors   = get_transient( 'fps_circuit_breaker_errors' );
		$errors   = is_array( $errors ) ? $errors : array();
		$errors[] = array(
			'time'    => current_time( 'mysql' ),
			'message' => $message,
		);
		$errors = array_slice( $errors, -5 );
		set_transient( 'fps_circuit_breaker_errors', $errors, DAY_IN_SECONDS );
	}

	/**
	 * Trigger an admin warning notice.
	 *
	 * @param string $message Warning message.
	 * @return void
	 */
	private function trigger_admin_warning( string $message ): void {
		set_transient(
			self::WARNING_TRANSIENT,
			array(
				'message' => $message,
				'time'    => current_time( 'mysql' ),
			),
			HOUR_IN_SECONDS * 6
		);
		do_action( 'fps_circuit_breaker_triggered', array(
			'message' => $message,
			'time'    => current_time( 'mysql' ),
		) );
	}

	/**
	 * Display admin notice if a circuit breaker warning exists.
	 * Should be hooked to admin_notices.
	 *
	 * @return void
	 */
	public static function maybe_show_admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$warning = get_transient( self::WARNING_TRANSIENT );
		if ( ! $warning || empty( $warning['message'] ) ) {
			return;
		}
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong><?php esc_html_e( 'طلا ارز پرو – Circuit Breaker', 'formula-price-sync' ); ?>:</strong>
				<?php echo esc_html( $warning['message'] ); ?>
			</p>
			<p>
				<em>
					<?php
					printf(
						/* translators: %s: timestamp */
						esc_html__( 'Detected at: %s', 'formula-price-sync' ),
						esc_html( $warning['time'] )
					);
					?>
				</em>
			</p>
		</div>
		<?php
	}
}
