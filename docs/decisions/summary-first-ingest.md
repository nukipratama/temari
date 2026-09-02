---
title: Summary-first ingest, hydrate on demand
description: A Strava connect stores the athlete's whole history from paged summaries and fetches detail, streams and the story layer only for runs someone actually opens.
tags: [decision, run]
status: accepted
reviewed: 2026-08-14
code_refs:
  - app/Services/Run/Ingest/SyncOrchestrator.php
  - app/Services/Run/Ingest/SummaryIngest.php
  - app/Services/Run/Ingest/DetailHydrator.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Services/Strava/ActivityFetcher.php
  - app/Services/Strava/StravaClient.php
  - app/Enums/IngestState.php
  - app/Services/Run/Metrics/WeeklyAggregator.php
---

# Summary-first ingest, hydrate on demand

**Status:** Accepted (documented 2026-08-14, ratifying the shape shipped earlier)

## Context

Strava's read budget is **per API client, not per athlete** — 200 reads per 15 minutes and 2000 per day for the whole app ([`RATE_LIMIT_15MIN_MAX`](app/Services/Strava/StravaClient.php#L36)). Every user shares one pool.

The two ways to fill an athlete's history price out very differently against that pool:

- **Detail-first.** `/activities/{id}` plus its streams is **2 reads per run**. A 500-run history is 1000 reads, half the app's entire daily budget, spent on one connect while every other user's sync starves behind it.
- **Summary-first.** `/athlete/activities` returns **200 summaries per read** ([`PER_PAGE`](app/Services/Strava/ActivityFetcher.php#L13)). The same 500-run history costs **3 reads**.

That is a ~300x difference on the one resource the app cannot buy more of. Detail-first does not scale past a handful of users; at public-signup volume it fails on the first busy afternoon.

The cost is that a summary payload is genuinely thinner. It carries distance, moving and elapsed time, average and max speed, elevation, average and max HR, cadence, polyline and start coords, all real. It carries nothing stream-derived: no `stream_summary`, no TRIMP, no splits, no laps, no calories, no device, no weather.

## Decision

**Sync stores everything from summaries; hydrate fills one run at a time, when someone is about to look at it.**

- [SyncOrchestrator::syncUser()](app/Services/Run/Ingest/SyncOrchestrator.php) walks `/athlete/activities` newest-first and [SummaryIngest::store()](app/Services/Run/Ingest/SummaryIngest.php) bulk-writes the rows at `ingest_state = summary`.
- [DetailHydrator::hydrate()](app/Services/Run/Ingest/DetailHydrator.php) dispatches the expensive pipeline for a `summaryOnly()` row on demand: the run page opens one, and the "Past You" comparison opens the run it matched against.
- [ActivityPipeline::ingest()](app/Services/Run/Ingest/ActivityPipeline.php) then does the 2-read fetch, derives the stream summary and commits the whole story layer in one transaction.

**Completeness is a first-class column, not an inference.** [IngestState](app/Enums/IngestState.php) (`summary` / `detailed`) says how complete a row is, separately from `analyzed_at`, which says only that we have processed it. `SummaryIngest` only ever touches rows still in `summary` state, so a re-sync can never overwrite a hydrated run with the thinner payload.

**The load-bearing consequence: a missing half reads as unknown, never as zero.** This is the rule that makes the whole shape honest, and the one that is easy to violate by accident:

- [WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php)'s `dailyHistory()` skips a null `trimp_edwards` rather than summing a zero, and [upsertWeek()](app/Services/Run/Metrics/WeeklyAggregator.php#L172) writes `null`, not `0.0`, for load, ATL, CTL, form, monotony and strain when no run scored a TRIMP at all. Per *week*, the same distinction is drawn against a run-day set so an unscored week is null while a rest week keeps its honest zero: [[unscored-load-is-null-not-zero]].
- Everything reading `stream_summary` goes through `StreamSummary::fromArray()`, which maps a null blob to null accessors, so splits, zones and relative effort simply do not render.
- Volume, on the other hand, is exact across un-hydrated history: distance, duration and pace come straight off the summary.

A brand-new connection's history is **entirely** summary-only, so this is not an edge case, it is the first screen a stranger sees. Writing `0.0` there would tell a runner with a decade of training that they have done nothing.

## Consequences

- **Enables:** a connect that costs single-digit Strava reads regardless of history depth, a shared rate-limit pool that survives many concurrent signups, and a full, honest history visible immediately rather than after a long drain.
- **Costs:** a run nobody opens never gets its splits, zones, TRIMP, card, PRs or narration. The run page has to say so rather than looking broken: `RunController::show` passes the hydrator's own answer through as `awaitingDetail`, so the notice appears exactly when something really is coming.
- **Load history is genuinely incomplete until runs are opened**, so ATL/CTL/form converge as a user browses rather than being right on day one. That is the deliberate trade: an approximate-but-honest load curve beats an exact one that costs half the app's daily Strava budget per signup.
- **Narration is bounded separately.** Summary-first makes a deep history cheap in Strava reads but says nothing about LLM spend, which is why the depth bound is its own decision: [[twelve-week-narration-cutoff]].

## See also

- [[run-ingest-pipeline]] — the operational mechanics: the drain, the transactional boundary, failure handling.
- [[strava-circuit-breaker-rate-limit]] — how the shared per-client budget is actually enforced.
- [[past-you-engine]] — the one read path that spans un-hydrated history on purpose, matching on summary fields only.
- [[training-load-metrics]] — the load engine whose inputs are the nulls above.
