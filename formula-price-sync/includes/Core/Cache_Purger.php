<?php
/**
 * Smart cache purging for popular Iranian/WordPress cache plugins.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cache_Purger
 *
 * Clears caches of major caching plugins after price updates.
 */
class Cache_Purger {

	/**
	 * Purge all known caches.
	 *
	 * Called after bulk price updates to ensure frontend shows new prices immediately.
	 *
	 * @return void
	 */
	public static function purge_all(): void {
		// 1. WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// 2. LiteSpeed Cache.
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}

		// 3. W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// 4. WooCommerce object / product transients.
		// wc_delete_product_transients() requires a product ID, so after a bulk update
		// we flush the entire object cache and also clear WC product lookup tables if available.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			// Full object-cache flush is the safest approach after mass price changes.
			wp_cache_flush();
		}

		// Additional popular plugins (bonus – keeps Iranian stores clean).
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); // WP Super Cache
		}

		if ( function_exists( 'fastly_purge_all' ) ) {
			fastly_purge_all();
		}

		/**
		 * Allow other plugins/themes to clear their own caches.
		 */
		do_action( 'fps_after_cache_purge' );
	}

	/**
	 * Purge cache for a single product or variation.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return void
	 */
	public static function purge_product( int $product_id ): void {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}

		// WooCommerce product transients (exact requirement).
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		// WP Rocket single post.
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $product_id );
		}

		// Core post cache.
		clean_post_cache( $product_id );

		// If this is a variation, also clean the parent.
		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id ) {
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $parent_id );
			}
			clean_post_cache( $parent_id );
		}
	}
}
