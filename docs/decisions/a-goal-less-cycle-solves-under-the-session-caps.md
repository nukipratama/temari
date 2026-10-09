---
title: A goal-less cycle solves under the session caps
description: The goal-less baseline is bisected under long_run_cap_km and the 30-day progression cap like the race floor, sessions under their cap carry what a capped one loses, no cap is lifted, and an unreachable anchor takes the best reachable figure.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-09
code_refs:
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SegmentGenerator.php
---

# A goal-less cycle solves under the session caps

**Status:** Accepted (2026-10-09)

## Context

[[a-race-floor-solves-under-the-session-caps]] left the goal-less solve, [TrainingBaseline::selfScaledBaselineKm()](app/Services/Run/Plan/TrainingBaseline.php), sizing its cycle with no caps (its point 5). Measured on 2026-10-09 against the plan's own weekly sums, the 30-day progression cap held goal-less cycles under their anchor whenever the longest recent run sat near the habit long run at three or four sessions, or well under it: 20 km at four sessions with a 7 km longest run averaged 19.68 km, 40 km at five sessions with an 8 km longest run averaged 36.70 km. The demo athlete's cap does not bind.

## Decision

1. The goal-less solve passes `long_run_cap_km` and the progression cap to [TrainingBaseline::baselineAveragingKm()](app/Services/Run/Plan/TrainingBaseline.php), the same bisection the race floor uses. This supersedes point 5 of [[a-race-floor-solves-under-the-session-caps]].
2. Sessions under their cap carry what a capped one loses, as in the race block. **Heuristic**, on the same grounds ([[coaching-evidence#Frandsen2025]]).
3. No cap is lifted to reach the anchor.
4. Where the caps leave the anchor out of reach, the baseline is the smallest that reaches the most they allow, bounded by `long_run_cap_km`.

## Consequences

- The two measured cycles above now average 20.12 km and 40.07 km, and no session exceeds its progression cap.
- An athlete at 30 km over four sessions with a 7 km longest run still averages under the anchor: the baseline rises from 12.4 km to its 15.0 km cap and the cycle from 25.90 km to 27.28 km.
- A cycle held by `long_run_cap_km` alone, such as 70 km at three to five sessions under the 22 km goal-less cap, is unchanged, since every session sizes off the capped long run.

## See also

- [[a-season-averages-its-anchor-and-no-session-outruns-recent-capacity]]: the anchor this solves
- [[plan-periodizer]]
