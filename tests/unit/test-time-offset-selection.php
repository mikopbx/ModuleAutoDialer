<?php

require_once 'Globals.php';
require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';

use Modules\ModuleAutoDialer\bin\ConnectorDB;

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('Time Offset Selection');
$taskId = '';
$firstPhone = '79995550201';
$secondPhone = '79995550202';

$runner->run('Select later number whose recipient-local window is open', function () use (
    $api,
    $config,
    &$taskId,
    $firstPhone,
    $secondPhone
): void {
    $pbxMinute = (int)date('G') * 60 + (int)date('i');
    $timeStart = ($pbxMinute + 1438) % 1440;
    $timeEnd = ($pbxMinute + 2) % 1440;
    $crmId = $config['test_crm_prefix'] . 'timezone-selection-' . str_replace('.', '', (string)microtime(true));

    $response = $api->createTask([
        'crmId' => $crmId,
        'name' => 'Time offset selection',
        'state' => 0,
        'innerNum' => '99999',
        'maxCountChannels' => 1,
        'timeStart' => $timeStart,
        'timeEnd' => $timeEnd,
        'numbers' => [
            ['number' => $firstPhone, 'TimeOffset' => 12],
            ['number' => $secondPhone, 'TimeOffset' => 0],
        ],
    ]);

    if (!($response['result'] ?? false)) {
        echo '  API response: ' . json_encode($response, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    assertTrue($response['result'] ?? false, 'task created');
    $taskId = (string)($response['data']['id'] ?? '');
    assertNotEmpty($taskId, 'task id returned');

    $slice = ConnectorDB::invoke('getSliceTask', [], true, 10);
    $selected = null;
    foreach ($slice as $task) {
        if ((string)($task['taskId'] ?? '') === $taskId) {
            $selected = $task;
            break;
        }
    }

    assertTrue(is_array($selected), 'task appears in worker slice');
    assertEq($secondPhone, $selected['phone'] ?? '', 'PBX-local zero candidate selected');
    assertFalse(($selected['phone'] ?? '') === $firstPhone, 'first out-of-window candidate skipped');
});

$runner->run('Cleanup', function () use ($api, &$taskId): void {
    if ($taskId === '') {
        assertTrue(true, 'nothing to clean');
        return;
    }
    $response = $api->deleteTask($taskId);
    assertTrue($response['result'] ?? false, 'task deleted');
});

exit($runner->exitCode());
