---
title: Load numbers are named as running load
description: CTL, ATL and form are shown as long-term load, short-term load and load balance, with three states (fresh, steady, heavy) over the four stored ones, and no copy, UI or prompt reads them as fitness, readiness, overreaching, injury or soreness.
tags: [decision, run, design, ai]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Metrics/TrainingFormStatus.php
  - app/Services/Run/Metrics/LoadBalance.php
  - app/Services/Run/Metrics/TrainingLoad.php
  - resources/js/components/trends/MonthComparison.tsx
---

# Load numbers are named as running load

**Status:** Accepted (2026-10-02). Decided in #1542, #1539 and #1540. Extends [[a-load-label-supports-a-concern-it-never-decides-one]] from the plan to the words.

## Context

The CTL, ATL and form numbers are exponentially weighted averages of heart-rate TRIMP over running only. The app called them fitness, fatigue and form, and showed four states including `fatigued` and `overreaching`. Those words claim a physiology the numbers do not measure: overreaching is a performance-based diagnosis that no load number makes ([[coaching-evidence#Meeusen2013]]), the fitness-fatigue model's parameters are unstable across athletes and its numbers do not mean what the names say ([[coaching-evidence#Vermeire2022]]), and monotony and strain carry mixed injury evidence ([[coaching-evidence#Foster1998]], [[coaching-evidence#JonesCM2017]]). Tiredness has causes a load number cannot see, such as low energy availability ([[coaching-evidence#Mountjoy2023]]).

## Decision

1. **Name them as load.** CTL is **long-term load** (running load averaged over about six weeks), ATL is **short-term load** (the last week or so), and form is **load balance** (long-term minus short-term). All three count running only. **Evidence-supported**: a load number is not fitness, readiness or a diagnosis.
2. **Show three states, store four.** [TrainingFormStatus::loadBalance()](app/Services/Run/Metrics/TrainingFormStatus.php#L17) maps `fresh` to fresh, `optimal` to steady, and `fatigued` and `overreaching` to heavy ([LoadBalance](app/Services/Run/Metrics/LoadBalance.php)). The four stored states keep their thresholds in [TrainingLoad](app/Services/Run/Metrics/TrainingLoad.php) unchanged. **Product choice**: the split between the two heavy states is not one the evidence supports.
3. **No physiology claims.** No copy, UI or prompt calls these numbers fitness, fatigue, readiness or overreaching, or claims soreness or injury risk from them. "Uniform" describes monotony and carries no risk claim. Remaining fatigue wording names other causes (illness, poor sleep, under-fuelling). **Evidence-supported.**
4. **Related terms follow.** Threshold reads "comfortably hard, about one-hour race effort". VDOT reads as the most conservative recent result. Heart-rate drift names heat, duration and fluids, never a weak aerobic base ([[coaching-evidence#CoyleGonzalezAlonso2001]], [[coaching-evidence#Smyth2022]]). The recovery glossary matches the 24 and 48 hour readiness logic. Narrator prompts and rule-based fallbacks use the same names ([[voice-and-tone]]). **Product choice.**
5. **The month section is "long-term load".** [MonthComparison](resources/js/components/trends/MonthComparison.tsx) is headed "long-term load" and says it tracks how much you have run, not fitness. **Product choice.**

## Consequences

- An athlete never reads a load number as a verdict on their body, and a narrator has no term to turn it into one.
- The stored `form_status` and its plan consequences are untouched, so no data migration or replan.
- Anything that names a load number must use the three names; a new surface that says "fitness" for CTL breaks this decision.
