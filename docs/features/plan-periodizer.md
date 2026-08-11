---
title: Plan — deterministic periodizer and the Plan tab
description: The rules-only training periodizer that fills the Plan tab, its two modes, the render-time readiness clamp, and the render-time volume redistribution
tags: [feature, run]
status: living
reviewed: 2026-08-10
code_refs:
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/WeekPlanBuilder.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/DistanceBandKm.php
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Services/Run/Plan/VolumeRedistributor.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Models/PlannedSession.php
  - app/Models/Season.php
  - app/Models/SeasonGoal.php
  - app/Http/Controllers/PlanController.php
  - app/Console/Commands/Run/RegeneratePlanCommand.php
  - resources/js/pages/Plan.tsx
---

# Plan — deterministic periodizer and the Plan tab

The training instrument's forward half: a rules-only periodizer that fills a per-day plan (`planned_sessions`), rendered on its own top-level `/plan` tab. **Rules own every number, the LLM owns voice only** — there is no LLM call anywhere in this feature (see the "Plan authorship" row in the v2 program's locked decisions).

## Two modes, chosen fresh on every regeneration

[Periodizer::regenerate()](app/Services/Run/Plan/Periodizer.php) reads the user's active [[race-projection|race]] (`RaceGoal::query()->where('user_id', $id)->active()->first()`) and picks a mode. A mode switch (setting or clearing a race) takes effect at the *next* regeneration, never retroactively.

- **Race-oriented** ([PhaseSchedule::forRace()](app/Services/Run/Plan/PhaseSchedule.php)): taper length scales with race distance (1 week &le;15&nbsp;km, 2 weeks 15&ndash;25&nbsp;km, 3 weeks &gt;25&nbsp;km). Too little time to build anything (`weeksToRace &le; taperWeeks + 1`) skips straight to a taper-only arc. Otherwise the weeks remaining after the taper split `base 30% / build 45% / peak 25%` (peak and build each floored at 1 week, base absorbs the remainder so the three always sum exactly — no rounding drift). Build ramps volume ~7.5%/week compounding; Peak sits at `build's final multiplier * 0.92`; Taper reduces further along a `-20% / -40% / -60%` curve (the nearest-to-race week always lands on the steepest cut, scaled for shorter tapers) — see [PhaseSchedule::volumeMultipliers()](app/Services/Run/Plan/PhaseSchedule.php).
- **Self-scaled** ([PhaseSchedule::selfScaled()](app/Services/Run/Plan/PhaseSchedule.php)): no taper/peak exists without a race date to count back from, so it cycles a repeating 3-weeks-build : 1-week-deload mesocycle (deload at `build's final multiplier * 0.65`) indefinitely.

Both modes materialize up to `Periodizer::HORIZON_WEEKS` (12) weeks of rows; a race-oriented arc that resolves to fewer weeks (the plan never extends past race day) simply writes fewer.

## Shared session-structure logic

[TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php) reads the athlete's own recent behavior fresh on every call (never frozen): sessions/week from the trailing 4-week average run count (clamped 3-6), and a long-run reference from the longest run in the trailing 28 days. [WeekPlanBuilder](app/Services/Run/Plan/WeekPlanBuilder.php) turns one week's `(phase, sessionsPerWeek)` into a row per calendar day — a fixed day-of-week template per session count (the last training day is always the week's `long` run), quality slots sized/typed by phase (Base: at most one Tempo once there's a session to spare; Build/Peak/Taper: 1-2 Tempo/Interval, with self-scaled Build staying threshold-only per its "1-2 threshold sessions" spec wording; Peak/Taper for a marathon-distance race narrows to one Marathon-paced session and the long run itself becomes a race-pace simulation), everything else Easy, everything not training Rest.

`distance_band` (`short`/`medium`/`long`/`rest`) is derived purely from `session_type` at generation time — **no km is ever computed or stored on a row**. `session_type` is intentionally distinct from `pace_band` (the VDOT-derived numeric target): a `long` session pairs with `easy` pace unless it's that marathon-pace race simulation.

## Editable, pinned, regenerated

A day can be moved (date), resized (`distance_band`), blocked (`session_type = rest`), pinned, or deleted via [PlanController::update()/destroy()](app/Http/Controllers/PlanController.php) (`PATCH`/`DELETE /plan/sessions/{plannedSession}`). Any explicit edit auto-pins the row (so the next regeneration doesn't silently revert it) unless the request explicitly passes `pinned: false`. `Periodizer::regenerate()` reads every pinned row in the horizon *before* building any week and never assigns a row to a pinned date; `WeekPlanBuilder` also skips any date before `$today` so **past weeks are never touched**. Regeneration is weekly ([routes/console.php](routes/console.php), `plan:regenerate` at Monday 00:07, after the existing 00:01/00:05 cluster) plus on-demand (`POST /plan/regenerate`).

Each regeneration deletes every unpinned row across the *full* 12-week horizon before writing (not just the freshly-computed weeks), so a shrinking horizon — e.g. switching from self-scaled to a near-term race — cleans up stale far-future rows from the old mode rather than leaving orphans.

## Readiness clamp — render-time, deterministic, not a narrator

[ReadinessClamp::apply()](app/Services/Run/Plan/ReadinessClamp.php) compares a stored session's implied intensity against the CURRENT [ReadinessCeiling](app/Services/Run/Metrics/ReadinessCeiling.php) and, when the stored session asks for more than the ceiling allows, returns a downgraded view plus one of a handful of short templated strings (e.g. *"Your form dipped, so today's the easy version instead."*) — this is a rule-driven downgrade message, never an LLM call, never a new `AnalysisType`. [PlanController::index()](app/Http/Controllers/PlanController.php) applies it to **today's row only**: a future day's readiness isn't knowable today, and clamping a whole training block by this moment's fatigue would defeat periodization. The stored row is never mutated — only the rendered payload reflects the clamp.

Quality work (Tempo/Interval) needs the optimistic `QualityOk` ceiling; a Long day only needs `ModerateOk` (it's a volume day, not an intensity one); Easy needs the floor above `Rest`.

## Volume redistribution — a recompute, not a day-swap

Rather than moving a specific day's volume to another specific day (which risks an awkward doubled-up load), every `/plan` read recomputes the current week's remaining unpinned, non-past training days from `(week's target km) - (already completed) - (pinned days' km) - (today's now-fixed km)`, spread proportionally by each day's existing relative band weighting, then re-bucketed to the nearest band — see [VolumeRedistributor::redistribute()](app/Services/Run/Plan/VolumeRedistributor.php). A readiness-clamped day's lost volume folds in automatically: it's excluded from the eligible pool and its (now smaller) fixed contribution is what gets subtracted from the target, the same way a completed run would be. This, too, is render-only.

## Render-time km — never frozen into the row

[DistanceBandKm::kmFor()](app/Services/Run/Plan/DistanceBandKm.php) is the only place a `distance_band` becomes an actual kilometre figure, combining the athlete's *current* long-run baseline with a phase-derived volume multiplier. [PlanController::index()](app/Http/Controllers/PlanController.php) recomputes this fresh on every page load (never reading a stored km), so a week regenerated weeks ago still displays honestly against the athlete's fitness today.

## Season — the arc this plan belongs to (Slice 7)

The Plan tab's top-of-page summary section is the same periodized arc viewed at a higher zoom, not a separate page (`Season IS the training block` — see the v2 program's locked decisions). [SeasonService::ensureCurrent()](app/Services/Run/Plan/SeasonService.php) is called both from `PlanController::index()` (a fresh user's first page view already has a season) and from `Periodizer::regenerate()` (the weekly job and on-demand regeneration keep it in lockstep with the plan's own mode). A self-scaled `Season` runs a fixed 12 weeks (matching `HORIZON_WEEKS`) and auto-cycles into a fresh one on expiry; a race-oriented one ends on `race_date`. Setting or clearing a `RaceGoal` mid-season closes the current season early (`ends_at` moves to the day before) and opens the other mode at the next call — never a gap, never an overlap, since the mode check always compares the CURRENT active race against the latest season's `race_goal_id`.

5 `SeasonGoal` rows generate once, at creation — see [[gamification]] for the full list and the rest-day reward mechanism that isn't a `Badge`.

## Extracted: interval detection

[IntervalDetector::detect()](app/Services/Run/Metrics/IntervalDetector.php) is [LapsTool](app/Services/AI/Agent/Tools/LapsTool.php)'s original `reps()` heuristic (pace-spread threshold, midpoint split, non-adjacency rejection), pulled out as a pure function so the periodizer and other session-structure code can reuse it without going through the LLM tool layer. No behavior change from the original inline logic.

See also [[race-projection]] (the `RaceGoal` this periodizer reads), [[training-load-metrics]] (the CTL/ATL/monotony inputs `Readiness` clamps against, and the season's CTL-growth goal), and [[gamification]] (season goals, the rest-day reward, and the badge board).
