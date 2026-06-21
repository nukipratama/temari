---
title: Reverse Geocoding (start point → place name)
description: How a run's GPS start point becomes a human place name — async Nominatim resolve, 1 req/sec lock, 30-day grid cache with miss sentinels, transient-vs-permanent error handling, hourly backfill
tags: [architecture, geo]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/Geo/NominatimResolver.php
  - app/Services/Geo/ResolvedLocation.php
  - app/Jobs/Geo/ResolveActivityLocationJob.php
  - app/Console/Commands/Geo/BackfillActivityLocationsCommand.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Models/ActivityDetail.php
  - routes/console.php
---

# Reverse Geocoding (start point → place name)

Turns a run's start coordinate into a display string like *"Kebayoran Baru, Jakarta Selatan, DKI Jakarta, Indonesia"*. The resolve is asynchronous and best-effort: it never blocks ingest, never throws into the pipeline, and degrades to "no location" silently. The resolved text lives on the run's detail row and is read by the UI wherever a run shows its sense of place.

> Citations link to the **file** (the CI guard verifies paths, never line numbers — lines rot); the `L<n>` in the link text is the spot to jump to as of `reviewed`.

## The resolver

[`NominatimResolver::reverse`](app/Services/Geo/NominatimResolver.php#L22) is the only public surface. Given a lat/lng it returns a [`ResolvedLocation` DTO](app/Services/Geo/ResolvedLocation.php#L13) (display `name` + uppercased ISO alpha-2 `country`) or `null`.

- **Caching + grid keying.** The cache key snaps coords to a coarse grid so adjacent points along a route collapse to one entry — see the `sprintf` precision in [`cacheKey`](app/Services/Geo/NominatimResolver.php#L123) and the TTL constant at the [`Cache::put` in `reverse`](app/Services/Geo/NominatimResolver.php#L37). (Constants rot; read the lines.)
- **Miss sentinels.** `Cache::remember` can't memoize a `null`, so a miss is stored as the sentinel `false` and short-circuited on the next call — [the read/branch at the top of `reverse`](app/Services/Geo/NominatimResolver.php#L28). This stops a coord that genuinely has no address from re-hitting Nominatim every time.
- **Zoom level.** The request asks Nominatim for a suburb-level result so the address carries kecamatan + kota rather than a street or a whole province — see the `zoom` query param in [`fetchUncached`](app/Services/Geo/NominatimResolver.php#L54).
- **Indonesian field preference.** Nominatim's address keys vary by country, so [`formatAddress`](app/Services/Geo/NominatimResolver.php#L82) tries Indonesia-likely keys first and falls back to the global ones, taking the first hit per rank via [`firstFilled`](app/Services/Geo/NominatimResolver.php#L111). The result is assembled coarse→fine into the comma-joined display name.
- **No throw-out.** Any HTTP/JSON failure is swallowed to `null` and logged at info level, never re-raised — the `try/catch` in [`fetchUncached`](app/Services/Geo/NominatimResolver.php#L68). A polite `User-Agent` and `Accept-Language: id,en` are sent per Nominatim TOS ([headers](app/Services/Geo/NominatimResolver.php#L45)).

Note the rate limit is **not** enforced here — see the job below.

## The job

[`ResolveActivityLocationJob`](app/Jobs/Geo/ResolveActivityLocationJob.php#L15) runs the resolver off the queue, keyed to one `ActivityDetail` row.

- **1 req/sec, app-wide.** Nominatim's TOS caps callers at one request per second. The job serializes *every* resolve through a single global [`WithoutOverlapping` lock](app/Jobs/Geo/ResolveActivityLocationJob.php#L40) (one named key for all rows, not per-row), so concurrent workers can't stampede the endpoint.
- **Idempotency + uniqueness.** `ShouldBeUnique` keyed on the detail id ([`uniqueId`](app/Jobs/Geo/ResolveActivityLocationJob.php#L29)) dedupes queued copies, and the handler early-exits if the row is already stamped ([`handle`](app/Jobs/Geo/ResolveActivityLocationJob.php#L49)).
- **No-coords case is terminal.** A treadmill / manual run with no start coords is stamped resolved-with-no-name so the backfill stops reconsidering it — [the null-coords branch](app/Jobs/Geo/ResolveActivityLocationJob.php#L53).
- **Transient vs permanent.** This is the crux: on a real hit the job writes `location_name` / `location_country` and stamps `location_resolved_at`; on a `null` (rate-limit / timeout / empty body) it **returns without stamping**, leaving the row eligible for the catch-up sweep — [the null-resolve guard](app/Jobs/Geo/ResolveActivityLocationJob.php#L65). With only `$tries = 2` ([retry config](app/Jobs/Geo/ResolveActivityLocationJob.php#L19)), durable recovery is the backfill's job, not the retry's.

## Where it's dispatched

1. **On ingest (primary).** [`ActivityPipeline`](app/Services/Run/Ingest/ActivityPipeline.php#L132) dispatches the job `afterCommit`, and only when the run actually has start coords, so the queued job never reads a detail the rolled-back ingest txn never wrote. See [[run-ingest-pipeline]].
2. **On run-detail view (lazy backfill).** When you open a GPS run whose location is still unresolved, [`RunController`](app/Http/Controllers/RunController.php#L299) fires a one-off resolve so a viewed run heals itself. See [[run-detail]].
3. **Hourly catch-up.** `geo:backfill-locations` is scheduled in [routes/console.php](routes/console.php#L51). [`BackfillActivityLocationsCommand`](app/Console/Commands/Geo/BackfillActivityLocationsCommand.php#L18) first recovers `start_lat`/`start_lng` from the stored `summary_polyline` for older rows ([`backfillCoordsFromPolyline`](app/Console/Commands/Geo/BackfillActivityLocationsCommand.php#L35)), then re-queues resolve jobs for every coord-bearing row still missing `location_resolved_at` ([`queueResolveJobs`](app/Console/Commands/Geo/BackfillActivityLocationsCommand.php#L56)). This is what sweeps up the transient misses the job deliberately left un-stamped.

## Where it's stored & who reads it

The three columns hang off [`ActivityDetail`](app/Models/ActivityDetail.php#L42) (`location_name`, `location_country`, `location_resolved_at`), added in [the migration](database/migrations/2026_05_13_095323_add_location_columns_to_activity_details_table.php). `location_resolved_at` doubles as the "has this been processed" flag the job and backfill both gate on. See [[data-model]].

Consumers select `location_name` (the display string) — never re-deriving place:

- [`DashboardController`](app/Http/Controllers/DashboardController.php#L56) → the last-run card ([`LastLariCard`](resources/js/components/dashboard/LastLariCard.tsx#L23), shortened for the chip).
- [`RunController`](app/Http/Controllers/RunController.php#L299) → run detail's weather/place hero ([`WeatherHero`](resources/js/components/aktivitas/WeatherHero.tsx#L39), [`Show`](resources/js/pages/Runs/Show.tsx#L248)).
- [`PrScoreboardBuilder`](app/Services/Run/PrScoreboardBuilder.php#L59) + [`RekorController`](app/Http/Controllers/RekorController.php#L50) → "where this PR was set" on the records page ([`Rekor`](resources/js/pages/Koleksi/Rekor.tsx#L130)). See [[records]].
