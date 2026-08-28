# F5 — Two-ground art

**Wave** 1 · **Slot** worktree · **Blockers** `F2` · **Status** todo

## Goal

Re-cut mascot, accessories, Kartu chrome, share cards and the Strava mark for two grounds (decision
13). `TemariProto.tsx` (903 lines) + `build-mascot.mjs` + `build-accessories.mjs` (which imports
`COLOR` from `build-tokens.mjs`, so `F2` already moved its 25 SVG outputs — this slice must account
for that, not repeat it). Kartu rarity chrome. `lib/shareCard.ts` + `lib/runcard.ts` +
`RunCardImageRenderer.php` — client and server renderers must agree (checked directly, side by side,
in the designer review template). Strava mark contrast on both grounds, brand-locked, never
recoloured. `dawn-shift` scoped to light only.

## Files touched

`resources/brand/TemariProto.tsx`, `resources/brand/build-mascot.mjs`,
`resources/brand/build-accessories.mjs`, `resources/js/components/card/` (20 files),
`resources/js/lib/shareCard.ts`, `resources/js/lib/runcard.ts`,
`app/Services/Run/Story/RunCardImageRenderer.php`, `resources/js/hooks/useDawnShift.ts`.

## Blockers

`F2` (art generation reads the new token values).

## Acceptance criteria

_To be filled when wave 1 starts._

## Coverage delta

_To be filled when wave 1 starts._

## Verification notes

_To be filled when wave 1 starts. Designer template §5 (art, both grounds, client and server) is
this slice's primary check._

## Open questions

_To be filled when wave 1 starts._
