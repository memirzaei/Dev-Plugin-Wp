<?php
/**
 * Product metaboxes for Simple and Variable products.
 *
 * Gold path: weight (grams) + wage + profit + tax(9% on wage+profit only).
 * Currency path: base foreign price + profit + fixed fee (no wage/tax).
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\Engine\Rounding;
use FormulaPriceSync\API\API_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Metaboxes
 */
class Metaboxes {

	const META_KEYS = array(
		'_fps_enable',
		'_fps_source_type',
		'_fps_currency_code',
		'_fps_base_foreign_price',
		'_fps_wage_percent',
		'_fps_profit_percent',
		'_fps_tax_percent',
		'_fps_tax_mode',
		'_fps_fixed_fee',
		'_fps_rounding_rule',
		'_fps_custom_formula',
		'_fps_price_locked',
	);

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_simple_fields' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple_meta' ) );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue admin assets on product edit screens.
	 *
	 * @param string $hook Hook.
	 * @return void
	 */
	public static function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'fps-admin',
			FPS_URL . 'assets/css/admin.css',
			array(),
			FPS_VERSION
		);

		wp_enqueue_script(
			'fps-admin-app',
			FPS_URL . 'assets/js/admin-app.js',
			array( 'jquery' ),
			FPS_VERSION,
			true
		);

		wp_localize_script(
			'fps-admin-app',
			'FPS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'fps_admin_ajax' ),
				'displayUnit' => (int) Settings_API::get_display_unit(),
				'i18n'    => array(
					'calculating'       => __( 'در حال محاسبه...', 'formula-price-sync' ),
					'error'             => __( 'خطا در محاسبه', 'formula-price-sync' ),
					'weight_label'      => __( 'وزن (گرم)', 'formula-price-sync' ),
					'weight_desc'       => __( 'وزن قطعه طلا/سکه به گرم.', 'formula-price-sync' ),
					'base_price_label'  => __( 'قیمت پایه ارزی', 'formula-price-sync' ),
					'base_price_desc'   => __( 'قیمت کالا به واحد ارز انتخاب‌شده (مثلاً ۷۰۰ دلار برای گوشی).', 'formula-price-sync' ),
					'base_custom_label' => __( 'مقدار پایه / وزن', 'formula-price-sync' ),
					'base_custom_desc'  => __( 'مقدار {weight} در فرمول سفارشی.', 'formula-price-sync' ),
					'profit_gold'       => __( 'درصد سود فروشنده', 'formula-price-sync' ),
					'profit_currency'   => __( 'درصد سود روی قیمت تبدیل‌شده', 'formula-price-sync' ),
					'formula_gold'      => __( 'فرمول طلا: ارزش خام = وزن × نرخ | اجرت = خام × ٪اجرت | سود = (خام+اجرت) × ٪سود | مالیات فقط روی (اجرت+سود) × ۹٪ | جمع = خام+اجرت+سود+مالیات+ثابت', 'formula-price-sync' ),
					'formula_currency'  => __( 'فرمول ارز: پایه ریالی = قیمت×نرخ | سود = پایه×٪سود | مالیات = (پایه+سود)×٪ یا فقط سود | نهایی = پایه+سود+مالیات+ثابت', 'formula-price-sync' ),
					'formula_custom'    => __( 'فرمول سفارشی با متغیرهای مجاز اجرا می‌شود.', 'formula-price-sync' ),
				),
			)
		);
	}

	/**
	 * Render fields for Simple products.
	 *
	 * @return void
	 */
	public static function render_simple_fields(): void {
		global $post;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$product_id = $post->ID;

		wp_nonce_field( 'fps_product_meta_save', '_fps_product_nonce' );

		echo '<div class="options_group fps-pricing-fields">';
		echo '<p class="form-field"><strong>' . esc_html__( 'طلا ارز پرو – قیمت‌گذاری خودکار', 'formula-price-sync' ) . '</strong></p>';

		$rates = array();
		try {
			$api   = new API_Manager();
			$rates = $api->get_rates();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			$rates = array();
		}
		$rate_gold18   = isset( $rates['gold_18k'] ) ? (float) $rates['gold_18k'] : 0.0;
		$rate_gold24   = isset( $rates['gold_24k'] ) ? (float) $rates['gold_24k'] : 0.0;
		$rate_coin     = isset( $rates['coin'] ) ? (float) $rates['coin'] : 0.0;
		$rate_usd      = isset( $rates['usd'] ) ? (float) $rates['usd'] : 0.0;
		$rate_eur      = isset( $rates['eur'] ) ? (float) $rates['eur'] : 0.0;
		$source_curr   = get_post_meta( $product_id, '_fps_source_type', true ) ?: 'gold_18k';
		$currency_code = get_post_meta( $product_id, '_fps_currency_code', true ) ?: 'usd';
		?>
		<div class="fps-price-preview-wrap">
			<span id="fps-current-rate"
				data-rate="<?php echo esc_attr( (string) $rate_gold18 ); ?>"
				data-gold_18k="<?php echo esc_attr( (string) $rate_gold18 ); ?>"
				data-gold_24k="<?php echo esc_attr( (string) $rate_gold24 ); ?>"
				data-coin="<?php echo esc_attr( (string) $rate_coin ); ?>"
				data-usd="<?php echo esc_attr( (string) $rate_usd ); ?>"
				data-eur="<?php echo esc_attr( (string) $rate_eur ); ?>"
			></span>
			<strong><?php esc_html_e( 'قیمت پیش‌بینی‌شده:', 'formula-price-sync' ); ?></strong>
			<span id="fps-price-preview">—</span> <span id="fps-preview-unit" class="fps-preview-unit"></span>
		</div>

		<div class="fps-formula-hint" id="fps-formula-hint" aria-live="polite"></div>
		<?php

		woocommerce_wp_checkbox(
			array(
				'id'          => '_fps_enable',
				'label'       => __( 'فعال‌سازی قیمت‌گذاری خودکار', 'formula-price-sync' ),
				'description' => __( 'با فعال کردن این گزینه، قیمت محصول بر اساس نرخ زنده و فرمول تنظیم‌شده به‌روزرسانی می‌شود.', 'formula-price-sync' ),
				'desc_tip'    => true,
				'value'       => get_post_meta( $product_id, '_fps_enable', true ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => '_fps_source_type',
				'label'   => __( 'منبع محاسبه', 'formula-price-sync' ),
				'options' => self::get_source_type_options(),
				'value'   => $source_curr,
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => '_fps_currency_code',
				'label'         => __( 'واحد ارز', 'formula-price-sync' ),
				'options'       => self::get_currency_code_options(),
				'value'         => $currency_code,
				'wrapper_class' => 'fps-currency-code-row',
				'desc_tip'      => true,
				'description'   => __( 'فقط برای منبع «ارز». نرخ همان واحد از API خوانده می‌شود.', 'formula-price-sync' ),
			)
		);

		// Shared numeric input: weight (gold) OR base foreign price (currency).
		// Label/description are switched client-side by source type.
		woocommerce_wp_text_input(
			array(
				'id'                => '_fps_base_foreign_price',
				'label'             => __( 'وزن (گرم)', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
				'desc_tip'          => true,
				'description'       => __( 'برای طلا/سکه: وزن به گرم. برای ارز: قیمت پایه به واحد انتخاب‌شده.', 'formula-price-sync' ),
				'value'             => get_post_meta( $product_id, '_fps_base_foreign_price', true ),
				'wrapper_class'     => 'fps-base-amount-row',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_fps_wage_percent',
				'label'             => __( 'درصد اجرت ساخت', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc_tip'          => true,
				'description'       => __( 'فقط برای طلا/سکه. روی ارزش خام طلا اعمال می‌شود.', 'formula-price-sync' ),
				'value'             => get_post_meta( $product_id, '_fps_wage_percent', true ),
				'wrapper_class'     => 'fps-gold-only-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_fps_profit_percent',
				'label'             => __( 'درصد سود فروشنده', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc_tip'          => true,
				'description'       => __( 'طلا: روی (خام+اجرت). ارز: روی قیمت ریالی تبدیل‌شده.', 'formula-price-sync' ),
				'value'             => get_post_meta( $product_id, '_fps_profit_percent', true ),
				'wrapper_class'     => 'fps-profit-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_fps_tax_percent',
				'label'             => __( 'درصد مالیات (۰ = بدون مالیات)', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc_tip'          => true,
				'description'       => __( 'طلا: فقط روی (اجرت+سود). ارز: طبق حالت انتخابی زیر. مقدار ۰ مجاز است.', 'formula-price-sync' ),
				'value'             => metadata_exists( 'post', $product_id, '_fps_tax_percent' ) ? get_post_meta( $product_id, '_fps_tax_percent', true ) : '9',
				'wrapper_class'     => 'fps-tax-field',
			)
		);

		$tax_mode = get_post_meta( $product_id, '_fps_tax_mode', true ) ?: 'total';
		woocommerce_wp_checkbox(
			array(
				'id'            => '_fps_tax_mode_profit_only',
				'label'         => __( 'مالیات فقط روی سود (روش مشابه طلا)', 'formula-price-sync' ),
				'description'   => __( 'برای ارز: اگر فعال باشد مالیات فقط روی مبلغ سود محاسبه می‌شود؛ در غیر این صورت روی (مبلغ پایه ریالی + سود).', 'formula-price-sync' ),
				'desc_tip'      => true,
				'value'         => ( 'profit_only' === $tax_mode ) ? 'yes' : 'no',
				'cbvalue'       => 'yes',
				'wrapper_class' => 'fps-currency-tax-mode',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_fps_fixed_fee',
				'label'             => __( 'کارمزد / هزینه ثابت (واحد فروشگاه)', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
				'value'             => get_post_meta( $product_id, '_fps_fixed_fee', true ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => '_fps_rounding_rule',
				'label'   => __( 'قانون رند کردن', 'formula-price-sync' ),
				'options' => Rounding::get_rule_labels(),
				'value'   => get_post_meta( $product_id, '_fps_rounding_rule', true ) ?: 'none',
			)
		);

		woocommerce_wp_textarea_input(
			array(
				'id'            => '_fps_custom_formula',
				'label'         => __( 'فرمول سفارشی', 'formula-price-sync' ),
				'placeholder'   => '{weight} * {rate} + {fixed_fee}',
				'desc_tip'      => true,
				'description'   => __( 'فقط منبع «فرمول سفارشی». متغیرها: {weight} {rate} {wage_percent} {profit_percent} {tax_percent} {fixed_fee} {raw_gold} {base_rial} {wage_amount} {profit_amount} {tax_amount}', 'formula-price-sync' ),
				'value'         => get_post_meta( $product_id, '_fps_custom_formula', true ),
				'wrapper_class' => 'fps-custom-formula-row',
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'            => '_fps_price_locked',
				'label'         => __( 'قفل قیمت (عدم به‌روزرسانی خودکار)', 'formula-price-sync' ),
				'description'   => __( 'اگر تیک بزنید، این محصول از همگام‌سازی خودکار قیمت خارج می‌شود.', 'formula-price-sync' ),
				'value'         => get_post_meta( $product_id, '_fps_price_locked', true ),
				'cbvalue'       => 'yes',
				'wrapper_class' => 'fps-price-lock-row',
			)
		);

		echo '</div>';
	}

	/**
	 * Render fields for each variation.
	 *
	 * @param int      $loop           Loop index.
	 * @param array    $variation_data Data.
	 * @param \WP_Post $variation      Variation post.
	 * @return void
	 */
	public static function render_variation_fields( int $loop, array $variation_data, \WP_Post $variation ): void {
		$variation_id = $variation->ID;

		wp_nonce_field( 'fps_variation_meta_save_' . $variation_id, '_fps_variation_nonce_' . $loop );

		$source = get_post_meta( $variation_id, '_fps_source_type', true ) ?: 'gold_18k';

		echo '<div class="fps-variation-pricing-fields" data-loop="' . esc_attr( (string) $loop ) . '">';
		echo '<p><strong>' . esc_html__( 'طلا ارز پرو – قیمت‌گذاری خودکار (این ورییشن)', 'formula-price-sync' ) . '</strong></p>';
		echo '<p class="fps-formula-hint fps-var-formula-hint"></p>';

		$enable_value = get_post_meta( $variation_id, '_fps_enable', true );
		woocommerce_wp_checkbox(
			array(
				'id'            => "_fps_enable[{$loop}]",
				'name'          => "_fps_enable[{$loop}]",
				'label'         => __( 'فعال‌سازی قیمت‌گذاری خودکار', 'formula-price-sync' ),
				'value'         => $enable_value ? $enable_value : 'no',
				'cbvalue'       => 'yes',
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => "_fps_source_type[{$loop}]",
				'name'          => "_fps_source_type[{$loop}]",
				'label'         => __( 'منبع محاسبه', 'formula-price-sync' ),
				'options'       => self::get_source_type_options(),
				'value'         => $source,
				'wrapper_class' => 'form-row form-row-first fps-var-source',
				'class'         => 'fps-var-source-type',
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => "_fps_currency_code[{$loop}]",
				'name'          => "_fps_currency_code[{$loop}]",
				'label'         => __( 'واحد ارز', 'formula-price-sync' ),
				'options'       => self::get_currency_code_options(),
				'value'         => get_post_meta( $variation_id, '_fps_currency_code', true ) ?: 'usd',
				'wrapper_class' => 'form-row form-row-last fps-currency-code-row',
				'class'         => 'fps-var-currency-code',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => "_fps_base_foreign_price[{$loop}]",
				'name'              => "_fps_base_foreign_price[{$loop}]",
				'label'             => __( 'وزن (گرم) / قیمت پایه ارزی', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => 'any',
					'min'  => '0',
				),
				'value'             => get_post_meta( $variation_id, '_fps_base_foreign_price', true ),
				'wrapper_class'     => 'form-row form-row-full fps-base-amount-row',
				'class'             => 'fps-var-base-amount',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => "_fps_wage_percent[{$loop}]",
				'name'              => "_fps_wage_percent[{$loop}]",
				'label'             => __( 'درصد اجرت', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'value'             => get_post_meta( $variation_id, '_fps_wage_percent', true ),
				'wrapper_class'     => 'form-row form-row-first fps-gold-only-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => "_fps_profit_percent[{$loop}]",
				'name'              => "_fps_profit_percent[{$loop}]",
				'label'             => __( 'درصد سود', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'value'             => get_post_meta( $variation_id, '_fps_profit_percent', true ),
				'wrapper_class'     => 'form-row form-row-last fps-profit-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => "_fps_tax_percent[{$loop}]",
				'name'              => "_fps_tax_percent[{$loop}]",
				'label'             => __( 'درصد مالیات', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'value'             => metadata_exists( 'post', $variation_id, '_fps_tax_percent' ) ? get_post_meta( $variation_id, '_fps_tax_percent', true ) : '9',
				'wrapper_class'     => 'form-row form-row-first fps-var-tax-percent-field',
			)
		);

		$tax_mode = get_post_meta( $variation_id, '_fps_tax_mode', true );
		if ( ! in_array( $tax_mode, array( 'total', 'profit_only' ), true ) ) {
			$tax_mode = 'total';
		}
		woocommerce_wp_checkbox(
			array(
				'id'            => "_fps_tax_mode_profit_only[{$loop}]",
				'name'          => "_fps_tax_mode_profit_only[{$loop}]",
				'label'         => __( 'مالیات فقط روی سود', 'formula-price-sync' ),
				'description'   => __( 'برای ارز، مالیات فقط روی مبلغ سود محاسبه می‌شود.', 'formula-price-sync' ),
				'cbvalue'       => 'yes',
				'value'         => ( 'profit_only' === $tax_mode ) ? 'yes' : 'no',
				'wrapper_class' => 'form-row form-row-full fps-currency-tax-mode-row',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => "_fps_fixed_fee[{$loop}]",
				'name'              => "_fps_fixed_fee[{$loop}]",
				'label'             => __( 'هزینه ثابت (واحد فروشگاه)', 'formula-price-sync' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
				'value'             => get_post_meta( $variation_id, '_fps_fixed_fee', true ),
				'wrapper_class'     => 'form-row form-row-last',
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => "_fps_rounding_rule[{$loop}]",
				'name'          => "_fps_rounding_rule[{$loop}]",
				'label'         => __( 'قانون رند', 'formula-price-sync' ),
				'options'       => Rounding::get_rule_labels(),
				'value'         => get_post_meta( $variation_id, '_fps_rounding_rule', true ) ?: 'none',
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		woocommerce_wp_textarea_input(
			array(
				'id'            => "_fps_custom_formula[{$loop}]",
				'name'          => "_fps_custom_formula[{$loop}]",
				'label'         => __( 'فرمول سفارشی', 'formula-price-sync' ),
				'placeholder'   => '{weight} * {rate} + {fixed_fee}',
				'value'         => get_post_meta( $variation_id, '_fps_custom_formula', true ),
				'wrapper_class' => 'form-row form-row-full fps-custom-formula-row',
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'            => "_fps_price_locked[{$loop}]",
				'name'          => "_fps_price_locked[{$loop}]",
				'label'         => __( 'قفل قیمت (عدم به‌روزرسانی خودکار)', 'formula-price-sync' ),
				'description'   => __( 'اگر تیک بزنید، این ورییشن از همگام‌سازی خودکار قیمت خارج می‌شود.', 'formula-price-sync' ),
				'value'         => get_post_meta( $variation_id, '_fps_price_locked', true ),
				'cbvalue'       => 'yes',
				'wrapper_class' => 'form-row form-row-full fps-price-lock-row',
			)
		);

		echo '</div>';
	}

	/**
	 * Save meta for Simple products.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function save_simple_meta( int $product_id ): void {
		if (
			! isset( $_POST['_fps_product_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fps_product_nonce'] ) ), 'fps_product_meta_save' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		$enable = isset( $_POST['_fps_enable'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_fps_enable', $enable );

		if ( isset( $_POST['_fps_source_type'] ) ) {
			$source  = sanitize_text_field( wp_unslash( $_POST['_fps_source_type'] ) );
			$allowed = array_keys( self::get_source_type_options() );
			if ( in_array( $source, $allowed, true ) ) {
				update_post_meta( $product_id, '_fps_source_type', $source );
			}
		}

		if ( isset( $_POST['_fps_currency_code'] ) ) {
			$code = sanitize_key( wp_unslash( $_POST['_fps_currency_code'] ) );
			if ( in_array( $code, array( 'usd', 'eur' ), true ) ) {
				update_post_meta( $product_id, '_fps_currency_code', $code );
			}
		}

		self::save_numeric_meta( $product_id, '_fps_base_foreign_price', '_fps_base_foreign_price' );
		self::save_numeric_meta( $product_id, '_fps_wage_percent', '_fps_wage_percent' );
		self::save_numeric_meta( $product_id, '_fps_profit_percent', '_fps_profit_percent' );
		self::save_numeric_meta( $product_id, '_fps_tax_percent', '_fps_tax_percent' );
		$tax_mode = ( isset( $_POST['_fps_tax_mode_profit_only'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['_fps_tax_mode_profit_only'] ) ) )
			? 'profit_only'
			: 'total';
		update_post_meta( $product_id, '_fps_tax_mode', $tax_mode );
		self::save_numeric_meta( $product_id, '_fps_fixed_fee', '_fps_fixed_fee' );

		if ( isset( $_POST['_fps_rounding_rule'] ) ) {
			$rule = sanitize_text_field( wp_unslash( $_POST['_fps_rounding_rule'] ) );
			if ( Rounding::is_valid_rule( $rule ) ) {
				update_post_meta( $product_id, '_fps_rounding_rule', $rule );
			}
		}

		if ( isset( $_POST['_fps_custom_formula'] ) ) {
			$formula = sanitize_text_field( wp_unslash( $_POST['_fps_custom_formula'] ) );
			update_post_meta( $product_id, '_fps_custom_formula', $formula );
		}

		$locked = isset( $_POST['_fps_price_locked'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_fps_price_locked', $locked );
	}

	/**
	 * Save meta for a single variation.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 * @return void
	 */
	public static function save_variation_meta( int $variation_id, int $loop ): void {
		$nonce_key = '_fps_variation_nonce_' . $loop;
		if (
			! isset( $_POST[ $nonce_key ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) ), 'fps_variation_meta_save_' . $variation_id )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		$current_source = get_post_meta( $variation_id, '_fps_source_type', true ) ?: 'gold_18k';
		$enable = isset( $_POST['_fps_enable'][ $loop ] ) ? 'yes' : 'no';
		update_post_meta( $variation_id, '_fps_enable', $enable );

		$source = $current_source;
		if ( isset( $_POST['_fps_source_type'][ $loop ] ) ) {
			$submitted_source = sanitize_text_field( wp_unslash( $_POST['_fps_source_type'][ $loop ] ) );
			$allowed         = array_keys( self::get_source_type_options() );
			if ( in_array( $submitted_source, $allowed, true ) ) {
				$source = $submitted_source;
				update_post_meta( $variation_id, '_fps_source_type', $source );
			}
		}

		if ( isset( $_POST['_fps_currency_code'][ $loop ] ) ) {
			$code = sanitize_key( wp_unslash( $_POST['_fps_currency_code'][ $loop ] ) );
			if ( in_array( $code, array( 'usd', 'eur' ), true ) ) {
				update_post_meta( $variation_id, '_fps_currency_code', $code );
			}
		}

		self::save_variation_numeric( $variation_id, $loop, '_fps_base_foreign_price' );
		self::save_variation_numeric( $variation_id, $loop, '_fps_wage_percent' );
		self::save_variation_numeric( $variation_id, $loop, '_fps_profit_percent' );
		self::save_variation_numeric( $variation_id, $loop, '_fps_tax_percent' );

		$tax_mode = ( isset( $_POST['_fps_tax_mode_profit_only'][ $loop ] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['_fps_tax_mode_profit_only'][ $loop ] ) ) )
			? 'profit_only'
			: 'total';
		if ( 'currency' !== $source ) {
			$tax_mode = 'total';
		}
		update_post_meta( $variation_id, '_fps_tax_mode', $tax_mode );

		self::save_variation_numeric( $variation_id, $loop, '_fps_fixed_fee' );

		if ( isset( $_POST['_fps_rounding_rule'][ $loop ] ) ) {
			$rule = sanitize_text_field( wp_unslash( $_POST['_fps_rounding_rule'][ $loop ] ) );
			if ( Rounding::is_valid_rule( $rule ) ) {
				update_post_meta( $variation_id, '_fps_rounding_rule', $rule );
			}
		}

		if ( isset( $_POST['_fps_custom_formula'][ $loop ] ) ) {
			$formula = sanitize_text_field( wp_unslash( $_POST['_fps_custom_formula'][ $loop ] ) );
			update_post_meta( $variation_id, '_fps_custom_formula', $formula );
		}

		$locked = isset( $_POST['_fps_price_locked'][ $loop ] ) ? 'yes' : 'no';
		update_post_meta( $variation_id, '_fps_price_locked', $locked );
	}

	/**
	 * @param int    $product_id Product ID.
	 * @param string $meta_key   Meta key.
	 * @param string $post_key   POST key.
	 * @return void
	 */
	private static function save_numeric_meta( int $product_id, string $meta_key, string $post_key ): void {
		if ( ! isset( $_POST[ $post_key ] ) ) {
			return;
		}
		$value = floatval( wp_unslash( $_POST[ $post_key ] ) );
		$value = max( 0.0, $value );
		if ( ! is_finite( $value ) ) {
			$value = 0.0;
		}
		update_post_meta( $product_id, $meta_key, $value );
	}

	/**
	 * @param int    $variation_id Variation ID.
	 * @param int    $loop         Loop.
	 * @param string $meta_key     Meta key.
	 * @return void
	 */
	private static function save_variation_numeric( int $variation_id, int $loop, string $meta_key ): void {
		if ( ! isset( $_POST[ $meta_key ][ $loop ] ) ) {
			return;
		}
		$value = floatval( wp_unslash( $_POST[ $meta_key ][ $loop ] ) );
		$value = max( 0.0, $value );
		if ( ! is_finite( $value ) ) {
			$value = 0.0;
		}
		update_post_meta( $variation_id, $meta_key, $value );
	}

	/**
	 * @return array<string, string>
	 */
	private static function get_source_type_options(): array {
		return array(
			'gold_18k'       => __( 'طلا ۱۸ عیار', 'formula-price-sync' ),
			'gold_24k'       => __( 'طلا ۲۴ عیار', 'formula-price-sync' ),
			'coin'           => __( 'سکه', 'formula-price-sync' ),
			'currency'       => __( 'ارز (دلار / یورو)', 'formula-price-sync' ),
			'custom_formula' => __( 'فرمول سفارشی', 'formula-price-sync' ),
		);
	}

	/**
	 * Currency codes for imported goods pricing.
	 *
	 * @return array<string, string>
	 */
	private static function get_currency_code_options(): array {
		return array(
			'usd' => __( 'دلار آمریکا (USD)', 'formula-price-sync' ),
			'eur' => __( 'یورو (EUR)', 'formula-price-sync' ),
		);
	}
}
