---
title: Prescribed volume anchors on a trimmed weekly mean, not the longest run
description: The long run is derived as a share of a robust weekly volume and capped by race distance and time on feet, rather than being read off the single longest run in the trailing 28 days.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-06
code_refs:
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Metrics/Readiness.php
  - app/Services/Run/Plan/SeasonService.php
---

# Prescribed volume anchors on a trimmed weekly mean, not the longest run

**Status:** Accepted (2026-09-06)

## Context

The plan prescribed roughly **59 km/week to a runner averaging 22 km/week**, and told them they were overreaching.

[TrainingBaseline::forUser()](app/Services/Run/Plan/TrainingBaseline.php) set `long_run_km` to the **single longest run in the trailing 28 days**, and [SegmentGenerator::coreKmFor()](app/Services/Run/Plan/SegmentGenerator.php#L92) scales *every* session off that one scalar — Long 1.0x, Tempo 0.65x, Easy 0.65x/0.40x, Interval 0.40x — with [PhaseSchedule](app/Services/Run/Plan/PhaseSchedule.php) compounding a Build ramp on top. Nothing else determined volume.

For the reporting athlete that scalar was **24 km**, from one run on 2026-08-16 that they confirm was a one-off event. Their real shape over 12 weeks was 45 runs averaging 6.6 km, with 53% of all running between 4 and 6 km. The anchor was a singleton: 60% longer than the next-longest run and roughly 4x the mean.

**The honest number was already computed and thrown away.** The same method produced `weekly_volume_km` (25.55 for this athlete) and returned it — and a repo-wide grep found **no consumer anywhere**. The plan was anchored on the outlier while the representative figure sat beside it, unused.

This is *not* downstream of [[summary-first-ingest]]: `TrainingBaseline` reads `weekly_snapshots.distance_km` and `activity_details.distance`, both of which summary ingest writes. Volume is exact across un-hydrated history.

### What this is not

The first diagnosis blamed the null load curve — `ctl_42d` of 5.0 with 52 of 57 weeks carrying a null `weekly_trimp`. That was wrong in both directions. The periodizer never reads load for volume, and it handles null load correctly: [PlanAdapter::strainIsExcessive()](app/Services/Run/Plan/PlanAdapter.php#L131) *disables* the deload when CTL is null or below 10 rather than firing it, honouring [[unscored-load-is-null-not-zero]]. The plan was over-prescribing, not under-.

## Decision

**Weekly volume drives the long run, not the other way round.**

- **`weekly_volume_km` becomes robust and becomes consumed**: a trimmed mean over **six** weeks — drop the highest and lowest, average the rest — so neither one 44.7 km week nor one injured week moves the anchor. A trimmed mean still tracks a genuine ramp where a median lags. Under three weeks there is nothing to trim and the plain mean stands; with no weeks at all the cold-start seed does.
- **The long-run share rises as volume falls**: 0.35 under 30 km/week, 0.30 from 30 to 60, 0.25 above. Daniels caps a long run at 25% of weekly mileage and Pfitzinger at 25-30%, but both ranges assume higher mileage than a beginner runs — 25% of 20 km/week is not a long run at all.
- **Two ceilings, tighter wins.** A race-distance **band table**, because the long-run to race-distance ratio *inverts* with distance (a 5K long run is 2-3x race distance, a marathon's is 0.7-0.85x) so a single multiplier is wrong at both ends. And **150 minutes of easy running**, because the real limit is time on feet: at 7:30/km that 24 km run was three hours, over the ceiling regardless of race distance. The time cap is skipped when the athlete has no VDOT, leaving the band alone.
- **Cold-start seeds drop** to `[3, 8.0] / [4, 14.0] / [5, 22.0]`. A self-declared "experienced" label should not license 35 km/week with zero evidence.

**No data gate.** A coach takes a history, starts conservative, and adjusts after two to four weeks under the 10% rule — they do not refuse to plan. Volume evidence needs no hydration, so anyone importing a Strava history is correctly served from day one by the anchor alone, and a true beginner gets a low seed plus the existing 7.5%/week Build ramp, already inside the 10% rule.

**Absence of data stops reading as fatigue.** [Readiness](app/Services/Run/Metrics/Readiness.php) capped the ceiling at `ModerateOk` whenever `form_status` was not `fresh` or `optimal` — including when it was **null**. An athlete whose runs carry no heart rate has no CTL or ATL at all, so that was every day, forever: [ReadinessClamp](app/Services/Run/Plan/ReadinessClamp.php) downgraded their tempo and interval sessions to easy on every render, and the plan stored quality sessions they were never shown. `fatigued` and `overreaching` are capped harder earlier, so that arm now only guards an unrecognised value.

## Consequences

- **Enables:** a prescription that describes the athlete rather than their biggest day. For the reporting athlete: a 6-week trimmed mean of ~22.75 km gives a **7.7-8 km** long run and a ~19-24 km week, against ~59 km before. Neither cap binds for them; both exist for the athletes where they do.
- **Costs:** an athlete who genuinely ramps via one big weekend run now sees a lower prescription than their peak day suggests. That is the intent — the 10% rule and the Build ramp are how volume grows, not a single long effort.
- **Watch for:** anyone "simplifying" the band table back to a multiplier, or the trimmed mean back to `avg()`. Both are the bug.
- `weekly_volume_km` now has a consumer, so a future change to it is no longer free.

## See also

- [[plan-periodizer]] — where the baseline feeds the week
- [[unscored-load-is-null-not-zero]] — the rule the readiness cap was violating
- [[summary-first-ingest]] — explicitly *not* the cause here
