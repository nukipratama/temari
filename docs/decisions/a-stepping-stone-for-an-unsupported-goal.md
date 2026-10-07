---
title: A stepping stone for an unsupported goal
description: An unsupported goal shows a stepping-stone time at the edge of on track, 3% faster than the supported time, and its goal-pace work in the last weeks runs there with the on-track dose, without rewriting the stated goal.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-05
code_refs:
  - app/Services/Run/Plan/RaceAmbitionAssessor.php
  - app/Services/Run/Plan/RaceAmbition.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/GoalPaceWork.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/AI/Agent/Tools/PlanContextTool.php
  - resources/js/components/race/SteppingStoneCard.tsx
  - resources/js/lib/plan.ts
  - tests/Feature/Plan/GoalPaceWorkPlanTest.php
---

# A stepping stone for an unsupported goal

**Status:** Accepted (2026-10-05). Decision #1803, layer 6 of #1804. Partly supersedes rule 5 of [[goal-pace-work-in-the-last-weeks]]: an `unsupported` goal now gets goal-pace work at its stepping-stone pace. It also narrows decision 3 of [[one-race-model-drives-the-plan]] and the supported-effort rule of [[race-ambition-is-shown-and-capacity-is-prescribed]] for that work only.

## Context

An unsupported goal, more than 6% faster than the time recent runs support, got no goal-pace work at all: the plan trained only the supported effort, and the Race page showed the gap with nothing in between. A distant target with no nearer step gives the athlete nothing to aim at this block, and the last weeks rehearsed no race pace.

## Decision

1. **When it shows.** Only for an `unsupported` goal. `low_evidence` and `unknown` get none, because their supported time is not reliable enough to step from. **Product definition.**
2. **The time.** Supported time × (1 − `ON_TRACK_WITHIN`), so 0.97, rounded to whole seconds: the edge of on track ([RaceAmbitionAssessor::steppingStoneTimeSec()](app/Services/Run/Plan/RaceAmbitionAssessor.php#L65)). It is computed wherever the supported time is, so it moves as fitness moves, and the stated goal is never rewritten. The race payload carries it as `stepping_stone_time_sec` and `stepping_stone_pace_sec_per_km` ([RaceAmbition::toArray()](app/Services/Run/Plan/RaceAmbition.php#L39)). The 3% is the on-track edge from #1803, not a research number. **Product definition.**
3. **Race page.** Its own card under the duel ([SteppingStoneCard](resources/js/components/race/SteppingStoneCard.tsx#L13)): the eyebrow "stepping stone", the time with its pace per km, and one line saying it is the edge of on track, 3% faster than the supported time, that goal-pace work runs there, and that it moves as the athlete gets fitter. **Product choice.**
4. **5K to half.** Goal-pace work runs at the stepping-stone pace with the full on-track dose from `GOAL_PACE_TARGETS` ([GoalPaceWork::forWeek()](app/Services/Run/Plan/GoalPaceWork.php#L49)). The stepping-stone pace sits exactly at the on-track edge, where an on-track athlete already gets the full dose. Window, replacement, progression (`goal_pace` family) and readiness rules are those of [[goal-pace-work-in-the-last-weeks]]. **Heuristic.**
5. **Marathon.** On-track treatment at the stepping-stone pace: the race Tempo and the race-long marathon-pace block both run at it with the existing minutes ([GoalPaceWork::racesLongAtGoalPace()](app/Services/Run/Plan/GoalPaceWork.php#L100)). Families stay `race_tempo` and `race_long`. `low_evidence` and `unknown` marathons keep the clamped supported marathon pace. **Heuristic.**
6. **Label.** "stepping-stone pace", not "goal pace", on Home's today session, the Plan day detail and the week strip, and the purpose line reads "rehearsing your 10K stepping-stone pace." ([sessionLabel()](resources/js/lib/plan.ts#L393), [sessionPurpose()](resources/js/lib/plan.ts#L451)). The day payload carries `stepping_stone` beside `goal_pace` ([PlanRenderer::dayPayload()](app/Services/Run/Plan/PlanRenderer.php#L244)). The plan tools hand narrators `stepping_stone` in place of `goal_pace` ([PlanRenderer::goalPaceForNarration()](app/Services/Run/Plan/PlanRenderer.php#L479)), so a written read never calls it the goal pace. Grading judges these sessions against the prescribed stepping-stone pace, the same way goal-pace work is judged. **Product choice.**
7. **Persisted at generation.** The band `unsupported` and the stepping-stone pace are stamped in `prescription_race_context`, read back by [GoalPaceWork::isSteppingStone()](app/Services/Run/Plan/GoalPaceWork.php#L148). A band or supported-time change takes effect at the next regeneration.

## Evidence

- Moderately difficult goals improve sport and exercise performance and very difficult ones do not; combining short- and long-term goals worked best ([[coaching-evidence#KylloLanders1995]]). **Measured.**
- Proximal subgoals built mastery, self-efficacy and interest where distal goals alone showed no effect ([[coaching-evidence#BanduraSchunk1981]]). The study was on children's arithmetic, so it supports the mechanism only. **Measured.**
- The full dose and the marathon's on-track treatment follow from the stepping-stone pace equalling the on-track edge, and inherit the heuristic doses of [[goal-pace-work-in-the-last-weeks]]. **Heuristic.**

## Consequences

- An unsupported athlete gets one goal-pace session a week in the last 6 to 8 weeks, in place of an existing quality session; the hard-day count is unchanged. Low-evidence and unknown plans are unchanged.
- Rollout: the owner runs `plan:regenerate` for all users after deploy. No regrade: only future sessions change.

## See also

- [[goal-pace-work-in-the-last-weeks]], [[race-projection]], [[plan-periodizer]], [[coaching-evidence]]
