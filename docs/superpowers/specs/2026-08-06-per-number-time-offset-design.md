# Per-number time-zone-aware dialing window

## Goal

Apply a task's `timeStart`/`timeEnd` dialing window in each recipient's local time. The request supplies an optional `TimeOffset` for every entry in `numbers`.

## Input contract

`TimeOffset` is the recipient's offset from UTC in hours:

- positive values represent zones east of UTC (`5` means UTC+05:00);
- negative values represent zones west of UTC (`-4` means UTC-04:00);
- numeric zero, including the string `"0"`, explicitly means UTC;
- an empty string, `null`, or an omitted field means to use the PBX's local time;
- numeric fractional hours are accepted and converted to minutes, so `5.5` becomes 330 minutes.

The API must reject non-numeric, non-empty values with a clear validation error. Accepted explicit offsets must be within UTC-12:00 through UTC+14:00, inclusive, and resolve to a whole number of minutes.

The public request field remains `TimeOffset` for compatibility with the supplied integration payload. Internally it is normalized to `timeOffsetMinutes`.

## Persistence

Add a nullable integer `timeOffsetMinutes` column to `TaskResults`:

- `NULL` means PBX local time;
- `0` means UTC;
- any other value is an explicit UTC offset in minutes.

The value is stored per phone-number result because recipients in one task may use different time zones. Existing rows receive `NULL`, preserving the current PBX-local-time behavior. Updating an open result updates its offset along with `params`, `clientId`, and `timeCallAllow`.

`TimeOffset` must not be stored only in serialized `params`, because dialing eligibility needs to be evaluated before selecting the next result.

## Eligibility calculation

A result can be selected only when both existing scheduling and the new local-time rule pass:

1. The task is open (`state = 0`).
2. The result is open and waiting in `CreateTask` state.
3. `timeCallAllow` is not later than the current timestamp.
4. The current minute in the recipient's applicable clock falls inside the task window.
5. Existing channel-limit, busy-client, and extension-availability rules pass.

For an explicit offset, calculate the recipient minute of day from the current UTC timestamp plus `timeOffsetMinutes`. This calculation must not depend on the PBX time zone or daylight-saving setting. For `NULL`, calculate the minute of day using the PBX's local clock, retaining existing behavior.

Window rules:

- when `timeStart <= timeEnd`, eligibility is `timeStart <= minute <= timeEnd`;
- when `timeStart > timeEnd`, the window crosses midnight and eligibility is `minute >= timeStart OR minute <= timeEnd`;
- the boundaries remain inclusive;
- the existing default `0..1440` permits dialing throughout the day.

Offset arithmetic must normalize dates in both directions, so negative offsets and day rollover work correctly.

## Selection behavior

The current query chooses the minimum ready result before inspecting per-number data. That order must change: a result outside its local dialing window must not block other eligible numbers in the same task.

Selection must choose the earliest eligible result for each task while preserving the existing task ordering and channel accounting. Alternate-number selection used to avoid simultaneous calls for the same `clientId` must apply the same time-zone eligibility test; it must never substitute a number whose local window is closed.

If no number in a task is currently eligible, the task stays open and the worker creates no call file. It becomes eligible automatically when a recipient's local window opens. A call already started is not terminated when the window closes.

## Compatibility

- Requests without `TimeOffset`, and existing database rows, behave exactly as before using PBX local time.
- `timeStart` and `timeEnd` remain task-level fields expressed as minutes from local midnight.
- `timeCallAllow` remains an absolute timestamp and is combined with, not replaced by, the local-time window.
- Task state semantics are unchanged: `state = 0` is active, `state = 1` is closed, and `state = 2` is paused.

## Validation and diagnostics

API validation errors must identify the affected number and invalid `TimeOffset`. Runtime logging for a skipped number should include the task/result identifiers, normalized offset, recipient minute of day, and configured window at debug/info level suitable for diagnosing scheduling behavior without logging unrelated personal parameters.

## Tests

Add automated coverage for:

- UTC+5, UTC, UTC-4, and PBX-local fallback;
- numeric strings, numeric zero, empty string, omitted value, and invalid input;
- positive and negative day rollover;
- inclusive start/end boundaries;
- a window crossing midnight;
- default `0..1440` behavior;
- `timeCallAllow` combined with the local window;
- selection of a later eligible number when the first number is outside its window;
- busy-client alternate selection respecting the alternate number's window;
- preservation and updating of offsets on repeated submissions with the same task/number;
- migration compatibility for existing rows.

An end-to-end test should submit one task containing numbers with different offsets and verify that only numbers whose recipient-local time falls in the task window advance beyond `CreateTask`.
