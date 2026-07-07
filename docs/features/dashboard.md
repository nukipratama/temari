---
title: Dashboard (Hari Ini)
description: The home page — daily greeting, Temari's briefing, vitals, featured kartu, suggestion, last run, training load, goals
tags: [feature, dashboard]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/HariIni.tsx
  - app/Http/Controllers/DashboardController.php
  - resources/js/components/dashboard/KataTemariCompact.tsx
  - resources/js/components/dashboard/VitalChips.tsx
  - resources/js/components/dashboard/FeaturedKartuPanel.tsx
  - resources/js/components/dashboard/SuggestionCard.tsx
  - resources/js/components/dashboard/LastLariCard.tsx
  - resources/js/components/dashboard/KondisiCard.tsx
  - resources/js/components/dashboard/GoalsCard.tsx
---

# Dashboard (Hari Ini)

The app's home (`/`). It greets the runner by name, hands them Temari's read on the day, then stacks the day's vitals, this week's featured kartu, a session suggestion, the last run, training load, and the nearest goals. Server entry is [DashboardController](app/Http/Controllers/DashboardController.php) (`__invoke`), rendering the [HariIni](resources/js/pages/HariIni.tsx) page.

**Navigation:** `route('dashboard')` → `/`. Named route: `dashboard`.

## System dependencies

- **AI narration** — every voice block (greeting, briefing, suggestion, featured-kartu voice) is an `Analysis` row from the [[ai-pipeline]].
- **Training metrics** — `load` comes from `TrainingLoad::summary`; `trendAnalysis` / `weeklyRecap` are computed by the training-load engine. See [[training-load-metrics]].
- **Gamification** — the featured kartu is picked by rarity rank. See [[gamification]].
- **Dawn-shift** — surface tints drift by time of day via `useDawnShift`. See [[frontend-architecture]].

## The headline

`HariIni` builds the eyebrow line from `formatWeekdayDateId` + `formatTimeId` + the briefing's `vibeLabel`, and the `<h1>` reads "Halo, {firstName}" over an italic `vibeSubtitle`. The vibe drives Temari's `pose` (`VIBE_TO_POSE`). The greeting prose itself comes from the server: `DashboardController::resolveGreeting` returns today's cached `StoryLine` (kind `daily_greeting`) or generates it via the `Temari` story service.

## Kata Temari (briefing card)

The headline's right rail is [KataTemariCompact](resources/js/components/dashboard/KataTemariCompact.tsx) — Temari's mascot beside "Kata Temari hari ini". The prose is an LLM block (`briefing.mascotVoice`) rendered through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx), so it shows the spinner / retry / "Baca ulang" states from the [[ai-pipeline]] and an `ExpandableQuote` for long text. The whole briefing object is assembled server-side by [BriefingComposer::compose](app/Services/Run/Story/BriefingComposer.php#L24) — and it isn't one narrative but **four** independent Analysis rows: headline, suggestion, mascot voice, and featured-kartu voice (the last keyed on a separate discriminator so re-picking the featured card doesn't rebill the other three). Each is its own [[ai-pipeline]] block with independent retry. The signals their prompts read come from the context builders in [[ai-narration-internals]]; the vibe that colours Temari's tone is [[vibe-and-mood]].

## Vital chips

[VitalChips](resources/js/components/dashboard/VitalChips.tsx) is a 3-up row: **Vibe** (absolute form score as a numeric proxy, qualitative label below), **Kesiapan** (`load.form` signed, with `formStatusLabel`), and **Recovery** (`recoveryHoursLabel` / streak / recovery label). The Vibe and Kesiapan chips carry a `MetricExplainer` tooltip. `load` is the `TrainingLoad::summary` payload.

## Featured kartu

When there are runs, [FeaturedKartuPanel](resources/js/components/dashboard/FeaturedKartuPanel.tsx) wraps `FeaturedCardHero` + a full `Kartu`, picked client-side by `featuredCardFor(recentRuns, briefing.featuredCardId)`. Its voice line (`briefing.featuredKartuVoice`) is another `AnalysisStatus` block, here `onSky` and `allowReanalyze={false}`. The controller deliberately selects `summary_polyline` + `stream_summary` on `recentRuns` so this hero can draw the route, zone bar, and pace-shape. See [[cards-collection]].

## The 3-up: suggestion, last run, kondisi

- [SuggestionCard](resources/js/components/dashboard/SuggestionCard.tsx) — "Saran sesi dari Temari": an LLM `suggestion` block parsed into a bold title + body, plus a weather chip from the last run and a "Saran lain" re-trigger (`useAnalysisTrigger`).
- [LastLariCard](resources/js/components/dashboard/LastLariCard.tsx) — the most recent run (`recentRuns[0]`) as a `LinkCard` to its detail page, with km / pace / TRIMP tiles and an optional post-run note one-liner (`lastRunNote`, from `PostRunNoteReader::forActivity`). Temari's pose comes from `poseForRun`.
- [KondisiCard](resources/js/components/dashboard/KondisiCard.tsx) — training load read-out: **Fondasi** (CTL 42d), **Kelelahan** (ATL 7d), **Beban** (strain), **Variasi** (monotony), each with a plain-language hint. Links out to `/aktivitas`. See [[run-history]] for the weekly metrics this mirrors.

## Goals

[GoalsCard](resources/js/components/dashboard/GoalsCard.tsx) reads `goalsSummary` from Inertia **shared props** (not a page prop) and renders the nearest targets as progress bars linking to `/target`; it returns `null` when there are none. See [[targets-accessories]].

## Empty state

When `recentRuns.length === 0`, the page swaps everything below the headline for `EmptyRunsState` — connect Strava and run, see [[strava-connect]].

## Notes / gotchas

- `DashboardController` also passes `trendAnalysis` and `weeklyRecap` props, but `HariIni.tsx` does **not** consume them today — the weekly recap narrative lives on [[run-history]]/Jejak and [[recaps]], not the dashboard.
- Greeting + every Temari voice block route through the [[ai-pipeline]]; see [[data-model]] for `Analysis`, `WeeklySnapshot`, and `StoryLine`.
