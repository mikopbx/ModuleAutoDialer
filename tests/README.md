# Тестирование ModuleAutoDialer

## Требования

Все тесты запускаются **на PBX-сервере**, т.к. им нужен доступ к REST API модуля, Asterisk, Beanstalk и БД.

- **Сервер:** `serber@boffart.miko.ru`
- **Путь модуля:** `/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/`
- **Модуль должен быть включён** в веб-интерфейсе MikoPBX

> **Важно:** если тест выбросит необработанное исключение, MikoPBX автоматически отключит модуль. Включить обратно — через веб-интерфейс.

## Типы тестов

| Тип | Каталог | Что тестирует | Зависимости |
|---|---|---|---|
| Unit/Integration | `tests/unit/` | REST API через HTTP (CRUD задач, клиентов) | REST API, ConnectorDB воркер |
| E2E | `tests/e2e/` | Полный цикл звонка: SIP-регистрация, вызов, результат | Asterisk, PJSUA, AMI, SIP-транк |

## Развёртывание тестов на сервер

```bash
scp -r tests/ serber@boffart.miko.ru:/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/
```

Или отдельный файл:

```bash
scp tests/unit/test-api-tasks.php \
  serber@boffart.miko.ru:/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/unit/
```

## Запуск тестов

### Все тесты (unit + e2e)

```bash
ssh serber@boffart.miko.ru \
  "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/run-all.php 2>&1 | grep -v '^php.backend'"
```

### Только unit/integration

```bash
ssh serber@boffart.miko.ru \
  "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/run-all.php unit 2>&1 | grep -v '^php.backend'"
```

### Только E2E

```bash
ssh serber@boffart.miko.ru \
  "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/run-all.php e2e 2>&1 | grep -v '^php.backend'"
```

### Отдельный тест

```bash
ssh serber@boffart.miko.ru \
  "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/unit/test-api-tasks.php 2>&1 | grep -v '^php.backend'"
```

## Конфигурация: `e2e-config.php`

| Параметр | Значение | Описание |
|---|---|---|
| `api_url` | `http://127.0.0.1/pbxcore/api/module-dialer/v1` | REST API модуля |
| `sip_client` | `SIP-1692280724`, порт 5080 | SIP-транк, принимает исходящие вызовы |
| `sip_operator` | `228`, порт 5082 | Внутренний номер оператора |
| `dial_prefix` | `999` | Префикс маршрута AutoDialer |
| `test_phone` | `79990000228` | Тестовый номер клиента |
| `polling_id` | `15` | ID существующего опроса в БД |

## Настройка PBX для E2E тестов

1. SIP-транк `SIP-1692280724` настроен в MikoPBX
2. Исходящий маршрут с префиксом `999` направлен на этот транк
3. Внутренний номер `228` существует
4. Бинарники `pjsua-linux-*` в `tests/bin/` имеют `chmod +x`
5. AMI-доступ: логин `phpagi` / пароль `phpagi`, порт 5038

## Структура тестового фреймворка

```
tests/
  lib/
    TestRunner.php    — assert-функции и TestRunner (try/catch обёртка)
    ApiClient.php     — HTTP-клиент REST API (curl)
    PjsuaManager.php  — управление SIP-клиентом pjsua (E2E)
    AmiHelper.php     — AMI-клиент для DTMF и каналов (E2E)
  unit/
    test-api-tasks.php    — CRUD задач обзвона
    test-api-clients.php  — CRUD клиентов
    test-data-integrity.php — целостность данных в БД
  e2e/
    test-basic-call.php      — базовый исходящий звонок
    test-callback.php        — callback-режим
    test-retry.php           — повторные попытки дозвона
    test-client-grouping.php — группировка номеров по clientId
    test-polling-ivr.php     — IVR-опрос с DTMF
    test-working-hours.php   — рабочее время (timeStart/timeEnd)
  e2e-config.php   — параметры подключения
  run-all.php      — запуск всех тестов
```

## Написание новых тестов

```php
<?php
require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../lib/ApiClient.php';

$config = require __DIR__ . '/../e2e-config.php';
$api = new ApiClient($config['api_url']);
$runner = new TestRunner('My Test Suite');

$runner->run('test name', function() use ($api) {
    $result = $api->getTasks();
    assertTrue($result['result'] ?? false, 'tasks loaded');
    // assertEq($expected, $actual, 'message')
    // assertContains($needle, $haystack, 'message')
    // assertNotEmpty($value, 'message')
    // assertFalse($actual, 'message')
});

exit($runner->exitCode());
```

## Ручное тестирование REST API

См. [curl-examples.md](curl-examples.md) — примеры curl-запросов для ручной проверки каждого эндпоинта.
