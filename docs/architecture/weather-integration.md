---
title: Weather Integration
description: How a run's start-time weather (temp / humidity / rain / wind) is fetched from Open-Meteo, cached, stored on the activity detail, and surfaced across run detail, dashboard, and AI narration
tags: [architecture, weather]
status: living
reviewed: 2026-11-02
code_refs:
  - app/Services/Weather/OpenMeteoClient.php
  - app/Services/Weather/WeatherSnapshot.php
  - app/Services/Weather/HeatIndex.php
  - app/Exceptions/Weather/OpenMeteoUnavailableException.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Console/Commands/Weather/BackfillActivityWeatherCommand.php
  - app/Console/Commands/Weather/CorrectForecastWeatherCommand.php
  - app/Models/ActivityDetail.php
  - resources/js/pages/Runs/Show.tsx
  - resources/js/components/run/MapWeatherPanel.tsx
  - resources/js/components/run/WeatherRail.tsx
  - app/Services/AI/Context/ActivityNarrationContext.php
---

# Weather Integration

Every run gets one weather reading taken at its start point and spread over its start-to-finish window: mean and peak temperature, humidity, dew point, apparent ("feels-like") temperature, wind speed/gust/direction, and a rain-detected boolean. The reading is fetched once during [[run-ingest-pipeline|ingest]], frozen onto the activity detail row, and read back everywhere after that — there is no live weather call at render time.

## The client

[OpenMeteoClient](app/Services/Weather/OpenMeteoClient.php) talks to [Open-Meteo](https://open-meteo.com) and returns an immutable [WeatherSnapshot](app/Services/Weather/WeatherSnapshot.php) value object (mean and peak temp °C, humidity %, dew point °C, apparent temp °C, wind speed/gust km/h, wind direction °, rain-detected bool) — or `null` when a request fails or the response is unusable, and throws when Open-Meteo is throttling or unreachable (see below).

**Forecast vs. archive routing.** Open-Meteo splits "recent" and "historical" across two endpoints. The client picks one based on how old the run is: fresh runs hit the forecast endpoint (which carries a window of recent past days), older runs hit the archive endpoint. The age cutoff and the two endpoint URLs are at [OpenMeteoClient.php](app/Services/Weather/OpenMeteoClient.php) and the branch is in [`fetchForActivity()`](app/Services/Weather/OpenMeteoClient.php). The two endpoints take different query params — the run's start-to-finish dates for the archive vs. a past/forecast-days window for the forecast — assembled in [`params()`](app/Services/Weather/OpenMeteoClient.php). Measured on 2026-11-02 at a Jakarta point, the archive's default model returned the same hourly values as the forecast endpoint and Open-Meteo's historical-forecast endpoint (ECMWF IFS at about 9 km, not ERA5), so the routing changes which flag is set more than which numbers come back.

**Sampling the run.** Open-Meteo returns hourly arrays bucketed by local wall-clock (`timezone=auto`), which lines up with Strava's `start_date_local`. [`parse()`](app/Services/Weather/OpenMeteoClient.php) samples the run every [`SAMPLE_STEP_SECONDS`](app/Services/Weather/OpenMeteoClient.php) from start to start plus `elapsed_time`, interpolating each instantaneous variable between the hours around it ([`sampled()`](app/Services/Weather/OpenMeteoClient.php)), and stores the mean, plus the peak for temperature and gusts. Wind direction is not averaged; it is the bearing at the hour nearest the run's midpoint ([`directionNear()`](app/Services/Weather/OpenMeteoClient.php)). Rain is a derived boolean: an hourly precipitation total covers the hour ending at its timestamp, and any hour the run overlaps over [`RAIN_THRESHOLD_MM`](app/Services/Weather/OpenMeteoClient.php) counts ([`rainedDuring()`](app/Services/Weather/OpenMeteoClient.php)).

**Caching.** Results are cached keyed by rounded lat/lng, start minute and duration ([`cacheKey()`](app/Services/Weather/OpenMeteoClient.php)), with **separate TTLs**: a short TTL for forecast hits (the recent past can still be revised) and a long one for archive hits (history is settled), chosen in [`fetch()`](app/Services/Weather/OpenMeteoClient.php) ([`FORECAST_CACHE_TTL` and `ARCHIVE_CACHE_TTL`](app/Services/Weather/OpenMeteoClient.php)). Only the primitive shape is cached, never the `WeatherSnapshot` object — a non-array hit is treated as a miss and refetched so legacy/poisoned keys self-heal; the rationale is documented inline at [OpenMeteoClient.php](app/Services/Weather/OpenMeteoClient.php). The prefix is versioned (`weather:v3:...` since window sampling) so an entry of an older shape is never read back.

**Never blocks, best-effort.** The HTTP call has a tight timeout ([request()](app/Services/Weather/OpenMeteoClient.php), [`TIMEOUT_SECONDS`](app/Services/Weather/OpenMeteoClient.php)), and a connection failure or timeout is tried again once after a second ([`CONNECTION_ATTEMPTS`](app/Services/Weather/OpenMeteoClient.php)), because ingest narrates the run straight after and nothing re-narrates it once a later backfill fills the weather in. On 2026-11-05 a single 5 s timeout left a fresh run without weather and its narration described conditions it never read. A 429 is not retried. A 4xx/5xx response or an unexpected client error logs a warning and returns `null` ([`fetchUncached()`](app/Services/Weather/OpenMeteoClient.php)). A rate limit (HTTP 429) or a connection failure or timeout instead throws [OpenMeteoUnavailableException](app/Exceptions/Weather/OpenMeteoUnavailableException.php), with the query string (and so the coordinates) scrubbed from its message, because every following request in a batch would fail the same way. Ingest catches it like any other lookup failure, and the batch commands stop on it.

## Where it's stored

The snapshot lands on the [ActivityDetail](app/Models/ActivityDetail.php) row as ten nullable, casted columns — `weather_temp_c` (the mean over the run), `weather_temp_max_c`, `weather_humidity_pct`, `weather_dew_point_c`, `weather_apparent_temp_c`, `weather_rain_detected`, `weather_wind_speed_kmh`, `weather_wind_gust_kmh`, `weather_wind_direction_deg`, `weather_rain_is_forecast` ([`ActivityDetail::casts()`](app/Models/ActivityDetail.php)). See [[data-model]] for how `ActivityDetail` hangs off the run.

It's written during ingest by [ActivityPipeline::lookupWeather](app/Services/Run/Ingest/ActivityPipeline.php): it uses the detail's stored `start_lat` / `start_lng`, `start_date_local` and `elapsed_time`, then calls the client. Consistent with the pipeline's best-effort contract (the header comment of [ActivityPipeline](app/Services/Run/Ingest/ActivityPipeline.php)), a missing coord/time short-circuits and a thrown lookup is caught and logged so weather can never strand an activity as an un-ingestable stub ([`ActivityPipeline::lookupWeather()`](app/Services/Run/Ingest/ActivityPipeline.php)). The same start point feeds [[geo-reverse-geocoding]], which populates `location_name`.

**Backfill.** Transient Open-Meteo misses leave `weather_temp_c` null even though coords exist. [`weather:backfill`](app/Console/Commands/Weather/BackfillActivityWeatherCommand.php) re-fetches every non-demo row with coords and a null `weather_temp_max_c` up to a `--limit`: rows with no weather at all, and rows read at the start hour before window sampling existed. So a temporary outage self-repairs on the next run, and old rows are re-sampled a batch a night. Requests are spaced a second apart, and when Open-Meteo throttles or stops answering the batch ends there without charging any row an attempt; the rest wait for the next run. This was measured on 2026-11-02, when an unpaced 2,000-row run drew HTTP 429s and 5 s timeouts. Every `null` result bumps `weather_attempts` and stamps `weather_attempted_at`. The batch takes rows with no weather first, then never-attempted rows, then the oldest attempt, then `id`, and rows with `ActivityDetail::MAX_BACKFILL_ATTEMPTS` (5) attempts are excluded for good, so rows that can never be filled cannot starve newer gaps. The count is never reset automatically. Each run also counts the non-demo runs still without weather between `ActivityDetail::PERSISTENT_GAP_HOURS` (48 h) and `PERSISTENT_GAP_MAX_DAYS` (7 d) after ingest and pages once while that count is above zero, the same way geo does ([[scheduler]]); a miss the next run repairs never pages.

**Correct forecast.** Archived runs that land late in the forecast window may have stale data after the archive endpoint stabilises. [`weather:correct-forecast`](app/Console/Commands/Weather/CorrectForecastWeatherCommand.php) runs at 03:15 daily (see [routes/console.php](routes/console.php)) and re-fetches archive-endpoint weather for runs that were ingested within the forecast-window overlap, replacing initial forecast data with settled archive data.

## What consumes it

All consumers read the stored columns; none call Open-Meteo.

- **[[run-detail]]** — [MapWeatherPanel](resources/js/components/run/MapWeatherPanel.tsx) renders the temp / humidity / wind / location block beside the route map, on both mobile and desktop, with [WeatherRail](resources/js/components/run/WeatherRail.tsx) drawing the mean, feels-like, dew point and (when it stands out) peak on one scale under it, and [VitalsCard](resources/js/components/run/VitalsCard.tsx) reads the server's `ran_hot` flag to explain a hot run's heart-rate drift. The dashboard's last-run weather chip went with the port to the prototype's mini card, which draws no chip (see [[dashboard]]).
- **[[ai-pipeline|AI narration]]** — temp and rain flow into [ActivityNarrationContext](app/Services/AI/Context/ActivityNarrationContext.php) so Temari's run commentary can mention the conditions.
- **Past You, badges, mood and Q&A** — [PastYouMatcher](app/Services/Run/Story/PastYouMatcher.php) matches past runs on temperature, [BadgeEvaluator](app/Services/Run/Story/BadgeEvaluator.php) awards the heat and rain badges from the stored weather, [Temari](app/Services/Run/Story/Temari.php) weighs hot weather in a run's mood, and the card facts and the run Q&A answers ([RuleBasedRunAnswer](app/Services/AI/RunQuestion/RuleBasedRunAnswer.php)) quote it.

**What counts as a hot run.** Every heat check (the heat badge, mood, rule-based narration and insights, the run Q&A seed and answer, VitalsCard) goes through one predicate, [`ActivityDetail::ranHot()`](app/Models/ActivityDetail.php). A run is hot when the US National Weather Service heat index of its stored temperature and humidity reaches 90 °F (32.2 °C). That is where the NWS "Extreme Caution" band starts: heat stroke, cramps or exhaustion become possible with prolonged exposure and/or physical activity ([NWS heat index](https://www.weather.gov/ama/heatindex)). [HeatIndex](app/Services/Weather/HeatIndex.php) implements the NWS Rothfusz regression with its adjustments ([NWS equation](https://www.wpc.ncep.noaa.gov/html/heatindex_equation.shtml)). The rule replaced "air temperature ≥ 31 °C", which ignored humidity and, on integer storage, fired from 30.5 °C. Open-Meteo's own `apparent_temperature` (Steadman's apparent temperature, not the NWS index) was measured and rejected: on the owner's 36 runs it read about 3.3 °C above Strava's feels-like and would have flagged 26 of them, mornings included, against 12 for the heat index. Decision: #2345.

## Notes / gotchas

- Weather is sampled over the run's **duration but at the start point only**, not along the route.
- Re-sampling changes only the weather columns. Badges, mood and narration built from the old reading are not recomputed.
- Because rain is a precipitation-threshold boolean, light drizzle below the threshold reads as "no rain"; see [`RAIN_THRESHOLD_MM`](app/Services/Weather/OpenMeteoClient.php).
- The cache key rounds coordinates, so two runs starting near each other in the same minute with the same duration share a reading — intentional, it dedupes the upstream call.
