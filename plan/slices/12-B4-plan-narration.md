# B4 — Plan narration, voice-only

**Wave** 2a · **Slot** worktree-be · **Blockers** `B1` · **Status** todo

## Goal

Three `AnalysisType` cases (day / week / season) with subject types and `cadence()`, three
narrators, three jobs, `AnalysisSubjectMap` + `AnalysisSubjectAuthorizer` arms, registration in
`NarratorsCoverageTest` / `JobsCoverageTest`; the replan pill mapped onto the existing
`Analysis::cooldownRemaining()`; amend `docs/features/plan-periodizer.md`.

Decision 11: **voice-only**. Rules still own every number. No superseding ADR, only an amendment —
this is not architecturally significant enough for its own decision doc.

## Files touched

`app/Enums/AnalysisType.php`, three new narrators under `app/Services/AI/Narrators/`, three new jobs,
`AnalysisSubjectMap`, `AnalysisSubjectAuthorizer`, `NarratorsCoverageTest`, `JobsCoverageTest`,
`resources/js/types/generated.ts` (regenerated — coordinate with `B2`), `docs/features/plan-periodizer.md`.

## Blockers

`B1`. Last in the strict wave-2a order.

## Acceptance criteria

_To be filled when wave 2a starts. Copywriter rubric §5 (prompt strings, voice-only enforcement) is
this slice's sharpest check — a prompt that asks the model to produce or adjust a number is a finding._

## Coverage delta

`n/a` — backend slice.

## Verification notes

_To be filled when wave 2a starts._

## Open questions

_To be filled when wave 2a starts._
