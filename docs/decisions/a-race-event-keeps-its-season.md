---
title: A race event keeps its season until it is replaced
description: Editing a race revises the same event and keeps its season, an explicit "new race" starts a new event and season, and every date and target the event had stays on record.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-01
code_refs:
  - app/Services/Run/Plan/RaceGoalService.php
  - app/Http/Controllers/RaceController.php
  - app/Http/Requests/StoreRaceGoalRequest.php
  - app/Models/RaceGoalChange.php
  - app/Services/Run/Plan/SeasonService.php
  - tests/Unit/Services/Run/Plan/RaceGoalServiceTest.php
---

# A race event keeps its season until it is replaced

**Status:** Accepted (2026-10-01). Supersedes the "resubmitting the form supersedes the active race" behaviour described in [[race-projection]] and in `RaceController::store()`'s earlier docblock.

## Context

`RaceController::store()` retired the active race and inserted a new row on every submission, and [[plan-periodizer]]'s season identity is the race row's id. So correcting a target time or moving the date ended the season and opened a new one, and a genuinely different race looked the same as an edit. Neither the athlete nor the code could say which they meant.

## Decision

- **The race row is the event.** Submitting the race form with a race already active carries an explicit `intent`: `update` (the default) revises that row in place, `new` retires it and creates another. With no active race the intent is ignored. Nothing infers an intent from the changed name, distance or date. See [RaceGoalService](app/Services/Run/Plan/RaceGoalService.php).
- **A revision keeps the season.** A new target time, or a new date, leaves `race_goal_id` unchanged, so [SeasonService::isCurrent()](app/Services/Run/Plan/SeasonService.php) keeps the season and only its end date follows the race. A date change is a postponement of the same event. A different distance is a different race: an `update` that changes it is rejected and the athlete is pointed at "Add a new race".
- **A new event starts a season.** `new` retires the previous event (cancelled if its date had not come, otherwise left as it was) and creates the new one; the next `ensureCurrent()` opens a season for it. A season created the same day as the switch is retargeted in place, as before, because of `unique(user_id, starts_at)`.
- **History is append-only.** Every creation, target revision, postponement, replacement, cancellation and outcome appends a `race_goal_changes` row carrying the date and target as they became ([RaceGoalChange](app/Models/RaceGoalChange.php)), so past ambitions and dates stay auditable.
- **Replanning is conditional.** The plan is rebuilt only when the date or target actually changed or an event was created; a repeated or name-only submission rebuilds nothing and adds no history. Onboarding goes through the same creation path.

## Consequences

- Editing a race no longer opens a season; code that relied on every edit producing a new row must read `race_goal_changes` instead.
- Legacy rows have no history and no outcome; they read exactly as before.

## See also

- [[a-race-outcome-is-confirmed-not-assumed]], [[the-arc-is-anchored-once]]
