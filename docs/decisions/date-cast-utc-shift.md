---
title: Date columns cast as `date:Y-m-d`, not `date`
description: Pin date-only columns to a plain YYYY-MM-DD JSON format so the frontend doesn't read the day off-by-one
tags: [decision, data]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Models/WeeklySnapshot.php
  - tests/Unit/Models/WeeklySnapshotTest.php
---

# Date columns cast as `date:Y-m-d`, not `date`

**Status:** Accepted (documented 2026-06-20)

## Context

The app runs under `Asia/Jakarta` (UTC+7). Laravel's plain `date` cast produces a `Carbon` at local midnight, which serializes to JSON as a UTC-shifted instant: `2026-06-14` becomes `2026-06-13T17:00:00.000000Z`. The frontend then takes `.slice(0, 10)` of that string and reads `2026-06-13` — the **previous day**. For `week_ending`, that off-by-one mis-grouped the weekly recap.

UTC-based tests are blind to this: at UTC the local midnight and the serialized instant fall on the same calendar day, so a plain `date` cast looks correct in CI unless the test forces a non-UTC timezone.

## Decision

We decided to cast date-only columns with an explicit **`date:Y-m-d`** format so the JSON carries a plain `YYYY-MM-DD` with no time or `Z` suffix, independent of timezone. The only model that holds a date-only column today applies it: [`WeeklySnapshot::casts()` casts `week_ending` to `date:Y-m-d`](../../app/Models/WeeklySnapshot.php).

The explicit format is the guard, not the test environment. [`WeeklySnapshotTest`](../../tests/Unit/Models/WeeklySnapshotTest.php) pins it with a case that flips both `app.timezone` and the PHP default timezone to `Asia/Jakarta`, then asserts the serialized `week_ending` equals `2026-06-14` and contains neither `T` nor `Z`.

## Consequences

- The frontend's `.slice(0, 10)` reads the correct day under any server timezone.
- Any **new** date-only column must repeat `date:Y-m-d`; a bare `date` cast will silently regress and pass under UTC CI. The timezone-flipping test is the regression net.
- This is for date-*only* columns. Genuine timestamps (`created_at`, `synced_at`) keep `datetime`, where the UTC instant is the correct serialization.

## See also

- [[data-model]]
