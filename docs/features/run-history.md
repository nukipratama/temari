---
title: Run history (Jejak & Kalender)
description: The activity archive — weekly journey strip + snapshots on Jejak, a month grid on Kalender
tags: [feature, runs]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Riwayat/Jejak.tsx
  - resources/js/pages/Riwayat/Kalender.tsx
  - app/Http/Controllers/RunController.php
  - app/Http/Controllers/CalendarController.php
  - resources/js/components/riwayat/RiwayatTabs.tsx
  - resources/js/components/riwayat/RiwayatFilter.tsx
  - resources/js/components/aktivitas/JourneyStrip.tsx
  - resources/js/components/aktivitas/RingkasanCard.tsx
---

# Run history (Jejak & Kalender)

The "Riwayat" area is the user's whole running archive, split into two views
that share a header tab strip. [RiwayatTabs](../../resources/js/components/riwayat/RiwayatTabs.tsx)
links **Jejak** (`/aktivitas`) and **Kalender** (`/kalender`) — two routes, two
controllers, one mental model.

## Jejak — the timeline

[Jejak.tsx](../../resources/js/pages/Riwayat/Jejak.tsx) (default export
`RunsIndex`) lists every run **grouped by ISO week** (`groupByWeek`, Monday-start;
undated runs fall into a trailing "Tanpa tanggal" bucket). Each `WeekSection`
renders a header of week totals (runs / km / TRIMP), a row of weekly load chips
(`WeeklyStatusChips` — Lelah/ATL, Variasi/monotony, Drift/decoupling, Fit/CTL,
Form), then Temari's narrative recap, then the runs.

The data comes from `RunController::index` in
[RunController.php](../../app/Http/Controllers/RunController.php). It returns
`runs`, the per-week `weeklySnapshots`, and a `journeyMatch`. Two behaviours
worth knowing live there:

- **Auto-widen range** (`widenRangeToReach`): the range chip defaults to `8w`
  but the server silently widens it to the smallest preset that reaches the
  user's newest run, escalating to `all`. So the page never makes the user
  hunt for their last run by hand. When it widens, `RangeWidenedNote` explains it.
- **Truncation cap** (`MAX_RUNS = 365`): a wide/`all` range is capped at the
  365 newest runs; older ones drop and `RunsTruncatedNote` says so.

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
two controls. The **range** chips do a partial Inertia reload (`only:` a fixed
prop list) so changing the window re-queries the server. The **mood** toggles
are purely client-side: `Jejak` computes `matchedRunIds` and *dims* unmatched
runs/weeks rather than removing them, so the timeline shape stays stable.

## Kalender — the month grid

[Kalender.tsx](../../resources/js/pages/Riwayat/Kalender.tsx) is a
Google-Calendar-style single month. [CalendarController](../../app/Http/Controllers/CalendarController.php)
(`__invoke`) resolves `?month=YYYY-MM`, pads the grid to full Mon–Sun weeks, and
hands the frontend pre-computed `cells` (per-day distance / pace / HR / mood /
`activity_id`) so each cell renders rich without a second query. A run-day cell
links to that run's [[run-detail]]; the mood tints the cell fill.

The month also carries a `monthlyRecap` (`MonthlyRecapCard`) — Temari wears the
month's **dominant run mood** (`dominantMoodOf`) — and a `lifetime` eyebrow.
The mood filter here dims cells the same way Jejak dims rows.

## See also

- [[data-model]] — `Activity`, `ActivityDetail`, `WeeklySnapshot` shapes
- [[run-ingest-pipeline]] — how a run becomes a row these pages read
- [[recaps]] — weekly/monthly narrative generation
- [[temari-mascot]] — the mascot voicing each recap
