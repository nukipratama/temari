---
title: The override window scales with race distance
description: An athlete's quality-session override is closed in race week only for a race up to 10 km, and in race week and the 2 weeks before it for anything longer; time trials keep their 2-week window.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-11-16
code_refs:
  - app/Services/Run/Plan/PreRaceWindow.php
  - app/Services/Run/Plan/QualityOverride.php
---

# The override window scales with race distance

**Status:** Accepted (2026-11-16). Partly supersedes the guardrail in [[an-athlete-can-override-an-eased-quality-session]].

## Context

[[an-athlete-can-override-an-eased-quality-session]] closed every override in race week and the 2 weeks before it, the window time trials keep clear. Every race up to 25 km tapers for 2 weeks ([[a-race-block-tapers-two-weeks-and-recovers-in-peak]]), so for a 10K that window reached a full Peak week before the taper began. A ran-too-hard week 2 weeks before race week dropped a tempo that the athlete could not bring back, and the plan showed no reason.

## Decision

`PreRaceWindow::locksOverride()` closes the override:

- **Race up to 10 km** (`PreRaceWindow::SHORT_RACE_MAX_M`): race week only.
- **Anything longer:** race week and the `TimeTrialSchedule::TRIAL_FREE_WEEKS_BEFORE_RACE` (2) weeks before it, as before.

Time trials keep `PreRaceWindow::contains()` with its 2-week default. A taken override still survives a race edit that moves its week into the window.

## Rationale

- The best tapers cut volume 41–60% over about two weeks and keep intensity, and reducing intensity did worse ([[coaching-evidence#Bosquet2007]], [[coaching-evidence#Wang2023]]). Intense work kept into the taper preserves the adaptations it is meant to reveal ([[coaching-evidence#Mujika2010]]). Restoring one quality session, dosed by the taper rules, does not break the taper. **Measured** (meta-analyses).
- Race week stays closed for every distance, and longer races keep the wider window: a half or marathon carries more cumulative fatigue into its taper, and its ran-too-hard drop is the safer default. **Heuristic** (the 10 km cut-off and the week counts).

## Consequences

A 5K or 10K athlete gets the "bring back" offer through Peak and the first taper week. The week's one-override limit, the demo exclusion and every other guardrail are unchanged.
