<?php
/**
 * E2E тест: IVR-опрос (polling).
 *
 * Сценарий:
 * 1. Используем существующий опрос (polling_id из конфига) — диалплан
 *    генерируется при старте модуля, новые опросы не получают контексты.
 * 2. Создание задачи с innerNumType=polling
 * 3. PJSUA принимает вызов, через AMI PlayDTMF отправляется "1"
 * 4. Проверка: result = SUCCESS_POLLING, PolingResults содержит ответ
 *
 * Запуск: php -f tests/e2e/test-polling-ivr.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';
require_once __DIR__ . '/../lib/PjsuaManager.php';
require_once __DIR__ . '/../lib/AmiHelper.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('E2E: Polling IVR');
$timeouts = $config['timeouts'];

$runner->run('Polling IVR: answer question with DTMF', function() use ($api, $config, $timeouts) {
    $testCrmId = $config['test_crm_prefix'] . 'polling-' . time();
    $clientSip = $config['sip_client'];
    $pjsua = null;
    $ami = null;

    // Используем существующий опрос — его контекст уже в диалплане
    $pollingId = $config['polling_id'];

    try {
        // 0. Подключение к AMI (для отправки DTMF)
        $ami = new AmiHelper();
        assertTrue($ami->connect(), 'AMI connected');

        // 1. Запуск PJSUA-клиента (транк)
        $pjsua = new PjsuaManager(array_merge($clientSip, [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 60,
        ]), 'client');

        assertTrue($pjsua->start(), 'PJSUA started');
        assertTrue($pjsua->waitForRegistration($timeouts['registration']), 'PJSUA registered');

        // Ожидание Reachable-статуса endpoint (qualify_frequency может быть 60с)
        $ami->qualifyEndpoint($config['sip_client']['username']);

        // 2. Создание задачи с polling
        $changeTime = (string)(microtime(true) - 1);
        $result = $api->createTask([
            'crmId'            => $testCrmId,
            'name'             => 'E2E Polling',
            'state'            => 0, // STATE_OPEN
            'innerNum'         => $pollingId,
            'innerNumType'     => 'polling',
            'dialPrefix'       => $config['dial_prefix'],
            'maxCountChannels' => 1,
            'numbers'          => [$config['test_phone']],
        ]);

        assertTrue($result['result'] ?? false, 'Task created');
        $taskId = (string)($result['data']['id'] ?? '');
        assertNotEmpty($taskId, 'taskId is set');

        // 3. Отправка DTMF через AMI
        // НЕ ждём PJSUA CONFIRMED — stdout буферизован и CONFIRMED появляется
        // только при Hangup. Вместо этого ищем канал через AMI сразу после создания задачи.
        // WorkerDialer подхватывает задачу в течение 1-2с, call-file создаётся и канал появляется.
        // delayBeforeDtmf=3: пауза чтобы Background() начал играть вопрос и перешёл в WaitExten.
        echo "  Waiting for channel and sending DTMF via AMI...\n";
        assertTrue(
            $ami->waitAndSendDtmf($config['trunk_channel'], '1', $timeouts['call_start'], 3),
            'DTMF "1" sent via AMI'
        );

        // 4. Ожидание результата
        $results = $api->pollResults($taskId, $changeTime, $timeouts['poll_max_wait'], $timeouts['poll_interval']);
        assertTrue(count($results) > 0, 'Got call results');

        if (!empty($results)) {
            $callResult = $results[0]['result'] ?? '';
            assertEq('SUCCESS_POLLING', $callResult, 'Result = SUCCESS_POLLING');
        }

        // 5. Проверка результатов опроса
        $pollingResults = $api->getPollingResults($changeTime);
        $pollData = $pollingResults['data']['results'] ?? [];
        $ourPollResults = [];
        if (is_array($pollData)) {
            $ourPollResults = array_filter($pollData, function($r) use ($taskId) {
                return (string)($r['taskId'] ?? '') === $taskId;
            });
        }

        assertTrue(count($ourPollResults) >= 1, 'Polling results recorded');

        // Cleanup
        $api->deleteTask($taskId);

    } finally {
        if ($pjsua !== null) {
            $pjsua->stop();
        }
        if ($ami !== null) {
            $ami->disconnect();
        }
    }
});

exit($runner->exitCode());
