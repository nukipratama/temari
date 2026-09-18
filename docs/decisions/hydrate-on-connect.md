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

> **2026-09-19 — this job's own drain no longer stays plain oldest-first (#1054).**
> [HydrateBacklogCommand::hydrateFor()](../../app/Console/Commands/Strava/HydrateBacklogCommand.php)
> gained an optional `$recentFirst` sort, passed only from
> [HydrateBacklogForUserJob](../../app/Jobs/Strava/HydrateBacklogForUserJob.php): the last
> `RecentlyActiveUsers::ACTIVE_WINDOW_DAYS` days hydrate first, then the rest oldest-first as
> before. The cron tick (`strava:hydrate-backlog`) never passes it, so its drain is exactly as
> described below. Everything else here — reusing `budget()`/`hydrateFor()`, the headroom
> pacing, the give-up guard, the no-new-locking argument — is unchanged. See
> docs/decisions/history-narrates-on-demand.md for why: a fresh connect narrates its recent
> runs, briefing and profile voice immediately, ahead of the rest of its history.

**[KickoffRecapsJob](../../app/Jobs/AI/KickoffRecapsJob.php)** — the last link of the
first-connect chain, running the moment the summary backfill completes — now also dispatches
**[HydrateBacklogForUserJob](../../app/Jobs/Strava/HydrateBacklogForUserJob.php)** for that
athlete. The job reuses
[HydrateBacklogCommand::budget()](../../app/Console/Commands/Strava/HydrateBacklogCommand.php)
and [::hydrateFor()](../../app/Console/Commands/Strava/HydrateBacklogCommand.php) (made `public`
for this reuse; the headroom calculation and the give-up guard are untouched), so the immediate
batch is paced by the same background read headroom the cron drain shares, exactly as
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
- **Unchanged:** the drain's cadence, even split across other backlogged users, and give-up
  guard. A returning athlete's reconnect or manual re-sync still waits for the cron, since only
  the first-connect chain (`KickoffRecapsJob`) dispatches the immediate batch.
- **Changed (#1054):** this job's own drain hydrates the last
  `RecentlyActiveUsers::ACTIVE_WINDOW_DAYS` days first, then the rest oldest-first; the cron's
  own drain is untouched.

## See also

- [[chronological-hydration-drain]] — the oldest-first order the cron keeps; this job's own drain now hydrates recent-first (#1054)
- [[background-hydration-drain]] — the headroom pacing and even split this reuses unchanged
- [[backfill-borrows-the-live-reserve]] — the read budget both paths pace against
- [[summary-first-ingest]] — why a connect's backlog exists in the first place
