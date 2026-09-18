---
title: A race block never prescribes below habit, and the load guard outranks that
description: A race block averages at least the athlete's recent actual weekly mean, climbs to its readiness long run and holds it until the taper, keeps its last recovery week off the last Build week, derives its long-run goal from the plan, and gives way to a load deload that it records.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-18
code_refs:
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PlanPageAssembler.php
  - app/Models/Season.php
  - app/Models/PlanAdaptation.php
---

# A race block never prescribes below habit, and the load guard outranks that

**Status:** Accepted (2026-09-18)

## Context

The #1000 audit simulated a real athlete's 12-week 10K block and found four problems, all pointing the same way (#1013):

- **The block prescribed less than the athlete already ran.** It averaged 22.05 km/week, against a 25.91 km actual twelve-week mean. The six-week trimmed anchor was 24.4 km, and two recovery weeks, a Peak held at 0.92 of Build and a single taper dragged the mean below even that.
- **The second recovery week landed on the last Build week.** [PhaseSchedule](app/Services/Run/Plan/PhaseSchedule.php) put one on every fourth ramp week, and index 7 of that block was the ramp's last. The block went deload, then Peak.
- **The long run peaked at 10.1 km five weeks out,** then dropped to 9.2 km for the three Peak weeks.
- **The season goal asked for a 12 km long run that the plan never prescribed.** The goal read a fixed readiness table ([[the-block-opens-on-a-computed-date]]), and the plan read the volume share.

Each of these has a defensible cautious reading for an athlete whose biggest week the app flagged `overreaching`. The owner decided them together, including how they interact.

## Decision

**1. Volume floor.** A race season freezes the athlete's plain twelve-week actual mean at creation as `seasons.volume_floor_km`. [TrainingBaseline::volumeFloorKm()](app/Services/Run/Plan/TrainingBaseline.php) solves for the long-run baseline at which the block's weeks, laid out by `WeekPlanBuilder` and sized by `SegmentGenerator::coreKmFor()`, average that floor. Every session scales linearly off the baseline except race day, so one division solves it. It is a floor on the block's mean, not on every week: recovery and taper weeks sit under it, and Build and Peak carry the difference. A season opened before this decision is backfilled from the weeks before its start. After a collapse, the floor comes down with the anchor, because the weeks it averaged no longer describe the athlete. The half-the-week long-run cap is sized off the bigger of the anchor and the floor, because the floor is the week the plan now prescribes. The race-distance band and the 150-minute time cap still bind. A goal-less season has no floor.

**2. The last recovery week moves off the last Build week.** A scheduled recovery week that would land on the ramp's last week moves one week earlier, so a Build week always sits between it and Peak. The ramp still counts Build weeks. In a twelve-week 10K block that is still three of them, so the realised ceiling stays at 1.1556. The volume comes from the floor, not from this move.

**3. The long run climbs or holds until the taper.** Peak holds the Build ramp's level instead of dropping to 0.92 of it, and the taper cuts from that level. A recovery week, scheduled or reactive, still dips, as it always has.

**4. The long-run goal is the plan's, and the plan reaches the readiness distance.** The readiness table (10K 12 km, half 18 km, marathon 30 km) moved from the goal generator into `TrainingBaseline` as a long-run floor. Bounded by the athlete's own cap, it is divided by the block's biggest Build or Peak multiplier, the same way the race-distance floor is, so the athlete gets there by running the ramp. It now applies to a marathon too, where the cap is what binds. [SeasonService::generateGoals()](app/Services/Run/Plan/SeasonService.php) sets `season_longest_long_run_km` to the longest long run the season's own arc prescribes, for race and goal-less seasons alike. A goal the plan never schedules a session for can no longer be generated.

**5. The load guard outranks all four.** Nothing above touches [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php): an overreaching form, excess monotony or strain, or a missed week still deloads the current week, and the floor is a target the guard is allowed to break. When a deload turns a week of a floored season down, [Periodizer](app/Services/Run/Plan/Periodizer.php) records the floor it set aside on the week's `PlanAdaptation` row as `volume_floor_km`. The Plan tab's adaptation detail then ends with one plain sentence naming that floor. There is no narration change and no LLM call. A taper week the adapter leaves alone records nothing.

## Consequences

For the audited athlete and the same twelve-week block, the plan now averages 26.83 km/week, where it averaged 22.05 km. The long run climbs 10.4, 11.2 and 12.0 km and holds 12.0 through Peak. The biggest week is 32.4 km, and the long-run goal is 12 km, which the plan prescribes four times.

- **Week one rises.** The floor lifts the whole block, so the first week sits above the six-week anchor: 28.2 km against 24.4 for the audited athlete. That is 9% above their twelve-week mean. The readiness floor still divides by the ramp's peak, so the first long run is a starting point rather than the target itself, but a short block has little ramp to divide by.
- **A short marathon block reaches its cap early.** With almost no ramp to divide by, the 30 km readiness target bounded by half the week becomes the long run from the first week.
- **The guard is only as sensitive as its thresholds.** Replaying the audited athlete's own flagged week into the current week reads `fatigued` against today's fitness, not `overreaching`, so it clamps today's session through [[readiness-clamp-is-advisory]] but does not deload the week. Only at 1.25 times that load does the form reach `overreaching`, and then the plan deloads and says so.
- The floor still gives way to the race-distance band and the time-on-feet cap, and that shortfall is not recorded.

## See also

- [[plan-volume-anchors-on-weekly-mean]]: the anchor the floor sits beside
- [[the-plan-follows-the-coaching]]: the recovery-week cadence the second rule amends
- [[the-block-opens-on-a-computed-date]]: the readiness goal the fourth rule replaces
- [[plan-periodizer]]
