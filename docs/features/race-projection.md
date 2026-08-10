---
title: Race — goal race and Riegel projection
description: The first user-authored object in the app — a race the user is training for, a fitted-Riegel finish-time projection, and the 90-day fitness trend
tags: [feature, run]
status: living
reviewed: 2026-08-10
code_refs:
  - app/Models/RaceGoal.php
  - app/Http/Controllers/RaceController.php
  - app/Http/Requests/StoreRaceGoalRequest.php
  - app/Services/Run/Metrics/RiegelProjector.php
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Inertia/GamificationProps.php
  - resources/js/pages/Race.tsx
  - resources/js/components/race/CtlTrendChart.tsx
---

# Race — goal race and Riegel projection

The first genuinely user-authored object in the app: a race the user is training for, entered on [Race.tsx](resources/js/pages/Race.tsx) at `/race` and served by [RaceController](app/Http/Controllers/RaceController.php).

## Naming: "Race", not "Goal"

`/goals`, [GoalController](app/Http/Controllers/GoalController.php) and [GoalResolver](app/Services/Gamification/GoalResolver.php) already mean something else entirely: a config-driven, static accessory-unlock progress catalog with no DB table. Introducing a second "goal" concept here would collide in the UI. **The user-facing name for this feature is "Race"** — route, controller, page, nav copy. The DB/model layer still says "goal" ([RaceGoal](app/Models/RaceGoal.php), `race_goals` table) since that's an implementation detail invisible to users. The two features stay completely separate; [[gamification]]'s catalog is untouched by this note.

## Schema: one active race, history retained

`race_goals` has no `unique(user_id, ...)` constraint — that shape only fits "at-most-one-ever" (like `personal_records`' `unique(user_id, category)`), not "at-most-one-active-but-keep-history". Instead, a nullable `completed_at` marks a row inactive, and "one active per user" is enforced at the application layer inside [RaceController::store()](app/Http/Controllers/RaceController.php): every submission transactionally marks the current active row `completed_at = now()` and inserts a new one. This is also how "edit" works from the user's side — there's no separate update endpoint; resubmitting the form supersedes the active race while the old row stays on record.

## Riegel projection: fitted, not assumed

[RiegelProjector](app/Services/Run/Metrics/RiegelProjector.php) projects a finish time for the race distance from Riegel's formula, `T2 = T1 * (D2/D1)^exponent`, but fits the exponent from the athlete's own [PersonalRecord](app/Models/PersonalRecord.php) rows via log-log linear regression instead of assuming the population-average 1.06.

`personal_records` holds at most 11 rows per user (6 distance categories + 5 effort-window categories, `unique(user_id, category)`, no time-series) — a thin sample is the *common* case, not an edge case:

- **0 usable PRs** → no projection (nothing to anchor to).
- **1 usable PR** → falls back to the default 1.06 exponent, anchored on that one PR, with the widest uncertainty band the projector ever produces.
- **≥2 usable PRs** → fits both the exponent and intercept via log-log regression, clamped to `[0.90, 1.30]` so a noisy 2-point fit can't extrapolate into a physiologically meaningless slope.

The uncertainty band (`low_sec`/`high_sec`) widens as the sample thins — see `HALF_WIDTH_BY_SAMPLE` in the projector — so the UI never claims false precision from one or two data points.

Effort-window PRs (`Best5Min` etc.) store a **pace** (sec/km), not elapsed time — `RiegelProjector` converts each to a `(distance, time)` pair (`distance_m = window_sec / pace_sec_per_km * 1000`, `time_sec = window_sec`) before fitting alongside distance-category rows.

This is deliberately **not** reconciled with [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php), which solves training-pace prescription (a `min()` reduction across PRs), not race-time projection — different questions, no shared math.

## 90-day fitness trend

[TrainingLoad::ctlTrend()](app/Services/Run/Metrics/TrainingLoad.php) needed no new storage: `rollDailySeries` (the EWMA roll every CTL/ATL computation already runs) now returns every day's pair instead of only the last one, and `ctlTrend` slices the tail. See [[training-load-metrics]] for the full CTL/ATL engine.

## Sharing and cache busting

The active race is shared app-wide via `activeRace` in [GamificationProps](app/Services/Inertia/GamificationProps.php), deliberately thin (no projection math on every page load — that's computed only on `/race` itself). Cached per user behind [SharedPropCacheKey::ActiveRace](app/Support/SharedPropCacheKey.php), busted on every `RaceGoal` write via its `saved`/`deleted` model hooks, plus an explicit post-commit bust in `RaceController::store()` (the same pattern as `AksesoriController::equip()` — the model hook alone fires mid-transaction, before commit, which could let a concurrent read re-cache stale state).
