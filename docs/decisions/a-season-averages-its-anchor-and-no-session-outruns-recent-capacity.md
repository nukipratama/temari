---
title: A season averages its anchor, and no session outruns recent capacity
description: A goal-less season solves its long-run baseline so the four-week cycle averages the frozen anchor; following the prescription never lowers that anchor; and the 30-day single-run cap bounds every running session, falling back to the cold-start long run.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/PhaseSchedule.php
---

# A season averages its anchor, and no session outruns recent capacity

**Status:** Accepted (2026-10-02)

## Context

The 2026-10-01 coaching audit ran the volume rules against synthetic athletes:

- **Goal-less seasons prescribed below the anchor.** The long run was a share of the weekly volume, and every other session a fraction of the long run, so the week's sum depended on the session count. A self-scaled cycle prescribed roughly 0.4–1.2× its anchor, typically 0.6–0.85×. [[a-goalless-arc-does-not-ramp]] assumed the baseline already tracked real volume, and it did not.
- **The re-anchor followed the plan down.** The collapse re-anchor and the rollover anchor read only trailing actual volume. An athlete who ran exactly the under-sized plan, or a deload the plan asked for, lowered their own anchor.
- **The 30-day cap bound only the Long.** Tempo and the primary Easy run, sized at 0.65 of the long run, could still exceed 110% of the longest recent run, and the cap disappeared after 30 days without a run.

## Decision

1. **A self-scaled season solves its long-run baseline** so the mean prescribed week of its Build, Build, Build, Deload cycle equals the frozen anchor. [TrainingBaseline::selfScaledBaselineKm()](app/Services/Run/Plan/TrainingBaseline.php#L511) uses the same solve a race block uses for its volume floor ([TrainingBaseline::baselineAveragingKm()](app/Services/Run/Plan/TrainingBaseline.php#L531)). The race-distance, time-on-feet and half-the-week caps still bind, so where one does (two sessions a week, or a 70 km week at five sessions or fewer under the 22 km goal-less cap), the cycle averages less than the anchor. A matrix test covers anchors of 20, 40 and 70 km at two to six sessions: within ±5% where no cap binds, and at the cap where one does. **Heuristic**: habit-level volume is what the athlete already does, volume relates to performance ([[coaching-evidence#Doherty2020]]), and training below it loses adaptations ([[coaching-evidence#Coyle1984]], [[coaching-evidence#MujikaPadilla2000a]]).
2. **Following the prescription never lowers the anchor.** [SeasonService](app/Services/Run/Plan/SeasonService.php#L240) compares trailing actual km with trailing prescribed km over the last six completed weeks that carry a settled prescription. The athlete followed the prescription when they ran at least 75% of it, the complement of the existing 25% collapse fraction. A mid-season collapse re-anchors only when the athlete also fell short of the prescription. At rollover, an athlete who followed it keeps at least the previous season's anchor. With no prescription data, both rules fall back to trailing actual alone. **Heuristic** ([[coaching-evidence#Coyle1984]], [[coaching-evidence#MujikaPadilla2000a]], [[coaching-evidence#MujikaPadilla2000b]]).
3. **The 30-day single-run cap bounds every running session.** [SegmentGenerator::coreKmFor()](app/Services/Run/Plan/SegmentGenerator.php#L119) applies 110% of the longest run in the prior 30 days to every session type. With no run in that window, the cap is the cold-start long run, not absent. A single session more than 10% longer than the longest recent run carried injury risk ([[coaching-evidence#Frandsen2025]]). **Evidence-supported.**

The Build ramp's 7.5% a week stays. It is a heuristic, not a safety rule: the weekly "10% rule" did not lower injuries in novices ([[coaching-evidence#Buist2008]]).

## Consequences

- A goal-less athlete's plan now asks for about what they run, rather than 15–40% less.
- With a longest recent run of 10 km and an effective long run of 20 km, no session exceeds 11 km.
- An athlete back after more than 30 days off starts from the cold-start long run on every session, and each completed run raises the cap for the next.
- Supersedes two statements in [[recent-single-run-cap-stages-long-run-progression]] and corrects the premise of [[a-goalless-arc-does-not-ramp]].
