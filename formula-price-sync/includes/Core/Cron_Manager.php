<?php
/**
 * Server-side cron manager for Formula Price Sync.
 *
 * Provides a secure query-var endpoint that server-level crontabs
 * (curl / wget) can hit to trigger background price synchronization,
 * independent of WordPress WP-Cron and user traffic.
 *
 * Example crontab entry (run every hour):
 *   0 * * * * curl -fsS -H "Authorization: Bearer YOUR_SECRET_TOKEN" "https://yoursite.com/?fps_action=cron_sync" > /dev/null
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Core;

use FormulaPriceSync\API\API_Manager;
use FormulaPriceSync\Core\Cache_Purger;
use FormulaPriceSync\Core\Atomic_Option_Lock;
use FormulaPriceSync\Licensing\License_Guard;
use FormulaPriceSync\Core\Logger;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron_Manager {

	const OPTION_KEY       = 'fps_cron_token';
	const QUERY_VAR        = 'fps_action';
	const QUERY_VALUE      = 'cron_sync';
	const RATE_LIMIT_KEY   = 'fps_cron_last_run';
	const RATE_LIMIT_SECS  = 300; // 5 minutes between accepted cron runs.
	const LOCK_KEY         = 'fps_cron_running';
	const LOCK_TTL         = 15 * MINUTE_IN_SECONDS;

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_filter( 'query_vars', array( self::class, 'register_query_var' ) );
		add_action( 'parse_request', array( self::class, 'handle_request' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Register the custom query variable.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Handle incoming request when the query var is present.
	 *
	 * @param \WP $wp WordPress object.
	 * @return void
	 */
	public static function handle_request( \WP $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		if ( self::QUERY_VALUE !== $wp->query_vars[ self::QUERY_VAR ] ) {
			return;
		}

		self::handle_cron_request();
	}

	/**
	 * Process a server-cron sync request.
	 */
	public static function handle_cron_request() {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		$request = self::process_authenticated_request( self::get_bearer_token() );
		self::respond( $request['status'], wp_json_encode( $request['body'] ) );
	}

	/**
	 * Process an authenticated cron request without terminating the PHP worker.
	 * This is the deterministic service boundary used by integration tests.
	 *
	 * @param string $token Extracted bearer token.
	 * @return array{status:int,body:array}
	 */
	public static function process_authenticated_request( string $token ): array {
		if ( '' === $token ) {
			return array( 'status' => 401, 'body' => array( 'ok' => false, 'code' => 'missing_token', 'message' => 'Unauthorized.' ) );
		}

		$stored_token = self::get_token();
		if ( '' === $stored_token ) {
			return array( 'status' => 401, 'body' => array( 'ok' => false, 'code' => 'token_not_configured', 'message' => 'Cron token is not configured.' ) );
		}

		if ( ! hash_equals( $stored_token, $token ) ) {
			return array( 'status' => 401, 'body' => array( 'ok' => false, 'code' => 'invalid_token', 'message' => 'Unauthorized.' ) );
		}

		$owner = self::acquire_lock();
		if ( '' === $owner ) {
			return array( 'status' => 429, 'body' => array( 'ok' => false, 'code' => 'locked', 'message' => 'Cron sync is already running.' ) );
		}

		try {
			if ( self::is_rate_limited() ) {
				return array( 'status' => 429, 'body' => array( 'ok' => false, 'code' => 'rate_limited', 'message' => 'Cron rate limit is active.' ) );
			}

			$result = self::run_sync();
			if ( is_wp_error( $result ) ) {
				return array( 'status' => 500, 'body' => array( 'ok' => false, 'code' => $result->get_error_code(), 'message' => 'Sync failed.' ) );
			}

			self::set_rate_limit();
			return array(
				'status' => 200,
				'body'   => array(
					'ok'        => true,
					'chunks'    => absint( $result['chunks'] ),
					'timestamp' => current_time( 'mysql' ),
				),
			);
		} catch ( \Throwable $e ) {
			Logger::get_instance()->error( 'Cron execution failed: ' . $e->getMessage(), array( 'source' => 'cron_manager' ) );
			return array( 'status' => 500, 'body' => array( 'ok' => false, 'code' => 'sync_failed', 'message' => 'Sync failed.' ) );
		} finally {
			self::release_lock( $owner );
		}
	}

	/**
	 * Perform the actual rate fetch + price sync.
	 *
	 * @return array|WP_Error
	 */
	public static function run_sync() {
		if ( License_Guard::should_block() ) {
			return new \WP_Error( 'fps_license_required', 'A valid commercial license is required.' );
		}
		$logger      = Logger::get_instance();
		$api_manager = new API_Manager();
		$rate        = $api_manager->force_refresh();

		if ( empty( $rate ) ) {
			$logger->error( 'Rate fetch failed or returned no valid rates.', array( 'source' => 'cron_manager' ) );
			return new \WP_Error( 'fps_rate_fetch_failed', 'No valid rates available.' );
		}

		$chunks = Action_Scheduler_Handler::on_rates_updated( 'scheduled' );
		$logger->info( 'Sync queued. Chunks scheduled: ' . $chunks, array(
			'source' => 'cron_manager',
			'chunks' => $chunks,
		) );

		Cache_Purger::purge_all();
		return array( 'chunks' => $chunks, 'rate' => $rate, 'timestamp' => current_time( 'mysql' ) );
	}

	/**
	 * Check whether we are rate-limited.
	 *
	 * @return bool
	 */
	private static function is_rate_limited(): bool {
		$last = get_option( self::RATE_LIMIT_KEY, 0 );
		return $last && ( time() - (int) $last ) < self::RATE_LIMIT_SECS;
	}

	/** Record a sync timestamp while the cron mutex is held. */
	private static function set_rate_limit() {
		update_option( self::RATE_LIMIT_KEY, time(), false );
	}

	/** Extract only a bearer token from the Authorization header. */
	private static function get_bearer_token(): string {
		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return '';
		}

		$token = trim( substr( $header, 7 ) );
		return ( $token !== '' && strlen( $token ) <= 255 && 1 === preg_match( '/^[A-Za-z0-9._~+\/=:-]+$/', $token ) ) ? $token : '';
	}

	/** Acquire the cron mutex atomically. Returns owner or empty string. */
	private static function acquire_lock(): string {
		$owner = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		return Atomic_Option_Lock::acquire( self::LOCK_KEY, $owner, self::LOCK_TTL ) ? $owner : '';
	}

	/** Release only when the current request still owns the mutex. */
	private static function release_lock( string $owner ): void {
		if ( '' !== $owner ) {
			Atomic_Option_Lock::release( self::LOCK_KEY, $owner );
		}
	}

	/**
	 * Send a plain-text HTTP response and exit.
	 *
	 * @param int    $code  HTTP status code.
	 * @param string $body  Response body.
	 * @return void
	 */
	private static function respond( int $code, string $body ) {
		status_header( $code );
		nocache_headers();
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$data = array( 'ok' => 200 === $code, 'error' => $body );
		}
		echo wp_json_encode( $data );
		exit;
	}

	/**
	 * Register the cron token setting field in the Settings API.
	 */
	public static function register_settings() {
		add_settings_section(
			'fps_section_cron',
			esc_html__( 'کرون مستقل (Server Cron)', 'formula-price-sync' ),
			array( self::class, 'section_description' ),
			'formula-price-sync'
		);

		add_settings_field(
			'fps_cron_token',
			esc_html__( 'توکن امنیتی', 'formula-price-sync' ),
			array( self::class, 'token_field_cb' ),
			'formula-price-sync',
			'fps_section_cron'
		);
	}

	/**
	 * Section description.
	 */
	public static function section_description() {
		$sample_url = home_url( '/?fps_action=cron_sync' );
		?>
		<p class="fps-desc">
			<?php esc_html_e( 'این endpoint را می‌توانید با crontab لینوکس یا هر ابزار زمان‌بندی سرور فراخوانی کنید.', 'formula-price-sync' ); ?>
		</p>
		<p class="fps-desc">
			<strong><?php esc_html_e( 'مثال Crontab:', 'formula-price-sync' ); ?></strong><br>
			<code>0 * * * * curl -fsS -H 'Authorization: Bearer YOUR_TOKEN' "<?php echo esc_url( $sample_url ); ?>" > /dev/null</code>
		</p>
		<p class="fps-desc">
			<?php esc_html_e( 'پس از ذخیره تنظیمات، توکن بالا را با توکن امنیتی خودتان جایگزین کنید. حداقل هر ۵ دقیقه یکبار اجازه اجرا دارد.', 'formula-price-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Token field callback.
	 */
	public static function token_field_cb() {
		$token = get_option( self::OPTION_KEY, '' );
		?>
		<input type="password" name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
			value="<?php echo esc_attr( $token ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'توکن تصادفی ۳۲ کاراکتری یا بیشتر', 'formula-price-sync' ); ?>"
			autocomplete="new-password">
		<p class="fps-desc">
			<?php esc_html_e( 'توکن فقط در Header احراز هویت Cron ارسال می‌شود. آن را در URL قرار ندهید.', 'formula-price-sync' ); ?>
		</p>
		<?php
	}

	/**
	 * Register the cron token option so it survives settings save.
	 */
	public static function register_option() {
		register_setting(
			'fps_options_group',
			self::OPTION_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_token' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Sanitize the cron token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function sanitize_token( string $token ): string {
		$token = sanitize_text_field( $token );
		if ( strlen( $token ) < 16 && '' !== $token ) {
			$token = '';
		}
		return $token;
	}

	/**
	 * Get the stored cron token (empty string if not set).
	 *
	 * @return string
	 */
	public static function get_token(): string {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	/**
	 * Generate a secure random token.
	 *
	 * @return string
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

}