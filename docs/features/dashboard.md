---
title: Dashboard
description: The home page — daily greeting, Temari's daily voice, vitals, featured kartu, last run, training load, goals
tags: [feature, dashboard]
status: living
reviewed: 2026-07-29
code_refs:
  - resources/js/pages/Today.tsx
  - app/Http/Controllers/DashboardController.php
  - resources/js/components/dashboard/TodayHeroBanner.tsx
  - resources/js/components/dashboard/VitalChips.tsx
  - resources/js/components/dashboard/FeaturedKartuPanel.tsx
  - resources/js/components/dashboard/LastLariCard.tsx
  - resources/js/components/dashboard/KondisiCard.tsx
---

# Dashboard

The app's home (`/`). It greets the runner by name, hands them Temari's read on the day, then stacks the day's vitals, this week's featured kartu, the last run, and training load. Server entry is [DashboardController](app/Http/Controllers/DashboardController.php) (`__invoke`), rendering the [Today](resources/js/pages/Today.tsx) page.

**Navigation:** `route('dashboard')` → `/`. Named route: `dashboard`.

## System dependencies

- **AI narration** — every voice block (greeting, Temari's daily voice, featured-kartu voice) is an `Analysis` row from the [[ai-pipeline]].
- **Training metrics** — `load` comes from `TrainingLoad::summary`. See [[training-load-metrics]].
- **Gamification** — the featured kartu is picked by rarity rank. See [[gamification]].
- **Dawn-shift** — surface tints drift by time of day via `useDawnShift`. See [[frontend-architecture]].

## The headline

`Today` builds the eyebrow line from `formatWeekdayDateId` + `formatTimeId` + the briefing's `vibeLabel`, and the `<h1>` reads "Halo, {firstName}" over an italic `vibeSubtitle`. The vibe drives Temari's `pose` (`VIBE_TO_POSE`). The greeting prose itself comes from the server: `DashboardController::resolveGreeting` returns today's cached `StoryLine` (kind `daily_greeting`) or generates it via the `Temari` story service.

## Kata Temari (hero banner)

The whole page sits on a full-bleed dark [HeroPanel](resources/js/components/ui/HeroPanel.tsx) background. [TodayHeroBanner](resources/js/components/dashboard/TodayHeroBanner.tsx) is a full-width banner merged with the greeting at the top of it — Temari's mascot beside "Today from Temari". It renders **one** LLM block (`briefing.mascotVoice`) through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx), so it shows the spinner / retry / "Reread" states from the [[ai-pipeline]]. The text is parsed on `\n\n`: the first paragraph is the session title (display type), the rest is Temari's reasoning and her caveat. A weather chip from the last run and an "Another take" re-trigger (`useAnalysisTrigger`) sit under it. It renders whether or not the user has runs yet, and stays purely forward-looking (today's plan) — [LastLariCard](resources/js/components/dashboard/LastLariCard.tsx) owns any backward-looking recap of the last completed run.

That block used to be two separately billed calls (a mascot voice plus a session suggestion); they were merged into one voice so the dashboard speaks once. The whole briefing object is assembled server-side by [BriefingComposer::compose](app/Services/Run/Story/BriefingComposer.php#L24) — **two** Analysis rows now: the daily voice and the featured-kartu voice (the latter keyed on a separate discriminator so re-picking the featured card doesn't rebill the other). Each is its own [[ai-pipeline]] block with independent retry. The signals their prompts read come from the context builders in [[ai-narration-internals]]; the vibe that colours Temari's tone is [[vibe-and-mood]].

## Vital chips

[VitalChips](resources/js/components/dashboard/VitalChips.tsx) is a 3-up row: **Vibe** (the `vibeLabel` word, e.g. "Membara" — `load.form`'s magnitude only drives the hidden `<meter>` gauge, not visible text), **Kesiapan** (`load.form` signed, with `formStatusLabel`), and **Recovery** (`recoveryHoursLabel` / streak / recovery label). The Vibe and Kesiapan chips carry a `MetricExplainer` tooltip. `load` is the `TrainingLoad::summary` payload. All three values use a fluid font-size clamp (`text-stat-fluid` for the numeric two, a local word clamp for Vibe) tuned against the narrowest supported width (iPhone SE, 320px) so real values never silently truncate in the 1/3-width tile.

## Featured kartu

When there are runs, [FeaturedKartuPanel](resources/js/components/dashboard/FeaturedKartuPanel.tsx) wraps `FeaturedCardHero` + a full `Kartu`, picked client-side by `featuredCardFor(recentRuns, briefing.featuredCardId)`. Its voice line (`briefing.featuredKartuVoice`) is another `AnalysisStatus` block, here `onSky` and `allowReanalyze={false}`. The controller deliberately selects `summary_polyline` + `stream_summary` on `recentRuns` so this hero can draw the route, zone bar, and pace-shape. See [[cards-collection]].

## The 2-up: last run, kondisi

- [LastLariCard](resources/js/components/dashboard/LastLariCard.tsx) — the most recent run (`recentRuns[0]`) as a `LinkCard` to its detail page, with km / pace / TRIMP tiles and an optional post-run note one-liner (`lastRunNote`, from `PostRunNoteReader::forActivity`). Temari's pose comes from `poseForRun`.
- [KondisiCard](resources/js/components/dashboard/KondisiCard.tsx) — training load read-out: **Fondasi** (CTL 42d), **Kelelahan** (ATL 7d), **Beban** (strain), **Variasi** (monotony), each with a plain-language hint. Links out to `/activities`. See [[run-history]] for the weekly metrics this mirrors.

## Empty state

When `recentRuns.length === 0`, the page swaps everything below the hero banner for `EmptyRunsState` — connect Strava and run, see [[strava-connect]].

## Notes / gotchas

- The weekly recap narrative lives on [[run-history]]/Jejak and [[recaps]], not the dashboard.
- Greeting + every Temari voice block route through the [[ai-pipeline]]; see [[data-model]] for `Analysis`, `WeeklySnapshot`, and `StoryLine`.
