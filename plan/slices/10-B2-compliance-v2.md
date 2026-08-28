# B2 — Compliance v2

**Wave** 2a · **Slot** worktree-be · **Blockers** `B3` · **Status** todo

## Goal

`PlannedSessionStatus` gains `Overreached` and `Skip`; a 0-100 per-day score, a week tally, and "ran
anyway"; `SessionMatcher` rewritten to **persist** (today it is km-only via
`DONE_FRACTION`/`PARTIAL_FRACTION` and computed at render); a backfill command; `PlanAdapter` reads
the new adherence.

## Files touched

`app/Enums/PlannedSessionStatus.php`, `app/Services/.../SessionMatcher.php`, a new backfill Artisan
command, `PlanAdapter`, `resources/js/types/generated.ts` (regenerated — coordinate with `B4`, which
also regenerates it), `resources/js/types/inertia.ts`.

## Blockers

`B3` — must run after `WeekPlanDay` is frozen.

## Acceptance criteria

_To be filled when wave 2a starts._

## Coverage delta

`n/a` — backend slice.

## Verification notes

_To be filled when wave 2a starts. `resources/js/types/generated.ts` is touched by both this slice
and `B4` — see [../README.md](../README.md) §8._

## Open questions

_To be filled when wave 2a starts._
