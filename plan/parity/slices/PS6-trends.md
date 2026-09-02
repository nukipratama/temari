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

- [x] The page renders exactly the prototype's four blocks, in its order.
- [x] No `FaceIcon` anywhere on Trends (P10).
- [x] The bottom nav is present with `trends` lit (P6) — unchanged from `PP1`.
- [x] P3: switching a range tab changes the narration, the chart window, the stat figures *and* the
      badge chips.
- [x] P15: every badge earned in the window renders, wrapping; nothing truncates to three.
- [x] P14: the chips are the only badge surface on this screen — no board, no gallery.
- [x] The legend sits below the chart, as the prototype draws it.
- [x] The streak survives as a chip inside the fitness panel, not as its own block (P25/P27).
- [x] P36: every card surface is `rounded-md`, via `cardVariants`.
- [x] Every renamed component keeps a co-located test; `StreakBadge`'s test goes with it.
- [x] `Trends.test.tsx` covers block order, the range tabs actually filtering, and the chip row.
- [x] Coverage delta recorded.
- [x] `./vendor/bin/sail composer check` green on the final tree.
- [x] `Trends.tsx`'s entry-chunk size reported against its `ROUTE_BUDGETS_KB` budget.

## Coverage delta

Measured against `epic/mobile-ux-port@6740e1a3` by running `npm run test:coverage` on that commit in
this worktree, rather than trusting a sibling slice's recorded figure — `PS3` and `PS11` recorded
theirs against different bases, so neither is this slice's base.

| | before | after |
|---|---|---|
| statements | 97.41% (3988/4094) | **97.55%** (3982/4082) |
| branches | 91.21% (3196/3504) | **91.29%** (3188/3492) |
| functions | 97.33% (1094/1124) | 97.32% (1091/1121) |
| lines | 97.68% (3797/3887) | **97.75%** (3796/3883) |

Up on three axes, flat-to-0.01-down on functions. The file count drops by one (`StreakBadge` and its
test go; nothing new is added, both new components are renames). Well clear of the 95% gate.

`Trends.tsx` chunk: **8.79 kB raw / 3.37 kB gzipped**. Trends carries no `ROUTE_BUDGETS_KB` entry —
`check:chunks` budgets only Login, Home, Runs/Show and Profile — and the guard passed unchanged.

## Verification notes

**P25's cuts verified, not redone.** `PP3` had already removed the milestones section, the badge
board, strain & monotony, VDOT/pace history and the personal-bests table: `components/trends/` held
only `NarrationHeadline`, `RangeToggle`, `StreakBadge` and `panels/FitnessTrend`, and a
case-insensitive grep for `milestone|monotony|strain|vdot|personal best|badge board|pace history`
across the page and its components now returns only the `badgeMilestones` prop name — the payload
that feeds the surviving chips, not a milestones section. `docs/features/records.md` records the
same cut from `PP3`'s side.

**But the page still drew five blocks, not four.** `PP3` left `StreakBadge` standing as its own card
("Badge board" eyebrow, "Streak" headline, one chip). P25 says exactly four, and `cut-list.md` §1's
Today row says the streak survives "only as the week-streak badge chip on Trends" (P27) — a chip,
not a board. It is folded into `FitnessPanel`'s chip row here, leading it, and its card is deleted.
This is the one P25 item that was *not* already done.

**`LineChart` survived the rename.** It is `lazy()`-imported by path
(`@/components/collection/LineChart`), so the component name never appears at the call site; the
import moved verbatim from `FitnessTrend.tsx` to `FitnessPanel.tsx` and `tsc` confirms it resolves.
`docs/architecture/frontend-architecture.md`'s `#L20` citation to the old path is re-pointed at
`FitnessPanel.tsx#L24`.

**No `FaceIcon`, deliberately.** The only path that could introduce one is the empty state, and
`EmptyPanel` defaults `face={false}`; it is not overridden here. P10 holds.

**`grounds.json` needed three edits**, all of which `DesignTokenContrastTest` named for me: the two
`horizon/0.25` call sites re-pointed onto `FitnessPanel.tsx` (`StreakBadge`'s entry dropped with the
file), `NarrationHeadline.tsx`'s `horizon/0.1` call site dropped (the old card's `bg-horizon/10`; `cardVariants`' `narration` tone is what paints it now), and `horizon/0.12` removed outright — the
badge detail panel was its only painter, and it is `bg-muted` now, following the prototype.

**Gate**: `./vendor/bin/sail composer check` green in one run — pint, eslint, the palette guard,
phpstan (0 errors), rector (0 changed files), `pest` 3640 passed / 10790 assertions, `vitest` 1809
passed, build + `check:chunks` within budget. No contention failure in this wave.

## Plan / prototype discrepancies found

1. **`reference.md` §8's "Explicitly absent" list is right about the prototype and silent about the
   shipped page.** It reads as a clean bill of health for the cut, and `PP3` had indeed done the
   five named cuts — but the block P25's count actually collided with was `StreakBadge`, which is
   named in neither §8's list nor `cut-list.md` §1's Trends row (it is only implied by the Today
   row's P27 note, two rows up and under a different screen). A slice reading §8 alone would have
   shipped five blocks and believed it had shipped four.

2. **No prototype/plan contradiction otherwise.** Every claim in §8's section, interaction and
   alternate-state tables checks out against `TrendsScreen.tsx`, including the reflow half `PP1`'s
   table records for this screen: the root at `:301` is the whole of it (`px-4`→`px-6`,
   `pt-16`→`pt-6`, `pb-22`→`pb-24`, 760px), and both halves are already carried by
   `PageContainer` + `appLayout`. Nothing screen-specific was left unbuilt.

## Open questions

1. **The regenerate control is a left-aligned text trigger, not the prototype's bottom-right pill.**
   The prototype's `NarrationCard` puts "regenerate" / "next read in 4h 12m" in a right-aligned
   muted pill (`TrendsScreen.tsx:163-178`). The app's equivalent — including the cooldown countdown,
   which is real here and hardcoded there — lives inside `AnalysisStatus`, which owns its own column
   and renders the trigger `self-start` as a small text button. Six ported screens already draw it
   that way, so changing it is a cross-screen treatment decision, not this slice's. Recorded rather
   than taken.

2. **Badge chips carry rarity in the medal's ink, not in the selected chip's background.** The
   prototype tints the selected chip with `color-mix(in oklab, var(--rarity-X) 18%, var(--muted))`.
   Reproducing that needs five new translucent grounds entries for a state only one chip is ever in;
   under P2 the selected state stays on the already-registered `bg-horizon/25` and the rarity signal
   rides on the `mdi:medal-outline` tint, which the prototype also tints per rarity. Cheap to
   revisit if the loss of the rarity wash reads as flat.

3. **The panel headline is derived in four branches, where the prototype hardcodes one line.**
   `fitnessVerdict()` maps the window's CTL climb and current form onto "Climbing, not spiking." /
   "Climbing, and carrying the load." / "Easing off." / "Holding steady." P1 requires real data, and
   a fixed "climbing, not spiking." would be a claim about a user who might be detraining. The
   thresholds (±2 CTL over the window) are a judgement call, not a measured one.

4. **A zero-week streak draws no chip at all.** The deleted `StreakBadge` had a "No streak yet"
   state with a first-run nudge. The prototype's chip row has no empty member, and a chip that says
   nothing was earned is not a badge — so the copy is gone from the product. Flagged because it is a
   small loss no decision asked for.

5. **`RunCard::allBadgeCountsForUser()` looks orphaned.** Its docblock says it exists "for the badge
   board", which P14 cut. Left alone — pre-existing dead code is `W2`'s sweep, not this slice's.
