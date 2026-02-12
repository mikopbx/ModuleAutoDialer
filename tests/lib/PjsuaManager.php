<?php
/**
 * Управление процессом PJSUA для E2E тестов.
 *
 * Запускает pjsua в фоне, регистрируется на SIP-сервере,
 * отслеживает входящие звонки, отправляет DTMF.
 */

class PjsuaManager
{
    private string $binary;
    private string $username;
    private string $password;
    private string $registrar;
    private int $localPort;
    private int $autoAnswerCode;
    private int $duration;

    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    /** @var resource|null */
    private $stderr = null;

    private string $outputBuffer = '';
    private string $label;

    public function __construct(array $config, string $label = 'pjsua')
    {
        $this->binary     = $config['binary'] ?? '';
        $this->username   = $config['username'] ?? '';
        $this->password   = $config['password'] ?? '';
        $this->registrar  = $config['registrar'] ?? 'sip:127.0.0.1';
        $this->localPort  = (int)($config['local_port'] ?? 5080);
        $this->autoAnswerCode = (int)($config['auto_answer'] ?? 200);
        $this->duration   = (int)($config['duration'] ?? 60);
        $this->label      = $label;
    }

    /**
     * Запуск процесса pjsua.
     */
    public function start(): bool
    {
        if ($this->process !== null) {
            return true;
        }

        if (!file_exists($this->binary) || !is_executable($this->binary)) {
            echo "  [{$this->label}] ERROR: binary not found or not executable: {$this->binary}\n";
            return false;
        }

        $cmd = sprintf(
            '%s --null-audio --auto-answer=%d --duration=%d --local-port=%d --id=sip:%s@127.0.0.1 --registrar=%s --realm=* --username=%s --password=%s --no-color 2>&1',
            escapeshellarg($this->binary),
            $this->autoAnswerCode,
            $this->duration,
            $this->localPort,
            escapeshellarg($this->username),
            escapeshellarg($this->registrar),
            escapeshellarg($this->username),
            escapeshellarg($this->password)
        );

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        echo "  [{$this->label}] Starting: {$this->username}@{$this->registrar} port={$this->localPort}\n";

        $this->process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($this->process)) {
            echo "  [{$this->label}] ERROR: failed to start process\n";
            return false;
        }

        $this->stdin  = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Неблокирующее чтение stdout
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        return true;
    }

    /**
     * Ожидание SIP-регистрации.
     */
    public function waitForRegistration(int $timeout = 15): bool
    {
        return $this->waitForPattern(
            ['registration success', 'status=200 (OK)'],
            $timeout,
            'registration'
        );
    }

    /**
     * Ожидание входящего вызова (CONFIRMED).
     */
    public function waitForIncomingCall(int $timeout = 60): bool
    {
        return $this->waitForPattern(
            ['state changed to CONFIRMED', 'CONFIRMED'],
            $timeout,
            'incoming call'
        );
    }

    /**
     * Ожидание завершения вызова (DISCONNECTED).
     */
    public function waitForHangup(int $timeout = 30): bool
    {
        return $this->waitForPattern(
            ['state changed to DISCONN', 'DISCONNCTD'],
            $timeout,
            'hangup'
        );
    }

    /**
     * Отправка DTMF-тонов через RFC 2833 (RTP events).
     * В pjsua: # переключает на режим DTMF, затем вводим цифры.
     */
    public function sendDtmf(string $digits): bool
    {
        return $this->sendDtmfInternal($digits, '#', 'RFC2833');
    }

    /**
     * Отправка DTMF через SIP INFO.
     * В pjsua: * переключает на режим DTMF через INFO.
     * Работает в --null-audio режиме, когда RFC 2833 не проходит.
     */
    public function sendDtmfInfo(string $digits): bool
    {
        return $this->sendDtmfInternal($digits, '*', 'SIP-INFO');
    }

    private function sendDtmfInternal(string $digits, string $menuKey, string $method): bool
    {
        if ($this->stdin === null) {
            echo "  [{$this->label}] ERROR: process not started\n";
            return false;
        }

        echo "  [{$this->label}] Sending DTMF ({$method}): {$digits}\n";

        fwrite($this->stdin, "{$menuKey}\n");
        usleep(500000); // 500ms — ждём промпт
        fwrite($this->stdin, "{$digits}\n");
        fflush($this->stdin);

        return true;
    }

    /**
     * Завершение вызова.
     */
    public function hangup(): bool
    {
        if ($this->stdin === null) {
            return false;
        }

        echo "  [{$this->label}] Hanging up\n";
        fwrite($this->stdin, "h\n");
        fflush($this->stdin);
        return true;
    }

    /**
     * Остановка процесса.
     */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        echo "  [{$this->label}] Stopping...\n";

        // Graceful shutdown
        if ($this->stdin !== null && is_resource($this->stdin)) {
            fwrite($this->stdin, "q\n");
            fflush($this->stdin);
        }

        // Ждём 3 секунды
        $deadline = time() + 3;
        while (time() < $deadline) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        }

        // SIGKILL если всё ещё работает
        $status = proc_get_status($this->process);
        if ($status['running']) {
            echo "  [{$this->label}] Force kill (SIGKILL)\n";
            proc_terminate($this->process, 9);
        }

        $this->closeStreams();
        proc_close($this->process);
        $this->process = null;
    }

    /**
     * Возвращает весь вывод stdout для отладки.
     */
    public function getOutputBuffer(): string
    {
        $this->readOutput();
        return $this->outputBuffer;
    }

    /**
     * Проверяет, запущен ли процесс.
     */
    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }
        $status = proc_get_status($this->process);
        return $status['running'];
    }

    public function __destruct()
    {
        $this->stop();
    }

    // --- Приватные методы ---

    /**
     * Ожидание появления одного из паттернов в stdout.
     */
    private function waitForPattern(array $patterns, int $timeout, string $description): bool
    {
        $deadline = time() + $timeout;
        $searchFrom = strlen($this->outputBuffer);

        while (time() < $deadline) {
            $this->readOutput();

            // Ищем только в новом тексте (оптимизация)
            $newText = substr($this->outputBuffer, $searchFrom);
            $lowerText = strtolower($newText);

            foreach ($patterns as $pattern) {
                if (strpos($lowerText, strtolower($pattern)) !== false) {
                    echo "  [{$this->label}] {$description}: OK\n";
                    return true;
                }
            }

            // Проверка что процесс не упал
            if (!$this->isRunning()) {
                echo "  [{$this->label}] ERROR: process died while waiting for {$description}\n";
                return false;
            }

            usleep(200000); // 200ms
        }

        echo "  [{$this->label}] TIMEOUT waiting for {$description} ({$timeout}s)\n";
        return false;
    }

    /**
     * Чтение доступного вывода из stdout.
     */
    private function readOutput(): void
    {
        if ($this->stdout === null || !is_resource($this->stdout)) {
            return;
        }
        while (($line = fgets($this->stdout, 4096)) !== false) {
            $this->outputBuffer .= $line;
        }
    }

    private function closeStreams(): void
    {
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $stream) {
            if ($stream !== null && is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
    }
}
