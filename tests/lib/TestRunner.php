<?php
/**
 * Тестовый фреймворк для ModuleAutoDialer.
 *
 * Предоставляет глобальные assert-функции и класс TestRunner
 * для безопасного запуска тестов (try/catch предотвращает отключение модуля).
 */

$passed = 0;
$failed = 0;

function assertEq($expected, $actual, string $msg): void {
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "  OK: {$msg}\n";
    } else {
        $failed++;
        echo "  FAIL: {$msg} (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")\n";
    }
}

function assertTrue($actual, string $msg): void {
    assertEq(true, $actual, $msg);
}

function assertFalse($actual, string $msg): void {
    assertEq(false, $actual, $msg);
}

function assertContains(string $needle, string $haystack, string $msg): void {
    global $passed, $failed;
    if (strpos($haystack, $needle) !== false) {
        $passed++;
        echo "  OK: {$msg}\n";
    } else {
        $failed++;
        echo "  FAIL: {$msg} ('{$needle}' not found in '{$haystack}')\n";
    }
}

function assertNotEmpty($value, string $msg): void {
    global $passed, $failed;
    if (!empty($value)) {
        $passed++;
        echo "  OK: {$msg}\n";
    } else {
        $failed++;
        echo "  FAIL: {$msg} (value is empty)\n";
    }
}

class TestRunner
{
    private int $testsPassed = 0;
    private int $testsFailed = 0;
    private string $suiteName;

    public function __construct(string $suiteName = 'Tests')
    {
        $this->suiteName = $suiteName;
        echo "=== {$suiteName} ===\n";
    }

    /**
     * Запускает тест в try/catch.
     * Критично: необработанное исключение на PBX отключает модуль!
     */
    public function run(string $name, callable $fn): void
    {
        global $passed, $failed;
        $beforePassed = $passed;
        $beforeFailed = $failed;

        echo "\n--- {$name} ---\n";
        try {
            $fn();
        } catch (\Throwable $e) {
            $failed++;
            echo "  EXCEPTION: " . $e->getMessage() . "\n";
            echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        }

        $newPassed = $passed - $beforePassed;
        $newFailed = $failed - $beforeFailed;

        if ($newFailed > 0) {
            $this->testsFailed++;
        } elseif ($newPassed > 0) {
            $this->testsPassed++;
        }
    }

    /**
     * Выводит итоги и возвращает exit code (0 = все тесты прошли).
     */
    public function exitCode(): int
    {
        global $passed, $failed;
        echo "\n=== {$this->suiteName}: RESULT ===\n";
        echo "Tests: {$this->testsPassed} passed, {$this->testsFailed} failed\n";
        echo "Assertions: {$passed} passed, {$failed} failed\n";
        return $failed > 0 ? 1 : 0;
    }
}
