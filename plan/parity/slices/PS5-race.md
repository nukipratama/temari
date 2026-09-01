# PS5 — Race

Race (`pages/Race.tsx`) to prototype parity, against
[`RaceGoalScreen.tsx`](../../../resources/brand/prototype/src/components/pages/RaceGoalScreen.tsx)
as the source of truth and [reference.md](../reference.md) §7 as the cross-check.

Decisions in scope: **P26** (three blocks; the CTL/ATL chart is cut), **P36** (card radius
`rounded-md`), **P3** (a control the prototype wires to nothing gets the real behaviour), plus
P1/P2/P5/P6/P7 which apply everywhere. **P10**: Race is one of the eight screens that *do* carry a
`FaceIcon`, at the two placements `PP2` set — `RaceGoalScreen.tsx:205` (18px, beside the "projected
finish" label) and `:229` (40px, in the no-race empty state).

## Goal

The sections `RaceGoalScreen.tsx` draws, in its order, at its treatment, populated with real data:

1. **header** — mono eyebrow "Race", a serif italic h1 whose second line is accent-italic and whose
   copy branches on whether a race is set, one supporting line.
2. **schedule / race-goal tabs** — two pills in a single `bg-muted` track, *below* the intro.
3. **race card** *(race set)* — flag + name, `date · N days to go`, two value-over-label stat
   figures. **or the no-race empty state** *(race unset)* — the 40px `FaceIcon` card.
4. **projection block** *(race set)* — a horizon glow, the "projected finish" label with the 18px
   `FaceIcon`, the arc gauge, the predicted time, and the confidence line. Falls back to the "no
   personal record yet" copy when there is no projection to draw.
5. **goal form** — always rendered. Name, race day, distance presets + a custom-distance field,
   h/m/s goal time, the derived warning banner, and the set/update trigger.

Nothing else. P26's cut of the 90-day CTL chart was delivered by `PP3` and is verified absent
rather than redone — see *Verification notes*.

## Files touched

| area | what |
|---|---|
| `resources/js/pages/Race.tsx` | reordered to the prototype's section list (tabs move below the intro), header onto the `Eyebrow` + `PageHero` shell the other ported screens use, body extracted into the three prototype-named components |
| `resources/js/components/race/RaceCard.tsx` | new — the prototype's race summary card, with its value-over-label stat figures |
| `resources/js/components/race/ProjectionBlock.tsx` | new — the prototype's `ProjectionBlock`: horizon glow, `FaceIcon`, gauge, predicted time, confidence line, and the no-projection fallback |
| `resources/js/components/race/RaceGoalForm.tsx` | new — the form lifted out of the page onto the prototype's `RaceGoalForm` shape; the in-card label, the custom-distance row on its own line, a full-width pill trigger |
| `resources/js/components/race/PlanRaceTabs.tsx` | restyled onto the prototype's `ScheduleRaceTabs` segmented track (`HistoryNav`'s shape, which `PS7` ported from the same prototype element) |
| `resources/js/components/race/ProjectionGauge.tsx` | end-label type tier brought onto the prototype's bold foreground reading |
| `resources/js/components/ui/SectionTabs.tsx` | deleted (+ its test) — `PlanRaceTabs` was its last consumer, as `PP1` predicted |
| co-located `*.test.tsx` | one per new/changed component, per the 1:1 convention; `Race.test.tsx` keeps the page-level block-order and wiring coverage |
| `resources/brand/grounds.json` | surgical edits for the re-toned panels, driven by `DesignTokenContrastTest` |
| `docs/features/race-projection.md` | kept true in the same commit as the code |
| `plan/parity/README.md` | the `PS5` progress row, and a third data point on `PS12`'s empty-state entry |

## Blockers

None. `PP1` (the 900px/760px layer, already inside `PageContainer`), `PP2` (`FaceIcon`) and `PP3`
(P26's cut) all landed first.

`LineChart` is `lazy()`-loaded **by path** (`@/components/collection/LineChart`) from
`race/CtlTrendChart.tsx` and from Trends' `FitnessPanel`, so it never appears in a grep for its own
component name. Nothing in this slice removes either import.

## Acceptance criteria

- [ ] The page renders exactly the prototype's sections, in its order, with the tabs below the
      intro rather than above the eyebrow.
- [ ] No CTL/ATL chart on Race (P26), verified rather than re-cut.
- [ ] `FaceIcon` appears at exactly the prototype's two placements and nowhere else (P10).
- [ ] The bottom nav is present with **`plan`** lit (P6).
- [ ] P36: every card surface is `rounded-md`, via `cardVariants`.
- [ ] Every real behaviour the prototype leaves dead (name, race day, the save trigger) stays
      wired to the server (P3 — already satisfied before this slice; verified not regressed).
- [ ] Both warning banners' logic survives, and neither blocks submission.
- [ ] Every new component ships a co-located test; the deleted one takes its test with it.
- [ ] Coverage delta recorded, measured at both ends against `e8b3393f`.
- [ ] `./vendor/bin/sail composer check` green on the final tree.
- [ ] `npm run build` + `check:chunks` green.

## Coverage delta

Measured against `epic/mobile-ux-port@e8b3393f` by running `npm run test:coverage` on that commit
in this worktree, rather than trusting a sibling slice's recorded figure.

_(filled in at the end of the slice)_

| | before | after |
|---|---|---|
| statements | — | — |
| branches | — | — |
| functions | — | — |
| lines | — | — |

## Verification notes

_(filled in as the slice runs)_

## Open questions

_(filled in as the slice runs)_
