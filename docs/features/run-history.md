---
title: Run history (Feed & Calendar)
description: The activity archive — weekly snapshots on the Feed view, the prototype's week grid on the Calendar view, both behind one /history route. Filters and the journey strip were cut in the mobile-UX port (S7); PS7 ported the screen to prototype parity.
tags: [feature, runs]
status: living
reviewed: 2026-09-01
code_refs:
  - resources/js/pages/History.tsx
  - resources/js/pages/Activities/Feed.tsx
  - resources/js/pages/Activities/Calendar.tsx
  - app/Http/Controllers/HistoryController.php
  - app/Http/Requests/FeedFilterRequest.php
  - app/Services/Run/FeedQuery.php
  - app/Services/Run/FeedFilters.php
  - resources/js/pages/Activities/weekBuckets.ts
  - resources/js/pages/Activities/useCalendar.ts
  - resources/js/components/history/HistoryHeader.tsx
  - resources/js/components/history/HistoryNav.tsx
  - resources/js/components/history/RecapCard.tsx
  - resources/js/components/history/WeekSection.tsx
  - resources/js/components/history/WeeklyStatusChips.tsx
  - resources/js/components/history/CalendarWeekRow.tsx
  - resources/js/components/history/InlineNote.tsx
---

# Run history (Feed & Calendar)

The run-history area is the user's whole running archive, split into two views
that share a header tab strip. Both views are one destination, `/history`
(`HistoryController::index`), not two routes: `?view=calendar` selects the
month grid, any other value (including absent) renders the chronological list.
Each view's props are built independently server-side, so switching never pays
for the other view's queries. [History.tsx](../../resources/js/pages/History.tsx)
is a thin switch between the real [Feed.tsx](../../resources/js/pages/Activities/Feed.tsx)
and [Calendar.tsx](../../resources/js/pages/Activities/Calendar.tsx) page
components based on the `activeView` prop the controller ships.
[HistoryHeader](../../resources/js/components/history/HistoryHeader.tsx) is the
top fold both views render: the lifetime-count eyebrow, the two-line headline,
and [HistoryNav](../../resources/js/components/history/HistoryNav.tsx) — the
Feed⇄Calendar switcher (a real `Link` pill, not client-side state) linking
`/history` and `/history?view=calendar`. The prototype draws one header above
its tab switch, so both pages share this one rather than composing their own;
they had drifted apart while each owned a copy.

**Navigation:** `route('history')` → `/history` (list by default);
`route('history', ['view' => 'calendar'])` → `/history?view=calendar`. Named
route: `history`. The former `/activities` (bare index) and `/calendar` routes
were retired outright, not redirected — `/activities/{activity}` (the run
detail page) is unaffected and still resolves via `RunController::show`.

## System dependencies

- **AI narration** — weekly and monthly recaps come from the [[ai-pipeline]] via `AnalysisType::WeeklyRecap` / `MonthlyRecap`.
- **Recap windowing** — the open week/month is window-gated ([[deferred-recap-windowing]]); recaps are [[chained-narration|chained]].
- **Training metrics** — `WeeklySnapshot` payloads carry CTL/ATL/form from [[training-load-metrics]].
- **Data model** — the shape of `Activity`, `ActivityDetail`, `WeeklySnapshot` is in [[data-model]].

## S7 — feed filters and the journey strip were cut

The mobile-UX port's reconciliation ledger ruled both **cut**, not restyled
(the prototype's own History mockup has no filter popover and no journey-strip
concept): `HistoryFilter.tsx`/`HistoryTabs.tsx`, `useFeedFilters.ts`,
`ActiveFilterChips.tsx`, `ResumeFilterChip.tsx`, `useLastFilter.ts` (orphaned by
the same cut) and `components/activities/JourneyStrip.tsx` are gone, along with
the mood/distance/rarity/sort surface of `FeedFilters.php`/`FeedQuery.php`/
`FeedFilterRequest.php` and `HistoryController::buildJourneyMatch`. The
Calendar's own client-side mood-dim filter rode the same `HistoryFilter`
popover and was cut for the same reason (`useCalendar.ts` no longer exposes
`moodFilter`/`toggleMood`/`resetFilter`/`isFilteredOut`).

**What survived the cut, because it isn't a filter:** the `?week=` deep link
from the weekly-recap Telegram notification (`AnalysisMessagePresenter`) still
pins the Feed to one week — that's server-driven windowing triggered by an
external link, not a user-facing filter control. `FeedFilters`/`FeedQuery` keep
`range`/`rangeAutoWidened`/`rangeStart`/`week`; the auto-widen-to-reach-the-
newest-run behaviour is unchanged, it's just no longer user-selectable (no
range chip UI).

`groupByWeek`/`WeekBucket`/`RunWithDetail` (real, filter-independent bucketing
logic) moved out of the deleted `useFeedFilters.ts` into their own module,
[weekBuckets.ts](../../resources/js/pages/Activities/weekBuckets.ts).

## Feed — the timeline

[Feed.tsx](../../resources/js/pages/Activities/Feed.tsx) (default export
`RunsIndex`) groups every run **by ISO week** (`groupByWeek` in
[weekBuckets.ts](../../resources/js/pages/Activities/weekBuckets.ts),
Monday-start; undated runs fall into a trailing "No date" bucket). Matching the
prototype's own structure, only the two most recent run-bearing weeks render up
front. "Load older weeks" is a **real page**, not a reveal: it is an Inertia
visit carrying `?weeks=` two higher, and the server ships exactly that many week
sections (decision P3 of the prototype-parity program). Each [WeekSection](../../resources/js/components/history/WeekSection.tsx)
renders a plain mono meta line (runs / km / TRIMP), then the week's
[RecapCard](../../resources/js/components/history/RecapCard.tsx) (mood-ringed
Temari, narration, `WeeklyStatusChips` — Fatigue/ATL, Monotony, Drift/
decoupling, Fitness/CTL, Readiness/form), then the runs via
[RunListRow](../../resources/js/components/run/RunListRow.tsx). The chips
themselves live in [WeeklyStatusChips](../../resources/js/components/history/WeeklyStatusChips.tsx),
shared with the Calendar's week disclosure below.

The data comes from `HistoryController`'s list branch in
[HistoryController.php](../../app/Http/Controllers/HistoryController.php). It
returns `runs` and the per-week `weeklySnapshots`. The listing query itself is
not in the controller: [FeedFilterRequest](../../app/Http/Requests/FeedFilterRequest.php)
normalises `range`/`week` off the query string, [FeedQuery](../../app/Services/Run/FeedQuery.php)
resolves them into a [FeedFilters](../../app/Services/Run/FeedFilters.php) DTO
and builds the `Activity` query (always newest-first), and the controller hands
Inertia closures over it. Two behaviours worth knowing:

- **Auto-widen range** (`FeedQuery::widenRangeToReach`): the window defaults to
  `8w` but the server silently widens it to the smallest preset that reaches the
  user's newest run, escalating to `all`. So the page never makes the user
  hunt for their last run by hand. When it widens, `RangeWidenedNote` explains it.
- **Week paging** (`FeedQuery::weekWindow`): the page floor is the Monday of the
  `?weeks=`-th most recent run-bearing week, and `hasOlderWeeks` says whether a
  run sits behind it. `FeedFilters::WEEKS_PER_PAGE` (2) is both the first paint
  and the step; `MAX_WEEKS` (52) is the ceiling on a hand-edited cursor. Paging
  by *week* rather than by run keeps the unit the same as the one the screen
  renders, so one heavy week cannot consume another week's page. This replaced
  a flat 365-run cap and the truncation note that explained it.

That note, plus the week-deep-link `WeekFocusNote`, is one shape:
[InlineNote](../../resources/js/components/history/InlineNote.tsx), a strip
that names why the list below is not the plain full history.

The **weekly recap** under each week is a `WeeklySnapshot.recap_analysis`
payload rendered through `RecapCard`, with a rule-based fallback
(`ruleBasedFallback`) so a week always reads even before the LLM fills it. Only
`is_chain_head` (the latest *completed* week) may regenerate; the in-progress
week (`is_current_week`) waits for the scheduler. See [[recaps]] and
[[ai-pipeline]].

## Calendar — the month grid

[Calendar.tsx](../../resources/js/pages/Activities/Calendar.tsx) draws the
prototype's month grid: a weekday header, then one
[CalendarWeekRow](../../resources/js/components/history/CalendarWeekRow.tsx) per
Mon–Sun week — a week-summary button beside seven bordered day boxes, each box
carrying only its day number and a mood dot. `HistoryController`'s calendar
branch resolves `?month=YYYY-MM`, pads the grid to full weeks, and hands the
frontend pre-computed `cells` (per-day distance / pace / HR / mood / card
`rarity` / `activity_id`) so nothing needs a second query. A run-day cell still
links to that run's [[run-detail]], and keeps its distance, pace, HR and mood in
its accessible label — the prototype's box has nowhere visible left for them.

Week rows total the **whole** Mon–Sun week, padding days included, because the
row sits beside that week's own ISO-week recap and would otherwise contradict
the sentence next to it. The viewed month's meta line is scoped separately, to
the month's own days (`monthTotalsOf` in
[useCalendar.ts](../../resources/js/pages/Activities/useCalendar.ts), computed
from `cells` rather than by summing the rows). The month also carries a
`monthlyRecap`, rendered through
the same `RecapCard` used by the Feed — Temari wears the month's **dominant run
mood** (`dominantMoodOf`) — and a `lifetime` eyebrow (a separate, all-time stat,
not the viewed month's). `RecapCard`'s `fallback` prop is omitted here: there is
no rule-based fallback for monthly recaps.

**Per-week narration inside the grid** is the prototype's tap-to-expand week
row, and it is ported: the calendar branch ships the grid's own
`weeklySnapshots` (bounded to `gridStart`..`gridEnd`, so an old month does not
get the newest weeks), and pressing a week reveals its recap through
`AnalysisStatus`, the shared `WeeklyStatusChips`, and — the one Card surface
this screen keeps (decision P12) — a badge for the week's **rarest** earned
card, tinted by rarity. A week with no snapshot leaves the button disabled and
dimmed. The badge lives here and nowhere else on the screen; it is not a day-cell
affordance.

## See also

- [[data-model]] — `Activity`, `ActivityDetail`, `WeeklySnapshot` shapes
- [[run-ingest-pipeline]] — how a run becomes a row these pages read
- [[recaps]] — weekly/monthly narrative generation
- [[temari-mascot]] — the face that fronts each recap card
