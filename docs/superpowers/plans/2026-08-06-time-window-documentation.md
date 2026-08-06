# Time-window Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide consistent user, test, and maintainer documentation for per-recipient dialing windows and corrected task update/upsert semantics.

**Architecture:** Keep the main README conceptual and API-oriented, make `tests/curl-examples.md` the executable cookbook, describe test ownership in `tests/README.md`, and reserve implementation details for `CLAUDE.md`. Cross-check the same field semantics across all four files.

**Tech Stack:** Markdown, shell/curl examples, JSON request payloads, PHP test-script inventory.

## Global Constraints

- External input is `TimeOffset`; persisted output is `timeOffsetMinutes`.
- Numeric `0` means UTC; empty, `null`, or omitted means PBX-local time.
- Supported offsets span UTC-12:00 through UTC+14:00 and may be fractional by whole minutes.
- `480..1320` means 08:00-22:00; `1320..360` means 22:00-06:00.
- `timeCallAllow` remains an additional PBX-timezone absolute restriction.
- Active `state=0` examples carry real-call safety warnings.
- Partial PUT preserves omitted `crmId`; repeat POST by `crmId` updates the same task.

---

### Task 1: Update user and curl documentation

**Files:**
- Modify: `README.md`
- Modify: `tests/curl-examples.md`

**Interfaces:**
- Produces: conceptual API contract in `README.md`.
- Produces: copyable manual verification scenarios in `tests/curl-examples.md` using `$API`.

- [ ] **Step 1: Add the complete time-window contract to README**

Cover ordinary, all-day, inclusive, and overnight windows; all offset input forms; `timeCallAllow`; non-blocking candidate selection; active-call behavior; and a mixed-offset request with GET/PUT/DELETE verification.

- [ ] **Step 2: Clarify PUT/upsert behavior in README**

State that omitted fields, especially `crmId`, remain unchanged during partial PUT and that repeat POST with the same external ID updates the same task and replaces its number list.

- [ ] **Step 3: Expand executable curl scenarios**

Provide UTC+5, UTC-4, UTC, PBX-local, mixed-offset, overnight, and `timeCallAllow` payloads. Include `jq` inspection of `timeOffsetMinutes`, safe task pausing/deletion, and expected results.

### Task 2: Update test and maintainer documentation

**Files:**
- Modify: `tests/README.md`
- Modify: `CLAUDE.md`

**Interfaces:**
- Produces: current test inventory and execution matrix.
- Produces: implementation-level storage, selection, and IPC/update notes.

- [ ] **Step 1: Document all current time-window tests**

List the five new scripts, identify which run locally and which require PBX services, add individual commands, and explain safe nonexistent-extension selection tests and automatic cleanup.

- [ ] **Step 2: Document internal architecture**

Add `TaskResults.timeOffsetMinutes`, `DialingWindow`, `DialingCandidateSelector`, selection ordering, `timeCallAllow`, busy-client behavior, presence-aware PUT, and IPC `PBXApiResult` normalization.

- [ ] **Step 3: Cross-check and validate documentation**

Run searches for `TimeOffset`, `timeOffsetMinutes`, `timeStart`, `timeEnd`, and `crmId`; run `git diff --check`; extract curl `-d` JSON blocks where practical and validate them with `jq` or PHP `json_decode`; verify every documented test filename exists.

- [ ] **Step 4: Commit**

```bash
git add README.md tests/curl-examples.md tests/README.md CLAUDE.md
git commit -m "docs: expand dialing time-window guidance"
```
