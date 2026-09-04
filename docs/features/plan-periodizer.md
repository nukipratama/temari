---
title: Plan — deterministic periodizer and the Plan tab
description: The rules-only training periodizer that fills the Plan tab, its two modes, the render-time readiness clamp, and the render-time volume redistribution
tags: [feature, run]
status: living
reviewed: 2026-08-29
code_refs:
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/WeekPlanBuilder.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Models/TrainingPreference.php
  - app/Enums/ExperienceLevel.php
  - app/Enums/GoalType.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/SessionSegment.php
  - app/Enums/SegmentKey.php
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Services/Run/Plan/VolumeRedistributor.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Console/Commands/Run/ScoreComplianceCommand.php
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - app/Enums/AdaptationReason.php
  - app/Enums/PlannedSessionStatus.php
  - app/Models/PlanAdaptation.php
  - app/Models/PlannedSession.php
  - app/Models/Season.php
  - app/Models/SeasonGoal.php
  - app/Http/Controllers/PlanController.php
  - app/Services/Gamification/SeasonStreakSummaryBuilder.php
  - app/Services/Run/Plan/SeasonSummaryBuilder.php
  - app/Console/Commands/Run/RegeneratePlanCommand.php
  - app/Services/AI/PlanNarrationRequester.php
  - app/Services/AI/Narrators/PlanDayVoiceNarrator.php
  - app/Services/AI/Narrators/PlanWeekVoiceNarrator.php
  - app/Services/AI/Narrators/PlanSeasonVoiceNarrator.php
  - app/Jobs/AI/AnalyzePlanDayVoiceJob.php
  - app/Jobs/AI/AnalyzePlanWeekVoiceJob.php
  - app/Jobs/AI/AnalyzePlanSeasonVoiceJob.php
  - resources/js/pages/Plan.tsx
---

# Plan — deterministic periodizer and the Plan tab

The training instrument's forward half: a rules-only periodizer that fills a per-day plan (`planned_sessions`), rendered on its own top-level `/plan` tab. **Rules own every number, the LLM owns voice only** (see the "Plan authorship" row in the v2 program's locked decisions) — every figure in this doc through "Season" below is deterministic; the one LLM layer, "Plan narration" at the end, only narrates verdicts the rules already reached and never computes anything itself.

## Two modes, chosen fresh on every regeneration

[Periodizer::regenerate()](app/Services/Run/Plan/Periodizer.php) reads the user's active [[race-projection|race]] (`RaceGoal::query()->where('user_id', $id)->active()->first()`) and picks a mode. A mode switch (setting or clearing a race) takes effect at the *next* regeneration, never retroactively.

- **Race-oriented** ([PhaseSchedule::forRace()](app/Services/Run/Plan/PhaseSchedule.php)): taper length scales with race distance (1 week &le;15&nbsp;km, 2 weeks 15&ndash;25&nbsp;km, 3 weeks &gt;25&nbsp;km). Too little time to build anything (`weeksToRace &le; taperWeeks + 1`) skips straight to a taper-only arc. Otherwise the weeks remaining after the taper split `base 30% / build 45% / peak 25%` (peak and build each floored at 1 week, base absorbs the remainder so the three always sum exactly — no rounding drift). Build ramps volume ~7.5%/week compounding; Peak sits at `build's final multiplier * 0.92`; Taper reduces further along a `-20% / -40% / -60%` curve (the nearest-to-race week always lands on the steepest cut, scaled for shorter tapers) — see [PhaseSchedule::volumeMultipliers()](app/Services/Run/Plan/PhaseSchedule.php).
- **Self-scaled** ([PhaseSchedule::selfScaled()](app/Services/Run/Plan/PhaseSchedule.php)): no taper/peak exists without a race date to count back from, so it cycles a repeating 3-weeks-build : 1-week-deload mesocycle (deload at `build's final multiplier * 0.65`) indefinitely.

Both modes materialize up to `Periodizer::HORIZON_WEEKS` (12) weeks of rows; a race-oriented arc that resolves to fewer weeks (the plan never extends past race day) simply writes fewer.

## Shared session-structure logic

[TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php) reads the athlete's own recent behavior fresh on every call (never frozen): sessions/week from the trailing 4-week average run count (clamped 3-6), and a long-run reference from the longest run in the trailing 28 days. An explicit [TrainingPreference](app/Models/TrainingPreference.php) row sits above that behavioral read, not beside it: a set `sessions_per_week` always overrides the behavioral average (bypassing its 3-6 clamp — 2 is a valid explicit choice, just not a valid *inferred* one), and with zero logged weeks *and* no explicit `sessions_per_week`, a stated `experience_level` seeds which cold-start `[sessions, km]` pair to use instead of one flat default for every brand-new athlete. Real logged behavior always wins the moment any exists, regardless of what was once claimed. `TrainingPreference.goal_type` carries no computational weight in this class or anywhere else in the periodizer — it's narration-flavor signal only, since `RaceGoal`'s presence/absence already fully selects the periodization mode below.

[WeekPlanBuilder](app/Services/Run/Plan/WeekPlanBuilder.php) turns one week's `(phase, sessionsPerWeek)` into a row per calendar day — a fixed day-of-week template per session count 2-6 (the last training day is always the week's `long` run), quality slots sized/typed by phase (Base: at most one Tempo once there's a session to spare; Build/Peak/Taper: 1-2 Tempo/Interval, with self-scaled Build staying threshold-only per its "1-2 threshold sessions" spec wording; Peak/Taper for a marathon-distance race narrows the quality block to one race-pace-specific Tempo slot), everything else Easy, everything not training Rest. When `TrainingPreference.run_days`/`long_run_day` are both set, they replace the day template entirely for that user — the chosen weekdays become the literal training days every regenerated week, with the flagged day always the long run — rather than merely seeding it; `Periodizer::regenerate()` is the only caller that threads this override through. `WeekPlanBuilder` decides `session_type` only — no km, pace or segment structure enters generation.

[SegmentGenerator::generate()](app/Services/Run/Plan/SegmentGenerator.php) turns `(session_type, phase)` into the day's full ordered [SessionSegment](app/Services/Run/Plan/SessionSegment.php) list — warmup, main effort, interval reps, cooldown, each keyed by [SegmentKey](app/Enums/SegmentKey.php) — combining the athlete's *current* long-run baseline, a phase-derived volume multiplier, and VDOT-derived paces. Render-time only, same discipline `DistanceBandKm` established before this class replaced it: **nothing here is stored on a row**, so a week rendered weeks after it was generated still reflects fitness gained since. Rest gets no segments; Easy and Long are a single unsegmented block (no effort change to warm into); Tempo and Interval get a fixed-duration warmup/cooldown (10/5 min, 12/8 min) around a core effort, with warmup/cooldown deliberately never scaled by volume redistribution — they're physiological readiness, not volume. Interval's rep count is dynamic (phase-scaled work-budget ÷ rep length), and rep length/recovery itself is phase-keyed (Build 3:2, Peak 4:2, Taper 2:3 minutes) — sharper early, sustained at Peak, short and generously recovered in Taper so the athlete arrives fresh. The headline `distance_km` a day ships with is always the CORE work only ({@see SegmentGenerator::coreKmFor()}) — pace-independent, so it's available even with no VDOT estimate yet; warmup/cooldown minutes are additional on top, not counted in it.

## Editable, pinned, regenerated

A day can be moved (date) or skipped via [PlanController::update()](app/Http/Controllers/PlanController.php) (`PATCH /plan/sessions/{plannedSession}`). Skipping leaves the prescribed session as-is but pre-emptively excuses the athlete from being graded on it once the compliance pass reaches it. **Moving is a swap, not a re-date**: `planned_sessions` is unique on `(user_id, date)` and `WeekPlanBuilder` materializes all seven days of every week, so every in-horizon target is already occupied — `update()` trades what the two days prescribe, each keeping its own calendar slot, and pins both. Per-segment editing (a Tempo day's warmup length, an Interval day's rep count) isn't a stored field to edit — segments are computed fresh at render time, not persisted. Any explicit edit auto-pins the row (so the next regeneration doesn't silently revert it) unless the request explicitly passes `pinned: false`.

Pin, block (`session_type = rest`) and delete were **cut** by the prototype-parity program's `PS4` (decision P23 — the prototype draws Skip and Move and nothing else). The `DELETE /plan/sessions/{id}` route and the `session_type` validation rule are gone with them; `pinned` survives as internal plumbing, set by `update()` rather than by any UI. `Periodizer::regenerate()` reads every pinned row in the horizon *before* building any week and never assigns a row to a pinned date; `WeekPlanBuilder` also skips any date before `$today` so **past weeks are never touched**. Regeneration is weekly ([routes/console.php](routes/console.php), `plan:regenerate` at Monday 00:07, after the existing 00:01/00:05 cluster) plus on-demand (`POST /plan/regenerate`).

Each regeneration deletes every unpinned row across the *full* 12-week horizon before writing (not just the freshly-computed weeks), so a shrinking horizon — e.g. switching from self-scaled to a near-term race — cleans up stale far-future rows from the old mode rather than leaving orphans.

## Readiness clamp — render-time, deterministic, not a narrator

This is the *within-the-day* half of the readiness reaction; the *within-the-week* half (a real deload) is in "Reacting to what actually happened" below.

[ReadinessClamp::apply()](app/Services/Run/Plan/ReadinessClamp.php) compares a stored session's implied intensity against the CURRENT [ReadinessCeiling](app/Services/Run/Metrics/ReadinessCeiling.php) and, when the stored session asks for more than the ceiling allows, returns a downgraded view plus one of a handful of short templated strings (e.g. *"Your form dipped, so today's the easy version instead."*) — this is a rule-driven downgrade message, never an LLM call, never a new `AnalysisType`. [PlanController::index()](app/Http/Controllers/PlanController.php) applies it to **today's row only**: a future day's readiness isn't knowable today, and clamping a whole training block by this moment's fatigue would defeat periodization. The stored row is never mutated — only the rendered payload reflects the clamp. [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) applies the identical clamp for Home's own current-week read (the `weekPlan` Inertia prop, `DashboardController`) — the two never disagree about today's row because they call the same `ReadinessClamp::apply()`.

Quality work (Tempo/Interval) needs the optimistic `QualityOk` ceiling; a Long day only needs `ModerateOk` (it's a volume day, not an intensity one); Easy needs the floor above `Rest`.

## Volume redistribution — a recompute, not a day-swap

Rather than moving a specific day's volume to another specific day (which risks an awkward doubled-up load), every `/plan` read recomputes the current week's remaining unpinned, non-past training days from `(week's target km) - (already completed) - (pinned days' km) - (today's now-fixed km)`, and applies a single continuous scale factor to each remaining day's own core km — see [VolumeRedistributor::redistribute()](app/Services/Run/Plan/VolumeRedistributor.php). No band bucketing: `SegmentGenerator` already accepts an arbitrary target distance for any session type, so a redistributed day lands on its exact scaled figure rather than snapping to a small set of discrete sizes. A readiness-clamped day's lost volume folds in automatically: it's excluded from the eligible pool and its (now smaller) fixed contribution is what gets subtracted from the target, the same way a completed run would be. This, too, is render-only.

The scale factor is capped at `VolumeRedistributor::MAX_SCALE`. A week missed until Friday would otherwise land its whole target on the two days that remain, which is the cram week the rest of the engine's clamps exist to prevent; past the cap the volume is **written off rather than carried**. Volume never crosses a week boundary either — a missed week becomes a re-entry deload (below), not a debt added to the next one.

## Reacting to what actually happened

The three sections above are all *render-time* reactions within one week. The generation-time reaction is [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php), which [Periodizer::regenerate()](app/Services/Run/Plan/Periodizer.php) consults before it builds a single week.

**Matching runs to sessions — persisted once a day is in the past.** A day's compliance verdict is a historical judgment, not a forward-looking target, so unlike the render-time computations above it is scored once and frozen: [ScoreComplianceCommand](app/Console/Commands/Run/ScoreComplianceCommand.php) (`plan:score-compliance`, daily at 00:03, before the Monday 00:07 `plan:regenerate`) finds every still-`Planned` row that's now past-due and writes back `status`, `compliance_score` and `ran_anyway` via [SessionMatcher::scoreRange()](app/Services/Run/Plan/SessionMatcher.php). It doubles as the backfill mechanism for any historical backlog — the query is "any Planned row that's now past, regardless of age," so there's no separate backfill command. [SessionMatcher::scoreFor()](app/Services/Run/Plan/SessionMatcher.php) grades km actually run against km prescribed as a continuous `round(completedKm/plannedKm * 100)` score: `<35` is `missed`, `35-84` is `partial`, `85-129` is `done`, `>=130` is `overreached` (see [PlannedSessionStatus](app/Enums/PlannedSessionStatus.php); `isCredited()` counts `done`/`partial`/`overreached`, not `planned`/`missed`/`skip`). A day the athlete pre-emptively excuses (`PATCH /plan/sessions/{id}` with `skipped: true`) always resolves to `skip` regardless of km, with a null score. A rest day asks for nothing, so it always reads `done` with a null score; `ran_anyway` separately records whether an activity was logged on it anyway, without changing the status. [PlanController](app/Http/Controllers/PlanController.php) and [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) read the stored `status` column as the primary source of truth; `SessionMatcher::statuses()` is now only a defensive render-time fallback for the rare row still `Planned` despite being past-dated (the daily command hasn't reached it yet). [PlanAdapter::previousWeekAdherencePct()](app/Services/Run/Plan/PlanAdapter.php) averages last week's persisted `compliance_score` (each day capped at 100 first, so one `overreached` day can't mask other missed ones), excluding rest, still-unscored and `skip` days, and defaults to perfect adherence when nothing was scoreable at all.

**One decision per week, safety first.** [PlanAdapter::decide()](app/Services/Run/Plan/PlanAdapter.php) is pure and returns exactly one [AdaptationReason](app/Enums/AdaptationReason.php), evaluated in priority order: readiness bottoming out at `Rest`, then monotony at Foster's injury-risk threshold, then weekly strain past a multiple of CTL, then a mostly-missed week, and only then race-pace feedback. Chasing a goal time can therefore never talk the plan past a red flag.

**A real deload, not a note.** The first four reasons rewrite the current week's phase to [PlanPhase::Deload](app/Enums/PlanPhase.php) before any row is built. That is structural in both directions at once: `WeekPlanBuilder` emits no quality slots for a `Deload` week, and because `volumeMultipliers()` is recomputed at render from the *stored* phase sequence, the week's km drop to `previous build's final multiplier * 0.65` with no separate scale factor to keep in sync. A `Taper` week is exempt — it is already a planned reduction counting down to race day.

**A missed week comes back smaller.** Adherence below `MISSED_WEEK_ADHERENCE` produces the same deload rather than carrying the missed volume forward. Simulated end to end in [tests/Feature/Plan/MissedWeekAdaptationTest.php](tests/Feature/Plan/MissedWeekAdaptationTest.php): a 4-session, 28.3 km build week with nothing logged against it is followed by a 16.6 km deload week carrying zero quality sessions.

**The race goal moves the sessions.** With an active race, [RiegelProjector](app/Services/Run/Metrics/RiegelProjector.php)'s projected finish is compared to the stored `goal_time_sec`. Outside `RACE_GAP_MARGIN` in either direction the adapter returns a `quality_delta`, which `WeekPlanBuilder::build()` applies to every week's quality block: behind the goal adds a session (capped, and only when the week has enough sessions to absorb it), inside the goal removes one. `Deload` and `Taper` weeks are exempt in both directions. Season-goal generation deliberately keeps using the unadapted `qualitySlotCount()`, so a mid-season adaptation doesn't move the goalposts it was scored against.

**Recorded, not recomputed.** The verdict is written to a [PlanAdaptation](app/Models/PlanAdaptation.php) row, `unique(user_id, week_start)`. `/plan` reads that row rather than re-deciding, so a deload triggered on Monday still explains itself on Thursday after the athlete's readiness has recovered. The copy lives on the enum ([AdaptationReason::headline()/detail()](app/Enums/AdaptationReason.php)), never in the database.

**Prescriptive, not clinical.** The Plan tab renders a standing not-medical-advice disclaimer on every load, adaptation or not ([Plan.tsx](resources/js/pages/Plan.tsx)): its own headed card, set at the supporting-body tier, with a link out to the full scope. Since `PS4` it sits at the foot of the page rather than above the timeline — the prototype draws no disclaimer at all, and the foot is the nearest place that keeps it without displacing a section the prototype does draw. Both the heading and the body are served from [TrainingDisclaimer](app/Support/TrainingDisclaimer.php) rather than held locally, so the public [[legal-pages]] and this tab cannot drift into two different positions. The tone gets to be assertive about numbers precisely because the clamps above stay in force underneath it.

## Render-time segments — never frozen into the row

[SegmentGenerator::coreKmFor()](app/Services/Run/Plan/SegmentGenerator.php) is the only place a `session_type` becomes an actual kilometre figure, combining the athlete's *current* long-run baseline with a phase-derived volume multiplier — the direct successor to `DistanceBandKm::kmFor()`, before `distance_band`/`pace_band` were retired as stored columns. [PlanController::index()](app/Http/Controllers/PlanController.php) recomputes this fresh on every page load (never reading a stored km), so a week regenerated weeks ago still displays honestly against the athlete's fitness today. `SegmentGenerator::generate()` goes one step further for the full segment breakdown: even the *shape* of an Interval day (its rep count) is pace-dependent, so nothing about a day's structure is ever persisted — see the "Shared session-structure logic" section above.

**Shared with Home, not duplicated.** [PlanRenderer](app/Services/Run/Plan/PlanRenderer.php) holds the two computations above — the phase→volume-multiplier grouping and the per-day payload — extracted out of `PlanController` so [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) (Home's `weekPlan` prop, no lookahead, no redistribution since Home never shows a future day's resized volume) can never numerically drift from Plan's own figures for the same week: a Peak/Taper/Deload week's multiplier is relative to how far into that phase block the week sits, which needs the same trailing history both callers query with the identical `HISTORY_WEEKS` window.

## Season — the arc this plan belongs to (Slice 7)

The Plan tab's top-of-page summary section is the same periodized arc viewed at a higher zoom, not a separate page (`Season IS the training block` — see the v2 program's locked decisions). [SeasonService::ensureCurrent()](app/Services/Run/Plan/SeasonService.php) is called both from `PlanController::index()` (a fresh user's first page view already has a season) and from `Periodizer::regenerate()` (the weekly job and on-demand regeneration keep it in lockstep with the plan's own mode). `SeasonService::peekCurrent()` is the read-only counterpart the [[profile]] page uses instead — it returns the current season if one already exists, `null` otherwise, and never creates, updates, or closes a `Season` row. A self-scaled `Season` runs a fixed 12 weeks (matching `HORIZON_WEEKS`) and auto-cycles into a fresh one on expiry; a race-oriented one ends on `race_date`. Setting or clearing a `RaceGoal` mid-season closes the current season early (`ends_at` moves to the day before) and opens the other mode at the next call — never a gap, never an overlap, since the mode check always compares the CURRENT active race against the latest season's `race_goal_id`.

5 `SeasonGoal` rows generate once, at creation — see [[gamification]] for the full list and the rest-day reward mechanism that isn't a `Badge`.

### Season-wide summary — the nested timeline (`V0` fork 3, rebuilt by `PS4`)

`PlanController::index()`'s own `weeks` payload only ever covers a rolling `HISTORY_WEEKS`/`LOOKAHEAD_WEEKS` window (3 history + current + 4 lookahead), not the full season — real scope for a season-wide phase-progress bar and week-by-week volume timeline, deliberately left out of `S4` (see that slice doc's "Deliberately scoped OUT"). [SeasonSummaryBuilder::build()](app/Services/Run/Plan/SeasonSummaryBuilder.php) closes that gap with a separate, season-wide read model (`seasonSummary` Inertia prop): one entry per week from `Season::$starts_at` to `$ends_at`, each carrying its phase, a `history`/`current`/`lookahead` type, a `planned_km` figure, a `sessions` count, and (once a [WeeklySnapshot](app/Models/WeeklySnapshot.php) exists for that week) an `actual_km`. `SeasonSummaryBuilder::adherencePct()` is the season header card's headline figure — the mean of every persisted `compliance_score` inside the season, so it covers weeks the page no longer renders day rows for.

`planned_km` is computed the same deterministic, season-start-anchored way [SeasonService::generateGoals()](app/Services/Run/Plan/SeasonService.php) already sizes its `SeasonGoal` targets — `PhaseSchedule`/`WeekPlanBuilder`/`SegmentGenerator`, never a read of materialized `PlannedSession` rows. Those rows only cover the periodizer's own rolling `Periodizer::HORIZON_WEEKS` (12-week) horizon and get deleted/recreated on every weekly regeneration, so they're the wrong source for a stable, whole-season figure — the same reasoning that already keeps `SeasonGoal` targets off of them. The trade-off: a week's `planned_km` won't reflect a real-time adaptation (e.g. an in-week deload) the way the day-by-day schedule below it on the page does.

**Frontend**: `PS4` replaced fork 3's two flat panels (`SeasonPhaseBar`, `SeasonWeekTimeline`) and the flat day-card list beneath them with the prototype's single nested timeline (decision P22).

[SeasonHeaderCard](../../resources/js/components/plan/SeasonHeaderCard.tsx) heads the page: week X of N, the season adherence figure, one bar per phase (height by that phase's mean weekly volume, so the bar chart traces the season's real arc), and Temari's season narration. Its `phasesOf()` builds the bars from the phase sequence the season actually has, so a self-scaled season's repeating Build/Deload cycle renders honestly rather than being forced into a fixed Base/Build/Peak/Taper four (see `App\Enums\PlanPhase`'s own docblock on which phases exist in which mode).

[SeasonTimeline](../../resources/js/components/plan/SeasonTimeline.tsx) is the rail beneath it. It splits the season into contiguous same-phase runs — not runs keyed on phase *name*, since a self-scaled season passes through Build more than once — shows the run holding the current week in full, and folds the weeks already behind in it, plus every later run, into a [WeekCluster](../../resources/js/components/plan/WeekCluster.tsx) summary row until asked for. Each [SeasonWeekRow](../../resources/js/components/plan/SeasonWeekRow.tsx) opens (the current week by default, every other week closed) onto a [WeekVolumeChart](../../resources/js/components/plan/WeekVolumeChart.tsx) — a dashed planned outline against a filled actual bar coloured by that day's compliance verdict — and a [WeekDayRow](../../resources/js/components/plan/WeekDayRow.tsx) per day. A week outside `PlanController`'s render window has no day rows to open and renders as a flat summary card instead.

A day row opens onto Temari's read on it, a [SessionBarGraph](../../resources/js/components/plan/SessionBarGraph.tsx) (the session's segments, width by minutes and height by zone, with interval repeats collapsed into one "N× Interval" legend block), a link to what was actually run, and the Move/Skip actions. Its collapsed state carries a [MiniSessionBar](../../resources/js/components/plan/MiniSessionBar.tsx) — the same zone strip at 4px.

Phase fill colors are the `PHASE_COLORS` export in [chartTokens.ts](../../resources/js/lib/chartTokens.ts), validated distinct via the dataviz skill's palette checker and deliberately drawn away from `PALETTE.overloaded`/`gassed`/`chill`, which are already committed to per-run Mood colors; zone fills reuse the existing `HR_ZONE_COLORS` ramp. Shared payload types, the adherence mean and the label maps live in [lib/plan.ts](../../resources/js/lib/plan.ts).

## Plan narration — voice only, layered on top

Three `AnalysisType` cases narrate what the rules above already decided, never re-deciding it: `PlanDayVoice`, `PlanWeekVoice`, `PlanSeasonVoice`. All three follow the standard AI narration pipeline (see the "AI narration pipeline" section of CLAUDE.md) — `PlanDayVoiceNarrator`/`PlanWeekVoiceNarrator`/`PlanSeasonVoiceNarrator` under [app/Services/AI/Narrators/](app/Services/AI/Narrators/), one `AnalyzeRowJob` subclass each under [app/Jobs/AI/](app/Jobs/AI/).

**Day narration covers only the current week's 7 days, never the full 12-week horizon.** `PlanDayVoice`'s subject is a synthetic `user_id` + `Y-m-d` discriminator key (mirroring `BriefingMascotVoice`'s own per-user-per-day shape), not the `PlannedSession` row's own id — that row's id is *not* stable across weekly regenerations (`Periodizer::regenerate()` deletes and recreates every unpinned future-date row), so keying on it would silently orphan a day's narration history every Monday even when the actual prescribed session never changed. Editing a day (`PlanController::update()`, skip or move) re-requests that day's narration — both days' narration, for a move — whenever the edited date falls within the current week, so the blurb never keeps describing a session the athlete just changed.

**Week narration attaches to `PlanAdaptation`, not `WeeklySnapshot`.** The existing `WeeklyRecap` type already narrates `WeeklySnapshot` retrospectively ("how'd your week go"); `PlanWeekVoice` is prospective instead ("why does this week's plan look like this"), and `PlanAdaptation` is the periodizer's own decision record for exactly that question — see "Reacting to what actually happened" above. It's structurally bounded to the current week already, since `PlanAdaptation` itself is only ever written for `$currentWeekStart`.

**Season narration attaches to `Season`**, requested on every dispatch but relying on `AnalysisService`'s own idempotency (an already-`Done`, unchanged season is left alone) rather than an explicit "did the season actually change" check.

**Day and week narration re-bill only where the material actually changed.** The Monday sweep runs for every non-demo user, seven days at a time, and the periodizer frequently rewrites a week into something that reads identically — the same session type, phase and prescribed distance produce the same blurb. Each row is stamped with a [MaterialFingerprint](app/Services/AI/MaterialFingerprint.php) of what it describes (`forPlannedSession()` mirrors what [PlanDayTool](app/Services/AI/Agent/Tools/PlanDayTool.php) hands the model; `forPlanAdaptation()` mirrors [PlanWeekTool](app/Services/AI/Agent/Tools/PlanWeekTool.php)), written by the job through `AnalyzeRowJob::fingerprintFor()`, and [PlanNarrationRequester](app/Services/AI/PlanNarrationRequester.php) invalidates a row only when the fingerprint no longer matches. A row with **no** stored fingerprint counts as changed — the inverse of the per-run rule in `DispatchPostRunAnalysis`, deliberately: only the rule-based paths leave the column null (a cost-capped or content-filtered day), and those must stay eligible for a real narration rather than keeping filler forever. A manual edit through `PlanController::update()` still re-narrates unconditionally, since the athlete just changed that day on purpose.

**Dispatched from `Periodizer::regenerate()`'s two callers, never from `Periodizer.php` itself** — [PlanController::regenerate()](app/Http/Controllers/PlanController.php) (manual) and [RegeneratePlanCommand](app/Console/Commands/Run/RegeneratePlanCommand.php) (the weekly cron), via [PlanNarrationRequester](app/Services/AI/PlanNarrationRequester.php). Literally dispatching narration inside `Periodizer.php` would contradict its own "no LLM call anywhere in this feature" heritage; keeping the requester one layer up preserves that boundary while still tying narration to the exact moment the underlying facts change. `RegeneratePlanCommand` narrates every non-demo user's week (`is_demo === false`) — the regenerate half stays exactly as free as before, but the narration half is now real per-user LLM cost, so it's classified `BILLING` in [DemoBillingExclusionTest](tests/Feature/Console/DemoBillingExclusionTest.php) even though the command's own regenerate call is unconditional. The demo account's own Plan page instead fills every block rule-based on view (`PlanNarrationRequester::ensureDemoFilled()`), the same path its manual "Reread" already resolves through, so it never shows a perpetually-Pending block.

**The manual Regenerate button carries a real rate limit, unlike the button that predates this slice.** A full regenerate can dispatch up to 9 narration rows (7 days, the week, the season) — real LLM cost per click — so `PlanController::regenerate()` now checks a dedicated one-hour cooldown (`PlanNarrationRequester::regenerateCooldownRemaining()`/`startRegenerateCooldown()`) before calling the periodizer, started immediately rather than waiting for the async narration jobs to finish (closing the queue-latency window where two rapid clicks could both slip through). It's a standalone `Cooldown` key, not `Analysis::cooldownKey()` reused — every narration row's own completion unconditionally starts its own shorter (15-minute) cooldown in `AnalysisService::markDone()`, so sharing the key would have this longer window silently overwritten within moments. The weekly cron starts the same cooldown after its own regenerate (so a manual click right after Monday's auto-run is still correctly rate-limited) but never checks it — the cron always runs.

### No season track on the page

`PP3` cut the `SeasonTrack` tier module (P24): the prototype's Plan screen draws a
`SeasonHeaderCard` with a single progress line, not a pip rail. The season-scoped reward engine is
unchanged — `GrantSeasonUnlocksAction` still grants a tier under `season.{id}.track_{N}` per
completed `SeasonGoal`, and `season.tiers_kept_from_past_seasons`
([SeasonStreakSummaryBuilder](../../app/Services/Gamification/SeasonStreakSummaryBuilder.php)) still
counts the tiers owned under an earlier season's key namespace. Nothing renders that count now;
`PS4` decides whether the prototype's single line carries it.

The per-goal `GoalCard` grid under the season summary is gone: P24 replaced the tier module with
the prototype's single progress line, and `W2` swept the orphaned component. The week-grained
lifetime streak
(`WeeklySnapshot::consecutiveWeekStreak()`, wrapped by `SeasonStreakSummaryBuilder::streakPayload()`)
does not render here either — it lives on Trends as a badge chip. `PlanController` still calls
`seasonPayload()`, never `streakPayload()`.

## Extracted: interval detection

[IntervalDetector::detect()](app/Services/Run/Metrics/IntervalDetector.php) is [LapsTool](app/Services/AI/Agent/Tools/LapsTool.php)'s original `reps()` heuristic (pace-spread threshold, midpoint split, non-adjacency rejection), pulled out as a pure function so the periodizer and other session-structure code can reuse it without going through the LLM tool layer. No behavior change from the original inline logic.

See also [[race-projection]] (the `RaceGoal` this periodizer reads), [[training-load-metrics]] (the CTL/ATL/monotony inputs `Readiness` clamps against, and the season's CTL-growth goal), and [[gamification]] (season goals, the rest-day reward, and badge milestones).
