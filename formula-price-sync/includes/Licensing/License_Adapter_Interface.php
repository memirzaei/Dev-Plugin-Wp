<?php
/**
 * License provider contract.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface License_Adapter_Interface {
	/**
	 * Install/activate a license at the marketplace.
	 *
	 * @param string $license_key License key.
	 * @return array{valid:bool,status:string,message:string,expires_at:int|null}
	 */
	public function activate( string $license_key ): array;

	/**
	 * Validate an already installed license.
	 *
	 * @param string $license_key License key.
	 * @return array{valid:bool,status:string,message:string,expires_at:int|null}
	 */
	public function validate( string $license_key ): array;
}
