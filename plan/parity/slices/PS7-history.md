# PS7 — History

**Program** prototype parity · **Slot** 2 (worktree, concurrent with one sibling slice) ·
**Blockers** `PP0`-`PP3`, `C1` · **Status** in-review

## Goal

Rebuild `/history` — both `?view=` halves — to the prototype's section list, order and treatment at
P2 fidelity, against
[HistoryScreen.tsx](../../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx),
cross-checked with [reference.md](../reference.md) §9 rather than implemented from it.

The decisions this slice owns:

- **P35** — `Activities/Feed` and `Activities/Calendar` are *not* pushed screens. `History.tsx`
  renders them behind `?view=`, so they inherit History's nav chrome; History is one of P6's five
  bottom-nav screens. Nothing to build — verified, not re-decided.
- **P12** — the calendar's **kartu badge** survives. It lives inside a week row's *expanded
  narration disclosure*
  ([:649-661](../../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx)), never on
  a day cell.
- **P3** — "load older weeks" must genuinely page. The prototype swaps in a second hardcoded array
  ([:547-562](../../../resources/brand/prototype/src/components/pages/HistoryScreen.tsx)); the
  shipped app was only unhiding weeks it had already fetched. Both are mockup-grade; this slice
  makes it a real server round trip.
- **P36** — cards are `rounded-md` (14px). The prototype's `rounded-[6px]` day cells and
  `rounded-[10px]` disclosure are *not* cards; they snap exactly onto `--radius-xs` / `--radius-sm`.

Plus P1/P2/P5/P6 as they apply everywhere, and the standing rule: every plan claim verified against
prototype source before it was built.

## Files touched

### Backend

| file | what |
|---|---|
| `app/Http/Requests/FeedFilterRequest.php` | `weeks()` accessor — the P3 page cursor, normalising like every other accessor on this request (clamped, never rejected) |
| `app/Services/Run/FeedQuery.php` | `weekWindow()` — resolves the Monday that the Nth-most-recent run-bearing week starts on, plus whether anything older exists |
| `app/Http/Controllers/HistoryController.php` | list props page by week window instead of a 365-run cap; calendar props gain the grid's weekly snapshots (narration + chips) so week rows can disclose |
| `app/Actions/Run/BuildCalendarCellsAction.php` | each cell carries the day's kartu `rarity`, so the week disclosure can show P12's badge |
| `app/Services/Run/PostRunNoteReader.php` | `speechForToday()` removed — the calendar's today cell no longer carries a quote (the prototype's does not) |

### Frontend — new

| file | what |
|---|---|
| `resources/js/components/history/HistoryHeader.tsx` | eyebrow + headline + tab nav, shared by both views (the prototype draws one header above its tab switch, decl 752-787) |
| `resources/js/components/history/WeeklyStatusChips.tsx` | lifted out of `WeekSection` so the calendar's week disclosure renders the same chips |
| `resources/js/components/history/CalendarWeekRow.tsx` | the prototype's `WeekRow` (decl 567): week-cell button + 7 day boxes + the expandable narration disclosure carrying P12's kartu badge |

### Frontend — changed

- `resources/js/pages/Activities/Feed.tsx` — header via `HistoryHeader`; "load older weeks" is a
  real Inertia visit with the prototype's `ChevronDown`; the client-side reveal is gone.
- `resources/js/pages/Activities/Calendar.tsx` — header via `HistoryHeader`; the single bordered
  card grid becomes the prototype's per-week box grid; legend loses its hint column.
- `resources/js/pages/Activities/useCalendar.ts` — week rows carry mood + rarity.
- `resources/js/components/history/WeekSection.tsx` — chips import.
- `resources/js/components/history/InlineNote.tsx` — `RunsTruncatedNote` removed (paging replaces
  the cap it explained).
- `resources/js/lib/mood.ts` — `MOOD_HINT` removed; the legend was its only consumer.

Each new component ships its co-located `*.test.tsx`; every changed test moves with its subject.

## Blockers

None outstanding. Two things were checked and cleared before writing code:

1. **`S7`'s cuts.** Feed filters and the journey strip were cut by `S7`, orphaning
   `ActiveFilterChips`, `ResumeFilterChip`, `useLastFilter` and the Calendar mood filter. Verified:
   all four are already gone from the tree. What survives is `FeedFilters`' range/week *windowing*
   (not a user-facing control — its own docblock says so) and the three `InlineNote` banners.
2. **`FaceIcon` placement.** The brief named `RecapCard` at 36 and `EmptyRunsState` at 72.
   `RecapCard` at 36 is correct; `EmptyRunsState` is **not on this screen** — see *Open questions*.

## Acceptance criteria

1. Both `?view=` halves render one identical header: the prototype's eyebrow, the two-line
   `every run / has a story.` headline, then the tab pill.
2. Feed shows two week sections; "load older weeks" issues a real request that returns more weeks,
   and the button disappears once the oldest run is on screen.
3. `?weeks=` survives a reload, is clamped, and never widens past the range/week window.
4. Calendar renders the prototype's grid: a weekday header above the grid, then one row per week
   made of a week-summary button and seven bordered day boxes carrying only the day number and a
   mood dot.
5. Expanding a week row reveals the narration line, the weekly chips, and — when that week earned
   one — the kartu badge, tinted by rarity. A week with no narration is disabled and dimmed.
6. The AI pending / failed / retry states still render inside both recap surfaces (P1).
7. `./vendor/bin/sail composer check` green; coverage does not regress.

## Coverage delta

_(placeholder — filled after the gate run)_

## Verification notes

_(placeholder — filled after the gate run)_

## Open questions

_(placeholder — filled during implementation)_
