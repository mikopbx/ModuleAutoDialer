<?php
/**
 * E2E тест: базовый исходящий звонок.
 *
 * Сценарий:
 * 1. Запуск PJSUA-клиента (транк) и PJSUA-оператора (внутренний номер)
 * 2. Создание задачи обзвона (state=OPEN)
 * 3. WorkerDialer звонит через транк -> клиент отвечает -> мост к оператору
 * 4. Проверка: результат содержит SUCCESS
 *
 * Запуск: php -f tests/e2e/test-basic-call.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';
require_once __DIR__ . '/../lib/PjsuaManager.php';
require_once __DIR__ . '/../lib/AmiHelper.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('E2E: Basic Call');
$timeouts = $config['timeouts'];

$runner->run('Basic outbound call with auto-answer', function() use ($api, $config, $timeouts) {
    $testCrmId = $config['test_crm_prefix'] . 'basic-' . time();
    $pjsuaClient = null;
    $pjsuaOperator = null;
    $ami = null;

    try {
        // 0. Подключение к AMI (для qualify endpoint'ов)
        $ami = new AmiHelper();
        $ami->connect();

        // 1. Запуск PJSUA-клиента (регистрация как SIP-транк)
        $pjsuaClient = new PjsuaManager(array_merge($config['sip_client'], [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 30,
        ]), 'client');

        assertTrue($pjsuaClient->start(), 'Client PJSUA started');
        assertTrue($pjsuaClient->waitForRegistration($timeouts['registration']), 'Client registered (trunk)');

        // 2. Запуск PJSUA-оператора (внутренний номер)
        $pjsuaOperator = new PjsuaManager(array_merge($config['sip_operator'], [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 30,
        ]), 'operator');

        assertTrue($pjsuaOperator->start(), 'Operator PJSUA started');
        assertTrue($pjsuaOperator->waitForRegistration($timeouts['registration']), 'Operator registered (ext 228)');

        // Ожидание Reachable-статуса endpoint'ов
        $ami->qualifyEndpoint($config['sip_client']['username']);
        $ami->qualifyEndpoint($config['sip_operator']['username']);

        // 3. Создание задачи
        $changeTime = (string)(microtime(true) - 1);
        $result = $api->createTask([
            'crmId'            => $testCrmId,
            'name'             => 'E2E Basic Call',
            'state'            => 0, // STATE_OPEN
            'innerNum'         => $config['operator_exten'],
            'dialPrefix'       => $config['dial_prefix'],
            'maxCountChannels' => 1,
            'numbers'          => [$config['test_phone']],
        ]);

        assertTrue($result['result'] ?? false, 'Task created');
        $taskId = (string)($result['data']['id'] ?? '');
        assertNotEmpty($taskId, 'taskId is set');

        // 4. Ожидание результата
        $results = $api->pollResults($taskId, $changeTime, $timeouts['poll_max_wait'], $timeouts['poll_interval']);
        assertTrue(count($results) > 0, 'Got call results');

        if (!empty($results)) {
            $callResult = $results[0]['result'] ?? '';
            assertContains('SUCCESS', $callResult, "Result contains SUCCESS (got: {$callResult})");
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
