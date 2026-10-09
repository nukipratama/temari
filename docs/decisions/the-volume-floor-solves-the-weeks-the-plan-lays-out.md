---
title: The volume floor solves the weeks the plan lays out
description: The volume-floor solve lays the block's weeks out with the same inputs the plan uses, the fall-off tilt is volume-neutral, time trials stay out of it, and a held block's training weeks average the floor, each within 10% of it.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-09
code_refs:
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SeasonSummaryBuilder.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/WeekPlanBuilder.php
---

# The volume floor solves the weeks the plan lays out

**Status:** Accepted (2026-10-09)

## Context

[[a-race-floor-solves-under-the-session-caps]] sizes the block's sessions the way the plan does, but it still laid the weeks out without the projected race time that [WeekPlanBuilder::build()](app/Services/Run/Plan/WeekPlanBuilder.php) picks a single quality day by. A race projected between the VO2max and threshold durations gets an Interval in Build, which is shorter than the Tempo the solve assumed. The audited athlete's 10K (#2043) was predicted to average 26.04 km and was stored at 25.08 km, under its 25.91 km floor. [SeasonSummaryBuilder::plannedWeeks()](app/Services/Run/Plan/SeasonSummaryBuilder.php) shared the solve's layout, so the season summary showed the predicted figure.

## Decision

1. [TrainingBaseline::weekLayout()](app/Services/Run/Plan/TrainingBaseline.php) reads, as of the baseline's own date, the inputs beyond phase and session count that [Periodizer](app/Services/Run/Plan/Periodizer.php) lays a week out with: the projected race time, the fall-off tilt, the athlete's run days and long-run day, and whether a two-run week may hold quality. The volume-floor solve, the goal-less solve and `plannedWeeks()` lay their weeks out with it, and [PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php) reads the same method, so generation and the solve cannot drift. The floor's memo is keyed on it, so the floor follows the inputs at each regeneration.
2. The fall-off tilt is volume-neutral, as [[plan-periodizer]] already said it was. The solve lays a tilted Build or Peak week out with its swapped quality day and its 10% longer long run, so the other sessions absorb the difference instead of the block running over its floor. **Heuristic**: a change of emphasis redistributes load at constant weekly volume rather than adding to it ([[coaching-evidence#Gabbett2016]]).
3. Time trials stay out of the solve. [[a-time-trial-every-six-weeks]] already accepts that a trial week's volume moves by the difference between the replaced slot and the trial.
4. While increases are held, the floor is solved over the block's training weeks as a mean: their average matches the floor, and each stays within 10% of it. **Heuristic**: a ±5% excursion keeps the acute:chronic workload ratio near 1.05, inside the 0.8–1.3 band and under the 10% week-to-week increase associated with lower injury risk ([[coaching-evidence#Gabbett2016]]); the ratio's injury association is contested ([[coaching-evidence#Impellizzeri2020]]), so this is a bound, not a target. Capping every week at the floor would put the held block's mean under it, which breaks the parent rule that a race block never prescribes below habit.

## Consequences

- The audited athlete's stored block now averages 25.95 km against the 25.91 km floor, the same figure the season summary shows.
- A held block's weeks no longer sit flat at the floor. The audited athlete's held Base and Peak weeks run 27.30 km and its Build weeks 24.70 km, a 26.0 km mean.
- A refit that moves the projected race time across a quality-type boundary, or the fall-off across a tilt band, moves the floor's baseline at the next regeneration.
- An endurance-tilted athlete's untilted sessions get shorter. A 45 km a week 10K runner's tilted block now renders at the mean the solve lays out, where it ran 2.5 km a week over it.
- A race week on run days the default template does not use keeps the sessions it actually holds in the solve. The audited athlete on Monday, Wednesday, Friday and Sunday renders the 26.13 km mean the solve now lays out, where the solve assumed 25.95 km.
- With a VDOT, Periodizer's intensity step can still turn a short taper quality day easy, which the solve does not model; that shortfall is open.

## See also

- [[a-race-block-never-prescribes-below-habit]]: the floor this solves, and the held-block rule this supersedes
- [[a-race-floor-solves-under-the-session-caps]]
- [[plan-periodizer]]
