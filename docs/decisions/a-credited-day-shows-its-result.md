---
title: A credited day shows its result, not a second menu
description: Once a day is credited the readiness step-down disappears rather than becoming guidance for another outing, and each session type is credited by what it actually asks of the athlete.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-16
code_refs:
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - resources/js/components/plan/DayDetail.tsx
---

# A credited day shows its result, not a second menu

**Status:** Accepted (2026-09-16)

## Context

Two things were wrong with a day the athlete had already run.

**The step-down kept talking.** `Readiness::assess()` caps the ceiling to `EasyOnly` on `ranToday` alone, so finishing the session is itself what clamps it. The block below the day therefore always rendered, and [PlanRenderer](app/Services/Run/Plan/PlanRenderer.php) relabelled it from *"eased today"* to *"anything else today"* with a note about a second outing. Nobody asked for a second outing. The athlete asked what their session came to, and got a menu.

**Crediting did not match what a session asks for.** Every day summed its runs except a `Long` day, which counted only its single longest. Both halves of that were wrong in different directions:

- A tempo day summed. Two easy 5 km outings credited a 10 km tempo session in full, which is not a tempo session at all — quality is one continuous effort.
- A long day threw distance away. An athlete who ran 12 km as 6 + 6 was credited 6, so real volume the week genuinely absorbed vanished from the score.

## Decision

**Crediting follows what the session asks of the athlete.**

- **Tempo and Interval credit the best single run.** The stimulus is one effort; a second outing on the same day is not part of it.
- **Long days sum, but only read `done` when one run carried 70% of the ask.** The volume happened and the score says so, keeping the honest ratio; the status says what the day actually was. A day that covered its distance in pieces reads `partial`, and the row carries a line saying why rather than leaving a partial badge beside a 100% score.
- **Easy, Rest and Race still sum**, unchanged: easy volume genuinely accumulates.

**A credited day ships no clamp at all.** [PlanRenderer::dayPayload()](app/Services/Run/Plan/PlanRenderer.php) returns `clamp: null` once the status is credited, and `ReadinessClamp::secondSessionNote()` is deleted rather than left unreachable. The day already states its own result: `judgedDayResult()` in [plan.ts](resources/js/lib/plan.ts) (renamed from `kmLabel` by #975, which also split the pace line out into the same `asked`/`ran` pair) renders the recorded prescription against what was run, and `ComplianceScorer::creditIfEarned()` writes that prescription the moment a run lands.

This supersedes the credited-day behaviour recorded in [[readiness-clamp-is-advisory]]. That note's core rule is untouched: the clamp is advisory, sits beside the stored prescription, and never replaces it. What changes is only what happens *after* the day is run.

## Consequences

- The excused path is unchanged and still wins first. A day the clamp downgraded to a full rest is `Skip` under every crediting rule, and `ran_anyway` still records a run on it without scoring against the athlete — see [[a-clamped-day-is-graded-on-what-it-asked]].
- The 70% single-run bar measures against `clamped_km` where one was recorded, so an eased long day is judged on the distance the athlete was actually told to run, not the session it replaced.
- A long day run in pieces now scores higher and ranks lower than before: the score reflects the volume, the status reflects the stimulus. Weekly adherence, which averages the score, therefore reads the volume honestly where it used to under-count it.
- No LLM call is made from a page load to explain a finished day, which was the constraint that produced the second-outing note in the first place.
