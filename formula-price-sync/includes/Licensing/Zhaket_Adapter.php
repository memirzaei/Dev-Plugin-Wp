<?php
/**
 * Zhaket marketplace license adapter.
 *
 * Contract follows the public Zhaket Guard API: install-license and
 * validation-license endpoints return a JSON object with a successful status.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zhaket_Adapter implements License_Adapter_Interface {

	const API_BASE       = 'https://guard.zhaket.com/api/';
	const REQUEST_TIMEOUT = 15;

	/**
	 * Activate/install a license.
	 *
	 * @param string $license_key License key.
	 * @return array{valid:bool,status:string,message:string,expires_at:int|null}
	 */
	public function activate( string $license_key ): array {
		$product_token = $this->get_product_token();
		if ( '' === $product_token ) {
			return $this->failure( 'not_configured', __( 'توکن محصول ژاکت در محیط سرور تنظیم نشده است.', 'formula-price-sync' ) );
		}

		return $this->request(
			'install-license',
			array(
				'product_token' => $product_token,
				'token'         => $license_key,
				'domain'        => $this->get_host(),
			)
		);
	}

	/**
	 * Validate a license.
	 *
	 * @param string $license_key License key.
	 * @return array{valid:bool,status:string,message:string,expires_at:int|null}
	 */
	public function validate( string $license_key ): array {
		return $this->request(
			'validation-license',
			array(
				'token'  => $license_key,
				'domain' => $this->get_host(),
			)
		);
	}

	/**
	 * Perform a bounded GET request against the fixed Zhaket endpoint.
	 *
	 * @param string $method API method.
	 * @param array  $params Parameters.
	 * @return array{valid:bool,status:string,message:string,expires_at:int|null}
	 */
	private function request( string $method, array $params ): array {
		$url = add_query_arg( $params, self::API_BASE . ltrim( $method, '/' ) );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'httpversion' => '1.1',
				'headers'     => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'FormulaPriceSync/' . ( defined( 'FPS_VERSION' ) ? FPS_VERSION : '2.0.0' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->failure( 'network_error', __( 'اتصال به سرویس لایسنس ژاکت برقرار نشد.', 'formula-price-sync' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return $this->failure( 'http_error', __( 'سرویس لایسنس پاسخ HTTP معتبر برنگرداند.', 'formula-price-sync' ) );
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return $this->failure( 'invalid_response', __( 'پاسخ سرویس لایسنس معتبر نیست.', 'formula-price-sync' ) );
		}

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : '';
		$valid  = 'successful' === $status;
		$message = $this->normalize_message( $data['message'] ?? '' );
		$expires = isset( $data['expires_at'] ) ? absint( $data['expires_at'] ) : 0;

		if ( ! $valid ) {
			return array(
				'valid'      => false,
				'status'     => '' !== $status ? $status : 'invalid',
				'message'    => '' !== $message ? $message : __( 'لایسنس توسط ژاکت تأیید نشد.', 'formula-price-sync' ),
				'expires_at' => $expires > 0 ? $expires : null,
			);
		}

		return array(
			'valid'      => true,
			'status'     => 'valid',
			'message'    => '' !== $message ? $message : __( 'لایسنس با موفقیت تأیید شد.', 'formula-price-sync' ),
			'expires_at' => $expires > 0 ? $expires : null,
		);
	}

	/**
	 * Whether a valid product token is available to the adapter.
	 *
	 * Never returns or logs the token value – only a boolean suitable
	 * for System Health and packaging diagnostics.
	 *
	 * @return bool
	 */
	public static function is_product_token_configured(): bool {
		$token = self::resolve_product_token();
		return '' !== $token;
	}

	/**
	 * Resolve the product token from constant, environment, or filter.
	 * Private – callers that need the value must go through instance methods.
	 *
	 * @return string Empty string when not configured or format is invalid.
	 */
	private static function resolve_product_token(): string {
		$token = '';
		if ( defined( 'FPS_ZHAKET_PRODUCT_TOKEN' ) ) {
			$token = (string) FPS_ZHAKET_PRODUCT_TOKEN;
		} else {
			$env_token = getenv( 'FPS_ZHAKET_PRODUCT_TOKEN' );
			$token     = false !== $env_token ? (string) $env_token : '';
		}
		$token = apply_filters( 'fps_zhaket_product_token', $token );
		return preg_match( '/^[A-Za-z0-9._-]{10,255}$/', (string) $token ) ? (string) $token : '';
	}

	/**
	 * Instance wrapper around the static resolver.
	 *
	 * @return string
	 */
	private function get_product_token(): string {
		return self::resolve_product_token();
	}

	private function get_host(): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		$host = preg_replace( '/^www\./i', '', $host );
		return is_string( $host ) ? trim( $host ) : '';
	}

	private function normalize_message( $message ): string {
		if ( is_string( $message ) ) {
			return sanitize_text_field( $message );
		}
		if ( is_array( $message ) ) {
			$parts = array();
			array_walk_recursive(
				$message,
				static function ( $value ) use ( &$parts ) {
					if ( is_scalar( $value ) ) {
						$parts[] = sanitize_text_field( (string) $value );
					}
				}
			);
			return implode( ' ', array_filter( $parts ) );
		}
		return '';
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
