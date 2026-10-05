---
title: Supported time history from daily trend snapshots
description: Each daily trend snapshot records the supported time at the race active that day, with its race and source effort, and Trends draws the current race's season from those rows as "supported over time".
tags: [decision, run, trends]
status: accepted
reviewed: 2026-10-05
code_refs:
  - app/Services/Run/Trend/TrendSnapshotWriter.php
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Models/TrendDailySnapshot.php
  - database/migrations/2026_10_05_000600_add_supported_time_to_trend_daily_snapshots_table.php
  - app/Http/Controllers/TrendsController.php
  - resources/js/components/trends/SupportedOverTime.tsx
  - app/Console/Commands/Run/TrendSnapshotCommand.php
  - database/seeders/Demo/DemoRunSeeder.php
---

# Supported time history from daily trend snapshots

**Status:** Accepted (2026-10-05). Decision #1803, layer 7 of #1804.

## Context

The Race page and Trends show the supported time as of today ([[supported-race-time-from-recent-efforts]]). An athlete could not see how it had moved across the season, or which effort moved it. That needs a history of the supported time, which nothing stored.

## Decision

1. **The history lives on the daily trend snapshot.** Each `trend_daily_snapshots` row gains the supported time at the race active that day, the race goal it was computed for, and the source effort's distance and date ([migration](database/migrations/2026_10_05_000600_add_supported_time_to_trend_daily_snapshots_table.php)). [TrendSnapshotWriter](app/Services/Run/Trend/TrendSnapshotWriter.php#L56) resolves the race as of each date through [VdotEstimator::raceAsOf()](app/Services/Run/Metrics/VdotEstimator.php#L488), the same rule the estimator uses for its race distance, and reads the time from [RaceAmbitionAssessor](app/Services/Run/Plan/RaceAmbitionAssessor.php), so a row agrees with what the Race page showed that day. All four columns are null without an active race or without a supported time (a race beyond the marathon). Every path that writes snapshots (ingest repair, nightly recovery, `trend:snapshot-daily --days=N`, the coaching reset, `demo:seed`) fills them. There is no new event table and no derivation at request time.
2. **The window is the current race's season.** [TrendsController::supportedHistory()](app/Http/Controllers/TrendsController.php#L92) serves only rows taken for the active race goal, from its season's start to today, so a new race starts a new line.
3. **Placement and shape.** A panel directly after "vs race day", [SupportedOverTime](resources/js/components/trends/SupportedOverTime.tsx#L156): the eyebrow "supported over time", a headline with the current supported time and its change since the first point ("3:15 faster since aug 3" in leaf-ink, "… slower since …" in ember-ink), and a stepped Chart.js line in leaf with faster up, beside the target as a dashed ink-3 line labelled "your target m:ss".
4. **Step labels name a new effort only.** A point is labelled "10K · oct 1" in leaf-ink only when its source effort differs from the day before's (`new_source`); a refit of the fall-off or an effort ageing into stale moves the line without a label ([stepLabels()](resources/js/components/trends/SupportedOverTime.tsx#L45)).
5. **When it shows.** It needs an active race with a supported time and at least two days of that race's snapshots; otherwise the panel is absent.

## Research basis

None needed. The panel displays the existing supported time and introduces no coaching number.

## Consequences

- Rollout backfills the current season's past days with `trend:snapshot-daily --days=N`, which rewrites closed days for every user ([TrendSnapshotCommand](app/Console/Commands/Run/TrendSnapshotCommand.php#L44)).
- Today's row is written when a run is ingested; on a rest day the line ends yesterday until the nightly recovery writes it.
- A race set today shows the panel from its second snapshot.
- `demo:seed` writes the demo season's snapshots ([DemoRunSeeder](database/seeders/Demo/DemoRunSeeder.php#L497)) with no model call. A freshly seeded demo's season starts that day, so its panel appears once a second day is recorded.

## See also

- [[trends]], [[race-projection]], [[supported-race-time-from-recent-efforts]]
