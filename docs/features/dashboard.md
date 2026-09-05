---
title: Dashboard
description: The home page — this week's plan widget, the Past You verdict and its evidence, today's session, then the week's stats behind a closed disclosure
tags: [feature, dashboard]
status: living
reviewed: 2026-09-01
code_refs:
  - resources/js/pages/Home.tsx
  - app/Http/Controllers/DashboardController.php
  - resources/js/components/home/WeekPlanWidget.tsx
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - resources/js/components/home/VerdictHero.tsx
  - resources/js/components/home/EvidenceList.tsx
  - resources/js/components/home/NoVerdictPanel.tsx
  - resources/js/components/home/TodaySession.tsx
  - resources/js/components/home/NoPlanCard.tsx
  - resources/js/components/home/WeekStatsDisclosure.tsx
  - resources/js/lib/verdict.ts
  - resources/js/components/dashboard/VitalBars.tsx
  - resources/js/components/dashboard/LastRunCard.tsx
  - resources/js/components/dashboard/TrainingLoadCard.tsx
---

# Dashboard

The app's home (`/`), ported to the frozen prototype's `TodayScreen` in `PS3`. Four sections, in its order: the week's plan card (or its empty state), the **"am I getting better?"** verdict with the evidence behind it, Temari's read on today, then the week's stats behind a **closed** disclosure. Server entry is [DashboardController](app/Http/Controllers/DashboardController.php) (`__invoke`), rendering the [Home](resources/js/pages/Home.tsx) page.

**Navigation:** `route('dashboard')` → `/`. Named route: `dashboard`. `/` is dispatched by [RootController](app/Http/Controllers/RootController.php), which branches on auth: a guest gets the landing page ([[landing]]) and a signed-in user is delegated here. `route('dashboard')` therefore resolves for guests too — it answers with the landing page rather than a redirect.

## System dependencies

- **Past You** — `pastYouTrend` comes from `PastYouTrendBuilder::build`. See [[past-you-engine]].
- **AI narration** — today's voice block is an `Analysis` row from the [[ai-pipeline]].
- **Training metrics** — `load` comes from `TrainingLoad::summary`. See [[training-load-metrics]].
- **Plan** — `weekPlan` comes from `CurrentWeekPlanBuilder::forUser`, the same phase/volume computation [[plan-periodizer]] uses for the full multi-week arc. See below.

## This week's plan

[WeekPlanWidget](resources/js/components/home/WeekPlanWidget.tsx) leads the page whenever `weekPlan` is non-null. It shows a credited/total progress ring reading out its own figure, that same figure and the week's planned distance as two plain stat figures, a phase badge, a 7-day day-status grid (today's cell ringed), and a footer row for today's session that links into Plan — all lifted straight from `CurrentWeekPlanBuilder::forUser`'s payload — the same [PlanRenderer::dayPayload](app/Services/Run/Plan/PlanRenderer.php) shape Plan's own week rows render, so nothing shown here can numerically drift from the Plan page. A rest day someone ran anyway shows its `actual_km`, the way the prototype's own wednesday cell does.

When `weekPlan` is null the slot draws [NoPlanCard](resources/js/components/home/NoPlanCard.tsx), the prototype's `planState: 'empty'` branch — a `FaceIcon`, "No plan yet." and a link into Plan. The shipped page used to render nothing here at all.

`PP3` cut the widget's `N Credited In A Row` line and the `streak_days` metric behind it (P27): the prototype's plan card draws a credited/total ring and a phase badge, and no "in a row" line. "Streak" now survives in exactly one place, the week-grained [WeeklySnapshot::consecutiveWeekStreak()](app/Models/WeeklySnapshot.php) chip on Trends.

## The verdict

[VerdictHero](resources/js/components/home/VerdictHero.tsx) sits below the week plan card: a mono eyebrow naming the window, the call itself in Temari's narrated register (lowercase-leaning) as a serif accent headline, and the aggregate that backs it. It carries **no mascot and no byline** — the prototype's "you vs past you" block draws neither, and `PS3` removed the ones the shipped page had. Its copy is rule-based, not a narrated block — [verdict.ts](resources/js/lib/verdict.ts) turns the `pastYouTrend` payload into a headline and a supporting line, so the claim costs no tokens and can never contradict the numbers beside it.

Three of the four outcomes render here (`improving` / `plateaued` / `slipped`), each with its own tone: `improving` on the prototype's `icon-accent`, the other two on tones that do not read as a celebration. `plateaued` and `slipped` are stated plainly, once, with the number: a page that only looks good when the news is good would not be keeping score. See [[voice-and-tone]] and [temari-keeps-score-persona](docs/decisions/temari-keeps-score-persona.md).

Which reading drives the headline mirrors the server's own precedence in `PastYouTrendBuilder::aggregateDirection` — pace leads, heart rate decides a window whose pace came back flat, so "same pace, less work to hold it" is an improvement rather than a plateau.

## The evidence

[EvidenceList](resources/js/components/home/EvidenceList.tsx) renders the 2 to 4 matched pairs the verdict was computed from, one row each: what made the pair comparable (distance, and the run it was matched against), the reading before and after, and the delta, toned by the pair's `direction`. Each row links to the run it was measured on.

A row shows **the metric that actually decided it**, not always pace: `decidingMetric` in [verdict.ts](resources/js/lib/verdict.ts) mirrors `PastYouComparison::direction`, so a pair whose pace landed inside the noise band shows its heart rate instead. Without that, a row marked as a gain could show a delta of `+2 s/km` and look broken.

## No verdict yet

The fourth outcome, `not_enough_history`, renders [NoVerdictPanel](resources/js/components/home/NoVerdictPanel.tsx) instead of a headline, so the USP never claims a reading it does not have. It is the `no-past-match` empty state from the brand set, not an error.

`comparison_count` splits it in two, because one comparable pair is a materially different situation from none:

- **0 pairs** — nothing comparable in the window at all.
- **1 pair** — a near miss. The single pair is still rendered as evidence, so the runner can see they are one comparable run away from a verdict rather than being told a flat "nothing yet".

## Today's session

[TodaySession](resources/js/components/home/TodaySession.tsx) is the one forward-looking block on an otherwise backward-looking page: a leaf-ringed `FaceIcon` beside a "Today" eyebrow and the line that leads, on a `today-accent` edged card (`PS3` moved it off the `sky` panel it used to sit on, see [[design-tokens]]). It renders `briefing.mascotVoice` through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx), so it carries the skeleton / retry states from the [[ai-pipeline]]. The text is parsed on `\n\n`: the first paragraph leads, the rest follows as body.

The whole briefing object is assembled server-side by [BriefingComposer::compose](app/Services/Run/Story/BriefingComposer.php#L24) — a single Analysis row, the daily voice (the featured-kartu voice that used to sit beside it was swept by `W2`). It is its own [[ai-pipeline]] block with independent retry. The signals their prompts read come from the context builders in [[ai-narration-internals]]; the vibe that colours Temari's tone is [[vibe-and-mood]].

## This week's stats

[WeekStatsDisclosure](resources/js/components/home/WeekStatsDisclosure.tsx) closes the page. It renders **closed**: the prototype's `Collapsible` passes no `defaultOpen`, which the parity program's 2026-08-31 amendment settled as what ships, superseding the earlier open-by-default choice. Its trigger summarises the week ("This week's stats · 4 runs · 18.2 km"); opening it reveals, in the prototype's order:

- **The stat strip** — runs / km / TRIMP from the latest `WeeklySnapshot`, count-up animated, as three inline figures rather than tiles.
- [VitalBars](resources/js/components/dashboard/VitalBars.tsx) — three labelled bars: **Vibe** (the `vibeLabel` word, with `VIBE_SUB` glossing it), **Readiness** (`load.form` signed, with `formStatusLabel`) and **Recovery** (`recoveryHoursLabel` / streak / recovery label). Each rail is a real `role="meter"`, and a fatigued readiness or a non-positive recovery tone takes the citrus "watch" treatment. Renamed from `VitalChips` in `PS3`, when the 3-up gauge tiles became these bars.
- [LastRunCard](resources/js/components/dashboard/LastRunCard.tsx) and [TrainingLoadCard](resources/js/components/dashboard/TrainingLoadCard.tsx) — the prototype's two mini cards, side by side: km / pace / TRIMP for `recentRuns[0]` with a link to its detail page, and fitness / fatigue / strain with a link to `/history`.

Three things the shipped cards carried are not on the prototype's and went with the port: the last run's name, location, weather chip and post-run note one-liner (the note survives on [[run-history]]'s run rows, the weather on the activity detail's `MapWeatherPanel`), the training-load card's plain-language hints and risk tones, and **Monotony**, which survives as [[run-history]]'s per-week alert. `lastRunNote` had no consumer left, so the prop and its controller query went too.

`PP3` cut the featured-kartu panel (P29) — the prototype's Today screen draws no Kartu surface — and
with it `briefing.featuredCardId` / `briefing.featuredKartuVoice`. `W2` then swept the
`briefing_featured_kartu_voice` narrator, its job, its agent tool and its enum case, which had kept
generating and billing for the deleted panel.

## Empty state

When `recentRuns.length === 0`, the page renders `EmptyRunsState` alone — connect Strava and run, see [[strava-connect]]. That is the `no-runs` state, distinct from `no-past-match` above: a brand new account is not shown a verdict block it cannot fill.

## Notes / gotchas

- `pastYouTrend` is a **plain (eager) closure**, not `Inertia::defer()`, so the verdict is present at first paint rather than popping in after it. That is deliberate now that it is the page's hero — but it means the dashboard's response time includes `PastYouTrendBuilder::build`, whose `TrainingLoad::ctlTrend` call is **uncached** (`summary()` is cached, `ctlTrend()` is not). If the dashboard gets slower, look there first.
- The previous dashboard (`Today.tsx`), its hero banner and the standalone `PastYouTrendCard` were **deleted** in an earlier change; the shared helper module moved with the page, and `pages/Today/helpers.ts` is now [pages/Home/helpers.ts](resources/js/pages/Home/helpers.ts). `PS3` emptied most of it out in turn — the week-range label, the weather/location formatters and the training-load hint/tone helpers all lost their last caller with the mini cards. Git history has them.
- The weekly recap narrative lives on [[run-history]]/Feed and [[recaps]], not the dashboard.
- Every Temari voice block routes through the [[ai-pipeline]]; see [[data-model]] for `Analysis`, `WeeklySnapshot`, and `StoryLine`.
