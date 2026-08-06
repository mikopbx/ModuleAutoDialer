<?php
/**
 * Тест целостности данных: проверяет batch INSERT и дедупликацию.
 *
 * Проверки:
 * 1. Количество записей после batch insert
 * 2. Корректность phoneId, phone, clientId, params, state, closeTime
 * 3. Дедупликация через hashmap
 * 4. Частичное обновление: новые + существующие + удалённые номера
 *
 * Запуск: php -f tests/unit/test-data-integrity.php
 */

require_once 'Globals.php';
require_once __DIR__ . '/../lib/TestRunner.php';

use Modules\ModuleAutoDialer\Models\TaskResults;
use Modules\ModuleAutoDialer\Models\Tasks;
use Modules\ModuleAutoDialer\bin\ConnectorDB;

$db = (new TaskResults())->getReadConnection();
$runner = new TestRunner('Data Integrity');

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

// --- Генерация телефонных номеров ---
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

// --- Тест 1: Batch INSERT ---
$runner->run('Batch INSERT correctness', function() use ($nums, $db) {
    $task   = createTestTask('t1');
    $taskId = (int)$task->id;

    $db->begin();
    batchInsert($taskId, $nums);
    $db->commit();

    $rows = TaskResults::find("taskId='{$taskId}'")->toArray();
    assertEq(500, count($rows), 'Record count = 500');

    $phoneIdFirst = ConnectorDB::getPhoneIndex('79000000000');
    $first = TaskResults::findFirst("taskId='{$taskId}' AND phoneId='{$phoneIdFirst}'");
    assertEq('79000000000', $first->phone, 'First record phone');
    assertEq('client_0', $first->clientId, 'First record clientId');
    assertEq(ConnectorDB::EVENT_CREATE_TASK, $first->state, 'state = CreateTask');
    assertEq(0, (int)$first->closeTime, 'closeTime = 0');

    $phoneIdLast = ConnectorDB::getPhoneIndex('79000000499');
    $last = TaskResults::findFirst("taskId='{$taskId}' AND phoneId='{$phoneIdLast}'");
    assertEq('79000000499', $last->phone, 'Last record phone');
    assertEq('client_9', $last->clientId, 'Last record clientId');

    cleanup($taskId, $task);
});

// --- Тест 2: Дедупликация ---
$runner->run('Deduplication (re-insert same numbers)', function() use ($nums, $db) {
    $task   = createTestTask('t2');
    $taskId = (int)$task->id;

    $nums200 = array_slice($nums, 0, 200);

    $db->begin();
    batchInsert($taskId, $nums200);
    $db->commit();

    $countBefore = (int)TaskResults::count("taskId='{$taskId}'");
    assertEq(200, $countBefore, 'First insert: 200 records');

    // Дедупликация через hashmap
    $phoneIdIndex = [];
    $indexPhones  = [];
    foreach ($nums200 as $i => $nd) {
        $indexPhones[$i]  = $nd;
        $phoneIdIndex[$nd['phoneId']] = $i;
    }

    $oldResults = TaskResults::find("taskId='{$taskId}'");
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

    cleanup($taskId, $task);
});

// --- Тест 3: Частичное обновление ---
$runner->run('Partial update (new + existing + removed)', function() use ($nums, $db) {
    $task   = createTestTask('t3');
    $taskId = (int)$task->id;

    // Исходные номера: 0-299 (300 штук)
    $numsOriginal = array_slice($nums, 0, 300);
    $db->begin();
    batchInsert($taskId, $numsOriginal);
    $db->commit();

    assertEq(300, (int)TaskResults::count("taskId='{$taskId}'"), 'Original records: 300');

    // Новый набор: 100-399 (300 штук)
    // 0-99 удаляются, 100-299 остаются, 300-399 добавляются
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

    // Дедупликация
    $oldResults = TaskResults::find("taskId='{$taskId}'");
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

    // Вставка новых номеров
    $db->begin();
    batchInsert($taskId, $indexPhones);
    $db->commit();

    $finalCount = (int)TaskResults::count("taskId='{$taskId}'");
    assertEq(300, $finalCount, 'Total records: 300 (200 old + 100 new)');

    // Проверка: удалённые номера действительно удалены
    $phoneDeleted = ConnectorDB::getPhoneIndex('79000000050');
    $deletedRow = TaskResults::findFirst("taskId='{$taskId}' AND phoneId='{$phoneDeleted}'");
    assertEq(true, ($deletedRow === null || $deletedRow === false), 'Number 050 deleted from DB');

    // Проверка: новые номера добавлены
    $phoneNew = ConnectorDB::getPhoneIndex('79000000350');
    $newRow = TaskResults::findFirst("taskId='{$taskId}' AND phoneId='{$phoneNew}'");
    assertEq(true, ($newRow !== null && $newRow !== false), 'Number 350 added');

    cleanup($taskId, $task);
});

exit($runner->exitCode());
