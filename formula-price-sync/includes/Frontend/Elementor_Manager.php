<?php
/**
 * Optional Elementor integration bootstrap.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Frontend;

use FormulaPriceSync\Widgets\Elementor_Rate_Table;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers RateMatic's optional Elementor integration.
 */
final class Elementor_Manager {

	/**
	 * Elementor widget style handle.
	 */
	const STYLE_HANDLE = 'fps-elementor-rate-table';

	/**
	 * Initialize the optional Elementor integration.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( function_exists( 'wp_register_style' ) && defined( 'FPS_URL' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				FPS_URL . 'assets/css/frontend.css',
				array(),
				defined( 'FPS_VERSION' ) ? FPS_VERSION : null
			);
		}

		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );
	}

	/**
	 * Register RateMatic Elementor widgets.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public static function register_widgets( $widgets_manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		$widgets_manager->register( new Elementor_Rate_Table() );
	}
}
