<?php
/**
 * Benchmark of the NEW approach: batch raw SQL INSERT + hashmap deduplication.
 *
 * Run: php -f tests/bench-new-batch-insert.php [number_count]
 * Example: php -f tests/bench-new-batch-insert.php 20000
 */

require_once 'Globals.php';

use Modules\ModuleAutoDialer\Models\TaskResults;
use Modules\ModuleAutoDialer\Models\Tasks;
use Modules\ModuleAutoDialer\bin\ConnectorDB;

$count = (int)($argv[1] ?? 1000);
$di = \Phalcon\Di\Di::getDefault();
$db = (new TaskResults())->getReadConnection();

echo "=== Benchmark: NEW approach (batch INSERT) ===\n";
echo "Number count: {$count}\n\n";

// --- Prepare test task ---
$task = new Tasks();
$task->name      = 'bench-new-' . time();
$task->crmId     = 'bench-new-' . time();
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

// --- Measure: batch raw SQL INSERT ---
$state      = ConnectorDB::EVENT_CREATE_TASK;
$changeTime = microtime(true);
$batchSize  = 100;
$columns    = 'taskId, phoneId, phone, clientId, params, state, changeTime, closeTime, timeCallAllow';

$db->begin();
$t0 = microtime(true);

$batch  = [];
$binds  = [];
$bCount = 0;
$inserted = 0;

foreach ($numbers as $phone) {
    $phoneId = ConnectorDB::getPhoneIndex($phone);
    $batch[] = '(?,?,?,?,?,?,?,?,?)';
    $binds[] = $taskId;
    $binds[] = $phoneId;
    $binds[] = $phone;
    $binds[] = '';
    $binds[] = '';
    $binds[] = $state;
    $binds[] = $changeTime;
    $binds[] = 0;
    $binds[] = 0;
    $bCount++;
    $inserted++;

    if ($bCount >= $batchSize) {
        $sql = "INSERT INTO m_TaskResults ($columns) VALUES " . implode(',', $batch);
        $db->execute($sql, $binds);
        $batch  = [];
        $binds  = [];
        $bCount = 0;
    }
}
if (!empty($batch)) {
    $sql = "INSERT INTO m_TaskResults ($columns) VALUES " . implode(',', $batch);
    $db->execute($sql, $binds);
}

$db->commit();
$t1 = microtime(true);

$insertTime = round($t1 - $t0, 3);
echo "INSERT {$inserted} records: {$insertTime} sec\n";
echo "Average per record: " . round(($t1 - $t0) / $inserted * 1000, 3) . " ms\n\n";

// --- Measure: hashmap lookup (deduplication) ---
$indexPhones  = [];
$phoneIdIndex = [];
foreach ($numbers as $i => $phone) {
    $phoneId = ConnectorDB::getPhoneIndex($phone);
    $indexPhones[$i] = [
        'phone'    => $phone,
        'phoneId'  => $phoneId,
        'clientId' => '',
        'params'   => '',
    ];
    $phoneIdIndex[$phoneId] = $i;
}

$t0 = microtime(true);
$oldResultsTask = TaskResults::find("taskId='{$taskId}'");
$found = 0;
foreach ($oldResultsTask as $oldResult) {
    // Hashmap lookup O(1)
    $indexRow = $phoneIdIndex[$oldResult->phoneId] ?? false;
    if ($indexRow !== false) {
        $found++;
    }
}
$t1 = microtime(true);

$searchTime = round($t1 - $t0, 3);
echo "Hashmap deduplication ({$found} matches): {$searchTime} sec\n\n";

// --- Cleanup ---
$t0 = microtime(true);
$db->execute("DELETE FROM m_TaskResults WHERE taskId = ?", [$taskId]);
$task->delete();
$t1 = microtime(true);
echo "Cleanup: " . round($t1 - $t0, 3) . " sec\n";

echo "\n=== TOTAL (new approach) ===\n";
echo "INSERT:        {$insertTime} sec\n";
echo "Deduplication: {$searchTime} sec\n";
echo "SUM:           " . round($insertTime + $searchTime, 3) . " sec\n";
