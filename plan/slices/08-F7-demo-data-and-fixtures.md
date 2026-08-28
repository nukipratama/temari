# F7 — Demo data + shared fixtures

**Wave** 1 · **Slot** worktree · **Blockers** `B2`, `B3` · **Status** todo

## Goal

`database/seeders/Demo/` (9 files, 1659 lines) learns the new backend shapes from `B2` (Compliance
v2) and `B3` (session segments) so screen slices are not designed against empty states (R5). Adds a
shared `resources/js/test/fixtures/` module so the twelve wave-2b screen slices share one
`WeekPlanDay` literal instead of each hand-rolling one.

## Files touched

`database/seeders/Demo/*.php` (9 files), `resources/js/test/fixtures/` (new module).

## Blockers

`B2`, `B3` — this slice runs **after** wave 2a's first two backend slices land, even though it is
listed in wave 1. Its worktree stays idle until then.

## Acceptance criteria

_To be filled when unblocked._

## Coverage delta

_To be filled when unblocked._

## Verification notes

_To be filled when unblocked. `demo:seed` producing a usable dataset on both grounds is wave-1 exit
criterion 8 in [../README.md](../README.md) §9 — this slice is what makes that true._

## Open questions

_To be filled when unblocked._
