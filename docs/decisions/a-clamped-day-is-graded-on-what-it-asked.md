---
title: A clamped day is graded on what it actually asked for
description: The eased distance is recorded at the daily kickoff and becomes the day's ask, so an athlete who follows the step-down is credited rather than marked partial.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-08
code_refs:
  - app/Services/Run/Plan/RestClampRecorder.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Metrics/Readiness.php
  - app/Services/Run/Plan/ReadinessClamp.php
  - app/Models/PlannedSession.php
---

# A clamped day is graded on what it actually asked for

**Status:** Accepted (2026-09-08). Narrows [[readiness-clamp-is-advisory]], which stands otherwise.

## Context

[[readiness-clamp-is-advisory]] decided the stored session is what `SessionMatcher` grades, and
shipped one exception: a day clamped all the way to `Rest` records `rest_clamped_at` and resolves
to `skip`, because otherwise an athlete who took the rest the card prescribed scored `missed` for
complying.

It gave a reason for stopping there:

> Tempo/Interval downgrades keep the same core km.

**That is not what the code does, and the note has been corrected.** Only the `ModerateOk` arm of
[ReadinessClamp::apply()](app/Services/Run/Plan/ReadinessClamp.php) keeps the day's size — it calls
`coreKmFor($sessionType, ...)`. The **`EasyOnly` arm calls `coreKmFor(SessionType::Easy, ...)`**, a
different formula entirely. Observed on prod: a 5.9 km tempo eased to a 3.6 km easy run. An athlete
who obeys runs 3.6 against a stored ask of 5.9, scores **61%**, and lands on `partial` —
`SessionMatcher::DONE_FRACTION` is 0.85. The same "penalised for complying" defect the rest case
already fixed, one branch over.

## Decision

**The eased distance is recorded, and it becomes the day's ask for grading.**

`planned_sessions.clamped_km` is written by [RestClampRecorder](app/Services/Run/Plan/RestClampRecorder.php)
alongside the `rest_clamped_at` it already writes, at the same two call sites that already compute
a ceiling — never from a render. [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php)
substitutes it for the stored figure when building `plannedKmByDate`, so the score, the status and
the `prescribed_km` stamped beside them all describe the session the athlete was set.

Two consequences follow, and both are intended:

- Running the **eased** distance credits `done` at 100%, instead of `partial` at 61%.
- Running the **full original** session on a clamped day reads as `overreached`. They were told to
  do less and did more, which is what that verdict means.

### Only a clamp the athlete was actually set counts, and this is the trap

[Readiness::assess()](app/Services/Run/Metrics/Readiness.php) caps to `EasyOnly` on `ranToday`
**alone**. So after any run at all the ceiling reads easy — including on a day the athlete has just
correctly run their tempo. Recording an eased target then would tell the scorer the day only ever
asked for 3.6 km and grade a properly-executed 6 km tempo as an overreach, **for every athlete on
every run day**.

The recorder therefore refuses to record an eased target when `ranToday` is true. That guard lives
in the recorder rather than in its callers, so a future call site cannot get it wrong. In practice
it means the eased target is written by the 00:01 kickoff, before the day has any runs in it, which
is exactly the case worth crediting: an athlete who wakes up fatigued, is told to ease off, and
does.

A cap caused by having already trained is not an instruction that replaced the session — it is
guidance for a second outing, which is what [PlanRenderer](app/Services/Run/Plan/PlanRenderer.php)
now says on a credited day.

## Consequences

- **`prescribed_km` on a clamped day is the eased figure.** The card reads "3.6 km asked · 3.6 km
  run · done" rather than pairing a 5.9 km ask with a 3.6 km run and a passing grade, which was
  incoherent either way round.
- **Rendering is untouched.** Only the scorer substitutes; `PlanRenderer` still leads with the
  stored session, so the week's headline km and the day cells still agree at the un-eased total,
  as [[readiness-clamp-is-advisory]] settled.
- **Write once, never cleared**, matching `rest_clamped_at`: readiness recovering later in the day
  does not un-tell the athlete to ease off, and the forgiving direction is the right one.
- **A day the ceiling only dropped on after the first run keeps its original ask.** That is the
  cost of the `ranToday` guard, and it is the correct trade: the alternative corrupts scoring for
  everyone who runs.

## Alternatives considered

**Keep grading against the stored ask, treat the eased km as a pass mark.** Score stays comparable
across days, but score and status then disagree — 61% labelled `done` — and the bands exist
precisely to couple them.

**Plumb the cap reason out of `Readiness::assess()`** so the ingest listener could record a
fatigue-caused clamp while ignoring a `ranToday`-caused one. Correct in every case, but it changes
the readiness contract and every caller to widen a window that the 00:01 write already covers.

**Leave it and correct only the note.** The narrowest honest option, and it was seriously
considered: #797 removed the common case by rewording the clamp on a credited day, so what remains
needs a fatigued athlete who opens the app before running. Rejected because the app should not
advise something it then marks you down for, however narrow the window.
