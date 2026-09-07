---
title: The plan knows its race day
description: Race day becomes its own session type, the days around it are recovery, and the race distance is stamped on the row so it survives the goal being retired.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-07
code_refs:
  - app/Enums/SessionType.php
  - app/Services/Run/Plan/WeekPlanBuilder.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Models/PlannedSession.php
---

# The plan knows its race day

**Status:** Accepted (2026-09-07)

## Context

The athlete's race date reached [PhaseSchedule::forRace()](app/Services/Run/Plan/PhaseSchedule.php)
and nowhere else, where it was used only to count how many weeks the arc had left. It never
reached [WeekPlanBuilder](app/Services/Run/Plan/WeekPlanBuilder.php), so the week the goal race
fell in was laid out as an ordinary tapered template week. Walking a shrinking plan range turned
this up; simulating four race weeks made it plain:

| Race | What the plan prescribed |
|---|---|
| Sunday marathon | a long run the day before it; `rest` on the day itself |
| Tuesday 10K | a `tempo` session ON the race |
| Thursday 10K | `easy` on the race |
| Saturday half | `long` on the race, correct only by coincidence |

Two harms followed from it. An athlete who correctly skipped the marathon-eve long run was
scored `missed` for tapering properly. And one who raced on a day prescribed as `rest` had
`plannedKm = 0`, so [SessionMatcher::scoreFor()](app/Services/Run/Plan/SessionMatcher.php)
recorded their goal race as a rest day they "ran anyway".

Nothing in the suite could see any of this: every session type was legal on every day, so
every assertion passed. It took simulating a real race week and reading the output.

## Decision

**Race day is its own `SessionType`.** `Race` joins the enum rather than the race borrowing
`Long` or `Tempo`. A race is not a training session that happens to be hard — it is the thing
the training was for, it is sized from the goal rather than from the athlete's baseline, and
it is the one day the plan must not quietly reshape.

**Race week is shaped around the race.** `WeekPlanBuilder::raceWeekType()` overrides the day
template for the week the race falls in: race day is the race, the day before it is rest, and
so is every day after it.

Resting the remainder of race week is deliberately conservative. A race arc *ends* with race
week, and the following Monday opens a fresh block, so the only days at stake are the few
between the race and that Monday. Under-prescribing there is benign; prescribing training in
the days immediately after a goal race — which is what the old layout did — is not. A
distance-scaled return to running was considered and rejected as a rule with no one to serve:
it would only ever apply to a mid-week race, and only until the next regeneration.

**The race distance lives on the row.** `planned_sessions.race_distance_m` is stamped when the
row is written. Reading it back from the `RaceGoal` at render time would not work:
`plan:close-finished-races` retires the goal at 00:02, *before* `plan:score-compliance` grades
race day at all, so the goal is already gone by the time the distance is needed. The row is
self-describing instead, which also keeps a past race in the athlete's history rendering
correctly long after the goal behind it was superseded.

**The readiness clamp never touches a race.** `Race` sits at the same required rank as `Rest`,
so no ceiling reaches it. The clamp is advisory (see [[readiness-clamp-is-advisory]]), and the
fatigue it reads on race morning is a taper's worth of load the athlete is *meant* to be
carrying into the start line. Talking someone out of the race they trained months for is not
advice this system gets to give.

**The race is never redistributed.** [VolumeRedistributor](app/Services/Run/Plan/VolumeRedistributor.php)
spreads a week's missed volume across the days that remain. The race is whatever distance it
is, so it contributes nothing to the week's redistributable target and is never scaled itself
— what redistributes is the training around it.

## Consequences

- A goal race now renders as a race: the chequered flag, "race day", and the race distance.
- Race day is graded against the race distance, so racing it well scores as `done` rather than
  as a rest day run anyway.
- A completed race counts as a quality session in
  [SeasonGamificationContext](app/Services/Gamification/SeasonGamificationContext.php). Before
  this it did so by accident, because race day was typed `tempo`; without the change the new
  type would have silently stopped counting it.
- `SegmentGenerator::generate()` now carries the race *distance* rather than the
  marathon-or-not boolean derived from it. Same parameter count, strictly more information,
  and every pace decision that used the flag still derives it.
