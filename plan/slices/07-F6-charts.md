# F6 — Charts, two grounds

**Wave** 1 · **Slot** worktree · **Blockers** `F2` · **Status** todo

## Goal

`collection/LineChart.tsx`, `ProgressionChart`, `race/CtlTrendChart.tsx`, `aiusage/DailyChart.tsx`,
`run/LapsGraph.tsx`, `lib/chartTokens.ts`. The prototype's `CHART_PALETTE` already ships both
halves as authored. **All chart work is confined here** — screen slices (`S1`-`S12`) do not redesign
charts; a screen slice that touches chart appearance is a finding against the engineer rubric.

## Files touched

`resources/js/components/collection/LineChart.tsx`, `ProgressionChart.tsx`,
`resources/js/components/race/CtlTrendChart.tsx`, `resources/js/pages/AiUsage/aiusage/DailyChart.tsx`,
`resources/js/components/run/LapsGraph.tsx`, `resources/js/lib/chartTokens.ts`.

## Blockers

`F2`.

## Acceptance criteria

_To be filled when wave 1 starts._

## Coverage delta

_To be filled when wave 1 starts._

## Verification notes

_To be filled when wave 1 starts. Load the `dataviz` skill before touching any chart — categorical
hue order, sequential/diverging rules, and the six-check palette validator all apply._

## Open questions

_To be filled when wave 1 starts._
