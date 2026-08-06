<?php
/**
 * Data integrity test: verifies batch INSERT produces correct data.
 *
 * Checks:
 * 1. Record count matches expected
 * 2. All phoneId, phone, clientId, params, state, closeTime values are correct
 * 3. Hashmap deduplication works properly
 * 4. Partial update: some numbers new, some existing, some removed
 *
 * Run: php -f tests/test-data-integrity.php
 */

require_once 'Globals.php';

use Modules\ModuleAutoDialer\Models\TaskResults;
use Modules\ModuleAutoDialer\Models\Tasks;
use Modules\ModuleAutoDialer\bin\ConnectorDB;

$di = \Phalcon\Di\Di::getDefault();
$db = (new TaskResults())->getReadConnection();
$passed = 0;
$failed = 0;

function assertEq($expected, $actual, string $msg): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "  OK: {$msg}\n";
    } else {
        $failed++;
        echo "  FAIL: {$msg} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
    }
}

function createTestTask(string $suffix): Tasks {
    $task = new Tasks();
    $task->name      = "integrity-{$suffix}-" . time();
    $task->crmId     = "integrity-{$suffix}-" . time();
    $task->state     = Tasks::STATE_CLOSE;
    $task->innerNum  = '000';
    $task->maxCountChannels = 1;
    if (!$task->save()) {
        echo "ERROR: failed to create task\n";
        exit(1);
    }
    return $task;
}

function cleanup(int $taskId, Tasks $task): void {
    global $db;
    $db->execute("DELETE FROM m_TaskResults WHERE taskId = ?", [$taskId]);
    $task->delete();
}

function batchInsert(int $taskId, array $numbers): void {
    global $db;
    $state      = ConnectorDB::EVENT_CREATE_TASK;
    $changeTime = microtime(true);
    $batchSize  = 100;
    $columns    = 'taskId, phoneId, phone, clientId, params, state, changeTime, closeTime, timeCallAllow';
    $batch  = [];
    $binds  = [];
    $bCount = 0;
    foreach ($numbers as $numData) {
        $batch[] = '(?,?,?,?,?,?,?,?,?)';
        $binds[] = $taskId;
        $binds[] = $numData['phoneId'];
        $binds[] = $numData['phone'];
        $binds[] = $numData['clientId'];
        $binds[] = $numData['params'];
        $binds[] = $state;
        $binds[] = $changeTime;
        $binds[] = 0;
        $binds[] = 0;
        $bCount++;
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
}

// --- Generate phone numbers ---
$nums = [];
for ($i = 0; $i < 500; $i++) {
    $phone = '7900' . str_pad($i, 7, '0', STR_PAD_LEFT);
    $nums[] = [
        'phone'    => $phone,
        'phoneId'  => ConnectorDB::getPhoneIndex($phone),
        'clientId' => 'client_' . ($i % 10),
        'params'   => serialize(['key' => "val_{$i}"]),
    ];
}

// =============================================
echo "=== Test 1: Batch INSERT correctness ===\n";
// =============================================
$task1   = createTestTask('t1');
$taskId1 = (int)$task1->id;

$db->begin();
batchInsert($taskId1, $nums);
$db->commit();

$rows = TaskResults::find("taskId='{$taskId1}'")->toArray();
assertEq(500, count($rows), 'Record count = 500');

$phoneIdFirst = ConnectorDB::getPhoneIndex('79000000000');
$first = TaskResults::findFirst("taskId='{$taskId1}' AND phoneId='{$phoneIdFirst}'");
assertEq('79000000000', $first->phone, 'First record phone');
assertEq('client_0', $first->clientId, 'First record clientId');
assertEq(ConnectorDB::EVENT_CREATE_TASK, $first->state, 'state = CreateTask');
assertEq(0, (int)$first->closeTime, 'closeTime = 0');

$phoneIdLast = ConnectorDB::getPhoneIndex('79000000499');
$last = TaskResults::findFirst("taskId='{$taskId1}' AND phoneId='{$phoneIdLast}'");
assertEq('79000000499', $last->phone, 'Last record phone');
assertEq('client_9', $last->clientId, 'Last record clientId');

cleanup($taskId1, $task1);

// =============================================
echo "\n=== Test 2: Deduplication (re-insert same numbers) ===\n";
// =============================================
$task2   = createTestTask('t2');
$taskId2 = (int)$task2->id;

$nums200 = array_slice($nums, 0, 200);

$db->begin();
batchInsert($taskId2, $nums200);
$db->commit();

$countBefore = (int)TaskResults::count("taskId='{$taskId2}'");
assertEq(200, $countBefore, 'First insert: 200 records');

// Deduplication via hashmap
$phoneIdIndex = [];
$indexPhones  = [];
foreach ($nums200 as $i => $nd) {
    $indexPhones[$i]  = $nd;
    $phoneIdIndex[$nd['phoneId']] = $i;
}

$oldResults = TaskResults::find("taskId='{$taskId2}'");
$foundCount = 0;
foreach ($oldResults as $old) {
    $indexRow = $phoneIdIndex[$old->phoneId] ?? false;
    if ($indexRow !== false) {
        $foundCount++;
        unset($indexPhones[$indexRow]);
        unset($phoneIdIndex[$old->phoneId]);
    }
}
assertEq(200, $foundCount, 'Hashmap found all 200 existing');
assertEq(0, count($indexPhones), 'New numbers to insert: 0');

cleanup($taskId2, $task2);

// =============================================
echo "\n=== Test 3: Partial update (new + existing + removed) ===\n";
// =============================================
$task3   = createTestTask('t3');
$taskId3 = (int)$task3->id;

// Original numbers: 0-299 (300 items)
$numsOriginal = array_slice($nums, 0, 300);
$db->begin();
batchInsert($taskId3, $numsOriginal);
$db->commit();

assertEq(300, (int)TaskResults::count("taskId='{$taskId3}'"), 'Original records: 300');

// New set: 100-399 (300 items)
// 0-99 removed, 100-299 kept, 300-399 new
$numsUpdated = [];
for ($i = 100; $i < 400; $i++) {
    $phone = '7900' . str_pad($i, 7, '0', STR_PAD_LEFT);
    $numsUpdated[] = [
        'phone'    => $phone,
        'phoneId'  => ConnectorDB::getPhoneIndex($phone),
        'clientId' => 'client_' . ($i % 10),
        'params'   => serialize(['key' => "val_{$i}"]),
    ];
}

$indexPhones  = [];
$phoneIdIndex = [];
foreach ($numsUpdated as $i => $nd) {
    $indexPhones[$i] = $nd;
    $phoneIdIndex[$nd['phoneId']] = $i;
}

// Deduplication
$oldResults = TaskResults::find("taskId='{$taskId3}'");
$deletedCount  = 0;
$existingCount = 0;
foreach ($oldResults as $old) {
    $indexRow = $phoneIdIndex[$old->phoneId] ?? false;
    if ($indexRow === false) {
        $old->delete();
        $deletedCount++;
    } else {
        $existingCount++;
        unset($indexPhones[$indexRow]);
        unset($phoneIdIndex[$old->phoneId]);
    }
}

assertEq(100, $deletedCount, 'Old numbers removed: 100');
assertEq(200, $existingCount, 'Existing matches: 200');
assertEq(100, count($indexPhones), 'New numbers to insert: 100');

// Insert new numbers
$db->begin();
batchInsert($taskId3, $indexPhones);
$db->commit();

$finalCount = (int)TaskResults::count("taskId='{$taskId3}'");
assertEq(300, $finalCount, 'Total records: 300 (200 old + 100 new)');

// Verify removed numbers are actually deleted
$phoneDeleted = ConnectorDB::getPhoneIndex('79000000050');
$deletedRow = TaskResults::findFirst("taskId='{$taskId3}' AND phoneId='{$phoneDeleted}'");
assertEq(true, ($deletedRow === null || $deletedRow === false), 'Number 050 deleted from DB');

// Verify new numbers were added
$phoneNew = ConnectorDB::getPhoneIndex('79000000350');
$newRow = TaskResults::findFirst("taskId='{$taskId3}' AND phoneId='{$phoneNew}'");
assertEq(true, ($newRow !== null && $newRow !== false), 'Number 350 added');

cleanup($taskId3, $task3);

// =============================================
echo "\n=== RESULT ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
