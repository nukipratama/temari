# S12 — Undrawn survivors

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4` · **Status** todo

## Goal

Devtools, Devtools/Design, Legal, AiUsage (+10 `aiusage/` components) — the screens the prototype
never drew at all and the slice programs like this forget. `resources/js/pages/Devtools/Design.tsx`
renders the live token audit out of `lib/designTokens.ts` and breaks the moment `F2` lands; this
slice is where that gets fixed rather than silently left red.

**Ledger ruling (final)**: these four get a real **restyle** pass, not just a mechanical re-skin —
upgraded from the original mechanical-only assumption. **Accessories is no longer part of this
slice** — the ledger ruled it `cut`; its removal is `W1` (routes) + `W2` (backend/components), not a
restyle here.

## Files touched

`resources/js/pages/Devtools.tsx`, `resources/js/pages/Devtools/Design.tsx`,
`resources/js/pages/Legal/Document.tsx`, `resources/js/pages/AiUsage.tsx` +
`resources/js/pages/AiUsage/aiusage/` (10 components) + `resources/js/pages/AiUsage/helpers.ts`.

## Blockers

`F4`.

## Acceptance criteria

_To be filled when this slice starts._

## Coverage delta

_To be filled when this slice starts._

## Verification notes

_To be filled when this slice starts. Confirm `Devtools/Design.tsx` renders correctly against the
post-`F2` token shape before calling this slice done — it is the one page in the app whose entire
job is to audit tokens, so a stale audit here is worse than elsewhere.*

## Open questions

_To be filled when this slice starts._
