<?php
/**
 * Real MySQL CAS gate for FPS-R06.
 *
 * Run with:
 *   FPS_INTEGRATION_MYSQL=1 php tests/Integration/real-mysql-cas.php
 *
 * The test intentionally does not use the in-memory PHPUnit $wpdb mock. It
 * creates a temporary options-like table on a shared MySQL server and loads
 * the production Atomic_Option_Lock class against real SQL.
 */

declare(strict_types=1);

if ( '1' !== (string) getenv( 'FPS_INTEGRATION_MYSQL' ) ) {
    fwrite( STDERR, "FPS_INTEGRATION_MYSQL=1 is required\n" );
    exit( 2 );
}

$host = getenv( 'FPS_DB_HOST' ) ?: '127.0.0.1';
$port = (int) ( getenv( 'FPS_DB_PORT' ) ?: 3306 );
$user = getenv( 'FPS_DB_USER' ) ?: 'root';
$pass = getenv( 'FPS_DB_PASS' ) ?: 'root';
$db   = getenv( 'FPS_DB_NAME' ) ?: 'fps_test';

$mysqli = new mysqli( $host, $user, $pass, $db, $port );
if ( $mysqli->connect_errno ) {
    fwrite( STDERR, "MySQL connect failed: {$mysqli->connect_error}\n" );
    exit( 1 );
}
$mysqli->set_charset( 'utf8mb4' );

$table = 'fps_r06_options_' . bin2hex( random_bytes( 4 ) );
$mysqli->query( "CREATE TABLE `{$table}` (option_name varchar(191) NOT NULL, option_value longtext NOT NULL, PRIMARY KEY (option_name)) ENGINE=InnoDB" );

class FPS_Real_WPDB {
    public string $options;
    public int $rows_affected = 0;
    private mysqli $db;

    public function __construct( mysqli $db, string $options ) {
        $this->db = $db;
        $this->options = $options;
    }

    public function prepare( string $query, ...$args ): string {
        $args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
        $i = 0;
        return preg_replace_callback(
            '/%s|%d/',
            function ( $m ) use ( $args, &$i ) {
                $value = $args[ $i++ ] ?? '';
                if ( '%d' === $m[0] ) {
                    return (string) (int) $value;
                }
                return "'" . $this->db->real_escape_string( (string) $value ) . "'";
            },
            $query
        );
    }

    public function query( string $sql ): int {
        $ok = $this->db->query( $sql );
        if ( false === $ok ) {
            throw new RuntimeException( $this->db->error );
        }
        $this->rows_affected = (int) $this->db->affected_rows;
        return $this->rows_affected;
    }

    public function fetch_option( string $key ) {
        $stmt = $this->db->prepare( "SELECT option_value FROM `{$this->options}` WHERE option_name = ? LIMIT 1" );
        $stmt->bind_param( 's', $key );
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if ( ! $row ) {
            return false;
        }
        return maybe_unserialize( $row['option_value'] );
    }

    public function write_option( string $key, $value ): bool {
        $serialized = maybe_serialize( $value );
        $stmt = $this->db->prepare( "INSERT INTO `{$this->options}` (option_name, option_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
        $stmt->bind_param( 'ss', $key, $serialized );
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    public function insert_if_missing( string $key, $value ): bool {
        $serialized = maybe_serialize( $value );
        $stmt = $this->db->prepare( "INSERT IGNORE INTO `{$this->options}` (option_name, option_value) VALUES (?, ?)" );
        $stmt->bind_param( 'ss', $key, $serialized );
        $stmt->execute();
        $rows = (int) $stmt->affected_rows;
        $stmt->close();
        return 1 === $rows;
    }

    public function delete_option_row( string $key ): bool {
        $stmt = $this->db->prepare( "DELETE FROM `{$this->options}` WHERE option_name = ?" );
        $stmt->bind_param( 's', $key );
        $stmt->execute();
        $rows = (int) $stmt->affected_rows;
        $stmt->close();
        return 1 === $rows;
    }
}

function maybe_serialize( $value ): string {
    return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
}

function maybe_unserialize( $value ) {
    if ( ! is_string( $value ) ) {
        return $value;
    }
    $unserialized = @unserialize( $value );
    return false !== $unserialized || 'b:0;' === $value ? $unserialized : $value;
}

$GLOBALS['fps_real_wpdb'] = new FPS_Real_WPDB( $mysqli, $table );
$GLOBALS['wpdb'] = $GLOBALS['fps_real_wpdb'];

function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
    return $GLOBALS['fps_real_wpdb']->insert_if_missing( (string) $key, $value );
}
function get_option( $key, $default = false ) {
    $value = $GLOBALS['fps_real_wpdb']->fetch_option( (string) $key );
    return false === $value ? $default : $value;
}
function update_option( $key, $value, $autoload = null ): bool {
    return $GLOBALS['fps_real_wpdb']->write_option( (string) $key, $value );
}
function delete_option( $key ): bool {
    return $GLOBALS['fps_real_wpdb']->delete_option_row( (string) $key );
}
function wp_cache_delete( $key, $group = '' ): bool { return true; }

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

require_once dirname( __DIR__, 2 ) . '/includes/Core/Atomic_Option_Lock.php';

use FormulaPriceSync\Core\Atomic_Option_Lock;

$key = 'fps_r06_real_' . bin2hex( random_bytes( 4 ) );
$workers = 5;
$dir = sys_get_temp_dir() . '/fps_r06_' . bin2hex( random_bytes( 4 ) );
mkdir( $dir, 0700, true );

// Each child opens its own DB connection after fork.
$dsn = array( $host, $port, $user, $pass, $db, $table );
$pids = array();
for ( $i = 0; $i < $workers; $i++ ) {
    $pid = pcntl_fork();
    if ( -1 === $pid ) {
        throw new RuntimeException( 'pcntl_fork failed' );
    }
    if ( 0 === $pid ) {
        [$chost, $cport, $cuser, $cpass, $cdb, $ctable] = $dsn;
        $child = new mysqli( $chost, $cuser, $cpass, $cdb, $cport );
        $child->set_charset( 'utf8mb4' );
        $GLOBALS['fps_real_wpdb'] = new FPS_Real_WPDB( $child, $ctable );
        $GLOBALS['wpdb'] = $GLOBALS['fps_real_wpdb'];
        $owner = 'worker_' . $i . '_' . getmypid();
        $won = Atomic_Option_Lock::acquire( $key, $owner, 30 );
        file_put_contents( $dir . '/' . $owner, $won ? '1' : '0' );
        if ( $won ) {
            usleep( 250000 );
            Atomic_Option_Lock::release( $key, $owner );
        }
        $child->close();
        exit( 0 );
    }
    $pids[] = $pid;
}

foreach ( $pids as $pid ) {
    pcntl_waitpid( $pid, $status );
}

$wins = 0;
foreach ( glob( $dir . '/*' ) as $file ) {
    $wins += '1' === trim( (string) file_get_contents( $file ) ) ? 1 : 0;
    @unlink( $file );
}
@rmdir( $dir );

if ( 1 !== $wins ) {
    $mysqli->query( "DROP TABLE IF EXISTS `{$table}`" );
    $mysqli->close();
    fwrite( STDERR, "Real MySQL contention failed: {$wins} winners\n" );
    exit( 1 );
}

// Reclaim + stale owner release validation.
$expired = array('owner'=>'departed','acquired_at'=>time()-120,'expires_at'=>time()-30);
update_option( $key, $expired, false );
if ( ! Atomic_Option_Lock::acquire( $key, 'reclaimer', 30 ) ) {
    fwrite( STDERR, "Expired lock reclaim failed\n" );
    exit( 1 );
}
if ( Atomic_Option_Lock::release( $key, 'departed' ) ) {
    fwrite( STDERR, "Stale owner released a reclaimed lock\n" );
    exit( 1 );
}
Atomic_Option_Lock::release( $key, 'reclaimer' );
delete_option( $key );
$mysqli->query( "DROP TABLE IF EXISTS `{$table}`" );
$mysqli->close();

echo "REAL_MYSQL_CAS_PASS workers={$workers}\n";
