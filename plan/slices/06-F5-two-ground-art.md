# F5 — Two-ground art

**Wave** 1 · **Slot** worktree · **Blockers** `F2` · **Status** merged ([#659](https://github.com/nukipratama/temari/pull/659), squashed as `6aa3f9fc`)

## Goal

Re-cut mascot, accessories, Kartu chrome, share cards and the Strava mark for two grounds (decision
13). `TemariProto.tsx` (903 lines) + `build-mascot.mjs` + `build-accessories.mjs` (which imports
`COLOR` from `build-tokens.mjs`, so `F2` already moved its 25 SVG outputs — this slice must account
for that, not repeat it). Kartu rarity chrome. `lib/shareCard.ts` + `lib/runcard.ts` +
`RunCardImageRenderer.php` — client and server renderers must agree (checked directly, side by side,
in the designer review template). Strava mark contrast on both grounds, brand-locked, never
recoloured. `dawn-shift` scoped to light only.

## Files touched

`resources/js/components/temari/TemariProto.tsx` (+test), `resources/brand/build-mascot.mjs`,
`resources/brand/build-accessories.mjs`, `resources/css/app.css` (dawn-shift block),
`tests/Unit/Architecture/DesignTokenMirrorsTest.php`.

## Blockers

`F2` (art generation reads the new token values). Done.

## What actually landed

A design fork surfaced before implementation and was put to the user directly: should Kartu
(trading-card chrome) and share-card images (client canvas `lib/shareCard.ts`/`lib/runcard.ts` +
the PHP server renderer `RunCardImageRenderer.php`) flip with the app's light/dark toggle, or keep
their current fixed look? Answer: **keep them fixed** — both already read as a printed/exported
artifact (the PHP renderer's own doc comment: "an exported image has no time of day"), so re-cutting
them for two grounds would be real new work with no user-visible benefit. That decision shrank this
slice from the plan's literal file list (`components/card/` 20 files, `shareCard.ts`, `runcard.ts`,
`RunCardImageRenderer.php`) down to what genuinely needed two-ground authoring:

- **`TemariProto.tsx`'s mood halo, equipped-aura ring, and Plan-tab season-coverage rings.** These
  three draw *outside* the mascot's body circle, against whatever the app's live page/panel ground
  is — the accessory item colours (headband/shirt/shorts/shoes/medal) do not, since they're clipped
  to the body circle and drawn against the mascot's own fixed-cream fill, so they needed no change.
  Computed actual contrast ratios against `GROUNDS_DARK` before touching anything (not assumed):
  4 of 8 halo colours, 2 of 5 aura colours, and 4 of 5 season colours failed 3:1 on the dark ground
  — largely because the light values were deliberately *darkened* for cream legibility (`inkOn()`),
  which is close to the worst possible choice on a near-black ground. Added `HALO_DARK` (mirrored
  from a new export in `build-mascot.mjs`), `AURA_ITEMS_DARK` (mirrored from a new export in
  `build-accessories.mjs`), and `SEASON_COLORS_DARK` (computed the same way, no generator
  counterpart — `SEASON_COLORS` itself never had one either), all derived via the existing
  `inkOnDark()` machinery F2 already built. Ground picked per-render via `useIsChartDark()`
  (F6's hook, reused as-is — it's a generic `data-theme` observer despite the chart-flavoured name).
- **Dawn-shift was dead, not just unscoped.** The plan's stated acceptance goal was "dawn-shift
  scoped to light only," which implied it was *working* and just needed a guard. It wasn't: F3's
  mechanical sweep migrated every `bg-surface` call site to `bg-card`/`bg-background`, and nothing
  else ever painted plain `bg-surface`, so dawn-shift's `--color-surface` write had been silently
  inert since F3 merged — confirmed by grep, not assumed. Fixing this needed care: `--color-surface`
  is *also* the exact property name `resources/brand/grounds.mjs`'s `readDawnShiftSurfaces()`,
  `build-directions.mjs`, and `apply-pewter.mjs` key off by name for contrast-ground computation, so
  renaming it would have silently dropped dawn-shift's grounds from the ink-tier contrast audit —
  exactly the "green CI, scored nothing" failure mode the plan's R1 warns about. Fix: keep writing
  `--color-surface` unchanged (all three scripts untouched, verified via `node` that
  `paperGrounds()`'s ground set is byte-identical before/after), and *also* write
  `--color-background` (what `bg-background` — the actual page-root class — reads) with the same
  literal hex per bucket, both guarded under `html:not([data-theme='dark'])`.
- **Kartu's one literal `#fcf9f3` (flagged by the pre-implementation investigation as "should
  probably be a var") turned out not to be a bug.** `--color-cream-deep` — the other gradient stop,
  already a `var()` — is a *raw palette* token, never redefined under `[data-theme='dark']`. Both
  stops are already fixed regardless of ground, consistent with the "keep Kartu fixed" decision.
  No change made.
- **Strava mark contrast, verified, not touched.** Every Strava mark in the app (`StravaSyncButton`,
  `StravaZoneReconnectBanner`, `DemoBlockedModal`) sits on a fixed `bg-strava-orange` +
  `text-white` button, never a ground-reactive token, so it was already safe on both grounds by
  construction. Confirmed via grep across every call site, not assumed from the brand-lock rule
  alone.

## Coverage delta

95.05% → 95.05% fn (no change — every new branch got direct test coverage, net zero).

## Verification notes

Fast-feedback ladder, full: `pest --group=structure --no-tia` (37/37, incl. both
`DesignTokenMirrorsTest` cases and `DesignTokenContrastTest`), `npx tsc --noEmit` (clean),
`npm run build && npm run check:chunks` (green, Login/Home/Runs/Profile all within budget),
targeted vitest for every touched file (159/159) plus the full frontend suite via
`npm run test:coverage` (all passing, coverage summary above), full `bin pest --parallel --no-tia`
(3627/3627), `bin pint` / `bin phpstan analyse --debug` / `bin rector --dry-run` all clean,
`check-raw-palette.mjs` and `check-doc-citations.php` both clean. `grounds.json` needed no changes:
neither `TemariProto.tsx` (pure inline SVG fill/stroke, no `bg-*` Tailwind classes) nor the untouched
Kartu/share-card files added any new panel call site.

Designer template §5 (art, both grounds, client and server): client mascot now verified correct on
both grounds via direct contrast computation + a new `TemariProto.test.tsx` describe block
(`dark-ground ring legibility`) that pins the dark halo/aura/season values against the
`build-mascot.mjs`/`build-accessories.mjs` generator exports, the same pattern the existing light
parity tests already use. Kartu/share-card client-vs-server parity was explicitly scoped *out* by
the "keep fixed" decision, so no new client/server comparison was needed there.

## Open questions

None outstanding. The one live question (Kartu/share-card ground-reactivity) was resolved with the
user before implementation; see "What actually landed" above.
