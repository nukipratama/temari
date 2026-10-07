---
title: Zones without a measured max are estimates
description: Settings labels default and observed heart-rate zones as estimated and asks for a measured max or Strava zones, heart-rate intent verdicts on such zones are marked a rough read, and no age is collected.
tags: [decision, run, settings]
status: accepted
reviewed: 2026-10-02
code_refs:
  - resources/js/components/settings/HrZonesDisclosure.tsx
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/IntentOutcome.php
  - app/Models/RunnerProfile.php
---

# Zones without a measured max are estimates

**Status:** Accepted (2026-10-02). Decided in #1538.

## Context

Zones come from a max heart rate. With no explicit zones the app uses a default 180, raised to the athlete's highest recorded heart rate when history proves 180 too low ([[settings-hr-zones]]). Either way the max is a guess, yet Settings called the source "default" or "observed" and heart-rate verdicts on easy, tempo and long days read as firm. Predicted max heart rate is imprecise for individuals: the common formulas fit populations, not people ([[coaching-evidence#Tanaka2001]], [[coaching-evidence#Nes2013]], [[coaching-evidence#RobergsLandwehr2002]]).

## Decision

1. **Label the estimate.** [HrZonesDisclosure](resources/js/components/settings/HrZonesDisclosure.tsx#L139) reads "estimated from a default max HR" or "estimated from your highest recorded heart rate", with one line asking for a max from a race or hard test, or a Strava zone sync. The `strava` and `manual` sources read as before. **Evidence-supported.**
2. **Mark the verdicts that rest on them.** [ComplianceScorer::verdictsFor()](app/Services/Run/Plan/ComplianceScorer.php#L61) adds `zones => estimated` to heart-rate-based intent evidence whenever [RunnerProfile::hasExplicitZones()](app/Models/RunnerProfile.php#L79) is not true. [IntentOutcome::detail()](app/Services/Run/Plan/IntentOutcome.php#L59) then appends "the heart-rate zones behind this are estimated, so it is a rough read". The verdict itself is unchanged. **Evidence-supported.**
3. **No age is collected.** The age-predicted formulas would give a fresher number than 180 but need a birth date the app has no other use for. The default and the observed-peak raise stay as they are. **Product choice.**

## Consequences

- An athlete with default zones sees why a heart-rate read is soft and what fixes it.
- Pace-based verdicts and athletes with Strava or manual zones are not marked.
- Entering a measured max, or syncing Strava zones, removes the label and the caveat.
