---
title: A fresh connect starts its own hydration drain immediately
description: The first-connect chain dispatches a per-user hydration batch the moment the summary backfill lands, instead of waiting up to 15 minutes for the next strava:hydrate-backlog tick.
tags: [decision, run, strava]
status: accepted
reviewed: 2026-09-19
code_refs:
  - app/Jobs/Strava/HydrateBacklogForUserJob.php
  - app/Jobs/AI/KickoffRecapsJob.php
  - app/Console/Commands/Strava/HydrateBacklogCommand.php
  - app/Jobs/Strava/IngestActivityJob.php
---

# A fresh connect starts its own hydration drain immediately

**Status:** Accepted (2026-09-19)

## Context

[[chronological-hydration-drain]]'s `strava:hydrate-backlog` cron is the only thing that
hydrates a summary-only backlog, and it only runs on its own 15-minute cadence
([routes/console.php](../../routes/console.php)). A newly connected athlete's backfill can
therefore land and then sit for up to 15 minutes before the first run gets its splits, HR,
card or PR flag — on day one, the exact moment a new signup forms its impression of the app.

## Decision

**[KickoffRecapsJob](../../app/Jobs/AI/KickoffRecapsJob.php)** — the last link of the
first-connect chain, running the moment the summary backfill completes — now also dispatches
**[HydrateBacklogForUserJob](../../app/Jobs/Strava/HydrateBacklogForUserJob.php)** for that
athlete. The job reuses
[HydrateBacklogCommand::budget()](../../app/Console/Commands/Strava/HydrateBacklogCommand.php)
and [::hydrateFor()](../../app/Console/Commands/Strava/HydrateBacklogCommand.php) unchanged
(made `public` for this reuse; everything else about them — the headroom calculation, the
oldest-first order, the give-up guard — is untouched), so the immediate batch is paced by
the same background read headroom the cron drain shares and stays oldest-first, exactly as
[[chronological-hydration-drain]] and [[backfill-borrows-the-live-reserve]] already decided.

**No new locking was needed against a concurrent cron tick.** Both paths bottom out in
[DetailHydrator::hydrate()](../../app/Services/Run/Ingest/DetailHydrator.php), which dispatches
[IngestActivityJob](../../app/Jobs/Strava/IngestActivityJob.php) — already `ShouldBeUnique`,
keyed on the activity id, holding its lock for the whole 6-hour retry window. Whichever of the
cron tick or the connect-triggered job reaches an activity first wins the dispatch; the other's
attempt for the same activity silently collapses onto it instead of spending the read twice.

## Consequences

- **Enables:** a newly connected athlete's most recent runs start converging within seconds of
  the backfill landing, instead of waiting for the next cron tick.
- **Costs:** none beyond what the cron drain already spends — the immediate batch draws from the
  same shared background headroom, it does not add to it.
- **Unchanged:** the drain's cadence, oldest-first order, even split across other backlogged
  users, and give-up guard. A returning athlete's reconnect or manual re-sync still waits for the
  cron, since only the first-connect chain (`KickoffRecapsJob`) dispatches the immediate batch.

## See also

- [[chronological-hydration-drain]] — the oldest-first order this reuses unchanged
- [[background-hydration-drain]] — the headroom pacing and even split this reuses unchanged
- [[backfill-borrows-the-live-reserve]] — the read budget both paths pace against
- [[summary-first-ingest]] — why a connect's backlog exists in the first place
