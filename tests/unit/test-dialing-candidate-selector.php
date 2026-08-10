<?php

require_once __DIR__ . '/../lib/TestRunner.php';
require_once __DIR__ . '/../../Lib/DialingWindow.php';
require_once __DIR__ . '/../../Lib/DialingCandidateSelector.php';

use Modules\ModuleAutoDialer\Lib\DialingCandidateSelector;

$runner = new TestRunner('Dialing Candidate Selector');
$now = gmmktime(7, 0, 0, 8, 6, 2026);

$runner->run('Skip first number outside its recipient-local window', function () use ($now): void {
    $previousTimezone = date_default_timezone_get();
    date_default_timezone_set('Asia/Yekaterinburg');
    try {
        $selected = DialingCandidateSelector::select([
            ['id' => 1, 'clientId' => 'a', 'timeCallAllow' => 0, 'timeOffsetMinutes' => -240],
            ['id' => 2, 'clientId' => 'b', 'timeCallAllow' => 0, 'timeOffsetMinutes' => 0],
        ], [], $now, 480, 1320);
    } finally {
        date_default_timezone_set($previousTimezone);
    }

    assertEq(2, $selected['id'], 'PBX-local zero candidate at 12:00 is selected');
});

$runner->run('Respect absolute timeCallAllow', function () use ($now): void {
    $selected = DialingCandidateSelector::select([
        ['id' => 1, 'clientId' => 'a', 'timeCallAllow' => $now + 60, 'timeOffsetMinutes' => 300],
        ['id' => 2, 'clientId' => 'b', 'timeCallAllow' => $now, 'timeOffsetMinutes' => 300],
    ], [], $now, 480, 1320);

    assertEq(2, $selected['id'], 'future candidate is skipped');
});

$runner->run('Skip candidates belonging to busy clients', function () use ($now): void {
    $selected = DialingCandidateSelector::select([
        ['id' => 1, 'clientId' => 'busy', 'timeCallAllow' => 0, 'timeOffsetMinutes' => 300],
        ['id' => 2, 'clientId' => 'free', 'timeCallAllow' => 0, 'timeOffsetMinutes' => 300],
    ], ['busy' => true], $now, 480, 1320);

    assertEq(2, $selected['id'], 'free client is selected');
});

$runner->run('Empty client IDs do not participate in client locking', function () use ($now): void {
    $selected = DialingCandidateSelector::select([
        ['id' => 1, 'clientId' => '', 'timeCallAllow' => 0, 'timeOffsetMinutes' => 300],
    ], ['' => true], $now, 480, 1320);

    assertEq(1, $selected['id'], 'empty client remains eligible');
});

$runner->run('Return null when no candidate is eligible', function () use ($now): void {
    $selected = DialingCandidateSelector::select([
        ['id' => 1, 'clientId' => 'a', 'timeCallAllow' => 0, 'timeOffsetMinutes' => -240],
    ], [], $now, 480, 1320);

    assertEq(null, $selected, 'no candidate selected');
});

exit($runner->exitCode());
