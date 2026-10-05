---
title: A long day eased to an easy run is graded as an easy day
description: A Long day with a recorded eased distance sums its runs with no single-run rule, because that distance only ever comes from the clamp arm that turns the day into an easy run.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-29
code_refs:
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Services/Run/Plan/RestClampRecorder.php
  - app/Services/Run/Plan/EffectiveSession.php
  - app/Services/Run/Plan/PlanRenderer.php
---

# A long day eased to an easy run is graded as an easy day

**Status:** Accepted (2026-09-29). Narrows the long-day half of [[a-credited-day-shows-its-result]]; builds on [[a-clamped-day-is-graded-on-what-it-asked]].

## Context

A `Long` day reads `done` only when one run carried 70% of the ask
([SessionMatcher::oneRunCarriedTheLongDay()](app/Services/Run/Plan/SessionMatcher.php#L251)). That rule
still fired on a Long day the readiness clamp had eased to a short easy run. Observed on prod: a Long
day eased to 6.8 km, run as 4.5 + 3.6 km (119%, intent `hit`), stored `partial` because 4.5 is 66% of
6.8. Every surface already showed that day as an easy run ([EffectiveSession](app/Services/Run/Plan/EffectiveSession.php#L38)),
so the athlete who followed the step-down was graded against a long run nobody asked for.

## Decision

**A Long day with a recorded `clamped_km` is graded as an easy day**: its runs are summed and the
single-run rule does not apply ([SessionMatcher::asksForOneLongRun()](app/Services/Run/Plan/SessionMatcher.php#L245)).
A Long day with no recorded ease, or with only a pace ease (`eased_pace_sec_per_km`), keeps the rule.

No new column is needed, because the stored data already tells the two clamp arms apart for a Long day:

- `requiredRank(Long)` is `ModerateOk`, so at a `ModerateOk` ceiling
  [ReadinessClamp::apply()](app/Services/Run/Plan/ReadinessClamp.php#L76) returns null for a Long day. The
  `ModerateOk` arm is reachable only for Tempo and Interval.
- [RestClampRecorder](app/Services/Run/Plan/RestClampRecorder.php) writes `clamped_km` only from a
  non-null `apply()`, so on a Long row it can only come from the `EasyOnly` arm. At `ModerateOk` a Long
  day gets the pace-only ease instead ([paceEaseApplies()](app/Services/Run/Plan/ReadinessClamp.php)),
  which records `eased_pace_sec_per_km` and never `clamped_km`.

## Consequences

- **Historical rows need no backfill to be read correctly.** Any existing Long row with `clamped_km` is
  already an `EasyOnly` ease, so re-scoring it applies the new grade. Stored verdicts change only when a
  row is re-scored; the one known affected day is re-scored by hand after deploy.
- **The "not in one run" note needs no change.** On a past eased day the renderer already passes the
  effective `Easy` type to `creditNote()` ([PlanRenderer](app/Services/Run/Plan/PlanRenderer.php#L271)),
  so the note could never fire there. Only the stored grade was wrong.
- **A clamp carried across a same-day regenerate** ([Periodizer](app/Services/Run/Plan/Periodizer.php#L211))
  could land a Tempo's `clamped_km` on a row regenerated as Long. That row also renders as an easy run,
  so grading it as one keeps the card and the grade in agreement.
- Stimulus adherence in [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php) reads `intent_verdict`,
  which is already judged against the eased easy run, so it needs no change.
