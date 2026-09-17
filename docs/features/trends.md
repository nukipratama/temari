---
title: Trends
description: /trends — Temari's 7-day verdict, then three stacked comparisons (vs last week, vs a month ago, vs race day)
tags: [feature, trends]
status: living
reviewed: 2026-09-17
code_refs:
  - resources/js/pages/Trends.tsx
  - app/Http/Controllers/TrendsController.php
  - resources/js/components/trends/NarrationCard.tsx
  - resources/js/components/trends/WeekComparison.tsx
  - resources/js/components/trends/MonthComparison.tsx
  - resources/js/components/trends/RaceComparison.tsx
  - resources/js/components/trends/panels/FitnessPanel.tsx
  - resources/js/components/trends/Stat.tsx
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
days now; its prompt no longer branches on range. Scheduled by [`ai:trend-read 7d`](routes/console.php)
daily at 06:00 and by [KickoffRecapsJob](app/Jobs/AI/KickoffRecapsJob.php) on first connect. See
[[llm-triggers]] for the fingerprint-gated re-bill rule.

## vs last week

[WeekComparison](resources/js/components/trends/WeekComparison.tsx) is the widest section, since it
carries both halves of the page's question in one card, separated by a rule: km and runs this week
against last week through the same weekday (the gain), then form in words, weekly TRIMP, monotony
and strain (the cost). Every number keeps its own label, and every training-load term carries a
one-line plain-language gloss (`formStatusMeaning`, `resources/js/lib/formStatus.ts`) per the
jargon-accessibility rule in [[voice-and-tone]].

`weekComparison` (km/runs) comes from `BriefingContext::forUser()` — the same context builder
`WeekStateTool` feeds the briefing narrator, reused here rather than a new query.
`load` is one [TrainingLoad::summary()](app/Services/Run/Metrics/TrainingLoad.php) call at the
7-day window, not one entry per range. See [[training-load-metrics]].

## vs a month ago

[MonthComparison](resources/js/components/trends/MonthComparison.tsx) owns the fitness chart: one
CTL line over the full 365-day `ctlTrend` series (Chart.js, via
[FitnessPanel](resources/js/components/trends/panels/FitnessPanel.tsx)), with the trailing 30 days
shaded and the deload marker kept — no ATL line, no stat tiles on the chart itself, no badge chips.
The categorical form-status band beneath the line is a plain flex strip, not a second Chart.js
dataset: each day's status ([`formStatusFor`](resources/js/lib/formStatus.ts), mirroring
[TrainingLoad::formStatus()](app/Services/Run/Metrics/TrainingLoad.php)) is bucketed into
fresh/balanced/tired and collapsed into runs.

Fitness-now, a-month-ago and best-this-year are read off that same `ctlTrend` series client-side
([resources/js/lib/trends.ts](resources/js/lib/trends.ts): `ctlNow`, `ctlDaysAgo`, `ctlPeak`) —
no new backend query, so the card and the line can never disagree.

## vs race day

[RaceComparison](resources/js/components/trends/RaceComparison.tsx) closes the page: days out, the
target time and pace, and where fitness sits today, read from the `activeRace` shared prop
([GamificationProps](app/Services/Inertia/GamificationProps.php)) rather than a page-specific
query. With no race set it renders **vs your own year** instead — today against the highest CTL in
the same 365-day series — plus a "set a race" link to `/race`.

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
