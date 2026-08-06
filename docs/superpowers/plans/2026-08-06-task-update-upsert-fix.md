# Task Update and Upsert Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve an omitted task `crmId`, return complete PUT results across IPC, and restore same-task POST upsert behavior.

**Architecture:** Keep `changeTask()` returning `PBXApiResult` for direct in-worker callers, but normalize that object to an array in `onEvents()` before Beanstalk serialization. Make `crmId` normalization presence-aware so partial updates do not manufacture an empty field.

**Tech Stack:** PHP, Phalcon ORM, Beanstalk IPC, existing REST integration test runner.

## Global Constraints

- Omitted `crmId` on an existing task preserves the stored value.
- Explicit empty `crmId` retains the current fallback-to-internal-ID behavior.
- New tasks without `crmId` retain the current internal-ID fallback.
- IPC callers receive arrays; direct internal callers may continue receiving `PBXApiResult`.
- `TimeOffset`, transaction, routing, and deletion behavior remain unchanged.

---

### Task 1: Reproduce partial-update and upsert failures

**Files:**
- Modify: `tests/unit/test-api-tasks.php`

**Interfaces:**
- Consumes: existing `ApiClient::createTask()`, `updateTask()`, `getTask()`, and `deleteTask()`.
- Produces: one regression sequence covering PUT response, `crmId` preservation, same-ID upsert, number replacement, and duplicate cleanup detection.

- [ ] **Step 1: Strengthen the existing assertions**

After name-only PUT, assert:

```php
assertTrue($result['result'] ?? false, 'PUT result = true');
$check = $api->getTask($taskId);
assertEq($testCrmId, $check['data']['crmId'] ?? '', 'crmId preserved after partial PUT');
```

After repeat POST, retain the same-ID and two-number assertions. During cleanup, query the task list and assert no remaining row has `$testCrmId` after deleting the original ID.

- [ ] **Step 2: Run the server test and verify RED**

Run: `ssh serber@boffart.miko.ru 'cd /storage/usbdisk1/mikopbx/custom_modules/ModuleAutoDialer && php tests/unit/test-api-tasks.php'`

Expected: PUT lacks `result=true`, GET shows `crmId` changed to the internal ID, repeated POST returns a new ID, and the original retains three numbers.

### Task 2: Preserve crmId and normalize IPC results

**Files:**
- Modify: `bin/ConnectorDB.php`
- Test: `tests/unit/test-api-tasks.php`

**Interfaces:**
- Produces: `onEvents()` replies with an array when a handler returns `PBXApiResult`.
- Produces: `changeTask()` only writes `crmId` when supplied or when creating a new task.

- [ ] **Step 1: Normalize worker results at the IPC boundary**

Immediately after dispatch in `onEvents()` add:

```php
if ($res_data instanceof PBXApiResult) {
    $res_data = $res_data->getResult();
}
```

Serialize the normalized `$res_data` through the existing reply path.

- [ ] **Step 2: Make crmId handling presence-aware**

At the start of `changeTask()` compute:

```php
$hasCrmId = array_key_exists('crmId', $data);
$crmId = trim((string)($data['crmId'] ?? ''));
if ($hasCrmId || $createNew) {
    $data['crmId'] = $crmId;
}
```

Use `$crmId` for logging and lookup. Because the field is absent from `$data` on a name-only PUT, the existing attribute-copy loop retains `$oldValue`.

- [ ] **Step 3: Deploy only the changed code and test to the test PBX**

Copy `bin/ConnectorDB.php` and `tests/unit/test-api-tasks.php` to their matching module paths, then restart/reload the module through its normal enable lifecycle so the long-running connector loads the new code.

- [ ] **Step 4: Verify GREEN on the server**

Run: `php tests/unit/test-api-tasks.php` in the installed module.

Expected: all seven test sections and all assertions pass, including same task ID and two numbers after upsert.

- [ ] **Step 5: Run regression suites and static checks**

Run `php -l bin/ConnectorDB.php`, `git diff --check`, the TimeOffset API/selection tests, and `php tests/run-all.php unit` on the test PBX.

Expected: the full unit suite reports eight passed files and zero failed files.

- [ ] **Step 6: Commit**

```bash
git add bin/ConnectorDB.php tests/unit/test-api-tasks.php
git commit -m "fix: preserve task crmId across partial updates"
```
