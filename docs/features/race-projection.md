---
title: Race — goal race and Riegel projection
description: The first user-authored object in the app — a race the user is training for and a fitted-Riegel finish-time projection
tags: [feature, run]
status: living
reviewed: 2026-09-24
code_refs:
  - app/Models/RaceGoal.php
  - app/Http/Controllers/RaceController.php
  - app/Http/Requests/StoreRaceGoalRequest.php
  - app/Services/Run/Metrics/RiegelProjector.php
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Inertia/GamificationProps.php
  - resources/js/components/race/RaceDuel.tsx
  - resources/js/components/race/ProjectionRangeBar.tsx
  - resources/js/components/race/RaceGoalForm.tsx
  - resources/js/pages/Race.tsx
---

# Race — goal race and Riegel projection

The first genuinely user-authored object in the app: a race the user is training for, entered on [Race.tsx](resources/js/pages/Race.tsx) at `/race` and served by [RaceController](app/Http/Controllers/RaceController.php).

## Naming: "Race", not "Goal"

A `GoalResolver` already meant something else entirely when this feature landed: a config-driven accessory-unlock progress catalog, since removed with the rest of the unlock system. Introducing a second "goal" concept would have collided in the UI. **The user-facing name for this feature is "Race"** — route, controller, page, nav copy. The DB/model layer still says "goal" ([RaceGoal](app/Models/RaceGoal.php), `race_goals` table) since that's an implementation detail invisible to users. The two features were always separate, and the catalog that forced the naming call is gone; see [[gamification]].

## Schema: one active race, history retained

`race_goals` has no `unique(user_id, ...)` constraint — that shape only fits "at-most-one-ever" (like `personal_records`' `unique(user_id, category)`), not "at-most-one-active-but-keep-history". Instead, a nullable `completed_at` marks a row inactive, and "one active per user" is enforced at the application layer inside [RaceController::store()](app/Http/Controllers/RaceController.php): every submission transactionally marks the current active row `completed_at = now()` and inserts a new one. This is also how "edit" works from the user's side — there's no separate update endpoint; resubmitting the form supersedes the active race while the old row stays on record.

## Riegel projection: fitted, not assumed

[RiegelProjector](app/Services/Run/Metrics/RiegelProjector.php) projects a finish time for the race distance from Riegel's formula, `T2 = T1 * (D2/D1)^exponent`, but fits the exponent from the athlete's own [PersonalRecord](app/Models/PersonalRecord.php) rows via log-log linear regression instead of assuming the population-average 1.06.

`personal_records` holds at most 11 rows per user (6 distance categories + 5 effort-window categories, `unique(user_id, category)`, no time-series) — a thin sample is the *common* case, not an edge case:

- **0 usable PRs** → no projection (nothing to anchor to).
- **1 usable PR** → falls back to the default 1.06 exponent, anchored on that one PR, with the widest uncertainty band the projector ever produces.
- **≥2 usable PRs** → fits both the exponent and intercept via log-log regression, clamped to `[0.90, 1.30]` so a noisy 2-point fit can't extrapolate into a physiologically meaningless slope.

The uncertainty band (`low_sec`/`high_sec`) widens as the sample thins — see `HALF_WIDTH_BY_SAMPLE` in the projector — so the UI never claims false precision from one or two data points.

## The fit reads the current block, not the whole record

`personal_records` has no time-series, so a category keeps whichever record was set last — a spring 10 km can still be on file after an autumn block has moved the athlete well past it. Regressing today's shape against those rows fits the exponent to a fade that has since been trained out: for one athlete a stale spring set pulled the fitted exponent to 1.1075 and the 10 km projection to 64:21, against 57:49 from their current block alone, which was enough for [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php) to keep prescribing an extra quality session every week.

`RiegelProjector::RECENT_MONTHS` (4) bounds the fit on `set_at`, as of the projection date. Fewer than two records survive that window and the fit falls back to the whole record rather than dropping to a single-PR default — a thin recent sample is worse evidence than a complete stale one. The chosen window travels with the payload as `window` (`recent` / `all`) and renders as the projection's provenance in [RaceDuel](resources/js/components/race/RaceDuel.tsx), beside the sample size.

Effort-window PRs (`Best5Min` etc.) store a **pace** (sec/km), not elapsed time — `RiegelProjector` converts each to a `(distance, time)` pair (`distance_m = window_sec / pace_sec_per_km * 1000`, `time_sec = window_sec`) before fitting alongside distance-category rows.

This is deliberately **not** reconciled with [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php), which solves training-pace prescription (a `min()` reduction across PRs), not race-time projection — different questions, no shared math.

## The page: goal against projection

The page leads with one duel card, [RaceDuel](resources/js/components/race/RaceDuel.tsx), under a compact "your race." header with a "plan →" link. It sets the goal time against the projected finish with the gap in words ("8:29 behind", "2:10 ahead", "on goal" within 5 seconds), a straight [ProjectionRangeBar](resources/js/components/race/ProjectionRangeBar.tsx) marking the goal against the projected range, then the race line and the PR basis. A Temari watermark is posed from the gap relative to the goal time. With no projection the card shows the goal alone. Why this shape: [[race-page-leads-with-goal-vs-projection]].

Under the card, a row behind a hairline holds "edit race" and a quiet ember "clear race". "edit race" expands [RaceGoalForm](resources/js/components/race/RaceGoalForm.tsx) inline, collapsed by default; a successful save collapses it again, and reopening remounts it on the saved values. "clear race" confirms through the concerned-pose `TemariNudgeModal` before `DELETE /race`, which retires the race and regenerates the plan onto its self-scaled arc. With no race set, the page shows only a one-line prompt and a "set a race" button that expands the same form.

## No fitness trend here any more

`PP3` cut `/race`'s 90-day CTL chart (P26): the prototype draws that chart once, on Trends. `PS6` built Trends' panel on `LineChart` directly rather than reusing `CtlTrendChart`, which left that component with no consumer at all; `W2` swept it. [TrainingLoad::ctlTrend()](app/Services/Run/Metrics/TrainingLoad.php) still feeds the CTL line inside Trends' "vs a month ago" comparison — see [[trends]] and [[training-load-metrics]] for the full CTL/ATL engine.

## Sharing and cache busting

The active race is shared app-wide via `activeRace` in [GamificationProps](app/Services/Inertia/GamificationProps.php), deliberately thin (no projection math on every page load — that's computed only on `/race` itself). Cached per user behind [SharedPropCacheKey::ActiveRace](app/Support/SharedPropCacheKey.php), busted on every `RaceGoal` write via its `saved`/`deleted` model hooks, plus an explicit post-commit bust in `RaceController::store()` (the same pattern as `AccessoryController::equip()` — the model hook alone fires mid-transaction, before commit, which could let a concurrent read re-cache stale state).
