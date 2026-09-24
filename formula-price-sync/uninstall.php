<?php
/**
 * Uninstall Formula Price Sync.
 *
 * @package FormulaPriceSync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'fps_process_product_chunk', array(), 'formula-price-sync' );
	as_unschedule_all_actions( 'fps_process_queue_continuation', array(), 'formula-price-sync' );
	as_unschedule_all_actions( 'fps_retry_product_update', array(), 'formula-price-sync' );
	as_unschedule_all_actions( 'fps_lock_meta_migration_batch', array(), 'formula-price-sync' );
	as_unschedule_all_actions( 'fps_queue_state_cleanup', array(), 'formula-price-sync' );
	as_unschedule_all_actions( 'fps_scheduled_rate_sync', array(), 'formula-price-sync' );
}

// Cancel plugin-owned Action Scheduler jobs before removing their state.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'fps_process_product_chunk', array(), 'fps-price-sync' );
	as_unschedule_all_actions( 'fps_process_queue_continuation', array(), 'fps-price-sync' );
	as_unschedule_all_actions( 'fps_retry_product_update', array(), 'fps-price-sync' );
	as_unschedule_all_actions( 'fps_lock_meta_migration_batch', array(), 'fps-price-sync' );
	as_unschedule_all_actions( 'fps_queue_state_cleanup', array(), 'fps-price-sync' );
	as_unschedule_all_actions( 'fps_scheduled_rate_sync', array(), 'fps-price-sync' );
}
if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'fps_license_revalidate' );
}

// Delete options.
$options = array(
	'fps_db_version',
	'fps_license_key',
	'fps_license_status',
	'fps_manual_rates',
	'fps_last_accepted_rates',
	'fps_navasan_api_key',
	'fps_options',
	'fps_rastchin_status',
	'fps_cron_token',
	'fps_cron_last_run',
	'fps_cron_running',
	'fps_queue_active_run',
	'fps_lock_meta_migrated',
	'fps_lock_meta_migration_state',
	'fps_trial_until',
	'fps_sms_provider',
	'fps_sms_api_key',
	'fps_sms_line_number',
	'fps_sms_recipient',
	'fps_telegram_bot_token',
	'fps_telegram_chat_id',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove plugin-owned options not known at build time while leaving unrelated plugins untouched.
$fps_option_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'fps_' ) . '%'
	)
);
foreach ( (array) $fps_option_names as $option_name ) {
	delete_option( $option_name );
}

// Delete all fps transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_fps_%'
	    OR option_name LIKE '_transient_timeout_fps_%'"
);

// Drop custom table.
$table = $wpdb->prefix . 'fps_price_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Remove all product meta keys.
$meta_keys = array(
	'_fps_enable',
	'_fps_source_type',
	'_fps_currency_code',
	'_fps_base_foreign_price',
	'_fps_wage_percent',
	'_fps_profit_percent',
	'_fps_tax_percent',
	'_fps_fixed_fee',
	'_fps_rounding_rule',
	'_fps_custom_formula',
	'_fps_last_synced',
	'_fps_price_locked',
);

foreach ( $meta_keys as $key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ), array( '%s' ) );
}

// Remove any plugin-owned post/user meta added in later releases.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_fps_' ) . '%'
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_fps_' ) . '%'
	)
);
