# PBX-Local Zero Time Offset Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `TimeOffset=0` evaluate dialing windows in PBX local time without migrating existing database rows.

**Architecture:** Preserve ingestion and storage so existing `timeOffsetMinutes=0` rows remain valid. Change only `DialingWindow::minuteOfDay()` so both `null` and zero use PHP's configured PBX timezone, while non-zero offsets continue to use UTC arithmetic.

**Tech Stack:** PHP 7.4+, Phalcon module models, SQLite, standalone PHP test runner, Markdown documentation.

## Global Constraints

- `TimeOffset=0`, `""`, `null`, and an omitted field use PBX local time.
- Non-zero offsets remain recipient UTC offsets between UTC−12 and UTC+14.
- Do not rewrite the client's 1.6-GB SQLite database.
- Preserve ordinary, overnight, inclusive-boundary, and all-day behavior.
- Do not add the unrelated untracked files `amd.md`, `uzbek.md`, or `tests/test-rhvoice-reproduce.php`.

---

### Task 1: Correct zero-offset window evaluation

**Files:**
- Modify: `tests/unit/test-dialing-window.php:10-52`
- Modify: `Lib/DialingWindow.php:35-45`
- Test: `tests/unit/test-dialing-window.php`

**Interfaces:**
- Consumes: `DialingWindow::minuteOfDay(int $timestamp, ?int $offsetMinutes): int`
- Produces: zero and null use the configured PBX timezone; non-zero values retain UTC-offset behavior.

- [ ] **Step 1: Write the failing regression tests**

Set `Asia/Yekaterinburg` (UTC+5), use `2026-08-10 07:12 UTC`, and assert:

```php
assertEq(732, DialingWindow::minuteOfDay($timestamp, 0), 'zero uses PBX-local 12:12');
assertTrue(DialingWindow::isAllowed($timestamp, 0, 485, 1315), 'zero permits task window at PBX-local 12:12');
```

Keep assertions that `300` resolves to UTC+5 and `-240` resolves to UTC−4.

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/unit/test-dialing-window.php`

Expected: FAIL because zero resolves to 07:12 UTC instead of 12:12 PBX time.

- [ ] **Step 3: Implement the minimal behavior change**

Change the local-time branch in `DialingWindow::minuteOfDay()` to:

```php
if ($offsetMinutes === null || $offsetMinutes === 0) {
    return (int)date('G', $timestamp) * 60 + (int)date('i', $timestamp);
}
```

Do not change `normalizeOffset()` or database storage.

- [ ] **Step 4: Verify GREEN and regression coverage**

Run:

```bash
php tests/unit/test-dialing-window.php
php tests/unit/test-dialing-candidate-selector.php
php tests/unit/test-time-offset-storage-contract.php
```

Expected: all tests and assertions pass.

- [ ] **Step 5: Commit the behavior and tests**

```bash
git add Lib/DialingWindow.php tests/unit/test-dialing-window.php
git commit -m "fix: use PBX time for zero recipient offset"
```

### Task 2: Align API tests and documentation

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `tests/README.md`
- Modify: `tests/curl-examples.md`
- Modify: `tests/unit/test-time-offset-api.php`
- Modify: `tests/unit/test-time-offset-selection.php`

**Interfaces:**
- Consumes: stored `timeOffsetMinutes=0` remains observable through GET.
- Produces: documentation clearly states that stored zero and null are distinct values but equivalent for dialing-window evaluation.

- [ ] **Step 1: Update API assertions and selection fixtures**

Keep the storage assertion that API input zero persists as integer `0`, rename its message from explicit UTC to PBX-local zero, and ensure selection tests use a configured non-UTC PBX timezone when asserting zero behavior.

- [ ] **Step 2: Update the public contract**

Replace every statement that `TimeOffset=0` means UTC with:

```text
TimeOffset=0, an empty string, null, or an omitted field uses PBX local time.
Only non-zero values specify a recipient timezone relative to UTC.
```

Retain GET examples showing that input zero is stored as `timeOffsetMinutes=0` and empty input as `null`.

- [ ] **Step 3: Validate documentation and tests**

Run:

```bash
rg -n "именно UTC|zero is explicit UTC|0.*означает UTC|0.*UTC" README.md CLAUDE.md tests --glob '*.md' --glob '*.php'
git diff --check
php -l tests/unit/test-time-offset-api.php
php -l tests/unit/test-time-offset-selection.php
```

Expected: no stale contract wording, clean diff, and both integration test files pass PHP syntax validation. Their runtime checks run on the test PBX in Task 3.

- [ ] **Step 4: Commit documentation and integration expectations**

```bash
git add README.md CLAUDE.md tests/README.md tests/curl-examples.md tests/unit/test-time-offset-api.php tests/unit/test-time-offset-selection.php
git commit -m "docs: clarify PBX-local zero time offset"
```

### Task 3: Deploy and verify on the test PBX

**Files:**
- No repository file changes.

**Interfaces:**
- Consumes: committed module code and existing test PBX database.
- Produces: evidence that existing stored zeros work without schema or data migration.

- [ ] **Step 1: Synchronize changed files to the test PBX and restart the module workers**

Deploy only the tracked changed files to `/storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer`, then restart/re-enable the module using the existing test-server workflow.

- [ ] **Step 2: Run the complete integration suite**

Run:

```bash
ssh serber@boffart.miko.ru 'cd /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer && php tests/run-all.php unit'
```

Expected: 8 passed, 0 failed.

- [ ] **Step 3: Verify runtime selection with a stored zero**

Create a cleanup-safe test task whose UTC time is outside 08:05–21:55 but PBX-local UTC+5 time is inside it, store `TimeOffset=0`, and assert `getSliceTask()` returns that number. Delete the test task afterward.

- [ ] **Step 4: Verify repository and server cleanup**

Confirm no active `autotest-*` task or test process remains, the server test configuration matches the repository, and `git status --short` contains only the three pre-existing unrelated untracked files.

### Task 4: Prepare client recovery instructions

**Files:**
- No repository file changes.

**Interfaces:**
- Consumes: fixed module package.
- Produces: safe operator commands that do not mutate `m_TaskResults`.

- [ ] **Step 1: Document the client procedure**

Tell the operator to install the fixed module, restart/re-enable it, confirm all three workers are running, and leave existing `timeOffsetMinutes=0` rows unchanged.

- [ ] **Step 2: Document task activation and verification**

For task `1006517`, confirm `m_Tasks.state=0`, verify `getSliceTask()` returns a non-zero result ID during 08:05–21:55 PBX time, and explain that no SQL update of the 1.6-GB database is required.
