---
title: Training-Load Metrics Engine
description: How per-run TRIMP rolls up into CTL/ATL fitness, fatigue, form, strain and monotony, and how weekly snapshots stay correct when a backdated run arrives
tags: [architecture, run]
status: living
reviewed: 2026-07-07
code_refs:
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Run/Metrics/WeeklyAggregator.php
  - app/Models/WeeklySnapshot.php
  - app/Services/Run/Ingest/ActivityPipeline.php
---

# Training-Load Metrics Engine

This is the engine behind the dashboard's training-load read-out and the [[run-history]] weekly trend: it turns a runner's heart-rate effort into a small set of training-load numbers — **fitness (CTL)**, **fatigue (ATL)**, **form**, **strain** and **monotony** — and keeps a per-week snapshot of them. Two classes do the work: [TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php) is the pure math, and [WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php) persists it per week.

## TRIMP: the unit of training stress

Everything is built on **Edwards TRIMP** — a single number for how hard one run was. The [[run-ingest-pipeline]] buckets a run's heart rate into HR zones (see [[stream-analysis]]) and hands the minutes-per-zone to [edwardsTrimp](app/Services/Run/Metrics/TrainingLoad.php#L32), which weights each zone by its intensity (the higher the zone, the heavier the weight; see [zoneWeight](app/Services/Run/Metrics/TrainingLoad.php#L316)) and sums them. The result is written onto the activity at [ActivityPipeline](app/Services/Run/Ingest/ActivityPipeline.php#L258) as `trimp_edwards`, so each run carries its own stress score.

Because the weights and the zone math depend on the runner's HR zones, recomputing with new zones (see [[settings-hr-zones]]) re-derives TRIMP without re-fetching from Strava — that's what [recomputeSummary](app/Services/Run/Ingest/ActivityPipeline.php#L427) does.

## CTL / ATL: fitness and fatigue as decaying averages

Fitness and fatigue are two **exponentially-weighted moving averages (EWMA)** of daily TRIMP, differing only in how fast they forget. Fatigue (ATL) uses a short time constant so it reacts to the last few days; fitness (CTL) uses a long one so it tracks months of consistent work (both time constants live in [TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php#L16)). The roll happens in [rollDailySeries](app/Services/Run/Metrics/TrainingLoad.php#L255): it walks day-by-day from the first day of history through the as-of date, decaying both averages and adding that day's TRIMP, keeping every day's pair along the way. [rollLoads](app/Services/Run/Metrics/TrainingLoad.php#L240) is a thin wrapper that keeps only the final day's pair, for the dashboard's single-point summary. **Missing days contribute zero** — a rest day bleeds off fatigue faster than it bleeds off fitness, which is exactly the desired behaviour. A day whose runs carried no HR is missing here too, which understates both averages across an unscored stretch rather than voiding them; that trade-off is [[unscored-load-is-null-not-zero]].

**Form** is simply fitness minus fatigue ([summaryFromDailyMap](app/Services/Run/Metrics/TrainingLoad.php#L78)): positive means fresh/rested, negative means carrying fatigue. [formStatus](app/Services/Run/Metrics/TrainingLoad.php#L212) labels it (`fresh` / `optimal` / `fatigued` / `overreaching`) against a threshold that scales with fitness, so the same raw form number means different things for a beginner and a high-volume runner.

### Why the ~365-day lookback (and why it's "converged")

An EWMA has no fixed window — every past day technically contributes. Naively that means scanning a runner's entire multi-year history on every page load. The optimisation: an EWMA decays geometrically, so after enough days the oldest contributions are vanishingly small and the result is **indistinguishable from full history**. The lookback cap ([CONVERGED_LOOKBACK_DAYS](app/Services/Run/Metrics/TrainingLoad.php#L27)) is chosen to be past that convergence point for the long (CTL) time constant — far enough that the EWMA has reached steady state, so the bounded query in [loadDailyHistory](app/Services/Run/Metrics/TrainingLoad.php#L110) returns the same answer as an unbounded one while staying O(year) instead of O(history). The rationale (with the convergence margin) is in the constant's docblock.

This is **not** the same as a 365-day window: a true window would zero out a continuous series and yield a too-low, window-dependent CTL. The cap is a lower bound on lookback, not a windowing of the average — see the [rollLoads](app/Services/Run/Metrics/TrainingLoad.php#L240) docblock.

## Strain and monotony: the shape of a week

The remaining two numbers describe the *distribution* of load across the last 7 days, computed in [weekStats](app/Services/Run/Metrics/TrainingLoad.php#L285). **Monotony** is the week's mean daily TRIMP over its standard deviation — high when every day looks the same (a known injury-risk pattern), capped to avoid a divide-by-zero on a perfectly uniform week. **Strain** is the week's total TRIMP scaled by monotony, so a high-volume week that's also samey scores worse than the same volume spread out. These feed the dashboard's Strain / Monotony hints.

`weekStats` takes a **run-day set** alongside the daily TRIMP map, because those two numbers plus `weekly_trimp` are the ones a zero would lie about. A week nobody ran scores an honest `0.0`; a week whose runs all lacked heart rate returns `null`, since "no reading" is a different fact from "did nothing" — see [[unscored-load-is-null-not-zero]]. Both maps come out of one query in [loadDailyHistory](app/Services/Run/Metrics/TrainingLoad.php#L110), or off the already-loaded detail set in [dailyHistory](app/Services/Run/Metrics/WeeklyAggregator.php#L223), so they cannot drift.

## Weekly snapshots and forward propagation

[WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php) persists the engine's output one row per ISO week into [WeeklySnapshot](app/Models/WeeklySnapshot.php) (week keyed by its Sunday `week_ending`; see [[data-model]]). Each week is an idempotent **upsert** keyed by `(user_id, week_ending)` in [upsertWeek](app/Services/Run/Metrics/WeeklyAggregator.php#L172), which slices that week's runs for the volume columns and asks [TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php) for the load columns.

Two subtleties:

- **Converged lead-in.** To roll a correct CTL for any given week, the aggregator first loads a long lead-in of history before that week ([leadInStart](app/Services/Run/Metrics/WeeklyAggregator.php#L79), sized by the same converged-lookback constant), then rolls the EWMA forward through the week. A short warm-up window would produce a too-low, window-dependent CTL.
- **In-progress week.** For the current (unfinished) week, load is measured as-of *today*, not the future Sunday, so days that haven't happened yet aren't zero-filled and don't understate current fitness ([upsertWeek](app/Services/Run/Metrics/WeeklyAggregator.php#L165) `loadAsOf`; the `summaryFromDailyMap` signature separates the week anchor from the load anchor).

### Backdated runs propagate forward

CTL is **cumulative**: a run inserted into a past week changes the fitness baseline of *every* later week too. So ingest doesn't just rebuild that one week — [rebuildForwardFrom](app/Services/Run/Metrics/WeeklyAggregator.php#L68) rebuilds the affected week and every week through today, loading one shared lead-in series and re-rolling each week's snapshot from it in a single query. This is what [recomputeSummary](app/Services/Run/Ingest/ActivityPipeline.php#L427) calls after a run's TRIMP changes. A full from-scratch backfill is [rebuildFor](app/Services/Run/Metrics/WeeklyAggregator.php#L130).

## Where the numbers surface

- The dashboard's live read-out comes from [summary](app/Services/Run/Metrics/TrainingLoad.php#L53) (computed as-of today, not from a snapshot) — see [[dashboard]].
- [ctlTrend](app/Services/Run/Metrics/TrainingLoad.php#L156) slices the tail of the same `rollDailySeries` roll into a 90-day `[date, atl, ctl]` list, for the fitness trend chart on [[race-projection]]. No new storage — every day the loop already computes.
- [strainMonotonyTrend](app/Services/Run/Metrics/TrainingLoad.php#L188) does the same for `weekStats` — one call per day across the range instead of once for "this week" — for the Trends tab's strain/monotony chart. Same "expose what the loop already computes" shape as `ctlTrend`, no new metric math.
- The weekly trend, streaks ([consecutiveWeekStreak](app/Models/WeeklySnapshot.php#L82)) and recap narration read [WeeklySnapshot](app/Models/WeeklySnapshot.php) rows — see [[run-history]], [[recaps]], and the records that hang off weekly bests in [[records]].
- The **progression chart** (per-distance weekly-best time series on `/records`) is built by [ProgressionSeriesBuilder](app/Services/Run/ProgressionSeriesBuilder.php), which queries `ActivityDetail` for weekly-best km-scaled times across 5K/10K/HM/FM.
- **Lifetime stats** (total km, runs, longest run on `/aku`) come from [LifetimeStats](app/Services/Run/LifetimeStats.php), which runs a single aggregate query over `ActivityDetail`.
- TRIMP per run also feeds the run's own story and mood ([[vibe-and-mood]], [[run-detail]]).
