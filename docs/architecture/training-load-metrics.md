---
title: Training-Load Metrics Engine
description: How per-run TRIMP rolls up into CTL/ATL long-term and short-term load, load balance, strain and monotony, and how weekly snapshots stay correct when a backdated run arrives
tags: [architecture, run]
status: living
reviewed: 2026-09-28
code_refs:
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Run/Metrics/WeeklyAggregator.php
  - app/Models/WeeklySnapshot.php
  - app/Services/Run/Ingest/ActivityPipeline.php
---

# Training-Load Metrics Engine

This is the engine behind the dashboard's training-load read-out and the [[run-history]] weekly trend: it turns a runner's heart-rate effort into a small set of training-load numbers — **long-term load (CTL)**, **short-term load (ATL)**, **load balance** (stored as `form`), **strain** and **monotony**, all counting running only — and keeps a per-week snapshot of them. Two classes do the work: [TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php) is the pure math, and [WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php) persists it per week.

## TRIMP: the unit of training stress

Everything is built on **Edwards TRIMP** — a single number for how hard one run was. The [[run-ingest-pipeline]] buckets a run's heart rate into HR zones (see [[stream-analysis]]) and hands the minutes-per-zone to [edwardsTrimp](app/Services/Run/Metrics/TrainingLoad.php#L32), which weights each zone by its intensity (the higher the zone, the heavier the weight; see [zoneWeight](app/Services/Run/Metrics/TrainingLoad.php#L485)) and sums them. The result is written onto the activity at [ActivityPipeline](app/Services/Run/Ingest/ActivityPipeline.php#L443) as `trimp_edwards`, so each run carries its own stress score.

Because the weights and the zone math depend on the runner's HR zones, recomputing with new zones (see [[settings-hr-zones]]) re-derives TRIMP without re-fetching from Strava — that's what [recomputeSummary](app/Services/Run/Ingest/ActivityPipeline.php#L472) does.

## CTL / ATL: long-term and short-term load as decaying averages

Long-term and short-term load are two **exponentially-weighted moving averages (EWMA)** of daily TRIMP, differing only in how fast they forget. Short-term load (ATL) uses a short time constant so it reacts to the last few days; long-term load (CTL) uses a long one so it tracks months of consistent running (both time constants live in [TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php#L16)). The roll happens in [rollDailySeries](app/Services/Run/Metrics/TrainingLoad.php#L422): it walks day-by-day from the first day of history through the as-of date, decaying both averages and adding that day's TRIMP, keeping every day's pair along the way. [rollLoads](app/Services/Run/Metrics/TrainingLoad.php#L407) is a thin wrapper that keeps only the final day's pair, for the dashboard's single-point summary. **Missing days contribute zero** — a rest day bleeds off short-term load faster than long-term load, which is exactly the desired behaviour. A day whose runs carried no HR is missing here too, which understates both averages across an unscored stretch rather than voiding them; that trade-off is [[unscored-load-is-null-not-zero]].

A load date before the first scored day returns a null summary, even when the shared history map includes later heart-rate runs. Full and forward weekly rebuilds therefore leave pre-HR ATL, CTL, form and form status unknown; a later reading cannot turn an earlier unmeasured week into a steady one. After the first scored day, missing days still decay the existing averages.

**Load balance** is long-term load minus short-term load ([summaryFromDailyMap](app/Services/Run/Metrics/TrainingLoad.php#L78)); the stored number is still called `form`. [formStatus](app/Services/Run/Metrics/TrainingLoad.php#L361) classifies it into four stored states (`fresh` / `optimal` / `fatigued` / `overreaching`) against a threshold that rises continuously with long-term load, so the same raw number means different things for a beginner and a high-volume runner, and more load can never cross a band edge into a fresher label. **The athlete sees three states, not four**: [TrainingFormStatus::loadBalance()](app/Services/Run/Metrics/TrainingFormStatus.php) maps `fresh` to fresh, `optimal` to steady, and `fatigued` and `overreaching` both to heavy ([LoadBalance](app/Services/Run/Metrics/LoadBalance.php)). The mapping is presentation only: the DB `form_status` column, the bands, the readiness logic and the vibe matrix keep the four stored values, and the UI ([resources/js/lib/formStatus.ts](resources/js/lib/formStatus.ts)) and every LLM-facing tool and prompt use the three. Load balance compares running load only, so no copy calls it fitness, fatigue, readiness or overreaching; a heavy balance names illness, poor sleep and under-fuelling as other possible causes and points the athlete to telling temari how they feel (the optional recovery feedback). **It is unknown for its warm-up**: until 42 days of scored history follow the first scored day, `form_status` is null (the summary carries `form_known_from`), because the EWMA is still climbing from zero and would read every ordinary week as heavy. The label is context, never a verdict: readiness counts it only as supporting load for a reported concern. See [[a-load-label-supports-a-concern-it-never-decides-one]].

### Why the ~365-day lookback (and why it's "converged")

An EWMA has no fixed window — every past day technically contributes. Naively that means scanning a runner's entire multi-year history on every page load. The optimisation: an EWMA decays geometrically, so after enough days the oldest contributions are vanishingly small and the result is **indistinguishable from full history**. The lookback cap ([CONVERGED_LOOKBACK_DAYS](app/Services/Run/Metrics/TrainingLoad.php#L27)) is chosen to be past that convergence point for the long (CTL) time constant — far enough that the EWMA has reached steady state, so the bounded query in [loadDailyHistory](app/Services/Run/Metrics/TrainingLoad.php#L224) returns the same answer as an unbounded one while staying O(year) instead of O(history). The rationale (with the convergence margin) is in the constant's docblock.

This is **not** the same as a 365-day window: a true window would zero out a continuous series and yield a too-low, window-dependent CTL. The cap is a lower bound on lookback, not a windowing of the average — see the [rollLoads](app/Services/Run/Metrics/TrainingLoad.php#L407) docblock.

## Strain and monotony: the shape of a week

The remaining two numbers describe the *distribution* of load across the last 7 days, computed in [weekStats](app/Services/Run/Metrics/TrainingLoad.php#L454). **Monotony** is the week's mean daily TRIMP over its standard deviation — high when every day looks the same, capped to avoid a divide-by-zero on a perfectly uniform week; above 2 means the days looked alike, which an easy or rest day breaks up, and it carries no injury claim. **Strain** is the week's total TRIMP scaled by monotony, so a big week that is also uniform scores higher than the same volume spread out. These feed the dashboard's Strain / Monotony hints. They describe the week and never deload it ([[monotony-and-strain-describe-a-week-they-never-deload-it]]).

`weekStats` takes a **run-day set** alongside the daily TRIMP map, because those two numbers plus `weekly_trimp` are the ones a zero would lie about. A week nobody ran scores an honest `0.0`; a week whose runs all lacked heart rate returns `null`, since "no reading" is a different fact from "did nothing" — see [[unscored-load-is-null-not-zero]]. Both maps come out of one query in [loadDailyHistory](app/Services/Run/Metrics/TrainingLoad.php#L224), or off the already-loaded detail set in [dailyHistory](app/Services/Run/Metrics/WeeklyAggregator.php#L299), so they cannot drift.

## Weekly snapshots and forward propagation

[WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php) persists the engine's output one row per ISO week into [WeeklySnapshot](app/Models/WeeklySnapshot.php) (week keyed by its Sunday `week_ending`; see [[data-model]]). [weekRow](app/Services/Run/Metrics/WeeklyAggregator.php#L250) builds each week's row, slicing that week's runs for the volume columns and asking [TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php) for the load columns. [writeWeeks](app/Services/Run/Metrics/WeeklyAggregator.php#L236) then writes a rebuild's weeks in one idempotent query `upsert()` keyed by `(user_id, week_ending)`, so a row another writer committed after this transaction's read snapshot is updated instead of raising a duplicate-key error. A query upsert skips the model's `saved` hooks, so `writeWeeks` replays both of them: it clears the [ResolveTrailingWeeksAction](app/Actions/Run/Plan/ResolveTrailingWeeksAction.php) memo and marks streak settlement dirty from the earliest week written.

Every rebuild entry point (`rebuildFor`, `rebuildForwardFrom`, `rebuildForWeekOf`) runs under one per-athlete cache lock, `weekly-aggregate:{user}` ([exclusively](app/Services/Run/Metrics/WeeklyAggregator.php#L93)), so the ingest listener, sync summary ingest, delete cleanup, recalibration and the splits rebuild never rewrite one athlete's weeks at the same time. A caller waits up to 20 s for it and then gets a `LockTimeoutException`; the lock expires after 120 s, the recalibration job's timeout. The lock is released when the rebuild returns, not when the caller's transaction commits. While a recalibration holds its own overlap lock, [DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php) skips its weekly rebuild and marks the recalibration dirty instead of waiting, and the recalibration re-runs over the full history after it commits.

The `avg_decoupling` column remains the legacy whole-run average, frozen and no longer read by the UI or narration. The separate `avg_decoupling_v2` column averages version 2 steady-segment readings ([averageSegmentDecoupling](app/Services/Run/Metrics/WeeklyAggregator.php)); it needs **at least two** measured runs, since a one-run mean only describes that run. Both narration and [WeeklyStatLine](resources/js/components/history/WeeklyStatLine.tsx) read this version 2 column, and it stays `null` (hidden, never falling back to legacy) when fewer than two runs carry a comparable segment. Neither column is backfilled from new calculations.

Two subtleties:

- **Converged lead-in.** To roll a correct CTL for any given week, the aggregator first loads a long lead-in of history before that week ([leadInStart](app/Services/Run/Metrics/WeeklyAggregator.php#L159), sized by the same converged-lookback constant), then rolls the EWMA forward through the week. A short warm-up window would produce a too-low, window-dependent CTL.
- **In-progress week.** For the current (unfinished) week, load is measured as-of *today*, not the future Sunday, so days that haven't happened yet aren't zero-filled and don't understate current long-term load ([weekRow](app/Services/Run/Metrics/WeeklyAggregator.php#L250) `loadAsOf`; the `summaryFromDailyMap` signature separates the week anchor from the load anchor).

### Backdated runs propagate forward

CTL is **cumulative**: a run inserted into a past week changes the long-term load baseline of *every* later week too. So ingest doesn't just rebuild that one week — [rebuildForwardFrom](app/Services/Run/Metrics/WeeklyAggregator.php#L125) rebuilds the affected week and every week through today, loading one shared lead-in series and re-rolling each week's snapshot from it in a single query. This is what [recomputeSummary](app/Services/Run/Ingest/ActivityPipeline.php#L472) calls after a run's TRIMP changes. A full from-scratch backfill is [rebuildFor](app/Services/Run/Metrics/WeeklyAggregator.php#L192).

## Where the numbers surface

- The dashboard's live read-out comes from [summary](app/Services/Run/Metrics/TrainingLoad.php#L53) (computed as-of today, not from a snapshot) — see [[dashboard]].
- [ctlTrend](app/Services/Run/Metrics/TrainingLoad.php#L281) slices the tail of the same `rollDailySeries` roll into a 365-day `[date, atl, ctl]` list, for the long-term load chart on `/trends`. No new storage — every day the loop already computes. `/trends` also derives its "a month ago" and "best this year" figures straight off this same series client-side (index `-31` and `Math.max`), rather than a second query — see [[trends]].
- [strainMonotonyTrend](app/Services/Run/Metrics/TrainingLoad.php#L320) does the same for `weekStats` — one call per day across the range instead of once for "this week". It fed the Trends tab's own strain/monotony chart, which `PP3` cut (P25); its one reader now is [TrendRangeTool](app/Services/AI/Agent/Tools/TrendRangeTool.php), which averages it for the trend-read narrator's `avg_monotony`/`avg_strain`.
- The weekly trend, streaks ([consecutiveWeekStreak](app/Models/WeeklySnapshot.php#L102)) and recap narration read [WeeklySnapshot](app/Models/WeeklySnapshot.php) rows — see [[run-history]], [[recaps]], and the records that hang off weekly bests in [[records]].
- The **progression chart** (per-distance weekly-best time series on `/records`) is built by [ProgressionSeriesBuilder](app/Services/Run/ProgressionSeriesBuilder.php), which queries `ActivityDetail` for weekly-best km-scaled times across 5K/10K/HM/FM.
- **Lifetime stats** (total km, runs, longest run on `/aku`) come from [LifetimeStats](app/Services/Run/LifetimeStats.php), which runs a single aggregate query over `ActivityDetail`.
- TRIMP per run also feeds the run's own story and mood ([[vibe-and-mood]], [[run-detail]]).
