---
title: An ambitious goal trains at the stepping stone and rehearses the goal
description: An ambitious goal's goal-pace work runs the full dose at the stepping-stone pace and closes at the real goal pace, the final rep of four or more or else the last quarter of each block or of the last rep, and grading judges each rep or block against its own pace.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-12
code_refs:
  - app/Services/Run/Plan/GoalPaceWork.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/SessionIntentJudge.php
  - tests/Feature/Plan/GoalPaceWorkPlanTest.php
---

> **Superseded in part (2026-10-12) by [[race-specific-sessions-name-the-pace-they-run-at]].** Rules 3 and 4: the race payload and the Race page now carry an ambitious goal's stepping stone, and its purpose line says the session closes at goal pace.

# An ambitious goal trains at the stepping stone and rehearses the goal

**Status:** Accepted (2026-10-12). Decision 2 of #2116, layer 2. Partly supersedes rules 3, 4 and 6 of [[goal-pace-work-in-the-last-weeks]] for an `ambitious` goal, and extends the stepping-stone pace of [[a-stepping-stone-for-an-unsupported-goal]] to the plan work of that band.

## Context

The bands were inverted. An `unsupported` goal got the full goal-pace dose at its stepping-stone pace, while the nearer `ambitious` goal got half the dose at its real goal pace (`AMBITIOUS_DOSE_DIVISOR`). A 10K ambitious athlete could reach at most 12 minutes of race-specific work in Peak.

## Decision

1. **Dose.** An ambitious goal runs the full `GOAL_PACE_TARGETS` dose for 5K to half and the full `RACE_TEMPO_TARGETS` dose for the marathon race Tempo, as an on-track or unsupported goal does ([IntensityPrescriptionResolver](app/Services/Run/Plan/IntensityPrescriptionResolver.php)). `AMBITIOUS_DOSE_DIVISOR` is gone. **Heuristic.**
2. **Pace.** The work runs at the stepping-stone pace, supported time × 0.97 ([RaceAmbitionAssessor::steppingStoneTimeSec()](app/Services/Run/Plan/RaceAmbitionAssessor.php)), and closes at the real goal pace. With four reps or more the final rep runs at goal pace. With fewer reps the last quarter of the last rep does, and in a block session the last quarter of each block does (`GoalPaceWork::CLOSING_SHARE`, rounded to whole minutes, at least one: [GoalPaceWork::closingMinutes()](app/Services/Run/Plan/GoalPaceWork.php)). So the goal-pace share is usually 14–27% of the work, from about 8% when a short session has under four reps to about 30% in a short Taper block: a Peak half block of 40 minutes is 30 at the stepping stone and 10 at goal pace, a Build half session 2 × (11 + 4). The row's prescribed pace is the stepping-stone pace, and `prescription_race_context` carries the goal pace as `final_pace_sec_per_km` ([GoalPaceWork::context()](app/Services/Run/Plan/GoalPaceWork.php), [GoalPaceWork::finalPaceOf()](app/Services/Run/Plan/GoalPaceWork.php)); [SegmentGenerator::forPrescription()](app/Services/Run/Plan/SegmentGenerator.php) writes the goal-pace close as its own segment straight after the stepping-stone part, with no recovery between. **Heuristic**, the share chosen to match the issue's 75–80% at the stepping stone.
3. **Where the stepping stone comes from.** [PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php) derives it from the supported time for an ambitious goal. The race payload still carries a stepping stone only for an unsupported goal, so the Race page is unchanged. **Product choice.**
4. **Label.** The session is labelled "stepping-stone pace" on every surface and to narrators, as [[a-stepping-stone-for-an-unsupported-goal]] rule 6 describes ([GoalPaceWork::isSteppingStone()](app/Services/Run/Plan/GoalPaceWork.php)). A row generated before this change, with no final pace, keeps its "goal pace" label. **Product choice.**
5. **Grading.** Back-to-back segments with no recovery between them are one rep or block at their combined pace (`SessionIntentJudge::workUnits()`), so a closing goal-pace part never counts as an extra rep. Intent grading then judges each rep or block against its own pace ([SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php)): the k-th detected work lap against the k-th rep's pace, and the excessive check against the mean of those targets. A pace window that cannot tell blocks apart is held to the slowest block's pace and checked for excess against the fastest. **Product choice.**
6. **Marathon.** The ambitious race Tempo follows rules 1 and 2. The ambitious race long stays at the clamped supported marathon pace, as before ([GoalPaceWork::racesLongAtGoalPace()](app/Services/Run/Plan/GoalPaceWork.php)). Unsupported, on-track, low-evidence and unknown plans are unchanged.

## Evidence

- Training paces follow current fitness, not goal fitness ([[coaching-evidence#Daniels2022]]). **Coaching consensus.**
- Moderately difficult goals improve performance and very difficult ones do not ([[coaching-evidence#KylloLanders1995]]). **Measured.**
- The goal-pace close, usually 14–27% of the work, is a **heuristic**: no trial compares it with a halved dose at goal pace.

## Consequences

- An ambitious 10K athlete's Peak goal-pace session reaches 6 × 4 min, five at the stepping-stone pace and the last at goal pace, where it was capped at 3 × 4 min at goal pace. An ambitious half's Peak block runs 30 minutes at the stepping stone and closes with 10 at goal pace. With [[peak-carries-over-from-build]] that Peak is never below the last Build quality session.
- Ambitious and unsupported goals get the same dose.
- Rollout: the owner runs `plan:regenerate` after deploy. No regrade.

## See also

- [[goal-pace-work-in-the-last-weeks]], [[a-stepping-stone-for-an-unsupported-goal]], [[peak-carries-over-from-build]], [[coaching-evidence]]
