<?php
/**
 * Commercial license guard with fail-closed validation.
 *
 * @package FormulaPriceSync
 */

namespace FormulaPriceSync\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class License_Guard
 *
 * The plugin never treats a locally formatted key as valid. A marketplace or
 * first-party license adapter must return a validated result through the
 * `fps_license_validation_result` filter. Missing adapters fail closed.
 */
class License_Guard {

	const REVALIDATE_HOOK = 'fps_license_revalidate';

    const LICENSE_OPTION       = 'fps_license_key';
    const STATUS_OPTION        = 'fps_license_status';
    const VALIDATION_TRANSIENT = 'fps_license_validation';
    const VALIDATION_TTL       = DAY_IN_SECONDS;
    const ACTIVATION_LIMIT     = 5;
    const ACTIVATION_WINDOW    = 15 * MINUTE_IN_SECONDS;

    /**
     * Whether the stored activation is currently valid.
     *
     * @return bool
     */
    public static function is_valid(): bool {
        if ( defined( 'FPS_DEV_MODE' ) && FPS_DEV_MODE ) {
            return false;
        }

        $status = (string) get_option( self::STATUS_OPTION, 'invalid' );
        if ( 'valid' !== $status ) {
            return false;
        }

        $validation = get_transient( self::VALIDATION_TRANSIENT );
        if ( ! is_array( $validation ) || ! self::normalize_boolean( $validation['valid'] ?? false ) ) {
            return false;
        }
        $expires_at = isset( $validation['expires_at'] ) ? absint( $validation['expires_at'] ) : 0;
        return 0 === $expires_at || $expires_at >= time();
    }

    /**
     * Trial mode is intentionally unsupported in production builds.
     *
     * @return bool
     */
    public static function is_trial(): bool {
        return false;
    }

    /**
     * Trial time is retained only as a backward-compatible API returning zero.
     *
     * @return int
     */
    public static function trial_until(): int {
        return 0;
    }

    public static function is_trial_expired(): bool {
        return false;
    }

    public static function trial_days_left(): int {
        return 0;
    }

    /**
     * Return a masked license key. Never expose the full key in UI.
     *
     * @param bool $masked Mask key.
     * @return string
     */
    public static function get_license_key( bool $masked = true ): string {
        $key = (string) get_option( self::LICENSE_OPTION, '' );
        if ( '' === $key ) {
            return '';
        }
        if ( ! $masked ) {
            return $key;
        }
        $len = strlen( $key );
        if ( $len <= 8 ) {
            return str_repeat( '*', $len );
        }
        return substr( $key, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $key, -4 );
    }

    /**
     * Validate and activate a license through the installed adapter.
     *
     * Adapter contract returns an explicit result array:
     * valid, message, expires_at and status. Missing, malformed, or rejected
     * results fail closed. Filters inject an adapter for tests/integrations; they
     * never turn an invalid remote result into a valid license.
     *
     * @param string $license_key License key.
     * @return array{success:bool,message:string}
     */
    public static function activate( string $license_key ): array {
        $license_key = sanitize_text_field( trim( $license_key ) );

        if ( ! self::activation_allowed() ) {
            return array(
                'success' => false,
                'message' => __( 'تعداد تلاش‌های فعال‌سازی بیش از حد مجاز است. بعداً دوباره تلاش کنید.', 'formula-price-sync' ),
            );
        }

        self::record_activation_attempt();

        if ( '' === $license_key || strlen( $license_key ) < 10 || strlen( $license_key ) > 255 ) {
            self::mark_invalid();
            return array(
                'success' => false,
                'message' => __( 'کلید لایسنس نامعتبر است.', 'formula-price-sync' ),
            );
        }

        $adapter = self::get_adapter();
        if ( null === $adapter ) {
            self::mark_invalid();
            return array(
                'success' => false,
                'message' => __( 'سرویس واقعی لایسنس پیکربندی نشده است.', 'formula-price-sync' ),
            );
        }

        $result = $adapter->activate( $license_key );
        if ( ! is_array( $result ) || ! self::normalize_boolean( $result['valid'] ?? false ) ) {
            self::mark_invalid();
            return array(
                'success' => false,
                'message' => is_array( $result ) && ! empty( $result['message'] )
                    ? sanitize_text_field( (string) $result['message'] )
                    : __( 'اعتبار لایسنس قابل تأیید نیست.', 'formula-price-sync' ),
            );
        }

        $expires_at = isset( $result['expires_at'] ) ? absint( $result['expires_at'] ) : 0;
        if ( $expires_at > 0 && $expires_at < time() ) {
            self::mark_invalid();
            return array(
                'success' => false,
                'message' => __( 'لایسنس منقضی شده است.', 'formula-price-sync' ),
            );
        }

        $stored_status = isset( $result['status'] ) ? sanitize_key( (string) $result['status'] ) : 'valid';
        if ( 'valid' !== $stored_status ) {
            self::mark_invalid();
            return array(
                'success' => false,
                'message' => __( 'لایسنس توسط سرویس اعتبارسنجی تأیید نشد.', 'formula-price-sync' ),
            );
        }

        update_option( self::LICENSE_OPTION, $license_key, false );
        update_option( self::STATUS_OPTION, 'valid', false );
        set_transient(
            self::VALIDATION_TRANSIENT,
            array(
                'valid'      => true,
                'time'       => time(),
                'expires_at' => $expires_at,
            ),
            self::VALIDATION_TTL
        );
        self::init();

        return array(
            'success' => true,
            'message' => isset( $result['message'] ) && is_string( $result['message'] ) && '' !== $result['message']
                ? sanitize_text_field( $result['message'] )
                : __( 'لایسنس با موفقیت فعال شد.', 'formula-price-sync' ),
        );
    }


    /** Revalidate the stored commercial license through the marketplace adapter. */
    public static function revalidate(): bool {
        $license_key = (string) get_option( self::LICENSE_OPTION, '' );
        if ( '' === $license_key ) {
            self::mark_invalid();
            return false;
        }

        $adapter = self::get_adapter();
        if ( null === $adapter ) {
            self::mark_invalid();
            return false;
        }

        $result = $adapter->validate( $license_key );
        if ( ! is_array( $result ) || ! self::normalize_boolean( $result['valid'] ?? false ) ) {
            self::mark_invalid();
            return false;
        }

        $expires_at = isset( $result['expires_at'] ) ? absint( $result['expires_at'] ) : 0;
        if ( $expires_at > 0 && $expires_at < time() ) {
            self::mark_invalid();
            return false;
        }

        set_transient(
            self::VALIDATION_TRANSIENT,
            array(
                'valid'      => true,
                'time'       => time(),
                'expires_at' => $expires_at,
            ),
            self::VALIDATION_TTL
        );
        update_option( self::STATUS_OPTION, 'valid', false );
        return true;
    }

    /**
     * Return the configured commercial adapter.
     *
     * Filters remain dependency injection for tests/integrations only. Production
     * provider selection comes from license-config.php / server constants.
     */
    private static function get_adapter(): ?License_Adapter_Interface {
        $adapter = apply_filters( 'fps_license_adapter', null );
        if ( $adapter instanceof License_Adapter_Interface ) {
            return $adapter;
        }

        $config   = self::get_license_config();
        $provider = isset( $config['provider'] ) ? sanitize_key( (string) $config['provider'] ) : 'zhaket';

        switch ( $provider ) {
            case 'zhaket':
                return new Zhaket_Adapter();
            case 'rastchin':
                return new Rastchin_Adapter();
            default:
                return null;
        }
    }

    /**
     * Return server-side license configuration without exposing secrets.
     *
     * @return array<string,mixed>
     */
    private static function get_license_config(): array {
        static $config = null;
        if ( null !== $config ) {
            return $config;
        }

        $file = defined( 'FPS_PATH' ) ? FPS_PATH . 'includes/Licensing/license-config.php' : '';
        if ( '' === $file || ! is_readable( $file ) ) {
            return $config = array( 'provider' => 'zhaket' );
        }

        $loaded = require $file;
        return $config = is_array( $loaded ) ? $loaded : array( 'provider' => 'zhaket' );
    }

    /**
     * Expose whether the selected marketplace has the server-side product token
     * configured. Only a boolean is returned.
     *
     * @return bool
     */
    public static function is_product_token_configured(): bool {
        $adapter = self::get_adapter();
        if ( $adapter instanceof Rastchin_Adapter ) {
            return Rastchin_Adapter::is_product_token_configured();
        }
        if ( $adapter instanceof Zhaket_Adapter ) {
            return Zhaket_Adapter::is_product_token_configured();
        }
        return false;
    }

    /**
     * Normalize external boolean representations without treating arbitrary
     * non-empty strings such as "false" as true.
     *
     * @param mixed $value External value.
     * @return bool
     */
    public static function normalize_boolean( $value ): bool {
        if ( true === $value || 1 === $value || '1' === $value ) {
            return true;
        }
        if ( is_string( $value ) ) {
            return 'true' === strtolower( trim( $value ) );
        }
        return false;
    }

    /** Register scheduled license revalidation. */
    public static function init(): void {
        add_action( self::REVALIDATE_HOOK, array( self::class, 'revalidate' ) );
        if ( ! wp_next_scheduled( self::REVALIDATE_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::REVALIDATE_HOOK );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::REVALIDATE_HOOK );
        delete_option( self::LICENSE_OPTION );
        update_option( self::STATUS_OPTION, 'invalid', false );
        delete_transient( self::VALIDATION_TRANSIENT );
    }

    public static function maybe_show_license_notice(): void {
        if ( self::is_valid() || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && false !== strpos( (string) $screen->id, 'formula-price-sync' ) ) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'طلا ارز پرو', 'formula-price-sync' ); ?>:</strong>
                <?php esc_html_e( 'لایسنس معتبر فعال نیست.', 'formula-price-sync' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=formula-price-sync' ) ); ?>">
                    <?php esc_html_e( 'فعال‌سازی', 'formula-price-sync' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    public static function should_block(): bool {
        return ! self::is_valid();
    }


    /** Allow a bounded number of activation attempts per user/site. */
    private static function activation_allowed(): bool {
        $user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        $key     = 'fps_license_attempts_' . $user_id;
        $attempts = get_transient( $key );
        return ! is_array( $attempts ) || count( $attempts ) < self::ACTIVATION_LIMIT;
    }

    /** Record a timestamped activation attempt without storing the license key. */
    private static function record_activation_attempt(): void {
        $user_id = function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0;
        $key     = 'fps_license_attempts_' . $user_id;
        $attempts = get_transient( $key );
        $attempts = is_array( $attempts ) ? $attempts : array();
        $now = time();
        $attempts = array_values( array_filter( $attempts, static function ( $timestamp ) use ( $now ) {
            return is_numeric( $timestamp ) && ( $now - (int) $timestamp ) < self::ACTIVATION_WINDOW;
        } ) );
        $attempts[] = $now;
        set_transient( $key, $attempts, self::ACTIVATION_WINDOW );
    }

    private static function mark_invalid(): void {
        update_option( self::STATUS_OPTION, 'invalid', false );
        delete_transient( self::VALIDATION_TRANSIENT );
    }
}
