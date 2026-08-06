<?php
/**
 * Benchmark of the OLD approach: per-row ORM INSERT + linear search deduplication.
 *
 * Run: php -f tests/bench-old-orm-insert.php [number_count]
 * Example: php -f tests/bench-old-orm-insert.php 20000
 */

require_once 'Globals.php';

use Modules\ModuleAutoDialer\Models\TaskResults;
use Modules\ModuleAutoDialer\Models\Tasks;
use Modules\ModuleAutoDialer\bin\ConnectorDB;

$count = (int)($argv[1] ?? 1000);
$di = \Phalcon\Di\Di::getDefault();
$db = (new TaskResults())->getReadConnection();

echo "=== Benchmark: OLD approach (ORM INSERT) ===\n";
echo "Number count: {$count}\n\n";

// --- Prepare test task ---
$task = new Tasks();
$task->name      = 'bench-old-' . time();
$task->crmId     = 'bench-old-' . time();
$task->state     = Tasks::STATE_CLOSE;
$task->innerNum  = '000';
$task->maxCountChannels = 1;
if (!$task->save()) {
    echo "ERROR: failed to create task\n";
    exit(1);
}
$taskId = (int)$task->id;
echo "Created task id={$taskId}\n";

// --- Generate phone numbers ---
$numbers = [];
for ($i = 0; $i < $count; $i++) {
    $numbers[] = '7900' . str_pad($i, 7, '0', STR_PAD_LEFT);
}

// --- Measure: per-row ORM INSERT ---
$state      = ConnectorDB::EVENT_CREATE_TASK;
$changeTime = microtime(true);

$db->begin();
$t0 = microtime(true);
$inserted = 0;
foreach ($numbers as $phone) {
    $row = new TaskResults();
    $row->taskId        = $taskId;
    $row->phoneId       = ConnectorDB::getPhoneIndex($phone);
    $row->phone         = $phone;
    $row->clientId      = '';
    $row->params        = '';
    $row->state         = $state;
    $row->changeTime    = $changeTime;
    $row->closeTime     = 0;
    $row->timeCallAllow = 0;
    if (!$row->save()) {
        echo "ERROR INSERT at row {$inserted}: " . implode('; ', $row->getMessages()) . "\n";
        $db->rollback();
        exit(1);
    }
    $inserted++;
}
$db->commit();
$t1 = microtime(true);

$insertTime = round($t1 - $t0, 3);
echo "INSERT {$inserted} records: {$insertTime} sec\n";
echo "Average per record: " . round(($t1 - $t0) / $inserted * 1000, 3) . " ms\n\n";

// --- Measure: linear search (deduplication) ---
$indexPhones = [];
foreach ($numbers as $i => $phone) {
    $indexPhones[$i] = [
        'phone'    => $phone,
        'phoneId'  => ConnectorDB::getPhoneIndex($phone),
        'clientId' => '',
        'params'   => '',
    ];
}

$t0 = microtime(true);
$oldResultsTask = TaskResults::find("taskId='{$taskId}'");
$found = 0;
foreach ($oldResultsTask as $oldResult) {
    // Linear search — same as old searchForId
    $indexRow = false;
    foreach ($indexPhones as $key => $val) {
        if ($val['phoneId'] === $oldResult->phoneId) {
            $indexRow = $key;
            break;
        }
    }
    if ($indexRow !== false) {
        $found++;
    }
}
$t1 = microtime(true);

$searchTime = round($t1 - $t0, 3);
echo "Linear search deduplication ({$found} matches): {$searchTime} sec\n\n";

// --- Cleanup ---
$t0 = microtime(true);
$db->execute("DELETE FROM m_TaskResults WHERE taskId = ?", [$taskId]);
$task->delete();
$t1 = microtime(true);
echo "Cleanup: " . round($t1 - $t0, 3) . " sec\n";

echo "\n=== TOTAL (old approach) ===\n";
echo "INSERT:        {$insertTime} sec\n";
echo "Deduplication: {$searchTime} sec\n";
echo "SUM:           " . round($insertTime + $searchTime, 3) . " sec\n";
