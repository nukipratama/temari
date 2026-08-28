# W2 — Dead-code sweep

**Wave** 3 · **Slot** main checkout · **Blockers** `W1` · **Status** todo

## Goal

Dependency pruning and removal of anything the program's own churn left dead — old primitives no
screen imports anymore, unused exports, orphaned test fixtures. This is the one slice in the program
explicitly licensed to delete pre-existing dead code, since by this point "pre-existing" and
"created by this program" are the same thing.

**Includes the Accessories backend deletion** (ledger ruling, `cut`): `AccessoryController.php`,
`EquipAccessoryRequest.php`, `EquippedAccessories.php` service and their tests; the 25 SVGs under
`resources/brand/accessories/` and their generator entry points in `build-accessories.mjs` (keep the
generator itself if `F5`'s mascot art still uses shared helpers from it — check before deleting the
whole file); `resources/js/pages/Collection/Accessories.tsx` + `equippedAccessories.ts` + tests.

**Resolve the `AccessoryUnlockModal.tsx` coupling before deleting it** — see
[../ledger.md](../ledger.md)'s "Coupling this ledger surfaced". It may be accessory-specific
(delete) or a generic unlock-celebration component `S10` also uses for badges (keep, hand ownership
to `S10`). Confirm which before touching it. Also narrow `GrantEligibleUnlocksAction.php` /
`GrantSeasonUnlocksAction.php` to badge-only unlocks if they currently branch on accessory unlocks
too.

## Files touched

Determined by what `depcheck`/import analysis finds at the time, plus the Accessories deletion list
above. Not fully enumerable in advance.

## Blockers

`W1`.

## Acceptance criteria

_To be filled when wave 3 starts._

## Coverage delta

_To be filled when wave 3 starts._

## Verification notes

_To be filled when wave 3 starts._

## Open questions

_To be filled when wave 3 starts._
