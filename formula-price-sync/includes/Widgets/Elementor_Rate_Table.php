<?php
/**
 * Elementor Rate Table widget.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Widgets;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\API\Circuit_Breaker;
use FormulaPriceSync\Helpers\Formatter;
use FormulaPriceSync\Licensing\License_Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays selected RateMatic rates without fetching providers during render.
 */
final class Elementor_Rate_Table extends \Elementor\Widget_Base {

	/**
	 * Rate metadata presented to editors.
	 *
	 * @var array<string,string>
	 */
	const RATE_OPTIONS = array(
		'usd'      => 'دلار',
		'eur'      => 'یورو',
		'gold_18k' => 'طلای ۱۸ عیار',
		'gold_24k' => 'طلای ۲۴ عیار',
		'coin'     => 'سکه',
	);

	public function get_name(): string {
		return 'fps-rate-table';
	}

	public function get_title(): string {
		return esc_html__( 'جدول نرخ نرخ‌ماتیک', 'formula-price-sync' );
	}

	public function get_icon(): string {
		return 'eicon-table';
	}

	/**
	 * @return array<int,string>
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * @return array<int,string>
	 */
	public function get_keywords(): array {
		return array( 'ratematic', 'rate', 'currency', 'gold', 'نرخ', 'طلا', 'ارز' );
	}

	/**
	 * @return array<int,string>
	 */
	public function get_style_depends(): array {
		return array( 'fps-elementor-rate-table' );
	}

	/**
	 * Register Elementor controls.
	 *
	 * @return void
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'نرخ‌ها', 'formula-price-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => esc_html__( 'عنوان', 'formula-price-sync' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => esc_html__( 'آخرین نرخ‌ها', 'formula-price-sync' ),
				'placeholder' => esc_html__( 'آخرین نرخ‌ها', 'formula-price-sync' ),
			)
		);

		$repeater = new \Elementor\Repeater();
		$repeater->add_control(
			'rate_key',
			array(
				'label'   => esc_html__( 'نرخ', 'formula-price-sync' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => self::RATE_OPTIONS,
				'default' => 'usd',
			)
		);

		$this->add_control(
			'rates',
			array(
				'label'       => esc_html__( 'موارد جدول', 'formula-price-sync' ),
				'type'        => \Elementor\Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array( 'rate_key' => 'usd' ),
					array( 'rate_key' => 'gold_18k' ),
				),
				'title_field' => '{{{ rate_key }}}',
			)
		);

		$this->add_control(
			'show_change',
			array(
				'label'        => esc_html__( 'نمایش درصد تغییر', 'formula-price-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'بله', 'formula-price-sync' ),
				'label_off'    => esc_html__( 'خیر', 'formula-price-sync' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'show_source',
			array(
				'label'        => esc_html__( 'نمایش منبع', 'formula-price-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_last_update',
			array(
				'label'        => esc_html__( 'نمایش زمان به‌روزرسانی', 'formula-price-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'dark_mode',
			array(
				'label'        => esc_html__( 'حالت تیره', 'formula-price-sync' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'style_section',
			array(
				'label' => esc_html__( 'استایل جدول', 'formula-price-sync' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'rate_typography',
				'selector' => '{{WRAPPER}} .fps-rate-table__value',
			)
		);

		$this->add_responsive_control(
			'item_spacing',
			array(
				'label'      => esc_html__( 'فاصله آیتم‌ها', 'formula-price-sync' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 64,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 12,
				),
				'selectors'  => array(
					'{{WRAPPER}} .fps-rate-table__list' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget.
	 *
	 * @return void
	 */
	protected function render(): void {
		if ( License_Guard::should_block() ) {
			echo '<div class="fps-rate-table fps-rate-table--blocked" dir="rtl">';
			echo esc_html__( 'برای نمایش نرخ‌ها، لایسنس معتبر نرخ‌ماتیک لازم است.', 'formula-price-sync' );
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();
		$snapshot = self::get_existing_rate_snapshot();
		$items    = self::get_selected_items( $settings );
		$rows     = array();

		foreach ( $items as $item ) {
			$key = sanitize_key( isset( $item['rate_key'] ) ? (string) $item['rate_key'] : '' );
			if ( ! isset( self::RATE_OPTIONS[ $key ] ) || ! self::is_rate_value( $snapshot[ $key ] ?? null ) ) {
				continue;
			}
			$rows[] = self::build_row( $key, $snapshot, $settings );
		}

		$classes = array( 'fps-rate-table' );
		if ( 'yes' === (string) ( $settings['dark_mode'] ?? '' ) ) {
			$classes[] = 'fps-rate-table--dark';
		}

		echo '<section class="' . esc_attr( implode( ' ', $classes ) ) . '" dir="rtl">';

		$title = isset( $settings['title'] ) ? trim( (string) $settings['title'] ) : '';
		if ( '' !== $title ) {
			echo '<h3 class="fps-rate-table__title">' . esc_html( $title ) . '</h3>';
		}

		if ( empty( $rows ) ) {
			echo '<div class="fps-rate-table__empty">';
			echo esc_html__( 'نرخ معتبری برای نمایش در دسترس نیست.', 'formula-price-sync' );
			echo '</div></section>';
			return;
		}

		echo '<div class="fps-rate-table__list">';
		foreach ( $rows as $row ) {
			echo '<article class="fps-rate-table__item">';
			echo '<div class="fps-rate-table__name">' . esc_html( $row['label'] ) . '</div>';
			echo '<div class="fps-rate-table__value">' . esc_html( $row['value'] ) . '</div>';
			if ( null !== $row['change'] ) {
				echo '<div class="fps-rate-table__change">' . esc_html( $row['change'] ) . '%</div>';
			}
			echo '</article>';
		}
		echo '</div>';

		$source  = self::source_label( (string) ( $snapshot['source'] ?? '' ) );
		$updated = self::format_timestamp( $snapshot['timestamp'] ?? 0 );
		$meta    = array();
		if ( 'yes' === (string) ( $settings['show_source'] ?? '' ) && '' !== $source ) {
			$meta[] = sprintf(
				/* translators: %s: provider/source label */
				esc_html__( 'منبع: %s', 'formula-price-sync' ),
				$source
			);
		}
		if ( 'yes' === (string) ( $settings['show_last_update'] ?? '' ) && '' !== $updated ) {
			$meta[] = sprintf(
				/* translators: %s: last update time */
				esc_html__( 'آخرین بروزرسانی: %s', 'formula-price-sync' ),
				$updated
			);
		}
		if ( ! empty( $meta ) ) {
			echo '<div class="fps-rate-table__meta">' . implode( ' · ', $meta ) . '</div>';
		}
		echo '</section>';
	}

	/**
	 * Read existing cached/accepted rates without triggering a provider request.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_existing_rate_snapshot(): array {
		$cached = get_transient( API_Manager::CACHE_KEY );
		if ( is_array( $cached ) && self::snapshot_has_rates( $cached ) ) {
			return $cached;
		}

		$accepted = get_option( Circuit_Breaker::LAST_ACCEPTED_OPTION, array() );
		return is_array( $accepted ) && self::snapshot_has_rates( $accepted ) ? $accepted : array();
	}

	/**
	 * @param array<string,mixed> $snapshot Snapshot data.
	 * @return bool
	 */
	private static function snapshot_has_rates( array $snapshot ): bool {
		foreach ( array_keys( self::RATE_OPTIONS ) as $key ) {
			if ( self::is_rate_value( $snapshot[ $key ] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_selected_items( array $settings ): array {
		$items = isset( $settings['rates'] ) && is_array( $settings['rates'] ) ? $settings['rates'] : array();
		if ( empty( $items ) ) {
			$items = array(
				array( 'rate_key' => 'usd' ),
				array( 'rate_key' => 'gold_18k' ),
			);
		}
		return $items;
	}

	/**
	 * @param string              $key      Rate key.
	 * @param array<string,mixed> $snapshot Snapshot data.
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array{label:string,value:string,change:?string}
	 */
	private static function build_row( string $key, array $snapshot, array $settings ): array {
		$change = null;
		if ( 'yes' === (string) ( $settings['show_change'] ?? '' ) ) {
			$change_value = self::get_change_percent( $snapshot, $key );
			if ( null !== $change_value ) {
				$change = number_format( $change_value, 2, '.', '' );
			}
		}

		return array(
			'label'  => self::RATE_OPTIONS[ $key ],
			'value'  => Formatter::format_price( (float) $snapshot[ $key ] ),
			'change' => $change,
		);
	}

	/**
	 * @param array<string,mixed> $snapshot Snapshot data.
	 * @param string              $key      Rate key.
	 * @return float|null
	 */
	private static function get_change_percent( array $snapshot, string $key ): ?float {
		$changes = $snapshot['changes'] ?? null;
		if ( is_array( $changes ) && isset( $changes[ $key ] ) && is_numeric( $changes[ $key ] ) ) {
			return (float) $changes[ $key ];
		}

		$field = $key . '_change_percent';
		if ( isset( $snapshot[ $field ] ) && is_numeric( $snapshot[ $field ] ) ) {
			return (float) $snapshot[ $field ];
		}

		return null;
	}

	/**
	 * @param mixed $value Candidate rate.
	 * @return bool
	 */
	private static function is_rate_value( $value ): bool {
		return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0;
	}

	/**
	 * @param string $source Source key.
	 * @return string
	 */
	private static function source_label( string $source ): string {
		$labels = array(
			'tgju'    => 'TGJU',
			'navasan' => 'نوانسان',
			'nobitex' => 'نوبیتکس',
			'manual'  => 'دستی',
		);
		return $labels[ sanitize_key( $source ) ] ?? '';
	}

	/**
	 * @param mixed $timestamp Snapshot timestamp.
	 * @return string
	 */
	private static function format_timestamp( $timestamp ): string {
		$timestamp = absint( $timestamp );
		if ( 0 === $timestamp ) {
			return '';
		}
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'Y-m-d H:i', $timestamp );
		}
		return gmdate( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * @return array<string,string>
	 */
	private static function rate_labels(): array {
		return self::RATE_OPTIONS;
	}
}
