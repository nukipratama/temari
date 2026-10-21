---
title: Season goal targets follow the plan
description: The sessions, quality and rest-day season goals target 80% of what the season's plan actually schedules, recounted on every regeneration and at settle; a kind the plan never schedules has no goal.
tags: [decision, run, plan, gamification]
status: accepted
reviewed: 2026-10-21
code_refs:
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Gamification/SeasonGamificationContext.php
  - app/Services/Gamification/SeasonRecordBuilder.php
---

# Season goal targets follow the plan

**Status:** Accepted (2026-10-21). Supersedes the "stable checklist" rule in [[gamification]] for `season_sessions_completed`, `season_quality_completed` and `season_rest_honored`.

## Context

These three targets were set once, when the season opened, from `sessions_per_week × weeks`. The plan written afterwards had fewer sessions: deload weeks drop a run, the sessions-per-week baseline moves, and the first and last weeks are partial. On 2026-10-21 every real athlete in prod had at least one goal they could not reach even by running every planned session. One half-marathon season asked for 16 quality sessions while its plan held 4. [SeasonRecordBuilder](app/Services/Gamification/SeasonRecordBuilder.php) averages each goal's current/target into `process_pct`, so the stale target capped the season record too. The `max(1, …)` floor also invented a quality goal for a plan with no quality day.

## Decision

- **The denominator is the plan.** `SeasonService::recountPlannedGoals()` counts the season's own `PlannedSession` rows inside `starts_at..ends_at` with the rules [SeasonGamificationContext](app/Services/Gamification/SeasonGamificationContext.php) uses for completions: non-rest rows are sessions, Tempo, Interval and Race rows are quality, and rest rows without a make-up are rest days.
- **The target is 80% of that count, rounded up.** Adherence is measured as sessions completed ÷ sessions prescribed, and 80% is the usual cutoff for adherent ([[coaching-evidence#LeyPutz2024]], [[coaching-evidence#Shaw2022]]). Goals must stay attainable to motivate ([[coaching-evidence#LockeLatham2002]], [[coaching-evidence#LockeLatham2019]]).
- **No planned rows, no goal.** A kind the plan never schedules has its goal removed. It comes back when a later plan schedules that kind.
- **Recounted on every regeneration and at settle.** `Periodizer::persist()` recounts the current season after writing the plan. `SeasonService` recounts a closing season right before its record settles, so a season closed early is judged only on the sessions it had.
- **A target moves with the plan.** A deload lowers it and a fuller plan raises it, which matches how people revise goals after setbacks and success ([[coaching-evidence#IliesJudge2005]]). A reachable target keeps the end-of-season push ([[coaching-evidence#Kivetz2006]]).
- **Product choice.** The 80% line is a product decision anchored on the adherence convention, not a training claim.

## Consequences

- No season goal asks for more than its plan schedules.
- Goal order can change when a removed goal comes back, because it returns as a new row.
- A season with no plan rows yet, including one that closes before any plan was written, keeps the formula targets it opened with.
- The long-run, race-margin, peak-weekly-km and consistency goals are unchanged.

## See also

- [[gamification]], [[coaching-evidence]], [[a-goalless-season-counts-consistent-weeks]]
