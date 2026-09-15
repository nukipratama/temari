---
title: A goal-less arc holds flat, and a race block's ramp is bounded
description: Self-scaled seasons prescribe 1.0x their own anchor with deload dips, and the race-arc build ramp is capped at 1.4, because the baseline already tracks the athlete's real volume.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-16
code_refs:
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/SeasonSummaryBuilder.php
---

# A goal-less arc holds flat, and a race block's ramp is bounded

**Status:** Accepted (2026-09-16)

## Context

`BUILD_WEEKLY_RAMP` in [PhaseSchedule](app/Services/Run/Plan/PhaseSchedule.php) is 1.075 per Build week and compounds with nothing stopping it. Measured across arc lengths for a 10K goal, against an athlete at 26.05 km/week:

| arc | peak multiplier | prescribes from 26 km/week |
|---|---|---|
| 12-week block | 1.16 | 30 km |
| 16-week block | 1.44 | 37 km |
| 20-week block | 1.54 | 40 km |
| 52 weeks out | 3.42 | 89 km |

The self-scaled path — the one every athlete with no race goal is on — was worse, because it ran the same ramp with no race date to terminate it:

| arc | peak multiplier | prescribes from 26 km/week |
|---|---|---|
| 12 weeks general | 1.78 | 46 km |
| 20 weeks general | 2.75 | 72 km |
| 30 weeks general | 4.91 | 128 km |

The multiplier is a function of **position in the arc**. The baseline it multiplies is a function of the athlete's **actual trailing volume**, per [[plan-volume-anchors-on-weekly-mean]]. So a goal-less athlete was prescribed a fixed percentage above what they really run, and that percentage grew every week without limit. Running more raised the baseline, which the ramp then multiplied again: the same growth counted twice.

## Decision

**A self-scaled arc holds at 1.0, with its deload weeks dipping to 0.65.** No ramp at all. All growth comes from the baseline tracking real volume, which it already does, weekly. Without a deadline there is nothing to progressively overload *toward*, and the deload rhythm still gives the arc its shape. Any push belongs in the narration, where the model decides whether this athlete has earned one, not in the engine, where it applies to everybody unconditionally.

**A race arc's ramp is capped so no multiplier exceeds 1.4.** A season is a bounded block of training, and no block asks an athlete for 40% more than its own anchor. This leaves a 12-week block's 1.16 exactly where it was, trims 16 weeks from 1.44 and 20 weeks from 1.54, and stops a long arc compounding into fiction. Peak and Taper derive from the ramp's level, so capping the ramp bounds them too.

`volumeMultipliers()` takes an explicit `$selfScaled` flag rather than inferring the mode from the phase sequence. A self-scaled arc is Build/Deload only, but so is the middle of a race arc, and a short race arc is Taper only — the sequence cannot tell you which mode produced it. Every caller that knows its own mode says so; [PlanRenderer](app/Services/Run/Plan/PlanRenderer.php)'s legacy recompute fallback does not know, and keeps the race-arc curve it always had.

## Consequences

- A goal-less athlete's plan is flat. That is the correct shape: it prescribes what they already do, on a rhythm, and tracks them upward as they actually improve.
- The demo athlete is unaffected, since [DemoRunSeeder](database/seeders/Demo/DemoRunSeeder.php) already seeds a 10K race goal twelve weeks out and therefore runs the race-arc path.
- Long race arcs prescribe less than they did. Combined with the long-run ceilings now binding at prescription time rather than only on the baseline, a 52-week-out 10K no longer asks for a 31 km long run.
- Only two athletes exist in production and the data is a pre-launch fixture, so nothing needed migrating; the next regeneration writes the new multipliers onto every row.
