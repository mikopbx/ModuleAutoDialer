
@sessions/CLAUDE.sessions.md

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## CRITICAL RULES

- **Git commit и push ТОЛЬКО с явного разрешения пользователя.** Никогда не выполнять `git commit` или `git push` без явной просьбы.

## Обзор проекта

ModuleAutoDialer — модуль расширения для MikoPBX, реализующий автоматический обзвон и IVR-опросы. Модуль работает поверх Asterisk PBX и использует Phalcon MVC фреймворк.

- **Язык**: PHP 7.4+, JavaScript/ES6, Volt-шаблоны
- **Фреймворк**: Phalcon 5.8 (наследуется от MikoPBX Core)
- **Namespace**: `Modules\ModuleAutoDialer\`
- **Минимальная версия PBX**: 2024.1.114

## Структура каталогов

| Каталог | Назначение |
|---|---|
| `Setup/` | Установка/удаление модуля (`PbxExtensionSetup`) |
| `App/Controllers/` | Web-контроллеры (admin-интерфейс) |
| `App/Forms/` | Phalcon-формы для UI |
| `App/Views/` | Volt-шаблоны |
| `App/Providers/` | DI-сервис-провайдеры (View, Volt) |
| `Lib/` | Бизнес-логика, утилиты, конфигурация Asterisk |
| `Lib/RestAPI/Controllers/` | REST API эндпоинты (отдельно от admin) |
| `bin/` | Фоновые воркеры (CLI-скрипты) |
| `Models/` | Phalcon ORM модели (12 файлов) |
| `agi-bin/` | AGI-скрипты Asterisk |
| `Messages/` | i18n переводы (28+ языков) |
| `public/assets/js/src/` | Исходники JS (компилируются Babel) |
| `public/assets/js/` | Скомпилированные JS + source maps |
| `tests/` | Тестовый фреймворк: unit/integration + E2E тесты |
| `1c/` | Интеграция с 1C:Enterprise |

## Команды

```bash
# Установка PHP-зависимостей
composer install

# Проверка синтаксиса
php -l <file.php>

# Фоновые воркеры (запускаются на PBX-системе)
php bin/ConnectorDB.php   # IPC-диспетчер, все операции с БД
php bin/WorkerDialer.php  # Основной воркер обзвона
php bin/WorkerAMI.php     # Обработка AMI-событий Asterisk
```

Схема БД создаётся автоматически из аннотаций Phalcon-моделей при вызове `PbxExtensionSetup::installDB()`.

### Кэши (после изменения Messages/*.php или Volt-шаблонов)
```bash
ssh serber@boffart.miko.ru 'redis-cli -n 4 FLUSHDB && rm -rf /var/tmp/www_cache/volt/* && php -r "opcache_reset();" 2>/dev/null'
```
- Redis DB#4: кэш переводов (`LocalisationArray:*`), TTL 1 час
- `/var/tmp/www_cache/volt/`: скомпилированные Volt-шаблоны
- OPcache: скомпилированные PHP (включая Messages/*.php)

## Build & CI

GitHub Actions workflow (`.github/workflows/build.yml`) срабатывает на push в `master`/`develop` и использует reusable workflow из `mikopbx/.github-workflows`:
```yaml
jobs:
  build:
    uses: mikopbx/.github-workflows/.github/workflows/extension-publish.yml@master
    with:
      initial_version: "1.34"
    secrets: inherit
```

## Сборка JavaScript

Исходники: `public/assets/js/src/` — **редактировать только файлы в `src/`**.
Скомпилированные файлы в `public/assets/js/*.js` — автогенерируемые, не редактировать вручную.

Сборка через Babel (пресет `airbnb`, source maps включены).
PHPStorm File Watcher: https://docs.mikopbx.com/mikopbx-development/prepare-ide-tools/mac#phpstorm-setup-babel

Ручная сборка:
```bash
cd /Users/apor/Developement/MikoPBX/MikoPBXUtils && \
cp babel.config.json babel.config.json.bak && \
echo '{"presets":[["@babel/preset-env",{"targets":{"chrome":50,"ie":11,"firefox":45}}]]}' > babel.config.json && \
./node_modules/.bin/babel \
  /Volumes/DevDisk/apor/Developement/MikoPBX/Extensions/ModuleAutoDialer/public/assets/js/src/module-auto-dialer-index.js \
  --out-dir /Volumes/DevDisk/apor/Developement/MikoPBX/Extensions/ModuleAutoDialer/public/assets/js/ \
  --source-maps && \
mv babel.config.json.bak babel.config.json
```

## Архитектура

### Основной поток данных обзвона

1. Внешняя система (CRM/1C) создаёт задачу через REST API (`POST /pbxcore/api/module-dialer/v1/task`)
2. `ApiController` вызывает `ConnectorDB::invoke('addTask', ...)` — синхронный IPC через Beanstalk
3. `ConnectorDB` (фоновый воркер) сохраняет задачу и номера в БД
4. `WorkerDialer` получает срез задач через `ConnectorDB::invoke('getSliceTask')`, создаёт call-файлы Asterisk
5. Asterisk выполняет вызовы, AGI-скрипты (`agi-bin/`) фиксируют результаты
6. `WorkerAMI` слушает AMI-события и обновляет статусы через `ConnectorDB::invoke('saveStateData', ...)`

### Режим callback

При `isCallback=1` порядок вызова меняется: сначала звонок на внутренний номер сотрудника, затем — на внешний номер клиента. Генерируется через контекст `dialer-out-originate-in-callback`.

### Повторные попытки дозвона

- `maxAttempt` — количество попыток
- `tryInterval` — интервал между попытками (секунды)
- `attemptUntilSignal=1` — повторять до внешнего сигнала (`task-signal-close`), игнорируя статус дозвона
- При каждой повторной попытке создаётся новая запись в `TaskResults` с инкрементом `attemptNumber`

### Группировка номеров по клиенту

При указании `clientId` в номерах задачи: если дозвонились на один номер клиента, остальные номера того же клиента автоматически закрываются с результатом `SUCCESS_ANOTHER_PHONE`.

Дополнительно реализована защита от одновременного обзвона нескольких номеров одного клиента: `ConnectorDB::getSliceTask()` проверяет наличие активных (незакрытых) вызовов по `clientId` и подставляет номер другого клиента через приватный метод `findAvailablePhone()`. Если все доступные номера принадлежат занятым клиентам, задача пропускается до следующего цикла.

### Ключевые компоненты

| Компонент | Путь | Назначение |
|---|---|---|
| REST API | `Lib/RestAPI/Controllers/ApiController.php` | Все внешние эндпоинты модуля |
| IPC-диспетчер | `bin/ConnectorDB.php` | Мост между веб-слоем и воркерами, все операции с БД |
| Dialer-воркер | `bin/WorkerDialer.php` | Основной цикл обзвона, создание call-файлов, callback |
| AMI-воркер | `bin/WorkerAMI.php` | Обработка событий Asterisk в реальном времени |
| Конфигурация | `Lib/AutoDialerConf.php` | Генерация Asterisk-диалплана (контексты `dialer-out-originate-in`, `dialer-polling`, `dialer-out-originate-in-callback`) |
| Утилиты | `Lib/AutoDialerMain.php` | Настройки PBX, список extension'ов, Redis-кеш, конвертация аудио |
| TTS Yandex | `Lib/YandexSynthesize.php` | Синтез речи через Yandex Cloud API |
| TTS RHVoice | `Lib/RHVoiceSynthesize.php` | Синтез речи через локальный сервер RHVoice |
| AGI-скрипты | `agi-bin/saveResult.php`, `change-state-task.php`, `gen-update-media-file.php` | Исполняются Asterisk'ом в контексте вызова |
| Cron-watchdog | `bin/safe.php` | Проверка и перезапуск воркеров из cron; убивает дубликаты процессов |

### Межпроцессное взаимодействие (IPC)

Весь IPC идёт через `ConnectorDB::invoke(funcName, args)` — статический метод, который:
- Отправляет JSON-сообщение в Beanstalk-очередь `ConnectorDB`
- Воркер `ConnectorDB::onEvents()` десериализует и вызывает метод по имени
- Если метод вернул `PBXApiResult`, `onEvents()` преобразует его через `getResult()`; массивы проходят без изменений
- Нормализованный массив сериализуется и возвращается через `$tube->reply()`

Флаг `$retVal = false` позволяет fire-and-forget без ожидания ответа.

### Модели данных (Models/)

- `Tasks` — задачи обзвона (states: `STATE_OPEN=0`, `STATE_CLOSE=1`, `STATE_PAUSE=2`; retry: `maxAttempt`, `tryInterval`, `attemptUntilSignal`; рабочее время: `timeStart`, `timeEnd`; callback: `isCallback`)
- `TaskResults` — результаты по каждому номеру в задаче (`clientId` для группировки, `attemptNumber` для попыток, `timeCallAllow` для абсолютной отсрочки, nullable `timeOffsetMinutes` для смещения получателя)
- `Polling` / `Question` / `QuestionActions` — IVR-опросы с деревом вопросов
- `PolingResults` — результаты опросов
- `AudioFiles` — метаданные загруженных аудиофайлов
- `Clients` / `ClientsPhones` / `ClientsProperties` — справочник клиентов с телефонами и произвольными свойствами
- `DialerExtensions` — настройки внутренних номеров для опросов (web-интерфейс)
- `ModuleAutoDialer` — глобальные настройки модуля (`defDialPrefix`, `yandexApiKey`, `ttsService`, `callbackAlertText`)

### Рабочее время и часовые пояса

- Внешний API принимает `numbers[].TimeOffset` в часах; внутри хранится `TaskResults.timeOffsetMinutes`
- `NULL` в `timeOffsetMinutes` означает локальное время АТС, `0` означает UTC
- `Lib/DialingWindow.php` нормализует смещение, вычисляет минуту суток и проверяет обычные/ночные окна
- `Lib/DialingCandidateSelector.php` выбирает первый номер, удовлетворяющий `timeCallAllow`, рабочему окну и блокировке занятого `clientId`
- `ConnectorDB::getSliceTask()` загружает ожидающих кандидатов в порядке `timeCallAllow, id`; номер вне своего окна не блокирует следующий
- Окна включают обе границы; `timeStart > timeEnd` означает переход через полночь
- Ограничение применяется только при создании нового звонка и не завершает уже активный вызов

### TTS-сервисы (`ModuleAutoDialer::ttsService`)

- `YANDEX` — Yandex Cloud SpeechKit (`Lib/YandexSynthesize.php`), требует `yandexApiKey`
- `RH_VOICE` — локальный RHVoice (`Lib/RHVoiceSynthesize.php`), порт 8081, кеширование по хешу текст+голос+rate

### STT Yandex (`Lib/YandexRecognize.php`)
- Формат: LPCM (sox WAV→raw PCM 8kHz 16bit mono), эндпоинт `stt.api.cloud.yandex.net/speech/v1/stt:recognize`
- Авторизация: `Api-Key` + обязательный `folderId` в query-параметрах
- Роль `ai.speechkit-stt.user` (не `yc.ai.speechkitStt.execute`) для классического API
- Настройки: `ModuleAutoDialer.yandexApiKey` + `ModuleAutoDialer.yandexFolderId`

### Вопрос-подтверждение STT (`Question.type = 'confirmation'`)
- `QuestionActions.needRecognize` = '1' + `recognizeLabel` — на уровне press-действия `playback_record`
- `PolingResults.recognizedText` / `recognizeLabel` — результат распознавания
- AGI `confirm-stt.php` — собирает STT-результаты по linkedId, генерирует TTS, озвучивает для подтверждения
- Гарантия порядка: Beanstalk FIFO — sync `getRecognizedResults` выполнится после всех `savePolingResult`

### Параметры клиента в шаблонах опросов
- `<NAME>` — имя клиента из `Clients.name` (добавляется в `findClientByPhone()`)
- `<KEY>` — любой ключ из `ClientsProperties` (ADDRES, ACCOUNT_1 и т.д.)
- Свойства из `ClientsProperties` имеют приоритет над `Clients.name` при дублировании ключа `NAME`

### Result-коды (`ConnectorDB::RESULT_*`)

`SUCCESS`, `SUCCESS_CLIENT_H`, `SUCCESS_USER_H`, `SUCCESS_POLLING`, `SUCCESS_ANOTHER_PHONE`, `SUCCESS_EXTERNAL_SIGNAL`, `FAIL`, `FAIL_CLIENT_H_BEFORE_ANSWER`, `FAIL_USER_NO_ANSWER`, `FAIL_USER_BUSY`, `FAIL_ROUTE`, `FAIL_PROVIDER`, `FAIL_POLLING`

### Внешние зависимости

- **Asterisk PBX** — AGI/AMI интеграция
- **Beanstalk** — очередь сообщений для IPC между процессами
- **Redis** — кеширование (префикс `auto_dialer_`, TTL 86400с)
- **Yandex Cloud TTS** или **RHVoice** — синтез речи для вопросов опроса
- **sox/lame** — конвертация аудиофайлов
- **PHPSpreadsheet** — парсинг XLS/XLSX файлов со списками номеров

### REST API эндпоинты

Базовый путь: `/pbxcore/api/module-dialer/v1/`

**Задачи:**
- `POST /task` — создать задачу обзвона
- `GET /task`, `GET /task/{id}` — список задач / детали задачи
- `PUT /task/{id}` — частично изменить задачу; отсутствующие поля сохраняются, включая `crmId`
- `DELETE /task/{id}` — удалить задачу
- `POST /task-signal-close` — остановить обзвон по номеру телефона

**Клиенты:**
- `POST /client` — создать/обновить клиентов (массив)
- `DELETE /client/{crmId}` — удалить клиента
- `GET /client-by-phone/{phone}` — найти клиента по номеру

**Опросы:**
- `POST /polling` — создать/обновить опрос
- `GET /polling`, `GET /polling/{id}` — список / детали опроса
- `DELETE /polling/{id}` — удалить опрос

**Результаты:**
- `GET /results/{changeTime}` — результаты обзвона (инкрементально)
- `GET /polling-results/{changeTime}` — результаты опросов

**Аудио:**
- `POST /audio` — загрузить аудиофайл
- `GET /audio` — список аудиофайлов
- `DELETE /audio/{name}` — удалить аудиофайл

**Утилиты:**
- `POST /upload-xls` — загрузить XLS/XLSX со списком номеров

### Фронтенд

- Semantic UI + jQuery + DataTables
- Volt-шаблоны: `App/Views/`
- Формы: `App/Forms/ModuleAutoDialerForm.php`

### Интеграция с 1С

Директория `1c/` содержит конфигурацию для 1C:Enterprise — каталоги, документы, регистры сведений, общие модули для синхронизации задач обзвона с CRM.

### Dialplan-контексты Asterisk

Генерируются в `AutoDialerConf::extensionGenContexts()`:
- `dialer-out-originate-in` — основной контекст обзвона
- `dialer-out-originate-outgoing` — инициация вызова
- `dialer-out-originate-in-callback` — callback-режим
- `dialer-out-originate-check-inner-peer-state` — проверка состояния внутреннего номера
- `dialer-out-originate-in-hangup-handler` — обработка завершения вызова
- `dialer-polling` — контекст IVR-опросов
- `dialer-polling-{pollId}-{questionId}` — контексты для каждого вопроса

### AGI-события (хуки в диалплане)

`EVENT_START_DIAL_IN`, `EVENT_END_DIAL_IN`, `EVENT_AFTER_DIAL_OUT`, `EVENT_FAIL_ORIGINATE`, `EVENT_END_CALL`, `EVENT_POLLING`, `EVENT_POLLING_END`

### Защита от зависших вызовов

**Проблема**: если AGI/AMI событие не дошло до модуля, запись `TaskResults` остаётся незакрытой (`closeTime=0`), блокируя слот `maxCountChannels` навсегда. Известные причины потери событий:
- Вызов заблокирован кастомным контекстом (например, `all-outgoing-custom` с лимитом каналов) — `bridgePeer` пустой, поток в `dialer-out-originate-in` идёт прямо в `Hangup()`, минуя все AGI-хуки
- Asterisk обработал call-файл, но originate завершился до срабатывания AGI

**Решения**:
1. **Диалплан**: AGI `EVENT_FAIL_ORIGINATE` вызывается перед `Hangup()` когда `bridgePeer` пустой — ловит случаи блокировки вызова кастомными контекстами
2. **`EVENT_END_CALL` fallback**: если ни одно условие в обработчике `EVENT_END_CALL` не установило `result` — ставится `FAIL` (предотвращает `result=null` при неожиданных комбинациях состояний)
3. **Автоочистка** (`ConnectorDB::resetStuckCallFiles()`): вызывается перед каждым `getSliceTask()`. Сбрасывает записи в переходных состояниях, зависшие дольше таймаута:
   - `CreateCallFile` → 120с (Asterisk забирает call-файл за секунды)
   - `endCall` → 300с (вызов уже завершён, но не закрыт — после fallback-фикса не должно случаться)
   - Активные состояния (`afterDialOut`, `startDial`, `EVENT_POLLING`) — **не трогаются**, вызов может длиться долго

### Asterisk-переменные канала

- `__M_*` (наследуемые через bridge): `M_TASK_ID`, `M_OUT_NUMBER`, `M_EXTEN_TYPE`, `M_PARAMS`
- `pt1c_*` — проприетарные переменные MikoPBX

## Соглашения

### Именование

| Категория | Паттерн | Пример |
|---|---|---|
| PHP-классы | PascalCase | `TaskResults`, `YandexSynthesize` |
| PHP-методы | camelCase | `saveStateData()`, `getSliceTask()` |
| Таблицы БД | Префикс `m_` + PascalCase | `m_Tasks`, `m_TaskResults`, `m_Polling` |
| Колонки БД | camelCase | `phoneId`, `clientId`, `changeTime` |
| JS-объекты | PascalCase (глобальный модуль) | `const ModuleAutoDialer = { ... }` |
| HTML ID | kebab-case | `polling-table`, `module-auto-dialer-form` |
| Ключи переводов | `mod_AutoDialer_<Feature>` | `mod_AutoDialer_defDialPrefix` |
| Хлебные крошки | `Breadcrumb<ModuleName>` | `BreadcrumbModuleAutoDialerModifyPolling` |

### Общие правила

- Комментарии в коде на русском языке
- Локализация: 28+ языков в `Messages/*.php`
- Логирование: `Lib/Logger.php` с ротацией в `$logPath/ModuleAutoDialer/`
- AGI-скрипты подключают `bin/Globals.php` для инициализации DI-контейнера
- Модуль лицензируется через MIKO (product_id: 128, feature_id: 52)

## Паттерны кода

### PHP — типизация и совместимость
- Typed properties (PHP 7.4): `private Logger $logger;`
- Return types всегда указаны: `:void`, `:string`, `:bool`
- `declare(strict_types=1)` — не используется повсеместно
- **PHP 7.4+ совместимость обязательна** — не использовать: `str_starts_with`/`str_ends_with`/`str_contains` (PHP 8.0), `match` (8.0), `?->` nullsafe (8.0), `enum` (8.1), `readonly` (8.1), `array_is_list` (8.1). Альтернативы: `strncmp($s, $prefix, strlen($prefix)) === 0` вместо `str_starts_with`

### Модели — аннотации Phalcon
Схема БД определяется через PHPDoc-аннотации:
```php
/**
 * @Primary
 * @Identity
 * @Column(type="integer", nullable=false)
 */
public $id;

/**
 * @Column(type="string", nullable=true, default="")
 */
public $name;
```
Типы: `integer`, `string`, `text`. Индексы через `@Indexes`. Таблица задаётся в `initialize()`:
```php
$this->setSource('m_Tasks');
```
Связи с core-моделями MikoPBX — через `getDynamicRelations()`.

### Web-контроллеры (`App/Controllers/`)
- Наследуют `BaseController`
- Действия: `indexAction()`, `modifyXxxAction()`, `saveAction()`, `deleteAction()`
- Подключение ассетов: `$this->assets->collection('footerJS')->addJs(...)`
- Передача данных в шаблон: `$this->view->form = new ModuleAutoDialerForm($entity)`
- Выбор шаблона: `$this->view->pick("{$this->moduleDir}/App/Views/index")`

### REST API контроллер (`Lib/RestAPI/Controllers/`)
- Наследует `ModulesControllerBase` (другой базовый класс)
- Парсинг JSON: приватный метод `getJsonBody()` — читает raw body, удаляет UTF-8 BOM (`\xEF\xBB\xBF`, часто приходит из 1С), затем `json_decode`. Возвращает `?array` (null при ошибке парсинга). Используется во всех POST/PUT эндпоинтах вместо `getJsonRawBody()`.
- Ответ: `echoResponse($result)` → `json_encode()` с `JSON_PRETTY_PRINT`
- Для больших данных — файловая передача через `ConnectorDB::saveInTmpFile()`

### Маршрутизация
- Web UI: convention-based — `ModuleAutoDialerController` → `/admin-cabinet/module-auto-dialer/`
- REST API: маршруты регистрируются в `AutoDialerConf::getPBXCoreRESTAdditionalRoutes()`

### Volt-шаблоны (`App/Views/`)
- Перевод: `{{ t._('mod_AutoDialer_TabPolling') }}`
- Рендер формы: `{{ form.render('fieldName') }}`
- Подключение partial: `{{ partial("partials/submitbutton", ['indexurl':'...']) }}`
- Циклы: `{% for item in items %} ... {% else %} ... {% endfor %}`
- CSS-фреймворк — Semantic UI (не Bootstrap)

### Phalcon-формы (`App/Forms/`)
- Наследуют `Form`, элементы добавляются в `initialize($entity, $options)`
- Типы: `Text`, `TextArea`, `Select`, `Hidden`
- CSS-классы Semantic UI указываются в параметрах элемента

### JavaScript (`public/assets/js/src/`)
- Глобальный объект-модуль: `const ModuleAutoDialer = { ... }`
- Кешированные jQuery-селекторы: `$formObj`, `$checkBoxes`, `$dropDowns`
- Глобальные зависимости: `globalRootUrl`, `globalTranslate`, `Form`, `Config`
- AJAX: `$.ajax()` и `$.api()` (обёртка Semantic UI)
- Инициализация Semantic UI: `.checkbox()`, `.dropdown()`, `.accordion()`, `.tab()`
- DataTables для таблиц с серверной пагинацией
- Обработка форм: `Form.initialize()` с коллбеками `cbBeforeSendForm`, `cbAfterSendForm`

### Переводы (`Messages/`)
- Файл на каждый язык: `en.php`, `ru.php`, `ja.php`, ...
- Формат: `return ['key' => 'value', ...]`
- Использование в PHP: `$this->translation->_('key')`
- Использование в Volt: `{{ t._('key') }}`

### Обработка ошибок
- Контроллеры: `$this->flash->error(msg)` + `$this->view->success = false`
- REST API: `getJsonBody()` возвращает `null` при невалидном JSON → контроллер отвечает `'Invalid JSON request body'`
- Воркеры: тихий fail + запись в `Logger`
- БД-транзакции: `$this->db->begin()` / `commit()` / `rollback()`

### Совместимость версий Phalcon
`Lib/MikoPBXVersion.php` — детекция Phalcon 4 vs 5 по версии PBX (>= 2024.2.3 = Phalcon 5).
Статические методы возвращают правильные классы: `getLoggerClass()`, `getValidationClass()`, `getDefaultDi()`.

### Установка модуля (`Setup/PbxExtensionSetup.php`)
- Наследует `PbxExtensionSetupBase`
- `installDB()`: создание таблиц из аннотаций → регистрация модуля → добавление в меню
- `unInstallDB($keepSettings)`: soft-delete с сохранением данных
- SQL-миграции отсутствуют — схема генерируется автоматически из моделей

## Файловые пути (на PBX-системе)

- Аудиофайлы: `{modulesDir}/ModuleAutoDialer/db/audio/`
- TTS-кеш: `{moduleDir}/db/tts/` (файлы по MD5-хешу текст+голос)
- Call-файлы: `{asterisk.astspooldir}/outgoing/` (формат: `dialer-{taskId}-{src}-{dst}.call`)
- Temp-файлы: `{core.tempDir}/ModuleAutoDialer/` (fallback `/tmp/`)
- Логи: `{logDir}/ModuleAutoDialer/{ClassName}.log` (ротация 10MB, 5 файлов)

## Тестирование

Тесты в `tests/` — PHP-скрипты без PHPUnit, со своим минимальным фреймворком. Запускаются на PBX-сервере (serber@boffart.miko.ru).

Подробная документация: `tests/README.md`. Примеры curl-запросов для ручного тестирования: `tests/curl-examples.md`.

### Структура тестов

```
tests/
  lib/
    TestRunner.php    — assert-функции (assertEq, assertTrue, assertFalse, assertContains, assertNotEmpty) и TestRunner (try/catch обёртка)
    ApiClient.php     — HTTP-клиент REST API (curl), включая pollResults() для ожидания результатов
    PjsuaManager.php  — управление SIP-клиентом pjsua через proc_open (E2E)
    AmiHelper.php     — AMI-клиент для DTMF-инъекции и поиска каналов (E2E)
  unit/
    test-data-integrity.php — целостность данных в БД (ORM-уровень)
    test-api-tasks.php      — CRUD, частичный PUT и upsert задач через REST API
    test-api-clients.php    — CRUD клиентов через REST API
    test-dialing-window.php — чистая логика UTC-смещений и рабочих окон
    test-dialing-candidate-selector.php — выбор номера, timeCallAllow и busy clientId
    test-time-offset-storage-contract.php — модель и пути сохранения смещения
    test-time-offset-api.php — хранение TimeOffset через REST API
    test-time-offset-selection.php — выбор допустимого номера через Worker
  e2e/
    test-basic-call.php      — базовый исходящий звонок
    test-callback.php        — callback-режим (isCallback=1)
    test-retry.php           — повторные попытки дозвона
    test-client-grouping.php — группировка номеров по clientId
    test-polling-ivr.php     — IVR-опрос с DTMF через AMI PlayDTMF
    test-working-hours.php   — рабочее время (timeStart/timeEnd)
  e2e-config.php   — SIP-креденшлы, номера, таймауты
  run-all.php      — запуск всех тестов (каждый в отдельном PHP-процессе)
```

### Запуск тестов на сервере

```bash
# Все тесты (unit + e2e)
ssh serber@boffart.miko.ru "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/run-all.php 2>&1 | grep -v '^php.backend'"

# Только unit/integration
ssh serber@boffart.miko.ru "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/run-all.php unit 2>&1 | grep -v '^php.backend'"

# Только E2E
ssh serber@boffart.miko.ru "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/run-all.php e2e 2>&1 | grep -v '^php.backend'"

# Отдельный тест
ssh serber@boffart.miko.ru "php -f /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/unit/test-api-tasks.php 2>&1 | grep -v '^php.backend'"
```

### Тестовый фреймворк

**TestRunner** (`tests/lib/TestRunner.php`): оборачивает каждый тест в `try/catch` (критично — необработанное исключение отключает модуль). Ведёт счётчики passed/failed на уровне тестов и assertions. Возвращает exit code 0/1.

**ApiClient** (`tests/lib/ApiClient.php`): HTTP-клиент на curl для всех REST API эндпоинтов модуля. Метод `pollResults($taskId, $changeTime, $maxWait, $interval)` выполняет polling до появления результатов.

**PjsuaManager** (`tests/lib/PjsuaManager.php`): управляет процессом pjsua через `proc_open()` — запуск, SIP-регистрация, ожидание входящего вызова, отправка DTMF, hangup, graceful stop.

**AmiHelper** (`tests/lib/AmiHelper.php`): AMI-клиент — подключение, поиск каналов по паттерну, инъекция DTMF через `PlayDTMF` (Receive=1), qualify endpoint.

### Паттерн unit-тестов

Unit/integration тесты используют `ApiClient` для HTTP-запросов к REST API. Для прямого доступа к БД: `$db = (new TaskResults())->getReadConnection()` (НЕ `$di->getShared('db')`). Raw SQL использует имена таблиц с префиксом `m_`.

### Паттерн E2E тестов

1. Создать задачу через `ApiClient::createTask()` с `state=0`
2. Запустить PJSUA (SIP-клиент и/или оператор) через `PjsuaManager::start()`
3. Ожидать входящий вызов (`waitForIncomingCall`)
4. При необходимости отправить DTMF через `AmiHelper::waitAndSendDtmf()`
5. Ожидать результат через `ApiClient::pollResults()`
6. Проверить assertions, cleanup задачи

### Конфигурация E2E: `tests/e2e-config.php`

- SIP-клиент регистрируется как транк `SIP-1692280724` (порт 5080) — получает исходящие
- SIP-оператор — внутренний номер `228` (порт 5082)
- Маршрут: префикс `999` направлен на транк
- PJSUA бинарники: `tests/bin/pjsua-linux-x86_64` и `tests/bin/pjsua-linux-aarch64` (в `.gitignore`)

### Правила написания тестов

- Unit-тесты: `require_once __DIR__ . '/../lib/TestRunner.php'` + `require_once __DIR__ . '/../lib/ApiClient.php'`
- E2E тесты дополнительно: `require_once __DIR__ . '/../lib/PjsuaManager.php'` и/или `AmiHelper.php`
- Конфиг: `$config = require __DIR__ . '/../e2e-config.php'`
- Тесты прямого доступа к БД: `require_once 'Globals.php'` (первая строка — инициализирует Phalcon DI)
- Если необработанное исключение вылетает из скрипта, MikoPBX автоматически **отключает модуль** — поэтому всегда использовать `TestRunner::run()` с try/catch

### Однократное обновление структуры БД
```bash
ssh serber@boffart.miko.ru "php -r \"require_once 'Globals.php'; \\\$d = new \MikoPBX\Core\System\Upgrade\UpdateDatabase(); \\\$d->updateDatabaseStructure();\""
```

### Развёртывание на PBX-сервер
Модуль установлен в `/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/`.
```bash
# Один файл
scp <файл> serber@boffart.miko.ru:/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/<путь>

# Все тесты
scp -r tests/ serber@boffart.miko.ru:/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/tests/
```
