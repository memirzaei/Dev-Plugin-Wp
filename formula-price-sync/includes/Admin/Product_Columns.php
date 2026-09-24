<?php
/**
 * Adds a custom "قیمت محاسباتی طلا/ارز" column to the WooCommerce Products list table.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Admin;

use FormulaPriceSync\Engine\Calculator;
use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\Helpers\Formatter;
use FormulaPriceSync\Helpers\Jalali;
use FormulaPriceSync\Admin\Settings_API;
use FormulaPriceSync\Core\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Product_Columns {

	const COLUMN_ID = 'fps_formula_price';
	const CACHE_KEY = 'fps_product_formula_prices';
	const CACHE_TTL = 300;

	private static $rates_cache = null;
	private static $meta_cache = array();
	private static $options_cache = null;

	public static function init(): void {
		add_filter( 'manage_edit-product_columns', array( self::class, 'register_column' ), 99 );
		add_action( 'manage_product_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( self::class, 'sortable_column' ) );
		add_action( 'pre_get_posts', array( self::class, 'sort_by_column' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
	}

	public static function register_column( array $columns ): array {
		$options = self::get_options();
		if ( empty( $options['show_formula_column'] ) ) {
			return $columns;
		}
		$columns[ self::COLUMN_ID ] = esc_html__( 'قیمت محاسباتی طلا/ارز', 'formula-price-sync' );
		return $columns;
	}

	public static function render_column( string $column, int $post_id ) {
		if ( self::COLUMN_ID !== $column ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '<span class="fps-col-na">—</span>';
			return;
		}

		$show_column = self::should_show_column( $product );

		if ( ! $show_column ) {
			echo '<span class="fps-col-na">—</span>';
			return;
		}

		$meta = self::get_product_meta( $post_id );

		if ( 'yes' !== $meta['_fps_enable'] ) {
			echo '<span class="fps-col-na">—</span>';
			return;
		}

		try {
			$rates = self::get_rates();
			$price = self::calculate_product_price( $post_id, $meta, $rates );
		} catch ( \Throwable $e ) {
			$logger = Logger::get_instance();
			$logger->error( 'Failed to calculate product price', array(
				'product_id' => $post_id,
				'error'     => $e->getMessage(),
			) );
			echo '<span class="fps-col-na">—</span>';
			return;
		}

		if ( null === $price ) {
			echo '<span class="fps-col-na">—</span>';
			return;
		}

		$formatted = Settings_API::format_for_display( $price );

		$locked = (string) ( $meta['_fps_price_locked'] ?? 'no' );
		$status_class = ( 'yes' === $locked ) ? 'fps-pill-red' : 'fps-pill-green';
		$status_text = ( 'yes' === $locked )
			? esc_html__( 'قفل‌شده', 'formula-price-sync' )
			: esc_html__( 'فعال', 'formula-price-sync' );

		$last_synced = ! empty( $meta['_fps_last_synced'] ) ? $meta['_fps_last_synced'] : '';
		$last_synced_display = '';
		if ( $last_synced ) {
			$last_synced_display = ' | ' . esc_html__( 'آخرین همگام‌سازی:', 'formula-price-sync' ) . ' ' . self::format_time_persian( $last_synced );
		}

		echo '<div class="fps-col-wrapper">';
		echo '<div class="fps-col-price">' . esc_html( $formatted ) . '</div>';
		echo '<div class="fps-col-status">' . Formatter::to_persian_num( wp_kses_post( $status_text ) ) . '</div>';
		if ( 'yes' === $meta['_fps_enable'] && 'yes' !== $locked ) {
			echo '<div class="fps-col-badge ' . esc_attr( $status_class ) . '">' . implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $status_text) ) ) . '</div>';
		}
		if ( $last_synced_display ) {
			echo '<div class="fps-col-synced">' . $last_synced_display . '</div>';
		}
		echo '</div>';
	}

	private static function get_options(): array {
		if ( null === self::$options_cache ) {
			self::$options_cache = get_option( 'fps_options', array() );
		}
		return self::$options_cache;
	}

	private static function should_show_column( $product ): bool {
		$options = self::get_options();
		$type = $product->get_type();

		if ( 'simple' === $type ) {
			return (bool) ( $options['show_formula_column_simple'] ?? true );
		}

		if ( 'variation' === $type ) {
			return (bool) ( $options['show_formula_column_variable'] ?? true );
		}

		return false;
	}

	private static function get_product_meta( int $product_id ): array {
		if ( isset( self::$meta_cache[ $product_id ] ) ) {
			return self::$meta_cache[ $product_id ];
		}

		$keys = array(
			'_fps_enable',
			'_fps_price_locked',
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
			'_fps_last_synced',
		);

		$meta = array();
		foreach ( $keys as $key ) {
			$value = get_post_meta( $product_id, $key, true );
			$meta[ $key ] = $value;
			if ( null === $value && '_fps_price_locked' === $key ) {
				$meta[ $key ] = 'no';
			}
		}

		self::$meta_cache[ $product_id ] = $meta;
		return $meta;
	}

	private static function get_rates(): array {
		if ( null !== self::$rates_cache ) {
			return self::$rates_cache;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			self::$rates_cache = $cached;
			return self::$rates_cache;
		}

		try {
			$api                 = new API_Manager();
			self::$rates_cache   = $api->get_rates();
			set_transient( self::CACHE_KEY, self::$rates_cache, self::CACHE_TTL );
		} catch ( \Throwable $e ) {
			self::$rates_cache = array();
		}

		return self::$rates_cache;
	}

	private static function calculate_product_price( int $product_id, array $meta, array $rates ): ?float {
		if ( 'yes' === $meta['_fps_price_locked'] ) {
			return null;
		}

		$source_type = ! empty( $meta['_fps_source_type'] ) ? $meta['_fps_source_type'] : 'gold_18k';

		$source_rate = 0;
		switch ( $source_type ) {
			case 'gold_18k':
				$source_rate = isset( $rates['gold_18k'] ) ? (float) $rates['gold_18k'] : 0;
				break;
			case 'gold_24k':
				$source_rate = isset( $rates['gold_24k'] ) ? (float) $rates['gold_24k'] : 0;
				break;
			case 'coin':
				$source_rate = isset( $rates['coin'] ) ? (float) $rates['coin'] : 0;
				break;
			case 'currency':
				$code = sanitize_key( (string) ( $meta['_fps_currency_code'] ?? '' ) );
				if ( ! in_array( $code, array( 'usd', 'eur' ), true ) ) {
					return null;
				}
				if ( isset( $rates[ $code ] ) && is_numeric( $rates[ $code ] ) && (float) $rates[ $code ] > 0 ) {
					$source_rate = (float) $rates[ $code ];
				}
				break;
			case 'custom_formula':
				$source_rate = isset( $rates['gold_18k'] ) ? (float) $rates['gold_18k'] : 0;
				break;
		}

		if ( $source_rate <= 0 ) {
			return null;
		}

		$meta_data = array(
			'source_type'        => $source_type,
			'currency_code'      => sanitize_key( (string) ( $meta['_fps_currency_code'] ?? '' ) ),
			'weight'             => (float) ( $meta['_fps_base_foreign_price'] ?? 0 ),
			'base_foreign_price' => (float) ( $meta['_fps_base_foreign_price'] ?? 0 ),
			'wage_percent'       => (float) ( $meta['_fps_wage_percent'] ?? 0 ),
			'profit_percent'     => (float) ( $meta['_fps_profit_percent'] ?? 0 ),
			'tax_percent'        => metadata_exists( 'post', $product_id, '_fps_tax_percent' ) ? (float) $meta['_fps_tax_percent'] : 9.0,
			'tax_mode'           => ! empty( $meta['_fps_tax_mode'] ) ? $meta['_fps_tax_mode'] : 'total',
			'fixed_fee'          => (float) ( $meta['_fps_fixed_fee'] ?? 0 ),
			'rounding_rule'      => $meta['_fps_rounding_rule'] ?? 'none',
			'custom_formula'     => (string) ( $meta['_fps_custom_formula'] ?? '' ),
		);

		$result = Calculator::calculate_price( $meta_data, $source_rate );
		return $result['final_price'] > 0 ? $result['final_price'] : null;
	}

	private static function format_time_persian( string $time_str ): string {
		$timestamp = strtotime( $time_str );
		if ( ! $timestamp ) {
			return '';
		}

		$date_parts = Jalali::format( $timestamp, 'Y/m/d H:i' );
		return Formatter::to_persian_num( $date_parts );
	}

	public static function sortable_column( array $columns ): array {
		$options = self::get_options();
		if ( empty( $options['show_formula_column'] ) ) {
			return $columns;
		}
		$columns[ self::COLUMN_ID ] = self::COLUMN_ID;
		return $columns;
	}

	public static function sort_by_column( $query ): void {
		if (
			! is_admin()
			|| ! $query->is_main_query()
			|| 'product' !== $query->get( 'post_type' )
			|| self::COLUMN_ID !== $query->get( 'orderby' )
		) {
			return;
		}

		$query->set( 'meta_key', '_price' );
		$query->set( 'orderby', 'meta_value_num' );
	}

	public static function enqueue_styles( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}

		wp_enqueue_style( 'fps-admin', FPS_URL . 'assets/css/admin.css', array(), FPS_VERSION );
	}

	public static function clear_meta_cache(): void {
		self::$meta_cache = array();
	}

	public static function clear_rates_cache(): void {
		self::$rates_cache = null;
	}
}
