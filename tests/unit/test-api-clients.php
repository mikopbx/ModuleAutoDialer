<?php
/**
 * Интеграционные тесты REST API клиентов.
 *
 * Тестирует CRUD клиентов через HTTP API:
 * POST /client, GET /client-by-phone/{phone}, DELETE /client/{crmId}
 *
 * Запуск: php -f tests/unit/test-api-clients.php
 */

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('API Clients');

$testCrmId = $config['test_crm_prefix'] . 'client-' . time();
$testPhone1 = '79990001122';
$testPhone2 = '79990001133';

// --- Тест 1: Создание клиента ---
$runner->run('POST /client - create client', function() use ($api, $testCrmId, $testPhone1, $testPhone2) {
    $result = $api->createClients([
        [
            'crmId'  => $testCrmId,
            'name'   => 'Test Client',
            'phones' => [$testPhone1, $testPhone2],
            'properties' => [
                ['key' => 'ADDRESS', 'value' => 'Moscow, Test St. 1'],
                ['key' => 'ACCOUNT', 'value' => '10001234'],
            ],
        ],
    ]);

    assertTrue($result['result'] ?? false, 'result = true');
    $data = $result['data'] ?? [];
    assertTrue(is_array($data) && count($data) >= 1, 'data contains client');
    assertEq($testCrmId, $data[0]['crmId'] ?? '', 'crmId matches');
});

// --- Тест 2: Поиск по телефону ---
$runner->run('GET /client-by-phone/{phone} - find by phone', function() use ($api, $testPhone1) {
    $result = $api->getClientByPhone($testPhone1);

    assertTrue($result['result'] ?? false, 'result = true');
    $data = $result['data'] ?? [];
    assertTrue(is_array($data) && count($data) >= 1, 'found properties');

    // Ищем свойство ADDRESS
    $address = '';
    foreach ($data as $prop) {
        if (($prop['key'] ?? '') === 'ADDRESS') {
            $address = $prop['value'] ?? '';
        }
    }
    assertEq('Moscow, Test St. 1', $address, 'ADDRESS property correct');

    // Ищем свойство NAME (автоматически добавляется)
    $name = '';
    foreach ($data as $prop) {
        if (($prop['key'] ?? '') === 'NAME') {
            $name = $prop['value'] ?? '';
        }
    }
    assertEq('Test Client', $name, 'NAME property correct');
});

// --- Тест 3: Поиск по второму телефону ---
$runner->run('GET /client-by-phone/{phone2} - find by second phone', function() use ($api, $testPhone2) {
    $result = $api->getClientByPhone($testPhone2);

    assertTrue($result['result'] ?? false, 'result = true');
    $data = $result['data'] ?? [];
    assertTrue(count($data) >= 1, 'found by second phone number too');
});

// --- Тест 4: Обновление клиента (тот же crmId) ---
$runner->run('POST /client (same crmId) - update client', function() use ($api, $testCrmId, $testPhone1) {
    $result = $api->createClients([
        [
            'crmId'  => $testCrmId,
            'name'   => 'Updated Client',
            'phones' => [$testPhone1], // Теперь только один телефон
            'properties' => [
                ['key' => 'ADDRESS', 'value' => 'SPb, New St. 2'],
            ],
        ],
    ]);

    assertTrue($result['result'] ?? false, 'update result = true');

    // Проверяем обновлённые данные
    $check = $api->getClientByPhone($testPhone1);
    $data = $check['data'] ?? [];

    // Ищем обновлённый адрес
    $address = '';
    foreach ($data as $prop) {
        if (($prop['key'] ?? '') === 'ADDRESS') {
            $address = $prop['value'] ?? '';
        }
    }
    assertEq('SPb, New St. 2', $address, 'ADDRESS updated');
});

// --- Тест 5: Удаление клиента ---
$runner->run('DELETE /client/{crmId} - delete client', function() use ($api, $testCrmId, $testPhone1) {
    $result = $api->deleteClient($testCrmId);
    assertTrue($result['result'] ?? false, 'delete result = true');

    // Проверяем что клиент удалён
    $check = $api->getClientByPhone($testPhone1);
    $data = $check['data'] ?? [];
    assertEq(0, count($data), 'client not found after delete');
});

// --- Тест 6: Повторное удаление (идемпотентность) ---
$runner->run('DELETE /client/{crmId} - idempotent delete', function() use ($api, $testCrmId) {
    $result = $api->deleteClient($testCrmId);
    assertTrue($result['result'] ?? false, 'second delete still returns true');
});

exit($runner->exitCode());
