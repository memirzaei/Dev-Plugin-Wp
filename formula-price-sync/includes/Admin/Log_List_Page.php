<?php
/**
 * Price change log list table page.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\Helpers\Jalali;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Log_List_Page
 */
class Log_List_Page {

	/**
	 * Render the logs table.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'شما دسترسی لازم را ندارید.', 'formula-price-sync' ) );
		}

		global $wpdb;

		$per_page = 20;
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $wpdb->prefix . 'fps_price_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$logs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, p.post_title AS product_name
				 FROM {$table} l
				 LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id
				 ORDER BY l.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);
		?>
		<div class="wrap fps-admin-wrap">
			<h1><?php esc_html_e( 'تاریخچه تغییرات قیمت', 'formula-price-sync' ); ?></h1>
			<p class="description"><?php esc_html_e( 'آخرین تغییرات قیمت ثبت‌شده توسط همگام‌سازی خودکار یا دستی.', 'formula-price-sync' ); ?></p>
			<table class="widefat striped fps-logs-table" id="fps-logs-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'محصول', 'formula-price-sync' ); ?></th>
						<th><?php esc_html_e( 'ورییشن', 'formula-price-sync' ); ?></th>
						<th><?php esc_html_e( 'قیمت قبل', 'formula-price-sync' ); ?></th>
						<th><?php esc_html_e( 'قیمت جدید', 'formula-price-sync' ); ?></th>
						<th><?php esc_html_e( 'نرخ منبع', 'formula-price-sync' ); ?></th>
						<th><?php esc_html_e( 'Snapshot', 'formula-price-sync' ); ?></th>
						<th><?php esc_html_e( 'نوع', 'formula-price-sync' ); ?></th>
						<th><?php esc_html_e( 'زمان', 'formula-price-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'هنوز لاگی ثبت نشده است.', 'formula-price-sync' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->product_name ? $log->product_name : '#' . (int) $log->product_id ); ?></td>
								<td><?php echo esc_html( $log->variation_id ? (string) (int) $log->variation_id : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $log->old_price, 0 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $log->new_price, 0 ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $log->source_rate, 0 ) ); ?></td>
								<td><code><?php echo esc_html( (string) $log->snapshot_id ); ?></code></td>
								<td><?php echo esc_html( (string) $log->trigger_type ); ?></td>
								<td><?php echo esc_html( Jalali::format( (string) $log->created_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $total_pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}
}
