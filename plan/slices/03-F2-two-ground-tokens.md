# F2 — Two-ground tokens

**Wave** 1 · **Slot** main checkout · **Blockers** `F1` · **Status** in-review

## Goal

*The single serialization point of the whole program* — nothing else in wave 1 runs concurrently
with this slice. Implements the token model in [../README.md](../README.md) §1 decision 4 and the
"token model, precisely" section of the frozen plan (`~/.claude/plans/valiant-jumping-sky.md`).

## What actually landed

The architecture ended up simpler than the plan sketched, after an empirical check: compiling a
minimal Tailwind v4 input confirmed `@theme static` already gives every token
`var(--color-x)`-indirected utilities by default (`static` only forces `:root` emission regardless
of usage). That means a plain `[data-theme='dark'] { }` CSS rule — no `@theme inline` layer, no
extra `:root` indirection — correctly overrides any `@theme static` token, and every value stays
literal hex, which is what `DesignTokenContrastTest.php`'s regex-based guard needs (R1).

- `resources/brand/grounds.mjs` / `build-tokens.mjs`: `darkGrounds()` (the Sky family), `inkOnDark()`
  (lightens toward white — the inverse of `inkOn()`), `GROUNDS_DARK`, `DARK_INK`, `RARITY_INK_DARK`.
  Scoped to leaf/ember/citrus + the five rarity tiers (not `mood`, not `horizon` — see the slice's
  commit-1 message).
- `resources/css/app.css`: the semantic layer (background/foreground/card/popover/primary/secondary/
  muted/accent/destructive/border/input/ring/chart-1..5 + the six hand-built prototype tokens) as
  literal light-default hex in `@theme static`, with a `[data-theme='dark']` override block in
  `@layer base`. `primary`/`ring` map to the app's own established horizon/leaf identity, not the
  prototype's Luma-default sky/indigo. `--radius-2xl/3xl/4xl` added additively (own keyword
  vocabulary, not a continuation of the existing ladder). Four `--color-phase-*` tokens promoted from
  the prototype's `PHASE_COLOR` literals.
- `resources/views/app.blade.php`: the blocking theme-persistence script (localStorage key
  `temari-theme`, resolves to dark by default per decision 6) replaces the static
  `<meta name="color-scheme">`. `resources/css/app.css`'s `html{}` rule and new
  `html[data-theme='light'/'dark']` rules handle the steady state.
- `scripts/check-raw-palette.mjs`: rule 3 (off-scale radius) removed — the whole named scale is
  tokened now, nothing left to reject (R10). Refactored to `export { RULES }` behind an entrypoint
  guard so it's directly testable.
- `tests/Unit/Architecture/DesignTokenContrastTest.php`: +4 tests — `@theme static` and the dark
  block both asserted literal-hex-only; every dark-ground `-ink` token and every dark-ground text
  tier (`foreground`/`text-2`/`text-3`) scored ≥4.5:1 against all three dark surfaces.
- `docs/design-tokens.md`, `.claude/skills/temari/SKILL.md`: R9 — both described a stale
  gold-on-indigo "Threadwork" palette instead of the shipped Pewter values. Fixed; `SKILL.md` wired
  into `DesignTokenDocsTest`'s forbidden-name loop.

**Two commits landed in the order R1 asked for**: `0c4f6390` (pure derivation — `grounds.mjs`,
`build-tokens.mjs`, and a new permanent proof test, zero CSS/token-value changes) then `7283b3c8`
(the actual token values, CSS, guard changes, and docs). A third commit, `68d8eedf`, is a separate
concern — see below.

## Deviations from the original plan, logged

- **No `resources/brand/build-grounds.mjs` (R4).** This slice adds zero new `grounds.json`
  paper/scoped/panel entries — the new semantic tokens aren't scanned as raw named-token backgrounds
  the way `grounds.json`'s classification works — so nothing here required the generator. Confirmed:
  all of `DesignTokenContrastTest.php`'s existing panel/paper tests kept passing unchanged.
  Building the generator is real, separable work; left for whichever wave-2b slice first needs it.
- **`lib/designTokens.ts`, `lib/chartTokens.ts` and the 8 `MIRROR_FILES` were *not* touched.**
  `DesignTokenMirrorsTest.php` passed unchanged because this slice only *added* tokens, never
  changed an existing literal hex value the mirror files reference. `designTokens.ts` (the
  `/devtools/design` audit) staying dark-blind is real and already assigned to `S12`'s slice doc.
- **A pre-existing frontend coverage gap was found and fixed** (94.17% functions vs the 95%
  threshold, unrelated to tokens — confirmed by stashing all of F2 and re-running against the clean
  epic tip). Fixed here per the user's explicit call, as commit `68d8eedf`, rather than deferred to
  `W3` — see [../README.md](../README.md)'s amendments log.
- **`color-scheme` hardcoded to `light`** in both `app.blade.php`'s `<meta>` and `app.css`'s `html{}`
  rule — not one of the plan's named "three problems," discovered mid-implementation. Fixed as part
  of the theme-persistence script (see above).

## Files touched

`resources/brand/build-tokens.mjs`, `resources/brand/grounds.mjs`, `resources/css/app.css`,
`resources/views/app.blade.php`, `scripts/check-raw-palette.mjs`, `vitest.config.ts`,
`tests/Unit/Architecture/DesignTokenContrastTest.php`, `tests/Unit/Architecture/DesignTokenDocsTest.php`,
`docs/design-tokens.md`, `.claude/skills/temari/SKILL.md`, plus new files
`resources/js/test/build-tokens-dark.test.ts`, `resources/js/test/check-raw-palette.test.ts`,
`resources/js/types/scripts.d.ts`, and updates to `resources/js/types/brand.d.ts`. Commit 3 touches
`ErrorBoundary.test.tsx`, `dashboard/VitalChips.test.tsx`, `PushNotificationToggle.test.tsx`,
`pages/Race.test.tsx`, `pages/Profile.test.tsx` (coverage fix, unrelated to tokens).

## Blockers

`F1` — merged (#654).

## Acceptance criteria

Draws on the wave-1 exit criteria in [../README.md](../README.md) §9 — items 1, 2, 5 are this
slice's direct responsibility; item 6 (toggle switches live) needs `F4`+`S11` and can't be fully
verified until then.

1. ✅ `@theme static` contains literal hex only; assertion test exists and passes.
2. ✅ `DesignTokenContrastTest` scores both grounds; every `-ink` tier passes AA on the ground it's
   used on.
3. ✅ App builds; full existing test suite passes after the change (3627 Pest, 1996 Vitest).
4. ✅ `check:chunks` green; Login still under 160 kB gz (142.9 kB).
5. ✅ `check:palette` rule 3 removed with a regression test proving the removal was deliberate.
6. ⏳ Appearance toggle — persistence mechanism lands here; the UI control is `S11`'s job. Not
   fully testable end-to-end until then.
7. N/A here — art (`F5`).
8. N/A here — demo data (`F7`).

## Coverage delta

Frontend: 94.17% → 95.06% functions (fixed a pre-existing gap, see above), 95.09% → 95.45%
statements, 95.46% → 95.86% lines. Backend: n/a (no new PHP classes; `DesignTokenContrastTest.php`
additions are test-file functions, exempt from the 1:1 convention like its existing helpers).

## Verification notes

Full ladder run and green before each commit:

```
sail bin pest --filter=DesignTokenContrastTest --no-tia    # 14/14
sail bin pest --parallel --no-tia                          # 3627/3627
sail npm run test                                           # 1996/1996 (206 files)
sail npm run test:coverage                                  # 95.06% functions, clears threshold
sail npm run typecheck / lint / build / check:chunks / check:palette
sail php scripts/check-doc-citations.php
sail pest --group=structure --no-tia
```

`composer check`'s `check-indonesian.php` step fails locally due to a stray gitignored
`resources/brand/prototype/node_modules/` (predates this session, matches "lari" inside vendored
lucide-react type docs) — confirmed CI-irrelevant: no workflow step ever installs the prototype's
own `node_modules`, and stashing it away reproduces the same failure on the clean epic tip. Not
fixed here; unrelated to F2's scope.

R1's two-commit mitigation is itself part of this slice's acceptance criteria — both landed in the
right order, verified via `git diff --cached --stat` before each commit that commit 1 touched zero
CSS.

## Open questions

None outstanding. `F3` should confirm `resources/js/lib/cn.ts`'s `font-size` group doesn't need a
new entry for any token this slice added (none of the new tokens are `text-*` typography tokens, so
expected to be a non-issue, but worth a glance when `F3` runs).
