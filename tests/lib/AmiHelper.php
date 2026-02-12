<?php
/**
 * Простой AMI-клиент для E2E тестов.
 *
 * Подключается к Asterisk Manager Interface для отправки DTMF
 * и поиска каналов. Используется вместо pjsua DTMF, который
 * не работает в --null-audio режиме.
 */

class AmiHelper
{
    private string $host;
    private int $port;
    private string $username;
    private string $secret;
    /** @var resource|null */
    private $socket = null;

    public function __construct(string $host = '127.0.0.1', int $port = 5038, string $username = 'phpagi', string $secret = 'phpagi')
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->secret = $secret;
    }

    /**
     * Подключение и авторизация.
     */
    public function connect(): bool
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$this->socket) {
            echo "  [AMI] ERROR: connect failed: {$errstr} ({$errno})\n";
            return false;
        }
        stream_set_timeout($this->socket, 5);

        // Читаем приветствие (одна строка: "Asterisk Call Manager/...")
        fgets($this->socket, 4096);

        // Логин
        $response = $this->sendAction([
            'Action'   => 'Login',
            'Username' => $this->username,
            'Secret'   => $this->secret,
        ]);

        if (strpos($response, 'Success') === false) {
            echo "  [AMI] ERROR: login failed\n";
            return false;
        }

        return true;
    }

    /**
     * Поиск канала по паттерну (например 'PJSIP/228-').
     * Возвращает полное имя канала или пустую строку.
     */
    public function findChannel(string $pattern): string
    {
        $response = $this->sendAction([
            'Action' => 'CoreShowChannels',
        ]);

        // Ищем строку "Channel: PJSIP/228-XXXXXXXX"
        if (preg_match_all('/Channel:\s*(' . preg_quote($pattern, '/') . '[^\r\n]*)/i', $response, $matches)) {
            return trim($matches[1][0]);
        }
        return '';
    }

    /**
     * Возвращает список всех активных каналов (для отладки).
     * @return string[]
     */
    public function listAllChannels(): array
    {
        $response = $this->sendAction([
            'Action' => 'CoreShowChannels',
        ]);

        $channels = [];
        if (preg_match_all('/Channel:\s*([^\r\n]+)/i', $response, $matches)) {
            foreach ($matches[1] as $ch) {
                $channels[] = trim($ch);
            }
        }
        return $channels;
    }

    /**
     * Отправка DTMF в канал через AMI PlayDTMF.
     * Receive=1 — DTMF инжектится как полученный каналом (а не отправленный).
     */
    public function playDtmf(string $channel, string $digit): bool
    {
        $response = $this->sendAction([
            'Action'  => 'PlayDTMF',
            'Channel' => $channel,
            'Digit'   => $digit,
            'Receive' => '1',
        ]);

        $ok = strpos($response, 'Success') !== false;
        if (!$ok) {
            echo "  [AMI] PlayDTMF failed for {$channel}: {$response}\n";
        }
        return $ok;
    }

    /**
     * Ожидание появления канала и отправка DTMF.
     * Объединяет findChannel + playDtmf с ожиданием.
     */
    public function waitAndSendDtmf(string $channelPattern, string $digits, int $timeout = 30, int $delayBeforeDtmf = 3): bool
    {
        $deadline = time() + $timeout;
        $channel = '';

        while (time() < $deadline) {
            $channel = $this->findChannel($channelPattern);
            if (!empty($channel)) {
                break;
            }
            usleep(500000);
        }

        if (empty($channel)) {
            echo "  [AMI] Channel matching '{$channelPattern}' not found within {$timeout}s\n";
            return false;
        }

        echo "  [AMI] Found channel: {$channel}\n";

        if ($delayBeforeDtmf > 0) {
            echo "  [AMI] Waiting {$delayBeforeDtmf}s before DTMF...\n";
            sleep($delayBeforeDtmf);
        }

        return $this->sendDtmfToChannel($channel, $digits);
    }

    /**
     * Поиск канала и немедленная отправка DTMF (без задержки между поиском и отправкой).
     * Используется когда известно что звонок уже активен (после waitForIncomingCall).
     */
    public function findAndSendDtmf(string $channelPattern, string $digits, int $timeout = 10): bool
    {
        $deadline = time() + $timeout;
        $channel = '';

        while (time() < $deadline) {
            $channel = $this->findChannel($channelPattern);
            if (!empty($channel)) {
                break;
            }
            usleep(500000);
        }

        if (empty($channel)) {
            echo "  [AMI] Channel matching '{$channelPattern}' not found within {$timeout}s\n";
            return false;
        }

        echo "  [AMI] Found channel: {$channel}\n";
        return $this->sendDtmfToChannel($channel, $digits);
    }

    /**
     * Отправка DTMF по имени канала.
     */
    private function sendDtmfToChannel(string $channel, string $digits): bool
    {
        $ok = true;
        for ($i = 0; $i < strlen($digits); $i++) {
            $digit = $digits[$i];
            echo "  [AMI] Sending DTMF '{$digit}' to {$channel}\n";
            if (!$this->playDtmf($channel, $digit)) {
                $ok = false;
                break;
            }
            if ($i < strlen($digits) - 1) {
                usleep(300000); // 300ms между цифрами
            }
        }
        return $ok;
    }

    /**
     * Ожидание доступности PJSIP-endpoint после регистрации.
     * SIP-транки в MikoPBX используют identify matching (не AOR-контакты),
     * поэтому `pjsip show contacts` не показывает их статус.
     * Вместо этого отправляем qualify и проверяем что Asterisk может
     * отправить OPTIONS и получить ответ.
     */
    public function qualifyEndpoint(string $endpoint, int $timeout = 10): bool
    {
        $deadline = time() + $timeout;
        while (time() < $deadline) {
            // Отправляем qualify-запрос (OPTIONS) через CLI
            shell_exec("asterisk -rx 'pjsip qualify {$endpoint}' 2>/dev/null");
            sleep(1);

            // Проверяем через CLI: endpoint показывает RTT (значит qualify прошёл)
            $output = shell_exec("asterisk -rx 'pjsip show endpoint {$endpoint}' 2>/dev/null");
            if ($output && preg_match('/Avail|Reachable/i', $output)) {
                echo "  [AMI] Endpoint {$endpoint} is available\n";
                return true;
            }
            // Проверяем AOR — если контакт есть, значит endpoint зарегистрирован
            $aorOutput = shell_exec("asterisk -rx 'pjsip show aor {$endpoint}' 2>/dev/null");
            if ($aorOutput && preg_match('/Contact:.*' . preg_quote($endpoint, '/') . '/i', $aorOutput)) {
                echo "  [AMI] Endpoint {$endpoint} has registered contact\n";
                return true;
            }
        }
        // Фоллбэк: просто ждём фиксированное время
        echo "  [AMI] Endpoint {$endpoint}: qualify timeout, continuing with sleep\n";
        sleep(3);
        return true;
    }

    /**
     * Отключение.
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            $this->sendAction(['Action' => 'Logoff']);
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    // --- Приватные методы ---

    private int $actionCounter = 0;

    private function sendAction(array $params): string
    {
        if ($this->socket === null) {
            return '';
        }

        $this->actionCounter++;
        $actionId = 'test-' . $this->actionCounter;
        $params['ActionID'] = $actionId;

        $msg = '';
        foreach ($params as $key => $value) {
            $msg .= "{$key}: {$value}\r\n";
        }
        $msg .= "\r\n";

        fwrite($this->socket, $msg);
        fflush($this->socket);

        // Для CoreShowChannels ожидаем EventList: Complete
        $isList = ($params['Action'] === 'CoreShowChannels');
        return $this->readActionResponse($actionId, $isList);
    }

    /**
     * Читает AMI-ответ, фильтруя по ActionID.
     * Пропускает асинхронные события (VarSet и т.д.).
     */
    private function readActionResponse(string $actionId, bool $isList = false): string
    {
        if ($this->socket === null) {
            return '';
        }

        $response = '';
        $block = '';
        $deadline = time() + 10;

        while (!feof($this->socket) && time() < $deadline) {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                // Таймаут чтения — попробуем ещё
                if (time() < $deadline) {
                    continue;
                }
                break;
            }
            $block .= $line;

            // Блок заканчивается пустой строкой
            if (trim($line) === '') {
                // Проверяем принадлежность к нашему ActionID
                if (strpos($block, "ActionID: {$actionId}") !== false) {
                    $response .= $block;
                    // Для списков ждём EventList: Complete
                    if ($isList) {
                        if (strpos($response, 'EventList: Complete') !== false) {
                            break;
                        }
                        $block = '';
                        continue;
                    }
                    break;
                }
                // Не наш блок — пропускаем (асинхронное событие)
                $block = '';
                continue;
            }
        }

        return $response;
    }
}
