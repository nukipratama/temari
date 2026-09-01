# PS10 — Profile

**Program** prototype parity · **Slot** 2 (worktree, concurrent with two sibling slices) ·
**Blockers** `PP0`-`PP3`, `C1` · **Status** in-review

## Goal

Rebuild `/profile` to the prototype's section list, order and treatment at P2 fidelity, against
[ProfileScreen.tsx](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx) —
cross-checked with [reference.md](../reference.md) §12, not implemented from it.

The three decisions this slice owns:

- **P13** — the persona mix `PP3` deleted is replaced, in the same hero slot, by a **Z1-Z5
  time-in-zone bar with a legend** ([:115-140](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)).
- **P24** — the five-row `SeasonStreakPanel` `PP3` deleted is replaced by the prototype's small
  **`SeasonCard`**: phase bar plus one progress line ([:222-283](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)).
- **P15** — badge chips wrap rather than truncate. **Not applicable on this screen**; see
  *Blockers* below.

Plus P1/P2/P3/P5/P6/P7 and P36 as they apply everywhere, and the standing verification rule: every
plan claim checked against prototype source before it was built.

## Files touched

### Frontend — new

| file | what |
|---|---|
| `resources/js/components/profile/ProfileHero.tsx` | the prototype's `HeroPanel` (decl 64): halo blob, `FaceIcon` + eyebrow + est. date, the desktop-only "with temari since" block, the narration quote, the zone bar, the divider, the scrolling stat row |
| `resources/js/components/profile/TimeInZoneBar.tsx` | P13's segmented Z1-Z5 bar + dot legend (decl 15/118/127) |
| `resources/js/components/profile/RaceCard.tsx` | `HasRaceCard` / `NoRaceCard` (decl 189 / 166) as one component branching on the shared `activeRace` prop |
| `resources/js/components/profile/SeasonCard.tsx` | P24's phase bar + progress line, and the `planState === 'empty'` CTA (decl 222) |
| `resources/js/components/profile/PaceTargetsCard.tsx` | the gradient pace-target rail with four markers (decl 285) |
| `resources/js/components/profile/ProgressionCard.tsx` | distance pills, "journey · X", then/now, quote, chips, chart (decl 486) |
| `resources/js/components/profile/JourneyChart.tsx` | the SVG polyline/area chart with clickable points and a clamped tooltip (decl 330) |

Each ships its co-located `*.test.tsx` per the 1:1 convention.

### Frontend — changed

- `resources/js/pages/Profile.tsx` — reduced to the prototype's seven-section column.
- `resources/js/components/ui/Icon.tsx` — one new key, `mdi:speedometer` → lucide `Gauge`, for the
  VDOT stat tile the prototype draws with `Gauge` ([:27](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)).
- `resources/js/lib/plan.ts` — `phasesOf()` moved here from `components/plan/SeasonHeaderCard.tsx`;
  Plan and Profile both derive a phase bar from the same season weeks, so the pure function belongs
  beside `SeasonSummaryWeek` and `PHASE_LABEL` rather than inside one of its two consumers.
- `resources/js/components/plan/SeasonHeaderCard.tsx` (+ test) — import updated for that move.
- `resources/js/types/inertia.ts` — `timeInZone` typing where it is shared.

### Frontend — deleted

- `resources/js/components/collection/ProgressionChart.tsx` (+ test) and its lazy
  `LineChart.tsx` (+ test). Profile was their only consumer, and the prototype draws a compact
  inline-SVG journey chart in that slot, not a Chart.js line chart with axes and a grid. A
  replacement in the same slot (PS8's `SplitsTable` → `SplitsChart` precedent), not a cut.

### Backend

- `app/Services/Run/Metrics/TimeInZoneSummary.php` (+ test) — aggregates
  `activity_details.stream_summary.time_in_zone_min` over the trailing 12 weeks into Z1-Z5
  percentages. The one new class.
- `app/Http/Controllers/ProfileController.php` — adds the `timeInZone`, `season` and
  `seasonSummary` props; re-adds `SeasonService::peekCurrent()` (never `ensureCurrent`) and
  `SeasonStreakSummaryBuilder`, both removed by `PP3` with the panel that consumed them.
- `docs/features/profile.md` — the doc already names `PS10` as the owner of both re-adds; brought
  in line with what shipped.

## Blockers

None that stopped work. Three things resolved by reading prototype source:

1. **P15 has no surface on this screen.** The brief assigns it to `PS10`, but the prototype's
   Profile draws no badge chips: per [cut-list.md](../cut-list.md) §2 the two surviving badge
   surfaces are Trends' fitness-panel chips (`PS6`) and Inbox's unlock rows (`PS9`). The only chip
   row Profile draws is `ProgressionCard`'s pair of *stat* chips
   ([:514-521](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)) — a
   `−4:25 total` and a goal chip, not badges. They already wrap (`flex flex-wrap`), and the port
   keeps that, but nothing here is P15's divergence. **P15 belongs to `PS6` and `PS9`.**
2. **Reflow #9's below-900 state is genuinely "nothing".** Unlike Login's reflow #5, the
   `@min-[900px]:` block at [:100-107](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)
   is a plain `hidden` → `block` toggle with no alternate treatment underneath. What the mobile
   width *does* draw, and what the shipped app was missing, is the separate always-visible
   `est. <date>` line at [:93-98](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx),
   which sits under the hero eyebrow at every width. Both are built.
3. **The Profile hero is a card, not a sky panel.** The prototype's `HeroPanel`
   ([:66](../../../resources/brand/prototype/src/components/pages/ProfileScreen.tsx)) is `bg-card`
   with a `border-strong` ring and a horizon halo, not the app's fixed sky-gradient
   `components/ui/HeroPanel`. Same finding `PS8` made for activity detail, resolved the same way.

## Acceptance criteria

1. The page renders the prototype's seven sections in its order: eyebrow → header row (h1 +
   avatar) → hero → race card → season card → pace card → progression card.
2. The hero draws, in order: the halo, the `FaceIcon` row with the est. date, the wide-only "with
   temari since" block, the narration quote, the time-in-zone label + bar + legend, the divider,
   and the horizontally scrolling five stat tiles.
3. The Z1-Z5 bar is real data — trailing-12-week zone minutes from ingested runs — and the whole
   block is absent when no run in the window recorded heart rate.
4. `SeasonCard` renders the phase bar and one goal progress line when a season exists, and the "no
   season yet / start one on Plan" CTA when it does not. Visiting Profile never creates a season.
5. The race card shows name, distance, date and a days countdown when `activeRace` is set, and the
   "got a race coming up?" prompt when it is not.
6. The distance pills switch the journey chart (P3), and the chart's point markers toggle a
   tooltip that stays inside the chart's bounds.
7. Chrome is unchanged: back chevron to Today, gear to Settings, no bottom nav.
8. `composer check` green; `check:chunks` green with `Profile.tsx` under its 230 kB budget.

## Coverage delta

Frontend line coverage before → after: **97.30% → 97.33%** (`npm run test:coverage`).

## Verification notes

- `./vendor/bin/sail composer check` — the whole gate, green.
- `./vendor/bin/sail npm run build && ./vendor/bin/sail npm run check:chunks` — `Profile` first
  paint recorded below.
- `grounds.json` needed no new entry: the hero moves onto `bg-card`, which is already classified
  `paper`, and no new `bg-*` utility is introduced.

## Open questions

1. **Does the season phase bar want `SeasonSummaryBuilder`'s full week list?** Profile only needs
   the distinct phase sequence and which one is current, but reusing the builder Plan already runs
   avoids a second, subtly different derivation. Reuse was chosen; if Profile's TTFB ever matters,
   a `phaseSequence()` on `PhaseSchedule` would be the cheaper read.
2. **Season goal wording.** The prototype's line is `62% to sub-50 10K` — a race-shaped goal. Our
   `SeasonGoal` titles are imperatives ("Nail your quality sessions"), so the line renders as
   `{pct}% · {title}`. If `PP4`'s demo seed wants the prototype's exact register, the titles are
   what would change, not this component.
