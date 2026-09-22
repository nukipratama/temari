---
title: Plan recalibration rewrites derived training history
description: HR-zone and intensity-policy changes recompute derived metrics and plan verdicts as one per-user recalibration, while old narration remains visibly stale.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-22
code_refs:
  - app/Services/Run/Plan/PlanRecalibrationService.php
  - app/Jobs/Run/RecalibrateTrainingHistoryJob.php
  - app/Console/Commands/Run/RecalibrateTrainingHistoryCommand.php
  - app/Services/Run/Plan/IntensityPrescriptionResolver.php
  - app/Models/AI/Analysis.php
---

# Plan recalibration rewrites derived training history

**Status:** Accepted (2026-09-22). Supersedes the "nothing is rescored; past verdicts stand" consequence and the rejected historical-rescore alternative in [[a-session-is-the-whole-outing]]. Its whole-outing and no-cooldown decisions remain unchanged.

## Context

Time in HR zones is derived from a mutable zone table. Quality prescriptions also used to be reconstructed from current fitness whenever a plan was rendered. Changing either input could therefore leave stream summaries, weekly load, plan verdicts and the plan shown next to them describing different versions of the athlete.

Keeping every historical value frozen avoids reconstruction drift, but makes a corrected HR profile apply only to future runs. Recomputing only the visible plan has the opposite failure: the prescription changes while the evidence and verdict behind it remain old.

## Decision

**Current zones and current deterministic coaching policy own derived training history.** One per-user recalibration recomputes stored stream summaries from stored raw streams, rebuilds weekly aggregates, regrades past plan rows in chronological order and regenerates the future plan. This path makes no Strava request and no LLM request.

Quality decisions are persisted on `planned_sessions`: hard minutes, pace band, resolved pace, race context and the deterministic reason. The segment graph is reconstructed from those stored facts, including an explicit Easy remainder, so the card and compliance judge read the same prescription.

HR-zone updates dispatch one unique after-commit job per user. Start and completion markers live on the user, outside the plan rows the recalibration can replace; failure leaves the operation visibly pending and ordinary queue retry owns recovery. The foreground `plan:recalibrate-history` command is the rollout and repair path, supports one-user and dry-run modes, and excludes demo data.

Existing plan-day narration is not rewritten or re-billed. It stays readable with a stale marker, and only an explicit Reread may replace it. During recalibration the UI keeps the previous coherent plan visible with a quiet notice rather than exposing a half-rewritten result.

## Consequences

- A zone correction consistently changes time-in-zone, aggregates, adherence and future coaching instead of splitting history at the edit date.
- Historical derived values may move under improved rules. That reconstruction drift is explicit and preferable to internally inconsistent coaching.
- Raw activity facts remain untouched, and missing streams remain missing rather than being fetched again.
- Demo data remains deterministic through `demo:seed`; bulk recalibration never includes it.
- Narration can be recognizably older than the deterministic plan without silently presenting itself as current.

## Rejected

- **Apply new zones only to future runs.** Leaves old load and tolerance evidence on a different scale from the plan consuming it.
- **Recompute streams but preserve plan verdicts.** Keeps the exact contradiction the recalibration exists to remove.
- **Automatically regenerate narration.** Turns a deterministic correction into an unbounded LLM bill and rewrites the athlete's historical voice without being asked.
- **Hide the plan until completion.** A coherent older plan is more useful than an empty screen, and the pending marker already states its age.
