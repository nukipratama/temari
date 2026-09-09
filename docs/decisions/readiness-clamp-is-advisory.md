---
title: The readiness clamp is advisory, not a replacement
description: A clamped day renders as a marked step-down beside the session the plan asked for, which stays the thing narrated and graded.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-06
code_refs:
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Services/AI/Agent/Tools/PlanDayTool.php
  - app/Services/AI/MaterialFingerprint.php
  - resources/js/components/plan/WeekDayRow.tsx
  - resources/js/components/home/WeekPlanWidget.tsx
---

# The readiness clamp is advisory, not a replacement

**Status:** Accepted (2026-09-06)

## Context

Observed on prod: the Plan card read *"long run today, around 9.1 km"* in Temari's voice, directly above a **5.9 km easy** prescription. Both halves were correct in isolation. [ReadinessClamp](app/Services/Run/Plan/ReadinessClamp.php) had eased the session because the athlete had already run that morning, and the note said so.

Pulling on it found not one disagreement but four, all from the same root. The clamp was **authoritative in the one place with no memory** — the render, where it overwrote `session_type`, `segments` and `distance_km` — and **advisory in both places that persist**:

- **Narration** describes the stored session. [PlanDayTool](app/Services/AI/Agent/Tools/PlanDayTool.php) reads the stored row and recomputes core km from it; the narrator cannot see the clamp at all.
- **Compliance** grades the stored session. [SessionMatcher](app/Services/Run/Plan/SessionMatcher.php) never sees the clamp either, so an athlete who correctly took a clamped-to-Rest day was scored 0% against the stored ask and landed on `missed` for following the app's own advice.
- **The week's headline km** summed the clamped figure while the day cell beside it showed something else.

The obvious fix — make narration describe the clamped session — is the one the economics rule out. The two artifacts are opposites:

|  | narration (`plan_day_voice`) | clamp (`ReadinessClamp`) |
|---|---|---|
| cost | an LLM call | free, pure function |
| lifetime | cached, keyed by [MaterialFingerprint](app/Services/AI/MaterialFingerprint.php) | recomputed every render |
| stability | meant to be stable for the day | **flips the moment you run** |

Coupling a cached, billed artifact to an input the athlete's own morning run invalidates re-bills narration on exactly the days readiness is least stable.

One further constraint, and it is the hard one: **the clamp cannot be reconstructed after the fact.** It derives from `ReadinessCeiling` via `TrainingLoad::summary($user, $today)`, which includes today's own runs — so the ceiling that produced an 08:00 clamp no longer exists when `plan:score-compliance` runs at 00:03. Nothing downstream can ask what the athlete was told.

## Decision

**The clamp is advisory.** It is a marked modification *to* today's plan, never a silent replacement of it. The stored `PlannedSession` remains what the card leads with, what the narrator describes, and what `SessionMatcher` grades.

[PlanRenderer::dayPayload()](app/Services/Run/Plan/PlanRenderer.php) therefore keeps `session_type`, `segments` and `distance_km` on the stored session for every day including today, and ships the eased version as its own `clamp` object alongside. Both surfaces render it as a step-down beneath the day's own prescription — [WeekDayRow](resources/js/components/plan/WeekDayRow.tsx) on Plan and [WeekPlanWidget](resources/js/components/home/WeekPlanWidget.tsx) on Home — so the two pages tell one story, which is the property `PlanRenderer` exists to guarantee. **Correction, 2026-09-09:** Home's half moved to [TodaySession](resources/js/components/home/TodaySession.tsx), which now carries today's whole prescription beside the voice describing it; `WeekPlanWidget` keeps the week strip. The decision is unchanged.

The clamp payload carries a single `pace_sec_per_km` rather than a segment list: the step-down is one line, and only the core set's pace is ever shown on it.

**The prescription keeps rendering all day**, including once the day is credited. Someone who ran a short first session still needs to see what the full ask was.

## Consequences

- The voice and the numbers can no longer disagree, because the card now leads with the session the voice is describing.
- `planned_km_this_week` still sums the day payloads the card renders ([today-credits-when-earned](docs/decisions/today-credits-when-earned.md)); with `distance_km` back on the stored figure, the headline and the cells agree at the un-eased total. This reverses the today-is-clamped half of that note's third consequence, and deliberately so.
- Volume redistribution is untouched: it still subtracts the *clamped* km as today's fixed contribution, because it forecasts the volume actually likely to happen rather than restating the prescription.
- A clamped day now shows two numbers where it showed one. That is the honest shape of the situation, and the note explains it.

## Follow-ups, decided here and shipping separately

- **Scoring — shipped.** A nullable `rest_clamped_at` on `planned_sessions`, written by [RestClampRecorder](app/Services/Run/Plan/RestClampRecorder.php) wherever the ceiling is already computed — the ingest listener and the 00:01 briefing, never on a render — letting the scorer resolve a clamped-to-Rest day to `Skip`: uncredited, but never penalising adherence. [ReadinessClamp::clampsToRest()](app/Services/Run/Plan/ReadinessClamp.php) shares `requiredRank()` with `apply()` so the predicate and the clamp cannot disagree, and needs neither paces nor a volume multiplier because the `Rest` arm uses neither. ~~Only the full-Rest clamp needs this. `Long -> Easy` is a fixed `SegmentGenerator::MEDIUM_FRACTION_OF_LONG` of the long run, clear of `SessionMatcher::PARTIAL_FRACTION`, so complying already credits; Tempo/Interval downgrades keep the same core km.~~ **Correction, 2026-09-08: the last clause was wrong about this codebase.** Only the `ModerateOk` arm keeps the day's core km; the **`EasyOnly` arm calls `coreKmFor(SessionType::Easy, ...)`**, a different figure — on prod a 5.9 km tempo eased to 3.6 km, so complying scored 61% and landed on `partial`. The eased distance is now recorded and graded against; see [[a-clamped-day-is-graded-on-what-it-asked]]. The decision this note records is unchanged: the clamp is still advisory, and the stored session is still what the card leads with and the narrator describes. The flag is **written once and never cleared** — readiness recovering later in the day does not un-tell the athlete to rest, and excusing is the forgiving direction.
- **A second, cheap narration for the clamp itself**, keyed to the readiness ceiling and clamped type rather than to km, dispatched from `ActivityIngested` rather than from a render — no LLM narration is dispatched from a GET anywhere in the plan path, and this does not become the first. `plan_day_voice` keeps its current material and fingerprint and never re-bills. The existing `restNote()`/`easyOnlyNote()` strings stay as a permanent floor, replaced in place when the narration lands, so a step-down is never unexplained.

## Alternatives considered

**Narrate the clamped session inside `plan_day_voice`.** Most coherent single voice; re-bills the expensive blurb every time the athlete runs.

**Suppress the day's narration when clamped.** Free and honest, but the voice goes quiet on exactly the days that most need explaining.

**A shared clamp phrasebook** keyed only to (ceiling, clamped type) — roughly nine lines, generated once, reused forever. Nearly free, but it can never mention what the athlete actually did, which makes it a better-sounding `restNote()` rather than narration.

**Persist every clamp and grade against it.** Most accurate scoring, but it is the first persistent write into a deliberately render-only path and it cannot handle a day the app was never opened.
