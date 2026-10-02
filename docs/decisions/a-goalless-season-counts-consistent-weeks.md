---
title: A goal-less season counts consistent weeks
description: A goal-less season's fifth goal is running the planned volume week by week, counted in weeks that reached 85% of that week's prescribed km, in place of growing CTL.
tags: [decision, run, plan, gamification]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Gamification/SeasonGamificationContext.php
  - app/Services/Run/Plan/SeasonService.php
  - database/migrations/2026_10_02_000200_replace_open_ctl_growth_goals_with_consistency.php
---

# A goal-less season counts consistent weeks

**Status:** Accepted (2026-10-02). Supersedes the "including CTL growth" consequence of [[the-block-opens-on-a-computed-date]].

## Context

A goal-less season asked the athlete to "Grow your fitness (CTL) this season". CTL is a smoothed load summary, not measured fitness, and a goal that rises with it rewards more load whether or not the plan asked for it. The goal-less plan holds volume flat on the athlete's anchor ([[a-goalless-arc-does-not-ramp]]), so the goal pulled against the plan it sat beside.

## Decision

- **The goal is "Run your planned volume week by week."** Its metric is `season_consistent_weeks` and its unit is weeks ([SeasonService::consistencyGoal()](app/Services/Run/Plan/SeasonService.php#L505)). The target is the number of Sundays in the season, 12 for a 12-week season.
- **A week counts when the athlete ran at least 85% of what was written.** [SeasonGamificationContext::consistentWeeks()](app/Services/Gamification/SeasonGamificationContext.php#L176) counts completed weeks whose actual km reached [CONSISTENT_WEEK_FRACTION](app/Services/Gamification/SeasonGamificationContext.php#L38) (0.85) of the sum of that week's recorded `prescribed_km`.
- **Open seasons are migrated.** `2026_10_02_000200_replace_open_ctl_growth_goals_with_consistency` swaps the CTL goal for the consistency goal on open goal-less seasons. A settled season keeps its CTL goal as history, and the resolver still reads it. Rollback restores the CTL goals.
- **Product choice.** The goal says what the app asks of the athlete: run the plan. CTL growth is not fitness ([[coaching-evidence#Vermeire2022]]), and volume is the trained quantity the plan holds ([[coaching-evidence#Doherty2020]]). The 85% line is a product choice, not a training claim.

## Consequences

- A goal-less athlete is no longer prompted to add load the plan does not ask for.
- A week with no recorded `prescribed_km` cannot count.
- Closed seasons render as before; only open ones change.
- Partly supersedes [[the-block-opens-on-a-computed-date]].

## See also

- [[gamification]], [[coaching-evidence]]
