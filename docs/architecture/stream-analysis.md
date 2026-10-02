---
title: Stream analysis (stream_summary)
description: How raw Strava streams become the stream_summary payload — zones, splits, decoupling, cadence, best-effort paces — and who reads it
tags: [architecture, run]
status: living
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Ingest/StreamAnalysis.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Services/Run/Metrics/SummaryRecomputer.php
  - app/Models/ActivityDetail.php
  - app/Models/User.php
  - resources/js/types/inertia.ts
  - resources/js/lib/runcard.ts
---

# Stream analysis (stream_summary)

Strava ships a run as raw per-sample streams (time, distance, heartrate, cadence, velocity, altitude, latlng). [StreamAnalysis](app/Services/Run/Ingest/StreamAnalysis.php) reduces those arrays into a single derived blob — `stream_summary` — that every downstream metric, card, and narrator reads instead of re-walking the streams. It is the analytical heart of the [[run-ingest-pipeline]].

## When it runs

The [[run-ingest-pipeline]] fetches streams and calls [compute](app/Services/Run/Ingest/StreamAnalysis.php#L32) inside [computeAndStoreSummary](app/Services/Run/Ingest/ActivityPipeline.php#L417). The result is persisted on the run's [ActivityDetail](app/Models/ActivityDetail.php) (JSON-cast `stream_summary` column, see [casts](app/Models/ActivityDetail.php#L140)); a `null` blob means "no usable streams" (treadmill / manual run). The same step also derives Edwards TRIMP from the zone minutes and stores it alongside ([store](app/Services/Run/Ingest/ActivityPipeline.php#L443)). See [[training-load-metrics]].

Recompute is Strava-free: [recomputeSummary](app/Services/Run/Ingest/ActivityPipeline.php) re-runs the analysis over already-stored streams with the user's *current* zones. A single-run "Reread" reaches it through [SummaryRecomputer](app/Services/Run/Metrics/SummaryRecomputer.php). A profile or synced-zone change instead dispatches the unique per-user [RecalibrateTrainingHistoryJob](app/Jobs/Run/RecalibrateTrainingHistoryJob.php), whose [PlanRecalibrationService](app/Services/Run/Plan/PlanRecalibrationService.php) recomputes every stored stream summary, rebuilds aggregates once and regenerates future sessions, keeping past prescriptions and grades. No Strava read or LLM call occurs in that path; the demo account is excluded and is refreshed by `demo:seed`. See [[a-one-time-reset-rebuilds-history-before-launch]].

## How HR zones come in

`compute` takes the runner's zone table and optimal cadence as arguments — it never reads config itself. The pipeline pulls them from [User::hrProfile](app/Models/User.php#L172), which returns the stored `runner_profiles` row when present and falls back to `config('runner.*')` in the identical shape ([fallback](app/Models/User.php#L186)). So a run's zone breakdown reflects whatever the runner configured in [[settings-hr-zones]] at compute time. Each zone is an inclusive-low / exclusive-high bpm band; [timeInZones](app/Services/Run/Ingest/StreamAnalysis.php#L426) classifies each sample into the first band it falls in and time-weights it by the gap to the next timestamp.

## What it derives

Each derivation is independent and best-effort — a missing or too-short stream simply omits its keys rather than failing the blob ([assembly](app/Services/Run/Ingest/StreamAnalysis.php#L41)):

- **Time-in-zone** — minutes and percent of moving time per HR zone, time-weighted ([timeInZones](app/Services/Run/Ingest/StreamAnalysis.php#L426)).
- **Best-effort paces** — fastest pace sustained over a fixed set of windows (30s … 60min), via a two-pointer sliding window that trims the trailing overshoot ([bestEffortPace](app/Services/Run/Ingest/StreamAnalysis.php#L204)).
- **Legacy whole-run aerobic decoupling** — how much the HR/pace ratio drifts up in the run's second half vs its first, ignoring stopped samples ([decoupling](app/Services/Run/Ingest/StreamAnalysis.php)). Only emitted above a **45-minute moving-time floor** ([isSustainedEffort](app/Services/Run/Ingest/StreamAnalysis.php)). The whole-run fields remain on new summaries and are frozen for anything reading old rows, but the UI no longer displays them — see version 2 below.
  - **Both halves are time-weighted, and the halves are split at the run's time midpoint.** Each half's pace is its moving seconds over its flat-equivalent distance, the same shape `gradeAdjustedPace` computes, and its HR is likewise weighted by the seconds each sample stands for. Averaging per-sample s/km let one crawling sample just above the stop threshold count for the same as a running one while standing for two metres, and splitting on the middle *index* cut the halves unevenly in time whenever the watch changed sample spacing mid-run.
  - **A sample whose heart rate is outside 30–220 bpm is skipped** (`HR_PLAUSIBLE_MIN_BPM` / `HR_PLAUSIBLE_MAX_BPM`), the way `timeInZones` skips a sample belonging to no zone. A strap dropout ships as 0 bpm; averaged in, a stretch of them in one half drags that half's mean HR toward zero and the ratio reports the gap in the data as drift. The band is fixed physiology rather than the runner's own zone table, whose Z1 floor would discard genuine easy-running samples.
  - **A figure past ±40% is withheld entirely** (`DECOUPLING_IMPLAUSIBLE_PCT`). Real drift is single digits, and the teens on a long run that came apart; past that the reading describes broken inputs that the per-sample guards did not catch.
  - **Pace is grade-adjusted first**, through the same Minetti cost factor GAP uses. Against raw pace the metric could not separate fatigue from terrain: a downhill finish carries the athlete faster for free and reads as a ~31% decoupling — "you fell apart" — when the effort never changed, while a climb flatters the number the other way. The grade stream is already fetched for `gap_pace`, so the correction costs nothing. Because the correction is part of what the number *is*, the gradient is one of the streams the reading is made of: [driftSampleCount](app/Services/Run/Ingest/StreamAnalysis.php) reads only as far as every stream covers, so a run with no grade trace publishes nothing and a run whose trace stops early is read up to that point rather than having its tail scored as flat ground.
- **Neither legacy value is published past a sustained 15% grade** (`TERRAIN_UNREADABLE_GRADE_PCT`, read off the `max_grade_pct` already computed). The Minetti curve is fitted for ordinary running gradients, so beyond that the corrected figure is not trustworthy. Legacy `hr_drift_bpm` is first-km against last-km average HR.
- **Version 2 steady-effort drift** — `drift_metric_version: 2`, `steady_effort_decoupling_pct` and `steady_effort_hr_drift_bpm` are computed from full-kilometre splits. It drops the larger of the first full kilometre or the first 10% of moving time, breaks a segment at an adjacent grade-adjusted pace gap of 45 sec/km or more, and compares heart rate per unit of grade-adjusted speed across the two halves of the longest remaining segment with complete plausible HR and elevation data, so a pace fade at a held HR reads as positive drift just like an HR rise at a held pace. A segment needs at least 20 minutes; if none qualifies, both readings are `null`. Split grades use `elevation_difference` for the same Minetti adjustment, and average grades past 15% are withheld. New narration, baselines and weekly rollups read only these version 2 values. Plan adaptation does not read decoupling: it describes durability, never a session run too hard ([[decoupling-describes-a-run-and-a-deletion-re-grades-its-day]]). Versionless summaries and `avg_decoupling` stay as historical data; no history backfill or automatic re-narration runs.
- **Cadence distribution** — share of time below / within / above a step-rate band, plus the share inside the runner's optimal window; the stream is single-foot rpm so it is doubled to SPM ([cadenceDistribution](app/Services/Run/Ingest/StreamAnalysis.php#L848)).
- **Elevation** — descent only, from the altitude stream ([elevation](app/Services/Run/Ingest/StreamAnalysis.php#L156)). Ascent is **not** recomputed: Strava's own `total_elevation_gain` is the single canonical figure, since it is what the UI shows and what narration quotes. The two disagreed by more than 5 m on 40% of real runs.
- **Stopped time** — time and count below the stop-velocity threshold ([stoppedTime](app/Services/Run/Ingest/StreamAnalysis.php#L736)).

When Strava's `splits_metric` is present, four more derivations attach ([splits block](app/Services/Run/Ingest/StreamAnalysis.php#L49)): **HR drift** and **cadence drop** from first to last full km ([hrDriftFromSplits](app/Services/Run/Ingest/StreamAnalysis.php#L1019), [cadenceDropFromSplits](app/Services/Run/Ingest/StreamAnalysis.php#L1042)), a **negative-split** flag, and **pace variability**.

The **per-km table** no longer comes from `splits_metric` at all. That payload's `moving_time` carries Strava's own auto-pause heuristic, and its `distance` stream is a smoothed curve that drifts off raw GPS mid-run, so splits read from the pair disagreed with the watch by up to 86 s/km. [KmSplitBuilder](app/Services/Run/Ingest/KmSplitBuilder.php) derives the rows on elapsed time instead, from the first of three sources that yields any: the **laps** when they already form a ~1 km grid (the watch's own numbers, exact), else **cumulative haversine over `latlng`** scaled to the device's total distance and interpolated at each km boundary (within ~2 s), else `splits_metric` read on `elapsed_time` — the last resort for a run with no GPS trace at all. The lap list is also emitted verbatim as `laps[]`, one row per lap at whatever length it was: nothing in that path assumes 1 km, since the auto-split length is the watch's setting, not ours.

**Pace variability** is the spread of the *per-km split* paces ([paceVariability](app/Services/Run/Ingest/StreamAnalysis.php#L661)), needing two full kilometres. It is deliberately not the spread of the instantaneous velocity stream: that samples every GPS wobble and traffic light, so on real runs it landed an order of magnitude above the thresholds reading it, and every run in the corpus scored as ragged. The bands live in [PaceConsistency](app/Services/Run/Metrics/PaceConsistency.php#L16), which is also the only thing narration sees — the raw figure is never handed to the model, because it quoted it verbatim at users.

The **negative-split** margin ([negativeSplit](app/Services/Run/Ingest/StreamAnalysis.php#L1062)) is fitted rather than assumed: the original 1.5% fired on a third of all runs, so it was raised until finishing strong reads as deliberate rather than as the default outcome.

Strava omits cadence from `splits_metric`, so per-km cadence is back-filled by bucketing the cadence stream over cumulative distance ([perKmCadenceFromStream](app/Services/Run/Ingest/StreamAnalysis.php#L947)) and decorating the rows ([attach](app/Services/Run/Ingest/StreamAnalysis.php#L990)).

## Output shape (the contract)

Downstream consumers key into specific fields, so the shape is a contract. The producer ([compute](app/Services/Run/Ingest/StreamAnalysis.php#L32)) is the source of truth; the TypeScript mirror lives in [inertia.ts](resources/js/types/inertia.ts#L175) and declares the fields consumed by the current UI. It carries no index signature, so a page reading an undeclared key is a type error rather than an `unknown`. The notable producer keys:

- `time_in_zone_min` / `time_in_zone_pct` — minutes and percent per zone (keyed `Z1..Z5`).
- `per_km[]` — rows of `{ km, pace, elapsed_sec, distance_m, avg_hr?, avg_cadence_spm? }` ([type](resources/js/types/inertia.ts#L144)).
- `laps[]` — rows of `{ lap, distance_m, elapsed_sec, pace, avg_hr?, avg_cadence_spm? }`, absent when the activity was never lapped.
- `partial_split` — the trailing sub-km leftover as `{ distance_m, pace, avg_hr?, avg_cadence_spm? }`, absent when the run finished on a whole kilometre. It carries no `elapsed_sec`: it is still derived from `splits_metric.moving_time` ([partialSplit](app/Services/Run/Ingest/StreamAnalysis.php#L903)), the one fragment `KmSplitBuilder` does not yet own.
- `best_{window}_pace` — e.g. `best_60min_pace`, as `"M:SS"` strings.
- `decoupling_pct`, `hr_drift_bpm` — legacy whole-run and first-to-last split readings; frozen, computed for historical rows but no longer read by the UI.
- `drift_metric_version`, `steady_effort_decoupling_pct`, `steady_effort_hr_drift_bpm` — version 2 readings; the two values are explicitly `null` when the required evidence is insufficient.
- `cadence_distribution_pct`, `optimal_cadence_pct`, `pace_variability_sec` (absent below two full km), `stopped_time_sec`, `stop_count`, `descent_m`. Ascent comes from `ActivityDetail::$total_elevation_gain`, not from this blob.

Read through [StreamSummary](app/Services/Run/Metrics/StreamSummary.php), a typed read model with one named accessor per key. The producer omits a key entirely whenever the stream behind it is missing or too short, so every row carries a subset of the shape and rows written by older revisions carry fewer keys still — each accessor therefore reports an absent key, an explicit `null` and an unusable type alike as "no reading" ([fromArray](app/Services/Run/Metrics/StreamSummary.php#L31)). Where a reading of zero has to stay distinct from no reading at all, [hasDecouplingPct](app/Services/Run/Metrics/StreamSummary.php#L171) answers presence on its own. Version 2 accessors such as [steadyEffortDecouplingPct](app/Services/Run/Metrics/StreamSummary.php#L181) require `drift_metric_version === 2`; absent, null or unusable values all mean no verdict. The raw array underneath is still reachable via the [streamSummary](app/Models/ActivityDetail.php#L177) accessor (null-safe to `[]`).

## Who consumes it

- **Training metrics** — `EstimateThresholdAction` mines best-effort paces and zone percent across recent runs ([query](app/Actions/Run/Metrics/EstimateThresholdAction.php#L23)); new `RunBaseline`, `Vibe`, weekly narration and plan-adaptation decisions use only version 2 drift. History chips retain the legacy weekly average. See [[training-load-metrics]].
- **Run detail UI** — the [[run-detail]] page reads the blob directly ([Show.tsx](resources/js/pages/Runs/Show.tsx#L94)); helpers in [runcard.ts](resources/js/lib/runcard.ts#L168) derive the pace-shape glyph, mean cadence, fastest km, and zone bar from `per_km` / `time_in_zone_pct`.
- **Narration** — the narrators read the blob through their tools; the demo stand-in frames the same cadence / decoupling / HR story from it ([RuleBasedRunInsights](app/Services/AI/RuleBased/RuleBasedRunInsights.php#L57)) for the [[ai-pipeline]].
- **Personal records** — distance PRs slide their window over `per_km` on `elapsed_sec`, then the `partial_split` leftover, so a 42.6 km run can still reach the 42 195 m marathon target ([splitRows](app/Services/Run/Metrics/PersonalRecords.php#L249)). See [[records]].

See [[data-model]] for where `ActivityDetail` sits relative to `Activity` and `ActivityStream`.
