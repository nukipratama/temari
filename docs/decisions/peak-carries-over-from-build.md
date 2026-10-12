---
title: Peak carries over from Build
description: The first race-specific session of Peak starts from the hard minutes of the last Build quality session instead of a cold start, capped at the phase target, so Peak quality never drops below Build.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-12
code_refs:
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/PlanInputs.php
  - tests/Feature/Plan/GoalPaceWorkPlanTest.php
---

# Peak carries over from Build

**Status:** Accepted (2026-10-12). Decision 1 of #2116, layer 1. Partly supersedes rule 7 of [[goal-pace-work-in-the-last-weeks]] for the Build→Peak seed only; later progression stays in the `goal_pace` family.

## Context

Goal-pace work forms its own progression family, so the first goal-pace week of Peak had no comparable history and cold-started at two reps. A 10K athlete whose Build ran 2 × 10 min at threshold (20 hard minutes) got 2 × 4 min at goal pace (8) in Peak, while Peak volume held at the build level. Coaching practice shifts content toward race pace from Build to Peak while total quality holds roughly level, not by restarting the quality dose.

## Decision

1. **Seed.** A race-specific Peak prescription (the `goal_pace` family for 5K to half, `race_tempo` for the marathon) is never below the hard minutes of the last Build quality session, capped at the phase target ([IntensityPrescriptionResolver::resolve()](app/Services/Run/Plan/IntensityPrescriptionResolver.php), `carriedHardMinutes`). Reps round up to a whole rep before the cap. The day's reason reads "carried over from the last build quality session". **Heuristic.**
2. **Which Build session.** The latest-dated Tempo or Interval of a Build week with hard minutes, a time trial aside. [Periodizer::rowsFor()](app/Services/Run/Plan/Periodizer.php) tracks it across the weeks it generates (`lastQualityMinutes()`); once Build is behind the athlete, [PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php) reads it from the last 42 days of persisted rows (`lastBuildQualityHardMinutes()`), prescribed minutes whatever the verdict. In a two-quality week the replaced session takes the last session's minutes and the other keeps its own progression, which keeps the Peak week total at or above the Build week total. **Heuristic.**
3. **Later progression.** A hit or held goal-pace or race-tempo verdict progresses in its own family, and the carry-over still floors it. A too-hard verdict steps down in its family and the carry-over does not undo it. **Heuristic.**
4. **What still cuts it.** The phase target, the weekly hard-minute ceiling, the readiness clamp and the day's distance (fit) all apply after the seed, as before.

## Evidence

- World-class distance runners shift from general to race-specific intensity toward competition while total intensive work holds roughly level ([[coaching-evidence#Haugen2022]], [[coaching-evidence#Casado2022]]). **Observational / consensus**, elite athletes.
- Daniels' phase IV and Canova's funnel periodization move quality to race pace without cutting it ([[coaching-evidence#Daniels2022]], [[coaching-evidence#ArcelliCanova1999]]). **Coaching consensus.**
- No recreational trial sets the carried dose, so the seed, its cap and the too-hard exception are **heuristic**.

## Consequences

- The 10K athlete above starts Peak at 5 × 4 min at goal pace for an on-track goal. An ambitious goal is still capped at its halved target until layer 2 of #2116.
- A marathon's Peak race Tempo is seeded from the latest Build Tempo or Interval (`lastQualityMinutes()`), and takes the seed only when it is above the race Tempo's progressed or cold-start dose, capped at the Peak target. When that last session is the race Tempo, Peak starts no lower than Build's race Tempo. When it is an Interval, a longer Interval raises the race Tempo to its minutes, up to the target, and a shorter one never lowers it.
- Rollout: the owner runs `plan:regenerate` after deploy. No regrade.

## See also

- [[goal-pace-work-in-the-last-weeks]], [[plan-periodizer]], [[coaching-evidence]]
