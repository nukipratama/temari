---
title: A race floor solves under the session caps
description: The race block's volume-floor baseline is bisected under long_run_cap_km and the 30-day progression cap, sessions under their cap carry what a capped one loses, no cap is lifted, an unreachable floor takes the best reachable figure, and the goal-less solve is unchanged.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-09
code_refs:
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SegmentGenerator.php
---

> **Partly superseded (2026-10-09) by [[a-goal-less-cycle-solves-under-the-session-caps]].** Point 5 no longer holds: the goal-less solve now runs under the same caps. The rest of this decision stands.

# A race floor solves under the session caps

**Status:** Accepted (2026-10-09)

## Context

[[a-race-block-never-prescribes-below-habit]] solves the race block's volume floor in one division, as if every session scaled linearly off the baseline. The plan does not size sessions that way: [SegmentGenerator::coreKmFor()](app/Services/Run/Plan/SegmentGenerator.php) bounds them by `long_run_cap_km` and by the 30-day progression cap ([[a-season-averages-its-anchor-and-no-session-outruns-recent-capacity]]). Once the two-week taper raised a twelve-week block's peak to 1.075³ ([[a-race-block-tapers-two-weeks-and-recovers-in-peak]]), the cap held more weeks, and the audited athlete's block averaged 25.56 km against a 25.91 km floor.

## Decision

1. [TrainingBaseline::volumeFloorKm()](app/Services/Run/Plan/TrainingBaseline.php) sizes the block's sessions with `coreKmFor()` under `long_run_cap_km` and the progression cap, the same sizing the plan uses. Where the caps cut the linear figure, [TrainingBaseline::baselineAveragingKm()](app/Services/Run/Plan/TrainingBaseline.php) bisects for the smallest baseline whose capped block averages the floor.
2. Sessions under their cap carry what a capped one loses: a Long held at the progression cap leaves its Tempo and Easy days to grow, each still bounded by the same cap. **Heuristic**: weekly habit volume is a weekly-capacity signal, and the single-run cap already bounds every session ([[coaching-evidence#Frandsen2025]]).
3. No cap is lifted to reach the floor; the tightest ceiling still wins.
4. Where the caps leave no baseline that reaches the floor, the solve returns the smallest baseline that reaches the most they allow, so the figure never promises more than the block delivers.
5. The goal-less solve, [TrainingBaseline::selfScaledBaselineKm()](app/Services/Run/Plan/TrainingBaseline.php), is unchanged and still ignores the caps.

## Consequences

- The audited athlete's block now averages 26.04 km against the 25.91 km floor, and no Long exceeds the 11.0 km progression cap.
- The weekly-volume baseline moves with the progression cap when the race floor binds, which supersedes that part of [[recent-single-run-cap-stages-long-run-progression]].
- A goal-less cycle whose sessions the progression cap holds still averages under its anchor.

## See also

- [[a-race-block-never-prescribes-below-habit]]: the floor this solves
- [[plan-periodizer]]
