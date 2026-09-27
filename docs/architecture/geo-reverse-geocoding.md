---
title: Reverse Geocoding (start point → place name)
description: How a run's GPS start point becomes a human place name — async Nominatim resolve, atomic 1 req/sec request slot, 30-day grid cache with miss sentinels, hourly backfill
tags: [architecture, geo]
status: living
reviewed: 2026-09-28
code_refs:
  - app/Actions/Geo/ReverseGeocodeAction.php
  - app/Services/Geo/NominatimRateSlotUnavailable.php
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

[`ReverseGeocodeAction::__invoke`](app/Actions/Geo/ReverseGeocodeAction.php#L33) is the only public surface. Given a lat/lng it returns a [`ResolvedLocation` DTO](app/Services/Geo/ResolvedLocation.php#L13) (display `name` + uppercased ISO alpha-2 `country`) or `null`.

- **Caching + grid keying.** The cache key snaps coords to a coarse grid so adjacent points along a route collapse to one entry — see the `sprintf` precision in [`cacheKey`](app/Actions/Geo/ReverseGeocodeAction.php#L172) and the TTL constant at the [`Cache::put` in `__invoke`](app/Actions/Geo/ReverseGeocodeAction.php#L48). (Constants rot; read the lines.)
- **Miss sentinels.** `Cache::remember` can't memoize a `null`, so a miss is stored as the sentinel `false` and short-circuited on the next call — [the read/branch at the top of `__invoke`](app/Actions/Geo/ReverseGeocodeAction.php#L39). This stops a coord that genuinely has no address from re-hitting Nominatim every time.
- **Request pacing.** Before an uncached lookup, the action claims a shared cache lock, compares the next allowed request time, and holds the lock through the provider call. The next slot opens one second after that call returns; cached results bypass the gate.
- **Zoom level.** The request asks Nominatim for a suburb-level result so the address carries kecamatan + kota rather than a street or a whole province — see the `zoom` query param in [`fetchUncached`](app/Actions/Geo/ReverseGeocodeAction.php#L103).
- **Indonesian field preference.** Nominatim's address keys vary by country, so [`formatAddress`](app/Actions/Geo/ReverseGeocodeAction.php#L131) tries Indonesia-likely keys first and falls back to the global ones, taking the first hit per rank via [`firstFilled`](app/Actions/Geo/ReverseGeocodeAction.php#L160). The result is assembled coarse→fine into the comma-joined display name.
- **No throw-out.** Any HTTP/JSON failure is swallowed to `null` and logged at info level, never re-raised — the `try/catch` in [`fetchUncached`](app/Actions/Geo/ReverseGeocodeAction.php#L91). A polite `User-Agent` and `Accept-Language: en` are sent per Nominatim TOS ([headers](app/Actions/Geo/ReverseGeocodeAction.php#L94)).

## The job

[`ResolveActivityLocationJob`](app/Jobs/Geo/ResolveActivityLocationJob.php#L16) runs the resolver off the queue, keyed to one `ActivityDetail` row.

- **Rate-slot deferral.** When another worker owns the request lock or the next slot has not opened, the action throws [`NominatimRateSlotUnavailable`](app/Services/Geo/NominatimRateSlotUnavailable.php); the job releases itself for two seconds. This retry is bounded by the job's 20-minute `retryUntil` window.
- **Idempotency + uniqueness.** `ShouldBeUnique` keyed on the detail id ([`uniqueId`](app/Jobs/Geo/ResolveActivityLocationJob.php#L28)) dedupes queued copies, and the handler early-exits if the row is already stamped ([`handle`](app/Jobs/Geo/ResolveActivityLocationJob.php#L38)). Mind the scope: Laravel frees that lock when the job *finishes*, so it dedupes concurrent copies but not a caller that re-dispatches after each attempt has completed. Together with the unresolved-result rule below — a miss finishes *without* stamping — that is why the read-path dispatcher needs a guard of its own.
- **No-coords case is terminal.** A treadmill / manual run with no start coords is stamped resolved-with-no-name so the backfill stops reconsidering it — [the null-coords branch](app/Jobs/Geo/ResolveActivityLocationJob.php#L45).
- **Unresolved results.** On a `null`, the job returns without stamping, leaving the row eligible for the catch-up sweep — [the null-resolve guard](app/Jobs/Geo/ResolveActivityLocationJob.php#L63). The resolver currently caches null outcomes with the same 30-day sentinel as other misses.

## Where it's dispatched

1. **On ingest (primary).** [`ActivityPipeline`](app/Services/Run/Ingest/ActivityPipeline.php#L132) dispatches the job `afterCommit`, and only when the run actually has start coords, so the queued job never reads a detail the rolled-back ingest txn never wrote. See [[run-ingest-pipeline]].
2. **On run-detail view (lazy backfill).** When you open a GPS run whose location is still unresolved, [`RunController::show`](app/Http/Controllers/RunController.php#L41) fires a resolve so a viewed run heals itself. This is a *read* path, and `useAnalysisTrigger` reloads it every 3-15s for up to 30 ticks while the run insights generate — so it reserves a per-detail cache lock before dispatching ([the guard](app/Http/Controllers/RunController.php#L55), TTL on [`LOCATION_DISPATCH_GUARD_SECONDS`](app/Http/Controllers/RunController.php#L39)) and skips the dispatch when the lock is already held. Without it a run stuck on transient misses re-queued once per tick, each one another hit on a rate-limited public endpoint. The lock is per detail id, so opening a *different* unresolved run still resolves immediately, and ingest (path 1) is unaffected. See [[run-detail]].
3. **Hourly catch-up.** `geo:backfill-locations` is scheduled in [routes/console.php](routes/console.php#L51). [`BackfillActivityLocationsCommand`](app/Console/Commands/Geo/BackfillActivityLocationsCommand.php#L18) first recovers `start_lat`/`start_lng` from the stored `summary_polyline` for older rows ([`backfillCoordsFromPolyline`](app/Console/Commands/Geo/BackfillActivityLocationsCommand.php#L35)), then re-queues resolve jobs for every coord-bearing row still missing `location_resolved_at` ([`queueResolveJobs`](app/Console/Commands/Geo/BackfillActivityLocationsCommand.php#L56)). This is what sweeps up the transient misses the job deliberately left un-stamped.

## Where it's stored & who reads it

The three columns hang off [`ActivityDetail`](app/Models/ActivityDetail.php#L42) (`location_name`, `location_country`, `location_resolved_at`), added in [the migration](database/migrations/2026_05_13_095323_add_location_columns_to_activity_details_table.php). `location_resolved_at` doubles as the "has this been processed" flag the job and backfill both gate on. See [[data-model]].

Consumers select `location_name` (the display string) — never re-deriving place:

- [`RunController`](app/Http/Controllers/RunController.php#L85) → run detail's route + conditions slab ([`MapWeatherPanel`](resources/js/components/run/MapWeatherPanel.tsx#L23)). It is the only screen consumer since `PS3`: the dashboard's last-run location chip went with the port to the prototype's mini card, and `DashboardController` no longer selects `location_name` at all.
