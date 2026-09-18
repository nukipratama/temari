---
title: Deferred recap dispatch, window-gated
description: Weekly/monthly recap rows stage Pending on ingest; the LLM narration is deferred to a scheduled command that fires once the period closes.
tags: [decision, ai]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Console/Commands/AI/WeeklyRecapCommand.php
  - app/Console/Commands/AI/MonthlyRecapCommand.php
  - app/Http/Controllers/Api/AnalysisController.php
  - routes/console.php
---

# Deferred recap dispatch, window-gated

**Status:** Accepted (documented 2026-06-20)

> **2026-09-06 — the scheduled tick is no longer the only trigger.** A first Strava connect now chains [KickoffRecapsJob](app/Jobs/AI/KickoffRecapsJob.php) behind the history backfill, running the same weekly + monthly kickoff for that one user. The decision below is unchanged: the kickoff still caps at the latest fully-closed period, still passes `invalidate: false`, and the scheduled commands and the job now share one implementation ([KickoffWeeklyRecaps](app/Actions/AI/KickoffWeeklyRecaps.php) / [KickoffMonthlyRecaps](app/Actions/AI/KickoffMonthlyRecaps.php)). What changed is only *when* a new user's first bill lands: on connect rather than after a 7 to 31 day wait.

> **2026-09-10 — `AnalysisCadence` removed.** The enum cited below no longer exists (it had no production callers). The windowed-cadence path it labelled is unchanged; see [[llm-triggers]] for how each type's dispatch origin is actually determined.

> **2026-09-18 — a new athlete's kickoff only reaches periods that closed after they connected.**
> #993: [KickoffWeeklyRecaps](app/Actions/AI/KickoffWeeklyRecaps.php) /
> [KickoffMonthlyRecaps](app/Actions/AI/KickoffMonthlyRecaps.php) used to narrate every completed
> week and month back to the 84-day `BackfillAgeGate` cutoff regardless of when the athlete
> connected, so a three-month backfill narrated roughly twelve weekly and three monthly recaps on
> day one — periods Temari never watched. Both kickoffs now also route a period whose close (the
> week's `week_ending`, or the month's last day) fell before
> [`HydrationBacklog::connectedAt()`](app/Services/AI/HydrationBacklog.php) — reading
> `strava_connections.created_at`, the same anchor [[recap-waits-for-hydration]] and
> [[history-narrates-on-demand]] already use — to `AnalysisService::requestRuleBased()` alongside
> the too-old bucket, bypassing the hydration wait entirely: a pre-connect period is filled
> rule-based up front and never left `Pending`. This matches the shape #989 gives per-run
> narration and the rule #922 applies on return. The demo account is unaffected: it never reaches
> either kickoff's `RecentlyActiveUsers` query.
>
> **2026-09-18 (later the same day) — that bypass was wrong for the weekly half, corrected by
> #1010.** "A pre-connect period needs no real numbers to fill rule-based" was false: the weekly
> rule-based closer reads the snapshot's own `form_status` (fresh/optimal/fatigued/overreaching),
> exactly the field [[recap-waits-for-hydration]] exists to protect. Bypassing that gate for
> `requestRuleBased` meant a full-history backfill's kickoff — which runs in the same breath as the
> backfill, before `strava:hydrate-backlog` has touched a single run — read every week's
> `form_status` as null and closed all of them with the same generic fallback line, permanently
> (`Done`, never revisited). Measured on the real account this decision was about: **58 of 58**
> weekly recaps. [KickoffWeeklyRecaps](app/Actions/AI/KickoffWeeklyRecaps.php) now runs its
> too-old and pre-connect rule-based buckets through
> [RecapHydrationReadiness](app/Services/AI/RecapHydrationReadiness.php) too, same as the LLM
> bucket already did — still zero LLM spend for a pre-connect period (that part of this decision
> stands), just no longer read before the pipeline finishes writing it. A deferred rule-based week
> is picked up by the same hourly `ai:catch-up` sweep the LLM path already relied on, so this costs
> the same "up to one hourly sweep" [[recap-waits-for-hydration]] already prices in, not a new
> unbounded wait. **The monthly half is unchanged and still bypasses hydration entirely** — it was
> out of scope for #1010 and is not known to read a field with the same race (flagged, not fixed).

## Context

A weekly or monthly recap describes a whole period. But activities trickle in across that period (each Strava ingest fires the post-run cascade). If the recap narrated on every ingest, the *same* recap would be re-billed several times per week as runs landed — and any narration produced mid-window would describe an incomplete period. We needed the recap to bill once, on final data, after the window closes.

## Decision

We decided to **stage the recap row on ingest but defer its LLM narration to a scheduled command**, gated on the period being closed:

- On ingest, [`AnalysisService::requestDeferred`](app/Services/AI/AnalysisService.php) upserts the WeeklyRecap / MonthlyRecap row as `Pending` (a `firstOrCreate`) without dispatching, filling, or invalidating. This is the windowed-cadence path; `AnalysisCadence` marked these `Weekly` / `Monthly`.
- The single billed narration comes from a scheduled command. [WeeklyRecapCommand](app/Console/Commands/AI/WeeklyRecapCommand.php) (`ai:weekly-recap`) and [MonthlyRecapCommand](app/Console/Commands/AI/MonthlyRecapCommand.php) (`ai:monthly-recap`) narrate every completed period whose recap is not yet `Done`, oldest first. Both cap at the latest **fully-closed** period (`RecapPeriod::lastClosedWeekEnding()` / `lastClosedMonth()`), so the still-running current period is never narrated on incomplete data.
- Schedule ([routes/console.php](routes/console.php)): `ai:weekly-recap` runs `weeklyOn(1, '00:16')` (Monday 00:16); `ai:monthly-recap` runs `monthlyOn(1, '05:45')` (1st of month).
- On-demand narration of the still-open current period is also blocked: [`AnalysisService::isStillOpenRecapPeriod`](app/Services/AI/AnalysisService.php) makes [AnalysisController](app/Http/Controllers/Api/AnalysisController.php) return the inert row unchanged for a recap whose week/month hasn't closed (and the UI hides the trigger for it).

## Consequences

- **Enables:** one recap, one bill, on final data — predictable LLM cost regardless of how many runs landed in the window.
- **Costs:** a just-closed period's recap isn't instant; it waits for the next scheduled tick (Monday 00:16 / 1st 05:45).
- **Gotchas:** a `Pending` recap row for the **open** week/month is **not backlog** — dispatch is window-gated, so `Pending` is the normal "recap incoming" signal. The anomaly to watch for is `pending: 0` (a missing staged row), not a lingering `Pending`. Don't treat these rows as a stuck queue.

## See also

- [[ai-pipeline]] — the narrator/analysis pipeline this windowing sits in
- [[chained-narration]] — the recap rows are also the links of the connected weekly/monthly chains
