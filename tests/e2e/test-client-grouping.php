<?php
/**
 * E2E тест: группировка номеров по клиенту.
 *
 * Сценарий:
 * 1. Задача с двумя номерами одного clientId
 * 2. Первый номер дозванивается (PJSUA отвечает)
 * 3. Второй номер автоматически закрывается
 * 4. Проверка: первый = SUCCESS*, второй = SUCCESS_ANOTHER_PHONE
 *
 * Запуск: php -f tests/e2e/test-client-grouping.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';
require_once __DIR__ . '/../lib/PjsuaManager.php';
require_once __DIR__ . '/../lib/AmiHelper.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('E2E: Client Grouping');
$timeouts = $config['timeouts'];

$runner->run('Client grouping: success on one phone closes others', function() use ($api, $config, $timeouts) {
    $testCrmId = $config['test_crm_prefix'] . 'grouping-' . time();
    $pjsuaClient = null;
    $pjsuaOperator = null;
    $ami = null;

    try {
        // 0. Подключение к AMI (для qualify endpoint'ов)
        $ami = new AmiHelper();
        $ami->connect();

        // 1. Запуск PJSUA (клиент + оператор)
        $pjsuaClient = new PjsuaManager(array_merge($config['sip_client'], [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 30,
        ]), 'client');

        assertTrue($pjsuaClient->start(), 'Client PJSUA started');
        assertTrue($pjsuaClient->waitForRegistration($timeouts['registration']), 'Client registered');

        $pjsuaOperator = new PjsuaManager(array_merge($config['sip_operator'], [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 30,
        ]), 'operator');

        assertTrue($pjsuaOperator->start(), 'Operator PJSUA started');
        assertTrue($pjsuaOperator->waitForRegistration($timeouts['registration']), 'Operator registered');

        // Ожидание Reachable-статуса endpoint'ов
        $ami->qualifyEndpoint($config['sip_client']['username']);
        $ami->qualifyEndpoint($config['sip_operator']['username']);

        // 2. Создание задачи с двумя номерами одного клиента
        $changeTime = (string)(microtime(true) - 1);
        $clientIdShared = 'test-client-group-' . time();
        $phoneReachable = $config['test_phone'];
        $phoneUnreachable = '79990009999';

        $result = $api->createTask([
            'crmId'            => $testCrmId,
            'name'             => 'E2E Client Grouping',
            'state'            => 0,
            'innerNum'         => $config['operator_exten'],
            'dialPrefix'       => $config['dial_prefix'],
            'maxCountChannels' => 1,
            'numbers'          => [
                ['number' => $phoneReachable, 'clientId' => $clientIdShared],
                ['number' => $phoneUnreachable, 'clientId' => $clientIdShared],
            ],
        ]);

        assertTrue($result['result'] ?? false, 'Task created');
        $taskId = (string)($result['data']['id'] ?? '');
        assertNotEmpty($taskId, 'taskId is set');

        // 3. Ожидание результатов обоих номеров
        $deadline = time() + $timeouts['poll_max_wait'];
        $allResults = [];
        while (time() < $deadline) {
            $response = $api->getResults($changeTime);
            $data = $response['data']['results'] ?? [];
            if (is_array($data)) {
                $matched = array_filter($data, function ($r) use ($taskId) {
                    return (string)($r['taskId'] ?? '') === $taskId && !empty($r['result']);
                });
                if (count($matched) >= 2) {
                    $allResults = array_values($matched);
                    break;
                }
            }
            sleep($timeouts['poll_interval']);
        }

        assertTrue(count($allResults) >= 2, 'Got results for both numbers');

        // 4. Проверка результатов
        $resultsByPhone = [];
        foreach ($allResults as $r) {
            $phone = $r['phone'] ?? '';
            $resultsByPhone[$phone] = $r['result'] ?? '';
        }

        if (isset($resultsByPhone[$phoneReachable])) {
            assertContains('SUCCESS', $resultsByPhone[$phoneReachable],
                "Reachable phone: SUCCESS (got: {$resultsByPhone[$phoneReachable]})");
        }

        if (isset($resultsByPhone[$phoneUnreachable])) {
            assertEq('SUCCESS_ANOTHER_PHONE', $resultsByPhone[$phoneUnreachable],
                'Unreachable phone: SUCCESS_ANOTHER_PHONE');
        }

        // Cleanup
        $api->deleteTask($taskId);

    } finally {
        if ($pjsuaClient !== null) {
            $pjsuaClient->stop();
        }
        if ($pjsuaOperator !== null) {
            $pjsuaOperator->stop();
        }
        if ($ami !== null) {
            $ami->disconnect();
        }
    }
});

exit($runner->exitCode());
