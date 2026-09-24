<?php
/**
 * Historical rates / sync history dashboard tab with CSV export.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\Helpers\Jalali;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\API\Circuit_Breaker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class History_Page
 *
 * Shows last accepted rates, recent sync summary, and provides CSV export.
 */
class History_Page {

	/**
	 * Render history view.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'formula-price-sync' ) );
		}

		$last_rates = get_option( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() );
		if ( ! is_array( $last_rates ) ) {
			$last_rates = array();
		}

		$api    = new API_Manager();
		$cached = $api->get_rates();

		// Enqueue script for CSV export AJAX.
		wp_enqueue_script(
			'fps-history',
			FPS_URL . 'assets/js/history-page.js',
			array( 'jquery' ),
			FPS_VERSION,
			true
		);
		wp_localize_script(
			'fps-history',
			'FPSHistory',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'fps_history_csv' ),
				'i18n'    => array(
					'exporting' => esc_html__( 'در حال تولید خروجی CSV...', 'formula-price-sync' ),
					'done'      => esc_html__( 'فایل آماده دانلود است.', 'formula-price-sync' ),
					'error'     => esc_html__( 'خطا در تولید فایل', 'formula-price-sync' ),
				),
			)
		);
		?>
		<div class="wrap fps-admin-wrap">
			<div class="fps-page-header">
				<div class="fps-logo-icon">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--fps-color-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
				</div>
				<h1><?php esc_html_e( 'تاریخچه نرخ و همگام‌سازی', 'formula-price-sync' ); ?></h1>
			</div>

			<div class="fps-grid" style="margin-top: var(--fps-gap-lg);">
				<div class="fps-card">
					<div class="fps-card-header">
						<h2><?php esc_html_e( 'آخرین نرخ پذیرفته‌شده (Circuit Breaker)', 'formula-price-sync' ); ?></h2>
					</div>
					<?php self::render_rate_list( $last_rates ); ?>
				</div>
				<div class="fps-card">
					<div class="fps-card-header">
						<h2><?php esc_html_e( 'نرخ فعلی کش‌شده', 'formula-price-sync' ); ?></h2>
					</div>
					<?php self::render_rate_list( $cached ); ?>
				</div>
			</div>

			<div class="fps-card" style="margin-top: var(--fps-gap-lg);">
				<div class="fps-card-header">
					<h2><?php esc_html_e( 'خروجی CSV تاریخچه قیمت‌ها', 'formula-price-sync' ); ?></h2>
					<div class="fps-card-actions">
						<button type="button"
							id="fps-export-csv"
							class="fps-btn fps-btn-primary"
							data-action="fps_export_history_csv">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle; margin-left: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
							<?php esc_html_e( 'دانلود CSV کامل', 'formula-price-sync' ); ?>
						</button>
					</div>
				</div>

				<div id="fps-csv-filters" class="fps-csv-filters" style="display: none; margin-top: var(--fps-gap-md); padding: var(--fps-gap-md); background: var(--fps-color-bg); border-radius: var(--fps-radius-md);">
					<h3><?php esc_html_e( 'فیلترهای خروجی', 'formula-price-sync' ); ?></h3>
					<div class="fps-form-row">
						<label for="fps_csv_start_date" class="fps-form-label"><?php esc_html_e( 'از تاریخ', 'formula-price-sync' ); ?></label>
						<div class="fps-form-field">
							<input type="text" id="fps_csv_start_date" name="start_date" class="regular-text" placeholder="1403/07/01" dir="ltr" inputmode="numeric" autocomplete="off">
						</div>
					</div>
					<div class="fps-form-row">
						<label for="fps_csv_end_date" class="fps-form-label"><?php esc_html_e( 'تا تاریخ', 'formula-price-sync' ); ?></label>
						<div class="fps-form-field">
							<input type="text" id="fps_csv_end_date" name="end_date" class="regular-text" placeholder="1403/07/19" dir="ltr" inputmode="numeric" autocomplete="off">
						</div>
					</div>
					<div class="fps-form-row">
						<label for="fps_csv_trigger_type" class="fps-form-label"><?php esc_html_e( 'نوع تراکنش', 'formula-price-sync' ); ?></label>
						<div class="fps-form-field">
							<select id="fps_csv_trigger_type" name="trigger_type">
								<option value=""><?php esc_html_e( 'همه موارد', 'formula-price-sync' ); ?></option>
								<option value="scheduled"><?php esc_html_e( 'برنامه‌ریزی‌شده', 'formula-price-sync' ); ?></option>
								<option value="manual"><?php esc_html_e( 'دستی', 'formula-price-sync' ); ?></option>
								<option value="api_webhook"><?php esc_html_e( 'به‌روزرسانی وب‌هوک', 'formula-price-sync' ); ?></option>
							</select>
						</div>
					</div>
					<div class="fps-form-row">
						<label for="fps_csv_product_id" class="fps-form-label"><?php esc_html_e( 'شناسه محصول (اختیاری)', 'formula-price-sync' ); ?></label>
						<div class="fps-form-field">
							<input type="number" id="fps_csv_product_id" name="product_id" min="1" class="regular-text" placeholder="شناسه محصول">
						</div>
					</div>
					<div class="fps-form-row">
						<div class="fps-form-field" style="margin-right: auto;">
							<button type="button"
								id="fps-confirm-export-csv"
								class="fps-btn fps-btn-primary">
								<?php esc_html_e( 'تولید و دانلود', 'formula-price-sync' ); ?>
							</button>
							<span id="fps-csv-status" class="fps-desc" style="display: none; margin-right: var(--fps-gap-md);"></span>
						</div>
					</div>
				</div>
			</div>

			<p class="fps-desc" style="margin-top: var(--fps-gap-lg);">
				<?php esc_html_e( 'برای مشاهده تغییرات قیمت محصولات به منوی «تاریخچه قیمت» مراجعه کنید.', 'formula-price-sync' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param array $rates Rates.
	 * @return void
	 */
	private static function render_rate_list( array $rates ): void {
		if ( empty( $rates ) ) {
			echo '<p class="fps-desc">' . esc_html__( 'داده‌ای موجود نیست.', 'formula-price-sync' ) . '</p>';
			return;
		}
		$labels = array(
			'usd'       => 'USD',
			'eur'       => 'EUR',
			'gold_18k'  => esc_html__( 'طلا ۱۸', 'formula-price-sync' ),
			'gold_24k'  => esc_html__( 'طلا ۲۴', 'formula-price-sync' ),
			'coin'      => esc_html__( 'سکه', 'formula-price-sync' ),
			'source'    => esc_html__( 'منبع', 'formula-price-sync' ),
			'timestamp' => esc_html__( 'زمان', 'formula-price-sync' ),
		);
		echo '<table class="widefat striped fps-rates-table"><tbody>';
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $rates[ $key ] ) ) {
				continue;
			}
			$val = $rates[ $key ];
			if ( 'timestamp' === $key && is_numeric( $val ) ) {
				$val = Jalali::format( (int) $val );
			} elseif ( is_numeric( $val ) && 'source' !== $key ) {
				$val = number_format_i18n( (float) $val, 0 );
			}
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $val ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * AJAX handler to export price history as CSV.
	 */
	public static function ajax_export_history_csv() {
		check_ajax_referer( 'fps_history_csv', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'دسترسی ندارید.', 'formula-price-sync' ) ) );
		}

		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end_date   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

		$start_g = '';
		$end_g   = '';
		if ( '' !== $start_date ) {
			$start_g = Jalali::to_gregorian_date( $start_date );
			if ( '' === $start_g ) {
				wp_send_json_error( array( 'message' => esc_html__( 'تاریخ شروع جلالی نامعتبر است.', 'formula-price-sync' ) ), 400 );
			}
		}
		if ( '' !== $end_date ) {
			$end_g = Jalali::to_gregorian_date( $end_date );
			if ( '' === $end_g ) {
				wp_send_json_error( array( 'message' => esc_html__( 'تاریخ پایان جلالی نامعتبر است.', 'formula-price-sync' ) ), 400 );
			}
		}
		$start_date = $start_g;
		$end_date   = $end_g;
		$trigger_type = isset( $_POST['trigger_type'] ) ? sanitize_key( wp_unslash( $_POST['trigger_type'] ) ) : '';
		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		$allowed_triggers = array( '', 'scheduled', 'manual', 'api_webhook' );
		if ( ! in_array( $trigger_type, $allowed_triggers, true ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'نوع تراکنش نامعتبر است.', 'formula-price-sync' ) ), 400 );
		}

		$date_pattern = '/^\d{4}-\d{2}-\d{2}$/'; // gregorian after Jalali normalize
		foreach ( array( $start_date, $end_date ) as $date_value ) {
			if ( '' !== $date_value && 1 !== preg_match( $date_pattern, $date_value ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'فرمت تاریخ نامعتبر است.', 'formula-price-sync' ) ), 400 );
			}
		}
		if ( '' !== $start_date && '' !== $end_date && $start_date > $end_date ) {
			wp_send_json_error( array( 'message' => esc_html__( 'بازه تاریخ نامعتبر است.', 'formula-price-sync' ) ), 400 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fps_price_logs';

		$where_clauses = array();
		$where_values  = array();

		// created_at is stored in the site's WordPress timezone. Date boundaries
		// are converted into indexed DATETIME comparisons rather than DATE(...),
		// preserving index usability for large history tables.
		if ( '' !== $start_date ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = $start_date . ' 00:00:00';
		}
		if ( '' !== $end_date ) {
			$where_clauses[] = 'created_at <= %s';
			$where_values[]  = $end_date . ' 23:59:59';
		}
		if ( '' !== $trigger_type ) {
			$where_clauses[] = 'trigger_type = %s';
			$where_values[]  = $trigger_type;
		}
		if ( $product_id > 0 ) {
			$where_clauses[] = 'product_id = %d';
			$where_values[]  = $product_id;
		}

		$base_where = empty( $where_clauses ) ? '' : ' AND ' . implode( ' AND ', $where_clauses );

		$filename = sprintf(
			'fps-price-history-%s.csv',
			wp_date( 'Ymd-His' )
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_send_json_error( array( 'message' => esc_html__( 'امکان ایجاد خروجی وجود ندارد.', 'formula-price-sync' ) ), 500 );
		}

		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array(
			__( 'شناسه', 'formula-price-sync' ),
			__( 'شناسه محصول', 'formula-price-sync' ),
			__( 'شناسه تنوع', 'formula-price-sync' ),
			__( 'قیمت قبلی (ریال)', 'formula-price-sync' ),
			__( 'قیمت جدید (ریال)', 'formula-price-sync' ),
			__( 'نرخ منبع (ریال)', 'formula-price-sync' ),
			__( 'Snapshot', 'formula-price-sync' ),
			__( 'نوع تراکنش', 'formula-price-sync' ),
			__( 'تاریخ و زمان', 'formula-price-sync' ),
		) );

		$last_id = 0;
		$batch_size = 500;
		do {
			$clauses = $where_clauses;
			$values  = $where_values;
			$clauses[] = 'id > %d';
			$values[] = $last_id;
			$where_sql = 'WHERE ' . implode( ' AND ', $clauses );

			$sql = $wpdb->prepare(
				"SELECT id, product_id, variation_id, old_price, new_price, source_rate, snapshot_id, trigger_type, created_at
				 FROM {$table}
				 {$where_sql}
				 ORDER BY id ASC
				 LIMIT %d",
				array_merge( $values, array( $batch_size ) )
			);

			$results = $wpdb->get_results( $sql, ARRAY_A );
			if ( empty( $results ) ) {
				break;
			}

			foreach ( $results as $row ) {
				$last_id = absint( $row['id'] );
				fputcsv( $output, array(
					$last_id,
					absint( $row['product_id'] ),
					absint( $row['variation_id'] ),
					(string) $row['old_price'],
					(string) $row['new_price'],
					(string) $row['source_rate'],
					(string) $row['snapshot_id'],
					self::get_trigger_label( (string) $row['trigger_type'] ),
					Jalali::format( (string) $row['created_at'] ),
				) );
			}
		} while ( count( $results ) === $batch_size );

		fclose( $output );
		die();
	}

	/**
	 * Get human-readable label for trigger type.
	 *
	 * @param string $trigger_type
	 * @return string
	 */
	private static function get_trigger_label( string $trigger_type ): string {
		$labels = array(
			'scheduled'     => esc_html__( 'برنامه‌ریزی‌شده', 'formula-price-sync' ),
			'manual'        => esc_html__( 'دستی', 'formula-price-sync' ),
			'api_webhook'   => esc_html__( 'به‌روزرسانی وب‌هوک', 'formula-price-sync' ),
		);
		return $labels[ $trigger_type ] ?? $trigger_type;
	}
}

// Register the AJAX handler.
add_action( 'wp_ajax_fps_export_history_csv', array( History_Page::class, 'ajax_export_history_csv' ) );