---
title: The race block opens on a computed date, inside one continuous season
description: A race arc runs the general cycle until a block fixed by distance (16 weeks up to the half, 20 beyond, race week included) opens, and block open is a date the plan computes, never a season boundary.
tags: [decision, run, plan, gamification]
status: accepted
reviewed: 2026-09-17
code_refs:
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/SeasonSummaryBuilder.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/PlanPageAssembler.php
  - app/Services/Gamification/SeasonGamificationContext.php
  - app/Services/Gamification/SeasonGoalResolver.php
  - app/Services/Gamification/SeasonStreakSummaryBuilder.php
  - app/Models/Season.php
  - resources/js/components/plan/SeasonHeaderCard.tsx
---

# The race block opens on a computed date, inside one continuous season

**Status:** Accepted (2026-09-17)

## Context

[PhaseSchedule::forRace()](app/Services/Run/Plan/PhaseSchedule.php) used to periodize the whole span from the season's start to race day. A 10K set 30 weeks out got eight Base weeks, fourteen Build weeks and eight Peak weeks, and every one of those Build weeks compounded the ramp until the 1.4 cap from [[a-goalless-arc-does-not-ramp]] caught it. [TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php)'s race floor then divided the race distance by that capped peak. Specific fitness lasts roughly 8 to 12 weeks. That is a property of the race, not the athlete, so a block months long builds fitness that has faded by race day.

The first design, grilled on 2026-09-11, made the season *be* the block: the `Season` row would span block open to race day and be created when the block opened. That was reverted on 2026-09-15. The owner wants race goals on the card from the day the race is set, not from a date months later.

## Decision

**The block length is fixed by distance, and the general phase takes whatever time is left.** Up to the marathon threshold `PhaseSchedule` already uses for tapers (25 km, so the half included), the block is 16 weeks long. Past it, the block is 20 weeks. Both counts include race week, so the block is always exactly the rows the phase ribbon shows: [PhaseSchedule::blockOpensOn()](app/Services/Run/Plan/PhaseSchedule.php) returns race week's Monday minus 15 or 19 weeks. The athlete's volume never moves the block. It moves only how long the general phase runs, which follows from when the race was set.

**Block open is a computed date, never a row boundary.** [SeasonService::ensureCurrent()](app/Services/Run/Plan/SeasonService.php) still opens one season from goal-set to race day, and `starts_at`, `ends_at` and the frozen anchor from [[the-arc-is-anchored-once]] are unchanged. Nothing about the season row is created, closed or moved at block open.

**The arc has two zones.** `forRace()` composes the weeks from the season's start to block open as the `selfScaled()` cycle, which holds at 1.0 with deload dips to 0.65. Each of those weeks carries `zone: general`. From `max(season start, block open)` to race day it runs the race periodization over the block alone, and each week carries `zone: block`. The base/build/peak/taper split, the recovery-week cadence and the ramp with its 1.4 cap all count from the block's first week. `volumeMultipliers()` takes the zones, so generation, the season summary and goal sizing all compute the same curve. When block open falls on or before the season's start, the whole span is the block and the arc is the same as before this decision. A race set twelve weeks out is that case.

**Rejected: the season is the block.** Opening the row at block open would leave a race athlete with no season during the general phase. They would have no goals, no season card and, since the race floor reads the season, no floor. A second season would also have to take over mid-plan, and that handover would need to rebuild the anchor that [[the-arc-is-anchored-once]] freezes.

## Consequences

**Gamification: goals attach in two steps.**
- *At creation*, a race season writes its general goals: sessions completed, rest days honoured, quality sessions, and a readiness goal. The readiness goal reuses `season_longest_long_run_km` with a target of 12 km for a 10K, 18 km for a half and 30 km for a marathon or longer, each capped by the athlete's own `long_run_cap_km`.
- *On the first `ensureCurrent()` on or after block open*, the block goals are appended once, checked by metric, and `seasons.block_goals_appended_at` is stamped. `ensureCurrent()` reads that stamp before touching the database, so once the block goals exist the check costs no queries on later reads. They are the race margin goal and `season_peak_weekly_km`. The target of `season_peak_weekly_km` is the biggest block week's planned km, summed by [SeasonSummaryBuilder::plannedWeeks()](app/Services/Run/Plan/SeasonSummaryBuilder.php). Its current value is the biggest [WeeklySnapshot](app/Models/WeeklySnapshot.php) week ending inside the season.
- A race set inside its block gets both steps in the same call, and its season row is written already stamped.
- Goal-less seasons are unchanged: five goals at creation, including CTL growth.

**Under-ready line.** When the weeks from `max(season start, block open)` through race week, counted inclusively, are fewer than the full block, the season card says so once. That count is the number of block rows the ribbon shows, spelled out in fixed copy, for example "Twelve weeks is tighter than I'd pick for this one, so we build what we can and race what we've built." A full block says nothing, whatever the athlete's long run. There is no Analysis row and no LLM call: `seasons.under_ready_noted_at` is stamped as the line is served. Only the Plan page serves it, through [PlanPageAssembler](app/Services/Run/Plan/PlanPageAssembler.php). Profile and Trends read the same season payload and must not use the line up.

**Summaries.** Each week from `SeasonSummaryBuilder::build()` carries its `zone`, and the season payload carries `block_opens_on`, so the phase ribbon can draw the two zones.

**`TrainingBaseline`'s floor** divides the race distance by the block's biggest Build or Peak multiplier only. A general week holds flat and never counts as ramp.

**Costs and limits.**
- A race set far out now prescribes flat volume until the block opens. That is intended: the growth before then comes from the baseline following real volume.
- The general weeks still pick session types in race mode. Only their volume is general.
- [PlanRenderer](app/Services/Run/Plan/PlanRenderer.php)'s fallback for rows written before multipliers were stored has no zones, so it keeps the old whole-arc curve for those rows.
- Only two athletes exist in production, so nothing is backfilled. The next regeneration writes the new multipliers.

## See also

- [[the-arc-is-anchored-once]]: the season row whose shape this keeps
- [[a-goalless-arc-does-not-ramp]]: the flat cycle the general zone reuses, and the ramp cap the block keeps
- [[plan-periodizer]]
- [[gamification]]
