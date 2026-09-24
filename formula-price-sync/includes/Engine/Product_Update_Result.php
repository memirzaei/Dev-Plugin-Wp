<?php
/**
 * Product update outcome contract.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Engine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Product_Update_Result {
	const UPDATED           = 'updated';
	const UNCHANGED         = 'unchanged';
	const LOCKED            = 'locked';
	const DISABLED          = 'disabled';
	const INVALID_RATE      = 'invalid_rate';
	const VALIDATION_ERROR  = 'validation_error';
	const RETRYABLE_ERROR   = 'retryable_error';
	const PERMANENT_ERROR   = 'permanent_error';

	/** @return string[] */
	public static function all(): array {
		return array(
			self::UPDATED,
			self::UNCHANGED,
			self::LOCKED,
			self::DISABLED,
			self::INVALID_RATE,
			self::VALIDATION_ERROR,
			self::RETRYABLE_ERROR,
			self::PERMANENT_ERROR,
		);
	}

	public static function is_retryable( string $status ): bool {
		return self::RETRYABLE_ERROR === $status;
	}
}
