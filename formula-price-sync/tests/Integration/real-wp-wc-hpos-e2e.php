<?php
/**
 * Real WordPress + WooCommerce + HPOS queue E2E gate for FPS-R05/R10.
 *
 * Usage via WP-CLI:
 *   wp eval-file tests/Integration/real-wp-wc-hpos-e2e.php prepare --allow-root
 *   wp action-scheduler run --hooks=fps_process_queue_continuation --group=fps-price-sync --force --allow-root
 *   wp eval-file tests/Integration/real-wp-wc-hpos-e2e.php verify --allow-root
 */

declare(strict_types=1);

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Variable' ) ) {
    fwrite( STDERR, "WooCommerce is required\n" );
    exit( 1 );
}

$mode = $argv[0] ?? ( $argv[1] ?? '' );
$mode = is_string( $mode ) ? strtolower( $mode ) : '';

if ( 'prepare' === $mode ) {
    delete_option( 'fps_r17_e2e_run' );
    delete_option( 'fps_r17_e2e_cache_purged' );

    // CI-only valid license state. No commercial token is used.
    update_option( '\FormulaPriceSync\Licensing\License_Guard::STATUS_OPTION', 'valid', false );
    set_transient(
        'fps_license_validation',
        array(
            'valid'      => true,
            'time'       => time(),
            'expires_at' => time() + DAY_IN_SECONDS,
        ),
        DAY_IN_SECONDS
    );

    if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
        throw new RuntimeException( 'WPMU_PLUGIN_DIR unavailable' );
    }
    if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
        wp_mkdir_p( WPMU_PLUGIN_DIR );
    }
    $mu_file = WPMU_PLUGIN_DIR . '/fps-r17-e2e-cache.php';
    file_put_contents(
        $mu_file,
        "<?php add_action('fps_after_cache_purge', function(){ update_option('fps_r17_e2e_cache_purged', 1, false); });"
    );

    $parent = new WC_Product_Variable();
    $parent->set_name( 'FPS R17 E2E Parent' );
    $parent->set_status( 'publish' );
    $parent->set_regular_price( '100' );
    $parent->save();

    $variation_a = new WC_Product_Variation();
    $variation_a->set_parent_id( $parent->get_id() );
    $variation_a->set_status( 'publish' );
    $variation_a->set_regular_price( '100' );
    $variation_a->set_price( '100' );
    $variation_a->save();

    $variation_b = new WC_Product_Variation();
    $variation_b->set_parent_id( $parent->get_id() );
    $variation_b->set_status( 'publish' );
    $variation_b->set_regular_price( '200' );
    $variation_b->set_price( '200' );
    $variation_b->save();

    foreach ( array( $variation_a->get_id(), $variation_b->get_id() ) as $id ) {
        update_post_meta( $id, '_fps_enable', 'yes' );
        update_post_meta( $id, '_fps_source_type', 'gold_18k' );
        update_post_meta( $id, '_fps_base_foreign_price', '1' );
        update_post_meta( $id, '_fps_wage_percent', '0' );
        update_post_meta( $id, '_fps_profit_percent', '0' );
        update_post_meta( $id, '_fps_tax_percent', '0' );
        update_post_meta( $id, '_fps_tax_mode', 'total' );
        update_post_meta( $id, '_fps_fixed_fee', '0' );
        update_post_meta( $id, '_fps_rounding_rule', 'none' );
    }
    update_post_meta( $variation_a->get_id(), '_fps_price_locked', 'no' );
    update_post_meta( $variation_b->get_id(), '_fps_price_locked', 'yes' );
    update_post_meta( $parent->get_id(), '_fps_enable', 'no' );

    $run_id = 'fps_r17_e2e_' . wp_generate_uuid4();
    $rates = array(
        'usd'      => 100,
        'eur'      => 120,
        'gold_18k' => 5000,
        'gold_24k' => 6500,
        'coin'     => 7000000,
        'source'   => 'r17-e2e',
        'timestamp'=> time(),
    );
    $snapshot_id = \FormulaPriceSync\API\Rate_Snapshot_Store::persist_for_run( $run_id, $rates );
    if ( '' === $snapshot_id ) {
        throw new RuntimeException( 'Could not persist E2E rate snapshot' );
    }

    update_option(
        'fps_queue_run_' . $run_id,
        array(
            'run_id'           => $run_id,
            'trigger_type'     => 'e2e',
            'filters'          => array(),
            'snapshot_id'      => $snapshot_id,
            'next_id'          => 0,
            'state'            => 'queued',
            'chunks_completed' => 0,
            'products_seen'    => 0,
            'products_updated' => 0,
            'attempts'         => 0,
            'last_error'       => '',
            'created_at'       => time(),
            'updated_at'       => time(),
        ),
        false
    );

    if ( ! function_exists( 'as_schedule_single_action' ) ) {
        throw new RuntimeException( 'Action Scheduler is not available' );
    }
    $action_id = as_schedule_single_action(
        time(),
        'fps_process_queue_continuation',
        array( 'run_id' => $run_id ),
        'fps-price-sync',
        true
    );
    if ( ! $action_id ) {
        throw new RuntimeException( 'Could not schedule continuation action' );
    }

    update_option(
        'fps_r17_e2e_run',
        array(
            'run_id'       => $run_id,
            'snapshot_id'  => $snapshot_id,
            'parent_id'    => $parent->get_id(),
            'variation_a'  => $variation_a->get_id(),
            'variation_b'  => $variation_b->get_id(),
            'old_a'        => 100,
            'old_b'        => 200,
            'old_parent'  => 100,
            'mu_file'     => $mu_file,
        ),
        false
    );

    echo "R17_E2E_PREPARED run={$run_id} snapshot={$snapshot_id}\n";
    exit( 0 );
}

if ( 'verify' === $mode ) {
    $state = get_option( 'fps_r17_e2e_run', array() );
    if ( ! is_array( $state ) || empty( $state['run_id'] ) ) {
        throw new RuntimeException( 'E2E state is missing' );
    }

    $run_id = (string) $state['run_id'];
    $snapshot_id = (string) $state['snapshot_id'];
    $parent_id = absint( $state['parent_id'] );
    $a_id = absint( $state['variation_a'] );
    $b_id = absint( $state['variation_b'] );

    $run = get_option( 'fps_queue_run_' . $run_id, array() );
    if ( ! is_array( $run ) || 'completed' !== ( $run['state'] ?? '' ) ) {
        throw new RuntimeException( 'Queue run did not complete: ' . wp_json_encode( $run ) );
    }

    $a = wc_get_product( $a_id );
    $b = wc_get_product( $b_id );
    $parent = wc_get_product( $parent_id );
    if ( ! $a || ! $b || ! $parent ) {
        throw new RuntimeException( 'Fixture products missing after queue execution' );
    }
    if ( (float) $a->get_price( 'edit' ) !== 5000.0 ) {
        throw new RuntimeException( 'Variation A expected 5000, got ' . $a->get_price( 'edit' ) );
    }
    if ( (float) $b->get_price( 'edit' ) !== 200.0 ) {
        throw new RuntimeException( 'Locked variation B mutated unexpectedly: ' . $b->get_price( 'edit' ) );
    }
    if ( (float) $parent->get_price( 'edit' ) !== 100.0 ) {
        throw new RuntimeException( 'Parent product mutated unexpectedly: ' . $parent->get_price( 'edit' ) );
    }

    global $wpdb;
    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT product_id, variation_id, old_price, new_price, snapshot_id FROM {$wpdb->prefix}fps_price_logs WHERE product_id = %d ORDER BY id DESC LIMIT 5",
            $a_id
        )
    );
    if ( empty( $logs ) || (int) $logs[0]->variation_id !== $a_id || (string) $logs[0]->snapshot_id !== $snapshot_id ) {
        throw new RuntimeException( 'Variation A audit log is missing or has wrong variation/snapshot correlation' );
    }

    $purged = (int) get_option( 'fps_r17_e2e_cache_purged', 0 );
    if ( 1 !== $purged ) {
        throw new RuntimeException( 'Bulk cache purge marker was not observed' );
    }

    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
        if ( ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            throw new RuntimeException( 'HPOS is not enabled for the G3 certification run' );
        }
    }

    $mu_file = isset( $state['mu_file'] ) ? (string) $state['mu_file'] : '';
    if ( $mu_file && file_exists( $mu_file ) ) {
        @unlink( $mu_file );
    }
    delete_option( 'fps_r17_e2e_run' );
    delete_option( 'fps_queue_run_' . $run_id );
    delete_option( 'fps_run_complete_' . $run_id );
    delete_option( 'fps_r17_e2e_cache_purged' );
    wp_delete_post( $a_id, true );
    wp_delete_post( $b_id, true );
    wp_delete_post( $parent_id, true );

    echo "REAL_WP_WC_HPOS_E2E_PASS run={$run_id} snapshot={$snapshot_id}\n";
    exit( 0 );
}

fwrite( STDERR, "Usage: prepare|verify\n" );
exit( 2 );
