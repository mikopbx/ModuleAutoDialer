# REST API: примеры curl-запросов

Базовый URL: `http://127.0.0.1/pbxcore/api/module-dialer/v1`

Все примеры выполняются на PBX-сервере (`ssh serber@boffart.miko.ru`).
Для удобства задаём переменную:

```bash
API="http://127.0.0.1/pbxcore/api/module-dialer/v1"
```

---

## Задачи обзвона

### Создание задачи (простой список номеров)

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "test-001",
    "name": "Тестовая задача",
    "state": 0,
    "innerNum": "228",
    "maxCountChannels": 1,
    "dialPrefix": "999",
    "numbers": ["79001112233", "79001112244"]
  }' "$API/task" | jq .
```

`state`: 0 = открыта (обзвон начнётся), 1 = закрыта, 2 = пауза.

### Создание задачи (номера с clientId и параметрами)

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "test-002",
    "name": "Задача с параметрами",
    "state": 1,
    "innerNum": "228",
    "maxCountChannels": 2,
    "dialPrefix": "999",
    "maxAttempt": 3,
    "tryInterval": 120,
    "numbers": [
      {"number": "79001112233", "clientId": "client-1", "params": {"debt": "5000 руб"}},
      {"number": "79001112244", "clientId": "client-1"},
      {"number": "79001112255", "clientId": "client-2"}
    ]
  }' "$API/task" | jq .
```

Если `clientId` одинаковый — при дозвоне на один номер остальные номера клиента закрываются автоматически.

### Создание задачи с IVR-опросом

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "test-poll-001",
    "name": "Задача с опросом",
    "state": 0,
    "innerNum": "15",
    "innerNumType": "polling",
    "maxCountChannels": 1,
    "dialPrefix": "999",
    "numbers": [
      {"number": "79001112233", "TimeOffset": 5},
      {"number": "79001112234", "TimeOffset": -4},
      {"number": "79001112235", "TimeOffset": ""}
    ]
  }' "$API/task" | jq .
```

`innerNumType: "polling"` — вместо соединения с оператором запускает IVR-опрос.
`innerNum` — ID опроса из `/polling`.

### Создание callback-задачи

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "test-cb-001",
    "name": "Callback задача",
    "state": 0,
    "innerNum": "228",
    "isCallback": 1,
    "maxCountChannels": 1,
    "dialPrefix": "999",
    "numbers": ["79001112233"]
  }' "$API/task" | jq .
```

`isCallback: 1` — сначала звонок оператору, затем клиенту.

### Создание задачи с рабочим временем и повторами

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "test-hours-001",
    "name": "Задача с рабочим временем",
    "state": 0,
    "innerNum": "228",
    "maxCountChannels": 1,
    "dialPrefix": "999",
    "timeStart": 540,
    "timeEnd": 1080,
    "maxAttempt": 5,
    "tryInterval": 300,
    "attemptUntilSignal": 0,
    "numbers": ["79001112233"]
  }' "$API/task" | jq .
```

`timeStart: 540` = 09:00 (540 минут от 00:00).
`timeEnd: 1080` = 18:00.
`tryInterval: 300` = 5 минут между попытками.
`TimeOffset: 5` означает UTC+5, `-4` — UTC−4, `0` — UTC, а пустое значение — время АТС. Рабочий интервал применяется отдельно по местному времени каждого номера.

### Получение задачи по ID

```bash
curl -s "$API/task/123" | jq .
```

Ответ включает массив `results` с номерами и их статусами.

### Список всех задач

```bash
curl -s "$API/task" | jq .
```

С фильтрацией:

```bash
# Только открытые задачи (state=0)
curl -s "$API/task?state=0" | jq .

# С пагинацией
curl -s "$API/task?limit=10&offset=100" | jq .
```

### Обновление задачи

```bash
# Поставить на паузу
curl -s -X PUT -H 'Content-Type: application/json' \
  -d '{"state": 2}' "$API/task/123" | jq .

# Возобновить обзвон
curl -s -X PUT -H 'Content-Type: application/json' \
  -d '{"state": 0}' "$API/task/123" | jq .

# Закрыть задачу
curl -s -X PUT -H 'Content-Type: application/json' \
  -d '{"state": 1}' "$API/task/123" | jq .

# Изменить имя и количество каналов
curl -s -X PUT -H 'Content-Type: application/json' \
  -d '{"name": "Новое имя", "maxCountChannels": 3}' "$API/task/123" | jq .
```

### Удаление задачи

```bash
curl -s -X DELETE "$API/task/123" | jq .
```

### Остановка обзвона по номеру телефона (внешний сигнал)

```bash
# Остановить обзвон конкретного номера во всех задачах
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"phone": "79001112233"}' "$API/task-signal-close" | jq .

# Остановить обзвон номера в конкретной задаче
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"phone": "79001112233", "taskId": "123"}' "$API/task-signal-close" | jq .
```

---

## Результаты обзвона

### Получение результатов (инкрементально)

```bash
# Все результаты с начала эпохи
curl -s "$API/results/0" | jq .

# Результаты за последний час (unix timestamp)
curl -s "$API/results/$(date -d '1 hour ago' +%s)" | jq .

# На macOS:
curl -s "$API/results/$(date -v-1H +%s)" | jq .
```

Ответ:

```json
{
  "result": true,
  "data": {
    "results": [
      {
        "id": "1",
        "taskId": "123",
        "phoneId": "...",
        "phone": "79001112233",
        "clientId": "client-1",
        "result": "SUCCESS",
        "attemptNumber": "1",
        "changeTime": "1700000000",
        "closeTime": "1700000030",
        "linkedId": "...",
        "cause": "",
        "params": ""
      }
    ]
  }
}
```

Возможные `result`:
- `SUCCESS` — успешный звонок
- `SUCCESS_CLIENT_H` — клиент положил трубку (после ответа)
- `SUCCESS_USER_H` — оператор положил трубку
- `SUCCESS_POLLING` — опрос завершён
- `SUCCESS_ANOTHER_PHONE` — дозвонились на другой номер того же клиента
- `SUCCESS_EXTERNAL_SIGNAL` — остановлено внешним сигналом
- `FAIL` — неудача
- `FAIL_CLIENT_H_BEFORE_ANSWER` — клиент сбросил до ответа
- `FAIL_USER_NO_ANSWER` — оператор не ответил
- `FAIL_USER_BUSY` — оператор занят
- `FAIL_ROUTE` — ошибка маршрутизации
- `FAIL_PROVIDER` — ошибка провайдера
- `FAIL_POLLING` — ошибка опроса

---

## Клиенты

### Создание/обновление клиентов

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '[
    {
      "name": "Петров Иван Степанович",
      "crmId": "CRM-001",
      "properties": [
        {"key": "ADDRES", "value": "Москва, ул. Ленина д. 1"},
        {"key": "ACCOUNT", "value": "10000123"}
      ],
      "phones": ["74952293042", "79052232222"]
    },
    {
      "name": "Сидорова Анна",
      "crmId": "CRM-002",
      "phones": ["79161234567"]
    }
  ]' "$API/client" | jq .
```

При повторном вызове с тем же `crmId` данные клиента обновляются.

### Поиск клиента по номеру телефона

```bash
curl -s "$API/client-by-phone/74952293042" | jq .
```

### Удаление клиента

```bash
curl -s -X DELETE "$API/client/CRM-001" | jq .
```

---

## Опросы (IVR Polling)

### Создание опроса

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "poll-001",
    "name": "Подтверждение доставки",
    "questions": [
      {
        "questionId": "1",
        "questionText": "Готовы ли Вы принять груз? Нажмите 1 если да, 0 если нет, 3 для связи с оператором.",
        "press": [
          {"key": "1", "action": "answer", "value": "1", "nextQuestion": "2"},
          {"key": "0", "action": "answer", "value": "0", "nextQuestion": ""},
          {"key": "3", "action": "dial", "value": "228", "nextQuestion": ""}
        ]
      },
      {
        "questionId": "2",
        "questionText": "Заказать Вам такси? Нажмите 1 если да, 0 если нет.",
        "press": [
          {"key": "1", "action": "answer", "value": "1", "nextQuestion": ""},
          {"key": "0", "action": "answer", "value": "0", "nextQuestion": ""}
        ]
      }
    ]
  }' "$API/polling" | jq .
```

Действия (`action`):
- `answer` — сохранить ответ и перейти к `nextQuestion` (пусто = завершить)
- `dial` — соединить с внутренним номером (`value` = номер)

### Список опросов

```bash
curl -s "$API/polling" | jq .
```

### Детали опроса

```bash
curl -s "$API/polling/15" | jq .
```

### Удаление опроса

```bash
curl -s -X DELETE "$API/polling/15" | jq .
```

### Опрос с подстановкой параметров номера

В `questionText` можно использовать переменные в угловых скобках `<key>`, которые будут заменены значениями из `params` каждого номера при звонке.

```bash
# 1. Создать опрос с переменной <gate> в тексте
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "poll-gate-001",
    "name": "Уведомление о воротах",
    "questions": [
      {
        "questionId": "1",
        "questionText": "Здравствуйте! Подъезжайте к воротам номер <gate>. Нажмите 1 для подтверждения, 0 для отмены.",
        "press": [
          {"key": "1", "action": "answer", "value": "confirmed", "nextQuestion": ""},
          {"key": "0", "action": "answer", "value": "cancelled", "nextQuestion": ""}
        ]
      }
    ]
  }' "$API/polling" | jq .

# 2. Создать задачу с params у каждого номера
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "test-gate-001",
    "name": "Задача с параметрами опроса",
    "state": 0,
    "innerNum": "15",
    "innerNumType": "polling",
    "maxCountChannels": 1,
    "dialPrefix": "999",
    "maxAttempt": 3,
    "numbers": [
      {"number": "79001112233", "params": {"gate": "ДЕВЯТЬ"}},
      {"number": "79001112244", "params": {"gate": "ТРИ"}}
    ]
  }' "$API/task" | jq .
```

При звонке на `79001112233` TTS произнесёт *"...к воротам номер ДЕВЯТЬ..."*, на `79001112244` — *"...номер ТРИ..."*.

**Важно:** если значение параметра состоит только из цифр, оно автоматически разбивается посимвольно для TTS (`"123"` → `"1 2 3"`). Чтобы число произносилось как слово — передавайте текстом (`"ДЕВЯТЬ"`, а не `"9"`).

### Результаты опросов (инкрементально)

```bash
curl -s "$API/polling-results/0" | jq .
```

---

## Аудиофайлы

### Загрузка аудиофайла

```bash
curl -s -F "file=@/path/to/recording.mp3" "$API/audio" | jq .
```

### Список аудиофайлов

```bash
curl -s "$API/audio" | jq .
```

### Удаление аудиофайла

```bash
curl -s -X DELETE "$API/audio/recording.mp3" | jq .
```

---

## Загрузка номеров из Excel

```bash
curl -s -F "file=@/path/to/phones.xlsx" "$API/upload-xls" | jq .
```

Формат файла: каждая строка — один клиент, колонки — номера телефонов.
Возвращает массив `[{"number": "...", "clientId": "1"}, ...]`, который можно использовать в `POST /task` как `numbers`.

---

## Типичные сценарии

### Полный цикл: создать задачу и дождаться результатов

```bash
# 1. Создать задачу
CHANGE_TIME=$(date +%s)
RESULT=$(curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "manual-test-001",
    "name": "Ручной тест",
    "state": 0,
    "innerNum": "228",
    "maxCountChannels": 1,
    "dialPrefix": "999",
    "numbers": ["79001112233"]
  }' "$API/task")

TASK_ID=$(echo "$RESULT" | jq -r '.data.id')
echo "Task ID: $TASK_ID"

# 2. Проверить статус задачи
curl -s "$API/task/$TASK_ID" | jq '.data.state, .data.results'

# 3. Дождаться результатов (повторять вручную)
curl -s "$API/results/$CHANGE_TIME" | jq '.data.results[] | select(.taskId == "'$TASK_ID'")'

# 4. Закрыть задачу
curl -s -X PUT -H 'Content-Type: application/json' \
  -d '{"state": 1}' "$API/task/$TASK_ID" | jq .

# 5. Удалить задачу
curl -s -X DELETE "$API/task/$TASK_ID" | jq .
```

### Повторная отправка задачи с тем же crmId (upsert)

```bash
# Повторный POST с тем же crmId обновляет существующую задачу и заменяет список номеров
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{
    "crmId": "test-001",
    "name": "Обновлённая задача",
    "state": 0,
    "innerNum": "228",
    "maxCountChannels": 1,
    "dialPrefix": "999",
    "numbers": ["79009998877"]
  }' "$API/task" | jq .
```

---

## Параметры задачи (справочник полей)

| Поле | Тип | Обязательное | По умолчанию | Описание |
|---|---|---|---|---|
| `crmId` | string | нет | = id | Внешний идентификатор (для upsert) |
| `name` | string | нет | | Название задачи |
| `state` | int | нет | 0 | 0=открыта, 1=закрыта, 2=пауза |
| `innerNum` | string | да | | Внутренний номер оператора или ID опроса |
| `innerNumType` | string | нет | `exten` | `exten` = оператор, `polling` = IVR-опрос |
| `maxCountChannels` | int | нет | 1 | Макс. одновременных вызовов |
| `dialPrefix` | string | нет | | Префикс маршрута |
| `isCallback` | int | нет | 0 | 1 = callback-режим |
| `maxAttempt` | int | нет | 1 | Количество попыток дозвона |
| `tryInterval` | int | нет | 60 | Интервал между попытками (секунды) |
| `attemptUntilSignal` | int | нет | 0 | 1 = повторять до внешнего сигнала |
| `timeStart` | int | нет | 0 | Начало рабочего времени (минуты от 00:00) |
| `timeEnd` | int | нет | 1440 | Конец рабочего времени (минуты от 00:00) |
| `numbers` | array | да | | Массив номеров (строки или объекты) |

Формат элемента `numbers`:
- Строка: `"79001112233"`
- Объект: `{"number": "79001112233", "clientId": "c1", "TimeOffset": 5, "params": {"key": "value"}}`

В объекте номера `TimeOffset` задаёт смещение от UTC в часах (`5`, `-4`, `0`). Пустая строка, `null` или отсутствие поля означают использование локального времени АТС.
