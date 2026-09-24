<?php
/**
 * Notification integrations for Formula Price Sync.
 *
 * Sends alerts via Telegram Bot API and Iranian SMS gateways (Kavenegar, FarazSMS)
 * when circuit breaker triggers or other events occur.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Integrations;

use FormulaPriceSync\API\Circuit_Breaker;
use FormulaPriceSync\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notifier {

	const OPTION_TELEGRAM_BOT_TOKEN = 'fps_telegram_bot_token';
	const OPTION_TELEGRAM_CHAT_ID   = 'fps_telegram_chat_id';
	const OPTION_SMS_PROVIDER       = 'fps_sms_provider';
	const OPTION_SMS_API_KEY        = 'fps_sms_api_key';
	const OPTION_SMS_LINE_NUMBER    = 'fps_sms_line_number';
	const OPTION_SMS_RECIPIENT      = 'fps_sms_recipient';

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'fps_circuit_breaker_triggered', array( self::class, 'send_alert_on_circuit_breaker' ), 10, 1 );
		add_action( 'fps_all_providers_failed', array( self::class, 'send_provider_failure_alert' ), 10, 2 );
		add_action( 'fps_queue_run_completed', array( self::class, 'send_queue_run_completed_alert' ), 10, 4 );
	}

	/**
	 * Register notification settings in the Settings API.
	 */
	public static function register_settings() {
		register_setting( 'fps_options_group', self::OPTION_TELEGRAM_BOT_TOKEN, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_TELEGRAM_CHAT_ID, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_PROVIDER, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_API_KEY, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_LINE_NUMBER, 'sanitize_text_field' );
		register_setting( 'fps_options_group', self::OPTION_SMS_RECIPIENT, 'sanitize_text_field' );

		add_settings_section(
			'fps_section_notifications',
			esc_html__( 'اعلان‌ها (Telegram / SMS)', 'formula-price-sync' ),
			array( self::class, 'section_description' ),
			'formula-price-sync'
		);

		add_settings_field(
			'fps_telegram_bot_token',
			esc_html__( 'توکن ربات تلگرام', 'formula-price-sync' ),
			array( self::class, 'telegram_bot_token_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_telegram_chat_id',
			esc_html__( 'شناسه چت تلگرام', 'formula-price-sync' ),
			array( self::class, 'telegram_chat_id_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_sms_provider',
			esc_html__( 'پاراسیور پیامک', 'formula-price-sync' ),
			array( self::class, 'sms_provider_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_sms_api_key',
			esc_html__( 'کلید API پیامک', 'formula-price-sync' ),
			array( self::class, 'sms_api_key_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_sms_line_number',
			esc_html__( 'شماره خط ارسال (فرازاس‌ام‌اس)', 'formula-price-sync' ),
			array( self::class, 'sms_line_number_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);

		add_settings_field(
			'fps_sms_recipient',
			esc_html__( 'شماره گیرنده پیامک', 'formula-price-sync' ),
			array( self::class, 'sms_recipient_field_cb' ),
			'formula-price-sync',
			'fps_section_notifications'
		);
	}

	/**
	 * Section description.
	 */
	public static function section_description() {
		echo '<p class="fps-desc">' . esc_html__( 'تنظیمات اعلان‌ها برای دریافت هشدار از طریق تلگرام یا پیامک وقتی فیوز امنیتی (Circuit Breaker) فعال شود.', 'formula-price-sync' ) . '</p>';
	}

	/* ============ Field Callbacks ============ */

	public static function telegram_bot_token_field_cb() {
		$token = get_option( self::OPTION_TELEGRAM_BOT_TOKEN, '' );
		echo '<input type="password" name="' . esc_attr( self::OPTION_TELEGRAM_BOT_TOKEN ) . '" ' .
			'value="' . esc_attr( $token ) . '" ' .
			'class="regular-text" ' .
			'placeholder="' . esc_attr__( '123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11', 'formula-price-sync' ) . '" ' .
			'autocomplete="off">';
		echo '<p class="fps-desc">' . esc_html__( 'توکن ربات تلگرام را از @BotFather دریافت کنید.', 'formula-price-sync' ) . '</p>';
	}

	public static function telegram_chat_id_field_cb() {
		$chat_id = get_option( self::OPTION_TELEGRAM_CHAT_ID, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_TELEGRAM_CHAT_ID ) . '" ' .
			'value="' . esc_attr( $chat_id ) . '" ' .
			'class="regular-text" ' .
			'placeholder="' . esc_attr__( '123456789 یا @channel', 'formula-price-sync' ) . '" ' .
			'autocomplete="off">';
		echo '<p class="fps-desc">' . esc_html__( 'شناسه چت یا کانال تلگرامی که اعلان‌ها باید به آن فرستاده شوند.', 'formula-price-sync' ) . '</p>';
	}

	public static function sms_provider_field_cb() {
		$provider = get_option( self::OPTION_SMS_PROVIDER, 'kavenegar' );
		echo '<select name="' . esc_attr( self::OPTION_SMS_PROVIDER ) . '">';
		echo '<option value="kavenegar"' . selected( $provider, 'kavenegar', false ) . '>' . esc_html__( 'کاوه‌نگار', 'formula-price-sync' ) . '</option>';
		echo '<option value="farazsms"' . selected( $provider, 'farazsms', false ) . '>' . esc_html__( 'فرازاس‌ام‌اس', 'formula-price-sync' ) . '</option>';
		echo '</select>';
		echo '<p class="fps-desc">' . esc_html__( 'پاراسیور پیامک مورد نظر را انتخاب کنید.', 'formula-price-sync' ) . '</p>';
	}

	public static function sms_api_key_field_cb() {
		$api_key = get_option( self::OPTION_SMS_API_KEY, '' );
		echo '<input type="password" name="' . esc_attr( self::OPTION_SMS_API_KEY ) . '" ' .
			'value="' . esc_attr( $api_key ) . '" ' .
			'class="regular-text" ' .
			'placeholder="' . esc_attr__( 'کلید API سرویس پیامک', 'formula-price-sync' ) . '" ' .
			'autocomplete="off">';
		echo '<p class="fps-desc">' . esc_html__( 'کلید API را از پنل سرویس پیامک خود دریافت کنید.', 'formula-price-sync' ) . '</p>';
	}

	public static function sms_line_number_field_cb() {
		$line_number = get_option( self::OPTION_SMS_LINE_NUMBER, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_SMS_LINE_NUMBER ) . '" ' .
			'value="' . esc_attr( $line_number ) . '" ' .
			'class="regular-text" ' .
			'placeholder="' . esc_attr__( 'شماره خط ارسال (مثلاً 3000XXXX)', 'formula-price-sync' ) . '" ' .
			'autocomplete="off">';
		echo '<p class="fps-desc">' . esc_html__( 'فقط برای فرازاس‌ام‌اس مورد استفاده قرار می‌گیرد.', 'formula-price-sync' ) . '</p>';
	}

	public static function sms_recipient_field_cb() {
		$recipient = get_option( self::OPTION_SMS_RECIPIENT, '' );
		echo '<input type="text" name="' . esc_attr( self::OPTION_SMS_RECIPIENT ) . '" ' .
			'value="' . esc_attr( $recipient ) . '" ' .
			'class="regular-text" ' .
			'placeholder="' . esc_attr__( 'شماره موبایل مدیر (مثلاً 09123456789)', 'formula-price-sync' ) . '" ' .
			'autocomplete="off">';
		echo '<p class="fps-desc">' . esc_html__( 'شماره موبایلی که اعلان‌های SMS باید به آن فرستاده شوند.', 'formula-price-sync' ) . '</p>';
	}

	/* ============ Notification Methods ============ */

	/**
	 * Central entry point for all notification events.
	 *
	 * Applies fps_notifier_should_send filter, then dispatches to Telegram and/or SMS.
	 *
	 * @param string $message Plain-text message body.
	 * @param string $event   Event slug (e.g. circuit_breaker, all_providers_failed, bulk_sync).
	 * @return void
	 */
	public static function dispatch( string $message, string $event = 'generic' ): void {
		/**
		 * Filter whether a notification should be sent.
		 *
		 * @param bool   $should_send Default true.
		 * @param string $message     Message body.
		 * @param string $event       Event slug.
		 */
		$should_send = apply_filters( 'fps_notifier_should_send', true, $message, $event );
		if ( ! $should_send ) {
			return;
		}

		$telegram_configured = ! empty( get_option( self::OPTION_TELEGRAM_BOT_TOKEN, '' ) )
			&& ! empty( get_option( self::OPTION_TELEGRAM_CHAT_ID, '' ) );
		$sms_configured = ! empty( get_option( self::OPTION_SMS_API_KEY, '' ) )
			&& ! empty( get_option( self::OPTION_SMS_RECIPIENT, '' ) );

		if ( ! $telegram_configured && ! $sms_configured ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$timestamp = current_time( 'Y/m/d H:i:s' );

		$telegram_message = '<b>' . esc_html( $site_name ) . '</b> — ' . esc_html( $timestamp ) . '<br><br>' . esc_html( $message );
		$sms_message      = $site_name . ' — ' . $timestamp . "\n\n" . $message;

		if ( $telegram_configured ) {
			$result = self::send_telegram( $telegram_message );
			if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::get_instance()->error( '[FPS Notifier] Telegram failed: ' . $result->get_error_message() );
			}
		}

		if ( $sms_configured ) {
			$result = self::send_sms( $sms_message );
			if ( is_wp_error( $result ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				Logger::get_instance()->error( '[FPS Notifier] SMS failed: ' . $result->get_error_message() );
			}
		}
	}

	/**
	 * Shared HTTP POST helper for Telegram / SMS gateways.
	 *
	 * @param string               $url     Request URL.
	 * @param array|string         $body    Request body (array for form, string for JSON).
	 * @param array                $headers Optional extra headers.
	 * @param string               $error_prefix Prefix for WP_Error codes.
	 * @return array|\WP_Error Decoded response body on success, WP_Error on failure.
	 */
	private static function dispatch_http_request( string $url, $body, array $headers = array(), string $error_prefix = 'fps_http' ) {
		// Outbound notifications must stay on fixed HTTPS notification hosts (SSRF guard).
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new \WP_Error( $error_prefix . '_unsafe_url', 'Unsafe notification URL blocked.' );
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allowed_hosts = apply_filters(
			'fps_allowed_notification_hosts',
			array(
				'api.telegram.org',
				'api.kavenegar.com',
				'api.farazsms.com',
			)
		);
		$allowed_hosts = array_map( 'strtolower', array_filter( (array) $allowed_hosts, 'is_string' ) );
		if ( '' === $host || ! in_array( $host, $allowed_hosts, true ) ) {
			return new \WP_Error( $error_prefix . '_unsafe_host', 'Notification host is not allowlisted.' );
		}

		$args = array(
			'body'               => $body,
			'timeout'            => 15,
			'httpversion'        => '1.1',
			'redirection'        => 2,
			'reject_unsafe_urls' => true,
		);
		if ( ! empty( $headers ) ) {
			$args['headers'] = $headers;
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				$error_prefix . '_request_failed',
				$response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				$error_prefix . '_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					esc_html__( 'خطای HTTP: %d', 'formula-price-sync' ),
					$code
				)
			);
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				$error_prefix . '_invalid_response',
				esc_html__( 'پاسخ نامعتبر از سرویس', 'formula-price-sync' )
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Send a message via Telegram Bot API.
	 *
	 * @param string $message Message text (HTML allowed when parse_mode=HTML).
	 * @return array|\WP_Error
	 */
	public static function send_telegram( string $message ) {
		$bot_token = get_option( self::OPTION_TELEGRAM_BOT_TOKEN, '' );
		$chat_id   = get_option( self::OPTION_TELEGRAM_CHAT_ID, '' );

		if ( empty( $bot_token ) || empty( $chat_id ) ) {
			return new \WP_Error(
				'fps_telegram_not_configured',
				esc_html__( 'تلگرام تنظیم نشده است.', 'formula-price-sync' )
			);
		}

		$url  = 'https://api.telegram.org/bot' . rawurlencode( $bot_token ) . '/sendMessage';
		$data = array(
			'chat_id'    => $chat_id,
			'text'       => $message,
			'parse_mode' => 'HTML',
		);

		$result = self::dispatch_http_request( $url, $data, array(), 'fps_telegram' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['ok'] ) && true === $result['ok'] ) {
			return array( 'success' => true );
		}

		$description = ! empty( $result['description'] ) ? $result['description'] : esc_html__( 'خطای نامعتبر', 'formula-price-sync' );
		return new \WP_Error( 'fps_telegram_api_error', $description );
	}

	/**
	 * Send a message via Kavenegar SMS API.
	 *
	 * @param string $message Message text.
	 * @param string $to      Recipient mobile number.
	 * @return array|\WP_Error
	 */
	public static function send_sms_kavenegar( string $message, string $to ) {
		$api_key = get_option( self::OPTION_SMS_API_KEY, '' );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'fps_sms_not_configured',
				esc_html__( 'سرویس پیامک تنظیم نشده است.', 'formula-price-sync' )
			);
		}

		if ( empty( $to ) ) {
			return new \WP_Error(
				'fps_sms_no_recipient',
				esc_html__( 'شماره گیرنده پیامک مشخص نیست.', 'formula-price-sync' )
			);
		}

		$url  = 'https://api.kavenegar.com/v1/' . rawurlencode( $api_key ) . '/send/sms.json';
		$data = array(
			'receptor' => $to,
			'message'  => $message,
		);

		$result = self::dispatch_http_request( $url, $data, array(), 'fps_sms_kavenegar' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['return'] ) && is_array( $result['return'] ) ) {
			$entry = reset( $result['return'] );
			if ( ! empty( $entry['status'] ) && 10 === (int) $entry['status'] ) {
				return array( 'success' => true );
			}
		}

		$api_message = ! empty( $result['message'] ) ? $result['message'] : esc_html__( 'خطای نامعتبر', 'formula-price-sync' );
		return new \WP_Error( 'fps_sms_kavenegar_api_error', $api_message );
	}

	/**
	 * Send a message via FarazSMS API.
	 *
	 * @param string $message Message text.
	 * @param string $to      Recipient mobile number.
	 * @return array|\WP_Error
	 */
	public static function send_sms_farazsms( string $message, string $to ) {
		$api_key  = get_option( self::OPTION_SMS_API_KEY, '' );
		$line_num = get_option( self::OPTION_SMS_LINE_NUMBER, '' );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'fps_sms_not_configured',
				esc_html__( 'سرویس پیامک تنظیم نشده است.', 'formula-price-sync' )
			);
		}

		if ( empty( $to ) ) {
			return new \WP_Error(
				'fps_sms_no_recipient',
				esc_html__( 'شماره گیرنده پیامک مشخص نیست.', 'formula-price-sync' )
			);
		}

		if ( empty( $line_num ) ) {
			return new \WP_Error(
				'fps_sms_missing_line_number',
				esc_html__( 'شماره خط ارسال برای فرازاس‌ام‌اس تنظیم نشده است.', 'formula-price-sync' )
			);
		}

		$url  = 'https://api.farazsms.com/v1/send';
		$data = array(
			'api_key'  => $api_key,
			'line'     => $line_num,
			'to'       => $to,
			'message'  => $message,
			'local_id' => uniqid( 'fps_', true ),
		);

		$result = self::dispatch_http_request(
			$url,
			wp_json_encode( $data ),
			array( 'Content-Type' => 'application/json' ),
			'fps_sms_farazsms'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['status'] ) && 1 === (int) $result['status'] ) {
			return array( 'success' => true );
		}

		$api_message = ! empty( $result['message'] ) ? $result['message'] : esc_html__( 'خطای نامعتبر', 'formula-price-sync' );
		return new \WP_Error( 'fps_sms_farazsms_api_error', $api_message );
	}

	/**
	 * Send an SMS using the configured provider.
	 *
	 * @param string $message Message text.
	 * @return array|\WP_Error
	 */
	public static function send_sms( string $message ) {
		$provider = get_option( self::OPTION_SMS_PROVIDER, 'kavenegar' );
		$to       = get_option( self::OPTION_SMS_RECIPIENT, '' );

		switch ( $provider ) {
			case 'kavenegar':
				return self::send_sms_kavenegar( $message, $to );
			case 'farazsms':
				return self::send_sms_farazsms( $message, $to );
			default:
				return new \WP_Error(
					'fps_sms_unknown_provider',
					esc_html__( 'پاراسیور پیامک نامعتبر است.', 'formula-price-sync' )
				);
		}
	}

	/* ============ Event Handlers ============ */

	/**
	 * Send alert when circuit breaker is triggered.
	 *
	 * @param string $message The circuit breaker message.
	 * @param array  $rates   The rates that were rejected.
	 */
	public static function send_alert_on_circuit_breaker( array $payload ) {
		$message = isset( $payload['message'] ) ? (string) $payload['message'] : __( 'Rate validation rejected an update.', 'formula-price-sync' );
		$text = '⚠️ ' . __( 'Circuit Breaker', 'formula-price-sync' ) . ': ' . $message;
		self::dispatch( $text, 'circuit_breaker' );
	}

	/**
	 * Send alert when all API providers have failed.
	 *
	 * @param string[] $failed_providers List of provider short names that failed.
	 * @param string   $last_error       Optional last error detail.
	 * @return void
	 */
	public static function send_provider_failure_alert( $failed_providers = array(), string $last_error = '' ): void {
		if ( ! is_array( $failed_providers ) ) {
			$failed_providers = array();
		}

		$provider_list = ! empty( $failed_providers )
			? implode( ', ', array_map( 'strval', $failed_providers ) )
			: __( 'همه پرووایدرها', 'formula-price-sync' );

		$timestamp = current_time( 'Y/m/d H:i:s' );
		$count     = count( $failed_providers );

		$message_text = sprintf(
			/* translators: 1: timestamp, 2: count, 3: provider list, 4: optional error */
			__( 'قطع اتصال API — زمان: %1$s | تعداد پرووایدر شکست‌خورده: %2$d (%3$s). لطفاً نرخ‌ها را به‌صورت دستی بررسی کنید.%4$s', 'formula-price-sync' ),
			$timestamp,
			$count > 0 ? $count : 4,
			$provider_list,
			$last_error ? ' ' . self::redact( $last_error ) : ''
		);

		self::dispatch( $message_text, 'all_providers_failed' );
	}

	/**
	 * Send a brief notification when a bulk sync chunk finishes.
	 *
	 * Only fires on manual triggers and only after the LAST chunk in a run.
	 *
	 * @param array  $product_ids  Processed IDs.
	 * @param int    $updated      Number actually updated.
	 * @param string $trigger_type Trigger type.
	 * @return void
	 */
	public static function send_bulk_sync_complete_alert( array $product_ids, int $updated, string $trigger_type ): void {
		// Backward-compatible entry point. New queue completion is emitted once per run.
		if ( 'manual' === $trigger_type ) {
			self::dispatch(
			sprintf( __( '✅ همگام‌سازی دسته‌ای کامل شد — %d محصول به‌روزرسانی شد.', 'formula-price-sync' ), $updated ),
			'bulk_sync'
		);
		}
	}

	/** Send exactly one completion alert for a completed queue run. */
	public static function send_queue_run_completed_alert( string $run_id, int $total_chunks, int $updated_total, string $trigger_type = 'scheduled' ): void {
		if ( 'manual' !== $trigger_type ) {
			return;
		}

		self::dispatch(
			sprintf(
				__( '✅ همگام‌سازی دسته‌ای کامل شد — %d محصول به‌روزرسانی شد (%d بخش).', 'formula-price-sync' ),
				$updated_total,
				$total_chunks
			),
			'bulk_sync'
		);
	}
	/* ============ Helpers ============ */

	/** Redact common credential-bearing values before logging or notifying. */
	private static function redact( string $value ): string {
		$value = preg_replace( '/(api[_-]?key|token|secret|authorization|password|bot)[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $value );
		return is_string( $value ) ? substr( $value, 0, 500 ) : '';
	}

	/**
	 * Get the last circuit breaker warning message if any.
	 *
	 * @return string|null
	 */
	public static function get_last_warning_message() {
		$warning = get_transient( Circuit_Breaker::WARNING_TRANSIENT );
		if ( ! $warning || empty( $warning['message'] ) ) {
			return null;
		}
		return $warning['message'];
	}
}
