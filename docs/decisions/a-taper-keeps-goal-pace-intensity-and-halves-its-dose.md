---
title: A taper keeps goal-pace intensity and halves its dose
description: Taper goal-pace work keeps the last Peak session's pace and rep length at about half its hard minutes, instead of cold-starting 2-minute reps.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-12
code_refs:
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Plan/GoalPaceWork.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - tests/Feature/Plan/GoalPaceWorkPlanTest.php
---

# A taper keeps goal-pace intensity and halves its dose

**Status:** Accepted (2026-10-12). Decision 3 of #2116, layer 3. Partly supersedes rules 1 and 4 of [[goal-pace-work-in-the-last-weeks]] for the Taper dose and rep length of 5K to half goal-pace work.

## Context

Taper goal-pace work cold-started like any first session of its family: two reps at the taper's 2-minute rep length, 4 hard minutes for a 10K, whatever Peak had reached. A taper should cut volume and keep intensity; this one cut both the dose and the rep.

## Decision

1. **Dose.** A Taper goal-pace session takes about half the hard minutes of the last Peak goal-pace session, rounded to whole work units and never below the minimum structure, two reps or a 10-minute block ([IntensityPrescriptionResolver::resolve()](app/Services/Run/Plan/IntensityPrescriptionResolver.php), `peakHardMinutes`). It replaces the cold start and family progression in Taper, and the Taper target no longer caps it. The day's reason reads "half the last peak session, at its pace and rep length". The weekly hard-minute ceiling, the readiness clamp and the day's distance still apply after it. **Heuristic** inside the meta-analytic band.
2. **Which Peak session.** [Periodizer::rowsFor()](app/Services/Run/Plan/Periodizer.php) tracks the latest Peak week's goal-pace session as it generates (`goalPaceMinutes()`); once Peak is behind the athlete, [PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php) reads the latest Peak goal-pace row of the last 42 days at the active race's distance kind (`lastPeakGoalPaceHardMinutes()`). With no Peak session to read, Taper keeps the earlier rule. **Heuristic.**
3. **Rep length.** 5K and 10K Taper reps keep the Peak rep, 4 minutes, in place of the 2-minute taper rep. The rep length is stamped as `rep_minutes` in `prescription_race_context` ([GoalPaceWork::repMinutesOf()](app/Services/Run/Plan/GoalPaceWork.php)), so rows written before this change still render as written ([SegmentGenerator::forPrescription()](app/Services/Run/Plan/SegmentGenerator.php)). Recovery between reps stays the Taper's 3 minutes. A half block keeps the Taper's two-block layout, so its whole work unit is a block. **Heuristic.**
4. **Pace.** Taper goal-pace work runs at the same pace rule as Peak: the goal pace, the stepping-stone pace, or for an ambitious goal the stepping stone closing at goal pace ([[an-ambitious-goal-trains-at-the-stepping-stone-and-rehearses-the-goal]]). Inside one regeneration that is the Peak pace; a supported-time change between Peak and Taper moves both. **Heuristic.**
5. **Marathon.** The marathon race Tempo and race long are unchanged.

## Evidence

- The best taper lasted about two weeks with volume cut 41–60%, and keeping intensity beat reducing it ([[coaching-evidence#Bosquet2007]], [[coaching-evidence#Wang2023]]). **Measured** (meta-analyses).
- Intense work maintained into the taper preserves the adaptations it is meant to reveal ([[coaching-evidence#Mujika2010]]). **Review.**
- Half the last Peak session sits mid-band; rounding to whole reps and the minimum structure are **heuristic**.

## Consequences

- A 10K athlete whose last Peak session was 5 × 4 min tapers with 3 × 4 min at the same pace, where the cold start gave 2 × 2 min.
- Rollout: the owner runs `plan:regenerate` after deploy. No regrade.

## See also

- [[goal-pace-work-in-the-last-weeks]], [[a-race-block-tapers-two-weeks-and-recovers-in-peak]], [[peak-carries-over-from-build]], [[coaching-evidence]]
