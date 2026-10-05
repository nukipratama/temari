---
title: A race ambition is shown, and the supported effort is prescribed
description: The target time stays on the race exactly as stated; the plan prescribes the effort the athlete's confirmed fitness supports, and an unsupported target never becomes a pace or a reason to add quality.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-01
code_refs:
  - app/Services/Run/Plan/RaceAmbitionAssessor.php
  - app/Services/Run/Plan/RaceAmbition.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Metrics/VdotEstimator.php
  - tests/Feature/Plan/RaceCapacityPlanTest.php
---

> **Partly superseded (2026-10-02) by [[one-race-model-drives-the-plan]].** The 3% and 6% bands now apply only when the qualifying evidence covers at least half the race distance; otherwise the state is `low_evidence`. The behind-pace arm no longer exists. The rest of this decision stands.

> **Partly superseded (2026-10-05) by [[supported-race-time-from-recent-efforts]].** The supported VDOT now comes from whole-run hard efforts of the last 16 weeks, confirmed or not, read at the race distance with the athlete's own fall-off; unconfirmed rises are capped at about 1 VDOT a week. The payload also names the effort it rests on (`basis`) and whether to ask for a confirmed one (`confirm_nudge`).

# A race ambition is shown, and the supported effort is prescribed

**Status:** Accepted (2026-10-01).

## Context

Race day was prescribed at `goal_time_sec / distance`, and a projection slower than the goal made [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php) add a quality session each week. A 50 minute 10K asked of an athlete with a 70 minute 10K therefore produced a 5:00/km race day and a plan chasing it.

## Decision

- **Compare pace with supported pace.** [RaceAmbitionAssessor](app/Services/Run/Plan/RaceAmbitionAssessor.php) solves the race time the athlete's current VDOT supports at the race distance ([VdotEstimator::raceTimeForVdot()](app/Services/Run/Metrics/VdotEstimator.php)) and bands the target against it: within 3% faster (or slower) is `on_track`, 3 to 6% faster `ambitious`, over 6% faster `unsupported`. No evidence, or a race beyond the marathon, is `unknown`.
- **The target is never rewritten.** The race keeps the stated `goal_time_sec`; the payload carries the state, both times and paces, the gap and the `prescribed_time_sec` as stable fields (`race.ambition`).
- **The plan prescribes the supported effort.** Race-day pace, marathon-class race context and the adapter all read `prescribed_time_sec`, which is the target unless it is unsupported. An unsupported ambition is excluded from the behind-pace arm, so it cannot force extra quality or a catch-up block. Volume stays on the athlete's own baseline and workload budget.
- The 3% and 6% bands are a calibration (constants on the assessor), adjustable without a schema change.

## See also

- [[race-projection]], [[plan-periodizer]], [[no-automatic-mileage-debt]]
