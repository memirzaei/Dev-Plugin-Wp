<?php
/**
 * Admin AJAX handlers (bulk sync + log fetch).
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\Queue\Action_Scheduler_Handler;
use FormulaPriceSync\Licensing\License_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ajax_Handler
 */
class Ajax_Handler {

	/**
	 * Register AJAX actions.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_fps_bulk_sync', array( __CLASS__, 'bulk_sync' ) );
		add_action( 'wp_ajax_fps_fetch_logs', array( __CLASS__, 'fetch_logs' ) );
	}

	/**
	 * Rate limit helper.
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
	 * Trigger bulk price sync.
	 *
	 * @return void
	 */
	public static function bulk_sync(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'formula-price-sync' ) ), 403 );
		}

		check_ajax_referer( 'fps_admin_ajax', 'nonce' );

		if ( ! self::check_rate_limit() ) {
			wp_send_json_error( array( 'message' => __( 'تعداد درخواست‌ها بیش از حد مجاز است. ۳۰ ثانیه صبر کنید.', 'formula-price-sync' ) ), 429 );
		}

		if ( License_Guard::should_block() ) {
			wp_send_json_error( array( 'message' => __( 'لایسنس فعال نیست.', 'formula-price-sync' ) ), 403 );
		}

		$filters = array();

		if ( ! empty( $_POST['product_cats'] ) ) {
			$cats = array_filter( array_map( 'absint', (array) $_POST['product_cats'] ) );
			if ( ! empty( $cats ) ) {
				$filters['product_cats'] = array_values( $cats );
			}
		}

		if ( ! empty( $_POST['product_tags'] ) ) {
			$tags = array_filter( array_map( 'absint', (array) $_POST['product_tags'] ) );
			if ( ! empty( $tags ) ) {
				$filters['product_tags'] = array_values( $tags );
			}
		}

		$chunks = Action_Scheduler_Handler::trigger_manual_sync( $filters );
		if ( is_wp_error( $chunks ) ) {
			wp_send_json_error( array( 'message' => $chunks->get_error_message() ), 502 );
		}

		$chunks = (int) $chunks;
		if ( $chunks <= 0 ) {
			wp_send_json_success(
				array(
					'chunks'  => 0,
					'message' => __( 'همگام‌سازی انجام شد: محصول فعالی برای به‌روزرسانی یافت نشد.', 'formula-price-sync' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'chunks'  => $chunks,
				'message' => sprintf(
					/* translators: %d: number of queue chunks */
					__( 'همگام‌سازی با موفقیت در صف قرار گرفت. تعداد دسته‌ها: %d — به‌روزرسانی قیمت‌ها در پس‌زمینه ادامه می‌یابد.', 'formula-price-sync' ),
					$chunks
				),
			)
		);
	}

	/**
	 * Fetch recent logs as JSON.
	 *
	 * @return void
	 */
	public static function fetch_logs(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'دسترسی غیرمجاز.', 'formula-price-sync' ) ), 403 );
		}

		check_ajax_referer( 'fps_admin_ajax', 'nonce' );

		global $wpdb;
		$table = $wpdb->prefix . 'fps_price_logs';
		$limit = isset( $_POST['limit'] ) ? min( 50, max( 1, absint( $_POST['limit'] ) ) ) : 20;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.post_title AS product_name
				 FROM {$table} l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
				 ORDER BY l.created_at DESC
				 LIMIT %d",
				$limit
			)
		);

		$rows = array();
		foreach ( (array) $logs as $log ) {
			$rows[] = array(
				'product'   => $log->product_name ? $log->product_name : '#' . (int) $log->product_id,
				'variation' => $log->variation_id ? (int) $log->variation_id : 0,
				'old_price' => (float) $log->old_price,
				'new_price' => (float) $log->new_price,
				'rate'      => (float) $log->source_rate,
				'snapshot_id'=> (string) $log->snapshot_id,
				'trigger'   => (string) $log->trigger_type,
				'time'      => (string) $log->created_at,
			);
		}

		wp_send_json_success( array( 'logs' => $rows ) );
	}
}
