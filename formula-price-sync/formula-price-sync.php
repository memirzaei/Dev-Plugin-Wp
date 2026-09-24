<?php
/**
 * Plugin Name: Formula Price Sync / طلا ارز پرو
 * Plugin URI:  https://webgraphx.ir
 * Description: قیمت‌گذاری خودکار محصولات ووکامرس بر اساس نرخ ارز، طلا و فرمول‌های سفارشی – با پشتیبانی از محصولات ساده و متغیر، Circuit Breaker و لاگ تغییرات قیمت.
 * Version:     2.0.0
 * Author:      WebGraphx
 * Author URI:  https://webgraphx.ir
 * Text Domain: formula-price-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package FormulaPriceSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FPS_VERSION' ) ) {
	define( 'FPS_VERSION', '2.0.0' );
}
define( 'FPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FPS_URL', plugin_dir_url( __FILE__ ) );
define( 'FPS_BASENAME', plugin_basename( __FILE__ ) );
define( 'FPS_FILE', __FILE__ );

/*
 * Autoloader guard: refuse to boot when Composer dependencies are missing.
 * This prevents a white-screen fatal if the plugin is uploaded without
 * running `composer install` (or the release ZIP is broken).
 */
$fps_autoload = FPS_PATH . 'vendor/autoload.php';
if ( ! file_exists( $fps_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'طلا ارز پرو: فایل vendor/autoload.php یافت نشد. لطفاً composer install را اجرا کنید یا از بسته انتشار رسمی استفاده کنید.',
				'formula-price-sync'
			);
			echo '</p></div>';
		}
	);
	return;
}
require_once $fps_autoload;

/*
 * Activation / deactivation lifecycle. Registered before plugins_loaded
 * because activation fires while this file is loaded for the first time.
 */
register_activation_hook( __FILE__, array( \FormulaPriceSync\Core\DB_Installer::class, 'install' ) );
register_deactivation_hook( __FILE__, array( \FormulaPriceSync\Core\DB_Installer::class, 'deactivate' ) );

/*
 * Declare WooCommerce feature compatibility (HPOS / Custom Order Tables).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/*
 * Bootstrap on plugins_loaded (priority 20) so WooCommerce is already
 * registered when we probe for it below.
 *
 * Initialisation order matters:
 *   1. License guard (its state is read by later components)
 *   2. Infrastructure hooks  → must ALWAYS register (see note in fps_init)
 *   3. Admin UI + core services
 *   4. Business-logic features gated by license
 */
add_action( 'plugins_loaded', 'fps_init', 20 );

/**
 * Main plugin bootstrap.
 *
 * @return void
 */
function fps_init() {
	load_plugin_textdomain( 'formula-price-sync', false, dirname( FPS_BASENAME ) . '/languages' );

	// Contract R02: never fatal when WooCommerce is absent.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'fps_woocommerce_missing_notice' );
		return;
	}

	// --- 1. Licensing -------------------------------------------------------
	// Registered first because other components read its state.
	\FormulaPriceSync\Licensing\License_Guard::init();
	add_action(
		'admin_notices',
		array( \FormulaPriceSync\Licensing\License_Guard::class, 'maybe_show_license_notice' )
	);

	// --- 2. Infrastructure (always registered) -----------------------------
	// CRITICAL: these hooks must register regardless of license validity.
	// Pending Action Scheduler jobs that were scheduled while the license
	// was valid would otherwise become orphaned and fail with
	// "no callback registered", permanently clogging the
	// wp_actionscheduler_actions table. The actual sync work is guarded
	// inside Action_Scheduler_Handler::process_chunk().
	\FormulaPriceSync\Core\DB_Installer::init_hooks();
	\FormulaPriceSync\Core\DB_Installer::maybe_upgrade();
	\FormulaPriceSync\Admin\Metaboxes::init();
	\FormulaPriceSync\Queue\Action_Scheduler_Handler::init();

	// --- 3. Admin UI + core services (always available) --------------------
	// Even with an invalid license, admins must be able to reach the
	// license form, view logs, and inspect system health.
	\FormulaPriceSync\Admin\Admin_Menu::init();
	\FormulaPriceSync\Admin\System_Health_Page::init();
	\FormulaPriceSync\Admin\Settings_API::init();
	\FormulaPriceSync\Admin\Ajax_Handler::init();
	\FormulaPriceSync\Admin\Product_Columns::init();

	\FormulaPriceSync\Core\Logger::init();
	\FormulaPriceSync\Core\Logger::register_settings();
	\FormulaPriceSync\Core\Cron_Manager::init();
	\FormulaPriceSync\Core\Cron_Manager::register_option();
	\FormulaPriceSync\Integrations\Notifier::init();

	// --- 4. License gate: blocks business logic, NOT infrastructure. -------
	if ( \FormulaPriceSync\Licensing\License_Guard::should_block() ) {
		return;
	}

	add_action(
		'admin_notices',
		array( \FormulaPriceSync\API\Circuit_Breaker::class, 'maybe_show_admin_notice' )
	);
}

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @return void
 */
function fps_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__(
				'افزونه طلا ارز پرو (Formula Price Sync) نیاز به نصب و فعال بودن ووکامرس دارد.',
				'formula-price-sync'
			);
			?>
		</p>
	</div>
	<?php
}