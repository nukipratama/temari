---
title: A fast-finish long run for 5K to half goals
description: From mid-Build through Peak, every two to three weeks, a 5K-to-half goal's long run ends with its last fifth at race-specific pace, as a hard day under the weekly budget, never in a recovery or taper week and never on a new longest distance.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-12
code_refs:
  - app/Services/Run/Plan/FastFinishLongRun.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/SessionIntentJudge.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Story/Temari.php
  - tests/Feature/Plan/FastFinishLongRunPlanTest.php
---

# A fast-finish long run for 5K to half goals

**Status:** Accepted (2026-10-12). Decision 4 of #2116, layer 4.

## Context

Every long run of a 5K, 10K or half goal was easy from start to finish; only the marathon's race long carried race-pace work. Progression and fast-finish long runs are a staple of 5K-to-half schedules in the weeks before a race.

## Decision

1. **Who.** Road races of dedicated preparation up to the marathon class: the 5K, 10K and half kinds of [GoalPaceWork::kindFor()](app/Services/Run/Plan/GoalPaceWork.php). Never a marathon, whose race long keeps its marathon-pace block ([FastFinishLongRun::forWeek()](app/Services/Run/Plan/FastFinishLongRun.php)). **Product choice.**
2. **When.** From the second half of the block's Build weeks, or its first Peak week with no Build, through Peak, at least two weeks after the last fast finish ([Periodizer::fastFinishFrom()](app/Services/Run/Plan/Periodizer.php), `FastFinishLongRun::MIN_WEEKS_APART`). The gap counts from the last one the athlete was prescribed ([PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php) `lastFastFinishDate()`), so a weekly regeneration keeps the cadence. A week a rule below turns down passes the turn to the next, which keeps the gap at two to three weeks in a full block. Never in a recovery (Deload) or Taper week, nor on a post-race recovery day. **Heuristic.**
3. **Never on a new longest distance.** The long run must be no longer than the longer of the longest run of the last 30 days and the longest long run the season has prescribed so far, sized as the plan sizes it ([PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php) `longestRunSoFarKm()`, then each generated week in turn). A Build ramp that lengthens the long run every week therefore places its fast finishes on the weeks that hold. **Heuristic** on a measured basis.
4. **Content.** The last fifth of the long run (`FastFinishLongRun::SHARE`, inside the 15–25% range) at race-specific pace: the week's goal-pace work pace when it has goal-pace work (goal pace when on track, stepping-stone pace when ambitious or unsupported), otherwise the supported race pace at the goal distance, except a 5K goal, whose fast finish runs at the supported 10K pace. With no supported time there is none. A block at 5K pace is labelled at the interval band, one at 10K or half pace at threshold. [SegmentGenerator::forPrescription()](app/Services/Run/Plan/SegmentGenerator.php) writes an easy block and then the fast block, with no easy finish. The row stamps `{kind: fast_finish}` in `prescription_race_context`, its own progression family ([IntensityPrescriptionResolver::familyKeyForContext()](app/Services/Run/Plan/IntensityPrescriptionResolver.php)). **Heuristic.**
5. **A hard day under the budget.** It counts as a hard day ([Periodizer::capQualityAroundWeeklyHardDays()](app/Services/Run/Plan/Periodizer.php), `isHardDay()`), and yields: Tempo and Interval days are kept first, and the fast finish is kept only while the week has fewer than two hard days and none within a day of it. It also stays under the weekly hard-minute ceiling. Otherwise the long run stays a plain easy long run. A four-run week with one quality day takes it; a five-run week with two does not. **Product choice.**
6. **Grading.** Intent grading judges the fast block on pace and holds the easy running before it to the heart-rate cap, as it does a marathon-pace long run ([SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php)).
7. **Mood.** A run on a fast-finish day is judged as a quality day, as a marathon-pace long run is ([Temari::moodForActivity()](app/Services/Run/Story/Temari.php), [[a-quality-sessions-mood-follows-its-purpose]]). **Product choice.**

## Evidence

- Long runs with progression or race-pace finishes appear in 5K-to-half and marathon schedules and in world-class practice ([[coaching-evidence#PfitzingerLatter2015]], [[coaching-evidence#PfitzingerDouglas2019]], [[coaching-evidence#Haugen2022]]). **Coaching consensus.**
- Long-run length, not its pace content, related to (half-)marathon performance in recreational runners ([[coaching-evidence#Fokkema2020]]). **Cohort.**
- A single session longer than the recent longest run carries the injury risk ([[coaching-evidence#Frandsen2025]]). **Cohort**, the basis of rule 3.
- Fast finishes in that practice run at around threshold to 10K pace; 5K pace over the last fifth of a long run is harder than they prescribe, so a 5K goal with no goal-pace work that week finishes at its supported 10K pace ([[coaching-evidence#PfitzingerLatter2015]], [[coaching-evidence#Haugen2022]]). **Coaching consensus.**
- No trial compares long-run content, so the share, the cadence and the pace choice are **heuristic**.

## Consequences

- A full 10K block in a four-run week shows two or three fast-finish long runs between mid-Build and Peak, none in a recovery or taper week and none on a new longest distance.
- Plans of five or more runs a week rarely get one, since their two quality days fill the hard-day budget.
- Rollout: the owner runs `plan:regenerate` after deploy. No regrade.

## See also

- [[goal-pace-work-in-the-last-weeks]], [[an-ambitious-goal-trains-at-the-stepping-stone-and-rehearses-the-goal]], [[plan-periodizer]], [[coaching-evidence]]
