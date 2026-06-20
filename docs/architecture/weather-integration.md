---
title: Weather Integration
description: How a run's start-time weather (temp / humidity / rain) is fetched from Open-Meteo, cached, stored on the activity detail, and surfaced across run detail, dashboard, and AI narration
tags: [architecture, weather]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/Weather/OpenMeteoClient.php
  - app/Services/Weather/WeatherSnapshot.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Console/Commands/Weather/BackfillActivityWeatherCommand.php
  - app/Models/ActivityDetail.php
  - resources/js/pages/Runs/Show.tsx
  - resources/js/components/dashboard/SuggestionCard.tsx
  - resources/js/components/dashboard/LastLariCard.tsx
  - resources/js/components/aktivitas/WeatherHero.tsx
  - app/Services/AI/Context/ActivityNarrationContext.php
---

# Weather Integration

Every run gets a one-shot weather reading taken at its start point and start hour: temperature, humidity, and whether it was raining. The reading is fetched once during [[run-ingest-pipeline|ingest]], frozen onto the activity detail row, and read back everywhere after that — there is no live weather call at render time.

## The client

[OpenMeteoClient](app/Services/Weather/OpenMeteoClient.php) talks to [Open-Meteo](https://open-meteo.com) and returns an immutable [WeatherSnapshot](app/Services/Weather/WeatherSnapshot.php) value object (temp °C, humidity %, rain bool) — or `null` when anything goes wrong.

**Forecast vs. archive routing.** Open-Meteo splits "recent" and "historical" across two endpoints. The client picks one based on how old the run is: fresh runs hit the forecast endpoint (which carries a window of recent past days), older runs hit the archive endpoint. The age cutoff and the two endpoint URLs are at [OpenMeteoClient.php:16-21](app/Services/Weather/OpenMeteoClient.php#L16) and the branch is at [OpenMeteoClient.php:36](app/Services/Weather/OpenMeteoClient.php#L36). The two endpoints take different query params — a single dated day for the archive vs. a past/forecast-days window for the forecast — assembled in [`params()`](app/Services/Weather/OpenMeteoClient.php#L117).

**Picking the hour.** Open-Meteo returns hourly arrays bucketed by local wall-clock (`timezone=auto`), which lines up with Strava's `start_date_local`. [`parse()`](app/Services/Weather/OpenMeteoClient.php#L144) finds the run's start hour in the `time` array and reads the matching temp/humidity/precipitation. Rain is a derived boolean: precipitation over a small millimetre threshold counts as rain, see [OpenMeteoClient.php:179](app/Services/Weather/OpenMeteoClient.php#L179) (threshold defined at [line 23](app/Services/Weather/OpenMeteoClient.php#L23)).

**Caching.** Results are cached keyed by rounded lat/lng + start hour ([`cacheKey()`](app/Services/Weather/OpenMeteoClient.php#L183)), with **separate TTLs**: a short TTL for forecast hits (the recent past can still be revised) and a long one for archive hits (history is settled), chosen at [OpenMeteoClient.php:50](app/Services/Weather/OpenMeteoClient.php#L50) (TTL constants at [lines 27-29](app/Services/Weather/OpenMeteoClient.php#L27)). Only the primitive shape is cached, never the `WeatherSnapshot` object — a non-array hit is treated as a miss and refetched so legacy/poisoned keys self-heal; the rationale is documented inline at [OpenMeteoClient.php:39-46](app/Services/Weather/OpenMeteoClient.php#L39).

**Never blocks, best-effort.** The HTTP call has a tight timeout ([request()](app/Services/Weather/OpenMeteoClient.php#L193), timeout at [line 25](app/Services/Weather/OpenMeteoClient.php#L25)). A thrown request logs a warning and returns `null` ([catch at line 96](app/Services/Weather/OpenMeteoClient.php#L96)); a 4xx/5xx response returns `null` silently ([line 107](app/Services/Weather/OpenMeteoClient.php#L107)). The caller always gets either a usable snapshot or `null`, never an exception.

## Where it's stored

The snapshot lands on the [ActivityDetail](app/Models/ActivityDetail.php) row as three nullable, casted columns — `weather_temp_c`, `weather_humidity_pct`, `weather_rain_detected` ([casts at ActivityDetail.php:141-143](app/Models/ActivityDetail.php#L141)). See [[data-model]] for how `ActivityDetail` hangs off the run.

It's written during ingest by [ActivityPipeline::lookupWeather](app/Services/Run/Ingest/ActivityPipeline.php#L295): it pulls the first lat/lng from the streams blob, uses the detail's `start_date_local`, and calls the client. Consistent with the pipeline's best-effort contract ([class header comment at ActivityPipeline.php:34](app/Services/Run/Ingest/ActivityPipeline.php#L34)), a missing coord/time short-circuits and a thrown lookup is caught and logged so weather can never strand an activity as an un-ingestable stub ([catch at ActivityPipeline.php:314](app/Services/Run/Ingest/ActivityPipeline.php#L314)). The same start point feeds [[geo-reverse-geocoding]], which populates `location_name`.

**Backfill.** Transient Open-Meteo misses leave `weather_temp_c` null even though coords exist. [`weather:backfill`](app/Console/Commands/Weather/BackfillActivityWeatherCommand.php) re-fetches exactly those rows (coords present, weather null) up to a `--limit`, so a temporary outage self-repairs on the next run.

## What consumes it

All consumers read the stored columns; none call Open-Meteo.

- **[[run-detail]]** — `MapWeatherPanel` on [Runs/Show.tsx](resources/js/pages/Runs/Show.tsx#L245) renders the temp / humidity / location block beside the route map. (A richer standalone [WeatherHero](resources/js/components/aktivitas/WeatherHero.tsx) card also exists, keyed off the same three fields.)
- **[[dashboard]]** — the last-run weather chip on [LastLariCard](resources/js/components/dashboard/LastLariCard.tsx#L24) and [SuggestionCard](resources/js/components/dashboard/SuggestionCard.tsx#L50), both via the `formatWeather` helper.
- **[[ai-pipeline|AI narration]]** — temp and rain flow into [ActivityNarrationContext](app/Services/AI/Context/ActivityNarrationContext.php#L41) so Temari's run commentary can mention the conditions.

## Notes / gotchas

- Weather is sampled **once at the start point/hour**, not averaged over the route or duration — a long run that started cool and ended hot reads as cool.
- Because rain is a precipitation-threshold boolean, light drizzle below the threshold reads as "no rain"; see [OpenMeteoClient.php:179](app/Services/Weather/OpenMeteoClient.php#L179).
- The cache key rounds coordinates, so two runs starting near each other in the same hour share a reading — intentional, it dedupes the upstream call.
</content>
</invoke>
