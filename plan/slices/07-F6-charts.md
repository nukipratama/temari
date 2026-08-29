# F6 — Charts, two grounds

**Wave** 1 · **Slot** worktree · **Blockers** `F2` · **Status** in-review

## Goal

`collection/LineChart.tsx`, `ProgressionChart`, `race/CtlTrendChart.tsx`, `aiusage/DailyChart.tsx`,
`run/LapsGraph.tsx`, `lib/chartTokens.ts`. The prototype's `CHART_PALETTE` already ships both
halves as authored. **All chart work is confined here** — screen slices (`S1`-`S12`) do not redesign
charts; a screen slice that touches chart appearance is a finding against the engineer rubric.

## What actually landed

The plan's file list was written before anyone grepped for every real `PALETTE` consumer — same
pattern as F3's icon audit. `LineChart.tsx` (Chart.js registration only, no colors of its own) and
`aiusage/DailyChart.tsx` (already fully token-driven via Tailwind semantic classes: `bg-popover`,
`border-border`, `bg-horizon`) needed **zero changes**. But `ProgressionChart.tsx` and
`CtlTrendChart.tsx` weren't the only real consumers: `resources/js/components/trends/panels/`
holds four more Chart.js configs (`FitnessTrend`, `VdotTrend`, `LoadTrend`,
`PaceConsistencyTrend`) with the identical bug, plus `run/SplitsTable.tsx` (a sibling view of
`LapsGraph.tsx`'s same lap data, sharing its `barRowFill()` helper) had the identical non-chart
version of it. Per the plan's own rule — "all chart work is confined here; a screen slice that
touches chart appearance is a finding against the engineer rubric" — fixing these here rather than
leaving the bug for `S6`/`S8` to rediscover was the consistent reading of that rule, not scope
creep past it.

**The actual bug, once traced**: `PALETTE.ink2`/`PALETTE.ink3` (grid lines, axis/legend tick
labels) and `PALETTE.horizonInk` (several panels' primary line stroke — darkened specifically so a
*thin* 1.5-2.5px mark holds up on light paper) are all light-only literals with no dark
counterpart. On the dark ground these read as dark-gray-on-near-black or dark-olive-on-near-black —
low to zero contrast. Bold *fills* (`PALETTE.horizon` used as an area fill or full-opacity bar,
`leaf`/`citrus`/`ember` band tints) don't have this problem and were left untouched, matching how
`--mood-*`/`--rarity-*` fills don't migrate either — only text-weight marks needed a ground-reactive
pair. Confirmed the split empirically, not just by category: `CtlTrendChart`/`ProgressionChart`
already used raw `PALETTE.horizon` (not `horizonInk`) for their primary stroke and read fine
unchanged on both grounds in the browser-review screenshots below, while `VdotTrend`/`LoadTrend`
(which used `horizonInk`) needed the swap.

New `resources/js/hooks/useIsChartDark.ts`: Chart.js reads plain JS values at render time, not
CSS custom properties, so a chart can't just inherit `[data-theme]` the way a component's classes
do — it needs to know which ground is active and recompute. `useSyncExternalStore` + a
`MutationObserver` on `<html>`'s `data-theme` attribute, mirroring the existing `useScrolled`
pattern. New `CHART_GROUND` export in `chartTokens.ts`: `{ light, dark }` pairs for `grid`, `tick`,
`secondaryLine`, `line`, `pointBorder`, `border` — each mirrors a real light/dark `--color-*` pair
already declared in `app.css` (`text-2`, `text-3`, `card`, `border`), kept as literal hex/rgba per
the same canvas constraint `PALETTE` itself is under. `grid`/`tick` additionally match the frozen
prototype's own `CHART_PALETTE`.

`FitnessTrend.tsx`'s canvas-drawn milestone-marker plugin is the one place ground couldn't just be
a `useMemo` dependency: react-chartjs-2 registers `plugins` once at chart creation and the file's
own existing comment already explains why (marks/selection go stale otherwise) — so ground follows
the same `groundRef` + explicit `chart.update('none')` pattern already used for `marks`/`selected`,
not a fresh dependency array.

**One genuine non-chart bug, same root cause**: `LapsGraph.tsx`/`SplitsTable.tsx`'s "not fastest
lap" bar was solid `bg-sky` sitting in a `bg-sky/[0.06]` track — both raw-palette sky, meaning on
the dark ground (where sky family *is* the page background) the bar and its track both vanished
into the surrounding card. Swapped to `bg-foreground`/`bg-foreground/[0.06]` (ink-dark on light,
cream-light on dark — confirmed correct both ways via cropped screenshots below), and the caption
"dark = the rest" to "muted = the rest" since the bars are no longer literally dark on the ground
where that reads best.

## Files touched

`resources/js/lib/chartTokens.ts` (+ test), new `resources/js/hooks/useIsChartDark.ts` (+ test),
`resources/js/components/collection/ProgressionChart.tsx`,
`resources/js/components/race/CtlTrendChart.tsx`,
`resources/js/components/trends/panels/{FitnessTrend,VdotTrend,LoadTrend,PaceConsistencyTrend}.tsx`,
`resources/js/components/run/{LapsGraph,SplitsTable}.tsx` (+ tests), `resources/js/lib/splits.ts`
(+ test), `resources/brand/grounds.json` (new panel registrations, stale entries dropped),
`tests/Unit/Architecture/DesignTokenMirrorsTest.php` (widened `declaredTokenValues()` to scan all
of `app.css`, not just the light `@theme static` block — see Verification notes),
`resources/js/pages/Devtools/Design.test.tsx` (live panel count, 7→9),
`docs/design-tokens.md` (documents the new `CHART_GROUND` bridge).
`LineChart.tsx` and `aiusage/DailyChart.tsx` from the plan's original list needed no changes (see
above).

## Blockers

`F2`, merged.

## Acceptance criteria

- [x] Every real `PALETTE` consumer with a ground-dependent role (grid, tick, secondary stroke,
      primary ink-safe stroke) audited and fixed, not just the plan's originally-named files.
- [x] Bold graphic fills (`horizon`, `leaf`/`citrus`/`ember` band tints, HR-zone colors) confirmed
      unchanged and left alone — no unnecessary churn on values that already work both grounds.
- [x] `useIsChartDark()` correctly drives every chart's ground-aware config live, including the one
      file (`FitnessTrend`) whose plugin can't just re-render off a prop change.
- [x] `LapsGraph`/`SplitsTable`'s bar-fill bug (identical root cause, non-chart) fixed alongside.
- [x] `grounds.json` regenerated by hand for every new/dropped translucent panel call site.
- [x] Full ladder green: structure, `tsc`, full frontend suite, full PHP suite, `test:coverage`,
      `check-raw-palette.mjs`, `check-doc-citations.php`, `check:chunks`.
- [x] `browser-review` spot-check on both grounds for every chart type (2-series line, single-line,
      dashed secondary, bar): all legible, correct role swaps confirmed via cropped canvas/element
      screenshots, not just code review.

## Coverage delta

Frontend: 95.11%→95.05% functions, 95.47%→95.44% statements, 95.85%→95.82% lines — net flat within
noise (new hook + token file vs. the usual fixture churn), all three comfortably clear of the 95%
floor. Backend: `DesignTokenMirrorsTest.php`'s two tests still pass with the widened scan; no other
PHP touched.

## Verification notes

Full ladder run after implementation: `sail pest --group=structure --no-tia`,
`sail npx tsc --noEmit`, `sail npm run build && npm run check:chunks`, `sail npm run test` (full,
2034 tests), `sail bin pest --parallel --no-tia` (full PHP suite, 3627 tests),
`node scripts/check-raw-palette.mjs`, `sail php scripts/check-doc-citations.php`,
`sail npm run test:coverage`. All green.

**`DesignTokenMirrorsTest.php`'s `declaredTokenValues()` predates F2's two-ground system.** It only
ever scanned inside `@theme static { ... }` (the light ground) for `--color-*: #hex;` declarations,
so any hex a mirror file needs specifically for the *dark* value (e.g. `--color-text-3`'s dark
`#9c9ea7`) would fail as "not a token app.css declares" even though app.css genuinely declares it,
in the `[data-theme='dark']` block. Verified this was a real gap (not something to route around
with an `OFF_TOKEN` entry, which is reserved for genuinely non-token art colors) by confirming the
only hex declarations outside the two theme blocks are the four legitimate dawn-shift `--color-surface`
overrides — widening the scan to the whole file is safe and was the correct fix, not a workaround.

**Browser-review, both grounds, cropped canvas/element screenshots** (full-page screenshots of a
canvas are unreliable — Chart.js's draw animation can still be mid-flight, as F3's browser-review
verification already found once): `FitnessTrend` (2-series + dashed secondary), the `LoadTrend`
mini-charts (single solid line, single dashed line), `CtlTrendChart` (2-series + legend), and
`LapsGraph`'s bar fill all confirmed correct on both grounds — dark-olive/lime line swap, light/dark
grid and tick labels, and the previously-invisible-on-dark bar now rendering as a clearly visible
light bar. `VdotTrend`/`PaceConsistencyTrend` didn't render on the demo account used for the
check (grow-forward-only data, insufficient history) — their color roles are identical to the three
confirmed panels and covered by the same `chartTokens.test.ts` assertions, but their live rendering
specifically wasn't visually spot-checked.

## Open questions

- **`VdotTrend`/`PaceConsistencyTrend` weren't visually spot-checked live** (see above) — same code
  path as the three confirmed panels, low risk, but worth a glance once the demo seed has enough
  grow-forward history to render them.
- **The frosted-pill/marker "cutout" `pointBorder` role** (`ProgressionChart`'s point markers) was
  derived from `--color-card`'s light/dark pair on the assumption that's the surface the chart
  visually sits on — true everywhere charts are used today (all inside a `card`-toned panel), but
  worth confirming if a future screen slice ever drops a chart onto a different surface (`popover`,
  `muted`) without revisiting this.
