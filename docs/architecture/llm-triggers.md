---
title: The LLM surface — everything that calls a model, what starts it, and what it costs
description: The complete inventory of narrators, agent tools and deterministic producers, with the four origins that dispatch them, the seven things that stop them, a proposed verdict per surface, and what the prod rebuild means for spend.
tags: [architecture, ai]
status: living
reviewed: 2026-09-04
code_refs:
  - routes/console.php
  - app/Services/AI/StructuredChatCaller.php
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/AnalysisType.php
  - app/Services/AI/AnalysisOrigin.php
  - app/Services/AI/NarrationOrigin.php
  - app/Services/AI/TemariPersona.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Http/Controllers/Api/RunQuestionController.php
  - app/Services/AI/SelfHealer.php
  - app/Services/AI/PlanNarrationRequester.php
  - app/Services/AI/BackfillAgeGate.php
  - app/Models/AI/TokenUsage.php
  - app/Actions/AI/RecordTokenUsageAction.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
  - tests/Unit/Architecture/LlmInventoryDocTest.php
---

# The LLM surface — everything that calls a model, what starts it, and what it costs

[[ai-pipeline]] explains the machinery a narrated block flows through. This note is the map of
**what exists**: every narrator, every tool it can be handed, every deterministic producer that
computes the numbers it describes, and who starts each call. Read it before adding a narrated
block, before cutting one, and before trying to explain a spend spike.

Every call funnels through one chokepoint —
[`StructuredChatCaller`](../../app/Services/AI/StructuredChatCaller.php#L29). If a code path does
not reach it, it does not cost money.

**This note is a gate, not a diary.**
[`LlmInventoryDocTest`](../../tests/Unit/Architecture/LlmInventoryDocTest.php#L44) fails the
structure group when a narrator, a concrete agent tool or an `AnalysisType` case exists in code but
not here, *and* when this note names a narrator or tool that no longer exists. Adding a surface
without a row here is a red build.

> **`cadence()` on `AnalysisType` looks like the origin map and is not.** `OnDemand` covers both a
> scheduled command and a user button, and `PerActivity` fires from an ingest cascade the enum never
> mentions. Use the origins below. The enum is a standing trap and a cut candidate.

## The four origins

Origin is a property of the dispatcher, not the narrator: the same `RunInsightNarrator` answers an
ingest cascade, a "Reread" and a self-heal. Each entry point declares itself once by setting
[`NarrationOrigin`](../../app/Services/AI/NarrationOrigin.php#L23),
[`AnalysisService::stamped()`](../../app/Services/AI/AnalysisService.php#L355) writes that
[`AnalysisOrigin`](../../app/Services/AI/AnalysisOrigin.php#L19) onto the job, and the job restores it
before generating, so the metering row records what started the call rather than only which narrator
answered. A dispatch site that declares nothing records `unknown` rather than a guess.

### 1. Scheduled

Derived from [routes/console.php](../../routes/console.php), which is the only authority — a
scheduled command missing from this table is a bug in this table.

| when | command | what it dispatches |
|---|---|---|
| daily 00:01 | [`ai:daily-briefing`](../../routes/console.php#L38) | one `BriefingMascotVoice` per active non-demo user |
| Mon 00:01 | [`ai:weekly-recap`](../../routes/console.php#L49) | `WeeklyRecap`, oldest unfinished link first |
| Mon 00:05 | [`ai:weekly-profile`](../../routes/console.php#L55) | `ProfileVoice`, keyed by ISO week |
| **Mon 00:07** | [**`plan:regenerate`**](../../routes/console.php#L75) | **up to 9 rows per user — see below** |
| 1st 05:45 | [`ai:monthly-recap`](../../routes/console.php#L83) | `MonthlyRecap`, oldest first |
| daily 06:00 | [`ai:trend-read 30d`](../../routes/console.php#L90) | `TrendRead`, discriminator `30d` |
| every 3rd day 06:00 | [`ai:trend-read 90d`](../../routes/console.php#L91) | discriminator `90d` |
| Mon 06:00 | [`ai:trend-read 12mo`](../../routes/console.php#L92) | discriminator `12mo` |
| hourly | [`ai:self-heal`](../../routes/console.php#L100) | recovery only — see origin 4 |

**`plan:regenerate` is the one to know about.** The periodizer it runs is deterministic and free,
but the command then calls
[`requestForCurrentWeek()`](../../app/Services/AI/PlanNarrationRequester.php#L86) for every non-demo
user, touching up to nine rows: `PlanDayVoice` ×7, `PlanWeekVoice`, and `PlanSeasonVoice`. It is the
largest scheduled spend in the app, which is why it is also the only one that checks before it bills.

**Those nine re-bill only where the material changed.** The periodizer frequently rewrites a week
into something that reads identically — the same session type, phase and prescribed distance produce
the same blurb — so each row carries a
[`MaterialFingerprint`](../../app/Services/AI/MaterialFingerprint.php#L26) of what it describes,
stamped by the job through
[`AnalyzeRowJob::fingerprintFor()`](../../app/Jobs/AI/AnalyzeRowJob.php#L94), and an unchanged
fingerprint means the row is left alone. `PlanSeasonVoice` needs no fingerprint; it relies on
`AnalysisService`'s own idempotency.

A row with **no** stored fingerprint counts as changed — the inverse of the per-run rule in
`DispatchPostRunAnalysis`, and deliberately so. Only the rule-based paths leave the column null (a
cost-capped or content-filtered day), and those must stay eligible for a real narration rather than
keeping filler forever.

Everything else on the schedule (`strava:sync`, `geo:backfill-locations`, `weather:*`,
`trend:snapshot-daily`, `plan:score-compliance`, `streak:*`, `demo:daily-refresh`) reaches no model.
`demo:daily-refresh` is worth naming because it looks like it should: it runs under
`AnalysisService::withoutDispatching()`, so the demo account's content is filled deterministically
and spends nothing. See [[demo-user-billing-exclusion]].

### 2. Ingest cascade

[`DispatchPostRunAnalysis::handle()`](../../app/Listeners/DispatchPostRunAnalysis.php#L41) is queued
on `ActivityIngested` and is where most per-run spend originates. In order: `CardFlavor`, then the
grouped `PostRunSpeech` + `RunInsight` pair, then `BriefingMascotVoice` (invalidated only when the
run is today's), then `ProfileVoice` keyed by the current ISO week with `invalidate: false` so it
never re-bills. `WeeklyRecap` and `MonthlyRecap` rows are **staged `Pending` and not narrated here** —
the scheduled commands above narrate them once the window closes, which is why a pending recap row
is not a backlog. See [[deferred-recap-windowing]].

### 3. User-initiated

- [`AnalysisController::trigger()`](../../app/Http/Controllers/Api/AnalysisController.php#L26) — the
  per-block "Reread". Gated in a fixed order: ownership, an open recap window, cooldown, demo,
  backfill age, paused generation, then chain resumption. Each gate is described under
  *What stops a call*.
- [`RunQuestionController::store()`](../../app/Http/Controllers/Api/RunQuestionController.php#L53) —
  the scoped run Q&A. **The one AI surface that is not an `Analysis` row**: one run holds many
  questions, which `(subject, type, discriminator)` cannot key, so it persists `RunQuestion` rows
  instead. It still goes through `StructuredChatCaller`, so persona, budget, retries and metering are
  unchanged. See [[scoped-run-qa-not-an-analysis-row]].
- **`PlanController::regenerate`** — the Plan page's own regenerate button runs the *same*
  `requestForCurrentWeek()` as the Monday command, so a user can trigger a full week of plan
  narration by hand. It is limited by its own 3600s cooldown inside `PlanNarrationRequester`, not by
  the per-block cooldown every other trigger uses.

### 4. Recovery

[`SelfHealer::run()`](../../app/Services/AI/SelfHealer.php#L59), hourly: reverts rows stuck in
flight, then resumes the earliest stalled link per user per family. **Every dispatch is
`invalidate: false`**, so recovery never re-bills content that already exists, and demo users are
excluded from every sweep. Failed rows are bounded by
[`MAX_SELF_HEAL_ATTEMPTS`](../../app/Models/AI/Analysis.php#L62) and then dead-letter to
`/devtools/ai-usage` for a manual re-arm, which is itself a recovery-origin dispatch. See
[[bounded-self-heal-and-dead-letter]].

## The twelve surfaces

Eleven [`AnalysisType`](../../app/Services/AI/AnalysisType.php) cases plus the scoped run Q&A, which
is not an Analysis row. Every case is dispatched by at least one origin above, and every case is
rendered somewhere a user can see — both directions matter, and only one of them used to be checked.

| type | narrator | subject · discriminator | origin | renders |
|---|---|---|---|---|
| `briefing_mascot_voice` | `BriefingMascotVoiceNarrator` | synthetic user+day · `Y-m-d` | scheduled + ingest | `TodaySession` on Home |
| `post_run_speech` | `PostRunSpeechNarrator` | `Activity` · none | ingest (grouped) | `RunLenses`, top of "What Temari says" |
| `run_insight` | `RunInsightNarrator` | `Activity` · none | ingest (grouped) | `RunLenses`, "What stood out" claims |
| `card_flavor` | `CardFlavorNarrator` | `RunCard` · none | ingest | the line burned into the share card, `ShareCardModal` |
| `weekly_recap` | `WeeklyRecapNarrator` | `WeeklySnapshot` · none | staged at ingest, narrated Mon | `WeekSection` and `CalendarWeekRow` |
| `monthly_recap` | `MonthlyRecapNarrator` | synthetic user+month · `Y-m` | staged at ingest, narrated 1st | calendar month card |
| `profile_voice` | `ProfileVoiceNarrator` | synthetic user · ISO week | scheduled + ingest | `ProfileHero` |
| `trend_read` | `TrendReadNarrator` | synthetic user+range · range | scheduled ×3 | `NarrationCard` on Trends |
| `plan_day_voice` | `PlanDayVoiceNarrator` | synthetic user+day · `Y-m-d` | `plan:regenerate`, Plan page | `WeekDayRow`, collapsed |
| `plan_week_voice` | `PlanWeekVoiceNarrator` | `PlanAdaptation` · none | `plan:regenerate`, Plan page | `SeasonWeekRow`, collapsed |
| `plan_season_voice` | `PlanSeasonVoiceNarrator` | `Season` · none | `plan:regenerate`, Plan page | `SeasonHeaderCard`, always visible |
| *(not an Analysis row)* | `RunQuestionNarrator` | `RunQuestion` rows per activity | user | `AskAboutRun` on the run page |

## What stops a call

Seven mechanisms, and they do **not** behave alike. The first three are interchangeable; the fourth
is the one people misremember.

| stop | row ends up | costs | self-heal resumes it |
|---|---|---|---|
| `AiEnabled` off | `Pending` | no | yes |
| Azure unconfigured | `Pending` (dispatch skipped) | no | yes |
| config circuit breaker | `Pending` | no | yes |
| **daily cost ceiling** | **`Done`, rule-based** | no | **no — clears on the clock** |

All three pauses resolve through
[`blockingReason()`](../../app/Services/AI/AnalysisService.php#L647), and an in-flight job reverts
its rows via [`haltForPausedGeneration()`](../../app/Jobs/AI/AnalyzeBaseJob.php#L193) without burning
an attempt.

**The cost ceiling is the exception in three ways.** It does not pause: a `pending` row is filled
from the rule-based filler and marked `Done` by
[`degradeToRuleBased()`](../../app/Services/AI/AnalysisService.php#L701), so a capped day is not a
day of empty blocks. A `Failed` row is explicitly excluded and stays failed, keeping its dead-letter
visibility. And a *manual* trigger past the ceiling is refused with a 409 rather than degraded,
because [`generationPaused()`](../../app/Services/AI/AnalysisService.php#L612) asks with the budget
included while auto-dispatch asks without it. See [[cost-ceiling-degrades-to-rule-based]] and
[[cost-ceiling-answers-run-questions-rule-based]].

Three more limits:

- **Demo exclusion.** [`notDemo()`](../../app/Models/User.php#L85) filters the AI kickoff commands
  and every `SelfHealer` sweep, and
  [`shouldServeRuleBased()`](../../app/Services/AI/AnalysisService.php#L563) serves a demo user's
  manual trigger from the filler *before* any pause check — so the public demo spends nothing while
  still feeling live. See [[demo-triggers-served-rule-based]].
- **The backfill age gate**, [84 days](../../config/ai.php#L43). The only limit that gates automatic
  dispatch *and* manual triggers: [`isTooOld()`](../../app/Services/AI/BackfillAgeGate.php#L36) for
  the ingest fan-out, [`blocksManualTrigger()`](../../app/Services/AI/BackfillAgeGate.php#L51) for
  the button. It is exhaustive per type — chained and recap types are exempt, because they resume a
  chain rather than narrate old material. See [[twelve-week-narration-cutoff]].
- **Cooldown and idempotency are two different defences for the same goal.** The
  [900s cooldown](../../app/Support/Cooldown.php#L32) stops a human clicking twice, at the
  controller, before a job exists. The `Done` check at the top of
  [`AnalyzeRowJob::handle()`](../../app/Jobs/AI/AnalyzeRowJob.php#L23) stops a UI trigger and a
  Horizon retry racing into a double bill. Plan narration adds a third, separate 3600s cooldown.

Three further ceilings bound a call rather than stopping it: a per-user trigger rate limit of 8/min,
a run-question limit of 4/min, and a per-run agent budget of 8 steps / 30k tokens — all in
[config/ai.php](../../config/ai.php).

## Cost shape

**Every narrator is a tool-calling agent run.** There is no one-shot structured call anywhere in the
app: each block is a multi-turn loop, and each turn re-sends the whole prefix. That single fact
drives everything below.

| narrator | tools | max steps | max output | temp | deployment key |
|---|---|---|---|---|---|
| `RunInsightNarrator` | 10 | default (8) | 3000 | 0.7 | `run_insight` |
| `RunQuestionNarrator` | up to 10 | default (8) | 1200 | 0.7 | `run_question` |
| `CardFlavorNarrator` | up to 6 | default (8) | 400 | 0.8 | `card_flavor` |
| `PostRunSpeechNarrator` | 6 | default (8) | 1500 | 0.8 | `post_run_speech` |
| `BriefingMascotVoiceNarrator` | 5 | default (8) | 1800 | 0.8 | `briefing_mascot_voice` |
| `ProfileVoiceNarrator` | 4 | default (8) | 1800 | 0.75 | `profile_voice` |
| `WeeklyRecapNarrator` | 1 | **4** | 1500 | 0.7 | `weekly_recap` |
| `MonthlyRecapNarrator` | 1 | **4** | 1500 | 0.7 | `monthly_recap` |
| `TrendReadNarrator` | 1 | **4** | 1200 | 0.7 | `trend_read` |
| `PlanDayVoiceNarrator` | 1 | **4** | 300 | 0.7 | `plan_day_voice` |
| `PlanWeekVoiceNarrator` | 1 | **4** | 400 | 0.7 | `plan_week_voice` |
| `PlanSeasonVoiceNarrator` | 1 | **4** | 400 | 0.7 | `plan_season_voice` |

Every kind has its own `azure_openai.narrators.*` override key, each defaulting to
`AZURE_OPENAI_DEPLOYMENT`. Routing is env-only; no code decides which model a narrator gets.

**Read the step budget as a ceiling, not as consumption.** A one-tool narrator spends about two
turns whatever its budget says, so a tighter number bounds a runaway loop rather than lowering a
normal bill. What actually drives spend is how often a surface is dispatched and whether it
re-bills, which is why the ranking below is led by cadence and not by toolbox size. A budget is only
worth declaring where it both covers `2 * (tools + 1)` — one full read pass plus the content-filter
retry's replay — and lands below the global default; `NarratorsCoverageTest` enforces exactly that.

**The persona is the largest single block of input.**
[`TemariPersona::systemPrompt()`](../../app/Services/AI/TemariPersona.php#L238) is 15,975 characters,
roughly **4,000 tokens**, and `StructuredChatCaller` prepends it to *every* turn of *every* run. What
makes that affordable is the cache: `prompt_cache_key` is set to the narrator's `kind`, never the
user, so the persona plus that narrator's prompt and tool schemas form one prefix shared by every
caller of that kind. Measured on prod before the prices were filled in, 43-62% of input on multi-step
calls already arrived cached, billed at a tenth of the input rate. Keying the cache per user would
shard that prefix across the user base and make the hit rate worse — see the comment on
`prompt_cache_key` before changing it.

## The agent toolbox

Every tool takes **no arguments**. Each is bound to its subject at PHP construction time — an
activity, a user, or a specific model row — so no phrasing of a question or a narration can redirect
a tool at someone else's data. There is no central registry: each narrator assembles its own list
inline in its `toolbox()` method.

### Bound to one run (`ActivityTool`)

| tool · `name()` | what it hands the model | who computed it |
|---|---|---|
| `RunSummaryTool` · `get_run_summary` | `started_at_local`, `distance_km`, `moving_time_sec`, `pace_sec_per_km`, `avg_hr`, `max_hr`, `avg_cadence_spm`, `cadence_drop_spm` | `ActivityNarrationContext`, `PaceCalculator`; cadence drop from the stored `stream_summary` |
| `KmSplitsTool` · `get_km_splits` | `per_km` (sampled rows), `omitted_km`, `fastest_km`, `slowest_km`, `finish_partial`, `negative_split`, `pace_consistency` | stored `stream_summary` via `StreamSummary`; label from `PaceConsistency` |
| `LapsTool` · `get_laps` | `lap_count`, `laps`, `fastest_lap`, `slowest_lap`, `rep_count`, `recovery_sec`, `pause_count`, `paused_laps` | `StreamSummary::laps()`, `KmSplitBuilder`, `PaceCalculator`, `IntervalDetector` |
| `HrZonesTool` · `get_hr_zones` | `zone_pct`, `time_in_zone_min`, `trimp`, `hr_drift_bpm`, `intensity_label` | stored `stream_summary` via `StreamSummary`; the label thresholds in-tool |
| `TerrainTool` · `get_terrain` | `elevation_gain_m`, `max_grade_pct`, `gap_pace` | stored detail attributes and `stream_summary` |
| `WeatherTool` · `get_weather` | `weather_temp_c`, `weather_humidity_pct`, `weather_rain`, `weather_rain_source`, `weather_wind_speed_kmh`, `weather_wind_gust_kmh`, `weather_wind_direction_deg` | `ActivityNarrationContext` over the stored weather snapshot |
| `EffortContextTool` · `get_effort_context` | `session_intent`, `relative_effort`, `decoupling_pct` | `SessionIntent`, `RelativeEffort`; decoupling from `stream_summary` |
| `PastYouTool` · `get_past_you` | `past_you`: `days_ago`, `pace_diff_sec`, `time_diff_sec`, `hr_diff_bpm`, `past_km`, `past_date` | `PastYouMatcher` |
| `PersonalRecordsTool` · `get_personal_records` | `personal_records`: list of `{category, value_sec}` | stored `PersonalRecord` rows, written by `PersonalRecords` |

### Bound to a user and an as-of date (`UserTool`)

| tool · `name()` | what it hands the model | who computed it |
|---|---|---|
| `WeekStateTool` · `get_week_state` | `this_week_runs`, `last_week_runs`, `this_week_km`, `last_week_km`, `recovery_hours`, `ran_today`, `days_since_last_run`, `form_status`, `time_bucket`, `consecutive_weeks_active`, `fitness_trend`, `volume_ramp_pct`, `readiness_ceiling`, `build_nudge` | `BriefingContext` over `TrainingLoad`, `RecoveryWindow` and `Readiness` |
| `TrainingLoadTool` · `get_training_load` | `training_load`: `acute_7d`, `chronic_42d`, `form`, `form_status` | `TrainingLoad::summary()` |
| `TrainingPacesTool` · `get_training_paces` | `easy_pace_sec`, `marathon_pace_sec`, `threshold_pace_sec`, `interval_pace_sec` | `VdotEstimator` into `TrainingPaceCalculator` |
| `RecentBaselineTool` · `get_recent_baseline` | `recent_baseline_28d`: rolling pace / HR / decoupling averages | `ResolveRunBaselineAction` |
| `RecentRunsTool` · `get_recent_runs` | `recent_runs`: up to 5 × `{mood, km, intensity, oneline}` | `VerdictNarrator::recent()` |
| `LatestPastYouTool` · `get_latest_past_you` | `past_you`, same shape as `PastYouTool` but for the latest run | `PastYouMatcher` |
| `LifetimeStatsTool` · `get_lifetime_stats` | `name`, `total_runs`, `total_km`, `longest_run_km`, `months_running`, `pr_count`, `unlocked_accessories`, `total_accessories`, `weekly_streak`, `favorite_time`, `strava_connected`, `form_status` | `LifetimeStats`, `WeeklySnapshot::consecutiveWeekStreak()` / `::latestFormStatus()` |
| `PersonaMixTool` · `get_persona_mix` | `lookback_weeks`, `total_runs`, `persona_mix`, `persona_mix_recent`, `persona_mix_earlier`, `form_status` | `MoodMix`, `WeeklySnapshot::latestFormStatus()` |
| `ProgressionSignalTool` · `get_progression_signal` | `progression_signal`: `{label, delta_sec}` | `ProgressionSeriesBuilder` over `PersonalRecord` rows |

### Bound to one specific row (`NoArgumentTool`)

| tool · `name()` | what it hands the model | who computed it |
|---|---|---|
| `WeekTotalsTool` · `get_week_totals` | `week_ending`, `runs`, `distance_km`, `pace_sec_per_km`, `weekly_trimp`, `ctl_42d`, `atl_7d`, `form`, `form_status`, `monotony`, `strain`, `avg_decoupling`, plus the previous week's `prev_runs`, `prev_distance_km`, `prev_pace_sec_per_km` | stored `WeeklySnapshot` rows, written by `WeeklyAggregator`; pace via `PaceCalculator` |
| `MonthTotalsTool` · `get_month_totals` | `month`, `total_runs`, `total_distance_km`, `longest_run_km`, `pr_count`, `weekly_distance_km`, `mood_mix`, `fitness` (`ctl_start`, `ctl_end`, `form_status_end`) | `DistanceFormatter`, `MoodMix`, stored `WeeklySnapshot` rows |
| `TrendRangeTool` · `get_trend_range_totals` | `range`, `current` and `comparison` (`runs`, `distance_km`, `trimp_total`), `ctl_start`, `ctl_end`, `vdot_start`, `vdot_end`, `avg_monotony`, `avg_strain` | `TrainingLoad::ctlTrend()` / `::strainMonotonyTrend()`, `TrendDailySnapshot` |
| `CardIdentityTool` · `get_card_identity` | `rarity`, `rarity_label`, `special_move`, `badges` | stored `RunCard` attributes; labels from `Badge::promptLabelsFor()` |
| `PlanDayTool` · `get_day_plan` | `date`, `session_type`, `phase`, `distance_km`, `skipped` | `TrainingBaseline`, `SegmentGenerator::coreKmFor()` |
| `PlanWeekTool` · `get_week_adaptation` | `week_start`, `reason`, `headline`, `detail`, `deload`, `quality_delta`, `adherence_pct` | stored `PlanAdaptation`, written by `PlanAdapter` |
| `PlanSeasonTool` · `get_season` | `starts_at`, `ends_at`, `is_race_oriented`, `race_name`, `race_date`, `race_distance_m`, `goals` | stored `Season`, `RaceGoal` and `SeasonGoal` rows |

**Which narrator carries which toolbox:**

| narrator | tools |
|---|---|
| `RunInsightNarrator` | `RunSummaryTool`, `KmSplitsTool`, `LapsTool`, `HrZonesTool`, `TerrainTool`, `WeatherTool`, `EffortContextTool`, `TrainingLoadTool`, `RecentBaselineTool`, `TrainingPacesTool` |
| `RunQuestionNarrator` | `RunSummaryTool`, `TrainingLoadTool`, `RecentBaselineTool`, `TrainingPacesTool` always; `KmSplitsTool`, `LapsTool`, `HrZonesTool`, `TerrainTool`, `WeatherTool`, `EffortContextTool` only once the run is `Detailed` |
| `PostRunSpeechNarrator` | `RunSummaryTool`, `TerrainTool`, `WeatherTool`, `PersonalRecordsTool`, `PastYouTool`, `WeekStateTool` |
| `CardFlavorNarrator` | `CardIdentityTool` always; `RunSummaryTool`, `KmSplitsTool`, `WeatherTool`, `EffortContextTool`, `PersonalRecordsTool` when the run has detail |
| `BriefingMascotVoiceNarrator` | `WeekStateTool`, `RecentRunsTool`, `TrainingLoadTool`, `LatestPastYouTool`, `RecentBaselineTool` |
| `ProfileVoiceNarrator` | `LifetimeStatsTool`, `PersonaMixTool`, `TrainingPacesTool`, `ProgressionSignalTool` |
| `WeeklyRecapNarrator` | `WeekTotalsTool` |
| `MonthlyRecapNarrator` | `MonthTotalsTool` |
| `TrendReadNarrator` | `TrendRangeTool` |
| `PlanDayVoiceNarrator` | `PlanDayTool` |
| `PlanWeekVoiceNarrator` | `PlanWeekTool` |
| `PlanSeasonVoiceNarrator` | `PlanSeasonTool` |

Every one of the 25 tools is carried by at least one narrator; none is orphaned.

## The deterministic half

The invariant this app is built on: **the LLM owns voice, rules own every number.** A narrator never
computes; it is handed numbers a deterministic class produced and asked to say something true about
them. That is why plan narration is voice-only ([[plan-periodizer]]) and why the rule-based filler
can stand in for any narrator without the page changing shape.

The classes above are the whole boundary — what a tool returns is exactly what the model is told.
Everything else under `app/Services/Run/Metrics/`, `app/Services/Run/Plan/` and
`app/Services/Gamification/` computes state the model never sees directly.

[`RuleBasedNarrationFiller`](../../app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L39) is the
deterministic twin: an exhaustive `match` with no `default` covering every `AnalysisType`, so a new
case cannot ship without a fallback. The run Q&A has its own, `RuleBasedRunAnswer`. Both are what a
capped day, an unconfigured environment and the public demo actually render, which is why a
screenshot alone can never tell you whether a model produced a given line.

## Verdicts

Three-way, and **proposed, not ruled** — the reasoning is here so the call can be argued with. A
"keep" or "right-size" is recorded in this table; a cut gets a dated ADR in `docs/decisions/`.

| surface | verdict | why |
|---|---|---|
| `briefing_mascot_voice` | earns it | Synthesises five reads into one plain-language morning line. Nothing deterministic produces that. |
| `post_run_speech` | earns it | The persona's reaction to a run is the product. |
| `run_insight` | earns it | The most expensive call in the app — 10 tools, 3000 output tokens — and the most substantive block it produces. Finding the non-obvious thing in a run is exactly what the toolbox is for. Ruled 2026-09-04: narrowing it would flatten the output for a saving nobody has measured. |
| `card_flavor` | earns it | One line capped at 400 output tokens from up to 6 tools, and it cannot tighten its budget while it carries them (`2 * (6 + 1)` already exceeds the default). Ruled 2026-09-04: the extra tools are conditional on the run having detail, so the 6-tool case is rarer than the table implies, and trimming them makes the flavour line generic. |
| `weekly_recap` | earns it | Chained, reads a real snapshot, already tightened to 4 steps. |
| `monthly_recap` | earns it | Same shape, same tightening. |
| `profile_voice` | earns it | Once a week, four reads, genuinely synthetic. |
| `trend_read` | earns it | 1 tool and a 4-step budget. Watch the cadence (×3 ranges) rather than the call. |
| `plan_day_voice` | earns it | Budget aligned to 4, and the weekly sweep now re-bills only the days whose prescribed session actually changed. Still the largest scheduled spend by cadence, but no longer a blanket re-narration. |
| `plan_week_voice` | earns it | Budget aligned to 4, and re-billed only when the adaptation verdict itself changed. |
| `plan_season_voice` | earns it | Budget aligned to 4 and idempotent, so it neither re-bills nor over-runs. |
| run Q&A | earns it | A free-form question about one run is exactly what rules cannot answer. |
| `TemariPersona` | earns it | ~4,000 tokens on every turn, but it *is* the product, and the per-kind prompt cache already serves roughly half of it at a tenth of the rate. The largest available lever, and the last one to reach for. |

**Tool shortlist** — flagged rather than ruled, since only a handful are worth acting on:

- The three plan tools (`PlanDayTool`, `PlanWeekTool`, `PlanSeasonTool`) each return one bound read
  with nothing for the model to decide. Handing the payload straight to the prompt would remove a
  tool round trip per plan block, which is up to nine per user per week.
- `RunInsightNarrator`'s three user-level tools are the ones to question first if its toolbox is
  narrowed.

**Cost ranking**, order only, from a structural proxy (tools × step budget × output cap × calls per
active user per week). It ranks surfaces against each other; it is not a dollar figure:

`run_insight` › `briefing_mascot_voice` › `post_run_speech` › run Q&A › `plan_day_voice` ›
`card_flavor` › `profile_voice` › `trend_read` › `plan_week_voice` › `weekly_recap` ›
`monthly_recap` › `plan_season_voice`

`plan_day_voice` led this list until 2026-09-04, on a weekly ×7 blanket re-narration. With the
fingerprint check it now bills only on the days the periodizer actually moved, so its steady-state
cost is a fraction of its worst case — and its worst case is unchanged.

## Retired surfaces

**`pr_context`** (cut 2026-09-04). Narrated once per beaten personal record on every ingest, resumed
by self-heal, re-staged on activity delete — and **never rendered anywhere**. No controller built a
payload for it and nothing in `resources/js/` read it outside the generated enum and the
`/devtools/ai-usage` admin list. Real spend, no output. The enum case, narrator, job, its
`PersonalRecordTool`, the authorizer arm, the ingest dispatch, the self-heal sweep, the age-gate arm,
the rule-based arm and the routing key are all gone. PR celebration survives: `PostRunSpeechNarrator`
already carries `PersonalRecordsTool`.

No pruning migration was needed.
[`KnownAnalysisTypeScope`](../../app/Models/Scopes/KnownAnalysisTypeScope.php#L26) filters rows whose
type is no longer a live case at the query boundary, so retiring a case cannot crash a read.

The lesson generalises: the old version of this note asserted that no type was orphaned, and it was
right about the direction it checked — every type *was* dispatched. Nothing checked whether every
type was *displayed*. The table above now carries a "renders" column for that reason.

## What it costs

Spend is metered per call into `ai_token_usages` on the separate `analytics` connection, written by
[`RecordTokenUsageAction`](../../app/Actions/AI/RecordTokenUsageAction.php#L21) into
[`TokenUsage`](../../app/Models/AI/TokenUsage.php#L32). A write failure is swallowed and logged, so
metering never fails a call that already succeeded.

**This note deliberately carries no cost table.** `/devtools/ai-usage` renders spend live with kind,
origin and range filters, and a frozen table here would duplicate a working page and then rot. Run
the query instead — the `(created_at, kind)` and `(created_at, origin)` indexes make it cheap:

```sql
SELECT kind,
       origin,
       COUNT(*)               AS calls,
       SUM(total_tokens)      AS tokens,
       SUM(cached_tokens)     AS cached_tokens,
       ROUND(AVG(steps), 1)   AS avg_steps,
       ROUND(AVG(latency_ms)) AS avg_ms
FROM ai_token_usages
WHERE created_at >= NOW() - INTERVAL 30 DAY
GROUP BY kind, origin
ORDER BY tokens DESC;
```

```bash
./vendor/bin/sail artisan tinker --execute 'print_r(DB::connection("analytics")->select("<query>"));'
```

`kind` names the narrator and `origin` names what started it, so the two together answer questions
neither can alone: whether `run_insight` spend is ingest or people pressing "Reread", and whether
recovery is quietly re-billing. Rows written before the origin column defaulted to `unknown`; so does
any dispatch site that forgets to declare itself, which is deliberate — unattributed is visible,
a wrong default is not.

## The prod rebuild

The v2 cutover wipes production and re-syncs from Strava with nothing preserved: re-register,
re-authorise Strava, re-enter the race goal and training preferences. Three consequences for spend,
all of them one-offs worth knowing before the day:

- **Everything older than 84 days comes back rule-based, permanently.** The re-synced history runs
  through `BackfillAgeGate` exactly as a first-time backfill would, so only the last twelve weeks are
  narrated by a model. That is the gate working as designed ([[twelve-week-narration-cutoff]]), not a
  failure to fix on the day.
- **The backfill is staggered, not bursty.** `ai.backfill_stagger_seconds` spaces successive cascades
  6 minutes apart per user, so a large re-sync spreads over hours rather than firehosing Azure.
- **The daily cost ceiling still applies.** If the narratable slice of the re-sync exceeds it, the
  remainder is filled rule-based and marked `Done` rather than left empty — and it will not
  re-narrate itself later.

## See also

- [[ai-pipeline]] — the machinery each dispatch flows through.
- [[ai-narration-internals]] — prompt construction and the rule-based filler.
- [[azure-openai-routing]] — which model each kind routes to.
- [[bounded-self-heal-and-dead-letter]] · [[cost-ceiling-degrades-to-rule-based]] ·
  [[twelve-week-narration-cutoff]] · [[demo-user-billing-exclusion]] ·
  [[scoped-run-qa-not-an-analysis-row]].
