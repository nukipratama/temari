---
title: Today's ease stays a step-down, recorded or not
description: For TODAY only, a recorded readiness ease renders exactly like an unrecorded one — the original session leads and the ease is a step-down beside it — amending the-eased-session-leads for the one day it disagreed with itself.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-19
code_refs:
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - app/Services/Run/Plan/RestClampRecorder.php
  - app/Services/AI/HydrationBacklog.php
---

# Today's ease stays a step-down, recorded or not

**Status:** Accepted (2026-09-19). Amends [[the-eased-session-leads]] for TODAY only.

## Context

[[the-eased-session-leads]] made a *recorded* ease headline the day, with the original session as
context. That left TODAY disagreeing with itself: before the 00:01 `RestClampRecorder` runs, an
eased today reads as an advisory step-down (original leading); the moment it records, the same ease
swaps the headline. The two screenshots of the same day, an hour apart, looked like different
decisions.

## Decision

For TODAY, before credit, a recorded ease renders exactly like an unrecorded one on both Home's
today card and the Plan row: the original session leads, and a `↓ EASED TODAY` step-down carries the
eased session and its reason beneath it (`PlanRenderer::dayPayload()`'s `clamp`, never `eased_from`).
The ease is advice from a signal that can be wrong, so the plan stays visible and the athlete decides.
A **past** day's recorded ease is unchanged — it still headlines, per [[the-eased-session-leads]].
Crediting, adherence and what `RestClampRecorder` stores are untouched; only presentation moved.
Home's week-forecast total (`planned_km_this_week`/`planned_km_eased_from`) follows the same rule, so
it never disagrees with the day row it sits above.

**Blind-clamp guard.** Neither the render path nor `RestClampRecorder::record()` applies a clamp
while the athlete's recent load is still unscored — a half-hydrated history otherwise reads as no
load and bottoms the ceiling out at Rest for the wrong reason. Both reuse
[HydrationBacklog::recentLoadAwaitsScoring()](app/Services/AI/HydrationBacklog.php), the same
trailing-CTL-window check [SeasonService](app/Services/Run/Plan/SeasonService.php) already uses to
hold a race season's `increases_held`, rather than a second signal for the same question.

## Consequences

- Today's card is now stable across the 00:01 write: it looks the same before and after, which was
  the actual bug report.
- A regenerate after 00:01 already fell back to the advisory presentation (per
  [[the-eased-session-leads]]'s own caveat); today's step-down now matches that fallback exactly
  instead of being a special case of it.
