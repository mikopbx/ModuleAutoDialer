# Per-number Time Offset Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `timeStart`/`timeEnd` apply in each phone recipient's UTC offset while preserving PBX-local behavior when `TimeOffset` is empty.

**Architecture:** Normalize the request offset into a nullable minute count stored on `TaskResults`. Put clock arithmetic and window checks in a small pure helper, then make `ConnectorDB` filter candidate results before choosing the next phone so an ineligible first row cannot block its task.

**Tech Stack:** PHP 7/8-compatible module code, Phalcon ORM/PHQL, SQLite-backed module models, existing custom PHP test runner.

## Global Constraints

- `TimeOffset = 0` explicitly means UTC; empty, `null`, or omitted means PBX local time.
- Positive and negative offsets, including fractional-hour values resolving to whole minutes, are supported from UTC-12:00 through UTC+14:00.
- Window boundaries are inclusive and windows crossing midnight are supported.
- Existing rows and requests without `TimeOffset` keep PBX-local behavior.
- `timeCallAllow`, channel limits, client locking, task states, and active-call behavior remain unchanged.
- The external field name remains exactly `TimeOffset`; the persisted property is `timeOffsetMinutes`.

---

### Task 1: Pure offset normalization and dialing-window rules

**Files:**
- Create: `Libs/DialingWindow.php`
- Create: `tests/unit/test-dialing-window.php`

**Interfaces:**
- Produces: `DialingWindow::normalizeOffset($value): ?int`, throwing `InvalidArgumentException` for invalid explicit values.
- Produces: `DialingWindow::minuteOfDay(int $timestamp, ?int $offsetMinutes): int`.
- Produces: `DialingWindow::isMinuteAllowed(int $minute, int $timeStart, int $timeEnd): bool`.
- Produces: `DialingWindow::isAllowed(int $timestamp, ?int $offsetMinutes, int $timeStart, int $timeEnd): bool`.

- [ ] **Step 1: Write the failing unit tests**

Cover normalization of `5`, `"5"`, `-4`, `"0"`, `""`, `null`, omitted-equivalent `null`, and `5.5`; rejection of text, values below `-12`, values above `14`, and fractions that do not resolve to a whole minute; fixed-timestamp UTC/local minute calculations; inclusive ordinary windows; midnight-crossing windows; and `0..1440`.

Key assertions:

```php
assertEq(300, DialingWindow::normalizeOffset('5'), 'UTC+5');
assertEq(-240, DialingWindow::normalizeOffset(-4), 'UTC-4');
assertEq(0, DialingWindow::normalizeOffset('0'), 'zero is explicit UTC');
assertEq(null, DialingWindow::normalizeOffset(''), 'empty uses PBX time');
assertTrue(DialingWindow::isMinuteAllowed(480, 480, 1320), 'start inclusive');
assertTrue(DialingWindow::isMinuteAllowed(60, 1320, 360), 'overnight window');
assertFalse(DialingWindow::isMinuteAllowed(720, 1320, 360), 'overnight daytime excluded');
```

- [ ] **Step 2: Run the new unit test and verify failure**

Run: `php tests/unit/test-dialing-window.php`

Expected: non-zero exit because `Libs/DialingWindow.php` does not exist.

- [ ] **Step 3: Implement the pure helper**

Use the module namespace and these rules:

```php
public static function normalizeOffset($value): ?int
{
    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }
    if (!is_numeric($value)) {
        throw new \InvalidArgumentException('TimeOffset must be numeric or empty');
    }
    $minutes = (float)$value * 60;
    if (abs($minutes - round($minutes)) > 0.000001 || $minutes < -720 || $minutes > 840) {
        throw new \InvalidArgumentException('TimeOffset must be between -12 and 14 hours in whole minutes');
    }
    return (int)round($minutes);
}
```

For an explicit offset use normalized modulo arithmetic on `intdiv($timestamp, 60) + $offsetMinutes`; for `null` use `(int)date('G', $timestamp) * 60 + (int)date('i', $timestamp)`. Treat `0..1440` as all day before ordinary/cross-midnight comparison.

- [ ] **Step 4: Run the unit test and syntax check**

Run: `php tests/unit/test-dialing-window.php`

Expected: all assertions pass.

Run: `php -l Libs/DialingWindow.php`

Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit the helper and tests**

```bash
git add Libs/DialingWindow.php tests/unit/test-dialing-window.php
git commit -m "feat: add recipient dialing window rules"
```

### Task 2: Persist and validate per-number offsets

**Files:**
- Modify: `Models/TaskResults.php`
- Modify: `bin/ConnectorDB.php`
- Modify: `tests/unit/test-api-tasks.php`
- Modify: `README.md`
- Modify: `tests/curl-examples.md`

**Interfaces:**
- Consumes: `DialingWindow::normalizeOffset($value): ?int` from Task 1.
- Produces: nullable ORM property `TaskResults::$timeOffsetMinutes`.
- Produces: each extended `numbers` item accepts external key `TimeOffset`.

- [ ] **Step 1: Add failing API/model assertions**

Extend task API coverage so a request with offsets `"5"`, `"0"`, `-4`, and `""` expects stored values `300`, `0`, `-240`, and `NULL`. Add invalid-input cases and assert the response fails with the affected phone number and `TimeOffset` in the message. Add a repeated submission assertion showing an open row's offset is updated.

- [ ] **Step 2: Run the focused test and verify failure**

Run: `php tests/unit/test-api-tasks.php`

Expected: failure because the model and ingestion path do not expose `timeOffsetMinutes`.

- [ ] **Step 3: Extend the model and ingestion map**

Add the nullable integer annotation/property to `TaskResults`:

```php
/** @Column(type="integer", nullable=true) */
public $timeOffsetMinutes;
```

In `addTaskResults()`, normalize `array_key_exists('TimeOffset', $numData) ? $numData['TimeOffset'] : null` before changing the database. On validation failure, return `false` and append a message containing the phone and validation reason. Carry `timeOffsetMinutes` through `$indexPhones`, existing-row updates, raw insert columns, placeholders, and binds. Do not use `empty()`, because it conflates explicit zero with fallback.

- [ ] **Step 4: Document the external contract**

Add `TimeOffset` to the extended-number tables and examples in `README.md` and `tests/curl-examples.md`, explicitly documenting `0` versus empty and showing a negative offset.

- [ ] **Step 5: Run focused tests and syntax checks**

Run: `php tests/unit/test-api-tasks.php`

Expected: all assertions pass in a configured PBX test environment; if unavailable locally, report the environmental dependency separately from static failures.

Run: `php -l Models/TaskResults.php` and `php -l bin/ConnectorDB.php`

Expected: no syntax errors.

- [ ] **Step 6: Commit persistence and API support**

```bash
git add Models/TaskResults.php bin/ConnectorDB.php tests/unit/test-api-tasks.php README.md tests/curl-examples.md
git commit -m "feat: persist recipient time offsets"
```

### Task 3: Select only recipient-local eligible numbers

**Files:**
- Modify: `bin/ConnectorDB.php`
- Create: `tests/unit/test-time-offset-selection.php`
- Modify: `tests/e2e/test-working-hours.php`

**Interfaces:**
- Consumes: `TaskResults::$timeOffsetMinutes` from Task 2.
- Consumes: `DialingWindow::isAllowed(int, ?int, int, int): bool` from Task 1.
- Produces: a focused private eligibility method in `ConnectorDB`, `isResultWithinDialingWindow(TaskResults $result, array $taskData, int $now): bool`.

- [ ] **Step 1: Write failing selection tests**

Create fixtures/stubs for one task where the first ready row is outside its UTC-offset window and a later row is inside. Assert the later row is selected. Cover UTC+5, UTC, UTC-4, PBX fallback, an overnight task window, `timeCallAllow`, and busy-client alternate selection.

- [ ] **Step 2: Run the focused selection test and verify failure**

Run: `php tests/unit/test-time-offset-selection.php`

Expected: failure because `getSliceTask()` selects `MIN(TaskResults.id)` before checking recipient-local eligibility.

- [ ] **Step 3: Refactor candidate selection**

Remove the task-level `:timeMin: BETWEEN Tasks.timeStart AND Tasks.timeEnd` restriction. Include `Tasks.timeStart` and `Tasks.timeEnd` in task aggregation and fetch ready candidate rows with `id`, `taskId`, `phone`, `params`, `clientId`, `timeCallAllow`, and `timeOffsetMinutes` ordered by `timeCallAllow, id`.

For each task, choose the first candidate satisfying `timeCallAllow <= $now`, `DialingWindow::isAllowed(...)`, and the existing busy-client rule. Keep `in_progress`, `not_completed`, channel limit data, dial prefix fallback, and automatic task closing semantics intact. Change `findAvailablePhone()` to accept task window and `$now`, iterate ordered ready candidates, and apply the same helper before returning an alternate.

When a candidate is skipped solely for its local window, log task/result ID, normalized offset, calculated local minute, and task window; do not log `params`.

- [ ] **Step 4: Run unit, existing working-hours, and syntax tests**

Run: `php tests/unit/test-time-offset-selection.php`

Expected: all assertions pass.

Run: `php tests/e2e/test-working-hours.php`

Expected: existing PBX-local test passes in the configured E2E environment.

Run: `php -l bin/ConnectorDB.php`

Expected: no syntax errors.

- [ ] **Step 5: Extend the E2E scenario**

Submit one active task containing at least two numbers whose explicit offsets place one inside and one outside the same task window. Assert only the inside-window result advances beyond `CreateTask`, then delete the task.

- [ ] **Step 6: Run the complete available test suite**

Run: `php tests/run-all.php`

Expected: all locally supported unit/integrity suites pass; PBX/AMI-dependent skips or connection failures are recorded explicitly.

Run: `git diff --check`

Expected: no whitespace errors.

- [ ] **Step 7: Commit selection behavior**

```bash
git add bin/ConnectorDB.php tests/unit/test-time-offset-selection.php tests/e2e/test-working-hours.php
git commit -m "feat: apply dialing windows per recipient timezone"
```

### Task 4: Final compatibility verification

**Files:**
- Verify: `Models/TaskResults.php`
- Verify: `Libs/DialingWindow.php`
- Verify: `bin/ConnectorDB.php`
- Verify: `README.md`
- Verify: `tests/curl-examples.md`

**Interfaces:**
- Consumes the completed feature from Tasks 1-3.
- Produces a verified implementation ready for review.

- [ ] **Step 1: Inspect the complete diff against the design**

Run: `git diff HEAD~3 -- Models/TaskResults.php Libs/DialingWindow.php bin/ConnectorDB.php README.md tests/curl-examples.md tests/unit tests/e2e/test-working-hours.php`

Expected: every design requirement is represented and unrelated files are absent.

- [ ] **Step 2: Verify PHP syntax for every changed PHP file**

Run individual `php -l` commands for each changed PHP file.

Expected: no syntax errors.

- [ ] **Step 3: Run all available tests again**

Run: `php tests/run-all.php`

Expected: all runnable tests pass; environmental E2E limitations are listed with their exact errors.

- [ ] **Step 4: Confirm worktree hygiene**

Run: `git status --short` and `git diff --check`.

Expected: only the user's pre-existing untracked files remain, and no whitespace errors exist.
