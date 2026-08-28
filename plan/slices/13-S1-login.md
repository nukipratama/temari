# S1 — Login

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4` · **Status** todo

## Goal

Port `pages/Auth/Login.tsx` (479 L) onto `BareShell`. The tightest budget in the program: capped at
160 kB gz, framer-motion-free (R6). Restricted to Base-UI-free primitives (`button`, `card`, `badge`)
— `toggle`, `toggle-group` and `collapsible` are out of bounds here.

Carries the copywriter rubric's open question: the prototype's all-lowercase treatment was agreed
for Login specifically. Whether that stays Login-only or extends is ruled in `L0`; if unruled by the
time this slice starts, escalate rather than guessing.

## Files touched

`resources/js/pages/Auth/Login.tsx`, `resources/js/layouts/BareShell.tsx` (already rebuilt in `F4`;
this slice applies it).

## Blockers

`F4`.

## Acceptance criteria

_To be filled when this slice starts._

## Coverage delta

_To be filled when this slice starts._

## Verification notes

_To be filled when this slice starts. `npm run build && npm run check:chunks` is non-negotiable
here — this is the route the whole `base-ui` chunk-group mitigation exists for._

## Open questions

_To be filled when this slice starts._
