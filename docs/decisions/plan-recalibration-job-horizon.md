---
title: Plan recalibration has a bounded single-job horizon
description: The atomic recalibration job has a measured runtime envelope and an explicit timeout until long histories can be resumed
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-26
code_refs:
  - app/Jobs/Run/RecalibrateTrainingHistoryJob.php
  - app/Services/Run/Plan/PlanRecalibrationService.php
---

# Plan recalibration has a bounded single-job horizon

**Status:** Accepted (2026-09-26). Operational limit for [[plan-recalibration-rewrites-history]] until [issue #1249](https://github.com/nukipratama/temari/issues/1249) is complete.

## Context

Recalibration keeps one per-user transaction open while it streams and recomputes stored activity data, rebuilds aggregates, regrades past sessions, and regenerates the future plan. A benchmark of 1,008 activities completed in 34.66 seconds with 56 MB peak memory. The queue job previously allowed only 60 seconds, leaving little headroom above the measured history size.

## Decision

The queued job gets a 120-second timeout, and the service lock lasts 150 seconds so it outlives a timed-out worker. The 1,008-activity benchmark implies roughly 3,500 activities at the same rate before reaching 120 seconds. This is an operational estimate, not a hard or supported maximum: aggregate rebuilding, database contention, skipped rows, and differences in stream data affect runtime.

The operation stays atomic. A history that exceeds the job timeout can therefore fail all three attempts without making progress; retries do not shorten deterministic work. Issue #1249 tracks a bounded or resumable execution strategy.

## Consequences

- The measured workload has substantially more timeout headroom while the operation remains all-or-nothing.
- Operators should treat roughly 3,500 activities as an estimate for monitoring and escalation, not as a guaranteed capacity boundary.
- Histories that approach or exceed that estimate need the follow-up resumable design before being treated as supported.
