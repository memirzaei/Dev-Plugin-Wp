<?php
/**
 * Centralized Logger for Formula Price Sync.
 *
 * Provides structured logging with configurable levels and output.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	const OPTION_ENABLED     = 'fps_log_enabled';
	const OPTION_LEVEL      = 'fps_log_level';
	const OPTION_RETENTION  = 'fps_log_retention_days';

	const LEVEL_DEBUG   = 'debug';
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	const TRANSIENT_LOG   = 'fps_error_log';
	const MAX_LOG_ENTRIES = 100;

	private static $instance = null;
	private $enabled;
	private $level;
	private $levels_order;

	public static function init(): void {
		self::$instance = new self();
		self::$instance->load_settings();
		add_action( 'fps_log_cleanup', array( self::class, 'cleanup_old_logs' ) );
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->load_settings();
		}
		return self::$instance;
	}

	private function load_settings(): void {
		$options    = get_option( 'fps_options', array() );
		$this->enabled = $this->is_logging_enabled();
		$this->level  = isset( $options['log_level'] ) ? $options['log_level'] : self::LEVEL_INFO;

		$this->levels_order = array(
			self::LEVEL_DEBUG   => 0,
			self::LEVEL_INFO    => 1,
			self::LEVEL_WARNING => 2,
			self::LEVEL_ERROR   => 3,
		);
	}

	public function is_logging_enabled(): bool {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return true;
		}
		$options = get_option( 'fps_options', array() );
		return ! empty( $options['log_enabled'] );
	}

	public function should_log( string $level ): bool {
		if ( ! $this->enabled ) {
			return false;
		}

		if ( ! isset( $this->levels_order[ $level ] ) ) {
			$level = self::LEVEL_INFO;
		}

		$current_level_value = isset( $this->levels_order[ $this->level ] )
			? $this->levels_order[ $this->level ]
			: $this->levels_order[ self::LEVEL_INFO ];

		$message_level_value = $this->levels_order[ $level ];

		return $message_level_value >= $current_level_value;
	}

	public function debug( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context );
	}

	public function info( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_INFO, $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_WARNING, $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->log( self::LEVEL_ERROR, $message, $context );
	}

	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$message = $this->redact( $message );
		$context = $this->redact_context( $context );
		$entry = $this->format_entry( $level, $message, $context );

		if ( defined( 'FPS_LOG_TO_ERROR_LOG' ) && FPS_LOG_TO_ERROR_LOG ) {
			error_log( $entry['text'] );
		}

		$this->store_entry( $entry );
	}

	private function format_entry( string $level, string $message, array $context ): array {
		$timestamp = current_time( 'Y-m-d H:i:s' );

		$text = sprintf(
			'[FPS][%s][%s] %s',
			strtoupper( $level ),
			$timestamp,
			$message
		);

		if ( ! empty( $context ) ) {
			$text .= ' ' . json_encode( $context, JSON_UNESCAPED_UNICODE );
		}

		return array(
			'level'     => $level,
			'timestamp' => $timestamp,
			'message'   => $message,
			'context'  => $context,
			'text'      => $text,
		);
	}

	private function redact( string $value ): string {
		$value = preg_replace( '/(api[_-]?key|token|secret|authorization|password|bot)[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $value );
		return is_string( $value ) ? substr( $value, 0, 2000 ) : '';
	}

	private function redact_context( array $context ): array {
		$secret_keys = array( 'token', 'api_key', 'secret', 'password', 'authorization', 'bot_token' );
		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $secret_keys, true ) ) {
				$context[ $key ] = '[REDACTED]';
			} elseif ( is_string( $value ) ) {
				$context[ $key ] = $this->redact( $value );
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = $this->redact_context( $value );
			}
		}
		return $context;
	}

	private function store_entry( array $entry ): void {
		$log = get_transient( self::TRANSIENT_LOG );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = $entry;

		if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_LOG_ENTRIES );
		}

		set_transient( self::TRANSIENT_LOG, $log, DAY_IN_SECONDS * 7 );
	}

	public function get_entries( int $limit = 50, string $level = '' ): array {
		$log = get_transient( self::TRANSIENT_LOG );

		if ( ! is_array( $log ) ) {
			return array();
		}

		if ( ! empty( $level ) && isset( $this->levels_order[ $level ] ) ) {
			$log = array_filter(
				$log,
				function ( $entry ) use ( $level ) {
					return $entry['level'] === $level;
				}
			);
		}

		return array_slice( array_reverse( $log ), 0, $limit );
	}

	public function get_stats(): array {
		$log = get_transient( self::TRANSIENT_LOG );

		if ( ! is_array( $log ) ) {
			return array(
				'total'     => 0,
				'debug'     => 0,
				'info'      => 0,
				'warning'   => 0,
				'error'     => 0,
				'oldest'    => null,
				'newest'    => null,
			);
		}

		$stats = array(
			'total'   => count( $log ),
			'debug'   => 0,
			'info'    => 0,
			'warning' => 0,
			'error'   => 0,
			'oldest'  => null,
			'newest'  => null,
		);

		foreach ( $log as $entry ) {
			if ( isset( $stats[ $entry['level'] ] ) ) {
				++$stats[ $entry['level'] ];
			}
		}

		if ( ! empty( $log ) ) {
			$stats['oldest'] = $log[0]['timestamp'] ?? null;
			$stats['newest'] = end( $log )['timestamp'] ?? null;
		}

		return $stats;
	}

	public static function cleanup_old_logs(): void {
		delete_transient( self::TRANSIENT_LOG );
	}

	public static function register_settings(): void {
		register_setting(
			'fps_options_group',
			self::OPTION_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		register_setting(
			'fps_options_group',
			self::OPTION_LEVEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'default'           => self::LEVEL_INFO,
			)
		);

		register_setting(
			'fps_options_group',
			self::OPTION_RETENTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 7,
			)
		);
	}

	public static function get_enabled(): bool {
		$logger = self::get_instance();
		return $logger->is_logging_enabled();
	}

	public static function debug_enabled(): bool {
		return (bool) get_option( self::OPTION_LEVEL, self::LEVEL_INFO ) === self::LEVEL_DEBUG
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}
}
