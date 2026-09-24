<?php
/**
 * System Health page for Formula Price Sync.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\API\Circuit_Breaker;
use FormulaPriceSync\Core\Cron_Manager;
use FormulaPriceSync\Core\Logger;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;
use FormulaPriceSync\Licensing\License_Guard;
use FormulaPriceSync\Helpers\Jalali;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class System_Health_Page
 *
 * Displays a system health dashboard with license status,
 * sync info, Circuit Breaker state, cron token, provider
 * connectivity, recent errors and environment versions.
 */
class System_Health_Page {

	const CACHE_TTL = 60;

	/**
	 * Render the full page.
	 *
	 * @return void
	 */
	/**
	 * Register AJAX hooks (must run on every request including admin-ajax).
	 */
	public static function init(): void {
		add_action( 'wp_ajax_fps_health_refresh', array( self::class, 'ajax_refresh' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'formula-price-sync' ) );
		}

		wp_enqueue_script(
			'fps-health',
			FPS_URL . 'assets/js/health-page.js',
			array( 'jquery' ),
			FPS_VERSION,
			true
		);
		wp_localize_script(
			'fps-health',
			'FPSHealth',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'fps_health_refresh' ),
				'i18n'    => array(
					'refreshing' => __( 'در حال بررسی...', 'formula-price-sync' ),
					'refreshed'  => __( 'بروزرسانی شد.', 'formula-price-sync' ),
					'error'      => __( 'خطا در بررسی', 'formula-price-sync' ),
					'refreshBtn' => __( 'بررسی مجدد', 'formula-price-sync' ),
				),
			)
		);
		?>
		<div class="wrap fps-admin-wrap">
			<div class="fps-page-header">
				<div class="fps-logo-icon">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--fps-color-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
				</div>
				<h1><?php esc_html_e( 'وضعیت سلامت سیستم', 'formula-price-sync' ); ?></h1>
			</div>

			<div style="margin-bottom: var(--fps-gap-md); display: flex; gap: var(--fps-gap-sm); flex-wrap: wrap; align-items: center;">
				<button type="button" id="fps-health-refresh" class="fps-btn fps-btn-primary">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-left: 4px;"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
					<?php esc_html_e( 'بررسی مجدد', 'formula-price-sync' ); ?>
				</button>
				<span id="fps-health-last-updated" class="fps-desc"></span>
			</div>

			<div id="fps-health-cards">
				<?php echo self::build_cards(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for force refresh.
	 *
	 * @return void
	 */
	public static function ajax_refresh(): void {
		// Prefer JSON error over WP die(-1) for easier front-end handling.
		if ( ! check_ajax_referer( 'fps_health_refresh', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست منقضی شده؛ صفحه را رفرش کنید.', 'formula-price-sync' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی ندارید.', 'formula-price-sync' ) ), 403 );
		}

		// Bust health card cache and force a live provider fetch.
		delete_transient( 'fps_health_cache' );

		try {
			$api = new API_Manager();
			$api->force_refresh();
		} catch ( \Throwable $e ) {
			// Still rebuild cards so the UI updates; surface message to operator.
			if ( class_exists( Logger::class ) ) {
				Logger::get_instance()->warning(
					'Health refresh force_refresh failed: ' . $e->getMessage(),
					array( 'source' => 'system_health' )
				);
			}
		}

		// Ensure gather_data() does not serve a pre-refresh transient.
		delete_transient( 'fps_health_cache' );

		$html = self::build_cards();
		wp_send_json_success(
			array(
				'html'    => $html,
				'message' => __( 'بروزرسانی شد.', 'formula-price-sync' ),
			)
		);
	}

	/**
	 * Build all health cards HTML.
	 *
	 * @return string
	 */
	private static function build_cards(): string {
		$data = self::gather_data();

		ob_start();
		?>
		<div class="fps-grid fps-health-grid">
			<?php self::render_license_card( $data['license'] ); ?>
			<?php self::render_sync_card( $data['sync'] ); ?>
			<?php self::render_circuit_breaker_card( $data['circuit_breaker'] ); ?>
			<?php self::render_cron_card( $data['cron'] ); ?>
			<?php self::render_providers_card( $data['providers'] ); ?>
			<?php self::render_errors_card( $data['errors'] ); ?>
			<?php self::render_environment_card( $data['environment'] ); ?>
			<?php self::render_queue_card( $data['queue'] ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Gather all health data with short caching.
	 *
	 * @return array
	 */
	private static function gather_data(): array {
		$cached = get_transient( 'fps_health_cache' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = array(
			'license'        => self::get_license_data(),
			'sync'           => self::get_sync_data(),
			'circuit_breaker' => self::get_circuit_breaker_data(),
			'cron'           => self::get_cron_data(),
			'providers'      => self::get_providers_data(),
			'errors'         => self::get_errors_data(),
			'environment'    => self::get_environment_data(),
			'queue'         => Action_Scheduler_Handler::get_latest_run_health_summary(),
		);

		set_transient( 'fps_health_cache', $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Get license data.
	 *
	 * @return array
	 */
	private static function get_license_data(): array {
		$valid  = License_Guard::is_valid();
		$key    = License_Guard::get_license_key( true );
		$status = $valid ? 'valid' : 'invalid';

		return array(
			'status'              => $status,
			'key'                 => $key,
			// Boolean only – never expose the actual product token value.
			'product_token_ready' => License_Guard::is_product_token_configured(),
		);
	}

	/**
	 * Get last sync and product count data.
	 *
	 * @return array
	 */
	private static function get_sync_data(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'fps_price_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_row = $wpdb->get_row( "SELECT created_at, trigger_type FROM {$table} ORDER BY created_at DESC LIMIT 1", ARRAY_A );

		$last_sync = $last_row ? $last_row['created_at'] : null;
		$last_trigger = $last_row ? $last_row['trigger_type'] : null;

		$product_count = (int) get_transient( 'fps_health_product_count' );
		if ( 0 === $product_count ) {
			$product_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
					'_fps_enable',
					'yes'
				)
			);
			set_transient( 'fps_health_product_count', $product_count, HOUR_IN_SECONDS );
		}

		return array(
			'last_sync'     => $last_sync,
			'last_trigger'  => $last_trigger,
			'product_count' => $product_count,
		);
	}

	/**
	 * Get Circuit Breaker data.
	 *
	 * @return array
	 */
	private static function get_circuit_breaker_data(): array {
		$last_rates = get_option( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() );
		$has_rates  = ! empty( $last_rates );

		$errors = get_transient( 'fps_circuit_breaker_errors' );
		$errors   = is_array( $errors ) ? $errors : array();

		return array(
			'has_rates'      => $has_rates,
			'last_rate_time' => $has_rates && isset( $last_rates['timestamp'] ) ? $last_rates['timestamp'] : null,
			'recent_errors'  => array_slice( array_reverse( $errors ), 0, 3 ),
		);
	}

	/**
	 * Get cron token and last run data.
	 *
	 * @return array
	 */
	private static function get_cron_data(): array {
		$token = Cron_Manager::get_token();
		$has_token = ! empty( $token );

		$last_logged_sync = null;
		global $wpdb;
		$table = $wpdb->prefix . 'fps_price_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT created_at FROM {$table} WHERE trigger_type = 'scheduled' ORDER BY created_at DESC LIMIT 1", ARRAY_A );
		if ( $row ) {
			$last_logged_sync = $row['created_at'];
		}

		return array(
			'has_token'      => $has_token,
			'token_masked'   => $has_token ? substr( $token, 0, 4 ) . str_repeat( '*', max( 0, strlen( $token ) - 8 ) ) . substr( $token, -4 ) : '',
			'last_scheduled' => $last_logged_sync,
		);
	}

	/**
	 * Get provider connectivity data.
	 *
	 * @return array
	 */
	private static function get_providers_data(): array {
		$providers = array(
			'tgju'    => 'TGJU',
			'navasan' => 'Navasan',
			'nobitex' => 'Nobitex',
			'manual'  => 'Manual',
		);

		$results = array();
		foreach ( $providers as $key => $label ) {
			$results[ $key ] = array(
				'label'  => $label,
				'active' => false,
			);
		}

		$current_source = '';
		try {
			$api   = new API_Manager();
			$rates = $api->get_rates();
			$current_source = isset( $rates['source'] ) ? (string) $rates['source'] : '';
		} catch ( \Exception $e ) {
			$current_source = '';
		}

		foreach ( $results as $key => &$result ) {
			$result['active'] = ( $key === $current_source );
		}
		unset( $result );

		return array(
			'providers' => $results,
			'current'   => $current_source,
		);
	}

	/**
	 * Get recent important errors.
	 *
	 * @return array
	 */
	private static function get_errors_data(): array {
		$logger = Logger::get_instance();
		$entries = $logger->get_entries( 10, Logger::LEVEL_ERROR );

		$circuit_errors = get_transient( 'fps_circuit_breaker_errors' );
		$circuit_errors = is_array( $circuit_errors ) ? array_slice( array_reverse( $circuit_errors ), 0, 5 ) : array();

		return array(
			'logger_entries' => $entries,
			'circuit_errors' => $circuit_errors,
			'has_errors'     => ! empty( $entries ) || ! empty( $circuit_errors ),
		);
	}

	/**
	 * Get environment version data.
	 *
	 * @return array
	 */
	private static function get_environment_data(): array {
		global $woocommerce;
		$wc_version = is_callable( array( $woocommerce, 'version' ) ) ? $woocommerce->version : ( defined( 'WC_VERSION' ) ? WC_VERSION : __( 'نامشخص', 'formula-price-sync' ) );

		return array(
			'plugin_version' => FPS_VERSION,
			'php_version'    => phpversion(),
			'wc_version'     => $wc_version,
			'wp_version'     => get_bloginfo( 'version' ),
		);
	}

	/* ============ Card Renderers ============ */

	/**
	 * Render license card.
	 *
	 * @param array $data License data.
	 * @return void
	 */
	private static function render_license_card( array $data ): void {
		$status_class = 'valid' === $data['status'] ? 'success' : 'error';
		$status_label = 'valid' === $data['status'] ? __( 'فعال', 'formula-price-sync' ) : __( 'غیرفعال', 'formula-price-sync' );
		$token_ready  = true === ( $data['product_token_ready'] ?? false );
		$token_class  = $token_ready ? 'success' : 'warning';
		$token_label  = $token_ready
			? __( 'پیکربندی شده', 'formula-price-sync' )
			: __( 'پیکربندی نشده', 'formula-price-sync' );
		?>
		<div class="fps-card">
			<div class="fps-card-header">
				<h2><?php esc_html_e( 'وضعیت لایسنس', 'formula-price-sync' ); ?></h2>
				<span class="fps-pill fps-pill-<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
			</div>
			<p class="fps-desc">
				<?php if ( 'valid' === $data['status'] && ! empty( $data['key'] ) ) : ?>
					<?php esc_html_e( 'کلید:', 'formula-price-sync' ); ?> <code><?php echo esc_html( $data['key'] ); ?></code>
				<?php else : ?>
					<?php esc_html_e( 'لایسنس معتبر فعال نیست. برای ادامه، کلید معتبر خود را از فروشنده دریافت و فعال کنید.', 'formula-price-sync' ); ?>
				<?php endif; ?>
			</p>
			<p class="fps-desc" style="margin-top: 8px;">
				<?php esc_html_e( 'توکن محصول:', 'formula-price-sync' ); ?>
				<span class="fps-pill fps-pill-<?php echo esc_attr( $token_class ); ?>"><?php echo esc_html( $token_label ); ?></span>
			</p>
			<?php if ( ! $token_ready ) : ?>
				<p class="fps-desc" style="margin-top: 4px; color: var(--fps-color-warning, #b45309);">
					<?php esc_html_e( 'توکن محصول در سرور تعریف نشده است. بدون آن اعتبارسنجی لایسنس امکان‌پذیر نیست (حالت fail-closed).', 'formula-price-sync' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render last sync card.
	 *
	 * @param array $data Sync data.
	 * @return void
	 */
	private static function render_sync_card( array $data ): void {
		$last_sync = $data['last_sync'];
		$trigger_label = $data['last_trigger'] ? self::get_trigger_label( $data['last_trigger'] ) : '—';
		?>
		<div class="fps-card">
			<div class="fps-card-header">
				<h2><?php esc_html_e( 'آخرین همگام‌سازی', 'formula-price-sync' ); ?></h2>
				<?php if ( $last_sync ) : ?>
					<span class="fps-pill fps-pill-success"><?php esc_html_e( 'موفق', 'formula-price-sync' ); ?></span>
				<?php else : ?>
					<span class="fps-pill fps-pill-warning"><?php esc_html_e( 'بدون داده', 'formula-price-sync' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="fps-desc">
				<?php if ( $last_sync ) : ?>
					<?php echo esc_html( Jalali::format( $last_sync, 'Y/m/d H:i:s' ) ); ?>
					<span class="fps-desc" style="margin-right: var(--fps-gap-sm);">—</span>
					<span class="fps-desc"><?php echo esc_html( $trigger_label ); ?></span>
				<?php else : ?>
					<?php esc_html_e( 'هنوز همگام‌سازی انجام نشده است.', 'formula-price-sync' ); ?>
				<?php endif; ?>
			</p>
			<p class="fps-desc" style="margin-top: var(--fps-gap-sm);">
				<strong><?php echo esc_html( number_format_i18n( $data['product_count'] ) ); ?></strong>
				<?php esc_html_e( 'محصول با فرمول فعال', 'formula-price-sync' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render Circuit Breaker card.
	 *
	 * @param array $data Circuit Breaker data.
	 * @return void
	 */
	private static function render_circuit_breaker_card( array $data ): void {
		$healthy = $data['has_rates'] && empty( $data['recent_errors'] );
		?>
		<div class="fps-card">
			<div class="fps-card-header">
				<h2><?php esc_html_e( 'Circuit Breaker', 'formula-price-sync' ); ?></h2>
				<span class="fps-pill <?php echo $healthy ? 'fps-pill-success' : 'fps-pill-warning'; ?>">
					<?php echo $healthy ? esc_html__( 'سالم', 'formula-price-sync' ) : esc_html__( 'هشدار', 'formula-price-sync' ); ?>
				</span>
			</div>
			<p class="fps-desc">
				<?php if ( $data['has_rates'] ) : ?>
					<?php esc_html_e( 'آخرین نرخ پذیرفته‌شده:', 'formula-price-sync' ); ?>
					<strong><?php echo esc_html( Jalali::format( (string) $data['last_rate_time'], 'Y/m/d H:i' ) ); ?></strong>
				<?php else : ?>
					<?php esc_html_e( 'هیچ نرخ پذیرفته‌شده‌ای ثبت نشده است.', 'formula-price-sync' ); ?>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $data['recent_errors'] ) ) : ?>
				<p class="fps-desc" style="margin-top: var(--fps-gap-sm); color: var(--fps-color-error);">
					<?php esc_html_e( 'آخرین هشدار:', 'formula-price-sync' ); ?>
					<?php echo esc_html( mb_strimwidth( $data['recent_errors'][0]['message'] ?? '', 0, 80, '...' ) ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render cron card.
	 *
	 * @param array $data Cron data.
	 * @return void
	 */
	private static function render_cron_card( array $data ): void {
		?>
		<div class="fps-card">
			<div class="fps-card-header">
				<h2><?php esc_html_e( 'کرون مستقل', 'formula-price-sync' ); ?></h2>
				<span class="fps-pill <?php echo $data['has_token'] ? 'fps-pill-success' : 'fps-pill-error'; ?>">
					<?php echo $data['has_token'] ? esc_html__( 'پیکربندی شده', 'formula-price-sync' ) : esc_html__( 'تنظیم نشده', 'formula-price-sync' ); ?>
				</span>
			</div>
			<p class="fps-desc">
				<?php if ( $data['has_token'] ) : ?>
					<?php esc_html_e( 'توکن:', 'formula-price-sync' ); ?> <code><?php echo esc_html( $data['token_masked'] ); ?></code>
				<?php else : ?>
					<?php esc_html_e( 'توکن کرون در تنظیمات تنظیم نشده است.', 'formula-price-sync' ); ?>
				<?php endif; ?>
			</p>
			<p class="fps-desc" style="margin-top: var(--fps-gap-sm);">
				<?php if ( $data['last_scheduled'] ) : ?>
					<?php esc_html_e( 'آخرین اجرای برنامه‌ریزی‌شده:', 'formula-price-sync' ); ?>
					<strong><?php echo esc_html( Jalali::format( $data['last_scheduled'], 'Y/m/d H:i:s' ) ); ?></strong>
				<?php else : ?>
					<?php esc_html_e( 'اجرای برنامه‌ریزی‌شده ثبت نشده.', 'formula-price-sync' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render provider connectivity card.
	 *
	 * @param array $data Providers data.
	 * @return void
	 */
	private static function render_providers_card( array $data ): void {
		?>
		<div class="fps-card">
			<div class="fps-card-header">
				<h2><?php esc_html_e( 'وضعیت Providerها', 'formula-price-sync' ); ?></h2>
				<span class="fps-desc"><?php echo esc_html( $data['current'] ? __( 'فعال:', 'formula-price-sync' ) . ' ' . esc_html( $data['current'] ) : '' ); ?></span>
			</div>
			<ul class="fps-provider-list">
				<?php foreach ( $data['providers'] as $provider ) : ?>
					<li class="<?php echo $provider['active'] ? 'fps-active' : ''; ?>">
						<span class="fps-dot"></span> <?php echo esc_html( $provider['label'] ); ?>
						<?php if ( $provider['active'] ) : ?>
							<strong>(<?php esc_html_e( 'فعال', 'formula-price-sync' ); ?>)</strong>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render errors card.
	 *
	 * @param array $data Errors data.
	 * @return void
	 */
	private static function render_errors_card( array $data ): void {
		?>
		<div class="fps-card">
			<div class="fps-card-header">
				<h2><?php esc_html_e( 'آخرین خطاها', 'formula-price-sync' ); ?></h2>
				<?php if ( ! $data['has_errors'] ) : ?>
					<span class="fps-pill fps-pill-success"><?php esc_html_e( 'بدون خطا', 'formula-price-sync' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $data['logger_entries'] ) ) : ?>
				<ul style="list-style: none; padding: 0; margin: 0;">
					<?php foreach ( $data['logger_entries'] as $entry ) : ?>
						<li style="padding: var(--fps-gap-sm) 0; border-bottom: 1px solid var(--fps-color-border); font-size: var(--fps-text-sm);">
							<strong><?php echo esc_html( $entry['timestamp'] ?? '' ); ?></strong>
							<p style="margin: 2px 0 0;"><?php echo esc_html( $entry['message'] ?? '' ); ?></p>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( ! empty( $data['circuit_errors'] ) ) : ?>
				<ul style="list-style: none; padding: 0; margin: 0;">
					<?php foreach ( $data['circuit_errors'] as $err ) : ?>
						<li style="padding: var(--fps-gap-sm) 0; border-bottom: 1px solid var(--fps-color-border); font-size: var(--fps-text-sm);">
							<strong><?php echo esc_html( $err['time'] ?? '' ); ?></strong>
							<p style="margin: 2px 0 0;"><?php echo esc_html( $err['message'] ?? '' ); ?></p>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="fps-desc"><?php esc_html_e( 'بدون خطای ثبت‌شده.', 'formula-price-sync' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render environment versions card.
	 *
	 * @param array $data Environment data.
	 * @return void
	 */
	private static function render_environment_card( array $data ): void {
		?>
		<div class="fps-card">
			<div class="fps-card-header">
				<h2><?php esc_html_e( 'محیط اجرا', 'formula-price-sync' ); ?></h2>
			</div>
			<table class="widefat striped fps-rates-table">
				<tbody>
					<tr>
						<th><?php esc_html_e( 'پلاگین', 'formula-price-sync' ); ?></th>
						<td><code><?php echo esc_html( $data['plugin_version'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'PHP', 'formula-price-sync' ); ?></th>
						<td><code><?php echo esc_html( $data['php_version'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WooCommerce', 'formula-price-sync' ); ?></th>
						<td><code><?php echo esc_html( $data['wc_version'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WordPress', 'formula-price-sync' ); ?></th>
						<td><code><?php echo esc_html( $data['wp_version'] ); ?></code></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Get human-readable label for trigger type.
	 *
	 * @param string $trigger_type Trigger type slug.
	 * @return string
	 */
	private static function get_trigger_label( string $trigger_type ): string {
		$labels = array(
			'scheduled'   => __( 'برنامه‌ریزی‌شده', 'formula-price-sync' ),
			'manual'      => __( 'دستی', 'formula-price-sync' ),
			'api_webhook' => __( 'وب‌هوک', 'formula-price-sync' ),
		);
		return $labels[ $trigger_type ] ?? $trigger_type;
	}
}

// Register AJAX handler.
