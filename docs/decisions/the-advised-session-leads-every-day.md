---
title: The advised session leads every day
description: Today's recorded ease and today's advisory clamp now headline the day the way a past recorded ease does, with the original as context, a pinned or Race day keeps its prescription beside one advice line, and a credited run explains itself in the grading's own words.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - app/Services/Run/Plan/SeasonSummaryBuilder.php
  - app/Services/Run/Plan/IntentOutcome.php
  - resources/js/components/home/TodaySession.tsx
  - resources/js/lib/plan.ts
---

# The advised session leads every day

**Status:** Accepted (2026-10-02). Supersedes the today-only rule of [[todays-ease-stays-a-stepdown]] and the "today's step-down" wording of [[a-pinned-day-still-gets-readiness-advice]]. The today-before-credit amendment to [[the-eased-session-leads]] is now moot.

## Context

[[todays-ease-stays-a-stepdown]] kept the original session as today's headline and showed the ease as a separate `clamp` step-down, so a recorded ease read the same before and after the 00:01 recorder ran. On every other day the eased session already led. The result was two stories on one card (the stored session in the title and chart, the advised one beside it), a Plan header total that disagreed with Home's, and a season-summary row that ignored today's ease (#1433). A credited run also had no plain explanation when the grading disagreed with the distance: an unplanned hard effort, an eased tempo run as written, a strong-concern day run hard, or an effort the data could not read.

## Decision

1. **The effective session leads, recorded or not.** [PlanRenderer::dayPayload()](app/Services/Run/Plan/PlanRenderer.php#L238) uses today's recorded ease, and today's unrecorded advisory clamp before credit, as the day's `session_type`, segments, distance and pace. `eased_from` carries the replaced `session_type`, `distance_km` and the reason as context. The separate `clamp` step-down payload is gone, so the recorder running at 00:01 changes nothing on screen. **Product choice**: one story per card.
2. **A pinned or Race day keeps its prescription.** It carries the safety advice as one `advice_note` line instead of replacing the session, so pinning still opts out of regeneration and a race is never downgraded ([[the-plan-knows-its-race-day]]). **Product choice.**
3. **A credited day explains itself.** [PlanRenderer::resultNote()](app/Services/Run/Plan/PlanRenderer.php#L614) words the outcome with [IntentOutcome](app/Services/Run/Plan/IntentOutcome.php#L59)'s own clauses: hard effort on an easy day, an eased tempo run anyway, a strong-concern day run hard, and an unknown effort ("so the day counts its distance only"). A `ran_hot` flag replaces `hot_note`. Home's [TodaySession](resources/js/components/home/TodaySession.tsx) shows the same note. The grading is unchanged ([[grading-follows-shown-advice-and-actual-stimulus]]); the note only reports it. **Product choice.**
4. **One week total everywhere.** [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) now sums today's ease and reports `planned_km_eased_from` from the days' `eased_from`. [SeasonSummaryBuilder::build()](app/Services/Run/Plan/SeasonSummaryBuilder.php#L69) takes the current week's planned km, phase and eased-from from it. For a reactive deload the eased-from figure is the arc's own km ([SeasonSummaryBuilder::easedFromKm()](app/Services/Run/Plan/SeasonSummaryBuilder.php#L116)). Future weeks keep the arc figure, since their readiness is not knowable yet. **Product choice.**

## Consequences

- Title, icon, chart, pace and week total all agree with the advice the athlete was given, before and after the recorder runs.
- The season-summary row for the current week can differ from the arc's figure for that week; that difference is shown as the eased-from value, not hidden.
- Pinned and Race days still show the stored session, so advice there reads as a note, not a replacement.
- Narration of the day is unchanged: the clamp voice still explains the ease ([[the-clamp-explains-itself]]).
