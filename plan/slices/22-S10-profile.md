# S10 — Profile

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `F5` · **Status** todo

## Goal

Port `pages/Profile.tsx` (490 L) + `components/me/`.

**Ledger rulings (final)**: persona mix (`PersonaBar.tsx`) gets a real **restyle** — a distinctive
identity feature worth deliberate treatment. Badge/milestone display also gets a **restyle**, split
with `S3` which owns where unlock toasts fire; this slice owns how milestones/badges *display* on
Profile. Note: Accessories itself is **cut** — nothing here should assume an equip-locker link exists
on Profile any more.

## Files touched

`resources/js/pages/Profile.tsx`, `resources/js/components/me/`,
`resources/js/components/PersonaBar.tsx`, `StravaSyncBadge.tsx`, badge/milestone display components.

## Blockers

`F4`, `F5`.

## Acceptance criteria

_To be filled when this slice starts._

## Coverage delta

_To be filled when this slice starts._

## Verification notes

_To be filled when this slice starts. Also one of `check-entry-chunks.mjs`'s four hardcoded
budgeted routes._

## Open questions

_To be filled when this slice starts._
