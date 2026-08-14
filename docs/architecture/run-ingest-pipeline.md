---
title: Run ingestion pipeline
description: Strava sync stores the whole history from paged summaries; detail, streams and the story layer are fetched lazily, atomically, and drainably on failure
tags: [architecture, run]
status: living
reviewed: 2026-08-14
code_refs:
  - app/Services/Run/Ingest/SyncOrchestrator.php
  - app/Services/Run/Ingest/SummaryIngest.php
  - app/Services/Run/Ingest/DetailHydrator.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Services/Run/Ingest/StreamAnalysis.php
  - app/Enums/IngestState.php
  - app/Services/Run/Metrics/PersonalRecords.php
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Run/Metrics/WeeklyAggregator.php
  - app/Services/Strava/StravaClient.php
  - app/Enums/StravaReadPriority.php
  - app/Services/Strava/ActivityFetcher.php
  - app/Jobs/Strava/SyncActivitiesJob.php
  - app/Jobs/Strava/IngestActivityJob.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Models/Activity.php
  - routes/console.php
---

# Run ingestion pipeline

How a Strava run becomes a run card + story layer. Two distinct phases — **sync** (cheap, stores the whole history from paged summaries) and **hydrate** (expensive, fills one run's detail + streams + story) — joined by a drain so a failure is always re-runnable.

## The shape

An [Activity](app/Models/Activity.php) carries two independent facts:

- `analyzed_at` — the visibility watermark. Null = we know nothing about this run yet; set = the row carries real data. The [AnalyzedScope](app/Models/Scopes/AnalyzedScope.php) global scope hides the nulls from every user-facing query; only the pipeline opts back in via the `withStubs` / `pendingIngest` scopes.
- `ingest_state` ([IngestState](app/Enums/IngestState.php)) — how *complete* that data is. `summary` = only what `/athlete/activities` returned; `detailed` = the full pipeline ran. The `detailed` / `summaryOnly` scopes on [Activity](app/Models/Activity.php) are the read-side filter, so no caller has to spell the predicate out.

A **summary-only** run is visible and honest: distance, moving time, elapsed time, average/max speed, elevation, average/max HR, cadence, polyline and start coords are all real. Everything stream-derived — `stream_summary`, `trimp_edwards`, `splits_metric`, `laps`, calories, device, weather — is null, and there is no [ActivityStream](app/Models/ActivityStream.php), [RunCard](app/Models/RunCard.php), [PersonalRecord](app/Models/PersonalRecord.php) or post-run [StoryLine](app/Models/StoryLine.php). Read paths degrade to "unknown", never to zero.

## Phase 1 — sync (summary-first)

`strava:sync` ([SyncCommand](app/Console/Commands/Strava/SyncCommand.php)), the manual re-pull and the on-connect backfill all drive [SyncOrchestrator::syncUser()](app/Services/Run/Ingest/SyncOrchestrator.php). It takes a per-user `Cache::lock` (so overlapping ticks don't double-walk), then [ActivityFetcher::fetchNewSummaries()](app/Services/Strava/ActivityFetcher.php) pages `/athlete/activities` newest-first at 200 summaries a call, keeping only Run / VirtualRun / TrailRun, returned **oldest-first** together with the number of reads the walk spent. It keeps scanning past a known id while the activity started within a trailing 14-day window (a backdated upload sits at its chronological position, nested among already-synced runs, so stopping at the first known id would miss it); below the window a known id (or the `--since` bound) means the history is synced and the walk stops.

[SummaryIngest::store()](app/Services/Run/Ingest/SummaryIngest.php) then bulk-writes the rows: `insertOrIgnore` for the activities (`ingest_state = summary`, `analyzed_at` stamped so the history is immediately visible) and a chunked `upsert` of the summary [ActivityDetail](app/Models/ActivityDetail.php) columns. It only ever touches rows still in `summary` state, so a re-sync can never overwrite a hydrated run with the thinner payload; a stub stranded by an earlier failed ingest gets filled in and becomes visible. Finally [rebuildAggregates()](app/Services/Run/Ingest/SyncOrchestrator.php) rolls the weekly snapshots forward once from the oldest new run, instead of once per activity. The read count lands on [StravaSyncLog](app/Models/Analytics/StravaSyncLog.php)`.api_calls_used`.

The webhook push path ([syncSingleActivity()](app/Services/Run/Ingest/SyncOrchestrator.php)) has only an activity id to work with, so it inserts a bare stub and dispatches [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php) immediately — a fresh run is always worth its two reads. It **skips an already-`analyzed_at` row** (Strava redelivers events and can't tell create from update apart here, so re-ingesting would re-spend two API calls for nothing).

## Phase 2 — hydrate (lazily → pipeline)

A summary-only run is hydrated when the deeper data is about to be looked at. [DetailHydrator::hydrate()](app/Services/Run/Ingest/DetailHydrator.php#L28) dispatches one [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php) for a `summaryOnly()` row belonging to a non-demo user with a live connection; the job is `ShouldBeUnique`, so repeated views collapse onto one fetch. These are the *expensive* reads (two per run opened, against a whole history's handful), so they queue at [`StravaReadPriority::Background`](app/Services/Run/Ingest/DetailHydrator.php#L42) and stop at the reserve floor rather than starving live ingest — see [[live-ingest-read-reserve]]. [RunController::show()](app/Http/Controllers/RunController.php) calls it twice — for the run being opened, and for whichever past run [PastYouMatcher](app/Services/Run/Story/PastYouMatcher.php) just picked as the comparison (the matcher itself needs only summary fields, so it works across un-hydrated history).

`strava:ingest` ([IngestCommand](app/Console/Commands/Strava/IngestCommand.php)) still runs every 5 min over `pendingIngest()` (`analyzed_at` null, below the give-up threshold, skipping demo + revoked connections), pacing the webhook backlog so it never 429-storms Strava. Summary rows are *not* in that set — they are already visible, and nothing drains them wholesale.

[ActivityPipeline::ingest()](app/Services/Run/Ingest/ActivityPipeline.php) does the real work, in order:

1. **Fetch detail** `/activities/{id}` → upsert [ActivityDetail](app/Models/ActivityDetail.php) via [storeDetail()](app/Services/Run/Ingest/ActivityPipeline.php). First the detail's `sport_type` is checked against [RunSportType](app/Services/Strava/RunSportType.php) (shared with the poll-path filter): a non-run upload (ride/walk/swim reaching ingest via the webhook, which fires for every type) has its stub **deleted** here so it never mints a bogus PR/card/snapshot or bills the narrator.
2. **Fetch streams** (time/distance/HR/cadence/velocity/altitude/latlng) → upsert [ActivityStream](app/Models/ActivityStream.php). Best-effort: a 4xx (404 = no streams, treadmill/manual) is logged and ingest continues.
3. **Compute summary** — [StreamAnalysis::compute()](app/Services/Run/Ingest/StreamAnalysis.php) derives HR time-in-zone, best-effort paces, decoupling, cadence distribution, per-km splits, etc. (see [[stream-analysis]]); [TrainingLoad::edwardsTrimp()](app/Services/Run/Metrics/TrainingLoad.php) folds zone minutes into a TRIMP (the load engine is [[training-load-metrics]]). Both land on the detail row.
4. **Weather** — [lookupWeather()](app/Services/Run/Ingest/ActivityPipeline.php) reverse-looks the start coords from the stream; best-effort, never blocks. See [[weather-integration]].

The HTTP fetches above all run **outside** any transaction.

### The transactional boundary

Then a single [DB::transaction](app/Services/Run/Ingest/ActivityPipeline.php) commits the watermark + the whole story layer atomically: stamp `analyzed_at`, flip `ingest_state` to `detailed` and reset `detail_fail_count` → [PersonalRecords::detectAndStore()](app/Services/Run/Metrics/PersonalRecords.php) (PR detection must run first — Temari's mood reads PR rows) → [RunCardFactory::build()](app/Services/Run/Story/RunCardFactory.php) → [Temari::postRunLine()](app/Services/Run/Story/Temari.php) → [DetectActivityMilestonesAction](app/Actions/Gamification/DetectActivityMilestonesAction.php). If any throws, `analyzed_at` rolls back with it, so the stub stays drainable rather than stranded "analyzed" with a half-built story. These are all same-connection DB writes (no HTTP, no queued dispatch inside the txn): the Run domain does not reference `AnalysisService` at all, so **every** analysis request is issued post-commit by the listener below.

After commit: [ActivityIngested](app/Events/ActivityIngested.php) fires the AI fan-out (see below), and `afterCommit` a [ResolveActivityLocationJob](app/Jobs/Geo/ResolveActivityLocationJob.php) reverse-geocodes the start point when coords exist (see [[geo-reverse-geocoding]]).

## Idempotency & re-drainability

The pipeline is re-runnable: detail/stream/card/PR writes are all `updateOrCreate`. Failure handling in [handleDetailFailure()](app/Services/Run/Ingest/ActivityPipeline.php):

- **Permanent 4xx** (404 deleted / 403 unshared) → stamp `analyzed_at` so it stops re-fetching every drain. `ingest_state` stays `summary`: we never got the detail, and saying otherwise would be a lie the read paths trust.
- **Transient 5xx / transport** → bump `detail_fail_count`; the stub stays pending until [MAX_DETAIL_FETCH_ATTEMPTS](app/Models/Activity.php) (5), then it's stamped handled to stop the loop.
- **429 / open circuit** are re-thrown unchanged so [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php)'s `ThrottlesExceptions` middleware re-queues with backoff (against `retryUntil`, not a fixed attempt count) — these never burn the failure budget.
- **Auth failure** (a detail-fetch 401 or an `invalid_grant` refresh) → `markRevoked()` and return **without** touching `detail_fail_count` (a revocation isn't the activity's fault); a **transient** token-endpoint blip is re-thrown so the job retries with backoff. Mirrors [SyncActivitiesJob](app/Jobs/Strava/SyncActivitiesJob.php)'s handling so a mid-sync revocation never strands a run as a detail-less ghost.

## Downstream of the commit

[DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php) (queued listener on `ActivityIngested`) owns the post-ingest fan-out: it rebuilds weekly snapshots via [WeeklyAggregator::rebuildForwardFrom()](app/Services/Run/Metrics/WeeklyAggregator.php) (CTL is cumulative, so a backdated run propagates forward into every later week) and stages the AI narration cascade: `pr_context` for the records this run now holds ([PersonalRecord](app/Models/PersonalRecord.php), `invalidate:false`), `card_flavor` for its [RunCard](app/Models/RunCard.php) (`invalidate:true`), then the per-activity group, the daily briefing set, and the deferred weekly/monthly recaps. The weekly snapshot rebuild and the whole narration fan-out live **here, post-commit** — not inside the pipeline transaction. See [[ai-pipeline]] for the narration side and [[data-model]] for the row layout.

## How a summary-only run reads

Every read path treats the missing half as unknown, not as zero:

- **Volume is exact.** Distance, duration and pace come straight off the summary, so [WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php), [LifetimeStats](app/Services/Run/LifetimeStats.php), [TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php) and the Jejak feed are all correct across un-hydrated history.
- **Load is unscored.** [dailyTrimpMap()](app/Services/Run/Metrics/WeeklyAggregator.php) skips a null `trimp_edwards` rather than summing a zero, and [BuildCalendarCellsAction](app/Actions/Run/BuildCalendarCellsAction.php) emits a null `trimp` for a day no run scored — same shape it already had for an HR-less treadmill run.
- **Stream-derived features are absent, not blank.** Everything reading `stream_summary` goes through [StreamSummary::fromArray()](app/Services/Run/Metrics/StreamSummary.php), which maps a null blob to null accessors, so splits, decoupling, zones and [RelativeEffort](app/Services/Run/Metrics/RelativeEffort.php) simply do not render.
- **No card, no PR, no narration.** Those are minted inside the hydration transaction, so a summary-only run contributes nothing to the collection, the records board or the LLM spend until it is opened. The one read path that *does* span un-hydrated history is [[past-you-engine]]: its matching reads summary fields only, so it compares against un-opened runs without hydrating them and without spending a token. [PersonalRecords](app/Services/Run/Metrics/PersonalRecords.php) only ever *lowers* a record, so hydrating history out of order cannot mint a false PR.
- **And the run page says so.** Rendering honestly is not the same as reading honestly: a run missing its splits, zones, effort and card looks broken unless something explains it. [RunController::show](app/Http/Controllers/RunController.php) passes `DetailHydrator::hydrate()`'s return value through as `awaitingDetail`, and [RunHydratingNotice](resources/js/components/run/RunHydratingNotice.tsx) renders the explanation and polls the page back until the rest lands. Because the flag *is* the hydrator's answer, the notice never appears for a run nothing is coming for (already detailed, demo data, or a revoked connection) and so never promises a refresh that will not happen. The same honesty bounds the *wait*: the copy says the deeper fetch queues behind runs finishing right now (the background tier of [[live-ingest-read-reserve]]), and once the [poll budget is spent](resources/js/components/run/RunHydratingNotice.tsx#L40) it drops the self-refresh promise and offers a manual re-check instead of a claim nothing will keep.

## Strava resilience

[StravaClient](app/Services/Strava/StravaClient.php) fronts every call with a circuit breaker, app-wide (per-client, not per-athlete) rate-limit buckets, and per-connection token refresh under a lock. Revocation vs. transient-refresh handling lives in [SyncActivitiesJob](app/Jobs/Strava/SyncActivitiesJob.php). The breaker + rate-limit rationale is its own decision: [[strava-circuit-breaker-rate-limit]], with the live-ingest share of that budget in [[live-ingest-read-reserve]]; the operational mechanics (state machine, buckets, token refresh) are [[strava-client]].

## Manual / scheduled entry points

See [routes/console.php](routes/console.php): `strava:sync` (hourly during WIB running peaks, the fallback poll behind the webhook), `strava:ingest` (every 5 min drain), `geo:backfill-locations` (hourly geocode catch-up). [recomputeSummary()](app/Services/Run/Ingest/ActivityPipeline.php) re-derives one run's metrics from already-stored streams with **zero** Strava calls — the path behind a "Baca ulang" when HR zones change.
