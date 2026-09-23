---
title: Plan adaptation responds to missed training stimulus
description: Weekly plan adaptation keeps volume adherence separate from key-session stimulus adherence and reconciles material verdict changes after ingest.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-23
code_refs:
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PlanReconciliationService.php
  - app/Services/Run/Plan/PlanReconciliationDispatch.php
  - app/Listeners/ReconcilePlanAfterActivityIngested.php
  - app/Jobs/Run/ReconcilePlanJob.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Console/Commands/Run/ScoreComplianceCommand.php
  - database/migrations/2026_09_23_000000_add_stimulus_adherence_to_plan_adaptations_table.php
  - database/migrations/2026_09_23_000001_add_plan_reconciliation_markers_to_users_table.php
---

# Plan adaptation responds to missed training stimulus

**Status:** Accepted (2026-09-23). Supersedes the weekly-adaptation half of [[a-day-is-graded-on-distance-and-intent]].

## Context

A full-distance easy run on a Tempo, Interval or race-pace Long day completed the
volume without doing the work the session was written to do. Treating that row as
distance-only made the next plan look successful and could add more quality when the
athlete had not absorbed the existing ask.

## Decision

The adapter records two separate signals for the prior week:

- `adherence_pct` remains the average of persisted, capped `distance_score` values.
- `stimulus_adherence_pct` counts only judgeable Long, Tempo and Interval rows. `Hit`
  and `TooHard` count as landed stimulus; `Missed` does not. `Unknown`, unscored,
  skipped and non-key rows are not evidence either way.

A single missed key stimulus returns `MissedStimulus`, holds quality, and never adds
catch-up work. Repeated misses, or every one of at least two judgeable key sessions
missing, removes one quality slot. Safety and mostly-missed-week deloads still have
priority. The effective session produced by a readiness clamp is what the existing
intent judge evaluates, and an explicit skip remains excused rather than a stimulus
miss.

Reconciliation is event-driven but deterministic. An analyzed activity and the
daily close pass mark the user's earliest affected date in durable pending markers.
A unique, per-user job drains that marker under a per-user overlap lock and calls
`Periodizer::regenerateIfChanged()`. The periodizer compares the adaptation
fingerprint before persisting, so late evidence can update the plan without plan
churn when the decision is unchanged. Monday regeneration remains the backstop.

## Consequences

The Plan page can explain “full volume, missed stimulus” without conflating the two
percentages. Late activity evidence can hold or reduce the next quality block before
Monday, while an unknown signal cannot punish the athlete and a single miss does not
trigger a deload.
