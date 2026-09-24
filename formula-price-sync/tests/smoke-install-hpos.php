<?php
/**
 * Smoke Install – WordPress + WooCommerce + HPOS compatibility
 *
 * Boots a minimal WP/WC environment, loads the real plugin, runs
 * activation + maybe_upgrade + fps_init, and asserts HPOS declaration,
 * fail-closed license, and core engine health.
 *
 * Usage (from plugin root):
 *   php tests/smoke-install-hpos.php
 *
 * @package FormulaPriceSync
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$fails  = 0;
$passes = 0;

function smoke( bool $ok, string $msg ): void {
	global $fails, $passes;
	if ( $ok ) {
		echo "[PASS] {$msg}\n";
		++$passes;
	} else {
		echo "[FAIL] {$msg}\n";
		++$fails;
	}
}

echo "=== Formula Price Sync – Smoke Install (WP + WC + HPOS) ===\n\n";

// --------------------------------------------------------------------------
// 1. Minimal WordPress environment
// --------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/tests/wp-stub/' );
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

$GLOBALS['fps_test_options']      = array();
$GLOBALS['fps_test_transients']   = array();
$GLOBALS['fps_test_filters']      = array();
$GLOBALS['fps_test_actions']      = array();
$GLOBALS['fps_hpos_declarations'] = array();
$GLOBALS['fps_activation_hooks']  = array();
$GLOBALS['fps_dbdelta_calls']     = array();

require_once $root . '/tests/bootstrap/bootstrap.php';

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {
		return true;
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
		$GLOBALS['fps_activation_hooks'][] = $callback;
	}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {
		$GLOBALS['fps_deactivation_hooks'][] = $callback;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		return 'test-nonce';
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $msg = '' ) {
		throw new RuntimeException( 'wp_die: ' . $msg );
	}
}
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $sql ) {
		$GLOBALS['fps_dbdelta_calls'][] = $sql;
		return array();
	}
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $option_group, $option_name, $args = array() ) {
		return true;
	}
}
if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( $id, $title, $callback, $page ) {
		return true;
	}
}
if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
		return true;
	}
}
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
		return true;
	}
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ) {
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		return true;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $l10n ) {
		return true;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return ( 'timestamp' === $type ) ? time() : gmdate( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		return null;
	}
}
// Avoid undefined $wpdb->postmeta during lock-meta migration.
global $wpdb;
if ( isset( $wpdb ) && ! isset( $wpdb->postmeta ) ) {
	$wpdb->postmeta = 'wp_postmeta';
	$wpdb->posts    = 'wp_posts';
}

@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents(
	ABSPATH . 'wp-admin/includes/upgrade.php',
	"<?php\nif (!function_exists('dbDelta')) { function dbDelta(\$sql) { \$GLOBALS['fps_dbdelta_calls'][] = \$sql; return array(); } }\n"
);

// --------------------------------------------------------------------------
// 2. WooCommerce + HPOS
// --------------------------------------------------------------------------
class WooCommerce {
	public static $instance;
	public function __construct() {
		self::$instance = $this;
	}
}
new WooCommerce();

require_once __DIR__ . '/stubs/FeaturesUtil.php';

smoke( class_exists( 'WooCommerce' ), 'WooCommerce class is available' );
smoke(
	class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ),
	'HPOS FeaturesUtil is available'
);

// --------------------------------------------------------------------------
// 3. Load real plugin
// --------------------------------------------------------------------------
$plugin_file = $root . '/formula-price-sync.php';
smoke( file_exists( $plugin_file ), 'Plugin main file exists' );

try {
	ob_start();
	require $plugin_file;
	ob_end_clean();
	smoke( true, 'Plugin main file loaded without fatal error' );
} catch ( Throwable $e ) {
	ob_end_clean();
	smoke( false, 'Plugin main file load fatal: ' . $e->getMessage() );
	echo "\n=== Summary: {$passes} passed, {$fails} failed ===\n";
	exit( 1 );
}

smoke( defined( 'FPS_VERSION' ), 'FPS_VERSION is defined' );
smoke( defined( 'FPS_VERSION' ) && '2.0.0' === FPS_VERSION, 'FPS_VERSION is 2.0.0' );
smoke( defined( 'FPS_PATH' ), 'FPS_PATH is defined' );

// --------------------------------------------------------------------------
// 4. HPOS declaration via before_woocommerce_init
// --------------------------------------------------------------------------
if ( ! empty( $GLOBALS['fps_test_actions']['before_woocommerce_init'] ) ) {
	foreach ( $GLOBALS['fps_test_actions']['before_woocommerce_init'] as $cb ) {
		call_user_func( $cb );
	}
	smoke( true, 'before_woocommerce_init hooks fired' );
} else {
	smoke( false, 'before_woocommerce_init action was not registered' );
}

$hpos_declared   = false;
$blocks_declared = false;
foreach ( $GLOBALS['fps_hpos_declarations'] as $decl ) {
	if ( 'custom_order_tables' === $decl['feature'] && ! empty( $decl['positive'] ) ) {
		$hpos_declared = true;
	}
	if ( 'cart_checkout_blocks' === $decl['feature'] && ! empty( $decl['positive'] ) ) {
		$blocks_declared = true;
	}
}
smoke( $hpos_declared, 'HPOS (custom_order_tables) compatibility declared positive' );
smoke( $blocks_declared, 'cart_checkout_blocks compatibility declared positive' );

// --------------------------------------------------------------------------
// 5. Activation
// --------------------------------------------------------------------------
$GLOBALS['fps_dbdelta_calls'] = array();
if ( ! empty( $GLOBALS['fps_activation_hooks'] ) ) {
	foreach ( $GLOBALS['fps_activation_hooks'] as $cb ) {
		call_user_func( $cb );
	}
	smoke( true, 'Activation hooks executed without fatal' );
} elseif ( class_exists( '\\FormulaPriceSync\\Core\\DB_Installer' ) ) {
	\FormulaPriceSync\Core\DB_Installer::install();
	smoke( true, 'DB_Installer::install() executed directly' );
} else {
	smoke( false, 'No activation path available' );
}

smoke(
	! empty( $GLOBALS['fps_dbdelta_calls'] ) || (string) get_option( 'fps_db_version', '' ) === '2.0.1',
	'Schema install attempted (dbDelta or version option set)'
);
smoke(
	(string) get_option( 'fps_db_version', '' ) === '2.0.1',
	'fps_db_version option is 2.0.1 after install'
);
smoke(
	! (bool) get_option( 'fps_lock_meta_migrated', false ),
	'lock-meta migration remains resumable after install'
);

// --------------------------------------------------------------------------
// The lock-meta migration is intentionally resumable. Complete the empty final batch
// in the harness before asserting the terminal migration flag.
\FormulaPriceSync\Core\DB_Installer::run_migration_batch();
smoke(
	(bool) get_option( 'fps_lock_meta_migrated', false ),
	'fps_lock_meta_migrated flag set after migration completion'
);

// 6. maybe_upgrade idempotent + 1.x path
// --------------------------------------------------------------------------
\FormulaPriceSync\Core\DB_Installer::maybe_upgrade();
smoke(
	(string) get_option( 'fps_db_version', '' ) === '2.0.1',
	'maybe_upgrade keeps version at 2.0.1 (idempotent)'
);

update_option( 'fps_db_version', '1.0.0', false );
delete_option( 'fps_lock_meta_migrated' );
\FormulaPriceSync\Core\DB_Installer::maybe_upgrade();
smoke(
	(string) get_option( 'fps_db_version', '' ) === '2.0.1',
	'Upgrade path 1.0.0 to 2.0.1 advances version'
);
smoke(
	(bool) get_option( 'fps_lock_meta_migrated', false ),
	'Upgrade path sets lock-meta migrated flag'
);

// --------------------------------------------------------------------------
// 7. fps_init
// --------------------------------------------------------------------------
if ( function_exists( 'fps_init' ) ) {
	try {
		fps_init();
		smoke( true, 'fps_init() completed without fatal' );
	} catch ( Throwable $e ) {
		smoke( false, 'fps_init() fatal: ' . $e->getMessage() );
	}
} else {
	smoke( false, 'fps_init() function not defined' );
}

smoke(
	\FormulaPriceSync\Licensing\License_Guard::should_block() === true,
	'License fail-closed: should_block() === true without valid license'
);
smoke(
	\FormulaPriceSync\Licensing\Zhaket_Adapter::is_product_token_configured() === false,
	'Product token not configured (expected in smoke env)'
);

// --------------------------------------------------------------------------
// 8. Core engine after install
// --------------------------------------------------------------------------
$calc = \FormulaPriceSync\Engine\Calculator::calculate_price(
	array(
		'source_type'    => 'gold_18k',
		'weight'         => 10.0,
		'wage_percent'   => 7.0,
		'profit_percent' => 10.0,
		'tax_percent'    => 9.0,
		'fixed_fee'      => 0.0,
		'rounding_rule'  => 'none',
	),
	5_000_000.0
);
smoke(
	isset( $calc['breakdown']['tax_amount'] ) && abs( $calc['breakdown']['tax_amount'] - 796_500.0 ) < 1.0,
	'Calculator gold guild tax rule still correct after install'
);

$lock_key = 'fps_smoke_install_lock';
$owner    = 'smoke_install';
smoke(
	\FormulaPriceSync\Core\Atomic_Option_Lock::acquire( $lock_key, $owner, 30 ) === true,
	'Atomic lock acquire works after install path'
);
smoke(
	\FormulaPriceSync\Core\Atomic_Option_Lock::acquire( $lock_key, 'other', 30 ) === false,
	'Atomic lock mutual exclusion holds after install path'
);
\FormulaPriceSync\Core\Atomic_Option_Lock::release( $lock_key, $owner );

// --------------------------------------------------------------------------
// 9. Package integrity
// --------------------------------------------------------------------------
smoke( file_exists( $root . '/uninstall.php' ), 'uninstall.php present' );
smoke( file_exists( $root . '/readme.txt' ), 'readme.txt present' );
smoke( is_dir( $root . '/includes' ), 'includes/ directory present' );
smoke( is_dir( $root . '/vendor' ), 'vendor/ directory present' );

echo "\n=== Summary: {$passes} passed, {$fails} failed ===\n";
if ( $fails > 0 ) {
	echo "STATUS: NOT READY – smoke install failures detected\n";
	exit( 1 );
}
echo "STATUS: PASS – Smoke install (WP + WC + HPOS stubs) successful\n";
exit( 0 );
