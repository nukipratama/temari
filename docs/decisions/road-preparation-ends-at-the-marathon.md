---
title: Dedicated road preparation ends at the marathon
description: Races through 42.195 km get the road block, taper and marathon-class work; anything longer stays on the athlete's account as general aerobic maintenance, with no ultra preparation claimed.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-01
code_refs:
  - app/Enums/RaceSupport.php
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/WeekPlanBuilder.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - tests/Unit/Enums/RaceSupportTest.php
---

# Dedicated road preparation ends at the marathon

**Status:** Accepted (2026-10-01). Supersedes the 30 km marathon threshold in [[the-plan-follows-the-coaching]] and the ultra race context of the earlier prescription resolver.

## Context

Three different thresholds decided what a long race was: 30 km for marathon-pace work, 25 km for taper and block length, and an unbounded ultra branch that scaled road coaching to any distance. A 100 km goal got a 20-week marathon block and goal-pace long runs the system has no research behind.

## Decision

[RaceSupport](app/Enums/RaceSupport.php) is the one rule. Races up to 42.195 km (42.3 km, so a rounded 42.2 still counts) are `road`: the block, the taper and, above 25 km, the marathon-class band. Races beyond are `general_maintenance`: no block (the arc runs the self-scaled cycle through race week with one taper week), no race-specific quality or goal-pace long runs, and the long-run cap stays at the general aerobic ceiling. The goal, its history and its race day remain accessible, and `race.support.limitation` states plainly that dedicated ultra preparation is not supported. The marathon-class lower bound moved from 30 km to 25 km, the majority of the sites that already used it.

## See also

- [[the-block-opens-on-a-computed-date]], [[plan-periodizer]]
