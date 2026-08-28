# B3 — Structured session segments

**Wave** 2a · **Slot** worktree-be · **Blockers** wave-1 checkpoint · **Status** todo

## Goal

First in wave 2a's strict order, because it redefines what a plan day *is*. A
`planned_session_segments` table (`planned_session_id`, `order`, `key`, `minutes`, `zone`,
`pace_label`) plus a `SegmentKey` enum (`warmup`/`main`/`interval`/`recovery`/`cooldown`);
`WeekPlanBuilder` emits segments; a retirement path for `DistanceBand`, `PaceBand` and
`DistanceBandKm`.

**Freeze `WeekPlanDay` at the end of this slice** — `S3` and `S4` code against the frozen shape.

Deleting `DistanceBandKm.php` touches `docs/features/plan-periodizer.md`'s 24 `code_refs`; land that
doc edit in the **same commit** as the deletion (the doc-citation job is unconditional and would
otherwise redden every open PR in the epic).

## Files touched

New migration for `planned_session_segments`, `app/Enums/SegmentKey.php` (new),
`WeekPlanBuilder` and callers, `DistanceBand.php`, `PaceBand.php`, `DistanceBandKm.php` (retired),
`resources/js/types/inertia.ts` (`WeekPlanDay`), `docs/features/plan-periodizer.md`,
`tests/Unit/Architecture/EveryClassHasATestTest.php` (exemption-array edit — first commit only, per
R7).

## Blockers

Wave-1 checkpoint (decision 18). Must land before `B2`.

## Acceptance criteria

_To be filled when wave 2a starts._

## Coverage delta

`n/a` — backend slice.

## Verification notes

_To be filled when wave 2a starts. Coupling: `resources/js/types/inertia.ts` is read by `S3`, `S4`,
`S5`, `S7`, `S11` — see [../README.md](../README.md) §8._

## Open questions

_To be filled when wave 2a starts._
