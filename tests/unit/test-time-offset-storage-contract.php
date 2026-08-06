<?php

require_once __DIR__ . '/../lib/TestRunner.php';

$runner = new TestRunner('Time Offset Storage Contract');
$modelSource = file_get_contents(__DIR__ . '/../../Models/TaskResults.php');
$connectorSource = file_get_contents(__DIR__ . '/../../bin/ConnectorDB.php');

$runner->run('Task result model exposes nullable offset minutes', function () use ($modelSource): void {
    assertContains('public $timeOffsetMinutes;', $modelSource, 'model property exists');
    assertContains("@Column(type=\"integer\", nullable=true)\n     */\n    public \$timeOffsetMinutes;", $modelSource, 'nullable integer column');
});

$runner->run('Task ingestion reads and persists TimeOffset', function () use ($connectorSource): void {
    assertContains('array_key_exists(\'TimeOffset\', $numData)', $connectorSource, 'explicit zero is preserved');
    assertContains('DialingWindow::normalizeOffset', $connectorSource, 'offset is validated');
    assertContains("'timeOffsetMinutes'", $connectorSource, 'normalized value enters number map');
    assertContains('timeOffsetMinutes = $indexPhones', $connectorSource, 'existing row is updated');
    assertContains('timeOffsetMinutes, timeCallAllow', $connectorSource, 'batch insert includes offset');
});

exit($runner->exitCode());
