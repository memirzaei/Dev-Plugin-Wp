<?php
/**
 * Stub for WooCommerce HPOS FeaturesUtil.
 *
 * @package FormulaPriceSync\Tests
 */

namespace Automattic\WooCommerce\Utilities;

class FeaturesUtil {
	/**
	 * @param string $feature     Feature id.
	 * @param string $plugin_file Plugin main file.
	 * @param bool   $positive    Compatible or not.
	 * @return bool
	 */
	public static function declare_compatibility( $feature, $plugin_file, $positive = true ) {
		$GLOBALS['fps_hpos_declarations'][] = array(
			'feature'  => $feature,
			'plugin'   => $plugin_file,
			'positive' => (bool) $positive,
		);
		return true;
	}
}
