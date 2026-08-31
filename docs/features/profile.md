---
title: Profile
description: The runner's identity page — Temari's profile voice, lifetime stats, 12-week persona mix, PR progression charts, Strava status
tags: [feature, profile]
status: living
reviewed: 2026-08-19
code_refs:
  - resources/js/pages/Profile.tsx
  - app/Http/Controllers/ProfileController.php
  - resources/js/components/temari/AnalysisStatus.tsx
  - resources/js/components/collection/ProgressionChart.tsx
  - resources/js/components/temari/Temari.tsx
  - resources/js/components/me/SeasonStreakPanel.tsx
  - resources/js/components/UserAvatarLink.tsx
  - app/Services/Run/Metrics/VdotEstimator.php
  - app/Actions/Run/Metrics/EstimateThresholdAction.php
  - app/Services/Run/Metrics/TrainingPaceCalculator.php
  - app/Services/Gamification/SeasonStreakSummaryBuilder.php
---

# Profile

The Profile page (`/profile`) is the runner's about-me: who they are, how Temari sees them, their lifetime totals, a 12-week mood persona, and their PR progression over time. Server entry is [ProfileController](app/Http/Controllers/ProfileController.php) (`__invoke`), rendering the [Profile](resources/js/pages/Profile.tsx) page.

**Navigation:** `route('profile')` → `/profile`. Named route: `profile`. There is no bottom-nav "Me" tab — [UserAvatarLink](resources/js/components/UserAvatarLink.tsx) links the avatar in [MobileTopBar](resources/js/components/MobileTopBar.tsx) straight to Profile from every bottom-nav screen. Profile is itself a **pushed screen**: its topbar carries a back chevron to Today and a gear to Settings, and it renders no bottom nav. Profile and Settings stay two separate routes/controllers, not a merged `/me?segment=` route. There is no `/aku` route, and `/profil` is a permanent redirect to `/profile`. The segmented `MeTabs` nav that used to sit atop both pages was cut by the parity program's `PP1`; the `/accessories` route/controller/page are still live pending removal in `W1`/`W2`, just no longer linked from here.

## System dependencies

- **AI narration** — `profileVoice` (`AkuProfileVoice`) is an `Analysis` row from the [[ai-pipeline]]. It is the page's only narrated block.
- **Gamification** — the `PersonalRecord` rows behind the progression charts, and the `Season`/`SeasonGoal`/streak data behind the Season & streak panel, come from [[gamification]].
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

**Cut in `PP3` (P13).** The prototype's Profile hero draws a Z1-Z5 heart-rate-zone bar in the slot the app drew its behavioural persona mix, so `PersonaBar` and the `personaMix` Inertia prop are gone; `PS10` builds the zone bar in that slot. `AkuProfileVoiceNarrator::personaMix()` and `PersonaMixTool` survive — the hero voice still reads the mix as narration context — and `W2` decides whether the method itself stays.

## Journey (progression)

When `progressionByCategory` is non-empty, a tabbed section (5K / 10K / HM / FM) renders [ProgressionChart](resources/js/components/collection/ProgressionChart.tsx) alongside a "Then …, now …" best/worst readout and a goal chip. The series are built server-side by `ProfileController::buildProgressionByCategory` via `ProgressionSeriesBuilder`, over the four `PROGRESSION_CATEGORIES`.

## Season & streak

Below the "Got a race coming up?" link card, [SeasonStreakPanel](resources/js/components/me/SeasonStreakPanel.tsx) surfaces the same season and weekly-streak data `/plan` computes — a streak tile (weeks running, a "Live" pill, rest-week dots) and a season tile (date range, per-goal progress bars). It's a compact, prototype-inspired layout, not the Plan page's `SeasonTrack` re-mounted. Plan itself dropped its own streak display in the mobile-UX port (`plan/README.md` §5, "Streak feature redesign"); Profile's panel is now the streak's only other home besides Trends' badge board. The streak tile carries the same `mdi:medal-outline` glyph as Trends' `StreakBadge` (not the flame reserved for the prototype's tempo-session day-glyph convention), and a completed season goal gets an inline `mdi:check-circle` glyph — this panel is `S10`'s answer to the mobile-UX port ledger's "badge/milestone display" ruling: the standalone badge board already lives on Trends (see [[gamification]]) and unlock celebrations already fire as a toast/inbox entry, so no separate badge gallery was built on Profile; its existing milestone-adjacent surface (season goal progress) got the real design pass instead.

The payload is built by [SeasonStreakSummaryBuilder](app/Services/Gamification/SeasonStreakSummaryBuilder.php), extracted from what used to be private `PlanController` methods so both pages read the exact same shapes. Streak is always present (`WeeklySnapshot`/`StreakRestToken` reads have no season dependency). Season can be `null`: `ProfileController` calls `SeasonService::peekCurrent()`, a read-only counterpart to `ensureCurrent()` that returns the current season **if one already exists**, never creating one — visiting Profile must not trigger the same season-creation / badge-board-grant side effects a Plan page load does. A `null` season renders a "No season yet — start one on Plan" link instead of goal progress.

## Not on this page

PRs and accessories are **not** rendered here — Profile shows no PR cards and no accessory strip. The full PR list is the Personal Bests panel on `/trends` ([[records]]); the accessory unlock catalog has no more nav entry point at all since `S10` dropped the Accessories tab (see [[targets-accessories]]).

## Settings

Profile carries no settings section of its own; the Telegram notification panel and HR-zone entry live on the [[settings]] hub instead. Settings is reachable from the gear in this screen's topbar ([MobileTopBar](resources/js/components/MobileTopBar.tsx)). Log out moved off the old avatar dropdown (which no longer exists) into a row at the bottom of Settings' Account section.

## Notes / gotchas

- `profileVoice` is keyed **per ISO week**, and `ProfileController` must compute that key the same way `WeeklyProfileCommand` and `DemoRunSeeder` do. `resolveProfileVoice` always returns a payload — `Analysis::toPayload(null, …)` stages a `pending` one when no row matches — and a plain `pending` block renders nothing at all, so a key mismatch shows up as a silently empty hero quote rather than an error.
- The mascot here renders via the shared [Temari](resources/js/components/temari/Temari.tsx) wrapper, so any equipped accessory shows up automatically.
- The voice block leans on the same [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) state machine as the rest of the app — see [[ai-pipeline]] and [[data-model]] (`Analysis`, `PersonalRecord`).
