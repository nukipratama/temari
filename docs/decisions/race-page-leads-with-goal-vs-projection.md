---
title: The Race page leads with the goal against the projection
description: The Race page is rebuilt around one duel card, the goal time facing the projected finish with the gap in words and a straight range bar, under a compact header, replacing the race card, the arc gauge and the schedule/race-goal tabs.
tags: [decision, design]
status: accepted
reviewed: 2026-09-24
code_refs:
  - resources/js/pages/Race.tsx
  - resources/js/components/race/RaceDuel.tsx
  - resources/js/components/race/ProjectionRangeBar.tsx
  - resources/js/lib/raceGoal.ts
---

# The Race page leads with the goal against the projection

## Context

The prototype-parity program (`PP3`, decision P26) gave the Race page three blocks behind the schedule/race-goal tabs: a race card, a projected-finish arc gauge and an always-open goal form. The owner's review (#1161) found four faults:

- **The gap was never stated.** The goal sat in one card and the projection in another, so the athlete did the subtraction.
- **The gauge was hard to read.** An arc placed the best estimate inside its own range, but never showed where the goal fell.
- **Cluttered top.** An eyebrow, a two-line headline, an intro sentence and the tab switch all came before the race.
- **The edit form was always open.**

## Decision

The options were rendered as pictures on both grounds, and the owner picked each one:

- **Compact header.** The title "your race." with a "plan →" link, which replaces the schedule/race-goal tabs, the same way the Plan page links back with "race goal →".
- **The duel (R3) is the hero.** [RaceDuel](resources/js/components/race/RaceDuel.tsx) sets "your goal" against "on track for", with the gap in words between them: "8:29 behind" in the ember family, "2:10 ahead" in the leaf family, and "on goal" within `ON_GOAL_TOLERANCE_SEC` (5 seconds) either way ([goalGap](resources/js/lib/raceGoal.ts)).
- **A straight range bar replaces the gauge.** [ProjectionRangeBar](resources/js/components/race/ProjectionRangeBar.tsx) marks the goal against the projected range and the best estimate, on an axis spanning both with a little padding. It draws no number the projection payload does not already carry.
- **The race line and the PR basis sit below it**: name · date · days to go, then what the projection rests on.
- **Temari watermarks the card, posed from the gap** by a coach's reading relative to the goal time ([goalGapPose](resources/js/lib/raceGoal.ts)): ahead or within 1% is `blazing`, up to 3% behind is `easy`, 3–8% is `wobbly`, more is `gassed`. The watermark sits bottom-right, where the basis line ends.
- **Too few runs to project** leaves the goal alone on the card with "not enough recent runs to project yet": no gap, no bar, a neutral Temari.
- **Edit and clear** are a second layer: "edit race" expands the form inline, collapsed by default, and a quiet "clear race" confirms through the concerned-Temari modal.

## Rejected

- **A verdict hero (R1).** A sentence such as "you're on track" leads, with the numbers under it. It states the outcome but hides the two times the athlete set and is measured against.
- **A countdown hero (R2).** Days to go lead. The date changes nothing the athlete can act on today, and the page is where they check whether training is closing the gap.

## Consequences

- `RaceCard`, `ProjectionBlock` and `ProjectionGauge` go away; the Profile page's own race card is a separate component and stays.
- `PlanRaceTabs` has no consumer left once both pages link across; its file goes with the second layer.
- The Race page's projection moves from a 40px gutter tag to a watermark.
