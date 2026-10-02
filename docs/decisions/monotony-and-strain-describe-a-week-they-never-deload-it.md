---
title: Monotony and strain describe a week, they never deload it
description: Monotony no longer triggers the HighMonotony deload or caps readiness, and strain above 12×CTL is no longer its own deload trigger; a return after a gap is handled once, by the missed-week adaptation and the ramp.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Metrics/Readiness.php
  - app/Enums/AdaptationReason.php
---

# Monotony and strain describe a week, they never deload it

**Status:** Accepted (2026-10-02)

## Context

The 2026-10-01 coaching audit ran the adaptation rules against synthetic athletes:

- **Steady frequent runners got load deloads with no change in load.** At constant daily load, "strain above 12×CTL" reduces to monotony above about 1.71. A plan-shaped six-session week and a daily runner both read HighStrain or HighMonotony and got whole-week deloads.
- **The thresholds have no universal basis.** Monotony and strain came from 25 athletes with individual illness thresholds, and the original abstract gives no 2.0 cutoff ([[coaching-evidence#Foster1998]]). A later systematic review found the associations mixed ([[coaching-evidence#JonesCM2017]]).
- **Production** (read-only aggregates over three non-demo athletes, 2026-10-01): monotony never reached 2.0 (maximum 1.76). Strain above 12×CTL fired only when load came back after a gap, because CTL lags. In practice it was a ramp detector that duplicated the missed-week adaptation.

## Decision

1. **Monotony is descriptive.** [PlanAdapter::decide()](app/Services/Run/Plan/PlanAdapter.php#L101) no longer reads it, so no week becomes a HighMonotony deload. [Readiness](app/Services/Run/Metrics/Readiness.php#L139) records it as an input but no longer counts it as supporting load or caps the day at 2.0. **Evidence-supported.**
2. **Strain is not a deload trigger.** A jump in load after a gap or a low-load period is handled once: the missed-week adaptation shrinks the week that follows the gap, and the ramp brings volume back. A week-to-week ratio carries no injury association of its own ([[coaching-evidence#Frandsen2025]], [[coaching-evidence#Impellizzeri2020]]). **Evidence-supported.**
3. Without strain in the decision, the form warm-up no longer needs a guard for the strain-to-CTL ratio, so it is gone. The hydration gate stays.

`HighMonotony` and `HighStrain` stay in [AdaptationReason](app/Enums/AdaptationReason.php) so stored decisions still render. Their copy no longer calls the pattern an injury risk.

## Consequences

- A steady six-session week (rest Monday, long Sunday) and a steady daily runner both adapt Steady.
- A return week after a two-week gap gets one MissedWeek deload, then Steady.
- Monotony and strain still appear on Trends and in narration as descriptions of the week. Their user-facing names are CR-07's (#1520).
- Supersedes [[a-load-label-supports-a-concern-it-never-decides-one]] decision 5's treatment of the strain ratio during the form warm-up.
