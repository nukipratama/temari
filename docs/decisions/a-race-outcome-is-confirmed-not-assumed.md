---
title: A race outcome is confirmed, not assumed from the date
description: A passed race date ends dated preparation but proves nothing; an owned run, a manual time, did not run or cancelled is recorded by the athlete, can be corrected later, and only then is anything graded, learned or recovered from.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-01
code_refs:
  - app/Services/Run/Plan/RaceOutcomeService.php
  - app/Services/Run/Plan/RaceOutcomeMatcher.php
  - app/Services/Run/Plan/PerformanceEvidenceRecorder.php
  - app/Services/Gamification/SeasonRecordBuilder.php
  - app/Services/Gamification/SeasonGamificationContext.php
  - app/Services/Run/Plan/SeasonService.php
  - app/Console/Commands/Run/RaceOutcomeAskCommand.php
  - tests/Unit/Services/Run/Plan/RaceOutcomeServiceTest.php
---

> **Partly superseded (2026-10-02) by [[post-race-recovery-follows-known-race-load]].** The next arc no longer opens with a recovery week only for a race confirmed before it is created; recovery is derived on every regeneration from known race load. The rest of this decision stands.

# A race outcome is confirmed, not assumed from the date

**Status:** Accepted (2026-10-01). Supersedes the date-based trigger of [[a-closed-race-earns-a-recovery-week]] and the "an unconfirmed matching run is success" reading of `SeasonGamificationContext::raceGoalMet()`.

## Context

`plan:close-finished-races` stamps `completed_at` the morning after the date, and everything downstream read that as "the athlete raced": the next arc opened with a recovery week off the race date, and any run on the day within 10% of the distance counted as a met goal. A missed, cancelled or unreported race was treated as a race, with its fatigue.

## Decision

- **Retirement is not an outcome.** Closing a race ends dated preparation and leaves the outcome `pending`. Rows created through [RaceGoalService](app/Services/Run/Plan/RaceGoalService.php) start `pending`; legacy rows carry no outcome and keep their old reading, with no retroactive regrade.
- **Outcomes** are `pending`, `confirmed` (an owned run or a manual finish time), `did_not_run` and `cancelled`. The next morning at 09:00 `race:ask-outcome` sends a push asking how it went (demo excluded). The Race page offers the owned race-day run closest to the distance within the existing 10% tolerance, longest on a tie, to confirm, or a time to enter, or "did not run". Another athlete's race or run can never be matched or confirmed.
- **Reopenable.** Any state can be corrected later with no deadline; each change is recorded on the event's history and the settled season's performance follows it.
- **A confirmed result is evidence.** Through the same validated path as `POST /fitness/evidence` ([PerformanceEvidenceRecorder](app/Services/Run/Plan/PerformanceEvidenceRecorder.php)): the plausibility range and one evidence row per activity apply, a result outside 1 to 42.195 km is kept on the race but feeds nothing (a road race's result is read at 42.195 km at most, so a rounded 42.2 km marathon still counts), and correcting or reopening retracts the stale evidence.
- **Nothing is assumed from the date.** Pending, did-not-run and cancelled races leave training normal, hold no taper and end their season on its date; the season record reads "pending", never a miss. The next arc opens with a recovery week only for a race confirmed before it is created; later freshness is the readiness assessment's job, and a late confirmation does not replay season creation.
- **Process and performance are separate.** [SeasonRecordBuilder](app/Services/Gamification/SeasonRecordBuilder.php) scores process as the mean completion of the season's training goals and performance as the confirmed result against the target (met, not met, pending, did not run, cancelled, none, unrecorded). A missed time goal cannot lower the process. The pair is stored on the season when it closes (`process_pct`, `performance_state`) and read live for the open one.

## Consequences

- A season created before this keeps null record columns until it is next settled; nothing is backfilled.
- The Home prompt and the unified presentation arrive with the presentation layer; this layer defines the payload only.

## See also

- [[a-race-event-keeps-its-season]], [[the-plan-knows-its-race-day]]
