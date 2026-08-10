<?php

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../../Lib/DialingWindow.php';

use Modules\ModuleAutoDialer\Lib\DialingWindow;

$runner = new TestRunner('Dialing Window');

$runner->run('Normalize non-empty offsets', function (): void {
    assertEq(300, DialingWindow::normalizeOffset('5'), 'UTC+5 string');
    assertEq(300, DialingWindow::normalizeOffset(5), 'UTC+5 integer');
    assertEq(-240, DialingWindow::normalizeOffset(-4), 'UTC-4');
    assertEq(0, DialingWindow::normalizeOffset('0'), 'zero remains explicitly stored');
    assertEq(330, DialingWindow::normalizeOffset(5.5), 'fractional offset');
});

$runner->run('Normalize PBX-local fallback', function (): void {
    assertEq(null, DialingWindow::normalizeOffset(''), 'empty string');
    assertEq(null, DialingWindow::normalizeOffset('  '), 'whitespace string');
    assertEq(null, DialingWindow::normalizeOffset(null), 'null');
});

$runner->run('Reject invalid explicit offsets', function (): void {
    foreach (['Moscow', -12.1, 14.1, 5.111] as $value) {
        $thrown = false;
        try {
            DialingWindow::normalizeOffset($value);
        } catch (InvalidArgumentException $e) {
            $thrown = true;
        }
        assertTrue($thrown, 'invalid offset rejected: ' . var_export($value, true));
    }
});

$runner->run('Calculate recipient minute of day', function (): void {
    $timestamp = gmmktime(21, 30, 0, 8, 6, 2026);
    assertEq(150, DialingWindow::minuteOfDay($timestamp, 300), 'UTC+5 rolls to 02:30');
    assertEq(1050, DialingWindow::minuteOfDay($timestamp, -240), 'UTC-4 is 17:30');

    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Moscow');
    assertEq(30, DialingWindow::minuteOfDay($timestamp, null), 'PBX-local time is 00:30');
    assertEq(30, DialingWindow::minuteOfDay($timestamp, 0), 'zero uses PBX-local time');
    date_default_timezone_set($previousTimezone);
});

$runner->run('Zero offset uses PBX-local dialing window', function (): void {
    $timestamp = gmmktime(7, 12, 0, 8, 10, 2026);
    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Yekaterinburg');
    assertEq(732, DialingWindow::minuteOfDay($timestamp, 0), 'zero uses PBX-local 12:12');
    assertTrue(
        DialingWindow::isAllowed($timestamp, 0, 485, 1315),
        'zero permits task window at PBX-local 12:12'
    );
    date_default_timezone_set($previousTimezone);
});

$runner->run('Apply ordinary and overnight windows', function (): void {
    assertTrue(DialingWindow::isMinuteAllowed(480, 480, 1320), 'ordinary start inclusive');
    assertTrue(DialingWindow::isMinuteAllowed(1320, 480, 1320), 'ordinary end inclusive');
    assertFalse(DialingWindow::isMinuteAllowed(479, 480, 1320), 'before ordinary window');
    assertTrue(DialingWindow::isMinuteAllowed(60, 1320, 360), 'overnight after midnight');
    assertTrue(DialingWindow::isMinuteAllowed(1380, 1320, 360), 'overnight before midnight');
    assertFalse(DialingWindow::isMinuteAllowed(720, 1320, 360), 'overnight daytime excluded');
    assertTrue(DialingWindow::isMinuteAllowed(720, 0, 1440), 'default window is all day');
});

$runner->run('Apply offset and window together', function (): void {
    $timestamp = gmmktime(3, 0, 0, 8, 6, 2026);
    assertTrue(DialingWindow::isAllowed($timestamp, 300, 480, 1320), '08:00 in UTC+5 is allowed');
    assertFalse(DialingWindow::isAllowed($timestamp, 0, 480, 1320), '03:00 UTC is excluded');
});

exit($runner->exitCode());
