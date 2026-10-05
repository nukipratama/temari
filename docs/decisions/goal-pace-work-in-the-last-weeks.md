---
title: Goal-pace work in the last weeks, dosed by the ambition band
description: In the last 6 weeks before a race up to 10K and the last 8 before a half or longer, one quality session a week becomes goal-pace work at the real goal pace, full for an on-track goal and halved for an ambitious one, without adding a hard day.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-05
code_refs:
  - app/Services/Run/Plan/GoalPaceWork.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/SessionIntentJudge.php
  - app/Services/Run/Plan/PlanRenderer.php
  - resources/js/lib/plan.ts
  - tests/Feature/Plan/GoalPaceWorkPlanTest.php
---

# Goal-pace work in the last weeks, dosed by the ambition band

**Status:** Accepted (2026-10-05). Decision #1803, layer 3 of #1804. Partly supersedes [[race-ambition-is-shown-and-capacity-is-prescribed]]: for an on-track or ambitious goal, marathon-pace work inside the window now runs at the real goal pace instead of the slower of goal and supported marathon pace. [[one-race-model-drives-the-plan]] decision 3 still holds for every other band and outside the window.

## Context

The plan prescribed only the effort the athlete's fitness supports. A 5K, 10K or half goal never rehearsed its own pace, and a marathon goal's race-specific Tempo and Long were clamped to the supported marathon pace even when the goal sat within a few percent of it. Race-specific work in the final weeks is standard coaching practice, but an unsupported target must still never become a pace or a reason to add quality.

## Decision

1. **Window.** Counted back from race week, inclusive: the last 6 weeks for races up to 10K, the last 8 for longer races ([GoalPaceWork::windowWeeks()](app/Services/Run/Plan/GoalPaceWork.php#L77)), only for road races with dedicated preparation. Only Build, Peak and Taper weeks carry it ([GoalPaceWork::forWeek()](app/Services/Run/Plan/GoalPaceWork.php#L49)): a scheduled deload keeps its deload session, and a Base week that falls inside a short arc keeps its base work. Taper weeks keep goal-pace work at the taper dose. **Heuristic.**
2. **Replacement, never an extra hard day.** At most one session a week becomes goal-pace work ([GoalPaceWork::replacedDate()](app/Services/Run/Plan/GoalPaceWork.php#L128)). For a 5K or 10K the Interval day becomes goal-pace reps; for the half and the marathon the Tempo day becomes a goal-pace block. With no session of that type, the week's first Tempo or Interval is replaced, and the work keeps the kind's own shape ([GoalPaceWork::shapeOf()](app/Services/Run/Plan/GoalPaceWork.php#L150)): reps on a 10K Tempo day, a block on a half Interval day. The hard-day budget, the hard-minute ceiling and the readiness clamp still apply on top. When a dose does not fit the day's distance it steps down by whole work units before the day is made easy. **Product choice.**
3. **Pace.** On-track and ambitious goals train at the real goal pace (`goal_time_sec / distance`), with no clamp to the supported marathon pace. **Heuristic.**
4. **Dose.** On track: full target minutes from `GOAL_PACE_TARGETS` by kind and phase ([IntensityPrescriptionResolver](app/Services/Run/Plan/IntensityPrescriptionResolver.php#L32)); the marathon keeps `RACE_TEMPO_TARGETS`. Ambitious: half the on-track target. The hit, held and too-hard progression still applies, capped at the band's target. Reps use the existing interval rep lengths (3, 4 and 2 minutes in Build, Peak and Taper); half and marathon blocks use the tempo block shape. **Heuristic**: no trial sets these numbers.

   | Kind | Build | Peak | Taper |
   |---|---|---|---|
   | 5K (reps) | 15 | 20 | 10 |
   | 10K (reps) | 21 | 24 | 12 |
   | Half (block) | 30 | 40 | 20 |
   | Marathon (block, unchanged) | 25 | 35 | 20 |

   These follow common coaching practice: about 5 × 1 km at 5K pace, 3 × 2 km at 10K pace and 2 × 15–20 min at half pace at the peak, with the taper about half the peak as a short sharpener.
5. **Bands without goal-pace work.** `low_evidence` and `unknown` get none. Nor does a goal more than 3% slower than the supported time, which the assessor still calls `on_track`: its pace would ask less than the session it replaces. `unsupported` gets none for 5K to the half until layer 6 adds a stepping-stone pace. For the marathon, `unsupported`, `low_evidence` and `unknown` keep exactly the earlier race Tempo and race Long at the clamped supported marathon pace.
6. **Marathon race long.** On track: the existing race-long marathon-pace block runs at goal pace with the existing minutes. Ambitious: the race long stays at the clamped supported pace; goal pace comes only through the halved Tempo. Outside the window marathon work is unchanged.
7. **Progression family.** 5K to half goal-pace sessions form their own comparable family, `goal_pace`, read from each row's persisted context ([IntensityPrescriptionResolver::familyKeyForContext()](app/Services/Run/Plan/IntensityPrescriptionResolver.php#L216)), so they neither learn from nor teach threshold or interval history. The marathon keeps `race_tempo` and `race_long`.
8. **Persisted at generation.** The band, goal pace and kind are stored in `prescription_race_context`; a band change takes effect at the next regeneration.
9. **What the athlete sees.** A goal-pace session is labelled "goal pace" on Home's today session, the Plan day detail and the week strip, derived from the persisted context ([PlanRenderer::goalPaceKindOf()](app/Services/Run/Plan/PlanRenderer.php#L438), [sessionLabel()](resources/js/lib/plan.ts#L378)), not from a new session type. Its target is the goal pace, and its line reads "rehearsing your 10K goal pace." Intent grading judges the session against the prescribed goal pace, by its shape ([SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php)). The plan tools hand narrators the same `goal_pace` label and target. An unsupported marathoner's supported-pace tempo keeps its tempo label. **Product choice.**

## Consequences

- An on-track or ambitious athlete gets goal-pace work in place of an existing quality session; the hard-day count is unchanged, and the unsupported, low-evidence and unknown plans are unchanged.
- A 5K or 10K has a one-week taper that is race week, where the existing hard ceiling already removes quality, so goal-pace work for those races ends the week before.
- Rollout: the owner runs `plan:regenerate` for all users after deploy. No regrade: only future sessions change.

## See also

- [[plan-periodizer]], [[coaching-evidence]], [[race-projection]]
