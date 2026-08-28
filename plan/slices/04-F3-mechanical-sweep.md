# F3 — Mechanical sweep

**Wave** 1 · **Slot** main checkout · **Blockers** `F2` · **Status** todo

## Goal

Migrate ground-dependent utilities per the table in the frozen plan's "what the sweep actually
changes" section. **Not** a global rename — fixed-identity values (`--mood-*`, `--rarity-*`,
`--color-strava-orange`, `--color-horizon` as accent fill, `--color-ink-on-sky`) are untouched.

Four script-generated commits plus one hand-fixup commit: (1) the ground-dependent utility
migration, (2) `@iconify/react` → `lucide-react` across 64 files, deleting `lib/iconBundle.ts`,
`scripts/build-icon-bundle.mjs` and the `prebuild`/`predev` hooks, (3) the 6-of-23 primitive swap
(`Card`→`card`, `Chip`→`badge`, `Toggle`→`toggle`, `SectionTabs`→`toggle-group`,
`PillButton`+`PillLink`→`button`), deleting each `.tsx` and its `.test.tsx` together, (4) `format` +
eslint `--fix`. Updates `resources/js/lib/cn.ts` if any `--text-*` name moved.

Per R2, the codemods under `plan/codemods/` **are** the review artifact — each pass is pure script
output, zero hand edits, reviewed as a script rather than as a hundred-file diff.

## Files touched

~148 `.tsx` + supporting `.ts` files across `resources/js`; `plan/codemods/*.mjs` (new);
`resources/js/lib/iconBundle.ts` (deleted), `scripts/build-icon-bundle.mjs` (deleted);
`resources/js/components/ui/{Card,Chip,Toggle,SectionTabs,PillButton,PillLink}.tsx` and tests
(deleted); `resources/js/lib/cn.ts`.

## Blockers

`F2`.

## Acceptance criteria

_To be filled when wave 1 starts._

## Coverage delta

_To be filled when wave 1 starts._

## Verification notes

_To be filled when wave 1 starts. R2's mitigation (codemods as review artifact, before/after
`browser-review` shots) is this slice's PR-body requirement, not optional colour._

## Open questions

_To be filled when wave 1 starts._
