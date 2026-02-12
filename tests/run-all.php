<?php
/**
 * Запуск всех тестов ModuleAutoDialer.
 *
 * Каждый тест запускается в отдельном PHP-процессе для изоляции
 * (Phalcon DI, глобальные счётчики, состояние PJSUA).
 *
 * Использование:
 *   php run-all.php          # Все тесты
 *   php run-all.php unit     # Только юнит/интеграционные
 *   php run-all.php e2e      # Только E2E
 *
 * Exit code: 0 = все тесты прошли, 1 = есть ошибки
 */

$filter = $argv[1] ?? 'all';
$testDir = __DIR__;

// Определяем наборы тестов
$unitTests = [
    'unit/test-data-integrity.php',
    'unit/test-api-tasks.php',
    'unit/test-api-clients.php',
];

$e2eTests = [
    'e2e/test-basic-call.php',
    'e2e/test-callback.php',
    'e2e/test-retry.php',
    'e2e/test-client-grouping.php',
    'e2e/test-polling-ivr.php',
    'e2e/test-working-hours.php',
];

// Фильтрация
switch ($filter) {
    case 'unit':
        $tests = $unitTests;
        break;
    case 'e2e':
        $tests = $e2eTests;
        break;
    case 'all':
    default:
        $tests = array_merge($unitTests, $e2eTests);
        break;
}

echo "========================================\n";
echo "ModuleAutoDialer Test Runner\n";
echo "Filter: {$filter} | Tests: " . count($tests) . "\n";
echo "========================================\n\n";

$totalPassed = 0;
$totalFailed = 0;
$results = [];

foreach ($tests as $testFile) {
    $fullPath = $testDir . '/' . $testFile;

    if (!file_exists($fullPath)) {
        echo "[SKIP] {$testFile} — file not found\n\n";
        $results[$testFile] = 'SKIP';
        continue;
    }

    echo "[RUN] {$testFile}\n";
    echo str_repeat('-', 40) . "\n";

    // Запуск в отдельном процессе
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $cmd = 'php -f ' . escapeshellarg($fullPath) . ' 2>&1';
    $process = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($process)) {
        echo "  ERROR: failed to start process\n\n";
        $results[$testFile] = 'ERROR';
        $totalFailed++;
        continue;
    }

    fclose($pipes[0]); // stdin не нужен

    // Читаем вывод
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    // Фильтруем php.backend (системные сообщения MikoPBX)
    $lines = explode("\n", $output);
    $filtered = array_filter($lines, function($line) {
        return stripos($line, 'php.backend') === false;
    });
    echo implode("\n", $filtered);

    if (!empty($stderr)) {
        $stderrLines = explode("\n", $stderr);
        $stderrFiltered = array_filter($stderrLines, function($line) {
            return stripos($line, 'php.backend') === false && trim($line) !== '';
        });
        if (!empty($stderrFiltered)) {
            echo "  STDERR: " . implode("\n  STDERR: ", $stderrFiltered) . "\n";
        }
    }

    if ($exitCode === 0) {
        $results[$testFile] = 'PASS';
        $totalPassed++;
        echo "[PASS] {$testFile}\n\n";
    } else {
        $results[$testFile] = 'FAIL';
        $totalFailed++;
        echo "[FAIL] {$testFile} (exit code: {$exitCode})\n\n";
    }
}

// Итого
echo "========================================\n";
echo "SUMMARY\n";
echo "========================================\n";

foreach ($results as $file => $status) {
    $icon = $status === 'PASS' ? '+' : ($status === 'SKIP' ? '~' : '-');
    echo "  [{$icon}] {$file}: {$status}\n";
}

echo "\nTotal: {$totalPassed} passed, {$totalFailed} failed\n";
echo "========================================\n";

exit($totalFailed > 0 ? 1 : 0);
