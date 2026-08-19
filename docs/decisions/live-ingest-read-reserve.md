---
title: A quarter of the Strava read budget is reserved for live ingest
description: Browsing-driven detail hydration stops at 75% of each shared read bucket, and backs off on its own throttle key, so a signup burst scrolling old runs cannot starve a freshly-finished run's webhook ingest.
tags: [decision, strava]
status: accepted
reviewed: 2026-08-14
code_refs:
  - app/Enums/StravaReadPriority.php
  - app/Services/Strava/StravaClient.php
  - app/Services/Run/Ingest/DetailHydrator.php
  - app/Jobs/Strava/IngestActivityJob.php
---

# A quarter of the Strava read budget is reserved for live ingest

**Status:** Accepted (documented 2026-08-14)

## Context

[[strava-circuit-breaker-rate-limit]] established that the read budget is one shared per-client pool (200 / 15 min, 2,000 / day) keyed globally. That decision is unchanged. What it did not settle is **who gets the last read when the pool runs low**, and with signup open to strangers that question stopped being hypothetical.

The two consumers are wildly asymmetric in cost:

- **Sync** pages `/athlete/activities` 200 summaries at a time ([ActivityFetcher](app/Services/Strava/ActivityFetcher.php#L13)), so an athlete's *entire* history costs a handful of reads.
- **Detail hydration** costs two reads per run *opened* ([ActivityPipeline](app/Services/Run/Ingest/ActivityPipeline.php#L79) for the detail, [again](app/Services/Run/Ingest/ActivityPipeline.php#L258) for the streams), driven by [DetailHydrator](app/Services/Run/Ingest/DetailHydrator.php#L28).

So **browsing costs far more than importing**, and it is user-driven and bursty. A cohort of new signups scrolling their archives is a plausible way to spend the whole pool in one 15-minute window.

Meanwhile a run appearing promptly after it finishes is the product's core promise, and that path ([syncSingleActivity](app/Services/Run/Ingest/SyncOrchestrator.php#L113) → [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php#L20)) went through the exact same guard, with no notion of priority. Both paths even dispatch the *same job class*, so nothing downstream could tell them apart either.

## Decision

Reads are tagged with a [StravaReadPriority](app/Enums/StravaReadPriority.php#L13) of `Live` or `Background`, and the tag does two things.

- **One pool, two ceilings.** [`guardRateLimit()`](app/Services/Strava/StravaClient.php#L238) still hits the same globally-keyed buckets, but a `Background` read is refused once a bucket reaches [`backgroundCeiling()`](app/Services/Strava/StravaClient.php#L268) — `100 - LIVE_RESERVE_PERCENT` of the max, i.e. 150 / 15 min and 1,500 / day. `Live` may spend the whole pool.
- **A throttle key per tier.** [`IngestActivityJob::middleware()`](app/Jobs/Strava/IngestActivityJob.php#L89) keys its `ThrottlesExceptions` circuit by [`throttleKey()`](app/Enums/StravaReadPriority.php#L27) instead of one literal `strava-ingest`.
- **Only browsing is `Background`.** [DetailHydrator](app/Services/Run/Ingest/DetailHydrator.php#L42) dispatches at `Background`; the webhook push, the fallback poll, the ingest drain, [ResyncActivityJob](app/Jobs/Strava/ResyncActivityJob.php#L61) and the doctor command all stay `Live`. The parameter [defaults to `Live`](app/Services/Strava/StravaClient.php#L56), so a caller that never thinks about priority keeps today's behaviour rather than silently losing the reserve.

### Why not two buckets, or two queues

**Two `RateLimiter` buckets** would have to be sized against one upstream limit, and a reserve that live ingest does not use would be *wasted* rather than borrowable. Splitting the counter also means two keys to get right, and the per-user-keying bug this pool has already suffered once (PR #206) argues for keeping exactly one key shape. A second ceiling against one counter has neither problem.

**Queue-level separation alone** would not have worked: two Horizon queues still spend from the same Strava bucket, so whichever worker arrives first wins and the starvation is unchanged. The queue layer is only half the fix, and the half it does fix is the throttle *key*, not the queue name.

### Why the throttle key had to split too

The ceiling alone would have made things *worse*. Laravel's `ThrottlesExceptions` releases **every job sharing its key** once the circuit trips, without running them. With one `strava-ingest` key, background jobs hitting the new reserve floor would trip the circuit that live ingest also sits behind — a reserve the live path could not reach. The key split is what makes the ceiling mean anything.

## Consequences

- **Enables:** a burst of browsing cannot consume the last quarter of the read pool, and its backoff no longer stalls live ingest. Overflow *queues* rather than erroring at the user: the refusal throws `StravaRateLimitedException`, the throttle middleware releases with backoff, and the run stays honestly summary-only until it drains.
- **Costs:** browsing an archive during a busy window is slower, and the wait can exceed the run page's own poll budget. [RunHydratingNotice](resources/js/components/run/RunHydratingNotice.tsx#L59) says so, and stops promising a self-refresh once it stops polling.
- **Gotchas:** the reserve is a *ceiling on a shared counter*, not a separate allowance — live reads spend from the same 200, so the floor moves as live traffic does. And `rateLimitRemaining()` still reports the raw pool, not the background-visible headroom; the sync log and Pulse card read the true budget on purpose.

## See also

- [[strava-circuit-breaker-rate-limit]] — the per-client global keying this builds on, unchanged
- [[strava-client]] — the operational mechanics of the buckets
- [[run-ingest-pipeline]] — where each tier's reads originate
