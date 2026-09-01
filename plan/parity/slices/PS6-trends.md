# PS6 — Trends

Trends (`pages/Trends.tsx`) to prototype parity, against
[`TrendsScreen.tsx`](../../../resources/brand/prototype/src/components/pages/TrendsScreen.tsx) as
the source of truth and [reference.md](../reference.md) §8 as the cross-check.

Decisions in scope: **P25** (four blocks only), **P14** (badges surface in exactly two places, and
Trends' fitness-panel chips are one), **P15** (chips wrap, showing every earned badge), **P3** (the
range tabs really work), **P36** (card radius `rounded-md`), plus P1/P2/P5/P6 which apply
everywhere. **P10**: Trends is one of the three screens the prototype draws faceless — no
`FaceIcon`, and none is added.

## Goal

The four blocks `TrendsScreen.tsx` draws, in its order, at its treatment, populated with real data:

1. **header** — mono eyebrow "Trends", a serif italic h1 with its second line accent-italic, one
   supporting line.
2. **range tab bar** — three pills in a single `bg-muted` track, no label beside them.
3. **"Temari's read"** — the prototype's `NarrationCard`: a `Sparkles` eyebrow, a bold italic serif
   headline, an italic serif body, and the regenerate control at the bottom right.
4. **one fitness panel** — eyebrow + a derived headline + the CTL/ATL/form explainer, three stat
   tiles, the solid-CTL / dashed-ATL chart, a hand-built legend *below* the chart, then the badge
   chips and the tap-to-open detail panel.

Nothing else. P25's cuts (milestones, badge board, strain & monotony, VDOT/pace history, personal
bests) were delivered by `PP3` and are verified absent rather than redone — see *Verification
notes*.

## Files touched

| area | what |
|---|---|
| `resources/js/pages/Trends.tsx` | header copy trimmed to the prototype's single line, the "Range" label dropped, the fifth block removed, `streak` handed to the fitness panel |
| `resources/js/components/trends/RangeToggle.tsx` | the prototype's tab-bar proportions (full-width track, taller pills) |
| `resources/js/components/trends/NarrationCard.tsx` | renamed from `NarrationHeadline`; the prototype's `narration`-tone card, `Sparkles` eyebrow, bold-italic lead + italic body, trigger bottom-right; the one-shot "ignition ring" the prototype does not draw is dropped |
| `resources/js/components/trends/panels/FitnessPanel.tsx` | renamed from `FitnessTrend`; reordered to the prototype's stat-tiles → chart → legend → chips sequence, tiles become the prototype's value-over-label wells, the CTL area fill and the x-axis go, and the week-streak chip joins the badge chips |
| `resources/js/components/trends/StreakBadge.tsx` | deleted (+ its test) — P25's fifth block; P27 keeps the streak only as a chip |
| `app/Models/RunCard.php` | `firstEarnedDatesForUser` → `firstEarnedBadgesForUser`, now carrying the rarity of the card the badge was first earned on, so the chip's medal can be rarity-tinted from real data |
| `app/Http/Controllers/TrendsController.php` | the `badgeMilestones` payload gains `rarity` |
| co-located `*.test.tsx` | one per changed/renamed component, per the 1:1 convention |
| `tests/Unit/Models/RunCardTest.php`, `tests/Feature/Http/Controllers/TrendsControllerTest.php` | follow the payload change |
| `resources/brand/grounds.json` | regenerated for the re-toned panels |
| `docs/features/gamification.md`, `docs/architecture/frontend-architecture.md` | kept true in the same commits as the code |
| `plan/parity/README.md` | the `PS6` progress row |

## Blockers

None. `PP1` (the 900px/760px layer, already in `PageContainer`), `PP2` (`FaceIcon` — correctly
absent here) and `PP3` (P25's cuts) all landed first. `F6` owns the chart styling; this slice styles
it into the prototype's slot rather than redesigning it.

`LineChart` is `lazy()`-loaded **by path** from this panel and from Race, so it does not appear in a
grep for its component name. The import is preserved through the rename.

## Acceptance criteria

- [ ] The page renders exactly the prototype's four blocks, in its order.
- [ ] No `FaceIcon` anywhere on Trends (P10).
- [ ] The bottom nav is present with `trends` lit (P6) — unchanged from `PP1`.
- [ ] P3: switching a range tab changes the narration, the chart window, the stat figures *and* the
      badge chips.
- [ ] P15: every badge earned in the window renders, wrapping; nothing truncates to three.
- [ ] P14: the chips are the only badge surface on this screen — no board, no gallery.
- [ ] The legend sits below the chart, as the prototype draws it.
- [ ] The streak survives as a chip inside the fitness panel, not as its own block (P25/P27).
- [ ] P36: every card surface is `rounded-md`, via `cardVariants`.
- [ ] Every renamed component keeps a co-located test; `StreakBadge`'s test goes with it.
- [ ] `Trends.test.tsx` covers block order, the range tabs actually filtering, and the chip row.
- [ ] Coverage delta recorded.
- [ ] `./vendor/bin/sail composer check` green on the final tree.
- [ ] `Trends.tsx`'s entry-chunk size reported against its `ROUTE_BUDGETS_KB` budget.

## Coverage delta

_placeholder — filled after the gate run._

## Verification notes

_placeholder — filled after the gate run._

## Open questions

_placeholder — filled as they arise._
