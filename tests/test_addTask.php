<?php
/**
 * Тест: проверяет что addTask через ConnectorDB работает без ошибки "no such table: m_TaskResults".
 * Создаёт задачу с парой номеров, проверяет результат, затем удаляет задачу.
 */
require_once 'Globals.php';

use Modules\ModuleAutoDialer\bin\ConnectorDB;

echo "=== Test addTask via ConnectorDB ===\n";

$taskData = [
    'id'      => '',
    'crmId'   => 'test_moduledb_fix_' . time(),
    'name'    => 'Test moduleDb fix',
    'innerNum'=> '201',
    'state'   => 0,
    'numbers' => [
        ['number' => '79991112233', 'clientId' => 'c1', 'params' => []],
        ['number' => '79994445566', 'clientId' => 'c2', 'params' => []],
    ],
];

echo "Creating task...\n";
$result = ConnectorDB::invoke('addTask', [$taskData], true, 10);

echo "Result:\n";
print_r($result);

if (!empty($result['success'])) {
    echo "\n✓ addTask SUCCESS\n";
    $taskId = $result['data']['id'] ?? null;
    if ($taskId) {
        echo "Task ID: $taskId\n";
        // Проверим что записи TaskResults появились
        $getResult = ConnectorDB::invoke('getTask', [$taskId], true, 10);
        $results = $getResult['data']['results'] ?? [];
        echo "TaskResults count: " . (is_array($results) ? count($results) : 'N/A') . "\n";

        // Удалим тестовую задачу
        echo "Deleting test task...\n";
        $delResult = ConnectorDB::invoke('deleteTask', [$taskId], true, 10);
        echo "Delete result: " . ($delResult['success'] ? 'OK' : 'FAIL') . "\n";
    }
} else {
    echo "\n✗ addTask FAILED\n";
    echo "Messages: " . print_r($result['messages'] ?? [], true) . "\n";
}
echo "=== Done ===\n";
