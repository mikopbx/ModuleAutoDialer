<?php
/**
 * E2E тест: рабочее время (timeStart/timeEnd).
 *
 * Сценарий:
 * 1. Задача с timeStart/timeEnd заведомо вне текущего времени
 * 2. Ожидание 10с (цикл WorkerDialer)
 * 3. Проверка: все результаты в state=CreateTask, result пустой
 *
 * Запуск: php -f tests/e2e/test-working-hours.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('E2E: Working Hours');

$runner->run('Working hours: task outside allowed time window', function() use ($api, $config) {
    $testCrmId = $config['test_crm_prefix'] . 'hours-' . time();

    // Рассчитываем timeStart/timeEnd заведомо вне текущего времени.
    // Текущее время в минутах от 00:00
    $nowMinutes = (int)((time() - strtotime("today")) / 60);

    // Если сейчас 12:00 (720 мин), ставим окно 0-1 (ночью)
    // Если сейчас 0:30 (30 мин), ставим окно 1400-1439 (поздняя ночь)
    if ($nowMinutes > 60) {
        $timeStart = 0;
        $timeEnd   = 1;
    } else {
        $timeStart = 1400;
        $timeEnd   = 1439;
    }

    $changeTime = (string)(microtime(true) - 1);
    $result = $api->createTask([
        'crmId'            => $testCrmId,
        'name'             => 'E2E Working Hours',
        'state'            => 0, // STATE_OPEN
        'innerNum'         => $config['operator_exten'],
        'dialPrefix'       => $config['dial_prefix'],
        'maxCountChannels' => 1,
        'timeStart'        => $timeStart,
        'timeEnd'          => $timeEnd,
        'numbers'          => ['79001110001', '79001110002'],
    ]);

    assertTrue($result['result'] ?? false, 'Task created');
    $taskId = (string)($result['data']['id'] ?? '');
    assertNotEmpty($taskId, 'taskId is set');

    // Ждём два цикла WorkerDialer (~10с)
    echo "  Waiting 15s for WorkerDialer cycle...\n";
    sleep(15);

    // Проверяем что звонки не состоялись
    $taskData = $api->getTask($taskId);
    assertTrue($taskData['result'] ?? false, 'getTask result = true');

    $results = $taskData['data']['results'] ?? [];
    assertTrue(is_array($results) && count($results) === 2, '2 results exist');

    $allInCreateState = true;
    $allNoResult = true;
    foreach ($results as $r) {
        if (($r['state'] ?? '') !== 'CreateTask') {
            $allInCreateState = false;
        }
        if (!empty($r['result'] ?? '')) {
            $allNoResult = false;
        }
    }

    assertTrue($allInCreateState, 'All results in CreateTask state (not dialed)');
    assertTrue($allNoResult, 'All results have empty result (no calls made)');

    // Cleanup
    $api->deleteTask($taskId);
});

exit($runner->exitCode());
