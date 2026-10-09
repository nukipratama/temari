---
title: Data model
description: Every Eloquent model by domain — the User → Strava/Activity graph, plan and race, notifications and Telegram, Strava grants, AI rows, and the analytics tables — and which of the two DB connections each lives on
tags: [architecture, data]
status: living
reviewed: 2026-10-09
code_refs:
  - app/Models/User.php
  - app/Models/Activity.php
  - app/Models/ActivityDetail.php
  - app/Models/ActivityStream.php
  - app/Models/StravaConnection.php
  - app/Models/RunCard.php
  - app/Models/StoryLine.php
  - app/Models/PersonalRecord.php
  - app/Models/RunnerProfile.php
  - app/Models/WeeklySnapshot.php
  - app/Models/StreakRestToken.php
  - app/Models/RecordStamp.php
  - app/Models/PlannedSession.php
  - app/Models/Season.php
  - app/Models/SeasonGoal.php
  - app/Models/PlanAdaptation.php
  - app/Models/RaceGoal.php
  - app/Models/RaceGoalChange.php
  - app/Models/PerformanceEvidence.php
  - app/Models/FitnessAnchor.php
  - app/Models/TrendDailySnapshot.php
  - app/Models/RecommendationRevision.php
  - app/Models/RecommendationView.php
  - app/Models/RecoveryFeedback.php
  - app/Models/TrainingPreference.php
  - app/Models/NotificationPreference.php
  - app/Models/InboxNotification.php
  - app/Models/NotificationDelivery.php
  - app/Models/HeldNotification.php
  - app/Models/TelegramConnection.php
  - app/Models/TelegramLinkTokenUse.php
  - app/Models/TelegramUpdateReceipt.php
  - app/Models/StravaGrantToken.php
  - app/Models/StravaGrantEvent.php
  - app/Models/AI/Analysis.php
  - app/Models/AI/AnalysisVersion.php
  - app/Models/AI/RunQuestion.php
  - app/Models/Feedback.php
  - app/Models/AI/TokenUsage.php
  - app/Models/AI/ContentFilterEvent.php
  - app/Models/Analytics/StravaSyncLog.php
  - app/Models/Analytics/StravaRead.php
  - app/Models/Analytics/DevtoolsAction.php
  - app/Models/Analytics/ScheduledTaskRunLog.php
  - app/Models/ScheduledTaskRun.php
  - app/Models/Scopes/AnalyzedScope.php
---

# Data model

The agent's first stop for "who relates to whom and which DB it lives in." Every model under `app/Models` is named below, grouped by domain. Two connections are in play: the **default** app DB and a separate **`analytics`** connection (see [[analytics-db]]) that holds metering rows so an app-DB `migrate:fresh` can't wipe cost/sync history.

For a model's columns, casts, relations and connection, run `./vendor/bin/sail artisan model:show <Model>` (Laravel's `php artisan model:show`) rather than reading them here; this note keeps the relations and the gotchas a column list won't tell you.

## Relationship sketch

```
User
 ├─ hasOne  StravaConnection      (OAuth tokens, encrypted)
 ├─ hasOne  RunnerProfile         (HR zones / cadence)
 ├─ hasOne  TelegramConnection
 ├─ hasOne  NotificationPreference
 ├─ hasOne  TrainingPreference
 ├─ hasMany Activity
 ├─ hasMany PersonalRecord
 ├─ hasMany WeeklySnapshot
 ├─ hasMany InboxNotification
 └─ morphMany PushSubscription    (the webpush package's model, via HasPushSubscriptions)

Activity
 ├─ belongsTo User
 ├─ hasOne   ActivityDetail       (the metrics row)
 ├─ hasOne   ActivityStream       (raw blob, never list-loaded)
 ├─ hasOne   RunCard              (gamified card)
 ├─ hasMany  PersonalRecord
 ├─ hasOne   postRunStoryLine     (StoryLine, kind=post_run)
 └─ morphMany Analysis  (subject)

WeeklySnapshot / Season / PlanAdaptation ── morphMany Analysis (subject)

Season ── belongsTo RaceGoal (nullable), hasMany SeasonGoal
RaceGoal ── hasMany RaceGoalChange

Analysis (ai_analyses)   ── polymorphic, no Eloquent relation method
analytics connection     ── standalone logs, user_id column only
```

The sections below give every remaining model's relations, by domain.

## The User-rooted graph (default connection)

- [User](app/Models/User.php) is the aggregate root. `hasOne` [StravaConnection](app/Models/StravaConnection.php) + [RunnerProfile](app/Models/RunnerProfile.php); `hasMany` `activities`, `personalRecords`, `weeklySnapshots`. The `scopeNotDemo` local scope (filters `is_demo`) keeps the seeded demo account out of schedulers. Deleting a User revokes its Strava connection and writes a `deleted` row to [StravaSyncLog](app/Models/Analytics/StravaSyncLog.php) via a `deleting` hook.
- [StravaConnection](app/Models/StravaConnection.php): `access_token`/`refresh_token` cast `encrypted` (and `Hidden`); `token_expires_at`/`revoked_at` are `datetime`. `markRevoked()` also purges that user's un-ingested stubs.
- [Activity](app/Models/Activity.php) is the run spine: `belongsTo` User, `hasOne` `detail`/`stream`/`runCard`, `hasMany` `personalRecords`, and `morphMany` `analyses`. It carries the `#[ScopedBy(AnalyzedScope)]` global scope — see below. JSON cast: `milestone_payload` (`array`, also `$hidden`). See [[run-ingest-pipeline]].
- [ActivityDetail](app/Models/ActivityDetail.php) holds the computed metrics (`belongsTo` Activity). Heavy JSON casts: `splits_metric`, `laps`, `stream_summary` (`array`) — `laps` is Strava's raw `laps[]` array, stored on ingest because it comes free in the detail response. `start_date_local` uses `datetime:Y-m-d\TH:i:s` (no zone suffix — guards against the UTC-shift off-by-one). Weather columns: `weather_temp_c`, `weather_humidity_pct`, `weather_rain_detected`, `weather_wind_speed_kmh`, `weather_wind_gust_kmh`, `weather_wind_direction_deg`, `weather_rain_is_forecast` — all nullable, filled once during ingest by [`ActivityPipeline::lookupWeather`](app/Services/Run/Ingest/ActivityPipeline.php).
- [ActivityStream](app/Models/ActivityStream.php): the raw `data` longText cast `array`. Docblock warns: never eager-load in list queries.
- [RunCard](app/Models/RunCard.php) (`belongsTo` Activity): `badges` cast `array`, `rarity` cast to the `Rarity` enum.
- [StoryLine](app/Models/StoryLine.php): the Temari mood/speech layer. `belongsTo` User **and** (nullable) Activity. `kind` discriminates `post_run` vs `daily_greeting`; `for_date` cast `date:Y-m-d` (the explicit format guards against the UTC-shift off-by-one, same as `week_ending`). Activity reaches its post-run line through `postRunStoryLine`; User has no inverse relation.
- [PersonalRecord](app/Models/PersonalRecord.php): `belongsTo` User + (nullable) Activity. `category` → `PrCategory` enum, `value_sec` `float`.
- [WeeklySnapshot](app/Models/WeeklySnapshot.php): training-load rollup (`belongsTo` User, `morphMany` Analysis). `week_ending` cast `date:Y-m-d`.
- [RunnerProfile](app/Models/RunnerProfile.php): `hr_zones` cast `array`; a `saving` hook stamps `hr_zones_changed_at` on any zone change.
- [StreakRestToken](app/Models/StreakRestToken.php) (`belongsTo` User): one earned forgiveness of a runless week against the weekly streak, unspent while `spent_for_week_ending` is null. See [[gamification]].
- [RecordStamp](app/Models/RecordStamp.php) (`belongsTo` User): the "already played" flag for the run hero's PR bib stamp, unique on `(user_id, record_key)`. See [[run-detail]].

## The AnalyzedScope gotcha

[AnalyzedScope](app/Models/Scopes/AnalyzedScope.php) is a global scope on [Activity](app/Models/Activity.php) that forces `analyzed_at IS NOT NULL`, hiding un-ingested Strava stubs from every default query. The ingest pipeline (and only it) opts out via the `withStubs`/`pendingIngest` scopes. Any "where are my activities?" surprise traces here first. Visibility is separate from completeness: `ingest_state` ([IngestState](app/Enums/IngestState.php)) marks a visible row as `summary` or `detailed`, filtered by the `summaryOnly`/`detailed` local scopes — see [[run-ingest-pipeline]]. A raw `->join('activities', ...)` bypasses this scope entirely (Eloquent global scopes only apply to a model's own query), so any such join must re-assert the predicate through [`Activity::analyzedJoinConstraint()`](app/Models/Activity.php) rather than duplicating `whereNotNull` by hand.

## Plan and race (default connection)

- [PlannedSession](app/Models/PlannedSession.php) (`belongsTo` User): one day of the periodized plan, unique on `(user_id, date)`. A make-up move links the emptied day and its new day through `made_up_on` and a `made_up_from_id` column with no relation method. See [[plan-periodizer]].
- [Season](app/Models/Season.php): the training arc, `belongsTo` User and (nullable) [RaceGoal](app/Models/RaceGoal.php), `hasMany` [SeasonGoal](app/Models/SeasonGoal.php) as `goals`, and `morphMany` Analysis. Created and cycled by [`SeasonService::ensureCurrent()`](app/Services/Run/Plan/SeasonService.php).
- [SeasonGoal](app/Models/SeasonGoal.php) (`belongsTo` Season): one of the goals generated at season creation; its progress is resolved live by [SeasonGoalResolver](app/Services/Gamification/SeasonGoalResolver.php), never stored.
- [PlanAdaptation](app/Models/PlanAdaptation.php) (`belongsTo` User, `morphMany` Analysis): what the periodizer decided about one week and why, unique on `(user_id, week_start)`.
- [RaceGoal](app/Models/RaceGoal.php) (`belongsTo` User, `hasMany` [RaceGoalChange](app/Models/RaceGoalChange.php) as `changes`): a race the athlete trains for. At most one is `active` (`completed_at` null) per user, enforced in the application rather than by a constraint, since finished races are kept. See [[race-projection]].
- [RaceGoalChange](app/Models/RaceGoalChange.php) (`belongsTo` RaceGoal): an append-only history entry for a race's date, target or outcome. It also carries `user_id` and a nullable `activity_id` foreign key without relation methods. See [[a-race-outcome-is-confirmed-not-assumed]].
- [PerformanceEvidence](app/Models/PerformanceEvidence.php): a runner-confirmed race or test result, written only through [PerformanceEvidenceRecorder](app/Services/Run/Plan/PerformanceEvidenceRecorder.php). Foreign keys to the user, a nullable activity and a nullable race goal, unique on `(user_id, activity_id)`, and no relation methods. See [[profile]] and [[supported-race-time-from-recent-efforts]].
- [FitnessAnchor](app/Models/FitnessAnchor.php): one row per user (unique `user_id`), captured with the first VDOT estimate; its source activity ids are bare columns. See [[profile]].
- [TrendDailySnapshot](app/Models/TrendDailySnapshot.php) (`belongsTo` User): one row per user per day, unique on `(user_id, snapshot_date)`, recomputed by [TrendSnapshotWriter](app/Services/Run/Trend/TrendSnapshotWriter.php). See [[trends]].
- [RecommendationRevision](app/Models/RecommendationRevision.php) and [RecommendationView](app/Models/RecommendationView.php): the immutable history of what the plan showed. A revision is a day's original and effective recommendation, unique on `(user_id, date, fingerprint)`; a view records when one was shown (unique `observation_id`, cascading from its revision). Both throw on update and define no relation methods; [RecommendationHistory](app/Services/Run/Plan/RecommendationHistory.php) joins them so compliance grades a run against what was shown before it started. See [[plan-periodizer]].
- [RecoveryFeedback](app/Models/RecoveryFeedback.php) (`belongsTo` User): the athlete's optional recovery check-in for a day, unique on `(user_id, date)`. See [[plan-periodizer]].
- [TrainingPreference](app/Models/TrainingPreference.php) (`hasOne` from User, `belongsTo` User): explicit training overrides, every column nullable until the athlete sets it, falling back to the behaviour-derived baseline. See [[onboarding]] and [[plan-periodizer]].

## Notifications and Telegram (default connection)

The delivery path these rows serve is [[notification-delivery]].

- [NotificationPreference](app/Models/NotificationPreference.php) (`hasOne` from User): the master switch and the two channel mutes. A missing row means all on.
- [InboxNotification](app/Models/InboxNotification.php) (`hasMany` from User as `inboxNotifications`, table `notifications`): one inbox row, unique on `(user_id, dedupe_key)` and written only through `InboxNotification::record()`. Named so it doesn't collide with Laravel's own `DatabaseNotification`; the `Notifiable` trait's `notifications()` does not match this schema. See [[notification-inbox]].
- [NotificationDelivery](app/Models/NotificationDelivery.php): the per-`(analysis_id, channel)` claim and outcome for an outbound send. `analysis_id` is a real foreign key to `ai_analyses`, cascading on delete; there is no relation method.
- [HeldNotification](app/Models/HeldNotification.php): one channel's send held during quiet hours, the serialised notification plus a `user_id` foreign key that cascades with the user; no relation method.
- [TelegramConnection](app/Models/TelegramConnection.php) (`hasOne` from User, `belongsTo` User): the linked chat, revoked by stamping `revoked_at` rather than deleting; the `active` scope filters on it.
- [TelegramLinkTokenUse](app/Models/TelegramLinkTokenUse.php): keyed by the link token's hash, so each signed deep-link token links once; pruned after it expires. No user column.
- [TelegramUpdateReceipt](app/Models/TelegramUpdateReceipt.php): keyed by Telegram's `update_id`, the durable dedupe taken before an update is dispatched; pruned after seven days. See [[durable-telegram-update-receipts-and-link-claims]].

Push subscriptions are the `laravel-notification-channels/webpush` package's own `PushSubscription` model, reached through User's `HasPushSubscriptions` trait, so they have no class under `app/Models`.

## Strava grants (default connection)

[StravaConnection](app/Models/StravaConnection.php) is the live link; two more tables keep the Strava grant's release obligation, so they deliberately have no foreign key to `users` and survive account deletion. [StravaGrantToken](app/Models/StravaGrantToken.php) is one row per `strava_athlete_id`: the mirrored refresh token (cast `encrypted`, and `Hidden`) with its `credential_version` and a bare nullable `user_id`. [StravaGrantEvent](app/Models/StravaGrantEvent.php) is the append-only history of grant events with the same bare columns. Both are written through [StravaGrantLedger](app/Services/Strava/StravaGrantLedger.php). See [[strava-connect]].

## AI rows (default connection)

- [Analysis](app/Models/AI/Analysis.php) (table `ai_analyses`, default connection) is **polymorphic** via `subject_type`/`subject_id` but defines no Eloquent relation method — the inverse `morphMany analyses` lives on [Activity](app/Models/Activity.php), [WeeklySnapshot](app/Models/WeeklySnapshot.php), [Season](app/Models/Season.php) and [PlanAdaptation](app/Models/PlanAdaptation.php). `analysis_type` → `AnalysisType` enum, `status` → `AnalysisStatus` enum, `served_by` → `ServedBy` enum (which producer wrote the current content, null before the row was ever Done); `discriminator` distinguishes sibling blocks on one subject. Superseded content is kept in `analysis_versions` ([AnalysisVersion](app/Models/AI/AnalysisVersion.php), `belongsTo` Analysis), the one narration table with a real foreign key — see [[narration-analytics-are-joinable]]. See [[ai-pipeline]] for the status lifecycle.
- [RunQuestion](app/Models/AI/RunQuestion.php) (`belongsTo` User and Activity): one "ask about this run" exchange. Deliberately not an Analysis row, since a run accumulates many questions. See [[run-qa]].
- [Feedback](app/Models/Feedback.php) (`belongsTo` User): a runner flagging one plan day or one narration. `subject_type` is the `FeedbackSubject` enum plus a `subject_id`, not an Eloquent morph; `superseded_at` marks a flag whose narration has since been re-narrated. See [[feedback]].

## Analytics

- [TokenUsage](app/Models/AI/TokenUsage.php) (table `ai_token_usages`) and [StravaSyncLog](app/Models/Analytics/StravaSyncLog.php) (table `strava_sync_logs`) both carry `#[Connection('analytics')]` and `#[WithoutTimestamps]`. [DevtoolsAction](app/Models/Analytics/DevtoolsAction.php) (table `devtools_actions`, the /devtools audit trail) does the same, as do [StravaRead](app/Models/Analytics/StravaRead.php) (one row per Strava API response), [ContentFilterEvent](app/Models/AI/ContentFilterEvent.php) (one row per content-filter trip) and [ScheduledTaskRunLog](app/Models/Analytics/ScheduledTaskRunLog.php) (one row per scheduled run). They are flat logs: a bare `user_id` column where they have one, no Eloquent relations — `ai_token_usages.analysis_id` is bare for the same reason and joined in PHP ([[narration-analytics-are-joinable]]). `StravaSyncLog::log(...)` and [DevtoolsActionRecorder](app/Services/Devtools/DevtoolsActionRecorder.php) are their single write entrypoints. Details: [[analytics-db]].
- [ScheduledTaskRun](app/Models/ScheduledTaskRun.php) is the one scheduler table on the **default** connection: a heartbeat per command (unique `command`) with its last status and runtime, upserted by the same [RecordScheduledTaskRun](app/Listeners/RecordScheduledTaskRun.php) listener that writes the log. See [[scheduler]].
