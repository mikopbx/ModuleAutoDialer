<?php

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('Time Offset API');
$taskId = '';
$crmId = $config['test_crm_prefix'] . 'timezone-' . str_replace('.', '', (string)microtime(true));

$runner->run('Persist normalized offsets for every number', function () use ($api, $crmId, &$taskId): void {
    $response = $api->createTask([
        'crmId' => $crmId,
        'name' => 'Time offset persistence',
        'state' => 1,
        'innerNum' => '19',
        'innerNumType' => 'polling',
        'timeStart' => 480,
        'timeEnd' => 1320,
        'numbers' => [
            ['number' => '79995550101', 'TimeOffset' => '5'],
            ['number' => '79995550102', 'TimeOffset' => '0'],
            ['number' => '79995550103', 'TimeOffset' => -4],
            ['number' => '79995550104', 'TimeOffset' => ''],
        ],
    ]);

    assertTrue($response['result'] ?? false, 'task created');
    $taskId = (string)($response['data']['id'] ?? '');
    assertNotEmpty($taskId, 'task id returned');

    $task = $api->getTask($taskId);
    assertTrue($task['result'] ?? false, 'task loaded');
    $offsets = [];
    foreach ($task['data']['results'] ?? [] as $row) {
        $offsets[$row['phone']] = $row['timeOffsetMinutes'] ?? null;
    }
    assertEq(300, (int)($offsets['79995550101'] ?? -1), 'UTC+5 stored as 300');
    assertEq(0, (int)($offsets['79995550102'] ?? -1), 'UTC stored as zero');
    assertEq(-240, (int)($offsets['79995550103'] ?? 1), 'UTC-4 stored as -240');
    assertEq(null, $offsets['79995550104'] ?? null, 'empty offset stored as NULL');
});

$runner->run('Reject invalid explicit offset', function () use ($api, $crmId): void {
    $response = $api->createTask([
        'crmId' => $crmId . '-invalid',
        'name' => 'Invalid time offset',
        'state' => 1,
        'innerNum' => '19',
        'numbers' => [
            ['number' => '79995550105', 'TimeOffset' => 'Moscow'],
        ],
    ]);

    assertFalse($response['result'] ?? true, 'invalid offset rejected');
    assertContains('TimeOffset', json_encode($response, JSON_UNESCAPED_UNICODE), 'error names TimeOffset');
    assertContains('79995550105', json_encode($response, JSON_UNESCAPED_UNICODE), 'error names phone');
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
