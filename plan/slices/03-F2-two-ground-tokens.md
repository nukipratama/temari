# F2 — Two-ground tokens

**Wave** 1 · **Slot** main checkout · **Blockers** `F1` · **Status** todo

## Goal

*The single serialization point of the whole program* — nothing else in wave 1 runs concurrently
with this slice. Implements the token model in [../README.md](../README.md) §1 decision 4 and the
"token model, precisely" section of the frozen plan (`~/.claude/plans/valiant-jumping-sky.md`).

Extends `resources/brand/build-tokens.mjs` to emit both grounds and the semantic layer; adds
`darkGrounds()` and `inkOnDark()` beside `paperGrounds()`/`inkOn()` in `resources/brand/grounds.mjs`;
regenerates `app.css`'s `@theme static`; re-authors `grounds.json` for two grounds (and per R4, makes
it **fully generated** by a new `resources/brand/build-grounds.mjs`); rewrites
`DesignTokenContrastTest.php` (674 lines) to score both grounds; updates `lib/designTokens.ts`,
`lib/chartTokens.ts` and all 8 `MIRROR_FILES`; re-authors `check-raw-palette.mjs` rule 3 against the
shadcn radius ladder (R10); promotes the four `PHASE_COLOR` hexes to
`--color-phase-{base,build,peak,taper}`; adds the theme-persistence inline script to
`app.blade.php`; fixes the stale palette section in `.claude/skills/temari/SKILL.md` (R9).

**Two commits, in this order — see R1**: (1) pure derivation-and-test change, palette
byte-identical, proves both grounds score against existing values, adds the "no `var(`, no
`color-mix(` in `@theme static`" assertion; (2) introduces the new dark-ground values.

## Files touched

`resources/brand/build-tokens.mjs`, `resources/brand/grounds.mjs`, `resources/brand/build-grounds.mjs`
(new), `resources/brand/grounds.json`, `resources/css/app.css`,
`tests/Unit/Architecture/DesignTokenContrastTest.php`, `resources/js/lib/designTokens.ts`,
`resources/js/lib/chartTokens.ts`, the 8 `MIRROR_FILES`, `scripts/check-raw-palette.mjs`,
`resources/views/app.blade.php`, `.claude/skills/temari/SKILL.md`.

## Blockers

`F1`.

## Acceptance criteria

_To be filled when wave 1 starts. Draws on the wave-1 exit criteria in
[../README.md](../README.md) §9._

## Coverage delta

_To be filled when wave 1 starts._

## Verification notes

_To be filled when wave 1 starts. R1's mitigation (the two-commit split) is itself part of this
slice's acceptance criteria, not just a suggestion._

## Open questions

_To be filled when wave 1 starts._
