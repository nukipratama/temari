---
title: Profile
description: The runner's identity page — Temari's profile voice, lifetime stats, 12-week persona mix, PR progression charts, Strava status
tags: [feature, profile]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Profile.tsx
  - app/Http/Controllers/ProfileController.php
  - resources/js/components/PersonaBar.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - resources/js/components/collection/ProgressionChart.tsx
  - resources/js/components/temari/Temari.tsx
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Actions/Run/Metrics/EstimateThresholdAction.php
  - app/Services/Run/Metrics/TrainingPaceCalculator.php
---

# Profile

The Profile page (`/profile`) is the runner's about-me: who they are, how Temari sees them, their lifetime totals, a 12-week mood persona, and their PR progression over time. Server entry is [ProfileController](app/Http/Controllers/ProfileController.php) (`__invoke`), rendering the [Profile](resources/js/pages/Profile.tsx) page.

**Navigation:** `route('profile')` → `/profile`. Named route: `profile`. "Me" is the nav *label* only ([TopNav](resources/js/components/TopNav.tsx)); there is no `/aku` route, and `/profil` is a permanent redirect to `/profile`.

## System dependencies

- **AI narration** — `profileVoice` (`AkuProfileVoice`) is an `Analysis` row from the [[ai-pipeline]]. It is the page's only narrated block.
- **Gamification** — the `PersonalRecord` rows behind the progression charts come from [[gamification]].
- **Settings** — the Telegram toggles, HR-zone entry, and account deletion moved to the [[settings]] hub; Profile links to it.
- **Data model** — `PersonalRecord` shape in [[data-model]].

## Identity + What Temari says about you

The header eyebrow is built from first-run date and months-since-first-run, over an "{firstName} Runner, / *your story.*" headline. Below it a `HeroPanel` pairs the [Temari](resources/js/components/temari/Temari.tsx) mascot (pose `proud`) with **"★ What Temari says about you"** — the AI profile voice (`profileVoice`), rendered through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) `onSky` as an italic quote. Strava status (`identity.strava_connected`) shows as a "Reconnect" action when revoked, and a "With Temari since" date anchors the panel's right edge on desktop.

This is the merged Aku voice: it reads who the runner is from their 12-week mood mix and backs that reading with their lifetime numbers, in one billed call ([AkuProfileVoiceNarrator](app/Services/AI/Narrators/AkuProfileVoiceNarrator.php) carries `get_persona_mix` alongside `get_lifetime_stats`, `get_training_paces` and `get_progression_signal`). Server side, `ProfileController::resolveProfileVoice` looks up the `AkuProfileVoice` analysis keyed by **ISO week** (`isoFormat('GGGG-[W]WW')`) and returns `Analysis::toPayload`. The numbers on the page are live; the prose is refreshed once a week by `ai:weekly-profile` (`invalidate: false`, so the week key is the refresh) or on demand via "Reread". See [[recaps]] and [[ai-pipeline]].

## Stats trio

Three `StatCard`s: **Total km**, **Total runs**, **Longest run**. The controller delegates to [LifetimeStats](app/Services/Run/LifetimeStats.php), the same service `/calendar` uses: one aggregate query over `ActivityDetail` (`SUM(distance)`, `MAX(distance)`, `MIN(start_date_local)`) plus `user->activities()->count()` for the run count, converted to km and cached per user for 5 minutes. `/profile` maps its `longest_km` onto the `longest_run_km` prop; the page renders **Total km** at 1dp and **Longest run** at 2dp, matching the precision the service rounds to.

Sharing `/calendar`'s cache means the totals can trail a just-ingested run by up to the TTL, the same window `/calendar` has always had.

## Fitness — VDOT, threshold pace & training paces

When the runner has a VDOT-eligible PR, the hero stat grid grows two more tiles (**VDOT**, **Threshold pace**, `explainerKey`s `vdot`/`threshold_pace`) and a "Training · pace targets" card renders below the hero panel with four tiles — **Easy**, **Marathon**, **Tempo**, **Interval** — each a pace-per-km via `formatPace`. `ProfileController::fitness` builds the `fitness` prop from [VdotEstimator](app/Services/Run/Metrics/VdotEstimator.php)`::estimate`, [EstimateThresholdAction](app/Actions/Run/Metrics/EstimateThresholdAction.php)`::__invoke` and [TrainingPaceCalculator](app/Services/Run/Metrics/TrainingPaceCalculator.php)`::fromVdotResult`; `fitness` is `null` (and the extra tiles don't render) when the user has no VDOT-eligible PR yet.

These are the same estimators [AkuProfileVoiceNarrator](app/Services/AI/Narrators/AkuProfileVoiceNarrator.php) calls via [TrainingPacesTool](app/Services/AI/Agent/Tools/TrainingPacesTool.php) to narrate pace targets in prose — the numbers reach the user both ways, tabulated here and spoken in the hero voice above.

## Persona · last 12 weeks

The "Persona" section renders [PersonaBar](resources/js/components/PersonaBar.tsx): a single stacked bar of mood slices (`personaMix`), each colored by `MOOD_FILL`, with a legend of `MOOD_LABEL` + percent. The mix comes from `AkuProfileVoiceNarrator::personaMix($user)`. The bar carries no narration block of its own: the mix is narrated once, in the hero voice above. Empty mix → PersonaBar shows "Not enough runs yet to read your persona."

## Journey (progression)

When `progressionByCategory` is non-empty, a tabbed section (5K / 10K / HM / FM) renders [ProgressionChart](resources/js/components/collection/ProgressionChart.tsx) alongside a "Then …, now …" best/worst readout and a goal chip. The series are built server-side by `ProfileController::buildProgressionByCategory` via `ProgressionSeriesBuilder`, over the four `PROGRESSION_CATEGORIES`.

## Not on this page

PRs and accessories are **not** rendered here — Profile shows no PR cards and no accessory strip. The full PR list lives at `/records` ([[records]]) and the unlock catalog at `/accessories` ([[targets-accessories]]).

## Settings

Profile no longer carries a settings entry point. The Telegram notification panel and the HR zones entry once lived inline here, then behind a single row at the bottom of the page; both now live on the [[settings]] hub, reached from the avatar menu ([UserMenu](../../resources/js/components/UserMenu.tsx)) next to "Log out". Settings is an account action, not a profile section — putting it beside logout makes it reachable from every page instead of only this one.

## Notes / gotchas

- `profileVoice` is keyed **per ISO week**, and `ProfileController` must compute that key the same way `WeeklyProfileCommand` and `DemoRunSeeder` do. `resolveProfileVoice` always returns a payload — `Analysis::toPayload(null, …)` stages a `pending` one when no row matches — and a plain `pending` block renders nothing at all, so a key mismatch shows up as a silently empty hero quote rather than an error.
- The mascot here renders via the shared [Temari](resources/js/components/temari/Temari.tsx) wrapper, so any equipped accessory shows up automatically.
- The voice block leans on the same [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) state machine as the rest of the app — see [[ai-pipeline]] and [[data-model]] (`Analysis`, `PersonalRecord`).
