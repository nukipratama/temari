---
title: Grading follows the shown advice and learns the stimulus actually done
description: A day is graded against the advice the athlete was shown, original, eased and actual effort stay separate, and only complete evidence on unchanged quality advice teaches progression.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-01
code_refs:
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/SessionIntentJudge.php
  - app/Services/Run/Plan/IntentOutcome.php
  - app/Services/Run/Plan/EffectiveSession.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Jobs/Strava/CleanupDeletedActivityJob.php
---

# Grading follows the shown advice and learns the stimulus actually done

> **Exception (2026-10-07): [[a-make-up-is-graded-against-the-moved-session]].** A make-up is graded against the moved session rather than the advice shown, and never teaches progression.

> **Partly superseded (2026-10-02) by [[decoupling-describes-a-run-and-a-deletion-re-grades-its-day]].** A deleted run now re-grades its day from the surviving runs in either direction, so a deletion can lower the score. Ingests, re-ingests, revisions and late uploads still only move it up, and the rest of this decision stands.

**Status:** Accepted (2026-10-01). Amends [[a-day-is-graded-on-distance-and-intent]] and the stimulus half of [[plan-adaptation-responds-to-stimulus]].

## Context

Grading read only the effective prescription, rebuilt it from the current baseline when no advice history existed, accepted any pace faster than a tempo target, and let a window shorter than the block count as the block. An eased Tempo run easy still progressed the abandoned Tempo dose, and an easy day with hard work added taught nothing.

## Decision

- **Shown advice or unknown.** [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) judges the last shown revision before the longest run's UTC start. With no shown revision the verdict is `unknown` with `advice_history: unknown`: the distance credit and the actual load stand, intent is never reconstructed, and the row never teaches quality progression. Existing rows are not regraded.
- **Original, effective and actual stay apart.** Evidence on `planned_sessions.intent_evidence` carries `effective_type`, `eased_from`, `concern`, `original_completed` and the measured `stimulus_family`, `stimulus_minutes` and `stimulus_source`. No new verdict case exists; [IntentOutcome](app/Services/Run/Plan/IntentOutcome.php) words it.
- **Mild or strong concern** comes from the stored readiness assessment of the shown revision: a ceiling of rest or easy-only driven by severe fatigue or soreness, concerning pain or illness is strong; every other ease is mild. A controlled original quality session completed against mild eased advice reads as completed and exceeding the recovery advice; strong stays plainly worded; hard work on an easy day reads as an unplanned hard effort.
- **Controlled, not rewarded.** A quality block quicker than its target by more than 5% of the target pace is `too_hard` (still counted as load). A hit needs a window, lap or recording covering at least 90% of the requested work; short or partial evidence is `unknown`, and `missed` only on complete evidence. A faster easy run keeps easy intent.
- **Eligibility.** A row teaches quality progression and stimulus adherence only when the scorer stamped `quality_progression: eligible`: shown, unchanged quality advice, verdict `hit`, `missed` or `too_hard`. Legacy and unknown rows stay uncertain.
- **Budgets.** A credited day budgets as the session it effectively was ([EffectiveSession::budgetProfileOf()](app/Services/Run/Plan/EffectiveSession.php)), so an abandoned Tempo spends none of its minutes; self-added or completed-anyway hard work counts as demanding with its measured minutes. [PlanAdapter](app/Services/Run/Plan/PlanAdapter.php) judges last week's execution against the effective type.
- **Re-scoring.** The earned distance score never moves down, but intent and stimulus evidence are rewritten whenever the recomputed reading differs, including at an equal score, after a delayed import, an edit or a deleted activity. Several recordings on a day are judged on the longest, the stimulus dose sums the day, and recordings that cannot cover a block give `unknown`.

## Consequences

`plan:regrade-season` and the recalibration rewrite now produce `unknown` intent for days with no shown advice; reworking them is separate. The `stimulus_*` keys are JSON, not columns, so they are not indexed.
