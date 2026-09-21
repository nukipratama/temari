---
title: Recent single-run capacity stages long-run progression
description: Planned Long sessions may rise at most ten percent above the longest completed run in the prior thirty days, without changing the weekly-volume baseline.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-21
code_refs:
  - app/Actions/Run/Plan/ResolveRecentLongestRunAction.php
  - app/Services/Run/Plan/TrainingBaseline.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/PlanRenderer.php
---

# Recent single-run capacity stages long-run progression

**Status:** Accepted (2026-09-21)

## Context

The trimmed weekly mean correctly describes normal training, but a race-readiness floor can still make the first Long session much farther than any run the athlete recently completed. Weekly capacity and single-session capacity are different signals.

## Decision

[TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php) reads the longest completed run in the thirty days through the plan date and exposes 110% of it as `long_run_progression_cap_km`. [SegmentGenerator::coreKmFor()](app/Services/Run/Plan/SegmentGenerator.php) applies that ceiling only to `Long`; the weekly-volume-derived baseline and the distances of Tempo, Interval and Easy sessions do not move.

The existing race-distance, time-on-feet and half-the-week safeguards remain independent, so the tightest Long ceiling wins. With no recent run, the progression ceiling is absent and the conservative cold-start baseline remains usable. A very short recent run cannot push a Long below the existing 3 km minimum.

## Consequences

- Race-readiness floors are approached through completed Long sessions instead of becoming a first-week jump.
- A recent outlier can relax this progression guard, but cannot bypass the existing safeguards or change the trimmed weekly baseline.
- Summary-ingested runs already carry exact distance and date, so the signal does not wait for stream hydration.

## See also

- [[plan-volume-anchors-on-weekly-mean]] — the weekly baseline stays representative
- [[a-race-block-never-prescribes-below-habit]] — the race floors this guard stages
- [[plan-periodizer]] — how the cap reaches every rendered Long session
