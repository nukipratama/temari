---
title: One race model drives the plan
description: The plan reads the athlete's supported fitness, not a Riegel projection; a projection slower than the goal adds nothing, ambition bands need evidence covering half the race, and marathon-pace blocks never outrun the VDOT race equivalent.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Plan/RaceAmbitionAssessor.php
  - app/Services/Run/Plan/RaceAmbition.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Services/Run/Metrics/RiegelProjector.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
---

> **Partly superseded (2026-10-02) by [[the-race-page-sets-the-target-beside-supported-time]].** Riegel is no longer shown on the Race page; only the race form's typed-goal warning reads it. The rest of this decision stands.

> **Partly superseded (2026-10-05) by [[supported-race-time-from-recent-efforts]].** The supported fitness the plan reads is now projected from recent whole-run efforts at the race distance, and the `low_evidence` check measures the longest effort that projection rests on.

# One race model drives the plan

**Status:** Accepted (2026-10-02). Supersedes the bands clause of [[race-ambition-is-shown-and-capacity-is-prescribed]].

## Context

Two models of the same race disagreed. The VDOT model prescribed paces and race-day effort, while a Riegel projection slower than the goal added a quality session to every week. The ambition bands also banded a target against evidence from a much shorter race as if it covered the race, and race-day pace followed the target.

## Decision

1. **The behind-goal arm is removed.** A Riegel projection slower than the goal no longer adds a quality session. `AheadOfRacePace` remains as a named reason that changes no work ([PlanAdapter](app/Services/Run/Plan/PlanAdapter.php#L148)), and the stated ambition stays visible. **Evidence-supported**: Riegel holds up to the half marathon but predicts recreational marathoners at least 10 min too fast in half of cases, and its error is largest in less-trained runners ([[coaching-evidence#Riegel1981]], [[coaching-evidence#VickersVertosick2016]], [[coaching-evidence#BlytheKiraly2016]]).
2. **Ambition bands need evidence covering half the race.** The 3% and 6% bands apply only when the qualifying evidence covers at least half the race distance. Otherwise the state is `low_evidence` ([RaceAmbitionAssessor](app/Services/Run/Plan/RaceAmbitionAssessor.php#L44)), and the prescribed race time is the slower of target and supported ([RaceAmbition::prescribedTimeSec()](app/Services/Run/Plan/RaceAmbition.php#L22)). Race-day pace is never an ambitious target pace. The `low_evidence` state is **Evidence-supported**: marathon predictors from shorter races carry roughly 5 to 8% error, and Riegel and VDOT both err for slower runners ([[coaching-evidence#VickersVertosick2016]], [[coaching-evidence#Keogh2019]], [[coaching-evidence#OficialCasado2025]], [[coaching-evidence#BlytheKiraly2016]], [[coaching-evidence#Riegel1981]]). The 3% and 6% bands stay a **Heuristic**.
3. **Marathon-pace blocks never run faster than the VDOT marathon race equivalent**, whatever the ambition state ([IntensityPrescriptionResolver](app/Services/Run/Plan/IntensityPrescriptionResolver.php#L154)). This already held; a test now pins it.
4. **The plan sizes the race by the time it trains for.** The quality type chosen by race duration reads [RaceAmbition::prescribedTimeSec()](app/Services/Run/Plan/PlanInputsGatherer.php#L106), the supported or target time, not a Riegel projection. **Evidence-supported**, for the same reasons as decision 1.
5. **Riegel stays for display.** It feeds the Race page and the `AheadOfRacePace` name, and nothing that sizes work. Its fitted exponent is floored at 1.0, not 0.90 ([RiegelProjector](app/Services/Run/Metrics/RiegelProjector.php#L52)), and efforts shorter than 210 s (3.5 min) are excluded ([RiegelProjector](app/Services/Run/Metrics/RiegelProjector.php#L57)). **Evidence-supported**: Riegel fitted its formula to records from about 3.5 to 230 min ([[coaching-evidence#Riegel1981]]), and individual exponents vary with distance ([[coaching-evidence#BlytheKiraly2016]]).

## Consequences

- An athlete whose projection trails the goal gets the same quality count as one on track. The Race page still shows the gap.
- A target backed only by a result under half its distance reads `low_evidence`, and race day is paced no faster than the supported time.
- A fitted exponent under 1.0 now reads 1.0, and efforts under 3.5 min no longer enter the fit.
- Partly supersedes [[race-ambition-is-shown-and-capacity-is-prescribed]].

## See also

- [[race-projection]], [[plan-periodizer]], [[coaching-evidence]]
