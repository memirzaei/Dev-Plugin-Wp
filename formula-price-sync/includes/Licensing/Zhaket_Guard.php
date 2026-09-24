<?php
/**
 * Backward-compatible alias for the generic license guard.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @deprecated Use License_Guard.
 */
class Zhaket_Guard extends License_Guard {
}
