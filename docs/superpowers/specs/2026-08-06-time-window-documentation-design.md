# Time-window documentation update

## Goal

Document per-recipient dialing windows consistently for API users, test operators, and module maintainers. Also document the corrected partial PUT/upsert behavior that affects task-management examples.

## Documentation structure

### `README.md`

Add a focused section next to task creation that explains:

- `timeStart` and `timeEnd` are task-level minutes from local midnight;
- when a number has `TimeOffset`, the task window is evaluated in that recipient's UTC-offset clock;
- positive, negative, zero, empty, `null`, and omitted offset values;
- fractional offsets are accepted when they resolve to whole minutes and the range is UTC-12:00 through UTC+14:00;
- ordinary, inclusive-boundary, all-day, and midnight-crossing windows;
- `timeCallAllow` is an additional absolute restriction interpreted in the PBX timezone;
- an ineligible number does not block a later eligible number;
- already-started calls are not terminated at `timeEnd`;
- active tasks (`state=0`) may create real calls immediately.

Include one complete mixed-offset POST example and compact GET/PUT/DELETE verification commands. Explain that name-only or state-only PUT preserves an omitted `crmId`, and repeat POST with the same `crmId` updates the same task and replaces its number list.

### `tests/curl-examples.md`

Expand the working-hours examples into executable scenarios for UTC+5, UTC-4, UTC, PBX-local fallback, mixed offsets, midnight crossing, and `timeCallAllow`. Include response inspection with `jq`, expected `timeOffsetMinutes` values, and safe cleanup commands.

Use conspicuous placeholder numbers and warn that `state=0` starts real processing. Commands must use the existing `$API` convention.

### `tests/README.md`

Add all new unit/integration scripts to the test inventory:

- `test-dialing-window.php`;
- `test-dialing-candidate-selector.php`;
- `test-time-offset-storage-contract.php`;
- `test-time-offset-api.php`;
- `test-time-offset-selection.php`.

Describe what each proves, distinguish locally runnable pure tests from PBX-dependent API/selection tests, and provide individual SSH commands for the server tests. State that selection tests use a nonexistent extension to avoid a real call and clean up their tasks.

### `CLAUDE.md`

Update maintainer documentation with:

- nullable `TaskResults.timeOffsetMinutes` storage (`NULL` means PBX local; `0` means UTC);
- `DialingWindow` and `DialingCandidateSelector` responsibilities;
- candidate-selection behavior and interaction with `timeCallAllow` and busy-client locking;
- presence-aware partial PUT semantics for `crmId`;
- IPC normalization of `PBXApiResult` to arrays;
- the complete current test list and commands.

## Consistency rules

- The external request field is always written as `TimeOffset`.
- The persisted field is always written as `timeOffsetMinutes`.
- Examples use `state=0` only with an explicit safety warning.
- `0` must never be described as PBX-local fallback; it means UTC.
- Empty string, `null`, and omission mean PBX-local time.
- `timeStart=480` and `timeEnd=1320` are consistently described as 08:00-22:00.
- Midnight-crossing behavior uses `1320..360` as 22:00-06:00.
- Documentation must not imply that `timeEnd` terminates an active call.

## Verification

After editing, search all four files for the relevant field names and compare descriptions. Run Markdown-oriented whitespace checks with `git diff --check`, validate every JSON example used inside curl payloads where practical, and ensure commands reference existing endpoints and test filenames.
