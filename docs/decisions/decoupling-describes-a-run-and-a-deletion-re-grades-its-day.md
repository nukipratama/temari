---
title: Decoupling describes a run, and a deletion re-grades its day
description: Steady-segment decoupling no longer counts a Long, Tempo or Interval day as run too hard, and deleting a run re-grades its plan day from the surviving runs in either direction, the one exception to a score that never moves down.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Metrics/DecouplingBands.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Jobs/Strava/CleanupDeletedActivityJob.php
---

# Decoupling describes a run, and a deletion re-grades its day

**Status:** Accepted (2026-10-02)

> **Partly superseded (2026-10-05) by [[easy-and-long-runs-are-capped-by-heart-rate]].** A ragged day is now an Easy day or a Long day with no marathon-pace block whose time past the heart-rate cap is too hard (15 minutes, or 20% of a run under 75 minutes; egregious past 30 minutes or 40%), not a share of time above Z2. Decoupling stays descriptive.

## Context

The 2026-10-01 coaching audit found two problems in how a finished week is read:

- **Decoupling decided a verdict it cannot carry.** A `Long`, `Tempo` or `Interval` day whose steady-segment decoupling passed 12% counted as "ragged", and two such days cost the next week a quality session. The 12% and 15% bands were fitted to one athlete's runs. No heat, duration or hydration context entered the decision, and on a threshold session prescribed above critical speed, drift is expected anyway.
- **A deleted run left its grade behind (#1371).** [CleanupDeletedActivityJob](app/Jobs/Strava/CleanupDeletedActivityJob.php) re-scored the day through the ingest path, which only moves a score up. A duplicate upload deleted from an `overreached` day stayed `overreached`, and a past day whose only run was deleted stayed `done`. Neither plan reconciliation nor the trend snapshots were told the history had changed.

## Decision

1. **Decoupling is descriptive.** [PlanAdapter::previousWeekExecution()](app/Services/Run/Plan/PlanAdapter.php#L286) counts only `Easy` days whose time above Z2 passed the easy-day line. The time-above-zone check and its single-egregious-day arm are unchanged. Decoupling stays on the run as durability information, for narration, baselines and weekly rollups, and [DecouplingBands](app/Services/Run/Metrics/DecouplingBands.php#L31) loses the `EGREGIOUS` band that only the verdict read. Cardiovascular drift rises with duration, heat and dehydration ([[coaching-evidence#CoyleGonzalezAlonso2001]], [[coaching-evidence#Racinais2015]]). In 82,303 marathoners, decoupling appeared around 25 km and lower decoupling marked faster finishers, so it describes durability, an individual trait ([[coaching-evidence#Smyth2022]], [[coaching-evidence#Maunder2021]]), not a session run harder than written. **Evidence-supported.**
2. **A deletion re-grades its day in both directions.** [ComplianceScorer::regradeAfterDelete()](app/Services/Run/Plan/ComplianceScorer.php#L428) writes the verdict the surviving runs earn: an `overreached` duplicate can drop to `done`, and a past day with no run left drops to `missed`. Today floors back to `planned`, as it would before a run. An excused day keeps `skip`. Ingests, re-ingests, revisions and late uploads still never lower the earned score ([ComplianceScorer::creditIfEarned()](app/Services/Run/Plan/ComplianceScorer.php#L394)). **Product choice**: the grade has to describe the runs that exist.
3. **A deletion marks the plan and trends dirty.** After its transaction commits, [CleanupDeletedActivityJob](app/Jobs/Strava/CleanupDeletedActivityJob.php#L86) marks plan reconciliation and trend snapshots dirty from the deleted run's date, as ingest and `plan:score-compliance` already do. **Product choice.**

## Consequences

- A hot long run and a threshold session that both decouple past 15% leave next week's quality alone.
- An easy day run mostly above Z2 still costs a quality session, alone when egregious or with one more such day.
- Deleting a duplicate or a mistaken upload corrects the plan day, the adherence that reads it, the next plan regeneration and the trend history.
- Partly supersedes [[a-day-is-scored-when-it-is-run]] and [[grading-follows-shown-advice-and-actual-stimulus]] on the deleted-activity case.
