<?php
/**
 * E2E тест: повторные попытки дозвона.
 *
 * Сценарий:
 * 1. Запуск клиента PJSUA (транк зарегистрирован с самого начала)
 * 2. Создание polling-задачи с maxAttempt=2, tryInterval=30
 * 3. Первая попытка: вызов проходит, но DTMF не отправляется → FAIL_POLLING
 * 4. Ожидание завершения всех каналов первой попытки
 * 5. После tryInterval: вторая попытка → отправка DTMF "1" → SUCCESS_POLLING
 *
 * Запуск: php -f tests/e2e/test-retry.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';
require_once __DIR__ . '/../lib/PjsuaManager.php';
require_once __DIR__ . '/../lib/AmiHelper.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('E2E: Retry');
$timeouts = $config['timeouts'];

$runner->run('Retry: fail first attempt (FAIL_POLLING), succeed on second', function() use ($api, $config, $timeouts) {
    $testCrmId = $config['test_crm_prefix'] . 'retry-' . time();
    $pjsuaClient = null;
    $ami = null;

    // Используем существующий опрос — его контекст уже в диалплане
    $pollingId = $config['polling_id'];

    try {
        // 0. Подключение к AMI
        $ami = new AmiHelper();
        assertTrue($ami->connect(), 'AMI connected');

        // 1. Запуск клиента PJSUA (транк зарегистрирован сразу)
        $pjsuaClient = new PjsuaManager(array_merge($config['sip_client'], [
            'binary'      => $config['pjsua_binary'],
            'auto_answer' => 200,
            'duration'    => 120,
        ]), 'client');

        assertTrue($pjsuaClient->start(), 'Client PJSUA started');
        assertTrue($pjsuaClient->waitForRegistration($timeouts['registration']), 'Client registered');

        // Ожидание Reachable-статуса endpoint (qualify_frequency может быть 60с)
        $ami->qualifyEndpoint($config['sip_client']['username']);

        // 2. Создание polling-задачи с retry
        $changeTime = (string)(microtime(true) - 1);
        $result = $api->createTask([
            'crmId'            => $testCrmId,
            'name'             => 'E2E Retry',
            'state'            => 0,
            'innerNum'         => $pollingId,
            'innerNumType'     => 'polling',
            'dialPrefix'       => $config['dial_prefix'],
            'maxCountChannels' => 1,
            'maxAttempt'       => 2,
            'tryInterval'      => 30,
            'numbers'          => [$config['test_phone']],
        ]);

        assertTrue($result['result'] ?? false, 'Task created');
        $taskId = (string)($result['data']['id'] ?? '');
        assertNotEmpty($taskId, 'taskId is set');

        // 3. Ожидание первого FAIL_POLLING (DTMF не отправляем — опрос таймаутится)
        echo "  Waiting for first attempt to fail (FAIL_POLLING, no DTMF)...\n";
        $firstResults = $api->pollResults($taskId, $changeTime, 90, $timeouts['poll_interval']);
        assertTrue(count($firstResults) > 0, 'Got first attempt result');

        $firstResult = $firstResults[0]['result'] ?? '';
        assertContains('FAIL', $firstResult, "First attempt failed (got: {$firstResult})");
        echo "  First attempt result: {$firstResult}\n";

        // 4. Ожидание исчезновения каналов первой попытки
        echo "  Waiting for first attempt channels to clear...\n";
        $clearDeadline = time() + 60;
        while (time() < $clearDeadline) {
            $channels = $ami->listAllChannels();
            $trunkPattern = $config['trunk_channel'];
            $trunkChannels = array_filter($channels, function($ch) use ($trunkPattern) {
                return strpos($ch, $trunkPattern) !== false;
            });
            if (empty($trunkChannels)) {
                echo "  All channels cleared.\n";
                break;
            }
            usleep(500000);
        }

        // 5. Ожидание второй попытки (tryInterval=30с) и отправка DTMF
        echo "  Waiting for second attempt channel and sending DTMF...\n";
        assertTrue(
            $ami->waitAndSendDtmf($config['trunk_channel'], '1', $timeouts['call_start'], 3),
            'DTMF "1" sent via AMI on second attempt'
        );

        // 6. Ожидание результата второй попытки
        echo "  Waiting for second attempt result...\n";
        $deadline = time() + $timeouts['poll_max_wait'];
        $secondResult = null;
        while (time() < $deadline) {
            $response = $api->getResults($changeTime);
            $data = $response['data']['results'] ?? [];
            if (is_array($data)) {
                foreach ($data as $r) {
                    if ((string)($r['taskId'] ?? '') === $taskId
                        && (int)($r['attemptNumber'] ?? 0) >= 2
                        && !empty($r['result'])
                        && strpos($r['result'], 'SUCCESS') === 0) {
                        $secondResult = $r;
                        break 2;
                    }
                }
            }
            sleep($timeouts['poll_interval']);
        }

        assertTrue($secondResult !== null, 'Got second attempt SUCCESS result');
        if ($secondResult !== null) {
            assertEq('SUCCESS_POLLING', $secondResult['result'], "Second attempt = SUCCESS_POLLING");
            echo "  Second attempt result: {$secondResult['result']} (attempt #{$secondResult['attemptNumber']})\n";
        }

        // Cleanup
        $api->deleteTask($taskId);

    } finally {
        if ($pjsuaClient !== null) {
            $pjsuaClient->stop();
        }
        if ($ami !== null) {
            $ami->disconnect();
        }
    }
});

exit($runner->exitCode());
