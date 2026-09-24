<?php
/**
 * Configurable Rastchin marketplace license adapter.
 *
 * Endpoint URLs and product token are server-side configuration.
 * Nothing secret is hard-coded into the plugin package.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rastchin_Adapter implements License_Adapter_Interface {

	const REQUEST_TIMEOUT = 15;

	public function activate( string $license_key ): array {
		$config = self::get_config();
		$url    = isset( $config['activate_url'] ) ? esc_url_raw( (string) $config['activate_url'] ) : '';
		if ( '' === $url ) {
			return $this->failure( 'not_configured', __( 'سرویس لایسنس راست‌چین پیکربندی نشده است.', 'formula-price-sync' ) );
		}

		return $this->request(
			$url,
			array(
				'action'        => 'activate',
				'license_key'   => $license_key,
				'product_token' => (string) ( $config['product_token'] ?? '' ),
				'domain'        => $this->get_host(),
			)
		);
	}

	public function validate( string $license_key ): array {
		$config = self::get_config();
		$url    = isset( $config['validate_url'] ) ? esc_url_raw( (string) $config['validate_url'] ) : '';
		if ( '' === $url ) {
			return $this->failure( 'not_configured', __( 'سرویس لایسنس راست‌چین پیکربندی نشده است.', 'formula-price-sync' ) );
		}

		return $this->request(
			$url,
			array(
				'action'        => 'validate',
				'license_key'   => $license_key,
				'product_token' => (string) ( $config['product_token'] ?? '' ),
				'domain'        => $this->get_host(),
			)
		);
	}

	public static function is_product_token_configured(): bool {
		$config = self::get_config();
		$token  = isset( $config['product_token'] ) ? (string) $config['product_token'] : '';
		return '' !== $token && (bool) preg_match( '/^[A-Za-z0-9._-]{10,255}$/', $token );
	}

	private static function get_config(): array {
		static $config = null;
		if ( null !== $config ) {
			return $config;
		}

		$file = defined( 'FPS_PATH' ) ? FPS_PATH . 'includes/Licensing/license-config.php' : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			return $config = array();
		}

		$loaded = require $file;
		$loaded = is_array( $loaded ) ? $loaded : array();
		return $config = isset( $loaded['rastchin'] ) && is_array( $loaded['rastchin'] ) ? $loaded['rastchin'] : array();
	}

	private function request( string $url, array $params ): array {
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'FormulaPriceSync/' . ( defined( 'FPS_VERSION' ) ? FPS_VERSION : '2.0.0' ),
				),
				'body'        => $params,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( 'network_error', __( 'اتصال به سرویس لایسنس راست‌چین برقرار نشد.', 'formula-price-sync' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return $this->failure( 'http_error', __( 'سرویس لایسنس راست‌چین پاسخ HTTP معتبر برنگرداند.', 'formula-price-sync' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->failure( 'invalid_response', __( 'پاسخ سرویس لایسنس راست‌چین معتبر نیست.', 'formula-price-sync' ) );
		}

		$valid_raw = $data['valid'] ?? null;
		$valid     = true === $valid_raw || 1 === $valid_raw || '1' === $valid_raw || ( is_string( $valid_raw ) && 'true' === strtolower( trim( $valid_raw ) ) );

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
		if ( null === $valid_raw && in_array( $status, array( 'valid', 'active', 'successful', 'success' ), true ) ) {
			$valid = true;
		}

		$message = isset( $data['message'] ) && is_scalar( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : '';
		$expires = isset( $data['expires_at'] ) ? absint( $data['expires_at'] ) : 0;

		return array(
			'valid'      => $valid,
			'status'     => $valid ? 'valid' : ( '' !== $status ? $status : 'invalid' ),
			'message'    => '' !== $message ? $message : ( $valid ? __( 'لایسنس با موفقیت تأیید شد.', 'formula-price-sync' ) : __( 'لایسنس توسط سرویس راست‌چین تأیید نشد.', 'formula-price-sync' ) ),
			'expires_at' => $expires > 0 ? $expires : null,
		);
	}

	private function get_host(): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		$host = preg_replace( '/^www\./i', '', $host );
		return is_string( $host ) ? trim( $host ) : '';
	}

	private function failure( string $status, string $message ): array {
		return array(
			'valid'      => false,
			'status'     => sanitize_key( $status ),
			'message'    => $message,
			'expires_at' => null,
		);
	}
}
