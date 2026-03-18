# ModuleAutoDialer — модуль автообзвона для MikoPBX

*Read this in other languages: [English](README.md), [Русский](readme.ru.md).*

Модуль автоматического обзвона и IVR-опросов для MikoPBX. Позволяет создавать кампании массового обзвона с переводом на внутренний номер сотрудника или запуском интерактивного голосового опроса.

- Документация по модулям MikoPBX: [docs.mikopbx.com](https://docs.mikopbx.com/mikopbx-development/)
- Telegram-канал для разработчиков: [@mikopbx_dev](https://t.me/joinchat/AAPn5xSqZIpQnNnCAa3bBw)

---

## REST API

Базовый URL: `/pbxcore/api/module-dialer/v1`

Все запросы и ответы используют формат JSON (`Content-Type: application/json`).

### Авторизация

При обращении к API не с localhost необходимо предварительно авторизоваться:

```bash
curl 'http://<PBX_ADDRESS>/admin-cabinet/session/start' \
  -X POST --cookie-jar auth-cookies.txt \
  -H 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' \
  -H 'X-Requested-With: XMLHttpRequest' \
  --data 'login=admin&password=<PASSWORD>'
```

Далее все запросы к API выполнять с `--cookie auth-cookies.txt`.

### Формат ответа

Все эндпоинты возвращают JSON со структурой:

```json
{
  "result": true,
  "data": { ... },
  "messages": []
}
```

- `result` — `true` при успехе, `false` при ошибке
- `data` — данные ответа
- `messages` — массив сообщений об ошибках (при `result: false`)

---

## Задачи обзвона (Tasks)

### Создание задачи

**POST** `/pbxcore/api/module-dialer/v1/task`

Создаёт новую задачу обзвона. Если задача с указанным `crmId` уже существует — обновляет её.

**Тело запроса:**

| Поле | Тип | Обязательное | Описание |
|------|-----|:---:|----------|
| `crmId` | string | нет | Внешний идентификатор задачи (CRM). Если пустой — назначается автоматически |
| `name` | string | нет | Название задачи |
| `state` | integer | нет | Состояние: `0` — активна, `1` — закрыта, `2` — пауза |
| `innerNum` | string | да | Внутренний номер сотрудника или ID опроса |
| `innerNumType` | string | нет | Тип назначения: `exten` (по умолчанию) — перевод на сотрудника, `polling` — запуск опроса |
| `maxCountChannels` | integer | нет | Максимальное количество одновременных каналов (по умолчанию `1`) |
| `dialPrefix` | string | нет | Префикс набора номера (для выбора маршрута). Если пустой — используется префикс из настроек модуля |
| `maxAttempt` | integer | нет | Максимальное количество попыток дозвона (по умолчанию `1`) |
| `tryInterval` | integer | нет | Интервал между попытками дозвона в секундах (по умолчанию `60`) |
| `attemptUntilSignal` | integer | нет | `1` — повторять звонки до получения внешнего сигнала (через `task-signal-close`), игнорируя статус дозвона. `0` — обычный режим (по умолчанию) |
| `timeStart` | integer | нет | Начало рабочего времени — количество минут от 00:00 (например, `540` = 09:00). По умолчанию `0` |
| `timeEnd` | integer | нет | Конец рабочего времени — количество минут от 00:00 (например, `1080` = 18:00). По умолчанию `1440` |
| `isCallback` | integer | нет | `1` — режим callback (сначала звонок сотруднику, затем клиенту). `0` — обычный режим (по умолчанию) |
| `numbers` | array | да | Список номеров для обзвона (см. форматы ниже) |

**Формат `numbers` — простой (массив строк):**

```json
{
  "numbers": ["79001234567", "79001234568"]
}
```

**Формат `numbers` — расширенный (массив объектов):**

| Поле | Тип | Описание |
|------|-----|----------|
| `number` | string | Номер телефона |
| `clientId` | string | Идентификатор клиента. Номера с одинаковым `clientId` группируются — при успешном дозвоне на один номер остальные номера этого клиента автоматически закрываются |
| `timeCallAllow` | string | Время, начиная с которого разрешён звонок. Формат: `DD.MM.YYYY HH:mm:ss` (учитывается часовой пояс PBX) |
| `params` | object | Произвольные параметры, доступные в вызове (например `{"speach": "Ваша задолженность 1000 рублей"}`) |

```json
{
  "numbers": [
    {
      "number": "79001234567",
      "timeCallAllow": "15.03.2025 09:00:00",
      "params": {"speach": "Ваша задолженность 1000 рублей"}
    },
    {
      "number": "79001234568"
    }
  ]
}
```

**Пример — задача с переводом на сотрудника:**

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{
    "crmId": "80001",
    "name": "Обзвон клиентов",
    "state": 0,
    "innerNum": "201",
    "innerNumType": "exten",
    "maxCountChannels": 3,
    "dialPrefix": "9",
    "numbers": ["79001234567", "79001234568"]
  }' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task
```

**Пример — задача с IVR-опросом:**

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{
    "crmId": "90001",
    "name": "Опрос по доставке",
    "state": 0,
    "innerNum": "7",
    "innerNumType": "polling",
    "maxCountChannels": 5,
    "dialPrefix": "9",
    "numbers": [
      {"number": "79001234567", "params": {"speach": "Ваш заказ 12345"}},
      {"number": "79001234568"}
    ]
  }' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "id": 1000000001,
    "crmId": "80001",
    "name": "Обзвон клиентов",
    "innerNum": "201",
    "innerNumType": "exten",
    "maxCountChannels": 3,
    "state": 0,
    "dialPrefix": "9"
  },
  "messages": []
}
```

---

### Получение списка задач

**GET** `/pbxcore/api/module-dialer/v1/task`

**Query-параметры:**

| Параметр | Тип | Описание |
|----------|-----|----------|
| `state` | string | Фильтр по состоянию: `0`, `1`, `2` |
| `limit` | integer | Максимальное количество записей |
| `offset` | integer | Вернуть задачи с `id` больше указанного (пагинация по ID) |

**Пример:**

```bash
# Все активные задачи
curl 'http://127.0.0.1/pbxcore/api/module-dialer/v1/task?state=0'

# Первые 10 задач
curl 'http://127.0.0.1/pbxcore/api/module-dialer/v1/task?limit=10'

# Задачи с id > 1000000005
curl 'http://127.0.0.1/pbxcore/api/module-dialer/v1/task?offset=1000000005&limit=10'
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "results": [
      {
        "id": 1000000001,
        "crmId": "80001",
        "name": "Обзвон клиентов",
        "innerNum": "201",
        "innerNumType": "exten",
        "maxCountChannels": 3,
        "state": 0,
        "dialPrefix": "9"
      }
    ]
  },
  "messages": []
}
```

---

### Получение задачи по ID

**GET** `/pbxcore/api/module-dialer/v1/task/{id}`

Возвращает данные задачи вместе с результатами обзвона и результатами опросов.

```bash
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/task/1000000001
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "id": 1000000001,
    "crmId": "80001",
    "name": "Обзвон клиентов",
    "innerNum": "201",
    "innerNumType": "exten",
    "maxCountChannels": 3,
    "state": 0,
    "dialPrefix": "9",
    "results": [
      {
        "id": 1,
        "taskId": 1000000001,
        "phone": "79001234567",
        "phoneId": "9001234567",
        "state": "endCall",
        "result": "SUCCESS",
        "outDialState": "ANSWER",
        "inDialState": "ANSWER",
        "verboseCallId": "PJSIP/...",
        "linkedId": "...",
        "callFile": "...",
        "cause": "location",
        "params": "",
        "changeTime": 1690194700.1234,
        "countTry": null,
        "timeCallAllow": 0,
        "closeTime": 1690194750.5678
      }
    ],
    "resultsPoling": [
      {
        "id": 1,
        "taskId": 1000000001,
        "pollingId": "7",
        "questionCrmId": "q1",
        "phoneId": "9001234567",
        "phone": "79001234567",
        "exten": "1",
        "result": "confirmed",
        "changeTime": 1690194720.1234
      }
    ]
  },
  "messages": []
}
```

---

### Изменение задачи

**PUT** `/pbxcore/api/module-dialer/v1/task/{id}`

Обновляет поля задачи. Можно передать любое подмножество полей.

```bash
# Поставить задачу на паузу
curl -X PUT \
  -H 'Content-Type: application/json' \
  -d '{"state": 2}' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task/1000000001

# Возобновить задачу
curl -X PUT \
  -H 'Content-Type: application/json' \
  -d '{"state": 0}' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task/1000000001

# Изменить количество каналов
curl -X PUT \
  -H 'Content-Type: application/json' \
  -d '{"maxCountChannels": 10}' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task/1000000001
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "id": 1000000001,
    "crmId": "80001",
    "name": "Обзвон клиентов",
    "innerNum": "201",
    "innerNumType": "exten",
    "maxCountChannels": 10,
    "state": 0,
    "dialPrefix": "9"
  },
  "messages": []
}
```

**Ошибка — задача не найдена:**

```json
{
  "result": false,
  "data": {"error": "TaskNotFound"},
  "messages": []
}
```

---

### Удаление задачи

**DELETE** `/pbxcore/api/module-dialer/v1/task/{id}`

Удаляет задачу и все связанные результаты обзвона.

```bash
curl -X DELETE http://127.0.0.1/pbxcore/api/module-dialer/v1/task/1000000001
```

**Ответ:**

```json
{
  "result": true,
  "data": [],
  "messages": []
}
```

---

### Остановка обзвона по номеру телефона

**POST** `/pbxcore/api/module-dialer/v1/task-signal-close`

Принудительно завершает обзвон для указанного номера телефона. Все незавершённые записи для этого номера (и всех номеров того же клиента, если задан `clientId`) получают результат `SUCCESS_EXTERNAL_SIGNAL`.

**Тело запроса:**

| Поле | Тип | Обязательное | Описание |
|------|-----|:---:|----------|
| `phone` | string | да | Номер телефона |
| `taskId` | string | нет | ID задачи. Если пустой — останавливает обзвон по этому номеру во всех задачах |

```bash
# Остановить обзвон конкретного номера в конкретной задаче
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"phone": "79001234567", "taskId": "1000000001"}' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task-signal-close

# Остановить обзвон номера во всех задачах
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{"phone": "79001234567"}' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task-signal-close
```

---

## Клиенты (Clients)

Справочник клиентов позволяет хранить данные о клиентах с несколькими номерами телефонов и произвольными свойствами. Данные клиентов могут использоваться при обзвоне (привязка через `clientId` в номерах задачи).

### Создание / обновление клиентов

**POST** `/pbxcore/api/module-dialer/v1/client`

Принимает массив клиентов. Если клиент с указанным `crmId` уже существует — обновляет его (телефоны и свойства пересоздаются).

**Тело запроса (массив):**

| Поле | Тип | Обязательное | Описание |
|------|-----|:---:|----------|
| `crmId` | string | да | Внешний идентификатор клиента |
| `name` | string | нет | Имя клиента |
| `phones` | array | да | Массив номеров телефонов (строки) |
| `properties` | array | нет | Массив свойств `[{"key": "...", "value": "..."}]` |

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '[
    {
      "crmId": "000000000001",
      "name": "Петров Иван Степанович",
      "phones": ["74952293042", "79052232222"],
      "properties": [
        {"key": "ADDRES", "value": "Москва, Георгиевский пр-кт д. 1701"},
        {"key": "ACCOUNT_1", "value": "10000123"}
      ]
    }
  ]' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/client
```

**Ответ:**

```json
{
  "result": true,
  "data": [
    {
      "id": 1,
      "name": "Петров Иван Степанович",
      "crmId": "000000000001",
      "phones": [
        {"id": 1, "phoneId": "4952293042", "phone": "74952293042", "clientId": "1"},
        {"id": 2, "phoneId": "9052232222", "phone": "79052232222", "clientId": "1"}
      ],
      "properties": [
        {"id": 1, "key": "NAME", "value": "Петров Иван Степанович", "clientId": "1"},
        {"id": 2, "key": "ADDRES", "value": "Москва, Георгиевский пр-кт д. 1701", "clientId": "1"},
        {"id": 3, "key": "ACCOUNT_1", "value": "10000123", "clientId": "1"}
      ]
    }
  ],
  "messages": []
}
```

> Свойство `NAME` добавляется автоматически из поля `name`, если не передано явно в `properties`.

---

### Удаление клиента

**DELETE** `/pbxcore/api/module-dialer/v1/client/{crmId}`

Удаляет клиента по его CRM-идентификатору вместе со всеми телефонами и свойствами.

```bash
curl -X DELETE http://127.0.0.1/pbxcore/api/module-dialer/v1/client/000000000001
```

---

### Поиск клиента по номеру телефона

**GET** `/pbxcore/api/module-dialer/v1/client-by-phone/{phone}`

Возвращает свойства клиента, которому принадлежит указанный номер.

```bash
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/client-by-phone/74952293042
```

**Ответ:**

```json
{
  "result": true,
  "data": [
    {"key": "NAME", "value": "Петров Иван Степанович"},
    {"key": "ADDRES", "value": "Москва, Георгиевский пр-кт д. 1701"},
    {"key": "ACCOUNT_1", "value": "10000123"}
  ],
  "messages": []
}
```

---

## Загрузка списка номеров из XLS/XLSX

**POST** `/pbxcore/api/module-dialer/v1/upload-xls`

Загружает файл XLS/XLSX и возвращает распарсенный список номеров. Каждая строка файла — один клиент, столбцы — номера телефонов. Содержимое файла должно быть закодировано в base64.

```bash
curl -F "file=@/path/to/phones.xlsx" \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/upload-xls
```

**Ответ:**

```json
[
  {"number": "79001234567", "clientId": "1"},
  {"number": "79001234568", "clientId": "1"},
  {"number": "79009876543", "clientId": "2"}
]
```

Результат можно использовать как значение поля `numbers` при создании задачи.

---

## Настройка Yandex Cloud (для TTS и STT)

Модуль использует Yandex SpeechKit для синтеза речи (TTS) и распознавания речи (STT).

### Настройка в веб-интерфейсе MikoPBX

На вкладке **Настройки** модуля необходимо заполнить:

- **Yandex API Key** — секретный ключ для авторизации в API
- **Yandex Cloud Folder ID (для STT)** — идентификатор каталога. Можно найти в адресной строке [консоли Yandex Cloud](https://console.yandex.cloud/), например: `https://console.yandex.cloud/folders/b1g99fhofn/...` — идентификатор `b1g99fhofn`

### Создание сервисного аккаунта и API-ключа

1. В [консоли Yandex Cloud](https://console.yandex.cloud/) откройте нужный каталог
2. Создайте **сервисный аккаунт** с ролями:
   - `ai.speechkit-tts.user` — генерация речи
   - `ai.speechkit-stt.user` — распознавание речи
3. Создайте **API-ключ** для этого сервисного аккаунта (тип: API key, для упрощённой аутентификации)
4. Сохраните ключ в поле **Yandex API Key** в настройках модуля

> Все сервисные аккаунты принадлежат каталогу, а все API-ключи — сервисному аккаунту. Идентификатор каталога и API-ключ должны относиться к одному каталогу.

---

## Опросы (Polling)

### Создание / обновление опроса

**POST** `/pbxcore/api/module-dialer/v1/polling`

Создаёт новый IVR-опрос или обновляет существующий (по `crmId`). После создания опроса используйте его `id` в поле `innerNum` при создании задачи с `innerNumType: "polling"`.

**Тело запроса:**

| Поле | Тип | Обязательное | Описание |
|------|-----|:---:|----------|
| `crmId` | string | нет | Внешний ID опроса. Если пустой — назначается автоматически |
| `name` | string | нет | Название опроса |
| `questions` | array | да | Массив вопросов |

**Структура вопроса:**

| Поле | Тип | Обязательное | Описание |
|------|-----|:---:|----------|
| `questionId` | string | нет | Идентификатор вопроса (для CRM). Если пустой — используется порядковый индекс |
| `questionText` | string | нет* | Текст вопроса для синтеза речи через Yandex SpeechKit |
| `questionFile` | string | нет* | Путь к WAV-файлу озвучки вопроса (приоритет над `questionText`) |
| `lang` | string | да | Язык синтеза речи, например `ru-RU`, `en-US` |
| `press` | array | да | Массив действий при нажатии клавиш |

> *Необходимо указать либо `questionText`, либо `questionFile`.

**Структура действия (`press`):**

| Поле | Тип | Описание |
|------|-----|----------|
| `key` | string | Клавиша DTMF (`0`–`9`, `*`, `#`) |
| `action` | string | Действие (см. таблицу ниже) |
| `value` | string | Значение действия |
| `valueOptions` | string | Тип значения: `text` — текст для синтеза, `file` — путь к аудиофайлу |
| `nextQuestion` | string | ID следующего вопроса (пустая строка = завершение опроса) |

**Типы действий (`action`):**

| Значение | Описание | `value` |
|----------|----------|---------|
| `answer` | Зафиксировать ответ | Значение ответа, сохраняемое в результатах |
| `dial` | Перевести на внутренний номер | Номер extension (например `201`) |
| `playback` | Воспроизвести текст/файл | Текст для синтеза или путь к файлу (зависит от `valueOptions`) |
| `playback_record` | Воспроизвести аудио и записать ответ клиента | Текст подсказки для синтеза |
| `restart` | Повторить опрос заново (переход к первому вопросу) | — |
| `""` (пустая) | Нет действия, переход к следующему вопросу | — |

**Дополнительные поля действия `playback_record`:**

| Поле | Тип | Описание |
|------|-----|----------|
| `needRecognize` | string | `"1"` — распознать речь клиента через Yandex SpeechKit STT. Длительность записи ограничена 30 секундами |
| `recognizeLabel` | string | Краткое представление вопроса (например `"ФИО"`, `"Номер счёта"`). Используется при озвучивании подтверждения |

**Тип вопроса `confirmation`:**

Последний вопрос опроса может быть вопросом-подтверждением. Для этого добавьте поле `"type": "confirmation"` в объект вопроса. При озвучивании:

1. Сначала воспроизводится `questionText` (например: *"Подтвердите введённые данные"*)
2. Затем добавляются пары `recognizeLabel` + распознанный ответ клиента для каждого вопроса с `needRecognize: "1"`
3. Генерируется аудиофайл и воспроизводится клиенту

Если клиент выбирает действие `restart` — опрос начинается заново с первого вопроса. Все предыдущие ответы сохраняются в истории, при повторном подтверждении используются только последние ответы по каждому вопросу.

**Пример:**

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{
    "crmId": "100001",
    "name": "Подтверждение доставки",
    "questions": [
      {
        "questionId": "q1",
        "questionText": "Здравствуйте! Готовы ли вы принять груз? Нажмите 1, если да. Нажмите 0, если нет. Нажмите 3 для связи с оператором.",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer", "value": "yes", "nextQuestion": "q2"},
          {"key": "0", "action": "answer", "value": "no", "nextQuestion": ""},
          {"key": "3", "action": "dial", "value": "201", "nextQuestion": ""}
        ]
      },
      {
        "questionId": "q2",
        "questionText": "Заказать вам такси? Нажмите 1 для подтверждения, 0 для отказа.",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer", "value": "taxi_yes", "nextQuestion": ""},
          {"key": "0", "action": "answer", "value": "taxi_no", "nextQuestion": ""}
        ]
      }
    ]
  }' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/polling
```

**Пример с распознаванием речи (STT) и подтверждением:**

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{
    "crmId": "200001",
    "name": "Сбор показаний",
    "questions": [
      {
        "questionId": "0",
        "questionText": "Вас зовут <NAME>? Если да - нажмите 1, если нет - нажмите 0.",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer", "nextQuestion": "1"},
          {"key": "0", "action": "playback_record", "value": "Представьтесь, пожалуйста.", "valueOptions": "5", "needRecognize": "1", "recognizeLabel": "ФИО", "nextQuestion": "1"}
        ]
      },
      {
        "questionId": "1",
        "questionText": "Номер лицевого счета <ACCOUNT_1>? Нажмите 1 если верно, 0 если нет.",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer", "nextQuestion": "2"},
          {"key": "0", "action": "playback_record", "value": "Продиктуйте номер счета.", "valueOptions": "5", "needRecognize": "1", "recognizeLabel": "Лицевой счёт", "nextQuestion": "2"}
        ]
      },
      {
        "questionId": "2",
        "questionText": "",
        "defPress": "1",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "playback_record", "value": "Назовите показания счётчика.", "valueOptions": "5", "needRecognize": "1", "recognizeLabel": "Показания", "nextQuestion": "3"}
        ]
      },
      {
        "questionId": "3",
        "type": "confirmation",
        "questionText": "Подтвердите введённые данные. Нажмите 1 если верно, 0 чтобы повторить.",
        "defPress": "0",
        "timeout": 10,
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer"},
          {"key": "0", "action": "restart", "nextQuestion": "0"}
        ]
      }
    ]
  }' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/polling
```

> Плейсхолдеры `<NAME>`, `<ACCOUNT_1>`, `<ADDRES>` подставляются из данных клиента (см. раздел "Клиенты"). `<NAME>` — имя клиента из поля `name`, остальные — из `properties`.

**Пример с аудиофайлом вместо TTS:**

```bash
curl -X POST \
  -H 'Content-Type: application/json' \
  -d '{
    "name": "Опрос с аудио",
    "questions": [
      {
        "questionId": "1",
        "questionFile": "/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/db/audio/greeting.wav",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer", "value": "1", "nextQuestion": ""},
          {"key": "2", "action": "playback", "value": "Спасибо за ваш ответ", "valueOptions": "text", "nextQuestion": ""}
        ]
      }
    ]
  }' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/polling
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "id": 7,
    "crmId": "100001",
    "name": "Подтверждение доставки"
  },
  "messages": []
}
```

---

### Получение списка опросов

**GET** `/pbxcore/api/module-dialer/v1/polling`

```bash
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/polling
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "results": [
      {"id": 7, "crmId": "100001", "name": "Подтверждение доставки"},
      {"id": 8, "crmId": "100002", "name": "Опрос удовлетворённости"}
    ]
  },
  "messages": []
}
```

---

### Получение опроса по ID

**GET** `/pbxcore/api/module-dialer/v1/polling/{id}`

Возвращает полную структуру опроса с вопросами и действиями.

```bash
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/polling/7
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "id": 7,
    "crmId": "100001",
    "name": "Подтверждение доставки",
    "questions": [
      {
        "id": 15,
        "questionText": "Здравствуйте! Готовы ли вы принять груз?...",
        "questionFile": "",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer", "value": "yes", "valueOptions": null, "nextQuestion": "16"},
          {"key": "0", "action": "answer", "value": "no", "valueOptions": null, "nextQuestion": ""},
          {"key": "3", "action": "dial", "value": "201", "valueOptions": null, "nextQuestion": ""}
        ]
      },
      {
        "id": 16,
        "questionText": "Заказать вам такси?...",
        "questionFile": "",
        "lang": "ru-RU",
        "press": [
          {"key": "1", "action": "answer", "value": "taxi_yes", "valueOptions": null, "nextQuestion": ""},
          {"key": "0", "action": "answer", "value": "taxi_no", "valueOptions": null, "nextQuestion": ""}
        ]
      }
    ]
  },
  "messages": []
}
```

> **Важно:** после сохранения опроса поле `nextQuestion` в действиях содержит внутренний `id` вопроса (а не переданный `questionId`). Используйте ответ на `GET /polling/{id}` для получения маппинга.

---

### Удаление опроса

**DELETE** `/pbxcore/api/module-dialer/v1/polling/{id}`

Удаляет опрос вместе со всеми вопросами и действиями.

```bash
curl -X DELETE http://127.0.0.1/pbxcore/api/module-dialer/v1/polling/7
```

---

## Результаты обзвона

### Получение результатов (инкрементально)

**GET** `/pbxcore/api/module-dialer/v1/results/{changeTime}`

Возвращает результаты обзвона, изменённые начиная с указанного timestamp. Используйте для периодического опроса: сохраняйте максимальный `changeTime` из ответа и передавайте его в следующем запросе.

```bash
# Все результаты (changeTime = 0)
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/results/0

# Результаты с определённого момента
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/results/1690194629
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "results": [
      {
        "id": 1,
        "taskId": 1000000001,
        "phone": "79001234567",
        "phoneId": "9001234567",
        "state": "endCall",
        "result": "SUCCESS",
        "outDialState": "ANSWER",
        "inDialState": "ANSWER",
        "verboseCallId": "...",
        "linkedId": "...",
        "callFile": "...",
        "cause": "location",
        "params": "",
        "changeTime": 1690194700.1234,
        "countTry": null,
        "timeCallAllow": 0,
        "closeTime": 1690194750.5678
      }
    ]
  },
  "messages": []
}
```

**Поля результата (TaskResults):**

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | integer | ID записи |
| `taskId` | integer | ID задачи |
| `phone` | string | Полный номер телефона |
| `phoneId` | string | Индекс номера (последние 10 цифр) |
| `clientId` | string | Идентификатор клиента (для группировки номеров одного клиента) |
| `state` | string | Текущее состояние вызова (см. ниже) |
| `result` | string | Итоговый результат (см. коды результатов) |
| `outDialState` | string | Статус внешнего вызова (`ANSWER`, `NO ANSWER`, `BUSY`, `CHANUNAVAIL`) |
| `inDialState` | string | Статус внутреннего вызова |
| `verboseCallId` | string | Идентификатор вызова |
| `linkedId` | string | Linked ID вызова в Asterisk |
| `callFile` | string | Имя call-файла |
| `cause` | string | Причина завершения вызова (Asterisk TECH_CAUSE) |
| `params` | string | Сериализованные параметры (PHP `serialize()`) |
| `changeTime` | float | Время последнего изменения (Unix timestamp с микросекундами) |
| `attemptNumber` | integer | Номер текущей попытки дозвона (начиная с `1`) |
| `timeCallAllow` | integer | Unix timestamp разрешённого времени звонка |
| `closeTime` | float | Время завершения вызова (0 — вызов ещё не завершён) |

**Состояния вызова (`state`):**

| Значение | Описание |
|----------|----------|
| `CreateTask` | Задача создана, ожидание обзвона |
| `CreateCallFile` | Call-файл создан, ожидание Asterisk |
| `afterDialOut` | Выполнен набор внешнего номера |
| `startDial` | Начат перевод на внутренний номер |
| `endDial` | Завершён перевод на внутренний номер |
| `EVENT_POLLING` | Идёт опрос |
| `EVENT_POLLING_END` | Опрос завершён |
| `endCall` | Вызов завершён |
| `failedOriginate` | Ошибка инициации вызова |
| `allUserBusy` | Все сотрудники заняты |
| `UserCancelCallback` | Сотрудник отменил callback-вызов |

**Коды результатов (`result`):**

| Код | Описание |
|-----|----------|
| `SUCCESS` | Успешный вызов |
| `SUCCESS_CLIENT_H` | Клиент положил трубку после разговора |
| `SUCCESS_USER_H` | Сотрудник положил трубку после разговора |
| `SUCCESS_POLLING` | Опрос успешно завершён |
| `SUCCESS_ANOTHER_PHONE` | Дозвонились на другой номер того же клиента |
| `SUCCESS_EXTERNAL_SIGNAL` | Обзвон остановлен внешним сигналом (`task-signal-close`) |
| `FAIL` | Общая ошибка |
| `FAIL_CLIENT_H_BEFORE_ANSWER` | Клиент положил трубку до ответа сотрудника |
| `FAIL_USER_NO_ANSWER` | Сотрудник не ответил |
| `FAIL_USER_BUSY` | Сотрудник занят |
| `FAIL_ROUTE` | Маршрут не найден |
| `FAIL_PROVIDER` | Провайдер недоступен |
| `FAIL_POLLING` | Ошибка опроса (клиент повесил трубку во время опроса) |

---

### Получение результатов опросов (инкрементально)

**GET** `/pbxcore/api/module-dialer/v1/polling-results/{changeTime}`

```bash
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/polling-results/0
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "results": [
      {
        "id": 107,
        "taskId": -1,
        "pollingId": "22",
        "questionCrmId": "0",
        "phoneId": "4952290003",
        "phone": "74952290003",
        "result": "-",
        "exten": "mikopbx-1773823480.3-dialer-polling-22-0-.wav",
        "changeTime": "1773823495.547",
        "verboseCallId": "[C-00000002]",
        "linkedId": "mikopbx-1773823480.3",
        "recognizedText": "Попов алексей владимирович",
        "recognizeLabel": "ФИО"
      }
    ]
  },
  "messages": []
}
```

**Поля результата опроса (PolingResults):**

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | integer | ID записи |
| `taskId` | integer | ID задачи обзвона (`-1` для входящих звонков) |
| `pollingId` | string | ID опроса |
| `questionCrmId` | string | CRM-идентификатор вопроса |
| `phoneId` | string | Индекс номера (последние 10 цифр) |
| `phone` | string | Полный номер телефона |
| `exten` | string | Нажатая клавиша или путь к WAV-записи |
| `result` | string | Значение ответа (из поля `value` действия) |
| `changeTime` | float | Время ответа (Unix timestamp) |
| `verboseCallId` | string | Идентификатор вызова для отладки |
| `linkedId` | string | Linked ID вызова в Asterisk (группирует ответы одного звонка) |
| `recognizedText` | string | Распознанный текст (Yandex STT). Пустой если `needRecognize` не включён |
| `recognizeLabel` | string | Краткое представление вопроса (настраивается в web-интерфейсе) |

> При повторе опроса (действие `restart`) все ответы клиента сохраняются в истории. В ответе API могут быть несколько записей с одинаковым `questionCrmId` и `linkedId` — последняя запись является актуальной.

---

## Аудиофайлы

### Загрузка аудиофайла

**POST** `/pbxcore/api/module-dialer/v1/audio`

Загружает аудиофайл и автоматически конвертирует его в формат, совместимый с Asterisk.

**Рекомендуемые характеристики:** MP3 или WAV, 1 канал (mono), 8 kHz, 16 bit.

```bash
curl -F "file=@/path/to/audio.mp3" \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/audio
```

**Ответ:**

```json
{
  "result": true,
  "data": {
    "filename": "/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer/db/audio/474d57f8204699fbb2249f20dede5f8e.mp3"
  },
  "messages": []
}
```

---

### Список аудиофайлов

**GET** `/pbxcore/api/module-dialer/v1/audio`

```bash
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/audio
```

**Ответ:**

```json
{
  "result": true,
  "data": [
    {
      "id": 1,
      "name": "greeting.mp3",
      "path": "/storage/.../db/audio/474d57f8204699fbb2249f20dede5f8e.mp3"
    }
  ],
  "messages": []
}
```

---

### Удаление аудиофайла

**DELETE** `/pbxcore/api/module-dialer/v1/audio/{name}`

Удаляет аудиофайл и все его конвертированные версии.

```bash
curl -X DELETE http://127.0.0.1/pbxcore/api/module-dialer/v1/audio/greeting.mp3
```

**Ответ:**

```json
{
  "result": true,
  "data": [],
  "messages": []
}
```

---

## Полный пример рабочего процесса

### 1. Создание опроса

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "poll-001",
    "name": "Подтверждение заказа",
    "questions": [{
      "questionId": "q1",
      "questionText": "Здравствуйте! Подтвердите заказ номер 12345. Нажмите 1 для подтверждения, 0 для отмены.",
      "lang": "ru-RU",
      "press": [
        {"key": "1", "action": "answer", "value": "confirmed", "nextQuestion": ""},
        {"key": "0", "action": "answer", "value": "cancelled", "nextQuestion": ""}
      ]
    }]
  }' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/polling
```

Запоминаем `id` из ответа (например `7`).

### 2. Создание задачи обзвона

```bash
curl -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "task-001",
    "name": "Обзвон по заказам",
    "state": 0,
    "innerNum": "7",
    "innerNumType": "polling",
    "maxCountChannels": 5,
    "dialPrefix": "9",
    "numbers": [
      {"number": "79001234567"},
      {"number": "79001234568"},
      {"number": "79001234569"}
    ]
  }' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task
```

### 3. Мониторинг результатов

```bash
# Первый запрос — получить все результаты
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/results/0

# Последующие запросы — только новые/изменённые
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/results/1690194700

# Результаты опросов
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/polling-results/0
```

### 4. Получение полной информации по задаче

```bash
curl http://127.0.0.1/pbxcore/api/module-dialer/v1/task/1000000001
```

### 5. Закрытие задачи

```bash
curl -X PUT -H 'Content-Type: application/json' \
  -d '{"state": 1}' \
  http://127.0.0.1/pbxcore/api/module-dialer/v1/task/1000000001
```
