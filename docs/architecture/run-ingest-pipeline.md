---
title: Run ingestion pipeline
description: Strava sync → fetch detail/streams/weather → compute metrics → atomically write run card + story layer, drainable on failure
tags: [architecture, run]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/Run/Ingest/SyncOrchestrator.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Services/Run/Ingest/StreamAnalysis.php
  - app/Services/Run/Metrics/PersonalRecords.php
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Run/Metrics/WeeklyAggregator.php
  - app/Services/Strava/StravaClient.php
  - app/Services/Strava/ActivityFetcher.php
  - app/Jobs/Strava/SyncActivitiesJob.php
  - app/Jobs/Strava/IngestActivityJob.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Models/Activity.php
  - routes/console.php
---

# Run ingestion pipeline

How a Strava run becomes a run card + story layer. Two distinct phases — **discover** (cheap, inserts stubs) and **ingest** (expensive, fills one stub) — joined by a drain so a failure is always re-runnable.

## The shape

An [Activity](app/Models/Activity.php) row starts life as a **stub**: just `user_id` + `strava_external_id`, `analyzed_at` null. `analyzed_at` is the watermark — null = pending, set = handled. The [AnalyzedScope](app/Models/Activity.php) global scope hides stubs from every user-facing query; only the pipeline opts back in via the `withStubs` / `pendingIngest` scopes.

## Phase 1 — discover (sync)

`strava:sync` ([SyncCommand](app/Console/Commands/Strava/SyncCommand.php)) and the webhook both drive [SyncOrchestrator::syncUser()](app/Services/Run/Ingest/SyncOrchestrator.php). It takes a per-user `Cache::lock` (so overlapping ticks don't double-walk), then [ActivityFetcher::fetchNewExternalIds()](app/Services/Strava/ActivityFetcher.php) pages `/athlete/activities` newest-first, stopping at the first already-known id (or the `--since` bound), keeping only Run / VirtualRun / TrailRun, returned **oldest-first**. [SyncOrchestrator::insertActivityRows()](app/Services/Run/Ingest/SyncOrchestrator.php) bulk `insertOrIgnore`s the stubs — no detail fetch here.

The webhook push path ([syncSingleActivity()](app/Services/Run/Ingest/SyncOrchestrator.php)) inserts the one stub and dispatches [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php) immediately, but **skips an already-`analyzed_at` row** (Strava redelivers events and can't tell create from update apart here, so re-ingesting would re-spend two API calls for nothing).

## Phase 2 — ingest (drain → pipeline)

Scheduled sync deliberately does **not** dispatch a job per stub. `strava:ingest` ([IngestCommand](app/Console/Commands/Strava/IngestCommand.php)) runs every 5 min, pulls a small `pendingIngest()` batch oldest-first (skipping demo + revoked connections), and dispatches one [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php) each — pacing the herd so a backlog never 429-storms Strava.

[ActivityPipeline::ingest()](app/Services/Run/Ingest/ActivityPipeline.php) does the real work, in order:

1. **Fetch detail** `/activities/{id}` → upsert [ActivityDetail](app/Models/ActivityDetail.php) via [storeDetail()](app/Services/Run/Ingest/ActivityPipeline.php).
2. **Fetch streams** (time/distance/HR/cadence/velocity/altitude/latlng) → upsert [ActivityStream](app/Models/ActivityStream.php). Best-effort: a 4xx (404 = no streams, treadmill/manual) is logged and ingest continues.
3. **Compute summary** — [StreamAnalysis::compute()](app/Services/Run/Ingest/StreamAnalysis.php) derives HR time-in-zone, best-effort paces, decoupling, cadence distribution, per-km splits, etc.; [TrainingLoad::edwardsTrimp()](app/Services/Run/Metrics/TrainingLoad.php) folds zone minutes into a TRIMP. Both land on the detail row.
4. **Weather** — [lookupWeather()](app/Services/Run/Ingest/ActivityPipeline.php) reverse-looks the start coords from the stream; best-effort, never blocks.

The HTTP fetches above all run **outside** any transaction.

### The transactional boundary

Then a single [DB::transaction](app/Services/Run/Ingest/ActivityPipeline.php) commits the watermark + the whole story layer atomically: stamp `analyzed_at` + reset `detail_fail_count` → [PersonalRecords::detectAndStore()](app/Services/Run/Metrics/PersonalRecords.php) (PR detection must run first — Temari's mood reads PR rows) → [RunCardFactory::build()](app/Services/Run/Story/RunCardFactory.php) → [Temari::postRunLine()](app/Services/Run/Story/Temari.php) → [MilestoneDetector::detect()](app/Services/Gamification/MilestoneDetector.php). If any throws, `analyzed_at` rolls back with it, so the stub stays drainable rather than stranded "analyzed" with a half-built story. These are all same-connection DB writes (no HTTP, no queued dispatch inside the txn).

After commit: [ActivityIngested](app/Events/ActivityIngested.php) fires the AI fan-out (see below), and `afterCommit` a [ResolveActivityLocationJob](app/Jobs/Geo/ResolveActivityLocationJob.php) reverse-geocodes the start point when coords exist.

## Idempotency & re-drainability

The pipeline is re-runnable: detail/stream/card/PR writes are all `updateOrCreate`. Failure handling in [handleDetailFailure()](app/Services/Run/Ingest/ActivityPipeline.php):

- **Permanent 4xx** (404 deleted / 403 unshared) → stamp `analyzed_at` so it stops re-fetching every drain.
- **Transient 5xx / transport** → bump `detail_fail_count`; the stub stays pending until [MAX_DETAIL_FETCH_ATTEMPTS](app/Models/Activity.php) (5), then it's stamped handled to stop the loop.
- **429 / open circuit** are re-thrown unchanged so [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php)'s `ThrottlesExceptions` middleware re-queues with backoff (against `retryUntil`, not a fixed attempt count) — these never burn the failure budget.

## Downstream of the commit

[DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php) (queued listener on `ActivityIngested`) owns the post-ingest fan-out: it rebuilds weekly snapshots via [WeeklyAggregator::rebuildForwardFrom()](app/Services/Run/Metrics/WeeklyAggregator.php) (CTL is cumulative, so a backdated run propagates forward into every later week) and stages the AI narration cascade. The weekly snapshot rebuild lives **here, post-commit** — not inside the pipeline transaction. See [[ai-pipeline]] for the narration side and [[data-model]] for the row layout.

## Strava resilience

[StravaClient](app/Services/Strava/StravaClient.php) fronts every call with a circuit breaker, app-wide (per-client, not per-athlete) rate-limit buckets, and per-connection token refresh under a lock. Revocation vs. transient-refresh handling lives in [SyncActivitiesJob](app/Jobs/Strava/SyncActivitiesJob.php). The breaker + rate-limit rationale is its own decision: [[strava-circuit-breaker-rate-limit]].

## Manual / scheduled entry points

See [routes/console.php](routes/console.php): `strava:sync` (hourly during WIB running peaks, the fallback poll behind the webhook), `strava:ingest` (every 5 min drain), `geo:backfill-locations` (hourly geocode catch-up). [recomputeSummary()](app/Services/Run/Ingest/ActivityPipeline.php) re-derives one run's metrics from already-stored streams with **zero** Strava calls — the path behind a "Baca ulang" when HR zones change.
