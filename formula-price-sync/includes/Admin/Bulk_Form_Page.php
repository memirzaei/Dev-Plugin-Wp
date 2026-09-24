<?php
/**
 * Bulk price sync form page.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\Licensing\License_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bulk_Form_Page
 */
class Bulk_Form_Page {

	/**
	 * Render bulk sync UI.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'formula-price-sync' ) );
		}

		$license_ok = ! License_Guard::should_block();

		$cats = $license_ok ? self::get_product_categories() : array();
		$tags = $license_ok ? self::get_product_tags() : array();

		?>
		<div class="wrap fps-admin-wrap">
			<h1><?php esc_html_e( 'همگام‌سازی دسته‌ای قیمت‌ها', 'formula-price-sync' ); ?></h1>

			<?php if ( ! $license_ok ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'برای همگام‌سازی، ابتدا لایسنس را فعال کنید.', 'formula-price-sync' ); ?></p></div>
			<?php endif; ?>

			<div class="fps-card" style="max-width:640px;margin-top:16px;">
				<p><?php esc_html_e( 'این عملیات نرخ‌های زنده را دریافت کرده و قیمت تمام محصولات/ورییشن‌های دارای «قیمت‌گذاری خودکار» را در پس‌زمینه (چانک‌های ۵۰تایی) به‌روزرسانی می‌کند.', 'formula-price-sync' ); ?></p>

				<p class="fps-bulk-filters-label">
					<label for="fps-bulk-product-cats"><?php esc_html_e( 'فیلتر بر حسب دسته/برچسب (اختیاری)', 'formula-price-sync' ); ?></label>
					<span class="description"><?php esc_html_e( 'اگر هیچ‌کدام انتخاب نشود، تمام محصولات همگام‌سازی می‌شوند.', 'formula-price-sync' ); ?></span>
				</p>

				<select name="fps_bulk_product_cats[]" id="fps-bulk-product-cats" multiple="multiple" data-placeholder="انتخاب دسته‌بندی‌ها" <?php disabled( ! $license_ok ); ?> style="width:100%;">
					<?php foreach ( $cats as $cat ): ?>
						<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="fps-bulk-product-tags" style="display:block;margin-top:14px;font-weight:600;"><?php esc_html_e( 'برچسب‌ها', 'formula-price-sync' ); ?></label>
				<select name="fps_bulk_product_tags[]" id="fps-bulk-product-tags" multiple="multiple" data-placeholder="انتخاب برچسب‌ها" <?php disabled( ! $license_ok ); ?> style="width:100%;">
					<?php foreach ( $tags as $tag ): ?>
						<option value="<?php echo esc_attr( (string) $tag->term_id ); ?>"><?php echo esc_html( $tag->name ); ?></option>
					<?php endforeach; ?>
				</select>

				<p style="margin-top:16px;">
					<button type="button" class="button button-primary" id="fps-bulk-sync-btn" <?php disabled( ! $license_ok ); ?>>
						<?php esc_html_e( 'شروع همگام‌سازی دسته‌ای', 'formula-price-sync' ); ?>
					</button>
					<span class="spinner" id="fps-bulk-spinner" style="float:none;margin:0 8px;"></span>
				</p>

				<div id="fps-bulk-progress" style="display:none;margin-top:12px;">
					<div style="background:#f0f0f1;border-radius:4px;height:12px;overflow:hidden;">
						<div id="fps-bulk-bar" style="background:#2271b1;height:100%;width:0%;transition:width .3s;"></div>
					</div>
					<p id="fps-bulk-status" class="description"></p>
				</div>

				<div id="fps-bulk-result" class="notice" style="display:none;margin-top:12px;"></div>
			</div>
		</div>

		<script id="fps-bulk-config" type="application/json">
			<?php echo wp_json_encode(
				array(
					'nonce'   => wp_create_nonce( 'fps_admin_ajax' ),
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				)
			); ?>
		</script>
		<script type="text/javascript">
			jQuery(function($){
				if(typeof $.fn.selectWoo !== 'undefined') {
					$('#fps-bulk-product-cats, #fps-bulk-product-tags').selectWoo({
						width: '100%',
						placeholder: $(this).data('placeholder') || '',
						allowClear: true,
						multiple: true,
					});
				}
			});
		</script>
		<?php
	}

	/**
	 * Fetch all product categories for the filter dropdown.
	 *
	 * @return \WP_Term[]
	 */
	private static function get_product_categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Fetch all product tags for the filter dropdown.
	 *
	 * @return \WP_Term[]
	 */
	private static function get_product_tags(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		return is_array( $terms ) ? $terms : array();
	}
}