# TimeOffset=0 Uses PBX Time

## Problem

The dialing-window implementation currently interprets `TimeOffset=0` as UTC. The required API contract treats zero the same as an empty, null, or omitted offset: `timeStart` and `timeEnd` must be evaluated in the PBX local timezone. This prevents task `1006517` from dialing at 12:12 UTC+5 because its 08:05 start is incorrectly treated as 08:05 UTC (13:05 PBX time).

## Contract

- `TimeOffset=0`, `""`, `null`, or an omitted field uses PBX local time.
- A non-zero positive or negative `TimeOffset` remains the recipient's UTC offset in hours.
- Ordinary, overnight, inclusive-boundary, and all-day window behavior remains unchanged.
- Existing database rows with `timeOffsetMinutes=0` acquire the corrected behavior without a data migration.
- `timeOffsetMinutes=NULL` and `timeOffsetMinutes=0` remain distinguishable in stored/API data but are equivalent for dialing-window evaluation.

## Implementation

Change `DialingWindow` at the point where it calculates the recipient minute of day: both `null` and integer zero select PBX local time; non-zero minute offsets use UTC plus the stored offset. Keep ingestion and storage unchanged so no mass update of the client's 1.6-GB SQLite database is required.

Update public and internal documentation that currently describes zero as explicit UTC.

## Tests

Use TDD to change the dialing-window expectation first and observe the current implementation fail. Cover:

- zero uses the supplied PBX timezone;
- zero permits task `1006517`'s 08:05–21:55 window at 12:12 UTC+5;
- positive, negative, fractional, null, empty, and overnight behavior remains intact;
- candidate selection uses the corrected zero behavior.

Run the local dialing-window, candidate-selector, and storage-contract tests, followed by the full integration suite on the test PBX.

## Client recovery

After deploying the fixed module, restart/re-enable it so `ConnectorDB`, `WorkerDialer`, and `WorkerAMI` load the new code. Do not update `m_TaskResults`: existing zero values are intentionally supported. Confirm task state is `0`, verify all three workers are running, and call `getSliceTask` to confirm a candidate is selected during the PBX-local window.
