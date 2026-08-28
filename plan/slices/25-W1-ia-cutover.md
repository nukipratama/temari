# W1 — IA cutover

**Wave** 3 · **Slot** main checkout · **Blockers** all of wave 2b · **Status** todo

## Goal

Finalize routes, redirects, `nav.ts`, and `ROUTE_BUDGETS_KB` source paths against whatever the
screen slices actually landed as, reconciling any drift from the `L0` IA ruling that accumulated
across eleven parallel screen slices.

**Includes the Accessories cut's route-level cleanup** (ledger ruling): remove `GET /accessories`
and `POST /api/accessories/equip` from `routes/web.php`; remove the `/target`, `/goals`, `/aksesori`
legacy 301 redirects entirely (ruled: no repoint, they 404 now) rather than leaving them pointing at
a route that no longer exists.

## Files touched

`routes/web.php`, `resources/js/lib/nav.ts`, `scripts/check-entry-chunks.mjs`.

## Blockers

All of wave 2b.

## Acceptance criteria

_To be filled when wave 3 starts._

## Coverage delta

_To be filled when wave 3 starts._

## Verification notes

_To be filled when wave 3 starts._

## Open questions

_To be filled when wave 3 starts._
