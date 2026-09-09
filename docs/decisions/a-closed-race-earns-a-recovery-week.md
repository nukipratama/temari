---
title: A closed race earns a recovery week
description: The self-scaled arc that follows a race the athlete actually ran opens with one easy recovery week at the deload multiplier, before the build cycle starts.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-09
code_refs:
  - app/Services/Run/Plan/SeasonService.php
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Console/Commands/Run/CloseFinishedRacesCommand.php
  - app/Models/Season.php
  - tests/Feature/Plan/PostRaceRecoveryWeekTest.php
---

# A closed race earns a recovery week

**Status:** Accepted (2026-09-09)

## Context

[CloseFinishedRacesCommand](app/Console/Commands/Run/CloseFinishedRacesCommand.php) retires a race the morning after it is run, at 00:02, so the plan falls back to the self-scaled arc rather than counting down to a day in the past ([[the-plan-knows-its-race-day]]). Five minutes later `plan:regenerate` runs, and [PhaseSchedule::selfScaled()](app/Services/Run/Plan/PhaseSchedule.php) opens its cycle at `Build`, multiplier `1.0`.

So an athlete who raced on Saturday was handed a full training week on Monday, quality sessions included. Every coaching convention says the opposite: the week after a race is the one week that is unambiguously recovery, and it is the week the athlete is least able to judge for themselves.

The reactive deload could not cover this. [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php) fires on monotony, strain or slipped adherence — a race week is a *taper* plus one hard day, so it presents as low volume and perfect adherence. Nothing in the adaptive path sees a race as fatigue, and by the time it would, the week is over.

## Decision

**A self-scaled arc that follows a race the athlete actually ran opens with one recovery week.**

- **One week, easy only, at the deload multiplier.** It reuses [PlanPhase::Deload](app/Enums/PlanPhase.php) rather than gaining a phase of its own: `WeekPlanBuilder` already emits no quality slots for a `Deload` week, `volumeMultipliers()` already prices it at `0.65`, and the Plan tab's phase rail builds itself from whatever phase sequence the season has ([phasesOf()](resources/js/lib/plan.ts)) rather than a fixed four, so nothing needed a new label.
- **It is an extra week, not a borrowed one.** The 3-build : 1-deload cycle starts from the week *after* it, so the athlete does not pay for their recovery with a shortened first build block.
- **The trigger is the race DATE, not `completed_at`.** A goal is also retired when the athlete calls the race off ([RaceController::destroy()](app/Http/Controllers/RaceController.php)) or supersedes it with another; there is nothing to recover from in either case. `SeasonService::followsARaceAlreadyRun()` asks whether the superseded season's race day has actually passed.
- **The fact is frozen on the `Season`** as `opens_with_recovery`, for the same reason `anchor_weekly_volume_km` is ([[the-arc-is-anchored-once]]): the season chain behind an arc may change, and the arc it already prescribed may not. Generation and [SeasonSummaryBuilder](app/Services/Run/Plan/SeasonSummaryBuilder.php)'s season-wide preview both read it, so the two describe the same weeks.
- **Only the self-scaled fallback.** An athlete who sets a new race the same week gets the race arc, which has its own Base phase to open on.

## Consequences

- **Enables:** the plan stops asking for a full build week from someone who raced 48 hours ago, without anyone having to notice and intervene.
- **Costs:** a column on `seasons` and a defaulted parameter on `selfScaled()`. A season row written before this existed reads `false` and behaves exactly as it did.
- **Watch for:** anyone triggering this off `completed_at` — that is the retirement stamp, not evidence anybody raced. And anyone folding the recovery week into the first cycle position to "keep the arc twelve weeks": the extra week is the decision.

## See also

- [[the-plan-knows-its-race-day]] — how a race is closed out
- [[the-arc-is-anchored-once]] — why the flag is frozen on the season rather than re-derived
- [[plan-periodizer]] — the two modes and where this sits between them
