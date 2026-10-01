---
title: A one-time reset rebuilds history before launch, and ordinary recalibration keeps it
description: Ordinary recalibration recomputes metrics and the future plan but never rewrites past prescriptions or shown-advice grades; one pre-launch coaching:reset rebuilds all derived history once under the current policy.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Console/Commands/Run/CoachingResetCommand.php
  - app/Services/Run/Plan/CoachingReset.php
  - app/Services/Run/Plan/PlanRecalibrationService.php
  - app/Services/Run/Plan/SeasonService.php
---

# A one-time reset rebuilds history before launch, and ordinary recalibration keeps it

**Status:** Accepted (2026-10-02). Supersedes [[plan-recalibration-rewrites-history]].

## Context

[[plan-recalibration-rewrites-history]] let every recalibration (a zone edit, a Strava zone sync, an ingest max-HR raise, `plan:recalibrate-history`) rewrite past prescriptions and re-grade past days. That moves a grade the athlete was shown after the fact, and the policy behind it changed several times during the 2026-10-01 coaching audit. Issue #1511 set the rule for corrections to history: verified and scoped only. Production data is still a pre-launch fixture (#1541), so one full rebuild under the current policy costs no athlete a grade they relied on.

## Decision

1. **Ordinary recalibration keeps history.** [PlanRecalibrationService::recalibrate()](app/Services/Run/Plan/PlanRecalibrationService.php#L47) recomputes run summaries and TRIMP, weekly snapshots and the future plan only. It no longer rewrites past prescriptions, re-grades past days or marks past plan-day narration stale. [rewriteHistory()](app/Services/Run/Plan/PlanRecalibrationService.php#L158) is public and only the reset calls it. **Product choice** (#1511): a grade shown against advice stays what the athlete saw.
2. **One pre-launch reset rebuilds derived history once.** [CoachingResetCommand](app/Console/Commands/Run/CoachingResetCommand.php#L18) (`coaching:reset {--user=} {--dry-run}`) calls [CoachingReset::reset()](app/Services/Run/Plan/CoachingReset.php#L64) for each non-demo athlete, under the recalibration locks in one transaction. In order it:
   - recomputes run summaries and TRIMP, rebuilds personal records, weekly snapshots and the trend daily snapshots from the first run to today, and recomputes card PR flags and moods;
   - rewrites and re-grades past days oldest first, which includes the deletion rule of [[decoupling-describes-a-run-and-a-deletion-re-grades-its-day]] because grading reads the surviving runs;
   - re-anchors the current season and regenerates its goals as of its own start date through [SeasonService::reanchorForReset()](app/Services/Run/Plan/SeasonService.php#L104), settles already settled seasons again and leaves legacy unsettled seasons alone;
   - regenerates the future plan, marks every Done narration the athlete owns stale (content kept, nothing dispatched) and stamps `users.coaching_reset_at`.

   **Product choice** (#1541, CR-D33).
3. **The reset is safe to repeat and to inspect.** An athlete with `coaching_reset_at` set is skipped, so a second apply is a no-op. `--dry-run` prints a before and after count table and rolls back. A failing athlete rolls back alone; the command continues, reports it and exits non-zero, and a rerun retries only the unfinished ones. The demo user is always excluded.
4. **Raw data and metering are never written.** Activities, Strava detail fields, streams and the analytics connection are untouched, and Analysis rows are kept, so usage rows stay joinable. Stale narration re-narrates only when the athlete asks or an existing fingerprint path fires, under the existing cost ceilings.
5. **After the reset, #1511 applies again.** Any later correction to history must be scoped, with dry-run, apply and rollback, reviewable before and after output, idempotency and explicit selection. It may correct only independently verified errors against reliably shown advice. Ambiguous history is preserved, uncertain old intent stays out of quality progression, and no retrospective recommendation is invented. The reset is the single exception to that rule.

## Consequences

- A zone edit or a max-HR raise changes run metrics and the plan ahead, not last month's grades.
- Release is a procedure, not a migration: see [[coaching-reset-runbook]].
- The reset has no backup table. Undoing it means restoring a database backup taken before the apply; a code revert does not restore old grades.
- [[plan-recalibration-job-horizon]] and [[plan-recalibration-retry-window]] still describe the queued job's limits, but the job no longer regrades history.
