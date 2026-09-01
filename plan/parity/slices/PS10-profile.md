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
- `resources/js/components/plan/SeasonHeaderCard.tsx` (+ test) — import updated for that move; the
  `phasesOf` cases moved to `lib/plan.test.ts` with the function.
- `resources/js/lib/pace.ts` — `daysUntilId()`, lifted out of `pages/Race.tsx` (its only previous
  home) so Profile's race row and Race's countdown share one definition.
- `resources/js/lib/chartTokens.ts` — `HR_ZONE_LABELS` beside `HR_ZONE_COLORS`;
  `settings/HrZonesDisclosure.tsx` now reads it instead of its own private copy, so the zone bar's
  legend and the zones editor name the bands identically.
- `resources/js/components/UserAvatar.tsx` — an `lg` size (44px) for Profile's own header circle.

### Frontend — deleted

- `resources/js/components/collection/ProgressionChart.tsx` (+ test). Profile was its only
  consumer, and the prototype draws a compact inline-SVG journey chart in that slot, not a Chart.js
  line chart with axes and a grid. A replacement in the same slot (PS8's `SplitsTable` →
  `SplitsChart` precedent), not a cut. **`LineChart.tsx` stays** — `trends/panels/FitnessTrend.tsx`
  and `race/CtlTrendChart.tsx` still `lazy()` it by path, which a grep for the component name
  misses.

### Backend

- `app/Services/Run/Metrics/TimeInZoneSummary.php` (+ test) — aggregates
  `activity_details.stream_summary.time_in_zone_min` over the trailing 12 weeks into Z1-Z5
  percentages. The one new class.
- `app/Http/Controllers/ProfileController.php` — adds the `timeInZone`, `season` and
  `seasonWeeks` props; re-adds `SeasonService::peekCurrent()` (never `ensureCurrent`) and
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
8. Every `composer check` step green, and `check:chunks` green with `Profile.tsx` under its 230 kB
   budget.

## Coverage delta

**Not measured locally — read it off this PR's CI run.** Three worktrees (`PS3`, `PS10`, `PS11`)
were live on one laptop for the whole of this slice; `npm run test:coverage` was started three
times and killed by contention each time before printing its totals. Every new file ships its
co-located test, and the full Vitest suite passes (1816 tests, 212 files), so the delta should be
flat-to-slightly-up — but that is a projection, not a measurement, and CI is the number to record
here when it lands.

## Verification notes

Every step of `composer check` was run and is green, but **not in one uninterrupted invocation** —
see the coverage note above for why. What ran, and its result:

| step | result |
|---|---|
| `typescript:enums --check` | up to date |
| doc-citation guard | all citations resolve |
| `{@see}` guard | all references resolve (caught one: `ActivityDetail::$stream_summary` is a `@property`, not a member) |
| `pint --test` | PASS, 898 files |
| `prettier --check` | PASS |
| `tsc` | PASS |
| `eslint --max-warnings 0` | PASS |
| `check:palette` | PASS, 446 files, zero off-token utilities |
| `phpstan analyse` | `[OK] No errors`, 409 files |
| `rector --dry-run` | `[OK]` **on the three changed PHP files**. The full-tree run deadlocked twice at `0/613` with orphaned parallel workers still bound to a dead main — the same shape as the known phpstan-parallel-cache race in Sail, and unrelated to this diff |
| `pest --group=structure` | 39/39 |
| `pest` on `tests/Feature/Profile` + the new unit test | 14/14 |
| `pest --parallel` (whole suite) | **3640 passed**, 10786 assertions, 571s |
| `vitest run` (whole suite) | 1816 passed / 212 files |
| `npm run build` + `check:chunks` | PASS, `Profile` 570.6 kB raw / **186.3 kB gzipped** against a 230 kB budget |

`grounds.json` needed two edits: `gradient-to-r` added as a `keyword` (the pace rail's gradient),
and the now-unpainted `foreground/0.06` panel entry dropped — it went with `ProgressionChart`.

## Open questions

1. **Does the season phase bar want `SeasonSummaryBuilder`'s full week list?** Profile only needs
   the distinct phase sequence and which one is current, but reusing the builder Plan already runs
   avoids a second, subtly different derivation. Reuse was chosen; if Profile's TTFB ever matters,
   a `phaseSequence()` on `PhaseSchedule` would be the cheaper read.
2. **Season goal wording.** The prototype's line is `62% to sub-50 10K` — a race-shaped goal. Our
   `SeasonGoal` titles are imperatives ("Nail your quality sessions"), so the line renders as
   `{pct}% · {title}`. If `PP4`'s demo seed wants the prototype's exact register, the titles are
   what would change, not this component.
