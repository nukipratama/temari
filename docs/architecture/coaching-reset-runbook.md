---
title: Coaching reset runbook
description: The release procedure for the one-time pre-launch coaching:reset: order, dry run, apply, partial failure, lock recovery, what cannot be rolled back and privacy.
tags: [architecture, run, plan]
status: living
reviewed: 2026-10-02
code_refs:
  - app/Console/Commands/Run/CoachingResetCommand.php
  - app/Services/Run/Plan/CoachingReset.php
  - app/Services/Run/Plan/PlanRecalibrationService.php
  - database/migrations/2026_10_02_000300_add_coaching_reset_at_to_users.php
---

# Coaching reset runbook

Why the reset exists, and what it rewrites, is in [[a-one-time-reset-rebuilds-history-before-launch]]. This note is the release procedure. [CoachingResetCommand](app/Console/Commands/Run/CoachingResetCommand.php#L18) is `coaching:reset {--user=} {--dry-run}`; the work is [CoachingReset::reset()](app/Services/Run/Plan/CoachingReset.php#L64).

## Before the deploy

- Release the integrated stack only after its final checks. Never deploy an intermediate layer: the guard on ordinary recalibration and the reset ship together.
- The deploy runs both migration sets (see [[deployment]]), including `coaching_reset_at` on `users`.
- Take a database backup of the app schema right before the apply. See [[deployment]] for where backups live and how `scripts/restore-db.sh` restores one.

## Steps on production

Every step is an SSH or production action and needs the owner's approval each time it is used; an approval covers that one step only. Run artisan in the live `app` service of [compose.prod.yaml](compose.prod.yaml), from the deploy directory on the host: `docker compose -f compose.prod.yaml exec app php artisan coaching:reset --dry-run`.

1. Dry run: `php artisan coaching:reset --dry-run`. It prints, per athlete, runs summarised, personal records, weekly and trend snapshots, cards with a PR, past days by status, unknown effort, days ahead, seasons and stale narrations, before and after, then rolls back.
2. The owner reviews the counts.
3. Apply: `php artisan coaching:reset`. Optionally start with `--user=<id>` for one athlete, check it, then run the rest.
4. Verify actual behaviour (Plan, Trends, a re-graded week) before choosing any further production correction. Any such correction follows the verified-only rule in the ADR.

## Failure and recovery

- **One athlete fails.** That athlete rolls back alone, the command reports it and exits non-zero. Rerun the same command: athletes with `coaching_reset_at` set are skipped, so only the unfinished ones run.
- **Interrupted run holding locks.** The reset holds the per-athlete recalibration locks for up to an hour. If it was killed, force-release them in Tinker, as in the lock runbook in [[plan-periodizer]]: `Cache::lock("plan-reconciliation:{id}")->forceRelease()` and `Cache::lock("training-recalibration:{id}")->forceRelease()`.
- **Dry run side effects.** Cache clears (weekly aggregates, the VDOT memo) are not rolled back; they rebuild on read. The started and completed recalibration markers are written outside the transaction.
- **Narration.** Nothing is dispatched. Stale narration keeps its text and re-narrates only when the athlete asks or an existing fingerprint path fires, under the cost ceilings. Rows that any accidental request leaves pending stay for the hourly self-heal.

## What cannot be rolled back

The reset rewrites derived history in place and keeps no backup table. Undoing an applied reset means restoring the database backup taken before it; reverting the code does not restore old grades. Dropping the `coaching_reset_at` column is safe on its own, but it only re-arms a repeat run.

## Privacy and scope

- Output is counts only. Do not paste athlete ids, emails or per-athlete rows into logs, issues or PRs; describe them.
- The demo user is excluded; refresh it with `demo:seed`.
- Raw activities, streams and the analytics connection are never written.
