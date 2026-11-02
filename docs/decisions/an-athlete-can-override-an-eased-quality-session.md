---
title: An athlete can override an eased quality session
description: Once a week the athlete may run a quality session the plan eased for readiness or dropped after a hard week, outside the last two weeks before race week; the override is stored, undoable until a run lands, graded as the full session and told to the coach.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-11-02
code_refs:
  - app/Services/Run/Plan/QualityOverride.php
  - app/Services/Run/Plan/QualityOverrideService.php
  - app/Services/Run/Plan/PreRaceWindow.php
  - app/Models/PlanAdaptation.php
  - app/Http/Controllers/PlanOverrideController.php
  - resources/js/components/plan/QualityOverrideAction.tsx
---

# An athlete can override an eased quality session

**Status:** Accepted (2026-11-02). Decision 4 of #2351 and its layer-4 sub-decisions.

## Context

The plan takes quality work away in two ways. [ReadinessClamp](app/Services/Run/Plan/ReadinessClamp.php) eases today's Tempo or Interval at a `ModerateOk` ceiling to a smaller dose or an easy run, and a `RanTooHard` week ([PlanAdapter](app/Services/Run/Plan/PlanAdapter.php), `quality_delta: -1`) drops a quality session from the current week. Both were final: an athlete who felt ready had no way back, and nothing recorded that they had wanted one.

## Decision

**What can come back, once per Monday–Sunday week across both kinds** ([QualityOverride](app/Services/Run/Plan/QualityOverride.php)):

1. **Readiness ease:** today's Tempo or Interval eased at a `ModerateOk` ceiling. At `EasyOnly` or `Rest` there is no button; the ease's own reason shows instead.
2. **Weekly drop:** the session a `RanTooHard` week removed, on its natural day: the first day from today on where the week planned with `quality_delta` 0 holds a Tempo or Interval that the dropped week does not. The button names it ("bring back thursday's tempo").

**Guardrails.** Never in race week or the 2 weeks before it, the window time trials keep clear ([PreRaceWindow](app/Services/Run/Plan/PreRaceWindow.php), shared with [TimeTrialSchedule](app/Services/Run/Plan/TimeTrialSchedule.php), see [[a-moved-time-trial-may-land-three-weeks-out]]). Never for the demo athlete. Never on a pinned, skipped or already-run day. Afterwards the normal rules apply with no exemption: the fatigue rule, the in-week warning and grading count the day as run.

**Stored on the week's adaptation row.** [PlanAdaptation](app/Models/PlanAdaptation.php) is already one row per athlete and week, so it carries `override_kind`, `override_date` and `overridden_at`, plus `restorable_quality`, the natural day and type that `Periodizer::persist()` records on a `RanTooHard` week. The stored `quality_delta` stays -1; [PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php) reads the override into `PlanInputs::$qualityRestored`, and `Periodizer::rowsFor()` plans the current week at delta 0 from it, so every later regeneration, reconciliation included, keeps the session.

**Flow.** "i'm ready" opens a confirm sheet with the plan's own reason (the clamp note or the adaptation detail) and "the coach will remember this". [QualityOverrideService::take()](app/Services/Run/Plan/QualityOverrideService.php) accepts only what the current week, rendered as the athlete sees it, offers. A readiness override clears the row's recorded ease, and the clamp sites (`PlanPageAssembler`, `CurrentWeekKm`, `RestClampRecorder`, `ClampNarrationContext`) leave the day as written. A weekly drop regenerates the week. Until a run lands on the day, "ease it again" clears the override, refunding the week's one, and puts the plan back: the ease is recorded again without a notification, or the week is regenerated without the session.

**Grading.** The day is graded as the full session. [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) ignores a shown recommendation from before `overridden_at`, so an eased snapshot the athlete saw before choosing does not grade it.

**The coach notes it.** The day shows "you chose to run this one as written.", and [PlanContextTool](app/Services/AI/Agent/Tools/PlanContextTool.php) carries `overridden` on the day, which the morning briefing and the weekly recap prompts mention. No new LLM call.

## Consequences

- A day the athlete takes back is planned, shown, graded and narrated as the full session everywhere.
- The briefing already sent for the day is not re-narrated.
- Rollout: the migration adds nullable columns; a week regenerated before it records no `restorable_quality` until its next regeneration.

## See also

- [[plan-periodizer]], [[the-advised-session-leads-every-day]], [[grading-follows-shown-advice-and-actual-stimulus]]
