<?php
/**
 * Settings page & API for Formula Price Sync.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\Engine\Calculator;
use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\Helpers\Formatter;
use FormulaPriceSync\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_API {

	const OPTION_NAME = 'fps_options';

	/**
	 * Default options.
	 *
	 * @return array
	 */
	public static function get_defaults(): array {
		return array(
			'currency_api'            => 'tgju',
			'custom_rate'             => '',
			'rate_divisor'            => 1,
			'display_unit'            => 2,
			'show_formula_column'     => true,
			'show_formula_column_simple'  => true,
			'show_formula_column_variable' => true,
			'auto_update'             => true,
			'update_schedule'         => 'hourly',
			'license_key'             => '',
			'log_enabled'             => false,
			'log_level'               => Logger::LEVEL_INFO,
		);
	}

	/**
	 * Bootstrap settings & AJAX.
	 */
	public static function init() {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		// Top-level menu is owned solely by Admin_Menu (avoids duplicate menus + broken subpages).
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_fps_save_display_unit', array( self::class, 'ajax_save_display_unit' ) );
		add_action( 'wp_ajax_fps_save_show_column', array( self::class, 'ajax_save_show_column' ) );
		add_action( 'wp_ajax_fps_refresh_rates', array( self::class, 'ajax_refresh_rates' ) );
		// fps_calculate_variable removed: Product_Columns::ajax_calculate_variable does not exist (fatal surface).
	}

	/**
	 * Register settings sections & fields.
	 */
	public static function register_settings() {
		$defaults = self::get_defaults();

		// Rate section.
		add_settings_section(
			'fps_section_rates',
			esc_html__( 'تنظیمات نرخ ارز', 'formula-price-sync' ),
			array( self::class, 'section_rates_cb' ),
			'formula-price-sync'
		);

		$rate_sources = API_Manager::get_available_sources();
		foreach ( $rate_sources as $key => $label ) {
			add_settings_field(
				'fps_currency_api_' . $key,
				esc_html( $label ),
				array( self::class, 'radio_field_cb' ),
				'formula-price-sync',
				'fps_section_rates',
				array(
					'name'  => 'fps_options[currency_api]',
					'value' => $key,
					'label' => $label,
				)
			);
		}

		add_settings_field(
			'fps_custom_rate',
			esc_html__( 'نرخ دلخواه (ریال)', 'formula-price-sync' ),
			array( self::class, 'text_field_cb' ),
			'formula-price-sync',
			'fps_section_rates',
			array(
				'name'        => 'fps_options[custom_rate]',
				'placeholder' => 'مثلاً 58000',
				'desc'        => esc_html__( 'فقط در صورت انتخاب «نرخ دلخواه» استفاده می‌شود.', 'formula-price-sync' ),
			)
		);

		// Display section.
		add_settings_section(
			'fps_section_display',
			esc_html__( 'تنظیمات نمایش', 'formula-price-sync' ),
			array( self::class, 'section_display_cb' ),
			'formula-price-sync'
		);

		add_settings_field(
			'fps_display_unit',
			esc_html__( 'واحد نمایش قیمت', 'formula-price-sync' ),
			array( self::class, 'display_unit_field_cb' ),
			'formula-price-sync',
			'fps_section_display',
			array()
		);

		add_settings_field(
			'fps_show_formula_column',
			esc_html__( 'نمایش ستون قیمت محاسباتی در لیست محصولات', 'formula-price-sync' ),
			array( self::class, 'checkbox_field_cb' ),
			'formula-price-sync',
			'fps_section_display',
			array( 'name' => 'fps_options[show_formula_column]', 'label' => esc_html__( 'فعال', 'formula-price-sync' ) )
		);

		add_settings_field(
			'fps_show_formula_column_simple',
			esc_html__( 'نمایش ستون «قیمت فرمولی» برای محصولات ساده', 'formula-price-sync' ),
			array( self::class, 'checkbox_field_cb' ),
			'formula-price-sync',
			'fps_section_display',
			array( 'name' => 'fps_options[show_formula_column_simple]', 'label' => esc_html__( 'فعال', 'formula-price-sync' ) )
		);

		add_settings_field(
			'fps_show_formula_column_variable',
			esc_html__( 'نمایش ستون «قیمت فرمولی» برای محصولات متغیر', 'formula-price-sync' ),
			array( self::class, 'checkbox_field_cb' ),
			'formula-price-sync',
			'fps_section_display',
			array( 'name' => 'fps_options[show_formula_column_variable]', 'label' => esc_html__( 'فعال', 'formula-price-sync' ) )
		);

		// Schedule section.
		add_settings_section(
			'fps_section_schedule',
			esc_html__( 'بروزرسانی خودکار', 'formula-price-sync' ),
			array( self::class, 'section_schedule_cb' ),
			'formula-price-sync'
		);

		add_settings_field(
			'fps_auto_update',
			esc_html__( 'بروزرسانی خودکار نرخ ارز', 'formula-price-sync' ),
			array( self::class, 'checkbox_field_cb' ),
			'formula-price-sync',
			'fps_section_schedule',
			array( 'name' => 'fps_options[auto_update]', 'label' => esc_html__( 'فعال', 'formula-price-sync' ) )
		);

		add_settings_field(
			'fps_update_schedule',
			esc_html__( 'زمان‌بندی بروزرسانی', 'formula-price-sync' ),
			array( self::class, 'schedule_field_cb' ),
			'formula-price-sync',
			'fps_section_schedule',
			array()
		);

		// License section.
		add_settings_section(
			'fps_section_license',
			esc_html__( 'لایسنس', 'formula-price-sync' ),
			array( self::class, 'section_license_cb' ),
			'formula-price-sync'
		);

		add_settings_field(
			'fps_license_key',
			esc_html__( 'کلید لایسنس', 'formula-price-sync' ),
			array( self::class, 'license_field_cb' ),
			'formula-price-sync',
			'fps_section_license',
			array()
		);

		// Logging section.
		add_settings_section(
			'fps_section_logging',
			esc_html__( 'لاگ‌گیری و دیباگ', 'formula-price-sync' ),
			array( self::class, 'section_logging_cb' ),
			'formula-price-sync'
		);

		add_settings_field(
			'fps_log_enabled',
		 esc_html__( 'لاگ‌گیری فعال باشد', 'formula-price-sync' ),
			array( self::class, 'checkbox_field_cb' ),
			'formula-price-sync',
			'fps_section_logging',
			array( 'name' => 'fps_options[log_enabled]', 'label' => esc_html__( 'فعال', 'formula-price-sync' ) )
		);

		add_settings_field(
			'fps_log_level',
			esc_html__( 'سطح لاگ', 'formula-price-sync' ),
			array( self::class, 'log_level_field_cb' ),
			'formula-price-sync',
			'fps_section_logging',
			array()
		);

		// Register the option.
		register_setting( 'fps_options_group', 'fps_options', array( self::class, 'sanitize' ) );
	}

	/**
	 * Sanitize options.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized.
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::get_defaults();
		$clean    = array();

		$allowed_sources = array_keys( API_Manager::get_available_sources() );
		$currency_api = isset( $input['currency_api'] ) ? sanitize_key( $input['currency_api'] ) : $defaults['currency_api'];
		$clean['currency_api']            = in_array( $currency_api, $allowed_sources, true ) ? $currency_api : $defaults['currency_api'];
		$custom_rate = isset( $input['custom_rate'] ) ? trim( (string) $input['custom_rate'] ) : '';
		$clean['custom_rate'] = ( '' === $custom_rate || is_numeric( $custom_rate ) ) ? sanitize_text_field( $custom_rate ) : '';
		// Canonical storage/calculation unit is always Rial. Ignore legacy divisor=10 input.
		$clean['rate_divisor'] = 1;
		$display_unit = isset( $input['display_unit'] ) ? absint( $input['display_unit'] ) : $defaults['display_unit'];
		$clean['display_unit']            = in_array( $display_unit, array( 1, 2 ), true ) ? $display_unit : 2;
		$clean['show_formula_column']         = isset( $input['show_formula_column'] ) ? (bool) $input['show_formula_column'] : $defaults['show_formula_column'];
		$clean['show_formula_column_simple']    = isset( $input['show_formula_column_simple'] ) ? (bool) $input['show_formula_column_simple'] : $defaults['show_formula_column_simple'];
		$clean['show_formula_column_variable']  = isset( $input['show_formula_column_variable'] ) ? (bool) $input['show_formula_column_variable'] : $defaults['show_formula_column_variable'];
		$clean['auto_update']             = isset( $input['auto_update'] ) ? (bool) $input['auto_update'] : $defaults['auto_update'];
		$clean['update_schedule']         = isset( $input['update_schedule'] ) && in_array( $input['update_schedule'], array( 'hourly', 'twicedaily', 'daily' ), true ) ? $input['update_schedule'] : $defaults['update_schedule'];
		$clean['license_key']             = isset( $input['license_key'] ) && '' !== trim( (string) $input['license_key'] ) ? sanitize_text_field( $input['license_key'] ) : (string) get_option( 'fps_license_key', '' );
		$clean['log_enabled']             = isset( $input['log_enabled'] ) ? (bool) $input['log_enabled'] : $defaults['log_enabled'];
		$log_level = isset( $input['log_level'] ) ? sanitize_key( $input['log_level'] ) : $defaults['log_level'];
		$clean['log_level']               = in_array( $log_level, array( Logger::LEVEL_DEBUG, Logger::LEVEL_INFO, Logger::LEVEL_WARNING, Logger::LEVEL_ERROR ), true ) ? $log_level : Logger::LEVEL_INFO;
		$queue_chunk_size = isset( $input['queue_chunk_size'] ) ? absint( $input['queue_chunk_size'] ) : 50;
		$clean['queue_chunk_size'] = max( 10, min( 100, $queue_chunk_size ?: 50 ) );

		return $clean;
	}

	/* ============ Section callbacks ============ */

	public static function section_rates_cb() {
		echo '<p class="fps-desc">' . esc_html__( 'منبع نرخ ارز را انتخاب کنید. در صورت نیاز نرخ دلخواه را به ریال وارد کنید.', 'formula-price-sync' ) . '</p>';
	}

	public static function section_display_cb() {
		echo '<p class="fps-desc">' . esc_html__( 'نحوه نمایش قیمت‌ها در مدیریت و ستون‌های لیست محصولات.', 'formula-price-sync' ) . '</p>';
	}

	public static function section_schedule_cb() {
		echo '<p class="fps-desc">' . esc_html__( 'بروزرسانی خودکار نرخ‌های ارز بر اساس زمان‌بندی انتخاب‌شده.', 'formula-price-sync' ) . '</p>';
	}

	public static function section_license_cb() {
		echo '<p class="fps-desc">' . esc_html__( 'کلید لایسنس را برای فعال‌سازی ویژگی‌های پیشرفته وارد کنید.', 'formula-price-sync' ) . '</p>';
	}

	public static function section_logging_cb() {
		echo '<p class="fps-desc">' . esc_html__( 'لاگ‌گیری سیستمی برای دیباگ و پایش مشکلات. در محصولات تولیدی توصیه می‌شود غیرعالی باشد.', 'formula-price-sync' ) . '</p>';
	}

	/* ============ Field callbacks ============ */

	public static function radio_field_cb( array $args ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$checked = checked( $options['currency_api'], $args['value'], false );
		printf(
			'<label class="fps-radio-label"><input type="radio" name="%s" value="%s" %s> %s</label>',
			esc_attr( $args['name'] ),
			esc_attr( $args['value'] ),
			$checked,
			esc_html( $args['label'] )
		);
	}

	public static function text_field_cb( array $args ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$value   = isset( $options[ str_replace( 'fps_options[', '', str_replace( ']', '', $args['name'] ) ) ] ) ? $options[ str_replace( 'fps_options[', '', str_replace( ']', '', $args['name'] ) ) ] : '';
		printf(
			'<input type="text" name="%s" value="%s" placeholder="%s" class="regular-text">',
			esc_attr( $args['name'] ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] )
		);
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="fps-desc">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	public static function checkbox_field_cb( array $args ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$key     = str_replace( 'fps_options[', '', str_replace( ']', '', $args['name'] ) );
		$checked = checked( ! empty( $options[ $key ] ), true, false );
		printf(
			'<label class="fps-checkbox-label"><input type="checkbox" name="%s" value="1" %s> %s</label>',
			esc_attr( $args['name'] ),
			$checked,
			esc_html( $args['label'] )
		);
	}

	public static function schedule_field_cb( array $args ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$choices = array(
			'hourly'   => esc_html__( 'ساعتی', 'formula-price-sync' ),
			'twicedaily' => esc_html__( 'دو بار در روز', 'formula-price-sync' ),
			'daily'    => esc_html__( 'روزانه', 'formula-price-sync' ),
		);
		echo '<select name="fps_options[update_schedule]">';
		foreach ( $choices as $val => $label ) {
			$sel = selected( $options['update_schedule'], $val, false );
			echo '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function license_field_cb( array $args ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		echo '<input type="password" name="fps_options[license_key]" value="" class="large-text" placeholder="کلید لایسنس" autocomplete="new-password">';
		echo '<p class="fps-desc">' . esc_html__( 'کلید لایسنس را از سایت فروشنده دریافت کنید.', 'formula-price-sync' ) . '</p>';
	}

	/**
	 * Log level field — radio buttons for debug/info/warning/error.
	 */
	public static function log_level_field_cb( array $args ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$current = isset( $options['log_level'] ) ? $options['log_level'] : Logger::LEVEL_INFO;
		$levels = array(
			Logger::LEVEL_DEBUG   => esc_html__( 'دیباگ', 'formula-price-sync' ),
			Logger::LEVEL_INFO    => esc_html__( 'اطلاعات', 'formula-price-sync' ),
			Logger::LEVEL_WARNING => esc_html__( 'هشدار', 'formula-price-sync' ),
			Logger::LEVEL_ERROR   => esc_html__( 'خطا', 'formula-price-sync' ),
		);
		?>
		<fieldset class="fps-radio-group">
		<?php foreach ( $levels as $value => $label ) : ?>
			<label class="fps-radio-label">
				<input type="radio" name="fps_options[log_level]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
				<?php echo $label; ?>
			</label>
		<?php endforeach; ?>
		</fieldset>
		<p class="fps-desc"><?php esc_html_e( 'سطح لاگ‌گیری را انتخاب کنید. در محیط تولید، فقط لاگ‌های خطای уровня می‌بایست فعال باشد.', 'formula-price-sync' ); ?></p>
		<?php
	}

	/**
	 * Display unit field — pill buttons with AJAX save.
	 *
	 * @param array $args
	 */
	public static function display_unit_field_cb( array $args ) {
		$options    = get_option( self::OPTION_NAME, self::get_defaults() );
		$current    = isset( $options['display_unit'] ) ? absint( $options['display_unit'] ) : 2;
		$nonce      = wp_create_nonce( 'fps_display_unit' );
		$ajax_url   = admin_url( 'admin-ajax.php' );
		?>
		<div class="fps-unit-switcher" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
			<button type="button" class="fps-unit-btn <?php echo 1 === $current ? 'active' : ''; ?>" data-unit="1">
				ریال
			</button>
			<button type="button" class="fps-unit-btn <?php echo 2 === $current ? 'active' : ''; ?>" data-unit="2">
				تومان
			</button>
		</div>
		<input type="hidden" name="fps_options[display_unit]" value="<?php echo esc_attr( $current ); ?>" id="fps_display_unit_hidden">
		<p class="fps-desc"><?php esc_html_e( 'واحد اصلی/برجسته در نمایش دوگانه (ریال و تومان همزمان). قیمت‌ها همیشه به ریال ذخیره می‌شوند.', 'formula-price-sync' ); ?></p>
		<script type="text/javascript">
			(function(){
				var container = document.querySelector('.fps-unit-switcher');
				if(!container) return;
				var hidden = document.getElementById('fps_display_unit_hidden');
				var nonce = container.getAttribute('data-nonce');
				var ajaxUrl = container.getAttribute('data-ajax-url');
				container.querySelectorAll('.fps-unit-btn').forEach(function(btn){
					btn.addEventListener('click', function(){
						var unit = parseInt(this.getAttribute('data-unit'), 10);
						if (unit === parseInt(hidden.value, 10)) return;
						var data = new FormData();
						data.append('action', 'fps_save_display_unit');
						data.append('nonce', nonce);
						data.append('display_unit', unit);
						fetch(ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
							.then(function(r){ return r.json(); })
							.then(function(res){
								if(res.success){
									hidden.value = unit;
									container.querySelectorAll('.fps-unit-btn').forEach(function(b){
										b.classList.toggle('active', parseInt(b.getAttribute('data-unit'),10)===unit);
									});
									// Trigger preview re-render if on product page
									if(window.fpsRecalcPreview) window.fpsRecalcPreview(unit);
								}
							});
					});
				});
			})();
		</script>
		<?php
	}

	/**
	 * Rate divisor field (storage unit) — radio 1 or 10.
	 *
	 * @param array $args
	 */
	public static function rate_divisor_field_cb( array $args ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$current = isset( $options['rate_divisor'] ) ? (float) $options['rate_divisor'] : 1;
		?>
		<fieldset class="fps-radio-group">
			<label class="fps-radio-label">
				<input type="radio" name="fps_options[rate_divisor]" value="1" <?php checked( $current, 1 ); ?>> ریال
			</label>
			<label class="fps-radio-label" style="margin-right: 16px;">
				<input type="radio" name="fps_options[rate_divisor]" value="10" <?php checked( $current, 10 ); ?>> تومان
			</label>
		</fieldset>
		<p class="fps-desc"><?php esc_html_e( 'واحد پایه برای محاسبه و ذخیره فرمول‌ها. تغییر این گزینه قیمت‌های موجود را مجدداً محاسبه نمی‌کند (برای اعمال از ابزار «بروزرسانی انبوه» استفاده کنید).', 'formula-price-sync' ); ?></p>
		<?php
	}

	/* ============ Menu page ============ */

	/**
	 * @deprecated 2.0.2 Menu owned by Admin_Menu. Kept as no-op for backward safety.
	 */
	public static function add_menu_page() {
		// Intentionally empty — duplicate top-level menu caused 404 on subpages.
	}

	public static function render_page() {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		?>
		<div class="wrap fps-admin-wrap">
			<div class="fps-page-header">
				<div class="fps-logo-icon" style="background: var(--fps-color-accent-soft);">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--fps-color-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
				</div>
				<h1><?php esc_html_e( 'تنظیمات فرمول قیمت', 'formula-price-sync' ); ?></h1>
			</div>

			<?php settings_errors( 'fps_messages' ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'fps_options_group' ); ?>
				<?php do_settings_sections( 'formula-price-sync' ); ?>
				<?php submit_button( esc_html__( 'ذخیره تغییرات', 'formula-price-sync' ), 'primary', 'fps_submit', false, array( 'class' => 'fps-btn fps-btn-primary' ) ); ?>
			</form>

			<!-- Dashboard widget shortcut -->
			<div class="fps-card" style="margin-top: var(--fps-gap-lg);">
				<h3 class="fps-card-title">داشبورد سریع</h3>
				<div class="fps-dashboard-grid">
					<div class="fps-stat-card">
						<span class="fps-stat-number" id="fps_stat_products">—</span>
						<span class="fps-stat-label">محصولات با فرمول</span>
					</div>
					<div class="fps-stat-card">
						<span class="fps-stat-number" id="fps_stat_variations">—</span>
						<span class="fps-stat-label">تنوع‌های فعال</span>
					</div>
					<div class="fps-stat-card fps-stat-wide">
						<span class="fps-stat-number" id="fps_stat_rate">—</span>
						<span class="fps-stat-label">نرخ جاری (ریال)</span>
					</div>
				</div>
				<div style="margin-top: var(--fps-gap-md); display: flex; gap: var(--fps-gap-sm); flex-wrap: wrap;">
					<button type="button" class="fps-btn fps-btn-primary" id="fps_manual_update">
						بروزرسانی نرخ الان
					</button>
					<button type="button" class="fps-btn fps-btn-secondary" id="fps_bulk_update">
						بروزرسانی انبوه قیمت‌ها
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/* ============ Asset enqueue ============ */

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_formula-price-sync', 'edit.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && 'edit-product' === $screen->id ) {
			wp_enqueue_style( 'fps-admin', FPS_URL . 'assets/css/admin.css', array(), FPS_VERSION );
			return;
		}
		if ( 'toplevel_page_formula-price-sync' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'fps-admin', FPS_URL . 'assets/css/admin.css', array(), FPS_VERSION );
		wp_enqueue_script( 'fps-admin', FPS_URL . 'assets/js/admin-app.js', array( 'jquery' ), FPS_VERSION, true );

		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$rate    = self::get_current_rate_value();
		wp_localize_script( 'fps-admin', 'fpsData', array(
			'ajaxurl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'fps_nonce' ),
			'rate'             => $rate,
			'display_unit'     => (int) $options['display_unit'],
			'currency_api'     => $options['currency_api'],
			'custom_rate'      => $options['custom_rate'],
			'show_simple'      => (bool) $options['show_formula_column_simple'],
			'show_variable'    => (bool) $options['show_formula_column_variable'],
			'strings'          => array(
				'calculating' => esc_html__( 'در حال محاسبه...', 'formula-price-sync' ),
				'error'       => esc_html__( 'خطا', 'formula-price-sync' ),
				'success'     => esc_html__( 'موفقیت', 'formula-price-sync' ),
				'rial'        => esc_html__( 'ریال', 'formula-price-sync' ),
				'toman'       => esc_html__( 'تومان', 'formula-price-sync' ),
			),
		) );
	}

	/* ============ AJAX Handlers ============ */

	public static function ajax_save_display_unit() {
		check_ajax_referer( 'fps_display_unit', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'دسترسی ندارید.', 'formula-price-sync' ) ) );
		}
		$unit = isset( $_POST['display_unit'] ) ? absint( $_POST['display_unit'] ) : 2;
		if ( ! in_array( $unit, array( 1, 2 ), true ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'مقدار نامعتبر.', 'formula-price-sync' ) ) );
		}
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$options['display_unit'] = $unit;
		update_option( self::OPTION_NAME, $options );
		wp_send_json_success( array( 'display_unit' => $unit ) );
	}

	public static function ajax_save_show_column() {
		check_ajax_referer( 'fps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'دسترسی ندارید.', 'formula-price-sync' ) ) );
		}
		$key   = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';
		$value = isset( $_POST['value'] ) ? (bool) $_POST['value'] : false;
		$allowed = array( 'show_formula_column_simple', 'show_formula_column_variable' );
		if ( ! in_array( $key, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'کلید نامعتبر.', 'formula-price-sync' ) ) );
		}
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$options[ $key ] = $value;
		update_option( self::OPTION_NAME, $options );
		wp_send_json_success( array( $key => $value ) );
	}

	public static function ajax_refresh_rates() {
		check_ajax_referer( 'fps_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'دسترسی ندارید.', 'formula-price-sync' ) ) );
		}
		$api   = new API_Manager();
		$rates = $api->force_refresh();
		if ( empty( $rates ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'نرخ معتبر دریافت نشد؛ قیمت‌ها تغییر نکردند.', 'formula-price-sync' ) ), 502 );
		}
		$rate = 0.0;
		foreach ( array( 'gold_18k', 'usd', 'eur', 'gold_24k', 'coin' ) as $rate_key ) {
			if ( isset( $rates[ $rate_key ] ) && is_numeric( $rates[ $rate_key ] ) && (float) $rates[ $rate_key ] > 0 ) {
				$rate = (float) $rates[ $rate_key ];
				break;
			}
		}
		wp_send_json_success( array(
			'rates'    => $rates,
			'rate'     => $rate,
			'formatted'=> Formatter::format_price( $rate ),
			'source'   => isset( $rates['source'] ) ? (string) $rates['source'] : 'unknown',
			'updated'  => isset( $rates['timestamp'] ) ? absint( $rates['timestamp'] ) : time(),
		) );
	}

	/* ============ Helpers ============ */

	/**
	 * Get the stored display unit.
	 *
	 * @return int 1=Rial, 2=Toman.
	 */
	public static function get_display_unit(): int {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		return isset( $options['display_unit'] ) ? absint( $options['display_unit'] ) : 2;
	}

	/**
	 * Get the stored rate divisor (storage unit).
	 *
	 * @return float 1 or 10.
	 */
	public static function get_rate_divisor(): float {
		// Backward-compatible API: canonical internal rate is always Rial.
		return 1.0;
	}

	/**
	 * Return one representative canonical Rial rate for legacy admin widgets.
	 *
	 * @return float
	 */
	private static function get_current_rate_value(): float {
		$rates = ( new API_Manager() )->get_rates();
		foreach ( array( 'gold_18k', 'usd', 'eur', 'gold_24k', 'coin' ) as $key ) {
			if ( isset( $rates[ $key ] ) && is_numeric( $rates[ $key ] ) && (float) $rates[ $key ] > 0 ) {
				return (float) $rates[ $key ];
			}
		}
		return 0.0;
	}

	/**
	 * Convert a Rial price to the display unit for UI.
	 *
	 * @param float $rial_price
	 * @return float
	 */
	public static function convert_for_display( float $rial_price ): float {
		$unit = self::get_display_unit();
		return ( 1 === $unit ) ? $rial_price : ( $rial_price / 10 );
	}

	/**
	 * Format a price for UI showing both units simultaneously.
	 *
	 * Primary unit is determined by display_unit setting; secondary is shown in parentheses.
	 * Example (display_unit=2): «۱۴,۴۲۰,۰۰۰ تومان (۱۴۴,۲۰۰,۰۰۰ ریال)»
	 *
	 * @param float $rial_price Price always stored in Rial.
	 * @return string
	 */
	public static function format_for_display( float $rial_price ): string {
		$unit        = self::get_display_unit();
		$rial_fmt    = Formatter::format_price( $rial_price );
		$toman_fmt   = Formatter::format_price( $rial_price / 10 );

		if ( 1 === $unit ) {
			// Rial is primary (highlighted).
			return $rial_fmt . ' ریال (' . $toman_fmt . ' تومان)';
		}

		// Toman is primary (default).
		return $toman_fmt . ' تومان (' . $rial_fmt . ' ریال)';
	}

	/**
	 * Get an option value by key with fallback.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Render the legacy settings page form (called from Admin_Menu).
	 *
	 * @return void
	 */
	public static function render_settings_form() {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$rate    = self::get_current_rate_value();
		?>
		<div class="wrap fps-admin-wrap">
			<div class="fps-page-header">
				<div class="fps-logo-icon">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--fps-color-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
				</div>
				<h1><?php esc_html_e( 'تنظیمات فرمول قیمت', 'formula-price-sync' ); ?></h1>
			</div>
			<?php settings_errors( 'fps_messages' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'fps_options_group' ); ?>
				<?php do_settings_sections( 'formula-price-sync' ); ?>
				<div style="margin-top: var(--fps-gap-lg);">
					<button type="submit" class="fps-btn fps-btn-primary"><?php esc_html_e( 'ذخیره تغییرات', 'formula-price-sync' ); ?></button>
				</div>
			</form>
			<div class="fps-card" style="margin-top: var(--fps-gap-lg);">
				<h3 class="fps-card-title"><?php esc_html_e( 'نرخ جاری (ریال)', 'formula-price-sync' ); ?></h3>
				<div class="fps-dashboard-grid">
					<div class="fps-stat-card">
						<span class="fps-stat-number" id="fps_stat_rate"><?php echo esc_html( Formatter::format_price( $rate ) ); ?></span>
						<span class="fps-stat-label"><?php esc_html_e( 'نرخ جاری', 'formula-price-sync' ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the standalone settings page (alternative to legacy form).
	 *
	 * @return void
	 */
	public static function render_standalone_page(): void {
		$options = get_option( self::OPTION_NAME, self::get_defaults() );
		$rate    = self::get_current_rate_value();
		?>
		<div class="wrap fps-admin-wrap">
			<div class="fps-page-header">
				<div class="fps-logo-icon">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--fps-color-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
				</div>
				<h1><?php esc_html_e( 'تنظیمات فرمول قیمت', 'formula-price-sync' ); ?></h1>
			</div>
			<?php settings_errors( 'fps_messages' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'fps_options_group' ); ?>
				<?php do_settings_sections( 'formula-price-sync' ); ?>
				<button type="submit" class="fps-btn fps-btn-primary"><?php esc_html_e( 'ذخیره تغییرات', 'formula-price-sync' ); ?></button>
			</form>
			<div class="fps-card" style="margin-top: var(--fps-gap-lg);">
				<h3 class="fps-card-title"><?php esc_html_e( 'نرخ جاری (ریال)', 'formula-price-sync' ); ?></h3>
				<div class="fps-dashboard-grid">
					<div class="fps-stat-card">
						<span class="fps-stat-number" id="fps_stat_rate"><?php echo esc_html( Formatter::format_price( $rate ) ); ?></span>
						<span class="fps-stat-label"><?php esc_html_e( 'نرخ جاری', 'formula-price-sync' ); ?></span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}