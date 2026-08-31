---
title: Dashboard
description: The home page — this week's plan widget, the Past You verdict and its evidence, today's session, then vitals, last run and training load
tags: [feature, dashboard]
status: living
reviewed: 2026-08-19
code_refs:
  - resources/js/pages/Home.tsx
  - app/Http/Controllers/DashboardController.php
  - resources/js/components/home/WeekPlanWidget.tsx
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - resources/js/components/home/VerdictHero.tsx
  - resources/js/components/home/EvidenceList.tsx
  - resources/js/components/home/NoVerdictPanel.tsx
  - resources/js/components/home/TodaySession.tsx
  - resources/js/lib/verdict.ts
  - resources/js/components/dashboard/VitalChips.tsx
  - resources/js/components/dashboard/LastRunCard.tsx
  - resources/js/components/dashboard/TrainingLoadCard.tsx
---

# Dashboard

The app's home (`/`). [WeekPlanWidget](resources/js/components/home/WeekPlanWidget.tsx) leads when the runner has a plan, then the page answers its other question, **"am I getting better?"**, with a verdict and the evidence behind it, then shows today's session. Everything the page used to open with (vitals, last run, training load) sits below that as supporting detail. Server entry is [DashboardController](app/Http/Controllers/DashboardController.php) (`__invoke`), rendering the [Home](resources/js/pages/Home.tsx) page.

**Navigation:** `route('dashboard')` → `/`. Named route: `dashboard`. `/` is dispatched by [RootController](app/Http/Controllers/RootController.php), which branches on auth: a guest gets the landing page ([[landing]]) and a signed-in user is delegated here. `route('dashboard')` therefore resolves for guests too — it answers with the landing page rather than a redirect.

## System dependencies

- **Past You** — `pastYouTrend` comes from `PastYouTrendBuilder::build`. See [[past-you-engine]].
- **AI narration** — today's voice block is an `Analysis` row from the [[ai-pipeline]].
- **Training metrics** — `load` comes from `TrainingLoad::summary`. See [[training-load-metrics]].
- **Gamification** — the featured kartu is picked by rarity rank. See [[gamification]].
- **Plan** — `weekPlan` comes from `CurrentWeekPlanBuilder::forUser`, the same phase/volume computation [[plan-periodizer]] uses for the full multi-week arc. See below.

## This week's plan

[WeekPlanWidget](resources/js/components/home/WeekPlanWidget.tsx) leads the page whenever `weekPlan` is non-null (a brand new account with no plan yet omits it entirely). It shows a sessions-done ring, this week's planned distance, the phase, a 7-day day-status grid (today's tile ringed), and today's row expanded into a sentence, all lifted straight from `CurrentWeekPlanBuilder::forUser`'s payload — the same [PlanRenderer::dayPayload](app/Services/Run/Plan/PlanRenderer.php) shape Plan's own week rows render, so nothing shown here can numerically drift from the Plan page.

`PP3` cut the widget's `N Credited In A Row` line and the `streak_days` metric behind it (P27): the prototype's plan card draws a credited/total ring and a phase badge, and no "in a row" line. "Streak" now survives in exactly one place, the week-grained [WeeklySnapshot::consecutiveWeekStreak()](app/Models/WeeklySnapshot.php) chip on Trends.

## The verdict

[VerdictHero](resources/js/components/home/VerdictHero.tsx) leads the page below the week plan widget (or leads it outright when there is no plan yet): the call itself in Temari's narrated register (lowercase-leaning), the aggregate that backs it, and her byline so the sentence reads as hers. Its copy is rule-based, not a narrated block — [verdict.ts](resources/js/lib/verdict.ts) turns the `pastYouTrend` payload into a headline and a supporting line, so the claim costs no tokens and can never contradict the numbers beside it.

Three of the four outcomes render here (`improving` / `plateaued` / `slipped`), each with its own tone and mascot pose. `plateaued` and `slipped` are stated plainly, once, with the number: a page that only looks good when the news is good would not be keeping score. See [[voice-and-tone]] and [temari-keeps-score-persona](docs/decisions/temari-keeps-score-persona.md).

Which reading drives the headline mirrors the server's own precedence in `PastYouTrendBuilder::aggregateDirection` — pace leads, heart rate decides a window whose pace came back flat, so "same pace, less work to hold it" is an improvement rather than a plateau.

## The evidence

[EvidenceList](resources/js/components/home/EvidenceList.tsx) renders the 2 to 4 matched pairs the verdict was computed from, one row each: what made the pair comparable (distance, and the run it was matched against), the reading before and after, and the delta, toned by the pair's `direction`. Each row links to the run it was measured on.

A row shows **the metric that actually decided it**, not always pace: `decidingMetric` in [verdict.ts](resources/js/lib/verdict.ts) mirrors `PastYouComparison::direction`, so a pair whose pace landed inside the noise band shows its heart rate instead. Without that, a row marked as a gain could show a delta of `+2 s/km` and look broken.

## No verdict yet

The fourth outcome, `not_enough_history`, renders [NoVerdictPanel](resources/js/components/home/NoVerdictPanel.tsx) instead of a headline, so the USP never claims a reading it does not have. It is the `no-past-match` empty state from the brand set ([build-empty.mjs](resources/brand/build-empty.mjs)), not an error.

`comparison_count` splits it in two, because one comparable pair is a materially different situation from none:

- **0 pairs** — nothing comparable in the window at all.
- **1 pair** — a near miss. The single pair is still rendered as evidence, so the runner can see they are one comparable run away from a verdict rather than being told a flat "nothing yet".

## Today's session

[TodaySession](resources/js/components/home/TodaySession.tsx) is the one forward-looking block on an otherwise backward-looking page, on the `sky` card tone (the dark panel in the one card system, see [[design-tokens]]). It renders `briefing.mascotVoice` through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx), so it carries the skeleton / retry states from the [[ai-pipeline]]. The text is parsed on `\n\n`: the first paragraph leads, the rest follows as body.

The whole briefing object is assembled server-side by [BriefingComposer::compose](app/Services/Run/Story/BriefingComposer.php#L24) — **two** Analysis rows: the daily voice and the featured-kartu voice (the latter keyed on a separate discriminator so re-picking the featured card doesn't rebill the other). Each is its own [[ai-pipeline]] block with independent retry. The signals their prompts read come from the context builders in [[ai-narration-internals]]; the vibe that colours Temari's tone is [[vibe-and-mood]].

## Demoted below the fold

Everything under here supports the verdict rather than competing with it, in this order:

- **This week** — a 3-up of runs / km / TRIMP from the latest `WeeklySnapshot`, count-up animated.
- [VitalChips](resources/js/components/dashboard/VitalChips.tsx) — a 3-up row: **Vibe** (the `vibeLabel` word — `load.form`'s magnitude only drives the hidden `<meter>` gauge, not visible text), **Readiness** (`load.form` signed, with `formStatusLabel`), and **Break** (`recoveryHoursLabel` / streak / recovery label). All three values use a fluid font-size clamp tuned against the narrowest supported width (iPhone SE, 320px) so real values never silently truncate in the 1/3-width tile.
- [LastRunCard](resources/js/components/dashboard/LastRunCard.tsx) — the most recent run (`recentRuns[0]`) as a `LinkCard` to its detail page, with km / pace / TRIMP tiles and an optional post-run note one-liner (`lastRunNote`, from `PostRunNoteReader::forActivity`). Temari's pose comes from `poseForRun`.
- [TrainingLoadCard](resources/js/components/dashboard/TrainingLoadCard.tsx) — training load read-out: **Fitness** (CTL 42d), **Fatigue** (ATL 7d), **Strain**, **Monotony**, each with a plain-language hint. Links out to `/activities`. See [[run-history]] for the weekly metrics this mirrors.

`PP3` cut the featured-kartu panel (P29) — the prototype's Today screen draws no Kartu surface — and
with it `briefing.featuredCardId` / `briefing.featuredKartuVoice`. The `briefing_featured_kartu_voice`
narrator, its job and its enum case are still generated; `W2` sweeps them.

## Empty state

When `recentRuns.length === 0`, the page renders `EmptyRunsState` alone — connect Strava and run, see [[strava-connect]]. That is the `no-runs` state, distinct from `no-past-match` above: a brand new account is not shown a verdict block it cannot fill.

## Notes / gotchas

- `pastYouTrend` is a **plain (eager) closure**, not `Inertia::defer()`, so the verdict is present at first paint rather than popping in after it. That is deliberate now that it is the page's hero — but it means the dashboard's response time includes `PastYouTrendBuilder::build`, whose `TrainingLoad::ctlTrend` call is **uncached** (`summary()` is cached, `ctlTrend()` is not). If the dashboard gets slower, look there first.
- The previous dashboard (`Today.tsx`), its hero banner and the standalone `PastYouTrendCard` were **deleted** in the same change, along with the exports only they used (`vibeSubtitleFor`, `VIBE_TO_POSE`, `formatWeekdayDateId`, `formatTimeId`). Git history has them if a comparison is ever wanted. The shared helper module moved with the page: `pages/Today/helpers.ts` is now [pages/Home/helpers.ts](resources/js/pages/Home/helpers.ts).
- The weekly recap narrative lives on [[run-history]]/Feed and [[recaps]], not the dashboard.
- Every Temari voice block routes through the [[ai-pipeline]]; see [[data-model]] for `Analysis`, `WeeklySnapshot`, and `StoryLine`.
