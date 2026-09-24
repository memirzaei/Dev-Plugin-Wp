<?php
/**
 * Admin top-level menu and dashboard page.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;
use FormulaPriceSync\Licensing\License_Guard;
use FormulaPriceSync\Helpers\Formatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Menu
 */
class Admin_Menu {

	const MENU_SLUG = 'formula-price-sync';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register menus and submenus.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'طلا ارز پرو', 'formula-price-sync' ),
			__( 'طلا ارز پرو', 'formula-price-sync' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-line',
			56
		);

		add_submenu_page( self::MENU_SLUG, __( 'داشبورد', 'formula-price-sync' ), __( 'داشبورد', 'formula-price-sync' ), 'manage_woocommerce', self::MENU_SLUG, array( __CLASS__, 'render_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'همگام‌سازی دسته‌ای', 'formula-price-sync' ), __( 'همگام‌سازی دسته‌ای', 'formula-price-sync' ), 'manage_woocommerce', 'fps-bulk', array( Bulk_Form_Page::class, 'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'تاریخچه قیمت', 'formula-price-sync' ), __( 'تاریخچه قیمت', 'formula-price-sync' ), 'manage_woocommerce', 'fps-logs', array( Log_List_Page::class, 'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'تاریخچه نرخ', 'formula-price-sync' ), __( 'تاریخچه نرخ', 'formula-price-sync' ), 'manage_woocommerce', 'fps-history', array( History_Page::class, 'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'وضعیت سیستم', 'formula-price-sync' ), __( 'وضعیت سیستم', 'formula-price-sync' ), 'manage_woocommerce', 'fps-health', array( '\FormulaPriceSync\Admin\System_Health_Page', 'render' ) );
		add_submenu_page( self::MENU_SLUG, __( 'تنظیمات', 'formula-price-sync' ), __( 'تنظیمات', 'formula-price-sync' ), 'manage_woocommerce', 'fps-settings', array( __CLASS__, 'render_settings_page' ) );
	}

	/**
	 * Enqueue assets on plugin admin pages.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		$is_fps = ( false !== strpos( $hook, 'formula-price-sync' ) || false !== strpos( $hook, 'fps-' ) );
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product = $screen && 'product' === $screen->id;

		if ( ! $is_fps && ! $is_product ) {
			return;
		}

		wp_enqueue_style( 'fps-admin', FPS_URL . 'assets/css/admin.css', array(), FPS_VERSION );
		wp_enqueue_script( 'fps-admin-app', FPS_URL . 'assets/js/admin-app.js', array( 'jquery' ), FPS_VERSION, true );
		wp_localize_script(
			'fps-admin-app',
			'FPS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'fps_admin_ajax' ),
				'displayUnit' => (int) Settings_API::get_display_unit(),
				'currencyApi' => Settings_API::get( 'currency_api', 'tgju' ),
				'i18n'    => array(
					'calculating'  => __( 'در حال محاسبه...', 'formula-price-sync' ),
					'error'        => __( 'خطا در محاسبه', 'formula-price-sync' ),
					'syncing'      => __( 'در حال همگام‌سازی...', 'formula-price-sync' ),
					'syncDone'     => __( 'همگام‌سازی آغاز شد.', 'formula-price-sync' ),
					'syncFail'     => __( 'خطا در همگام‌سازی', 'formula-price-sync' ),
					'loadingLogs'  => __( 'در حال بارگذاری لاگ‌ها...', 'formula-price-sync' ),
				),
			)
		);
	}

	/**
	 * Rate limit for form posts.
	 *
	 * @return bool
	 */
	private static function check_rate_limit(): bool {
		$user_id  = get_current_user_id();
		$key      = 'fps_ajax_throttle_' . $user_id;
		$attempts = (int) get_transient( $key );
		if ( $attempts >= 5 ) {
			return false;
		}
		set_transient( $key, $attempts + 1, 30 );
		return true;
	}

	/**
	 * Handle classic form posts (license + legacy manual sync).
	 *
	 * @return void
	 */
	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['fps_manual_sync'] ) && check_admin_referer( 'fps_manual_sync_action', 'fps_manual_sync_nonce' ) ) {
			if ( ! self::check_rate_limit() ) {
				add_settings_error( 'fps_messages', 'fps_rate_limit', __( 'تعداد درخواست‌ها بیش از حد مجاز است. ۳۰ ثانیه صبر کنید.', 'formula-price-sync' ), 'error' );
			} elseif ( License_Guard::should_block() ) {
				add_settings_error( 'fps_messages', 'fps_license_required', __( 'برای به‌روزرسانی قیمت‌ها ابتدا لایسنس را فعال کنید.', 'formula-price-sync' ), 'error' );
			} else {
				$chunks = Action_Scheduler_Handler::trigger_manual_sync();
				add_settings_error(
					'fps_messages',
					'fps_sync_started',
					sprintf(
						/* translators: %d: chunks */
						__( 'به‌روزرسانی قیمت‌ها آغاز شد. تعداد دسته‌های زمان‌بندی‌شده: %d', 'formula-price-sync' ),
						$chunks
					),
					'success'
				);
			}
		}

		if ( isset( $_POST['fps_activate_license'] ) && check_admin_referer( 'fps_license_action', 'fps_license_nonce' ) ) {
			$key    = isset( $_POST['fps_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['fps_license_key'] ) ) : '';
			$result = License_Guard::activate( $key );
			add_settings_error( 'fps_messages', 'fps_license_result', $result['message'], $result['success'] ? 'success' : 'error' );
		}

		if ( isset( $_POST['fps_deactivate_license'] ) && check_admin_referer( 'fps_license_action', 'fps_license_nonce' ) ) {
			License_Guard::deactivate();
			add_settings_error( 'fps_messages', 'fps_license_deactivated', __( 'لایسنس غیرفعال شد.', 'formula-price-sync' ), 'success' );
		}
	}

	/**
	 * Main dashboard.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'formula-price-sync' ) );
		}

		settings_errors( 'fps_messages' );

		$api           = new API_Manager();
		$rates         = $api->get_rates();
		$source        = $api->get_current_source();
		$license_valid = License_Guard::is_valid();
		?>
		<div class="wrap fps-admin-wrap">
			<div class="fps-page-header">
				<div class="fps-logo-icon" aria-hidden="true">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
				</div>
				<h1><?php esc_html_e( 'طلا ارز پرو', 'formula-price-sync' ); ?></h1>
			</div>

			<?php if ( ! $license_valid ) : ?>
				<?php echo self::render_license_warning(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>

			<?php echo self::render_unit_switcher(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<div class="fps-grid">
				<div class="fps-card fps-span-2">
					<h2 class="fps-card-title"><?php esc_html_e( 'نرخ‌های امروز', 'formula-price-sync' ); ?></h2>
					<?php self::render_rates_table( $rates, $source ); ?>
				</div>

				<div class="fps-card">
					<h2 class="fps-card-title"><?php esc_html_e( 'یک‌کلیکی', 'formula-price-sync' ); ?></h2>
					<form method="post">
						<?php wp_nonce_field( 'fps_manual_sync_action', 'fps_manual_sync_nonce' ); ?>
						<p><?php esc_html_e( 'نرخ جدید بگیر و قیمت محصولات فعال را به‌روز کن.', 'formula-price-sync' ); ?></p>
						<?php
						submit_button(
							__( 'به‌روزرسانی قیمت‌ها', 'formula-price-sync' ),
							'primary',
							'fps_manual_sync',
							false,
							array( 'class' => 'fps-btn fps-btn-primary' )
						);
						?>
					</form>
					<nav class="fps-quick-links" aria-label="<?php esc_attr_e( 'میانبرها', 'formula-price-sync' ); ?>">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fps-bulk' ) ); ?>"><?php esc_html_e( 'همگام‌سازی دسته‌ای', 'formula-price-sync' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fps-settings' ) ); ?>"><?php esc_html_e( 'تنظیمات', 'formula-price-sync' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fps-logs' ) ); ?>"><?php esc_html_e( 'تاریخچه قیمت', 'formula-price-sync' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=fps-health' ) ); ?>"><?php esc_html_e( 'وضعیت سیستم', 'formula-price-sync' ); ?></a>
					</nav>
				</div>

				<div class="fps-card">
					<h2 class="fps-card-title"><?php esc_html_e( 'منبع نرخ', 'formula-price-sync' ); ?></h2>
					<?php self::render_provider_status( $source, $rates ); ?>
				</div>

				<div class="fps-card fps-span-2">
					<h2 class="fps-card-title"><?php esc_html_e( 'لایسنس', 'formula-price-sync' ); ?></h2>
					<?php self::render_license_form( $license_valid ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'formula-price-sync' ) );
		}
		echo '<div class="wrap fps-admin-wrap"><div class="fps-page-header"><h1>' . esc_html__( 'تنظیمات', 'formula-price-sync' ) . '</h1></div>';
		Settings_API::render_settings_form();
		echo '</div>';
	}

	/**
	 * @param array  $rates Rates.
	 * @param string $source Source.
	 * @return void
	 */
	private static function render_rates_table( array $rates, string $source ): void {
		if ( empty( $rates ) ) {
			echo '<p class="fps-muted">' . esc_html__( 'نرخی دریافت نشده است.', 'formula-price-sync' ) . '</p>';
			return;
		}
		$rows = array(
			'usd'      => __( 'دلار (USD)', 'formula-price-sync' ),
			'eur'      => __( 'یورو (EUR)', 'formula-price-sync' ),
			'gold_18k' => __( 'طلا ۱۸ عیار (گرم)', 'formula-price-sync' ),
			'gold_24k' => __( 'طلا ۲۴ عیار (گرم)', 'formula-price-sync' ),
			'coin'     => __( 'سکه امامی', 'formula-price-sync' ),
		);
		$unit_label = __( 'نرخ (ریال)', 'formula-price-sync' );
		echo '<table class="widefat striped fps-rates-table"><thead><tr><th>' . esc_html__( 'نوع', 'formula-price-sync' ) . '</th><th>' . esc_html( $unit_label ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $key => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>';
			if ( ! empty( $rates[ $key ] ) && (float) $rates[ $key ] > 0 ) {
				echo esc_html( Formatter::format_price( (float) $rates[ $key ] ) );
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		printf(
			'<p class="fps-muted">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: source 2: time */
					__( 'منبع: %1$s | آخرین بروزرسانی: %2$s', 'formula-price-sync' ),
					$source ?: '—',
					isset( $rates['timestamp'] ) ? date_i18n( 'Y/m/d H:i', (int) $rates['timestamp'] ) : '—'
				)
			)
		);
	}

	/**
	 * @param string $source Source.
	 * @param array  $rates Rates.
	 * @return void
	 */
	private static function render_provider_status( string $source, array $rates ): void {
		$providers = array(
			'tgju'    => 'TGJU (اصلی)',
			'navasan' => 'Navasan',
			'nobitex' => 'Nobitex',
			'manual'  => 'دستی (Manual)',
		);
		$active = $source ?: 'unknown';
		echo '<ul class="fps-provider-list">';
		foreach ( $providers as $key => $label ) {
			$class = ( $key === $active ) ? 'fps-active' : '';
			echo '<li class="' . esc_attr( $class ) . '"><span class="fps-dot"></span> ' . esc_html( $label );
			if ( $key === $active ) {
				echo ' <strong>(' . esc_html__( 'فعال', 'formula-price-sync' ) . ')</strong>';
			}
			echo '</li>';
		}
		echo '</ul>';
		if ( empty( $rates ) ) {
			echo '<p class="fps-error">' . esc_html__( 'هیچ سرویسی پاسخ نداده است.', 'formula-price-sync' ) . '</p>';
		}
	}

	/**
	 * @param bool $is_valid Valid.
	 * @return void
	 */
	private static function render_license_form( bool $is_valid ): void {
		echo '<form method="post">';
		wp_nonce_field( 'fps_license_action', 'fps_license_nonce' );
		if ( $is_valid ) {
			echo '<p class="fps-success"><strong>' . esc_html__( 'وضعیت: فعال', 'formula-price-sync' ) . '</strong><br><code>' . esc_html( License_Guard::get_license_key( true ) ) . '</code></p>';
			submit_button( __( 'غیرفعال‌سازی لایسنس', 'formula-price-sync' ), 'delete', 'fps_deactivate_license', false );
		} else {
			echo '<p class="description">' . esc_html__( 'کلید لایسنس را از فروشنده دریافت کنید.', 'formula-price-sync' ) . '</p>';
			echo '<p><label for="fps_license_key">' . esc_html__( 'کلید لایسنس', 'formula-price-sync' ) . '</label><br>';
			echo '<input type="password" name="fps_license_key" id="fps_license_key" class="regular-text" value="" autocomplete="new-password" /></p>';
			submit_button( __( 'فعال‌سازی لایسنس', 'formula-price-sync' ), 'primary', 'fps_activate_license', false );
		}
		echo '</form>';
	}

	/**
	 * Render the license warning banner.
	 *
	 * @return string
	 */
	private static function render_license_warning(): string {
		ob_start();
		?>
		<div class="fps-notice fps-notice-warning" style="margin-bottom: var(--fps-gap-md);">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" y2="17"></line></svg>
			<span><?php esc_html_e( 'لایسنس فعال نیست. امکانات اصلی افزونه محدود شده‌اند.', 'formula-price-sync' ); ?></span>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the Rial/Toman display unit switcher.
	 *
	 * @return string
	 */
	private static function render_unit_switcher(): string {
		$current = Settings_API::get_display_unit();
		$nonce   = wp_create_nonce( 'fps_display_unit' );
		$ajax    = admin_url( 'admin-ajax.php' );
		ob_start();
		?>
		<div class="fps-card">
			<h3 class="fps-card-title">واحد نمایش قیمت‌ها</h3>
			<p class="fps-desc"><?php esc_html_e( 'قیمت‌ها همیشه به ریال محاسبه و ذخیره می‌شوند. این گزینه واحد اصلی/برجسته را در نمایش همزمان ریال و تومان تعیین می‌کند.', 'formula-price-sync' ); ?></p>
			<div class="fps-unit-switcher"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-ajax-url="<?php echo esc_url( $ajax ); ?>">
				<button type="button"
					class="fps-unit-btn <?php echo 1 === $current ? 'active' : ''; ?>"
					data-unit="1">
					ریال
				</button>
				<button type="button"
					class="fps-unit-btn <?php echo 2 === $current ? 'active' : ''; ?>"
					data-unit="2">
					تومان
				</button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
