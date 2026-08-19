---
title: Run history (Feed & Calendar)
description: The activity archive — weekly journey strip + snapshots on the Feed view, a month grid on the Calendar view, both behind one /history route
tags: [feature, runs]
status: living
reviewed: 2026-08-19
code_refs:
  - resources/js/pages/History.tsx
  - resources/js/pages/Activities/Feed.tsx
  - resources/js/pages/Activities/Calendar.tsx
  - app/Http/Controllers/HistoryController.php
  - app/Http/Requests/FeedFilterRequest.php
  - app/Services/Run/FeedQuery.php
  - app/Services/Run/FeedFilters.php
  - resources/js/pages/Activities/useFeedFilters.ts
  - resources/js/components/history/HistoryTabs.tsx
  - resources/js/components/history/HistoryFilter.tsx
  - resources/js/components/history/WeekSection.tsx
  - resources/js/components/history/InlineNote.tsx
  - resources/js/components/activities/JourneyStrip.tsx
  - resources/js/components/activities/SummaryCard.tsx
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
components based on the `activeView` prop the controller ships; neither
component's own markup changed for the merge.
[HistoryTabs](../../resources/js/components/history/HistoryTabs.tsx) is the
in-page Feed⇄Calendar switcher, now linking `/history` and
`/history?view=calendar`.

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

## Feed — the timeline

[Feed.tsx](../../resources/js/pages/Activities/Feed.tsx) (default export
`RunsIndex`) is pure composition: every filter derivation lives in
[useFeedFilters.ts](../../resources/js/pages/Activities/useFeedFilters.ts). It lists
every run **grouped by ISO week** (`groupByWeek` there, Monday-start; undated runs
fall into a trailing "Tanpa tanggal" bucket). Each
[WeekSection](../../resources/js/components/history/WeekSection.tsx)
renders a header of week totals (runs / km / TRIMP), a row of weekly load chips
(`WeeklyStatusChips` — Lelah/ATL, Variasi/monotony, Drift/decoupling, Fit/CTL,
Form), then Temari's narrative recap, then the runs.

The data comes from `HistoryController`'s list branch in
[HistoryController.php](../../app/Http/Controllers/HistoryController.php). It
returns `runs`, the per-week `weeklySnapshots`, and a `journeyMatch`. The listing query
itself is not in the controller: [FeedFilterRequest](../../app/Http/Requests/FeedFilterRequest.php)
normalises the query string, [FeedQuery](../../app/Services/Run/FeedQuery.php)
resolves it into a [FeedFilters](../../app/Services/Run/FeedFilters.php) DTO and
builds the `Activity` query, and the controller hands Inertia closures over it.
Two behaviours worth knowing:

- **Auto-widen range** (`FeedQuery::widenRangeToReach`): the range chip defaults to `8w`
  but the server silently widens it to the smallest preset that reaches the
  user's newest run, escalating to `all`. So the page never makes the user
  hunt for their last run by hand. When it widens, `RangeWidenedNote` explains it.
- **Truncation cap** (`MAX_RUNS = 365`): a wide/`all` range is capped at the
  365 newest runs; older ones drop and `RunsTruncatedNote` says so.

Both of those notes, plus the week-deep-link `WeekFocusNote`, are one shape:
[InlineNote](../../resources/js/components/history/InlineNote.tsx), a cream-deep
strip that names why the list below is not the plain full history.

The **weekly recap** under each week is a `WeeklySnapshot.recap_analysis`
payload rendered through [SummaryCard](../../resources/js/components/activities/SummaryCard.tsx),
with a rule-based fallback (`ruleBasedFallback`) so a week always reads even
before the LLM fills it. Only `is_chain_head` (the latest *completed* week) may
regenerate; the in-progress week (`is_current_week`) waits for the scheduler.
See [[recaps]] and [[ai-pipeline]].

### The journey strip

[JourneyStrip](../../resources/js/components/activities/JourneyStrip.tsx) sits
above the timeline and shows an **all-time progress delta**: first-ever run vs
latest run (pace + HR improvement) plus lifetime km. The controller builds it in
`HistoryController::buildJourneyMatch` and hides it for users with fewer than two
activities.

### Filters

[HistoryFilter](../../resources/js/components/history/HistoryFilter.tsx) drives
five controls (urutan, rentang waktu, jarak, rarity, mood), and all of them go
to the server: every filter is a partial Inertia reload (`only:` a fixed prop
list) that re-queries. The **mood** toggles narrow the query through the
post-run `StoryLine` (`FeedQuery::for`), so unmatched runs are *removed* from
the list, not dimmed. A run with no story line yet carries no mood and matches
no mood filter. **Rarity** narrows through the run's earned `RunCard` the same
way (`whereHas('runCard', ...)` in `FeedQuery::for`) — a run whose card hasn't
been generated yet (still summary-only, see [[run-ingest-pipeline]]) matches no
rarity filter, the same "not yet, not never" semantics as mood. Range, distance
band and sort resolve the same way; unknown values widen rather than error. The
`?week=` deep link from the weekly-recap notification is a sixth axis with no
popover control: it pins its own window and surfaces as a removable chip.

## Calendar — the month grid

[Calendar.tsx](../../resources/js/pages/Activities/Calendar.tsx) is a
Google-Calendar-style single month. `HistoryController`'s calendar branch
resolves `?month=YYYY-MM`, pads the grid to full Mon–Sun weeks, and
hands the frontend pre-computed `cells` (per-day distance / pace / HR / mood /
`activity_id`) so each cell renders rich without a second query. A run-day cell
links to that run's [[run-detail]]; the mood tints the cell fill.

The month also carries a `monthlyRecap` (`MonthlyRecapCard`) — Temari wears the
month's **dominant run mood** (`dominantMoodOf`) — and a `lifetime` eyebrow.
The mood filter here is client-side and only *dims* unmatched cells
(`isFilteredOut` in [Calendar.tsx](../../resources/js/pages/Activities/Calendar.tsx)),
so the month grid keeps its shape. The Feed's mood filter is server-side and removes
runs instead.

## See also

- [[data-model]] — `Activity`, `ActivityDetail`, `WeeklySnapshot` shapes
- [[run-ingest-pipeline]] — how a run becomes a row these pages read
- [[recaps]] — weekly/monthly narrative generation
- [[temari-mascot]] — the mascot voicing each recap
