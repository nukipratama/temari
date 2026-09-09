---
title: An hourly drain hydrates the summary-only backlog
description: Imported history converges on splits, TRIMP, PRs, cards and narration by itself, newest-first, paced by the share of the Strava read budget background reads may already spend.
tags: [decision, run, strava]
status: accepted
reviewed: 2026-09-06
code_refs:
  - app/Console/Commands/Strava/HydrateBacklogCommand.php
  - app/Services/Run/Ingest/DetailHydrator.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Services/Strava/StravaClient.php
  - app/Enums/StravaReadPriority.php
  - routes/console.php
---

# An hourly drain hydrates the summary-only backlog

**Status:** Accepted (2026-09-06)

> **Cadence changed 2026-09-07: the drain now runs every 15 minutes, not hourly.**
> The decision below — a headroom-paced background drain, newest-first, yielding to
> live ingest — is unchanged and still holds; only the tick interval moved. Fifteen
> minutes matches the read bucket's own decay window, and it is the direct remedy for
> the "headroom is measured at dispatch, not spent at dispatch" behaviour recorded
> under *Consequences*: a tick that fires into a still-draining bucket queues only a
> handful of runs, and used to wait a full hour for its next chance. The cadence cannot
> overspend, since each tick still takes only what `backgroundHeadroom()` allows. The
> daily total is unchanged (~750 runs at a full pool); a new account's history simply
> converges in well under an hour instead of several. `strava:sync` moved from
> `0 4-10,16-22 * * *` to hourly in the same change, closing a five-hour overnight gap
> in the webhook fallback.

> **The headroom it paces against grew 2026-09-09.** The daily background ceiling
> is no longer a fixed 1,500; it is the pool less a flat live floor (1,600 by
> default), so a tick at a full pool now affords ~800 runs rather than ~750, and
> the number below moves with `strava.live_read_floor`. The drain itself — its
> cadence, its newest-first order, its even split, and the fact that it only ever
> takes what `backgroundHeadroom()` allows — is unchanged. See
> [[backfill-borrows-the-live-reserve]].

## Context

[[summary-first-ingest]] made a connect cost single-digit Strava reads regardless of history depth, and it is still the right call. It also named its own price: *"a run nobody opens never gets its splits, zones, TRIMP, card, PRs or narration"*, and *"load history is genuinely incomplete until runs are opened."*

Measured on prod, that price is larger than the sentence suggests. The first real (non-demo) account imported **179 runs** and hydrated **6** — every one of them a run someone had actually opened. 173 runs carried no splits, no TRIMP, no card and no narration, and **303 of 308 weekly snapshots had a null `weekly_trimp`**, so ATL/CTL/form were resting on almost nothing.

That reads as broken, and it compounds: [WeeklyRecapNarrator](app/Services/AI/Narrators/WeeklyRecapNarrator.php#L65) narrates `weekly_trimp`, and recaps are requested with `invalidate: false` ([KickoffWeeklyRecaps](app/Actions/AI/KickoffWeeklyRecaps.php)), so a week narrated while its load is null keeps the thin story permanently.

The gap was never a *Strava* cost problem. Hydrating that whole backlog is 346 reads against a 2,000/day pool. It was simply that nothing drained it: [DetailHydrator](app/Services/Run/Ingest/DetailHydrator.php) only ever fired from a page view, and `strava:ingest` works `pendingIngest()` — stubs with a null `analyzed_at` — which summary rows are not in.

## Decision

**`strava:hydrate-backlog` ([HydrateBacklogCommand](app/Console/Commands/Strava/HydrateBacklogCommand.php)) runs hourly and drains the summary-only backlog newest-first, through the same `DetailHydrator` the browse path uses.**

- **It is paced by headroom, not by a guess.** [`StravaClient::backgroundHeadroom()`](app/Services/Strava/StravaClient.php) reports what a `Background` read may still spend *before* reaching the live-ingest reserve — the same ceiling [[live-ingest-read-reserve]] already enforces, read instead of hit. The tick's budget is that headroom divided by the two reads a run costs, so the drain shrinks itself as live ingest spends the shared pool and disappears entirely when the pool is tight. `rateLimitRemaining()` deliberately keeps reporting the raw pool for the sync log and Pulse card.
- **Newest-first**, because recent runs are the ones a user is about to look at, and the only ones still inside the twelve-week LLM window ([[twelve-week-narration-cutoff]]). The order also decides narration latency: [DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php) reserves a 6-minute stagger slot for *every* backfilled run, past the cutoff or not, so draining oldest-first would have parked the runs that actually get an LLM call behind hours of slots belonging to runs that fill rule-based and free.
- **The tick is split evenly across users with a backlog**, so one deep archive cannot consume a whole tick while another user's history stays dark.
- **Nothing new was needed to make it safe.** `IngestActivityJob` is `ShouldBeUnique` so overlapping ticks collapse; `Background` priority already yields to live ingest; the AI fan-out already staggers backfilled cascades 6 minutes apart ([StaggerBackfillAction](app/Actions/AI/StaggerBackfillAction.php)) and already serves anything past 84 days from the rule-based filler for free; and hydration already rolls the weekly snapshots forward ([DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php), on the `ActivityIngested` the pipeline fires post-commit), so the load curve repairs itself as a side effect.

**A run that has exhausted its detail-fetch attempts is no longer hydratable.** A permanent 4xx (deleted, unshared) stamps `analyzed_at` but deliberately leaves `ingest_state` at `summary`, so `summaryOnly()` alone would have re-queued a dead run on every tick forever. The guard lives in [DetailHydrator](app/Services/Run/Ingest/DetailHydrator.php) rather than the command, so the browse path stops re-spending two reads per view on the same dead run — and [RunController](app/Http/Controllers/RunController.php)'s `awaitingDetail` notice stops promising a refresh that was never coming.

### Why hourly, and not part of the connect backfill

The connect path is latency-sensitive and already does the expensive thing (paging the whole history). Attaching a several-hundred-read drain to it would put the cost back exactly where [[summary-first-ingest]] took it out of. An hourly tick spends only what the pool is not otherwise using, and a new account converges over a few hours instead of never.

### Why not simply hydrate everything on demand, faster

That is what the browse path already is. It cannot fix history nobody browses, which is the whole complaint.

## Consequences

- **Enables:** an imported history that fills itself in — splits, zones, TRIMP, PRs, cards and narration — without the user opening 179 runs by hand, and a load curve that converges in hours rather than never. Recaps generated afterwards narrate real load.
- **Costs:** ~2 reads per backlogged run, spent only from the background tier. For the 179-run account above, ~346 reads and roughly 43 in-window LLM cascades (130 fall past the narration cutoff and fill rule-based, free).
- **Ordering matters, and is not enforced in code.** A recap generated before its weeks are hydrated narrates null load and, being `invalidate: false`, keeps that thin story. Drain first, kick off recaps second.
  > **Enforced since 2026-09-09.** The recap side now holds a week back while this drain still owes one of its runs a detail fetch, bounded by a wall-clock grace window — see [[recap-waits-for-hydration]]. The drain itself is unchanged and knows nothing about narration.
- **Headroom is measured at dispatch, not spent at dispatch.** A tick sizes its batch from the headroom it can see, but the *previous* tick's jobs are still spending reads as they run, so a reading taken just after a tick overstates what the next one can afford. Nothing breaks — `ShouldBeUnique` collapses duplicates and the throttle middleware re-queues anything the ceiling refuses — but the drain paces itself more conservatively than the raw numbers suggest, and a tick firing into a still-draining bucket may queue only a handful of runs. Observed on the first real backlog: a tick reporting 106 reads of headroom queued 2.
- **Known limit:** the even split gives every user with a backlog at least one run per tick, so a tick can be over-subscribed once there are more such users than the headroom affords runs (roughly 750 at a full pool). Users are taken in id order, so the tail waits for a later tick rather than being served round-robin. At that scale the split needs real rotation state; below it, it does not.

## See also

- [[summary-first-ingest]] — the ingest shape this completes, unchanged
- [[live-ingest-read-reserve]] — the ceiling this paces itself against
- [[twelve-week-narration-cutoff]] — why most of a deep backlog costs no LLM spend
- [[run-ingest-pipeline]] — the mechanics of a single hydration
