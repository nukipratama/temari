# PS3 — Today

Today (`pages/Home.tsx`) to prototype parity, against
[`TodayScreen.tsx`](../../../resources/brand/prototype/src/components/pages/TodayScreen.tsx) as the
source of truth and [reference.md](../reference.md) §5 as the cross-check.

Decisions in scope: **P27** (day-grained streak readout cut), **P29** (top-pick featured-card panel
cut), **P36** (card radius `rounded-md`), plus P1/P2/P3/P5/P6 which apply everywhere. The stats
disclosure renders **closed**, per the 2026-08-31 amendment (d) in
[README.md](../README.md) §6, which supersedes `V0` fork 4.

## Goal

The four sections `TodayScreen.tsx` draws, in its order, at its treatment, populated with real data:

1. **plan card** — phase badge, credited/total progress ring beside a two-figure stat grid, a
   seven-column day grid, a footer link to today's session; or **`NoPlanCard`** when the account has
   no plan yet (`planState: 'empty'`, which the shipped page did not draw at all).
2. **"you vs past you"** — mono eyebrow, serif accent headline, one supporting line, a hairline list
   of evidence rows.
3. **today message card** — `FaceIcon`, a "today" eyebrow, a bold italic lead and an italic body, on
   a `border-today-accent` card rather than the shipped dark sky panel.
4. **"this week's stats" disclosure**, rendering **closed** — a stat strip, three vital bars, and a
   two-column last-run / condition pair.

P27 and P29 were already delivered by `PP3`; both verified absent rather than redone (see
*Verification notes*).

## Files touched

35 files, +1,528 / −1,707.

| area | what |
|---|---|
| `resources/js/pages/Home.tsx` | rebuilt onto the prototype's section order and spacing rhythm; `NoPlanCard` wired to the `weekPlan === null` branch |
| `resources/js/components/home/WeekPlanWidget.tsx` | ring row is a row again (not a stacked column), ring carries its centre `credited/total` label, plain stat figures replace `StatTile` boxes, day cells lose their fill and pin glyph, footer row becomes a real link to `/plan` |
| `resources/js/components/home/NoPlanCard.tsx` | new — the prototype's `NoPlanCard` empty state |
| `resources/js/components/home/VerdictHero.tsx` | prototype eyebrow/headline/support treatment; the `FaceIcon` + "temari" byline is removed (the prototype draws none in this section) |
| `resources/js/components/home/EvidenceList.tsx` | prototype's row metrics and delta pill |
| `resources/js/components/home/TodaySession.tsx` | `sky` panel → `border-today-accent` card; face beside the eyebrow and lead |
| `resources/js/components/home/WeekStatsDisclosure.tsx` | new — the closed `Collapsible`, its summary trigger, and the stat strip |
| `resources/js/components/dashboard/VitalBars.tsx` | renamed from `VitalChips`; the 3-up gauge tiles become the prototype's three labelled bars (vibe / readiness / recovery) |
| `resources/js/components/dashboard/LastRunCard.tsx` | reworked to the prototype's "last run · yesterday" mini card |
| `resources/js/components/dashboard/TrainingLoadCard.tsx` | reworked to the prototype's "condition · 7 days" mini card |
| `resources/js/components/ui/MiniRow.tsx` | new — the label/value hairline row both mini cards are built from |
| `resources/js/pages/Home/helpers.ts` | the week-range label, weather/location formatters and training-load hint/tone helpers lost their last caller and go with the port |
| `app/Http/Controllers/DashboardController.php` | `lastRunNote` dropped (no consumer left), and the recent-run `select` trimmed to the columns Today still draws |
| `tests/Feature/Dashboard/DashboardControllerTest.php` | the polyline/stream assertion becomes a guard on the trimmed select |
| co-located `*.test.tsx` | one per changed/added component, per the 1:1 convention |
| `resources/brand/grounds.json` | regenerated for the renamed/re-toned panels |
| `docs/features/dashboard.md`, `docs/features/vibe-and-mood.md`, `docs/features/temari-mascot.md` | kept true in the same commits as the code |
| `plan/parity/reference.md`, `plan/parity/README.md` | the stale disclosure row (below) and the `PS3` progress row |

## Blockers

None. `PP1` (shell + the 900px/760px responsive layer), `PP2` (`FaceIcon` placements) and `PP3`
(P27's streak line, P29's top-pick panel) all landed first.

Inherited from `PS4`: `WeekPlanDay` gained `actual_km` and `activity`, so `Home.test.tsx`'s fixtures
already moved. `WeekPlanWidget` itself was untouched by that slice.

## Acceptance criteria

- [x] Page follows `TodayScreen.tsx`'s section list and order: plan card (or `NoPlanCard`), "you vs
      past you", today message card, "this week's stats" disclosure.
- [x] The disclosure renders **closed** (no `defaultOpen`), and everything inside it stays: stat
      figures, three vital bars, last-run card, condition card.
- [x] `NoPlanCard` renders when `weekPlan` is null, where the shipped page rendered nothing.
- [x] P27: no "N in a row" streak line anywhere on the page. P29: no featured-kartu panel.
- [x] P36: every card surface is `rounded-md`, via `cardVariants`.
- [x] Reflow #8 (`gap-4` → `gap-6` on the plan card's ring row) is carried, and its below-900 state
      is checked directly against the prototype rather than trusted from `PP1`'s accounting.
- [x] Every new or renamed component has a co-located test.
- [x] `Home.test.tsx` covers the section order, both plan states, and the disclosure being closed on
      first render and opening on click.
- [x] Coverage delta recorded.
- [x] `./vendor/bin/sail composer check` green on the final tree.
- [x] `Home.tsx`'s entry-chunk size reported against its `ROUTE_BUDGETS_KB` budget.

## Coverage delta

Measured against `PS4`'s recorded post-merge figures at `c0672c02`, which `7a8d54c9` (docs only)
leaves unchanged, so they are this slice's base:

| | before | after |
|---|---|---|
| statements | 97.30% (4048/4160) | **97.39%** (3958/4064) |
| branches | 90.80% (3318/3654) | **91.18%** (3177/3484) |
| functions | 96.77% (1082/1118) | **97.01%** (1071/1104) |
| lines | 97.61% (3841/3935) | **97.69%** (3771/3860) |

Up on all four. The denominator falls by 96 statements: the page and its cards got smaller, twelve
orphaned helpers went, and the four new/renamed components are fully covered. Well clear of the 95%
gate.

`Home.tsx` entry chunk: **611.1 kB raw / 200.8 kB gzipped** against its 240 kB `ROUTE_BUDGETS_KB`
budget. No re-baseline needed, and the path is untouched.

## Verification notes

**P27 and P29 verified, not redone.** `grep` across `pages/Home.tsx`, `components/home/` and
`components/dashboard/` finds no "in a row" line and no featured-kartu surface. The one `streak`
hit left is `briefing.streakLabel`, a *fallback label for the recovery value* ("Ran today") that
`BriefingComposer` has always supplied — not a day-grained streak readout.

**Reflow #8 was recorded as carried, but the layout under it was the wrong axis.** `reference.md`
§1.2 row 8 describes the plan card's ring/stats row going `gap-4` → `gap-6` at 900px, and `PP1` did
add `min-[900px]:gap-6`. It added it to `flex flex-col items-center` — a *stacked column*, where the
prototype has `flex items-center`, a row. The gap step was therefore carried onto a layout that was
not the prototype's, and changed the vertical rhythm rather than the ring/stats gutter. Fixed here;
the below-900 state is now the prototype's row with `gap-4`, as checked directly against
[TodayScreen.tsx:242](../../../resources/brand/prototype/src/components/pages/TodayScreen.tsx).
This is the second instance of the pattern `PS1` found, so it is worth the remaining slices
re-reading both halves of every reflow they own.

**`grounds.json` needed real work**, and `DesignTokenContrastTest` caught all of it: `bg-border`
was painted but unclassified (added as a `fill`, beside the `border-strong` already there), the
evidence delta pill's `bg-horizon/20` was an unregistered panel (moved onto the registered
`horizon/0.18`, which already accepts `icon-accent` text — P2 token-nearest, and 0.02 of alpha is
not worth a new entry), and four panels `VitalChips` was the last painter of now paint nothing and
were dropped.

**The gate needed two runs.** The first ended red on `rector`, with `Child process timed out after
120 seconds` from `easy-parallel` — the two sibling worktrees (`PS10`, `PS11`) were each running
`vitest --coverage` on the same box. Rector single-process (`--debug`) then passed with zero errors
and zero changed files, and the full `composer check` was re-run to green once the siblings went
idle. Worth knowing for a three-slot wave: rector's per-worker timeout is the first thing that
breaks under contention, and it fails in a way that looks like a code problem.

## Plan / prototype discrepancies found

1. **`reference.md` §5 contradicts its own §9 and the README amendment on the stats disclosure.**
   §5's Interactions table still reads "Decision P1 / `V0` fork 4 opens it by default in the shipped
   app - a recorded divergence", while §9 discrepancy 3, `cut-list.md` §2 and README §6 amendment (d)
   all say the prototype renders it **closed** and that closed is what ships. `TodayScreen.tsx:464`
   passes no `defaultOpen`, so the prototype wins; the §5 row is stale text left behind when the
   amendment landed, and is corrected in this slice.

## Open questions

1. **The phase badge ships without the prototype's `PhaseSparkline`.** The prototype's badge holds a
   four-bar trace of the season's per-phase average weekly km with the current phase lit
   ([TodayScreen.tsx:75-94](../../../resources/brand/prototype/src/components/pages/TodayScreen.tsx)).
   Today's payload (`CurrentWeekPlanBuilder::forUser`) carries one phase, not the arc, and the only
   real source for the arc is `SeasonSummaryBuilder::build()` — which rebuilds every week of the
   season through `WeekPlanBuilder` and is why the Plan page, not the dashboard, pays for it. Sizing
   the bars from a static per-phase constant instead would be fabricated data, which is exactly what
   `PS4` declined to do for past weeks' focus lines. So the badge draws the phase name and nothing
   else, recorded as a deliberate gap. Making it real is a payload decision (add a per-phase volume
   summary to the Home payload, and pay for it on every dashboard load), not a parity one.

2. **Day-cell status colours stay the app's, not the prototype's raw hex.** The prototype maps
   done → `icon-accent`, partial → `citrus`, missed → `destructive`, overreached → `#d97706`. The app
   has no amber distinct from `citrus`, so porting literally would collapse partial and overreached
   onto the same value. The existing `STATUS_TONE` map — the app's own validated port of the same
   compliance vocabulary, shared in spirit with Plan — is kept, on `PS4`'s precedent for
   `PHASE_COLORS`.

3. **The today card's body paragraph is indented under the face, where the prototype runs it full
   width.** The prototype puts the face beside the eyebrow and the lead, then drops the second
   paragraph to full width below. Both paragraphs come out of one `AnalysisStatus` `renderContent`
   slot, so they cannot straddle the flex row; keeping the face beside the *lead* (the stronger
   signature) costs the body a 54px left inset. The alternative — the whole card inside
   `renderContent` — would leave the pending and failed states with no face and no "Today" label at
   all, which P1 keeps.

4. **Three things the shipped cards drew are gone, and none is in `cut-list.md`.** The last run's
   name/location/weather chip and post-run note, the training-load card's plain-language hints and
   risk tones, and the **Monotony** row. All three follow from the prototype's mini cards, and none
   is lost product-wide: the note survives on History's run rows, the weather on activity detail's
   `MapWeatherPanel`, monotony as History's per-week alert. Flagged because a reviewer will notice
   the dashboard got quieter than the cut list promised.

5. **The metric-glossary explainers were kept on the vital-bar labels**, where the prototype has
   none. `vibe_vs_mood`, `form` and `recovery` have no other call site in the app, so dropping them
   with the tiles would have removed those glossary entries from the product — a feature loss no
   decision asked for. They fit the 76px label cell at the prototype's 9px mono.
