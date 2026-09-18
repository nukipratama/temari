---
title: The eased session leads, and the week total agrees with it
description: A recorded readiness ease is the day's session everywhere it is shown, graded and narrated, with the original as context; one helper reads that rule off persisted state.
tags: [decision, run, plan, ai]
status: accepted
reviewed: 2026-09-16
code_refs:
  - app/Services/Run/Plan/EffectiveSession.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - app/Services/Run/Plan/PlanPageAssembler.php
  - app/Services/Run/Plan/SeasonSummaryBuilder.php
  - app/Services/AI/Agent/Tools/PlanDayTool.php
  - app/Services/AI/Agent/Tools/PlanContextTool.php
  - app/Services/AI/PlanNarrationRequester.php
  - app/Services/AI/Narrators/PlanClampVoiceNarrator.php
  - resources/js/components/plan/WeekDayRow.tsx
  - resources/js/components/home/TodaySession.tsx
  - resources/js/components/home/WeekPlanWidget.tsx
  - resources/js/components/plan/SeasonWeekRow.tsx
---

# The eased session leads, and the week total agrees with it

**Status:** Accepted (2026-09-16). Supersedes [[readiness-clamp-is-advisory]].

## Context

[[readiness-clamp-is-advisory]] kept the stored session as the headline and shipped the eased
version beside it. [[a-clamped-day-is-graded-on-what-it-asked]] then recorded the eased distance and
graded against it, so three surfaces disagreed: the scorer graded the ease, the day row headlined the
stored session, and the week card summed the un-eased prescriptions. An eased week could score `done`
on every day while its card read short.

The real case: a tempo day eased to easy at 00:01 with its 6.4 km held. The clamp line said the day
backed off to easy, the Plan row still headlined tempo at threshold pace, and the post-run read said
the athlete ran it at tempo. They ran it easy.

The advisory note's hard constraint still holds: a clamp cannot be reconstructed after the fact,
because the ceiling counts the day's own runs. What changed is that the outcomes worth leading with
are now **recorded** by [RestClampRecorder](app/Services/Run/Plan/RestClampRecorder.php), so they can
be read back instead of recomputed.

## Decision

**A recorded ease is the day's session.** [EffectiveSession](app/Services/Run/Plan/EffectiveSession.php)
is the one rule, read off persisted state only: `rest_clamped_at` means a rest day, `clamped_km` means
an easy run of that distance, anything else is the stored session. It returns the type, the core km
and what the day was eased from. The scorer, `PlanRenderer::dayPayload()`, Home's week builder, the
Plan page's redistribution and status fallback, the season header and both narrator tools read it; no
surface re-derives the substitution.

- **The day row and Home's today card** headline the eased session and carry `eased_from` as context.
  **Since #975** this renders as a delta pair rather than a sentence — `tempo → easy · 5.9 → 4.1 km`,
  tagged `eased` — with the type omitted when only the distance moved and the distance omitted when
  only the intensity did, so it never repeats an identical figure. A rest clamp reads as a rest day the
  same way. The decision below is unchanged; only the copy is.
- **The week total sums the eased values.** Home's card sums the day payloads it renders and names the
  un-eased total beside it. The Plan page's week header is a season forecast, so for the current week
  only it subtracts the km the recorded eases took off and names the forecast figure. A week whose ease
  moved no km shows no "eased from".
- **Redistribution** sizes both the week target and today's fixed contribution from the effective km,
  so an eased day lowers the target rather than pushing its lost volume onto the days after it.
- **A clamp shown but never recorded** (a short run before credit, a regenerate after 00:01, a skipped
  kickoff, a plan generated mid-day) keeps the advisory step-down beside the stored session. The
  headline always follows what the scorer grades.

### Narration: the clamp voice speaks for an eased day

Before the day is credited, an eased day's voice is `plan_clamp_voice`, whose prompt now names the
eased session as today's session and the replaced one only as context. The `plan_day_voice` blurb for
that day is held back from the payload, because it may have been written for the session the ease
replaced. The templated note for the recorded outcome stands in until a narrated line lands.

After credit, the existing fingerprint gate re-narrates `plan_day_voice` once (the credited status is
already a conditional key), and the tools it calls describe the effective session. `PlanDayTool` and
`PlanContextTool` send the eased type, distance and pace with `eased_from` beside them, so the post-run
read and the briefing describe the easy run.

**No new LLM calls and no new fingerprint key.** `MaterialFingerprint::forPlannedSession()` still
ignores the clamp, so an untouched row keeps its digest and easing a day re-bills nothing. A
re-narration still supersedes flags filed against the text it replaces.

## Consequences

- The headline, the day cells, the week total and the compliance score agree on one number.
- A rest-clamped day now records `prescribed_km` as 0, the rest day it became, rather than the session
  it replaced. It stays excused either way.
- `SessionMatcher` still reads the stored session type for its per-type crediting rules; only the
  distance it grades against is the effective one.
- A regenerate after 00:01 recreates today's row and loses the recorded ease, so the day falls back to
  the advisory presentation. That is tracked separately.

## Alternatives considered

**Teach the fingerprint about the clamp.** Add the clamp as a conditional key so `plan_day_voice`
re-narrates an eased day. Correct, but one extra LLM call per eased day forever, when a line written
for exactly that situation already exists.

**Keep the advisory presentation and only fix the week total.** Removes the short-week reading but
leaves the row headlining a session nobody is running, which is the defect the athlete saw.
