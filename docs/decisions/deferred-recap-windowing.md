---
title: Deferred recap dispatch, window-gated
description: Weekly/monthly recap rows stage Pending on ingest; the LLM narration is deferred to a scheduled command that fires once the period closes.
tags: [decision, ai]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/AnalysisCadence.php
  - app/Console/Commands/AI/WeeklyRecapCommand.php
  - app/Console/Commands/AI/MonthlyRecapCommand.php
  - app/Http/Controllers/Api/AnalysisController.php
  - routes/console.php
---

# Deferred recap dispatch, window-gated

**Status:** Accepted (documented 2026-06-20)

## Context

A weekly or monthly recap describes a whole period. But activities trickle in across that period (each Strava ingest fires the post-run cascade). If the recap narrated on every ingest, the *same* recap would be re-billed several times per week as runs landed — and any narration produced mid-window would describe an incomplete period. We needed the recap to bill once, on final data, after the window closes.

## Decision

We decided to **stage the recap row on ingest but defer its LLM narration to a scheduled command**, gated on the period being closed:

- On ingest, [`AnalysisService::requestDeferred`](app/Services/AI/AnalysisService.php) upserts the WeeklyRecap / MonthlyRecap row as `Pending` (a `firstOrCreate`) without dispatching, filling, or invalidating. This is the windowed-cadence path; [AnalysisCadence](app/Services/AI/AnalysisCadence.php) marks these `Weekly` / `Monthly`.
- The single billed narration comes from a scheduled command. [WeeklyRecapCommand](app/Console/Commands/AI/WeeklyRecapCommand.php) (`ai:weekly-recap`) and [MonthlyRecapCommand](app/Console/Commands/AI/MonthlyRecapCommand.php) (`ai:monthly-recap`) narrate every completed period whose recap is not yet `Done`, oldest first. Both cap at the latest **fully-closed** period (`RecapPeriod::lastClosedWeekEnding()` / `lastClosedMonth()`), so the still-running current period is never narrated on incomplete data.
- Schedule ([routes/console.php](routes/console.php)): `ai:weekly-recap` runs `weeklyOn(1, '00:01')` (Monday 00:01); `ai:monthly-recap` runs `monthlyOn(1, '05:45')` (1st of month).
- On-demand narration of the still-open current period is also blocked: [AnalysisController](app/Http/Controllers/Api/AnalysisController.php) `isStillOpenRecapPeriod()` returns the inert row unchanged for a recap whose week/month hasn't closed (and the UI hides the trigger for it).

## Consequences

- **Enables:** one recap, one bill, on final data — predictable LLM cost regardless of how many runs landed in the window.
- **Costs:** a just-closed period's recap isn't instant; it waits for the next scheduled tick (Monday 00:01 / 1st 05:45).
- **Gotchas:** a `Pending` recap row for the **open** week/month is **not backlog** — dispatch is window-gated, so `Pending` is the normal "recap incoming" signal. The anomaly to watch for is `pending: 0` (a missing staged row), not a lingering `Pending`. Don't treat these rows as a stuck queue.

## See also

- [[ai-pipeline]] — the narrator/analysis pipeline this windowing sits in
- [[chained-narration]] — the recap rows are also the links of the connected weekly/monthly chains
