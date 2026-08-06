# Task update and upsert fix

## Goal

Make partial task updates preserve an omitted `crmId`, return a complete REST result, and allow a subsequent POST with the original `crmId` to update the same task and its phone list.

## Partial-update semantics

`changeTask()` must distinguish an omitted `crmId` from an explicitly supplied value:

- when updating an existing task and `crmId` is omitted, retain the stored `crmId`;
- when `crmId` is supplied, trim and apply it;
- an explicitly empty `crmId` retains existing behavior: after save, assign the task's internal ID as its `crmId`;
- when creating a task without `crmId`, assign the internal ID as before.

Lookup behavior remains unchanged: a non-empty supplied `crmId` identifies the task; otherwise a supplied task ID identifies it. POST upsert therefore finds the original task after a name-only PUT because that PUT no longer destroys its external ID.

## IPC response contract

Methods executed inside `ConnectorDB` may return either arrays or `PBXApiResult` objects. Before replying through Beanstalk, `onEvents()` must convert a `PBXApiResult` into its `getResult()` array. Existing array results pass through unchanged.

`ConnectorDB::invoke()` continues to expose arrays to callers. Internal direct calls, such as `addTask()` calling `changeTask()`, continue to receive the `PBXApiResult` object and therefore require no refactor.

This normalization belongs at the IPC boundary rather than in only `putTaskAction()`, ensuring every future worker method receives the same response handling.

## Error and compatibility behavior

- Missing tasks continue to return `TaskNotFound`.
- Invalid JSON handling and HTTP routing remain unchanged.
- Explicit `crmId` changes remain supported.
- Task creation, number replacement, transaction rollback, and deletion behavior remain unchanged.
- The fix must not alter per-number `TimeOffset` behavior.

## Tests

Extend the task API regression scenario to assert the complete sequence:

1. POST creates a closed task with a unique external `crmId` and three numbers.
2. PUT containing only `name` returns `result=true`.
3. GET confirms both the new name and the original `crmId`.
4. POST with the same original `crmId` returns the original task ID.
5. GET confirms the number list was replaced with the requested two numbers.
6. Cleanup deletes the task and verifies no duplicate with that `crmId` remains.

Add a focused IPC regression assertion proving a `PBXApiResult` returned by a worker handler reaches `ConnectorDB::invoke()` as the standard result array.
