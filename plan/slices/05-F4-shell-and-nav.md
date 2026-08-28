# F4 — Shell + nav

**Wave** 1 · **Slot** main checkout · **Blockers** `F3` · **Status** todo

## Goal

Rebuild `layouts/{AppShell,BareShell,appLayout}.tsx` on the prototype's topbar/bottomnav components.
Apply `lib/nav.ts` per the **ruled** resolution in [../ia.md](../ia.md): the app adopts the
prototype's **4-tab** bar (today / plan / trends / history), replacing the current 3-tab shape where
Plan nested under Today. `routes/web.php` does not need route changes for this — `/plan` and `/race`
already exist as routes, only their nav classification moves. Wire the appearance toggle (dark
default, light/system reachable — decision 6). Re-measure `scripts/check-entry-chunks.mjs`'s budgets
with the new shell in the graph.

This is the blocker for every wave-2b screen slice — it is the shared chrome all eleven screens sit
inside.

## Files touched

`resources/js/layouts/AppShell.tsx`, `resources/js/layouts/BareShell.tsx`,
`resources/js/layouts/appLayout.tsx`, `resources/js/lib/nav.ts` (per the literal diff spec in
`plan/ia.md`), `scripts/check-entry-chunks.mjs` (budget re-measure).

## Blockers

`F3`. The IA ruling landed in `plan/ia.md` during `L0` — no longer an open blocker, just the spec to
implement against.

## Acceptance criteria

_To be filled when wave 1 starts._

## Coverage delta

_To be filled when wave 1 starts._

## Verification notes

_To be filled when wave 1 starts. R6 (Login's first-paint budget vs. the shadcn stack) is directly
this slice's concern for `BareShell`._

## Open questions

_To be filled when wave 1 starts._
