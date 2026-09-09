---
title: The arc is anchored once, at its start
description: A season freezes its starting weekly volume and its start week; every prescription derives from a week's position in that stored arc, rather than rebuilding the arc from today each Monday.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-09
code_refs:
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Models/Season.php
  - app/Models/PlannedSession.php
  - tests/Feature/Plan/AnchoredArcTest.php
---

# The arc is anchored once, at its start

**Status:** Accepted (2026-09-09)

## Context

[[plan-volume-anchors-on-weekly-mean]] fixed *how big* a week is. This is about *which week the athlete is standing in* — and the answer was always "the first one".

Two independent restarts caused it.

**Generation rebuilt the arc from today.** [Periodizer::regenerate()](app/Services/Run/Plan/Periodizer.php) called `PhaseSchedule::forRace($today, ...)`, which returns the arc counted from the *current* week. Every Monday the arc was recomputed with one fewer week in it, so the week being trained was index 0 every single time. [PhaseSchedule::volumeMultipliers()](app/Services/Run/Plan/PhaseSchedule.php) counts Build weeks from index 0, which makes index 0 exactly `1.0` in Base, Build, Peak-after-no-Build and Deload-after-no-Build alike. The scheduled recovery week [[the-plan-follows-the-coaching]] added sits at ramp index 3 — always three weeks in the future, never reached.

**Render restarted it again.** [PlanController](app/Http/Controllers/PlanController.php) and [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) recomputed the multiplier from the phases of a window reaching three weeks back, via [PlanRenderer::weekPhasesAndMultipliers()](app/Services/Run/Plan/PlanRenderer.php). Three weeks of history cannot tell you how far into a twelve-week season a week sits.

**And the size of the week chased its own output.** [TrainingBaseline::forUser()](app/Services/Run/Plan/TrainingBaseline.php) re-read the trailing six-week mean on every call, so a week the athlete missed lowered the volume they were prescribed next — a plan that shrinks toward whatever you last managed rather than toward the race.

Simulated end to end against a real athlete (prod snapshot 2026-09-08: four sessions a week, 26.05 km trimmed weekly mean, 10K on 2026-10-31), eight Mondays from 2026-09-07:

| Week | Phase trained (before) | Multiplier (before) | Phase trained (after) | Multiplier (after) |
| --- | --- | --- | --- | --- |
| 2026-09-07 | Base | 1.0 | Base | 1.0 |
| 2026-09-14 | Base | 1.0 | Base | 1.0 |
| 2026-09-21 | Base | 1.0 | Build | 1.0 |
| 2026-09-28 | Base | 1.0 | **Deload** | **0.65** |
| 2026-10-05 | Base | 1.0 | Build | 1.075 |
| 2026-10-12 | Build | 1.0 | Peak | 0.989 |
| 2026-10-19 | Taper | 0.368 | Peak | 0.989 |
| 2026-10-26 | Taper | 0.368 | Taper | 0.396 |

Five Base weeks, one Build week and a two-week taper for a 10K, against an arc that is meant to be two Base, three Build with a recovery week inside them, two Peak and one Taper. The ADR that introduced the trimmed mean claims it "still tracks a genuine ramp"; nothing was ramping.

## Decision

**A season owns its arc, and the arc is fixed when the season opens.**

- **`Season` stores where the arc starts.** `starts_at` was already the row's origin; it now also carries `anchor_weekly_volume_km`, the trailing weekly mean as it stood at that moment ([SeasonService::ensureCurrent()](app/Services/Run/Plan/SeasonService.php)). `TrainingBaseline::forUser()` reads the anchor of the season covering its `asOf` date and falls back to the live trailing mean only when there is none — so generation, render and a late compliance verdict all size the same week identically.
- **Generation builds the whole arc, then slices** ([Periodizer::sliceFromCurrentWeek()](app/Services/Run/Plan/Periodizer.php)). Multipliers are computed across the full season before the horizon's worth starting at the current week is taken, so the week being trained carries the multiplier its own position earns. The reactive deload is applied *before* the multipliers, so a week the adapter turns down also stops counting as a Build week for the weeks after it.
- **The multiplier is stamped on the row** (`planned_sessions.volume_multiplier`). It is the one number generation persists, because it is the one number render cannot re-derive: it describes position in a season, not fitness. Everything fitness-shaped — the long-run baseline, paces, segment structure — still enters fresh at render, exactly as [[a-session-is-the-whole-outing]] and the readiness clamp ([[readiness-clamp-is-advisory]]) require. `PlanRenderer` falls back to the old phase-sequence recompute for any window still holding a row written before the column existed.
- **The anchor moves downward only, and only on a collapse.** `SeasonService::reanchorIfCollapsed()` re-anchors when the trailing mean has fallen more than 25% below the stored value — an injury, a layoff, a month of travel. It never re-anchors upward: a rising trailing mean is the ramp working, and re-anchoring to it would compound the ramp on top of its own output, which is the bug the anchor exists to prevent. A trimmed six-week mean needs a sustained collapse to move 25%, so a single down week never trips it.
- **A replan does not reset the arc.** A manual regeneration, a page load and the weekly job all reach `ensureCurrent()` and leave `starts_at` and the anchor alone. Only a race being set, changed or cleared opens a new season, which is the one event that legitimately redraws the arc — consistent with [[the-plan-knows-its-race-day]] and with [[a-day-is-scored-when-it-is-run]], which needs a past day to keep the prescription it was actually given.

## Consequences

- **Enables:** the periodizer's own arc is the one the athlete trains. Build ramps, the scheduled recovery week arrives, Peak holds and the taper is one week for a 10K instead of two. `SeasonSummaryBuilder`'s season-wide preview and the trained plan now describe the same weeks, which they never did before.
- **Costs:** one stored column on `planned_sessions` in a subsystem that had made "nothing is stored on the row" a rule. The rule survives with a stated exception — arc position is not fitness. A self-scaled arc also materializes only to the end of its own season, so the four-week lookahead shrinks in the season's last weeks and refills when it rolls over.
- **Watch for:** anyone passing `$today` back into `PhaseSchedule::forRace()`/`selfScaled()` — the parameter is named `$arcStart` precisely so that reads wrong. And anyone making the re-anchor symmetric "for consistency": the asymmetry is the decision.
- The trailing mean now has exactly two jobs — set the anchor, and detect a collapse — so a change to it is no longer free in either direction.

## See also

- [[plan-volume-anchors-on-weekly-mean]] — what the anchor is a mean *of*
- [[the-plan-follows-the-coaching]] — the scheduled recovery week that was never reached
- [[plan-periodizer]] — where the arc feeds the week
