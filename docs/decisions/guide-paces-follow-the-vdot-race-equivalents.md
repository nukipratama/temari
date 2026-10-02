---
title: Guide paces follow the VDOT race equivalents
description: Each guide pace is read off the same VDOT race-time model that fits the athlete's races — marathon at the marathon race equivalent, threshold at a one-hour effort, interval at an eleven-minute effort, easy at the midpoint of the VDOT calculator's E band — instead of fixed VO2 fractions that ran about one band fast.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-01
code_refs:
  - app/Services/Run/Metrics/TrainingPaceCalculator.php
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
---

# Guide paces follow the VDOT race equivalents

**Status:** Accepted (2026-10-01)

## Context

[TrainingPaceCalculator](app/Services/Run/Metrics/TrainingPaceCalculator.php) set every guide pace at a fixed fraction of VDOT on the VO2 curve (easy 0.72–0.80, marathon 0.86, threshold 0.95, interval 1.03) and claimed calibration against Daniels' tables. Executed against the app's own race model, at VDOT 50 its "marathon" pace was half-marathon race pace and its "threshold" was about 21-minute race pace, faster than 5K pace. So a Peak threshold block of 35 minutes asked for more than the athlete could race at that pace, the marathon-pace cap (`max(goal, marathon)`) did not cap, and easy intent passed at anything slower than half-marathon pace. Every consumer reads the same four numbers: the easy-intent ceiling, the 150-minute long-run cap, the readiness pace ease, marathon-pace context and the race-day pace fallback.

## Decision

Each guide pace comes from [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php)'s race-time model, the one that fits the athlete's races ([[coaching-evidence#DanielsGilbert1979]]):

- **Marathon** is the marathon race-equivalent pace (`raceTimeForVdot()` over 42.195 km).
- **Threshold** is the pace the athlete could race for 60 minutes, and **interval** the pace for about 11 minutes, both from the model's sustainable-fraction curve (`sustainableVo2Fraction()`). Threshold and interval still read the quality VDOT when one exists.
- **Easy** is the midpoint of the VDOT calculator's E band on the VO2 curve. From VDOT 40 up the band is a fixed 0.618–0.700 of VDOT, midpoint 0.657. Below 40 the calculator's band rises toward marathon pace, reaching a 0.728 midpoint at VDOT 30, so the midpoint fraction rises linearly from 0.657 at VDOT 40 to 0.728 at VDOT 30 and holds below 30. The readiness ease's slow end sits 0.041 below the midpoint.

Checked against the [VDOT calculator](https://vdoto2.com/calculator) on 2026-10-01 (per km):

| VDOT | Calculator E / M / T / I | App E / M / T / I |
|---|---|---|
| 30 | 7:05–7:46 / 6:51 / 6:09 / 5:28 | 7:25 / 6:52 / 6:21 / 5:47 |
| 50 | 5:07–5:39 / 4:31 / 4:16 / 3:55 | 5:23 / 4:31 / 4:13 / 3:50 |

**Durations win over distance brackets.** For a runner whose 10K takes over an hour (below about VDOT 31.5), the one-hour pace is faster than 10K pace. For one whose 3K takes over eleven minutes (about VDOT 50 and below), the eleven-minute pace is faster than 3K pace. No single effort length fits between 3K and 5K for both VDOT 30 and 60. The owner chose the durations: they name the physiological target, and the brackets hold for the runners they describe. The calculator's own T and I are faster still at low VDOT.

**Labels.** The pace derivation is a **heuristic**: the Daniels model is a book, not peer-reviewed, and its best independent test found it worse for slower runners ([[coaching-evidence#OficialCasado2025]]). That marathon pace is not threshold pace is **evidence-supported** ([[coaching-evidence#SmythMunizPumares2020]], [[coaching-evidence#Jones2021]]). So is keeping a continuous threshold block within what the athlete could race at its pace: an effort sustainable for only about 20 minutes lies above critical speed ([[coaching-evidence#Jones2010]], [[coaching-evidence#Jamnick2020]]).

## Consequences

- Every guide pace slows: at VDOT 50, marathon by about 11 s/km, threshold by 13, interval by 5 and easy by 35. At VDOT 30, threshold and interval sit 12 and 19 s/km slower than the calculator's table.
- A Peak 35-minute threshold block is now within the athlete's own race model at every VDOT, asserted at VDOT 35 and 55.
- The marathon-pace cap binds again: a goal faster than the supported marathon is prescribed at the supported pace.
- The easy-intent ceiling (marathon pace), the 150-minute long-run cap (now 150 minutes at the guide easy pace) and the readiness pace ease all move with the corrected numbers. None of them changed code.
- Stored history is not recomputed here. The one-time recompute ships with CR-08.
