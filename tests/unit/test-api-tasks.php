<?php
/**
 * Интеграционные тесты REST API задач обзвона.
 *
 * Тестирует CRUD задач через HTTP API:
 * POST /task, GET /task/{id}, GET /task, PUT /task/{id}, DELETE /task/{id}
 * GET /results/{changeTime}
 *
 * Запуск: php -f tests/unit/test-api-tasks.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('API Tasks');

$testCrmId = $config['test_crm_prefix'] . 'task-' . time();
$taskId = null;
$changeTimeBefore = (string)(microtime(true) - 1);

// --- Тест 1: Создание задачи ---
$runner->run('POST /task - create task', function() use ($api, $testCrmId, &$taskId) {
    $result = $api->createTask([
        'crmId'            => $testCrmId,
        'name'             => 'Test Task ' . date('H:i:s'),
        'state'            => 1, // STATE_CLOSE, чтобы WorkerDialer не звонил
        'innerNum'         => '000',
        'maxCountChannels' => 1,
        'numbers'          => [
            ['number' => '79001112233', 'clientId' => 'c1', 'params' => ['key1' => 'val1']],
            ['number' => '79001112244', 'clientId' => 'c1'],
            ['number' => '79001112255', 'clientId' => 'c2'],
        ],
    ]);

    assertTrue($result['result'] ?? false, 'result = true');
    assertNotEmpty($result['data']['id'] ?? '', 'taskId is set');
    $taskId = (string)($result['data']['id'] ?? '');
    assertEq($testCrmId, $result['data']['crmId'] ?? '', 'crmId matches');
});

// --- Тест 2: Получение задачи ---
$runner->run('GET /task/{id} - get task details', function() use ($api, &$taskId, $testCrmId) {
    if (empty($taskId)) {
        echo "  SKIP: taskId not set (previous test failed)\n";
        return;
    }
    $result = $api->getTask($taskId);

    assertTrue($result['result'] ?? false, 'result = true');
    assertEq($testCrmId, $result['data']['crmId'] ?? '', 'crmId matches');
    assertEq('1', (string)($result['data']['state'] ?? ''), 'state = STATE_CLOSE');

    // Проверяем результаты (номера)
    $results = $result['data']['results'] ?? [];
    assertEq(3, count($results), '3 phone numbers in results');
});

// --- Тест 3: Список задач ---
$runner->run('GET /task - list tasks', function() use ($api, &$taskId) {
    if (empty($taskId)) {
        echo "  SKIP: taskId not set\n";
        return;
    }
    $result = $api->getTasks();

    assertTrue($result['result'] ?? false, 'result = true');
    $data = $result['data'] ?? [];
    assertTrue(is_array($data), 'data is array');
    assertTrue(count($data) >= 1, 'at least 1 task exists');
});

// --- Тест 4: Обновление задачи ---
$runner->run('PUT /task/{id} - update task', function() use ($api, &$taskId) {
    if (empty($taskId)) {
        echo "  SKIP: taskId not set\n";
        return;
    }
    $result = $api->updateTask($taskId, [
        'name' => 'Updated Test Task',
    ]);

    assertTrue($result['result'] ?? false, 'result = true');

    // Проверяем что имя обновилось
    $check = $api->getTask($taskId);
    assertEq('Updated Test Task', $check['data']['name'] ?? '', 'name updated');
});

// --- Тест 5: Повторный POST с тем же crmId (обновление номеров) ---
$runner->run('POST /task (same crmId) - update numbers', function() use ($api, $testCrmId, &$taskId) {
    if (empty($taskId)) {
        echo "  SKIP: taskId not set\n";
        return;
    }
    $result = $api->createTask([
        'crmId'            => $testCrmId,
        'name'             => 'Test Task Updated',
        'state'            => 1,
        'innerNum'         => '000',
        'maxCountChannels' => 1,
        'numbers'          => [
            ['number' => '79001112233', 'clientId' => 'c1'], // Оставляем
            ['number' => '79001112266', 'clientId' => 'c3'], // Новый
            // 79001112244 и 79001112255 удаляются
        ],
    ]);

    assertTrue($result['result'] ?? false, 'result = true');
    assertEq($taskId, (string)($result['data']['id'] ?? ''), 'same taskId (upsert by crmId)');

    // Проверяем обновлённый набор номеров
    $check = $api->getTask($taskId);
    $results = $check['data']['results'] ?? [];
    assertEq(2, count($results), '2 phone numbers after update');
});

// --- Тест 6: Инкрементальные результаты ---
$runner->run('GET /results/{changeTime} - incremental results', function() use ($api, $changeTimeBefore, &$taskId) {
    if (empty($taskId)) {
        echo "  SKIP: taskId not set\n";
        return;
    }
    $result = $api->getResults($changeTimeBefore);

    assertTrue($result['result'] ?? false, 'result = true');
    $data = $result['data']['results'] ?? [];
    assertTrue(is_array($data), 'data is array');

    // Ищем результаты нашей задачи
    $ours = array_filter($data, function($r) use ($taskId) {
        return (string)($r['taskId'] ?? '') === $taskId;
    });
    assertTrue(count($ours) >= 1, 'found results for our task');
});

// --- Тест 7: Удаление задачи ---
$runner->run('DELETE /task/{id} - delete task', function() use ($api, &$taskId) {
    if (empty($taskId)) {
        echo "  SKIP: taskId not set\n";
        return;
    }
    $result = $api->deleteTask($taskId);
    assertTrue($result['result'] ?? false, 'delete result = true');

    // Проверяем что задача удалена
    $check = $api->getTask($taskId);
    assertFalse($check['result'] ?? true, 'getTask returns false for deleted task');
});

exit($runner->exitCode());
