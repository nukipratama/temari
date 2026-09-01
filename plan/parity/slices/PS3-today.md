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

- [ ] Page follows `TodayScreen.tsx`'s section list and order: plan card (or `NoPlanCard`), "you vs
      past you", today message card, "this week's stats" disclosure.
- [ ] The disclosure renders **closed** (no `defaultOpen`), and everything inside it stays: stat
      figures, three vital bars, last-run card, condition card.
- [ ] `NoPlanCard` renders when `weekPlan` is null, where the shipped page rendered nothing.
- [ ] P27: no "N in a row" streak line anywhere on the page. P29: no featured-kartu panel.
- [ ] P36: every card surface is `rounded-md`, via `cardVariants`.
- [ ] Reflow #8 (`gap-4` → `gap-6` on the plan card's ring row) is carried, and its below-900 state
      is checked directly against the prototype rather than trusted from `PP1`'s accounting.
- [ ] Every new or renamed component has a co-located test.
- [ ] `Home.test.tsx` covers the section order, both plan states, and the disclosure being closed on
      first render and opening on click.
- [ ] Coverage delta recorded.
- [ ] `./vendor/bin/sail composer check` green on the final tree.
- [ ] `Home.tsx`'s entry-chunk size reported against its `ROUTE_BUDGETS_KB` budget.

## Coverage delta

_To be filled after the gate runs._

## Verification notes

_To be filled after the gate runs._

## Plan / prototype discrepancies found

1. **`reference.md` §5 contradicts its own §9 and the README amendment on the stats disclosure.**
   §5's Interactions table still reads "Decision P1 / `V0` fork 4 opens it by default in the shipped
   app - a recorded divergence", while §9 discrepancy 3, `cut-list.md` §2 and README §6 amendment (d)
   all say the prototype renders it **closed** and that closed is what ships. `TodayScreen.tsx:464`
   passes no `defaultOpen`, so the prototype wins; the §5 row is stale text left behind when the
   amendment landed, and is corrected in this slice.

## Open questions

_To be filled during implementation._
