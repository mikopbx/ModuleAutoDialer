<?php
/**
 * HTTP-клиент для REST API ModuleAutoDialer.
 *
 * Работает на curl без внешних зависимостей.
 * Базовый URL: http://127.0.0.1/pbxcore/api/module-dialer/v1
 */

class ApiClient
{
    private string $baseUrl;
    private int $defaultTimeout;

    public function __construct(string $baseUrl = 'http://127.0.0.1/pbxcore/api/module-dialer/v1', int $defaultTimeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultTimeout = $defaultTimeout;
    }

    // --- Задачи ---

    /**
     * Создание задачи обзвона.
     * POST /task
     * @param array $data Данные задачи (crmId, name, state, innerNum, numbers, ...)
     * @return array
     */
    public function createTask(array $data): array
    {
        // Создание задачи через Beanstalk IPC может быть медленным для больших списков
        return $this->post('/task', $data, 120);
    }

    /**
     * Получение задачи по ID.
     * GET /task/{id}
     */
    public function getTask(string $taskId): array
    {
        return $this->get("/task/{$taskId}");
    }

    /**
     * Список задач.
     * GET /task
     * @param array $params Фильтры: state, limit, offset
     */
    public function getTasks(array $params = []): array
    {
        $query = !empty($params) ? '?' . http_build_query($params) : '';
        return $this->get("/task{$query}");
    }

    /**
     * Обновление задачи.
     * PUT /task/{id}
     */
    public function updateTask(string $taskId, array $data): array
    {
        return $this->put("/task/{$taskId}", $data);
    }

    /**
     * Удаление задачи.
     * DELETE /task/{id}
     */
    public function deleteTask(string $taskId): array
    {
        return $this->delete("/task/{$taskId}");
    }

    /**
     * Остановка обзвона по телефону.
     * POST /task-signal-close
     */
    public function taskSignalClose(array $data): array
    {
        return $this->post('/task-signal-close', $data);
    }

    // --- Результаты ---

    /**
     * Получение результатов обзвона (инкрементально по changeTime).
     * GET /results/{changeTime}
     */
    public function getResults(string $changeTime): array
    {
        return $this->get("/results/{$changeTime}");
    }

    /**
     * Получение результатов опросов (инкрементально по changeTime).
     * GET /polling-results/{changeTime}
     */
    public function getPollingResults(string $changeTime): array
    {
        return $this->get("/polling-results/{$changeTime}");
    }

    // --- Клиенты ---

    /**
     * Создание/обновление клиентов.
     * POST /client
     * @param array $clients Массив клиентов
     */
    public function createClients(array $clients): array
    {
        return $this->post('/client', $clients);
    }

    /**
     * Удаление клиента.
     * DELETE /client/{crmId}
     */
    public function deleteClient(string $crmId): array
    {
        return $this->delete("/client/{$crmId}");
    }

    /**
     * Поиск клиента по номеру телефона.
     * GET /client-by-phone/{phone}
     */
    public function getClientByPhone(string $phone): array
    {
        return $this->get("/client-by-phone/{$phone}");
    }

    // --- Опросы ---

    /**
     * Создание/обновление опроса.
     * POST /polling
     */
    public function createPolling(array $data): array
    {
        return $this->post('/polling', $data);
    }

    /**
     * Список опросов.
     * GET /polling
     */
    public function getPollings(): array
    {
        return $this->get('/polling');
    }

    /**
     * Детали опроса по ID.
     * GET /polling/{id}
     */
    public function getPolling(string $pollingId): array
    {
        return $this->get("/polling/{$pollingId}");
    }

    /**
     * Удаление опроса.
     * DELETE /polling/{id}
     */
    public function deletePolling(string $pollingId): array
    {
        return $this->delete("/polling/{$pollingId}");
    }

    // --- Аудио ---

    /**
     * Загрузка аудиофайла.
     * POST /audio
     */
    public function uploadAudio(string $filePath): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/audio',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($filePath)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->defaultTimeout,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['result' => false, 'messages' => ["curl error: {$error}"], 'http_code' => 0];
        }
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['result' => false, 'messages' => ['Invalid JSON response'], 'http_code' => $httpCode, 'raw' => $response];
        }
        $decoded['http_code'] = $httpCode;
        return $decoded;
    }

    /**
     * Список аудиофайлов.
     * GET /audio
     */
    public function listAudioFiles(): array
    {
        return $this->get('/audio');
    }

    /**
     * Удаление аудиофайла.
     * DELETE /audio/{name}
     */
    public function deleteAudioFile(string $name): array
    {
        return $this->delete("/audio/{$name}");
    }

    // --- XLS ---

    /**
     * Загрузка XLS/XLSX с номерами.
     * POST /upload-xls
     */
    public function uploadXls(string $filePath): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/upload-xls',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($filePath)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->defaultTimeout,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['result' => false, 'messages' => ["curl error: {$error}"], 'http_code' => 0];
        }
        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['result' => false, 'messages' => ['Invalid JSON response'], 'http_code' => $httpCode, 'raw' => $response];
        }
        $decoded['http_code'] = $httpCode;
        return $decoded;
    }

    // --- Утилиты: polling результатов ---

    /**
     * Ожидание появления результатов по задаче.
     * Периодически опрашивает GET /results/{changeTime} пока не появятся записи с result != ''.
     *
     * @param string $taskId ID задачи
     * @param string $changeTime Начальная временная метка
     * @param int $maxWait Максимальное время ожидания (сек)
     * @param int $interval Интервал опроса (сек)
     * @return array Найденные результаты с непустым result
     */
    public function pollResults(string $taskId, string $changeTime, int $maxWait = 120, int $interval = 5): array
    {
        $deadline = time() + $maxWait;
        while (time() < $deadline) {
            $response = $this->getResults($changeTime);
            $results = $response['data']['results'] ?? [];
            if (is_array($results)) {
                $matched = array_filter($results, function ($r) use ($taskId) {
                    return (string)($r['taskId'] ?? '') === (string)$taskId && !empty($r['result']);
                });
                if (!empty($matched)) {
                    return array_values($matched);
                }
            }
            sleep($interval);
        }
        return [];
    }

    // --- HTTP-методы ---

    private function get(string $path, int $timeout = 0): array
    {
        return $this->request('GET', $path, null, $timeout ?: $this->defaultTimeout);
    }

    private function post(string $path, array $data, int $timeout = 0): array
    {
        return $this->request('POST', $path, $data, $timeout ?: $this->defaultTimeout);
    }

    private function put(string $path, array $data, int $timeout = 0): array
    {
        return $this->request('PUT', $path, $data, $timeout ?: $this->defaultTimeout);
    }

    private function delete(string $path, int $timeout = 0): array
    {
        return $this->request('DELETE', $path, null, $timeout ?: $this->defaultTimeout);
    }

    private function request(string $method, string $path, ?array $data, int $timeout): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init();

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ];

        switch ($method) {
            case 'POST':
                $opts[CURLOPT_POST] = true;
                if ($data !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                }
                break;
            case 'PUT':
                $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if ($data !== null) {
                    $opts[CURLOPT_POSTFIELDS] = json_encode($data);
                }
                break;
            case 'DELETE':
                $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['result' => false, 'messages' => ["curl error: {$error}"], 'http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['result' => false, 'messages' => ['Invalid JSON response'], 'http_code' => $httpCode, 'raw' => $response];
        }
        $decoded['http_code'] = $httpCode;
        return $decoded;
    }
}
