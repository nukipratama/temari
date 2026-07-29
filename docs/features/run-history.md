---
title: Run history (Jejak & Kalender)
description: The activity archive — weekly journey strip + snapshots on Jejak, a month grid on Kalender
tags: [feature, runs]
status: living
reviewed: 2026-07-29
code_refs:
  - resources/js/pages/Riwayat/Jejak.tsx
  - resources/js/pages/Riwayat/Kalender.tsx
  - app/Http/Controllers/RunController.php
  - app/Http/Requests/JejakFilterRequest.php
  - app/Services/Run/JejakQuery.php
  - app/Services/Run/JejakFilters.php
  - app/Http/Controllers/CalendarController.php
  - resources/js/pages/Riwayat/useJejakFilters.ts
  - resources/js/components/riwayat/RiwayatTabs.tsx
  - resources/js/components/riwayat/RiwayatFilter.tsx
  - resources/js/components/riwayat/WeekSection.tsx
  - resources/js/components/riwayat/InlineNote.tsx
  - resources/js/components/aktivitas/JourneyStrip.tsx
  - resources/js/components/aktivitas/RingkasanCard.tsx
---

# Run history (Jejak & Kalender)

The "Riwayat" area is the user's whole running archive, split into two views
that share a header tab strip. [RiwayatTabs](../../resources/js/components/riwayat/RiwayatTabs.tsx)
links **Jejak** (`/aktivitas`) and **Kalender** (`/kalender`) — two routes, two
controllers, one mental model.

**Navigation:** `route('aktivitas.index')` → `/aktivitas` (Jejak, `RunController::index`);
`route('kalender')` → `/kalender` (Kalender, `CalendarController::__invoke`).
Named routes: `aktivitas.index`, `kalender`.

## System dependencies

- **AI narration** — weekly and monthly recaps come from the [[ai-pipeline]] via `AnalysisType::WeeklyRecap` / `MonthlyRecap`.
- **Recap windowing** — the open week/month is window-gated ([[deferred-recap-windowing]]); recaps are [[chained-narration|chained]].
- **Training metrics** — `WeeklySnapshot` payloads carry CTL/ATL/form from [[training-load-metrics]].
- **Data model** — the shape of `Activity`, `ActivityDetail`, `WeeklySnapshot` is in [[data-model]].

## Jejak — the timeline

[Jejak.tsx](../../resources/js/pages/Riwayat/Jejak.tsx) (default export
`RunsIndex`) is pure composition: every filter derivation lives in
[useJejakFilters.ts](../../resources/js/pages/Riwayat/useJejakFilters.ts). It lists
every run **grouped by ISO week** (`groupByWeek` there, Monday-start; undated runs
fall into a trailing "Tanpa tanggal" bucket). Each
[WeekSection](../../resources/js/components/riwayat/WeekSection.tsx)
renders a header of week totals (runs / km / TRIMP), a row of weekly load chips
(`WeeklyStatusChips` — Lelah/ATL, Variasi/monotony, Drift/decoupling, Fit/CTL,
Form), then Temari's narrative recap, then the runs.

The data comes from `RunController::index` in
[RunController.php](../../app/Http/Controllers/RunController.php). It returns
`runs`, the per-week `weeklySnapshots`, and a `journeyMatch`. The listing query
itself is not in the controller: [JejakFilterRequest](../../app/Http/Requests/JejakFilterRequest.php)
normalises the query string, [JejakQuery](../../app/Services/Run/JejakQuery.php)
resolves it into a [JejakFilters](../../app/Services/Run/JejakFilters.php) DTO and
builds the `Activity` query, and the controller hands Inertia closures over it.
Two behaviours worth knowing:

- **Auto-widen range** (`JejakQuery::widenRangeToReach`): the range chip defaults to `8w`
  but the server silently widens it to the smallest preset that reaches the
  user's newest run, escalating to `all`. So the page never makes the user
  hunt for their last run by hand. When it widens, `RangeWidenedNote` explains it.
- **Truncation cap** (`MAX_RUNS = 365`): a wide/`all` range is capped at the
  365 newest runs; older ones drop and `RunsTruncatedNote` says so.

Both of those notes, plus the week-deep-link `WeekFocusNote`, are one shape:
[InlineNote](../../resources/js/components/riwayat/InlineNote.tsx), a cream-deep
strip that names why the list below is not the plain full history.

The **weekly recap** under each week is a `WeeklySnapshot.recap_analysis`
payload rendered through [RingkasanCard](../../resources/js/components/aktivitas/RingkasanCard.tsx),
with a rule-based fallback (`ruleBasedFallback`) so a week always reads even
before the LLM fills it. Only `is_chain_head` (the latest *completed* week) may
regenerate; the in-progress week (`is_current_week`) waits for the scheduler.
See [[recaps]] and [[ai-pipeline]].

### The journey strip

[JourneyStrip](../../resources/js/components/aktivitas/JourneyStrip.tsx) sits
above the timeline and shows an **all-time progress delta**: first-ever run vs
latest run (pace + HR improvement) plus lifetime km. The controller builds it in
`RunController::buildJourneyMatch` and hides it for users with fewer than two
activities.

### Filters

[RiwayatFilter](../../resources/js/components/riwayat/RiwayatFilter.tsx) drives
two controls, and both go to the server: every filter is a partial Inertia
reload (`only:` a fixed prop list) that re-queries. The **mood** toggles narrow
the query through the post-run `StoryLine` (`JejakQuery::for`), so unmatched runs
are *removed* from the list, not dimmed. A run with no story line yet carries no
mood and matches no mood filter. Range, distance band, search and sort resolve
the same way; unknown values widen rather than error.

## Kalender — the month grid

[Kalender.tsx](../../resources/js/pages/Riwayat/Kalender.tsx) is a
Google-Calendar-style single month. [CalendarController](../../app/Http/Controllers/CalendarController.php)
(`__invoke`) resolves `?month=YYYY-MM`, pads the grid to full Mon–Sun weeks, and
hands the frontend pre-computed `cells` (per-day distance / pace / HR / mood /
`activity_id`) so each cell renders rich without a second query. A run-day cell
links to that run's [[run-detail]]; the mood tints the cell fill.

The month also carries a `monthlyRecap` (`MonthlyRecapCard`) — Temari wears the
month's **dominant run mood** (`dominantMoodOf`) — and a `lifetime` eyebrow.
The mood filter here is client-side and only *dims* unmatched cells
(`isFilteredOut` in [Kalender.tsx](../../resources/js/pages/Riwayat/Kalender.tsx)),
so the month grid keeps its shape. Jejak's mood filter is server-side and removes
runs instead.

## See also

- [[data-model]] — `Activity`, `ActivityDetail`, `WeeklySnapshot` shapes
- [[run-ingest-pipeline]] — how a run becomes a row these pages read
- [[recaps]] — weekly/monthly narrative generation
- [[temari-mascot]] — the mascot voicing each recap
