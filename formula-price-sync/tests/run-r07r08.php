<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap/bootstrap.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use FormulaPriceSync\Engine\Calculator;
use FormulaPriceSync\Engine\Formula_Parser;
use FormulaPriceSync\Engine\Product_Update_Result;
use FormulaPriceSync\Queue\Action_Scheduler_Handler;

$pass = 0; $fail = 0;
function t7(bool $ok, string $msg): void { global $pass,$fail; echo ($ok?'[PASS] ':'[FAIL] ').$msg."\n"; $ok ? ++$pass : ++$fail; }

fps_test_reset_state();
$runSnapshot = 'snapshot-test-run';
$snapshotRates = array('usd'=>100,'eur'=>110,'gold_18k'=>5000,'gold_24k'=>6000,'coin'=>7000000,'source'=>'test','timestamp'=>time());
$snapshotId = \FormulaPriceSync\API\Rate_Snapshot_Store::persist_for_run($runSnapshot, $snapshotRates);
t7($snapshotId !== '', 'R03 snapshot persists before R07/R08 worker tests');
t7(\FormulaPriceSync\API\Rate_Snapshot_Store::get_for_run($runSnapshot) === $snapshotId, 'R03 run maps to one immutable snapshot id');
t7(\FormulaPriceSync\API\Rate_Snapshot_Store::get_rates($snapshotId)['usd'] === 100.0, 'R03 worker snapshot returns original rate');
$cursor = new ReflectionMethod(Action_Scheduler_Handler::class, 'get_next_enabled_product_ids');
$cursor->setAccessible(true);
$cursor->invoke(null, 5000, array(), 50);
t7(strpos($GLOBALS['wpdb']->last_query, 'e.post_id > %d') !== false, 'R02 cursor uses post_id > next_id');
t7(strpos($GLOBALS['wpdb']->last_query, 'ORDER BY e.post_id ASC') !== false, 'R02 cursor is monotonic');
t7(strpos($GLOBALS['wpdb']->last_query, 'LIMIT %d') !== false, 'R02 query is bounded');
update_option(\FormulaPriceSync\Licensing\License_Guard::STATUS_OPTION, 'valid', false);
set_transient(\FormulaPriceSync\Licensing\License_Guard::VALIDATION_TRANSIENT, array('valid'=>true,'expires_at'=>time()+3600), 3600);
$buildRun = new ReflectionMethod(Action_Scheduler_Handler::class, 'build_continuation_run_id'); $buildRun->setAccessible(true);
$continuationRun = $buildRun->invoke(null, 'manual', array());
\FormulaPriceSync\API\Rate_Snapshot_Store::persist_for_run($continuationRun, $snapshotRates);
$scheduled = Action_Scheduler_Handler::on_rates_updated('manual', array());
t7($scheduled === 1, 'R02/R03 run entry schedules one bounded continuation');
$actions = $GLOBALS['fps_test_scheduled_actions'];
t7(isset($actions[0]['hook']) && $actions[0]['hook'] === 'fps_process_queue_continuation', 'R02 continuation action is queued instead of full catalog actions');
$run_id = (string)$actions[0]['args']['run_id'];
t7(Action_Scheduler_Handler::process_queue_continuation($run_id) === true, 'R02 continuation safely handles an empty catalog');
t7((get_option('fps_queue_run_'.$run_id, array())['state'] ?? '') === 'completed', 'R02 continuation persists completed run state');
$valid = Formula_Parser::evaluate('5 + 2.5', array());
t7($valid === 7.5, 'R07 basic arithmetic');
t7(Formula_Parser::evaluate('10 + 1 * -4', array()) === 6.0, 'R07 unary minus');
foreach (array('1 + * 2','(1 + 2','100 / 0','{evil}+1','system(1)','1+2)') as $expr) {
    t7(Formula_Parser::evaluate($expr, array('weight'=>2)) <= 0.0, 'R07 rejects invalid formula: '.$expr);
}
t7(Formula_Parser::evaluate(str_repeat('9', 5000), array()) === 0.0, 'R07 rejects huge numeric input');
$gold = Calculator::calculate_price(array('source_type'=>'gold_18k','weight'=>10,'wage_percent'=>7,'profit_percent'=>10,'tax_percent'=>9),5000000);
t7($gold['breakdown']['tax_amount'] === 796500.0, 'R07 gold tax invariant');
$profitOnly = Calculator::calculate_price(array('source_type'=>'currency','base_foreign_price'=>50,'profit_percent'=>10,'tax_percent'=>9,'tax_mode'=>'profit_only'),1000000);
t7($profitOnly['breakdown']['tax_amount'] === 450000.0, 'R07 currency profit_only tax');
$zeroTax = Calculator::calculate_price(array('source_type'=>'currency','base_foreign_price'=>100,'profit_percent'=>10,'tax_percent'=>0,'fixed_fee'=>500),1000000);
t7($zeroTax['final_price'] === 110000500.0, 'R07 zero tax + fixed fee');
add_filter('fps_calculated_price', static function(){ return 999999999.0; });
$invalid = Calculator::calculate_price(array('source_type'=>'custom_formula','weight'=>1,'custom_formula'=>'1 / 0'),1000000);
t7($invalid['final_price'] === 0.0, 'R07 invalid computation cannot be overridden by filter');
remove_all_filters('fps_calculated_price');

fps_test_reset_state();
$lock = array('owner'=>'other','acquired_at'=>time(),'expires_at'=>time()+60);
update_option('fps_product_update_lock_101',$lock,false);
$result = Action_Scheduler_Handler::update_single_product_result(101,array('gold_18k'=>5000000),'manual');
t7($result['status'] === Product_Update_Result::RETRYABLE_ERROR, 'R08 lock contention is retryable');
$method = new ReflectionMethod(Action_Scheduler_Handler::class,'schedule_product_retry'); $method->setAccessible(true);
$rates=array('usd'=>100,'eur'=>110,'gold_18k'=>5000,'gold_24k'=>6000,'coin'=>7000000);
t7($method->invoke(null,'r08',101,'manual',$rates,1,0) === true, 'R08 schedules one product retry');
t7(count($GLOBALS['fps_test_scheduled_actions'])===1 && $GLOBALS['fps_test_scheduled_actions'][0]['hook']==='fps_retry_product_update', 'R08 retry hook is isolated to one product');
t7($GLOBALS['fps_test_scheduled_actions'][0]['args']['attempt']===2, 'R08 retry attempt increments');
t7($method->invoke(null,'r08max',101,'manual',$rates,3,0) === false, 'R08 max retry is terminal');
$summary = new ReflectionMethod(Action_Scheduler_Handler::class,'record_outcome'); $summary->setAccessible(true);
$summary->invoke(null,'r08sum',Product_Update_Result::UPDATED); $summary->invoke(null,'r08sum',Product_Update_Result::RETRYABLE_ERROR); $summary->invoke(null,'r08sum',Product_Update_Result::RETRYABLE_ERROR);
$state=get_option('fps_queue_run_r08sum',array());
t7(($state['outcomes']['updated']??0)===1 && ($state['outcomes']['retryable_error']??0)===2, 'R08 run-level outcome summary aggregates failures');

echo "Summary: {$pass} passed, {$fail} failed\n";
exit($fail?1:0);
