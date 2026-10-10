---
title: Trial weeks and eased taper days sit outside the volume floor
description: The volume floor holds on every race block week except time-trial weeks and taper days the intensity step eases; both reductions are deliberate and cost 0.37–0.50 km a week on the measured profiles.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-10
code_refs:
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Plan/TimeTrialSchedule.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - tests/Feature/Plan/VolumeFloorGuardTest.php
---

# Trial weeks and eased taper days sit outside the volume floor

**Status:** Accepted (2026-10-10). Extends [[the-volume-floor-solves-the-weeks-the-plan-lays-out]], which already keeps time trials out of the solve. Issue #2049.

## Context

[TrainingBaseline::volumeFloorKm()](app/Services/Run/Plan/TrainingBaseline.php) lays the block out with `weekLayout()` and solves for the baseline at which its weeks average the season's `volume_floor_km`. The plan then makes two reductions that the solve does not lay out:

- **Time-trial weeks.** [TimeTrialSchedule::forWeek()](app/Services/Run/Plan/TimeTrialSchedule.php) schedules a trial, the intensity step puts it in place of the week's first session still prescribed as quality, and the trial day is the trial distance alone ([[a-time-trial-every-six-weeks]]), shorter than the Tempo or Interval it replaces.
- **Eased taper days.** [Periodizer](app/Services/Run/Plan/Periodizer.php)'s intensity step (`withIntensityPrescriptions()`) turns a short taper quality day Easy through either of two checks: the minimum-quality fit, when the outing cannot safely fit the minimum quality structure, and the hard-minute ceiling, when the minutes left under the week's 30% hard-time budget fall below the minimum [IntensityPrescriptionResolver::resolve()](app/Services/Run/Plan/IntensityPrescriptionResolver.php) accepts as meaningful quality.

Measured on the stored plan against the season summary's prediction (km a week, block mean):

| Profile | Floor | Predicted | Rendered | Under the floor | Trials | Eased taper days |
|---|---|---|---|---|---|---|
| `VolumeFloorGuardTest` race profile (no VDOT, so no trials) | 25.91 | 25.95 | 25.95 | none | 0 | 0 |
| Tilted 10K at 45 km a week | 45.00 | 45.33 | 44.63 | 0.37 (0.8%) | −0.55 | −0.15 |
| Demo athlete at 2026-10-09 | 38.58 | 38.74 | 38.15 | 0.43 (1.1%) | −0.59 | 0 |
| Demo athlete at 2026-05-12 | 39.42 | 39.56 | 38.92 | 0.50 (1.3%) | −0.64 | 0 |

On every profile, each week with no trial and no eased day rendered exactly its predicted km.

## Decision

1. The volume floor holds on every race block week except time-trial weeks and taper days the intensity step eases. Those weeks may render under the solve's figure, and the block mean may sit under the floor by what they take off.
2. Both reductions are deliberate. A trial wants fresh legs, so its week runs lighter, and the taper keeps a safe intensity budget rather than padding a short day to hold volume.
3. The solve does not model them. Modelling them exactly would mean extracting the trial placement and the intensity step from `Periodizer` and reading past trials, all evidence and recent prescriptions inside [TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php): up to three more queries per page against a Profile budget with one query of headroom. A simplified second model would drift from the plan, and that drift is what [[the-volume-floor-solves-the-weeks-the-plan-lays-out]] removed.

## Consequences

- On stored rows, "never below habit" holds for every block week without a trial or an eased day. A block with trials or eased taper days can average 0.8–1.3% under its floor (0.37–0.50 km a week on the measured profiles).
- `VolumeFloorGuardTest` asserts that each block week with no trial and no eased day renders its predicted km and that those weeks average at least the floor. A change to the layout that breaks this fails there.
- If the trial schedule or the intensity step ever takes off much more than this, the cost of modelling them should be measured again.

## See also

- [[the-volume-floor-solves-the-weeks-the-plan-lays-out]]
- [[a-race-block-never-prescribes-below-habit]]
- [[a-time-trial-every-six-weeks]]
- [[plan-periodizer]]
