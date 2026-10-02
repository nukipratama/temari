---
title: The race page sets the target beside the supported time
description: The Race page and Trends compare the athlete's target with the time recent runs support, say "on track" only in the on-track band, and drop the Riegel range bar and PR basis, leaving the Riegel projection to the race form's typed-goal warning.
tags: [decision, design, run]
status: accepted
reviewed: 2026-10-02
code_refs:
  - resources/js/components/race/RaceDuel.tsx
  - resources/js/lib/raceGoal.ts
  - resources/js/components/trends/RaceComparison.tsx
  - app/Http/Controllers/TrendsController.php
  - app/Services/Run/Plan/RaceAmbitionAssessor.php
---

# The race page sets the target beside the supported time

**Status:** Accepted (2026-10-02). Supersedes [[race-page-leads-with-goal-vs-projection]]. Partly supersedes [[one-race-model-drives-the-plan]]: Riegel is no longer shown on `/race`.

## Context

[[race-page-leads-with-goal-vs-projection]] put the goal against a Riegel projection with a range bar. Since [[one-race-model-drives-the-plan]] the plan reads the VDOT race equivalent, so the page compared the target with a number the plan did not use, and the two could disagree. Riegel's exponent is also unreliable for the marathon in less-trained runners ([[coaching-evidence#VickersVertosick2016]]). Trends repeated a second long-term-load hero beside the race, and a long time wrapped badly at 320px (#1445, #1437, #1529).

## Decision

1. **Compare the target with the supported time.** [RaceDuel](resources/js/components/race/RaceDuel.tsx#L42) shows "your target" on the left and `ambition.supported_time_sec` (the VDOT race equivalent from [RaceAmbitionAssessor](app/Services/Run/Plan/RaceAmbitionAssessor.php)) on the right. **Product choice**: the page shows the number the plan trains at.
2. **"On track for" only in the on-track band.** [supportedEyebrow()](resources/js/lib/raceGoal.ts#L169) says "on track for" only when the state is `on_track` and the supported time is not behind the target; otherwise it says "supported". **Product choice**: the label never reads more optimistic than the band.
3. **One sentence states the band.** [ambitionNote()](resources/js/lib/raceGoal.ts#L144) names on track, ambitious, unsupported or low evidence, and for an unknown state gives the honest limit. The 3% and 6% bands stay a heuristic ([[race-ambition-is-shown-and-capacity-is-prescribed]]). **Product choice.**
4. **The range bar and PR basis are removed.** The Riegel projection now backs only the race form's typed-goal warning ([ambitiousGoalWarning()](resources/js/lib/raceGoal.ts#L77)). The projection and its fit stay documented in [[race-projection]].
5. **Trends reads the same model.** [TrendsController::raceOutlook()](app/Http/Controllers/TrendsController.php#L55) serves ambition and support from the same `RacePresenter`. [RaceComparison](resources/js/components/trends/RaceComparison.tsx#L40) shows "your target", "supported by your recent runs" and the same sentence. It drops its duplicate long-term-load hero. With no race it is one line and a set-a-race link. **Product choice.**
6. **Times wrap rather than clip** at 320px.

## Consequences

- Both pages cannot disagree about the target or the supported time, and neither disagrees with the plan.
- A fast target with thin evidence reads "low evidence" or "unsupported" rather than a projected finish the athlete may trust too much.
- Riegel has one consumer left. Removing it entirely would need a new decision on the typed-goal warning.
