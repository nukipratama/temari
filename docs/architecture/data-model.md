---
title: Data model
description: The core Eloquent domain — User → Strava/Activity graph, gamification rows, and the polymorphic AI/analytics tables split across two DB connections
tags: [architecture, data]
status: living
reviewed: 2026-07-07
code_refs:
  - app/Models/User.php
  - app/Models/Activity.php
  - app/Models/ActivityDetail.php
  - app/Models/ActivityStream.php
  - app/Models/StravaConnection.php
  - app/Models/RunCard.php
  - app/Models/StoryLine.php
  - app/Models/PersonalRecord.php
  - app/Models/UserUnlock.php
  - app/Models/RunnerProfile.php
  - app/Models/WeeklySnapshot.php
  - app/Models/AI/Analysis.php
  - app/Models/AI/TokenUsage.php
  - app/Models/Analytics/StravaSyncLog.php
  - app/Models/Scopes/AnalyzedScope.php
---

# Data model

The agent's first stop for "who relates to whom and which DB it lives in." Every model below was read to verify its relations and casts. Two connections are in play: the **default** app DB and a separate **`analytics`** connection (see [[analytics-db]]) that holds metering rows so an app-DB `migrate:fresh` can't wipe cost/sync history.

## Relationship sketch

```
User
 ├─ hasOne  StravaConnection      (OAuth tokens, encrypted)
 ├─ hasOne  RunnerProfile         (HR zones / cadence)
 ├─ hasMany Activity
 ├─ hasMany PersonalRecord
 ├─ hasMany WeeklySnapshot
 └─ hasMany StoryLine

Activity
 ├─ belongsTo User
 ├─ hasOne   ActivityDetail       (the metrics row)
 ├─ hasOne   ActivityStream       (raw blob, never list-loaded)
 ├─ hasOne   RunCard              (gamified card)
 ├─ hasMany  PersonalRecord
 ├─ hasMany  StoryLine  / hasOne postRunStoryLine (kind=post_run)
 └─ morphMany Analysis  (subject)

WeeklySnapshot ── morphMany Analysis (subject)

UserUnlock ── belongsTo User      (NO inverse on User; query directly)

Analysis (ai_analyses)   ── polymorphic, no Eloquent relation method
TokenUsage / StravaSyncLog ── standalone on `analytics`, user_id column only
```

## The User-rooted graph (default connection)

- [User](app/Models/User.php) is the aggregate root. `hasOne` [StravaConnection](app/Models/StravaConnection.php) + [RunnerProfile](app/Models/RunnerProfile.php); `hasMany` `activities`, `personalRecords`, `weeklySnapshots`, `storyLines`. The `scopeNotDemo` local scope (filters `is_demo`) keeps the seeded demo account out of schedulers. Deleting a User revokes its Strava connection and writes a `deleted` row to [StravaSyncLog](app/Models/Analytics/StravaSyncLog.php) via a `deleting` hook.
- [StravaConnection](app/Models/StravaConnection.php): `access_token`/`refresh_token` cast `encrypted` (and `Hidden`); `token_expires_at`/`revoked_at` are `datetime`. `markRevoked()` also purges that user's un-ingested stubs.
- [Activity](app/Models/Activity.php) is the run spine: `belongsTo` User, `hasOne` `detail`/`stream`/`runCard`, `hasMany` `personalRecords`/`storyLines`, and `morphMany` `analyses`. It carries the `#[ScopedBy(AnalyzedScope)]` global scope — see below. JSON cast: `milestone_payload` (`array`, also `$hidden`). See [[run-ingest-pipeline]].
- [ActivityDetail](app/Models/ActivityDetail.php) holds the computed metrics (`belongsTo` Activity). Heavy JSON casts: `splits_metric`, `stream_summary` (`array`). `start_date_local` uses `datetime:Y-m-d\TH:i:s` (no zone suffix — guards against the UTC-shift off-by-one). Weather columns: `weather_temp_c`, `weather_humidity_pct`, `weather_rain_detected`, `weather_wind_speed_kmh`, `weather_wind_gust_kmh`, `weather_wind_direction_deg`, `weather_rain_is_forecast` — all nullable, filled once during ingest by [`ActivityPipeline::lookupWeather`](app/Services/Run/Ingest/ActivityPipeline.php#L295).
- [ActivityStream](app/Models/ActivityStream.php): the raw `data` longText cast `array`. Docblock warns: never eager-load in list queries.
- [RunCard](app/Models/RunCard.php) (`belongsTo` Activity): `badges` cast `array`, `rarity` cast to the `Rarity` enum.
- [StoryLine](app/Models/StoryLine.php): the Temari mood/speech layer. `belongsTo` User **and** (nullable) Activity. `kind` discriminates `post_run` vs `daily_greeting`; `for_date` cast `date`.
- [PersonalRecord](app/Models/PersonalRecord.php): `belongsTo` User + (nullable) Activity. `category` → `PrCategory` enum, `value_sec` `float`.
- [WeeklySnapshot](app/Models/WeeklySnapshot.php): training-load rollup (`belongsTo` User, `morphMany` Analysis). `week_ending` cast `date:Y-m-d`.
- [UserUnlock](app/Models/UserUnlock.php): `belongsTo` User only — **User has no inverse `userUnlocks()` relation**, so query `UserUnlock` directly. `metadata` cast `array`, `equipped` `boolean`.
- [RunnerProfile](app/Models/RunnerProfile.php): `hr_zones` cast `array`; a `saving` hook stamps `hr_zones_changed_at` on any zone change.

## The AnalyzedScope gotcha

[AnalyzedScope](app/Models/Scopes/AnalyzedScope.php) is a global scope on [Activity](app/Models/Activity.php) that forces `analyzed_at IS NOT NULL`, hiding un-ingested Strava stubs from every default query. The ingest pipeline (and only it) opts out via the `withStubs`/`pendingIngest` scopes. Any "where are my activities?" surprise traces here first.

## AI + analytics rows

- [Analysis](app/Models/AI/Analysis.php) (table `ai_analyses`, default connection) is **polymorphic** via `subject_type`/`subject_id` but defines no Eloquent relation method — the inverse `morphMany analyses` lives on [Activity](app/Models/Activity.php) and [WeeklySnapshot](app/Models/WeeklySnapshot.php). `analysis_type` → `AnalysisType` enum, `status` → `AnalysisStatus` enum; `discriminator` distinguishes sibling blocks on one subject. See [[ai-pipeline]] for the status lifecycle.
- [TokenUsage](app/Models/AI/TokenUsage.php) (table `ai_token_usages`) and [StravaSyncLog](app/Models/Analytics/StravaSyncLog.php) (table `strava_sync_logs`) both set `protected $connection = 'analytics'` and `$timestamps = false`. They are flat metering logs: a `user_id` column, no Eloquent relations. `StravaSyncLog::log(...)` is the single write entrypoint. Details: [[analytics-db]].
