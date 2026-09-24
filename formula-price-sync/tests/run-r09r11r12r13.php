<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap/bootstrap.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use FormulaPriceSync\Core\DB_Installer;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;
use FormulaPriceSync\API\Rate_Snapshot_Store;

$pass = 0;
$fail = 0;
function t9(bool $ok, string $message): void {
    global $pass, $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . "\n";
    $ok ? ++$pass : ++$fail;
}

// R09: discovery and taxonomy filtering remain SQL-side and query-bounded.
fps_test_reset_state();
$GLOBALS['wpdb']->col_results = array(11, 12, 13);
$ids = Action_Scheduler_Handler::get_enabled_product_ids(
    array(
        'product_cats' => array(10, 11),
        'product_tags' => array(21),
    )
);
t9($ids === array(11, 12, 13), 'R09 discovery returns DB result without post-level calls');
t9($GLOBALS['wpdb']->query_count === 1, 'R09 full discovery uses one SQL query');
$q = $GLOBALS['wpdb']->last_query;
t9(strpos($q, "CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END") !== false, 'R09 taxonomy resolves variation parents in SQL');
t9(strpos($q, 'EXISTS') !== false && strpos($q, 'ORDER BY e.post_id ASC') !== false, 'R09 taxonomy filtering stays inside discovery SQL');

// R11: bounded resumable migration.
fps_test_reset_state();
$GLOBALS['wpdb']->col_results = array(101, 102, 103);
DB_Installer::run_migration_batch();
$state = get_option(DB_Installer::LOCK_META_MIGRATION_STATE, array());
t9(!get_option(DB_Installer::LOCK_META_MIGRATED_OPTION, false), 'R11 does not mark migration complete after one bounded batch');
t9((int)($state['next_id'] ?? 0) === 103 && (int)($state['inserted'] ?? 0) === 3, 'R11 persists cursor and inserted count');
$GLOBALS['wpdb']->col_results = array();
DB_Installer::run_migration_batch();
t9((bool)get_option(DB_Installer::LOCK_META_MIGRATED_OPTION, false), 'R11 marks migration complete only after final empty batch');
t9((get_option(DB_Installer::LOCK_META_MIGRATION_STATE, array())['state'] ?? '') === 'completed', 'R11 migration state reaches completed');

// R12: old terminal state and orphan snapshots are cleaned; active/deferred state remains.
fps_test_reset_state();
$old = time() - (Action_Scheduler_Handler::STATE_RETENTION_DAYS * DAY_IN_SECONDS) - 60;
$runOld = hash('sha256', 'old-run');
$runActive = hash('sha256', 'active-run');
$runDeferred = hash('sha256', 'deferred-run');
$oldSnapshot = 'fpss_' . str_repeat('a', 48);
update_option('fps_queue_run_completed_' . $runOld, array('completed_at'=>$old, 'updated_total'=>2, 'snapshot_id'=>$oldSnapshot), false);
update_option('fps_queue_run_' . $runOld, array('state'=>'completed','completed_at'=>$old,'snapshot_id'=>$oldSnapshot), false);
update_option('fps_queue_run_' . $runActive, array('state'=>'running','updated_at'=>$old), false);
update_option('fps_queue_run_' . $runDeferred, array('state'=>'deferred','updated_at'=>$old), false);
update_option('fps_queue_complete_' . $runOld . '_1', array('completed_at'=>$old,'updated'=>1), false);
update_option('fps_rate_snapshot_' . $oldSnapshot, array('snapshot_id'=>$oldSnapshot,'rates'=>array('usd'=>1,'eur'=>1,'gold_18k'=>1,'gold_24k'=>1,'coin'=>1),'created_at'=>$old), false);
update_option('fps_rate_snapshot_ref_' . hash('sha256', $runOld), $oldSnapshot, false);
$GLOBALS['wpdb']->result_rows = array(
    (object)array('option_name'=>'fps_queue_run_completed_'.$runOld,'option_value'=>maybe_serialize(get_option('fps_queue_run_completed_'.$runOld))),
    (object)array('option_name'=>'fps_queue_run_'.$runOld,'option_value'=>maybe_serialize(get_option('fps_queue_run_'.$runOld))),
    (object)array('option_name'=>'fps_queue_run_'.$runActive,'option_value'=>maybe_serialize(get_option('fps_queue_run_'.$runActive))),
    (object)array('option_name'=>'fps_queue_run_'.$runDeferred,'option_value'=>maybe_serialize(get_option('fps_queue_run_'.$runDeferred))),
    (object)array('option_name'=>'fps_queue_complete_'.$runOld.'_1','option_value'=>maybe_serialize(get_option('fps_queue_complete_'.$runOld.'_1'))),
    (object)array('option_name'=>'fps_rate_snapshot_'.$oldSnapshot,'option_value'=>maybe_serialize(get_option('fps_rate_snapshot_'.$oldSnapshot))),
    (object)array('option_name'=>'fps_rate_snapshot_ref_'.hash('sha256', $runOld),'option_value'=>$oldSnapshot),
);
$deleted = Action_Scheduler_Handler::cleanup_old_run_state();
t9($deleted >= 4, 'R12 cleanup removes stale terminal/completion state');
t9(false !== get_option('fps_queue_run_'.$runActive, false), 'R12 preserves active run state');
t9(false !== get_option('fps_queue_run_'.$runDeferred, false), 'R12 preserves deferred run state');

// R13: operator-facing run telemetry.
fps_test_reset_state();
$runTelemetry = hash('sha256', 'telemetry-run');
update_option('fps_queue_run_' . $runTelemetry, array(
    'run_id'=>$runTelemetry,
    'state'=>'failed',
    'trigger_type'=>'scheduled',
    'snapshot_id'=>'fpss_' . str_repeat('b', 48),
    'next_id'=>500,
    'chunks_completed'=>10,
    'products_seen'=>1000,
    'products_updated'=>875,
    'outcomes'=>array('updated'=>875,'retryable_error'=>25,'locked'=>100),
    'last_error'=>'temporary provider timeout',
    'created_at'=>time()-120,
    'updated_at'=>time()-10,
), false);
$GLOBALS['wpdb']->result_rows = array((object)array(
    'option_name'=>'fps_queue_run_' . $runTelemetry,
    'option_value'=>maybe_serialize(get_option('fps_queue_run_' . $runTelemetry)),
));
$summary = Action_Scheduler_Handler::get_latest_run_health_summary();
t9(($summary['state'] ?? '') === 'failed', 'R13 latest run state is exposed');
t9(($summary['products_seen'] ?? 0) === 1000 && ($summary['products_updated'] ?? 0) === 875, 'R13 progress counters are exposed');
t9(($summary['outcomes']['retryable_error'] ?? 0) === 25, 'R13 outcome taxonomy is exposed');
t9(($summary['last_error'] ?? '') === 'temporary provider timeout', 'R13 last error is operator-readable');

echo "Summary: {$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
