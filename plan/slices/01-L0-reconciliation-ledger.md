# L0 — Reconciliation ledger + IA

**Wave** 0 · **Slot** main checkout · **Blockers** `P0` (the tree must exist) · **Status** in-progress

## Goal

Decide, **before any code moves**, what happens to every shipped feature the prototype does not draw,
and what the app's information architecture actually is. Produce [../ledger.md](../ledger.md) and
[../ia.md](../ia.md).

This is the slice that prevents the program's worst failure: a beautiful port that silently deleted
working product. The prototype covers eleven screens. The app has considerably more than eleven
screens' worth of feature, and the difference is not visible from the mockups.

No code. Two documents and a set of rulings.

## Files touched

`plan/ledger.md`, `plan/ia.md`, and the status rows in `plan/README.md`. Nothing else.

## Method

1. **Inventory** — enumerate every shipped feature the prototype omits, with its real file locations
   (done; see the ledger's *where it lives* column).
2. **Rule each one** `keep` / `restyle` / `cut` / `defer`:
   - `keep` — survives, gets the mechanical sweep in `F3`, no redesign. Cheapest verdict.
   - `restyle` — survives and gets deliberate design work in a named slice. Most expensive.
   - `cut` — removed entirely: page, route, components, backend, nav entry, and every link into it.
     Cutting is permitted (decision 1) and is sometimes the right answer for a feature that exists
     because it was easy rather than because it earns its place.
   - `defer` — survives untouched and unstyled for now, revisited after the wave-2 checkpoint. The
     honest verdict for anything genuinely undecided; **not** a place to park hard calls
     indefinitely.
3. **Assign an owning slice** to every non-`cut` row, so nothing is homeless.
4. **Resolve the IA** — the 4-tab prototype against the 3-tab shipped nav, and where the six
   non-tab destinations hang.
5. **Put every verdict to the user.** No verdict is final until it is.

## Acceptance criteria

1. Every feature in the inventory has a verdict, a rationale, and (unless `cut`) an owning slice.
2. Every owning slice named in the ledger exists as a doc under `plan/slices/`.
3. `ia.md` contains a **literal diff spec** against `resources/js/lib/nav.ts` — the exact `TabId`
   union, the exact `ITEMS` array, and the exact `prefixes` for each — not a description of one.
4. Every route in `routes/web.php` appears in `ia.md` classified as tab / pushed / API / redirect /
   devtools, with its entry point named for anything that is not a tab.
5. Every `cut` row states what happens to inbound links and whether a 301 is needed.
6. The user has ruled on every verdict.

## Coverage delta

`n/a` — no application code.

## Verification notes

- The engineer and devops rubrics barely apply. The **product-manager template is the real gate
  here**, and it should be worked against this document rather than against a built app: §1 of that
  template is literally "does the ledger hold", so `L0` is where its contract is written.
- The copywriter rubric's open question (does the prototype's all-lowercase treatment extend past
  Login?) is *carried*, not resolved, by this slice. It belongs to `F4`/`S1`.

## Open questions

Tracked live in [../ledger.md](../ledger.md) and [../ia.md](../ia.md) until ruled. On ruling, they
move into the tables and, if they contradict anything in §1 of [../README.md](../README.md), a row is
added to the amendments log.
