---
title: Trends
description: /trends — Temari's 7-day verdict, then three stacked comparisons (vs last week, vs a month ago, vs race day) and the supported time over the race season
tags: [feature, trends]
status: living
reviewed: 2026-10-09
code_refs:
  - resources/js/pages/Trends.tsx
  - app/Http/Controllers/TrendsController.php
  - resources/js/components/trends/NarrationCard.tsx
  - resources/js/components/trends/WeekComparison.tsx
  - resources/js/components/trends/MonthComparison.tsx
  - resources/js/components/trends/RaceComparison.tsx
  - resources/js/components/trends/SupportedOverTime.tsx
  - app/Services/Run/Trend/TrendSnapshotWriter.php
  - resources/js/components/trends/panels/FitnessPanel.tsx
  - resources/js/components/ui/StatTile.tsx
  - app/Services/AI/Narrators/TrendReadNarrator.php
  - app/Services/AI/AnalysisType.php
  - app/Models/Scopes/KnownAnalysisTypeScope.php
---

# Trends

The page answers one question: **"am I getting fitter, and at what cost?"** Direction A of the
#914 design round, filed as #967. Server entry is [TrendsController](app/Http/Controllers/TrendsController.php)
(`__invoke`), rendering the [Trends](resources/js/pages/Trends.tsx) page.

Top to bottom, the page reads as one argument: Temari's 7-day read is the only place a verdict is
stated, and everything below it is evidence, in the order a runner would ask for it.

## The verdict

[NarrationCard](resources/js/components/trends/NarrationCard.tsx) renders the `narration` prop — a
single `AnalysisPayload` for the `trend_read` / `7d` row, not a per-range map. A `done` row splits
on the narrator's `"{title}\n\n{description}"` shape through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx),
which also carries flag-wrong and reread. Any other status (Pending, Queued, Processing, or Failed)
renders the same honest empty card instead: "not written yet" plus the existing per-block "try
again" retry, since every number elsewhere on the page is real and current regardless of whether
the sentence has been written. Direction A deliberately does not distinguish those states further —
see the card's own docblock. The retry button calls [useAnalysisTrigger](resources/js/hooks/useAnalysisTrigger.ts)
directly rather than going through `AnalysisStatus`'s own non-done branches, which by design render
nothing for a plain Pending row.

[TrendReadNarrator](app/Services/AI/Narrators/TrendReadNarrator.php) only ever reads the trailing 7
days now; its prompt no longer branches on range. A settled post-ingest snapshot repair requests
the read after a per-athlete quiet window, while [`ai:trend-read 7d`](routes/console.php) at 06:00
remains the fingerprint-gated fallback. First connect still uses
[KickoffRecapsJob](app/Jobs/AI/KickoffRecapsJob.php). See [[llm-triggers]] for the re-bill rule.

## vs last week

[WeekComparison](resources/js/components/trends/WeekComparison.tsx) is the widest section, since it
carries both halves of the page's question in one card, separated by a rule: km and runs this week
against last week through the same weekday (the gain), then load balance in words, weekly TRIMP, monotony
and strain (the cost). Every number keeps its own label, and every training-load term carries a
one-line plain-language gloss (`formStatusMeaning`, `resources/js/lib/formStatus.ts`) per the
jargon-accessibility rule in [[voice-and-tone]].

`weekComparison` (km/runs) comes from `BriefingContext::forUser()` — the same context builder
`WeekStateTool` feeds the briefing narrator, reused here rather than a new query.
`load` is one [TrainingLoad::summary()](app/Services/Run/Metrics/TrainingLoad.php) call at the
7-day window, not one entry per range, minus `weekly_trimp_reference`, which only the briefing's
`BriefingContext` reads. The comparison labels carry the current Monday-to-today
slice and the prior week's matching weekdays; the load tiles carry their trailing seven calendar
dates. The three cost tiles stay three columns on a phone: each label reserves two lines so the
values share one top whether or not a label wraps, and each tile's line names the window and the
athlete's own normal range without repeating the number above it. The load balance value is labelled with its as-of date. All three windows share the controller's
app-local `today`, and the calendar slice uses the same Sunday-ending week boundaries as
`BriefingContext`. See [[training-load-metrics]].

## Long-term load (vs a month ago)

[MonthComparison](resources/js/components/trends/MonthComparison.tsx) owns the long-term load chart: one
CTL line over the full 365-day `ctlTrend` series (Chart.js, via
[FitnessPanel](resources/js/components/trends/panels/FitnessPanel.tsx)), with the trailing 30 days
shaded and the deload marker kept — no ATL line, no stat tiles on the chart itself, no badge chips.
The shade, the deload dashes and the scrub cursor come from one module-level Chart.js plugin
([fitnessOverlayPlugin](resources/js/components/trends/panels/FitnessPanel.tsx)) that reads
`options.plugins.fitnessOverlay` at draw time, because react-chartjs-2 only reads `plugins` on mount;
a range or ground change reaches it through the memoised `options`, and a scrub writes the cursor
index there and calls `chart.draw()` from `onHover` ([FitnessPanel](resources/js/components/trends/panels/FitnessPanel.tsx)).
The 900 ms tween is off under `prefers-reduced-motion`.
The categorical load balance band beneath the line is a plain flex strip, not a second Chart.js
dataset: each day's status (`BAND_BUCKET` in [FitnessPanel](resources/js/components/trends/panels/FitnessPanel.tsx), mirroring
[TrainingLoad::formStatus()](app/Services/Run/Metrics/TrainingLoad.php)) is bucketed into
fresh/steady/heavy (`fatigued` and `overreaching` both heavy) and collapsed into runs.

Long-term load now, a-month-ago and best-this-year are read off that same `ctlTrend` series client-side
([resources/js/lib/trends.ts](resources/js/lib/trends.ts): `ctlNow`, `ctlDaysAgo`, `ctlPeak`) —
no new backend query, so the card and the line can never disagree.

## vs race day

[RaceComparison](resources/js/components/trends/RaceComparison.tsx) follows long-term load: days out, then
"your target" with its time and pace beside "supported by your recent runs", the VDOT race equivalent,
and the one sentence that states the band, all from the same `RacePresenter` as `/race`
([TrendsController::raceOutlook()](app/Http/Controllers/TrendsController.php), [[race-projection]],
[[the-race-page-sets-the-target-beside-supported-time]]). It repeats no long-term load hero: a single
"load balance today" line closes the section. With no race set it is one line and a "set a race" link
to `/race`, with no repeated long-term load.

## Supported over time

[SupportedOverTime](resources/js/components/trends/SupportedOverTime.tsx) follows "vs race day": the
supported time at the current race's distance across its season, as a stepped line in leaf with faster
up, the target as a dashed ink-3 line labelled "your target m:ss", and a headline with the change since
the first point ("3:15 faster since aug 3" in leaf-ink, "slower" in ember-ink). A step is labelled
"10K · oct 1" only when the effort under it changed. The points are the daily trend snapshots taken for
the active race from its season's start
([TrendsController::supportedHistory()](app/Http/Controllers/TrendsController.php)), which
[TrendSnapshotWriter::writeRange()](app/Services/Run/Trend/TrendSnapshotWriter.php) fills as of each date. With no
race, no supported time, or fewer than two days of history the panel is absent. See
[[supported-time-history-from-daily-trend-snapshots]].

## Removed (#967)

The `RangeToggle` (7d/30d/90d/12mo), the badges and the week streak, and the ATL line are gone from
this page. Badges and the streak still render on Profile and run pages; nothing else read them from
Trends. `resources/js/components/dashboard/VitalBars.tsx` and `TrainingLoadCard.tsx` lost their only
caller when the range toggle's `load` section went and were deleted along with it — see
[[dashboard]]'s "Where the deep stats went" for what used to live there.

## Retired trend-read ranges

`AnalysisType::TREND_READ_RANGES` is `['7d']` — `30d`/`90d`/`12mo` retired. Their schedule entries
in [routes/console.php](routes/console.php) are gone, and stored rows under a retired discriminator
are hidden by [KnownAnalysisTypeScope](app/Models/Scopes/KnownAnalysisTypeScope.php) the same way a
retired *type* is (see #953's `plan_week_voice`), except keyed on `(analysis_type, discriminator)`
rather than `analysis_type` alone, since `trend_read` itself is still a live case. `UserEraser`'s
`withoutGlobalScope` opt-out still reaches them for account erasure. See [[llm-triggers]] for the
full schedule and [[training-load-metrics]] for the CTL/ATL engine the chart and the narrator's
`TrendRangeTool` both read.
