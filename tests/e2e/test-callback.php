<?php
/**
 * E2E тест: callback-режим.
 *
 * Сценарий:
 * 1. Запуск PJSUA-оператора (внутр. номер) и PJSUA-клиента (транк)
 * 2. Создание задачи с isCallback=1
 * 3. Asterisk сначала звонит оператору (internal), затем клиенту (trunk)
 * 4. Оператор подтверждает callback нажатием DTMF "1" (через AMI PlayDTMF)
 * 5. Проверка: result содержит SUCCESS
 *
 * Запуск: php -f tests/e2e/test-callback.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';
require_once __DIR__ . '/../lib/PjsuaManager.php';
require_once __DIR__ . '/../lib/AmiHelper.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('E2E: Callback');
$timeouts = $config['timeouts'];

$runner->run('Callback mode: operator first, then client', function() use ($api, $config, $timeouts) {
    $testCrmId = $config['test_crm_prefix'] . 'callback-' . time();
    $pjsuaClient = null;
    $pjsuaOperator = null;
    $ami = null;

    try {
        // 0. Подключение к AMI (для отправки DTMF)
        $ami = new AmiHelper();
        assertTrue($ami->connect(), 'AMI connected');

        // 1. Запуск PJSUA-оператора
        $pjsuaOperator = new PjsuaManager(array_merge($config['sip_operator'], [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 60,
        ]), 'operator');

        assertTrue($pjsuaOperator->start(), 'Operator PJSUA started');
        assertTrue($pjsuaOperator->waitForRegistration($timeouts['registration']), 'Operator registered');

        // 2. Запуск PJSUA-клиента (транк)
        $pjsuaClient = new PjsuaManager(array_merge($config['sip_client'], [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 60,
        ]), 'client');

        assertTrue($pjsuaClient->start(), 'Client PJSUA started');
        assertTrue($pjsuaClient->waitForRegistration($timeouts['registration']), 'Client registered (trunk)');

        // Ожидание Reachable-статуса endpoint'ов (qualify_frequency может быть 60с)
        $ami->qualifyEndpoint($config['sip_client']['username']);
        $ami->qualifyEndpoint($config['sip_operator']['username']);

        // 3. Создание задачи с callback
        $changeTime = (string)(microtime(true) - 1);
        $result = $api->createTask([
            'crmId'            => $testCrmId,
            'name'             => 'E2E Callback',
            'state'            => 0,
            'innerNum'         => $config['operator_exten'],
            'isCallback'       => 1,
            'dialPrefix'       => $config['dial_prefix'],
            'maxCountChannels' => 1,
            'numbers'          => [$config['test_phone']],
        ]);

        assertTrue($result['result'] ?? false, 'Task created');
        $taskId = (string)($result['data']['id'] ?? '');
        assertNotEmpty($taskId, 'taskId is set');

        // 4. Ожидание входящего звонка на оператора и подтверждение callback через AMI
        // В callback-режиме Asterisk воспроизводит alert-сообщение (~9с) и WaitExten(6с)
        // PJSUA в --null-audio не отправляет DTMF, поэтому используем AMI PlayDTMF
        assertTrue(
            $ami->waitAndSendDtmf($config['operator_channel'], '1', $timeouts['call_start'], $timeouts['dtmf_delay']),
            'DTMF "1" sent via AMI'
        );

        // 5. Ожидание результата (после подтверждения звонок идёт клиенту через транк)
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
