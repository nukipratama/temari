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
   figures. **or the no-race empty state** *(race unset)* — the face-on-top empty card.
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
| co-located `*.test.tsx` | one per new/changed component; `Race.test.tsx` drops the form assertions that moved to `RaceGoalForm.test.tsx` and keeps the page-level section-order, state-branch and `FaceIcon`-placement coverage |
| `resources/brand/grounds.json` | three surgical edits, all named by `DesignTokenContrastTest` |
| `plan/parity/README.md` | the `PS5` progress row, and a third data point on `PS12`'s empty-state entry |

## Blockers

None. `PP1` (the 900px/760px layer, already inside `PageContainer`), `PP2` (`FaceIcon`) and `PP3`
(P26's cut) all landed first.

`LineChart` is `lazy()`-loaded **by path** (`@/components/collection/LineChart`) from
`race/CtlTrendChart.tsx` and from Trends' `FitnessPanel`, so it never appears in a grep for its own
component name. Nothing in this slice removes either import.

## Acceptance criteria

- [x] The page renders exactly the prototype's sections, in its order, with the tabs below the
      intro rather than above the eyebrow.
- [x] No CTL/ATL chart on Race (P26), verified rather than re-cut, and asserted in `Race.test.tsx`.
- [x] `FaceIcon` appears at exactly the prototype's two placements and nowhere else (P10) — one per
      race state, asserted both ways.
- [x] The bottom nav is present with **`plan`** lit (P6) — `NAV_SCREENS` unchanged from `PP1`.
- [x] P36: every card surface is `rounded-md`, via `cardVariants`.
- [x] Every real behaviour the prototype leaves dead (name, race day, the save trigger) stays
      wired to the server (P3 — already satisfied before this slice; verified not regressed).
- [x] Both warning branches survive, and neither blocks submission.
- [x] Every new component ships a co-located test; the deleted one takes its test with it.
- [x] Coverage delta recorded, measured at both ends against `e8b3393f`.
- [x] `./vendor/bin/sail composer check` green on the final tree.
- [x] `npm run build` + `check:chunks` green.

## Coverage delta

Measured against `epic/mobile-ux-port@e8b3393f` itself, not a sibling's recorded figure. The
baseline run is a `git archive` of `e8b3393f` extracted under `storage/app/ps5-base` and run with
`vitest --coverage --root`, so it is the same container, the same `node_modules` and the same
config as the "after" run.

| | before | after |
|---|---|---|
| statements | 97.55% (3986/4086) | **97.63%** (3961/4057) |
| branches | 91.39% (3176/3475) | **91.48%** (3147/3440) |
| functions | 97.33% (1094/1124) | 97.32% (1092/1122) |
| lines | 97.76% (3799/3886) | **97.84%** (3775/3858) |

Up on statements, branches and lines; flat-to-0.01-down on functions. Test files 217 → 219 (three
new race components, `SectionTabs`' test gone), tests 1823 → 1827. Well clear of the 95% gate.

`Race` chunk: **9.22 kB raw / 3.24 kB gzipped**. Race carries no `ROUTE_BUDGETS_KB` entry —
`check:chunks` budgets only Login, Home, Runs/Show and Profile — and the guard passed with all four
inside budget.

## Verification notes

**P26's cut verified, not redone.** `Race.tsx` at `e8b3393f` imported no chart and drew no
CTL/ATL section; a grep for `CtlTrendChart` across `resources/js` returns only the component's own
file and its test. `docs/features/race-projection.md`'s "No fitness trend here any more" records
the same cut from `PP3`'s side and is still accurate, so it needed no edit.

**But `cut-list.md` §1's Race row is wrong about where that chart went.** It says "The chart itself
survives — it is Trends' fitness chart". It is not: `PS6` built `FitnessPanel` with its own
`lazy(() => import('@/components/collection/LineChart'))` rather than reusing `CtlTrendChart`, so
`components/race/CtlTrendChart.tsx` now has **no consumer anywhere**. Left standing rather than
deleted — pre-existing dead code is `W1`/`W2`'s sweep, and `PS10` left `ProgressionChart` the same
way — but recorded so the sweep knows it is genuinely orphaned rather than "surviving on Trends".

**`LineChart` survived.** Both lazy-by-path imports (`FitnessPanel.tsx:24`, `CtlTrendChart.tsx:17`)
are byte-unchanged and `tsc` resolves them; nothing in this slice made the module look orphaned.

**`FaceIcon` placements checked against source, not assumed.** `RaceGoalScreen.tsx:205` is the 18px
face inside `ProjectionBlock`'s label row and `:229` the 40px face in `NoRaceState` — exactly the
two `PP2` set, so the brief was right this time. Both are ported, and `Race.test.tsx` asserts one
face per race state so a third can't creep in.

**P6 verified, not re-done.** `lib/nav.ts`'s `NAV_SCREENS` already maps `Race → 'plan'`, with a
comment recording why the map is keyed by Inertia component rather than URL prefix. Untouched.

**`PP1`'s reflow entry for Race is correct at both halves.** `@min-[900px]:` occurs exactly once in
`RaceGoalScreen.tsx` — the root at `:438` (`px-4`→`px-6`, `pt-16`→`pt-6`, `pb-22`→`pb-24`, 760px) —
and nowhere in `ScheduleRaceTabs.tsx` or `AiReplanPill.tsx`, the two components Race pulls in.
`PageContainer` + `AppShell` already carry all of it. Nothing screen-specific was left unbuilt.

**P3 needed nothing.** The prototype leaves the name input, the race-day input and the save trigger
dead (`reference.md` §7 records all three), but the shipped page already had them controlled and
posting to `POST /race`; the slice preserves that wiring verbatim through the extraction, and
`RaceGoalForm.test.tsx` asserts the payload, the null-name case, the `min` floor on race day and the
in-flight state.

**`grounds.json` took three surgical edits, no re-sort**, each named by `DesignTokenContrastTest`:
`ember/0.08`'s call site re-pointed from `pages/Race.tsx` to `components/race/RaceGoalForm.tsx`, and
`SectionTabs`' four registrations dropped with the file (`cream/0.1` and `sky/0.06` lose one call
site each; `cream/0.2` and `sky/0.15` had no other painter and go entirely).

**Two dead `data-coachmark` attributes dropped.** `race-goal` and `race-form` anchored nothing:
`CoachMark` binds by `anchorRef`, not by selector, and `docs/features/onboarding.md` lists the four
mounted marks — none on Race. Removed with the sections they were attached to.

**Gate**: `./vendor/bin/sail composer check` green in one run — the doc-citation and `{@see}`
guards, pint, prettier, `tsc`, eslint, the palette guard, phpstan (0 errors), rector (0 changed
files), `pest --parallel` 3653 passed / 10827 assertions, `vitest` 219 files passed, build and
`check:chunks` inside budget. No contention failure in this wave.

## Plan / prototype discrepancies found

1. **`cut-list.md` §1's Race row mis-states where the CTL chart went** (above). The cut itself was
   done correctly by `PP3`; the claim that the component "survives — it is Trends' fitness chart"
   was overtaken by `PS6` building its own chart, and nothing updated the row.

2. **`reference.md` §7 is accurate throughout.** Every line reference in its section, interaction
   and alternate-state tables resolves to what it claims in `RaceGoalScreen.tsx`, including the
   "explicitly absent" note — the file imports `Flag`, `TriangleAlert`, `FaceIcon`, `useCountUp`,
   `raceProgress`, `cn`, `AiReplanPill` and `ScheduleRaceTabs`, and nothing chart-shaped. Its
   section list also covers the screen's *whole* return, so unlike §8 on Trends there is no
   unlisted block to trip over.

## Open questions

1. **The prototype's `AiReplanPill` cooldown has no referent on Race, so it is not ported.**
   `RaceGoalScreen.tsx:399-414` swaps the save trigger for an inert "next in 5h 40m" pill when
   `aiReplanState === 'cooldown'` — the mockup conflates "set my race" with "replan my season". In
   the app they are two routes: `POST /race` (no rate limit, no cooldown; `RaceGoal` has no hook
   that regenerates a plan) and `POST /plan/regenerate`, which *does* have a real cooldown and
   already draws its own countdown on Plan. Adding a cooldown here would be a false-hope
   affordance for a limit that does not exist. Flagged rather than invented.

2. **The prototype's two warning tints collapse to one.** It tints the world-record-pace warning
   amber (`#d97706`) and the ahead-of-your-range warning `citrus`; the app draws both on
   `ember/0.08` + `ember-ink`, as it already did. `citrus` is reserved for PR / legendary
   celebrations by the design system, and the two warnings are mutually exclusive anyway
   (`paceIsImplausible` suppresses the personalized one), so only one is ever on screen. Under P2
   the guard wins over the literal hue.

3. **The intro copy promises a fitness trend this screen no longer draws.** "…then tracks your
   fitness trend against it" is the prototype's own line (`:462-465`) and it draws no chart either,
   so it is carried verbatim — the claim is true, it just resolves on Trends. Worth a copy pass if
   the promise reads as a missing block.

4. **The primary CTA is not ground-reactive, and that is app-wide.** The prototype's
   `--btn-primary-bg` is `horizon-ink` + white text on the light ground and `horizon` +
   `sky-deep` on the dark one; `PillButton tone="horizon"` is `bg-horizon text-sky` on both, and
   `bg-btn-primary-bg` has no `grounds.json` registration. Every ported screen's primary button is
   in the same position, so this is a `PS12`-shaped call, not a Race one. Not raised there yet
   because it is a token question rather than a treatment one — say the word and it becomes a
   third row.

5. **`components/race/CtlTrendChart.tsx` is dead** (see *Verification notes*). Left for `W1`/`W2`.
