---
title: Supported race time from recent efforts and a personal fall-off
description: The supported VDOT is read at the race distance from whole-run hard efforts of the last 16 weeks, projected with the athlete's own fall-off between distances, floored by recent training, rate-limited on unconfirmed rises, and shared by race prediction and training paces.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-05
code_refs:
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Services/Run/Metrics/FallOffExponent.php
  - app/Actions/Run/Metrics/ResolveHardEffortsAction.php
  - app/Services/Run/Metrics/RunDistanceTimes.php
  - app/Services/Run/Plan/RaceAmbitionAssessor.php
  - app/Services/Run/Plan/RaceAmbition.php
  - app/Console/Commands/Run/FitnessNotifyImprovementCommand.php
  - app/Notifications/FitnessImprovedNotification.php
  - resources/js/lib/raceGoal.ts
---

# Supported race time from recent efforts and a personal fall-off

**Status:** Accepted (2026-10-05). Supersedes the "lowest VDOT across 12 months" rule in [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php) and the frozen provisional anchor. Partly supersedes [[race-ambition-is-shown-and-capacity-is-prescribed]] and [[one-race-model-drives-the-plan]].

## Context

The supported VDOT was the lowest VDOT across every effort of at least 3 km in the last 12 months, and without confirmed evidence a provisional anchor captured once at first connect held it. A hard half marathon from five months earlier outvoted a 5K from five weeks earlier, so one athlete's supported 10K sat about 7% slower than a submaximal 10 km training run they had just done. The same number sets every training pace, so the plan never stepped up. No source supports "lowest across 12 months": it mixes fitness changing over time with how much the athlete slows as distance grows.

## Decision

1. **The level comes from recent whole-run hard efforts.** The pool is efforts of at least 3 km from the last 16 weeks ([LEVEL_WEEKS](app/Services/Run/Metrics/VdotEstimator.php#L75)), newest per ±10% distance band. An effort is confirmed evidence, or an unconfirmed run that set a distance record covering essentially the whole run (within 10% of the record distance) ([ResolveHardEffortsAction](app/Actions/Run/Metrics/ResolveHardEffortsAction.php#L47)). A fast segment inside a longer run never counts, and neither the Strava workout tag nor heart rate classifies a run. **Evidence-supported** for the window ([[coaching-evidence#SmythMunizPumares2020]], [[coaching-evidence#EmigPeltonen2020]]); the qualification rule is a **heuristic**.
2. **The distance is the goal race, or 10K without one** ([DEFAULT_RACE_METERS](app/Services/Run/Metrics/VdotEstimator.php#L79)). Efforts either side of it are log-interpolated, weighted by how close each sits in log distance; otherwise the closest effort sets it ([select](app/Services/Run/Metrics/VdotEstimator.php#L347)). **Consensus**: predictions degrade with extrapolation ([[coaching-evidence#DanielsGilbert1979]], [[coaching-evidence#EmigPeltonen2020]]).
3. **Each projection is the slower of the VDOT equivalence and a power law** with the athlete's own fall-off ([projectedTime](app/Services/Run/Metrics/VdotEstimator.php#L377)), so a personal k only ever makes paces safer. k is fitted on log time over log distance from efforts of the last 12 months run within 8 weeks of each other and at least 1.5× apart, clamped to [1.06, 1.15]; without a valid cluster it is 1.08 up to 10K, 1.10 to the half and 1.15 beyond ([FallOffExponent](app/Services/Run/Metrics/FallOffExponent.php#L55)). **Evidence-supported** ([[coaching-evidence#Riegel1981]], [[coaching-evidence#BlytheKiraly2016]], [[coaching-evidence#VickersVertosick2016]]); the clamp and defaults are **heuristics**.
4. **A training run is a floor.** The supported time is never slower than the fastest run of at least the race distance in the last 16 weeks, scaled to it with k ([trainingFloor](app/Services/Run/Metrics/VdotEstimator.php#L392)). It never creates an estimate on its own. **Evidence-supported** that training pace and distance are a valid input ([[coaching-evidence#SmythMunizPumares2020]], [[coaching-evidence#Hunter2023]]); heart rate is never turned into a time ([[coaching-evidence#MolinaGarcia2022]]).
5. **One number.** Race prediction, easy and marathon paces share the supported VDOT. `quality_vdot` stays the larger of it and the recent short anchor (3 months, at most 10K, at least 3 km sustained), read from confirmed evidence when the athlete has any and from distance records otherwise.
6. **Unconfirmed rises are rate-limited on read.** The estimator replays every effort date and floor-run date since the [FitnessAnchor](app/Models/FitnessAnchor.php)'s capture ([riseCapped](app/Services/Run/Metrics/VdotEstimator.php#L226)): a rise resting on unconfirmed records lifts by at most 1.0 VDOT on its first day and 1.0 more per started week after ([RISE_CAP_VDOT_PER_WEEK](app/Services/Run/Metrics/VdotEstimator.php#L81)); a rise resting only on confirmed efforts, and every drop, applies at once. The baseline is the model as of the capture date, so the method change itself applies at once. The anchor's capture time is the only field read; it is never recaptured. `estimate($user, $asOf)` gives the same answer for any past day whenever it is asked. **Heuristic** ([[coaching-evidence#Coyle1984]] bounds detraining, not improvement).
7. **Stale.** With nothing in 16 weeks, the newest older effort sets it, labelled `stale`. The `low_evidence` rule is unchanged and reads the longest effort the time rests on.
8. **What the athlete sees.** The Race page and Trends name the effort under the supported time, "based on your 5K on aug 26" ([supportedBasisLine](resources/js/lib/raceGoal.ts#L206)). The athlete is never asked to vouch for a run. A daily check ([FitnessNotifyImprovementCommand](app/Console/Commands/Run/FitnessNotifyImprovementCommand.php#L28)) writes an inbox entry and a push when the supported VDOT has risen at least 0.5 above the athlete's last noted VDOT, at most once a week; its first run records each baseline silently, the demo user is excluded, and quiet hours hold it like any other notification ([[quiet-hours-hold-every-notification]]). **Product choice.**

## Consequences

- The worked case: a recent 5K beside a cluster of older long races reads about 59 minutes for 10K at k ≈ 1.08, not 1:06:48. Paces step up with it.
- For a longer race than the evidence, paces get slower whenever the athlete's fall-off exceeds Daniels'.
- A GPS error that looks like a record lifts the VDOT by at most one point a week, and its removal drops it at once. A floor-driven rise is capped the same way.
- An unconfirmed source is always a record, so a drop comes from a confirmed effort, a newer record at a distance closer to the race, or the effort ageing out into `stale`. Time trials (#1808) supply confirmed efforts.
- A record whose run is gone no longer supports anything, since qualification reads the run itself.
- The 16-week window, the clamp, the default k, the 1 VDOT a week cap and the 0.5 VDOT note threshold are named constants, adjustable later.
- After deploy, `plan:regenerate` and `plan:regrade-season` re-plan and re-judge with the new numbers, each run with the owner's approval.

## Considered and rejected

- **The Strava Race tag as a source.** Rejected by the owner: a tag edited after ingest never reaches the app, because Strava's update webhook covers only title, type and privacy, and the poll skips runs already analysed. The tag would describe some races and silently miss the rest.
- **A nudge asking the athlete to confirm an effort.** Rejected by the owner: the engine never asks the athlete to vouch for a run. It decides from records, the weekly rise cap and staleness, and the planned time trials supply confirmed efforts.
- **A detraining-plausibility guard on unconfirmed efforts.** Not added: an unconfirmed effort is always a record, which cannot itself imply a fall-off to filter out.

## See also

- [[coaching-evidence]], [[race-projection]], [[profile]], [[notification-inbox]]
