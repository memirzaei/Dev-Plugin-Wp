<?php
/**
 * Lightweight WordPress stubs for pure unit / smoke tests.
 *
 * @package FormulaPriceSync\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/fps-tests/' );
}
if ( ! defined( 'FPS_VERSION' ) ) {
	define( 'FPS_VERSION', '2.0.0' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}

$GLOBALS['fps_test_options']           = array();
$GLOBALS['fps_test_transients']        = array();
$GLOBALS['fps_test_filters']           = array();
$GLOBALS['fps_test_scheduled_actions'] = array();

function fps_test_reset_state(): void {
	$GLOBALS['fps_test_options']           = array();
	$GLOBALS['fps_test_transients']        = array();
	$GLOBALS['fps_test_filters']           = array();
	$GLOBALS['fps_test_scheduled_actions'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['fps_test_options'] )
			? $GLOBALS['fps_test_options'][ $option ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['fps_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $option, $GLOBALS['fps_test_options'] ) ) {
			return false;
		}
		$GLOBALS['fps_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		if ( ! array_key_exists( $option, $GLOBALS['fps_test_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['fps_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		if ( ! array_key_exists( $transient, $GLOBALS['fps_test_transients'] ) ) {
			return false;
		}
		$entry = $GLOBALS['fps_test_transients'][ $transient ];
		if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
			unset( $GLOBALS['fps_test_transients'][ $transient ] );
			return false;
		}
		return $entry['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		$GLOBALS['fps_test_transients'][ $transient ] = array(
			'value'   => $value,
			'expires' => $expiration > 0 ? time() + (int) $expiration : 0,
		);
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['fps_test_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['fps_test_filters'][ $tag ][ $priority ][] = array(
			'function'      => $function_to_add,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		if ( empty( $GLOBALS['fps_test_filters'][ $tag ] ) ) {
			return $value;
		}
		ksort( $GLOBALS['fps_test_filters'][ $tag ] );
		foreach ( $GLOBALS['fps_test_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$params = array_merge( array( $value ), array_slice( $args, 0, max( 0, $cb['accepted_args'] - 1 ) ) );
				$value  = call_user_func_array( $cb['function'], $params );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $tag = null, $priority = false ) {
		if ( null === $tag ) {
			$GLOBALS['fps_test_filters'] = array();
			return true;
		}
		if ( false === $priority ) {
			unset( $GLOBALS['fps_test_filters'][ $tag ] );
		} else {
			unset( $GLOBALS['fps_test_filters'][ $tag ][ $priority ] );
		}
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $function_to_add, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag, ...$args ) {
		if ( empty( $GLOBALS['fps_test_filters'][ $tag ] ) ) {
			return;
		}
		ksort( $GLOBALS['fps_test_filters'][ $tag ] );
		foreach ( $GLOBALS['fps_test_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				call_user_func_array( $cb['function'], array_slice( $args, 0, $cb['accepted_args'] ) );
			}
		}
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $function_to_check = false ) {
		return ! empty( $GLOBALS['fps_test_filters'][ $tag ] );
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}
		return $data;
	}
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		if ( is_serialized( $data ) ) {
			return @unserialize( $data );
		}
		return $data;
	}
}
if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data, $strict = true ) {
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' === $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}
		return (bool) preg_match( '/^[aOs]:/', $data );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return $data;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return $gmt ? gmdate( 'Y-m-d H:i:s' ) : date( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$found = false;
		return false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		return true;
	}
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
	function wp_cache_flush() {
		return true;
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) {
		echo $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() {
		return false;
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return false;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();
		public $error_data = array();
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( $code ) {
				$this->errors[ $code ][] = $message;
				if ( $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}
		public function get_error_message() {
			$messages = reset( $this->errors );
			return is_array( $messages ) ? (string) reset( $messages ) : '';
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
		$GLOBALS['fps_test_scheduled_actions'][] = array(
			'timestamp' => $timestamp,
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
		);
		return count( $GLOBALS['fps_test_scheduled_actions'] );
	}
}
if ( ! function_exists( 'as_next_scheduled_action' ) ) {
	function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
		foreach ( $GLOBALS['fps_test_scheduled_actions'] as $action ) {
			if ( $action['hook'] === $hook ) {
				return $action['timestamp'];
			}
		}
		return false;
	}
}
if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( $hook, $args = null, $group = '' ) {
		$GLOBALS['fps_test_scheduled_actions'] = array_values(
			array_filter(
				$GLOBALS['fps_test_scheduled_actions'],
				static function ( $a ) use ( $hook ) {
					return $a['hook'] !== $hook;
				}
			)
		);
		return true;
	}
}

class FPS_Test_wpdb {
	public $options            = 'wp_options';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $prefix             = 'wp_';
	public $last_query         = '';
	public $last_error         = '';

	public function prepare( $query, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			static function () use ( &$i, $args ) {
				$val = $args[ $i++ ] ?? '';
				if ( is_string( $val ) ) {
					return "'" . addslashes( $val ) . "'";
				}
				return (string) $val;
			},
			$query
		);
	}

	public function query( $sql ) {
		$this->last_query = $sql;
		if ( preg_match( "/UPDATE\s+{$this->options}\s+SET\s+option_value\s*=\s*'(.*?)'\s+WHERE\s+option_name\s*=\s*'(.*?)'\s+AND\s+option_value\s*=\s*'(.*?)'/s", $sql, $m ) ) {
			$new_val     = stripslashes( $m[1] );
			$key         = stripslashes( $m[2] );
			$old_val     = stripslashes( $m[3] );
			$current     = $GLOBALS['fps_test_options'][ $key ] ?? null;
			$current_ser = maybe_serialize( $current );
			if ( (string) $current_ser === (string) $old_val ) {
				$GLOBALS['fps_test_options'][ $key ] = maybe_unserialize( $new_val );
				return 1;
			}
			return 0;
		}
		if ( preg_match( "/DELETE\s+FROM\s+{$this->options}\s+WHERE\s+option_name\s*=\s*'(.*?)'\s+AND\s+option_value\s*=\s*'(.*?)'/s", $sql, $m ) ) {
			$key     = stripslashes( $m[1] );
			$val     = stripslashes( $m[2] );
			$current = $GLOBALS['fps_test_options'][ $key ] ?? null;
			if ( (string) maybe_serialize( $current ) === (string) $val ) {
				unset( $GLOBALS['fps_test_options'][ $key ] );
				return 1;
			}
			return 0;
		}
		return 0;
	}

	public function get_col( $query = null, $x = 0 ) {
		$this->last_query = (string) $query;
		$this->query_count++;
		return array();
	}

	public function get_results( $query = null, $output = OBJECT ) {
		$this->last_query = (string) $query;
		return array();
	}

	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$this->last_query = (string) $query;
		return null;
	}

	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
		$this->last_query = (string) $query;
		return null;
	}

	public function insert( $table, $data, $format = null ) {
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		return 1;
	}

	public function delete( $table, $where, $where_format = null ) {
		return 1;
	}
	public function esc_like( $text ) {
		return addcslashes( (string) $text, "_%%\\" );
	}
	public $query_count = 0;
}

$GLOBALS['wpdb'] = new FPS_Test_wpdb();
