---
title: Today counts once it is earned, and the ring measures the days the week holds
description: A day still in progress is graded but only ever upward, and the week's progress ring counts training rows this week actually has rather than the baseline's weekly target.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-06
code_refs:
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - app/Http/Controllers/PlanController.php
  - app/Console/Commands/Run/ScoreComplianceCommand.php
  - app/Enums/PlannedSessionStatus.php
---

# Today counts once it is earned, and the ring measures the days the week holds

**Status:** Accepted (2026-09-06)

> **2026-09-07:** the *"this stays render-only"* clause below is superseded by
> [[a-day-is-scored-when-it-is-run]] — a day the athlete has earned is now persisted at
> ingest, still upward-only. Everything else in this note stands.

## Context

Reported from prod, on the first real account: Home's week card read **0/4 sessions** on an evening the athlete had run 12 km, in a week the same page's stats row correctly summarised as *5 runs · 25.5 km*.

Three independent causes sat behind that one figure.

**Today was never graded.** [SessionMatcher::scoreFor()](app/Services/Run/Plan/SessionMatcher.php) returned `Planned` for any day not strictly past, and both render-time callers filtered their fallback subset to `date < today`. The rule is sound where it came from — [[plan-periodizer]]'s compliance pass is a *historical* judgment, and grading a day at 09:00 would call an unrun morning `missed`. But it was applied symmetrically to a question that is not symmetric. Falling short is undecidable until the day ends; clearing the bar is not. Running the same builder against prod with a wall-clock `now()` instead of `Carbon::today()` returned that day as `overreached` — the matcher already held the answer and the render refused to ask for it.

**Rest days credited themselves.** A past rest day scores `Done` (it asked for nothing), and `Done` [isCredited()](app/Enums/PlannedSessionStatus.php). The ring's numerator counted every row in the week, so a normal 4-session week with three rest days would have read **7/4** once past — and a week where nothing at all was run would still have read 3.

**The denominator was unreachable.** It came from the baseline's `sessions_per_week`, while the numerator could only ever count rows that exist. [WeekPlanBuilder](app/Services/Run/Plan/WeekPlanBuilder.php) deliberately never writes a row before the day it runs, so a plan created mid-week holds only the days that remain. The reported account's plan began on a Saturday: two rows against a target of four, a ring that could not be filled in the week a new user forms their first impression.

## Decision

**A day still in progress is graded, but only ever upward.** `scoreFor()` now grades a non-past day on the same km ratio as any other, and floors anything short of [isCredited()](app/Enums/PlannedSessionStatus.php) back to `Planned`. `Missed` and a bare `Planned` are indistinguishable to a reader mid-day, which is exactly right: neither claims the day is over. Both render-time callers now include today in the subset they resolve.

This stays **render-only**. [ScoreComplianceCommand](app/Console/Commands/Run/ScoreComplianceCommand.php) still selects strictly past rows, so the persisted verdict is written once, the morning after, exactly as before — a mid-day credit is recomputed on every page load and cannot freeze a partial day into the column that [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php) reads back for adherence.

**The ring counts training days, against the training days this week actually holds.** [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) excludes rest rows from both halves of the fraction and takes the denominator from the week's own non-rest rows. A full week still reads out of 4; the signup week reads out of the 2 it was given. A total nothing can reach is not a target, and a day off is not a session.

The payload field is `sessions_this_week`, not `sessions_per_week` — the old name described the baseline's weekly intent, which is no longer what the figure means.

## Consequences

- The card corrects itself the moment a qualifying run syncs, rather than at 00:09 the next morning.
- A week can now read `2/2` where it previously read `2/4`. That is a smaller-looking week honestly measured, not a promotion.
- `planned_km_this_week` is summed from the day payloads the card renders rather than recomputed alongside them, so a readiness-clamped today lowers the headline instead of leaving *15.0 km planned* above two cells adding to 11.8.
- Home passes its own week's activity index into [PlanRenderer::dayPayload()](app/Services/Run/Plan/PlanRenderer.php), so a day cell can finally show what was run. [[dashboard]] had described that behaviour for some time; it was never true, because the argument was never passed.
- One extra query on Home, the same one [[plan-periodizer]]'s own page already runs.

## Alternatives considered

**Backfill the signup week's past days as `skip` rows.** Keeps the denominator at the baseline target and marks the untrained days excused. Rejected: it writes rows for days that never had a plan, and `skip` means *the athlete excused this day*, which would be a claim about a person who had not yet installed the app.

**Leave today alone and add a separate "today so far" readout.** Avoids touching the status machinery, at the cost of a fifth number on a card already carrying four, and of two places on the page that both describe today's session.
