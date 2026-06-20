---
title: Stream analysis (stream_summary)
description: How raw Strava streams become the stream_summary payload — zones, splits, decoupling, cadence, best-effort paces — and who reads it
tags: [architecture, run]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/Run/Ingest/StreamAnalysis.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Models/ActivityDetail.php
  - app/Models/User.php
  - resources/js/types/inertia.ts
  - resources/js/lib/runcard.ts
---

# Stream analysis (stream_summary)

Strava ships a run as raw per-sample streams (time, distance, heartrate, cadence, velocity, altitude, latlng). [StreamAnalysis](app/Services/Run/Ingest/StreamAnalysis.php) reduces those arrays into a single derived blob — `stream_summary` — that every downstream metric, card, and narrator reads instead of re-walking the streams. It is the analytical heart of the [[run-ingest-pipeline]].

## When it runs

The [[run-ingest-pipeline]] fetches streams and calls [compute](app/Services/Run/Ingest/StreamAnalysis.php#L32) inside [computeAndStoreSummary](app/Services/Run/Ingest/ActivityPipeline.php#L237). The result is persisted on the run's [ActivityDetail](app/Models/ActivityDetail.php) (JSON-cast `stream_summary` column, see [casts](app/Models/ActivityDetail.php#L140)); a `null` blob means "no usable streams" (treadmill / manual run). The same step also derives Edwards TRIMP from the zone minutes and stores it alongside ([store](app/Services/Run/Ingest/ActivityPipeline.php#L257)). See [[training-load-metrics]].

Recompute is forward-only and Strava-free: [recomputeSummary](app/Services/Run/Ingest/ActivityPipeline.php#L274) re-runs the analysis over the already-stored streams with the user's *current* zones, so a zones change (or a "Baca ulang") refreshes the blob without re-ingesting.

## How HR zones come in

`compute` takes the runner's zone table and optimal cadence as arguments — it never reads config itself. The pipeline pulls them from [User::hrProfile](app/Models/User.php#L97), which returns the stored `runner_profiles` row when present and falls back to `config('runner.*')` in the identical shape ([fallback](app/Models/User.php#L110)). So a run's zone breakdown reflects whatever the runner configured in [[settings-hr-zones]] at compute time. Each zone is an inclusive-low / exclusive-high bpm band; [timeInZones](app/Services/Run/Ingest/StreamAnalysis.php#L182) classifies each sample into the first band it falls in and time-weights it by the gap to the next timestamp.

## What it derives

Each derivation is independent and best-effort — a missing or too-short stream simply omits its keys rather than failing the blob ([assembly](app/Services/Run/Ingest/StreamAnalysis.php#L41)):

- **Time-in-zone** — minutes and percent of moving time per HR zone, time-weighted ([timeInZones](app/Services/Run/Ingest/StreamAnalysis.php#L182)).
- **Best-effort paces** — fastest pace sustained over a fixed set of windows (30s … 60min), via a two-pointer sliding window that trims the trailing overshoot ([bestEffortPace](app/Services/Run/Ingest/StreamAnalysis.php#L110)).
- **Aerobic decoupling** — how much the HR/pace ratio drifts up in the run's second half vs its first, ignoring stopped samples ([decoupling](app/Services/Run/Ingest/StreamAnalysis.php#L275)).
- **Cadence distribution** — share of time below / within / above a step-rate band, plus the share inside the runner's optimal window; the stream is single-foot rpm so it is doubled to SPM ([cadenceDistribution](app/Services/Run/Ingest/StreamAnalysis.php#L321)).
- **Elevation** — total ascent / descent from the altitude stream ([elevation](app/Services/Run/Ingest/StreamAnalysis.php#L156)).
- **Pace variability** and **stopped time** — pace spread and time/count below the stop-velocity threshold ([paceVariability](app/Services/Run/Ingest/StreamAnalysis.php#L219), [stoppedTime](app/Services/Run/Ingest/StreamAnalysis.php#L241)).

When Strava's `splits_metric` is present, four more derivations attach ([splits block](app/Services/Run/Ingest/StreamAnalysis.php#L49)): a **per-km table** (pace, avg HR, avg cadence) ([perKm](app/Services/Run/Ingest/StreamAnalysis.php#L365)), **HR drift** and **cadence drop** from first to last full km ([hrDrift](app/Services/Run/Ingest/StreamAnalysis.php#L472), [cadenceDrop](app/Services/Run/Ingest/StreamAnalysis.php#L491)), and a **negative-split** flag requiring a real margin so a flat run can't coin-flip into it ([negativeSplit](app/Services/Run/Ingest/StreamAnalysis.php#L511)). Strava omits cadence from `splits_metric`, so per-km cadence is back-filled by bucketing the cadence stream over cumulative distance ([perKmCadenceFromStream](app/Services/Run/Ingest/StreamAnalysis.php#L405)) and decorating the rows ([attach](app/Services/Run/Ingest/StreamAnalysis.php#L448)).

## Output shape (the contract)

Downstream consumers key into specific fields, so the shape is a contract. The producer ([compute](app/Services/Run/Ingest/StreamAnalysis.php#L32)) is the source of truth; the TypeScript mirror lives in [inertia.ts](resources/js/types/inertia.ts#L171). The notable keys:

- `time_in_zone_min` / `time_in_zone_pct` — minutes and percent per zone (keyed `Z1..Z5`).
- `per_km[]` — rows of `{ km, pace, avg_hr?, avg_cadence_spm? }` ([type](resources/js/types/inertia.ts#L161)).
- `best_{window}_pace` — e.g. `best_60min_pace`, as `"M:SS"` strings.
- `decoupling_pct`, `hr_drift_bpm`, `cadence_drop_spm`, `negative_split` (bool).
- `cadence_distribution_pct`, `optimal_cadence_pct`, `pace_variability_sec`, `stopped_time_sec`, `stop_count`, `ascent_m`, `descent_m`.

Read via the [streamSummary](app/Models/ActivityDetail.php#L115) accessor (null-safe to `[]`).

## Who consumes it

- **Training metrics** — `ThresholdEstimator` mines best-effort paces and zone percent across recent runs ([query](app/Services/Run/Metrics/ThresholdEstimator.php#L29)); `RunBaseline` and the `Vibe` form score average `decoupling_pct` ([Vibe](app/Services/Run/Story/Vibe.php#L132)). See [[training-load-metrics]].
- **Run detail UI** — the [[run-detail]] page reads the blob directly ([Show.tsx](resources/js/pages/Runs/Show.tsx#L94)); helpers in [runcard.ts](resources/js/lib/runcard.ts#L168) derive the pace-shape glyph, mean cadence, fastest km, and zone bar from `per_km` / `time_in_zone_pct`.
- **Narration** — the rule-based filler frames a run's real cadence / decoupling / HR story from the blob ([filler](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L139)) for the [[ai-pipeline]].

See [[data-model]] for where `ActivityDetail` sits relative to `Activity` and `ActivityStream`.
