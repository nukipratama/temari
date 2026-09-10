---
title: Onboarding wizard
description: The four-step post-connect wizard and its DB-backed gate.
tags: [feature, onboarding]
status: living
reviewed: 2026-09-09
code_refs:
  - app/Actions/AI/RequestTodaysBriefing.php
  - app/Http/Controllers/OnboardingController.php
  - app/Http/Middleware/EnsureOnboarded.php
  - app/Http/Requests/CompleteOnboardingRequest.php
  - app/Models/TrainingPreference.php
  - app/Models/User.php
  - app/Http/Controllers/Auth/StravaAuthController.php
  - app/Jobs/AI/KickoffRecapsJob.php
  - app/Services/AI/PlanNarrationRequester.php
  - app/Services/Run/Ingest/DetailHydrator.php
  - resources/js/pages/Onboarding/Index.tsx
  - resources/js/components/onboarding/StepProgress.tsx
  - resources/js/components/onboarding/IconChoiceCard.tsx
  - resources/js/components/onboarding/SessionsDial.tsx
  - resources/js/components/onboarding/DayPicker.tsx
  - resources/js/components/PushNotificationToggle.tsx
  - resources/js/components/ui/SettingsRow.tsx
  - resources/js/lib/raceGoal.ts
  - routes/web.php
---

# Onboarding

A minimal four-step wizard shown once, right after a user's *first* Strava connect.

## The gate

`users.onboarded_at` (nullable timestamp) is the source of truth — DB-backed, not session/client state, so an abandoned wizard resumes on a later visit or a different device. The migration backfills every pre-existing row to `now()` at deploy time, so only accounts created afterward ever see the wizard.

[EnsureOnboarded](../../app/Http/Middleware/EnsureOnboarded.php) (alias `onboarded`) gates the main authenticated route group in [web.php](../../routes/web.php); the wizard routes (`onboarding.show` / `onboarding.store`), logout and the two `/profile/push` routes sit in a plain `auth`-only group, so a user stuck mid-wizard can still sign out and can still subscribe a device from step 4. `OnboardingController::show` itself redirects an already-onboarded user straight to `dashboard`, so direct navigation to `/onboarding` can never re-trigger it.

## Trigger

[StravaAuthController::callback](../../app/Http/Controllers/Auth/StravaAuthController.php) redirects to `onboarding.show` when `$isFreshConnection` is true (a brand-new `User` + `StravaConnection` row), overriding any stashed intended deep-link for that one redirect — see [[strava-connect]] for the rest of the OAuth flow. A returning user's reconnect never re-enters the wizard, and it never fires for the demo account (its seeded row is marked onboarded on creation in [DemoRunSeeder](../../database/seeders/Demo/DemoRunSeeder.php)).

## The wizard

[Onboarding/Index.tsx](../../resources/js/pages/Onboarding/Index.tsx) runs inside the bare shell (`bareLayout`), not the normal authenticated `AppShell` — four steps, tracked by a persistent [StepProgress](../../resources/js/components/onboarding/StepProgress.tsx) bar (Welcome / Training / Race Goal / Nudges):

1. **Strava-connect confirmation** — a `pose="glow"` Temari, and a panel naming exactly what just landed and what has not. The first sync writes every historical run in `summary` state; splits, HR zones, effort and the run's card come from a second, per-run fetch that [DetailHydrator](../../app/Services/Run/Ingest/DetailHydrator.php) only queues when the run is opened or picked as a Past You comparison (see [[run-ingest-pipeline]]). Saying so here is what stops a new account reading its empty card collection as a bug.
2. **Optional training preferences** — one question per screen (experience level, sessions per week, goal type, then run days) instead of one flat form, each answer choosable via [IconChoiceCard](../../resources/js/components/onboarding/IconChoiceCard.tsx) / [SessionsDial](../../resources/js/components/onboarding/SessionsDial.tsx) / [DayPicker](../../resources/js/components/onboarding/DayPicker.tsx) and auto-advancing on tap; a back chevron revisits a prior question without discarding its answer, and a per-question "Skip this" link leaves that one field blank while keeping the rest. This is the same `experience_level`/`sessions_per_week`/`goal_type`/`run_days`/`long_run_day` shape [TrainingPreference](../../app/Models/TrainingPreference.php) and `/settings` already use — the wizard is just another writer of the same row (`updateOrCreate` keyed on `user_id` in `OnboardingController::store`). The days question is only reachable once a sessions target exists (nothing to pick otherwise) and is skipped straight through to the race-goal step when sessions was itself left blank. The header "Skip for now" pill discards every partial pick made across all four questions and jumps straight to step 3, matching the race-goal step's own "Skip for now".
3. **Optional first race goal** — the same shape as `/race` (`race_date`/`distance_m`/`goal_time_sec`/`name`, validated by [CompleteOnboardingRequest](../../app/Http/Requests/CompleteOnboardingRequest.php) with `required_with` making the three core fields all-or-nothing), plus a decorative "required pace" ring computed client-side from the distance/time fields already entered (not a fitness assessment, purely a fill animation). "Skip for now" carries an empty payload forward regardless of unsaved input.
4. **Optional nudges** — the two channels Temari can actually reach the runner on, offered once, at the end. The Telegram row is the same `SettingsRow` `/settings` draws, fed by `telegramConnectUrl` from `OnboardingController::show` — null, and the row absent, when the bot is unconfigured or this account is already linked, so the step never shows a control that could not work. Push is the same [PushNotificationToggle](../../resources/js/components/PushNotificationToggle.tsx) `/settings` mounts, minus the mute (there is nothing to mute before a subscription exists); on a browser tab it resolves to its Home-Screen-install explainer rather than a button. A back chevron returns to the race goal with its fields intact, and both "skip for now" and "finish" submit the wizard, so nothing here can strand a signup.

Two consequences of putting a step *after* the goal form. The wizard posts only from this last step, so the goal step stashes its answer in component state rather than submitting it. And `/profile/push` moved out of the `onboarded` route group in [web.php](../../routes/web.php): behind that gate the toggle's `fetch` would follow a 302 back to the wizard and read the resulting 200 as a success that stored nothing. For the same reason [SettingsRow](../../resources/js/components/ui/SettingsRow.tsx)'s external link opens in a new tab — tapping Telegram mid-wizard must not replace the page holding an unsubmitted signup.

The goal form only offers submissions the server can accept. [raceGoal.ts](../../resources/js/lib/raceGoal.ts) mirrors the request's `after:today` and `between:300,259200` bounds into the date input's `min` and a disabled submit, and server-side field errors render beside the field that caused them — since the submit now fires from step 4, a rejection on any goal field steps the wizard back here so the message is visible where it belongs. Before that, blanking the minutes field produced a `goal_time_sec` of 0 — a submit that could only ever 422, explained by nothing nearer than the global error banner. `/race` shares the same helper for the same reason ([[race-projection]]).

`OnboardingController::store` creates the `RaceGoal` (if goal fields were sent), upserts `TrainingPreference` (if any preference field was sent) and calls `User::markOnboarded()`, then builds the athlete's first plan and redirects to `dashboard`.

## The first week's voice

Onboarding and the Strava backfill race: the wizard writes a plan the moment signup finishes, while the connect chain is still importing history. Whoever finishes second narrates that first week. Onboarding requests narration only when `users.backfilled_at` is already stamped; otherwise it writes the rows and stops, and [KickoffRecapsJob](../../app/Jobs/AI/KickoffRecapsJob.php) — the chain's last link, which stamps that column — re-sizes that plan against the history it just imported and then narrates it, once it finds a plan waiting. Regenerating first matters: narrating first would describe a week that is about to be replaced. Both call [PlanNarrationRequester::requestForFirstWeek()](../../app/Services/AI/PlanNarrationRequester.php), which never invalidates a row, so the interleaving where both fire costs nothing extra. Until one fires the Plan page has no `Analysis` row to render and omits the day rather than drawing a skeleton over a job nobody queued. See [[plan-periodizer]] and [[strava-connect]].

## Today's first briefing

`BriefingMascotVoice` is keyed by the day and was staged only by the 00:01 `ai:daily-briefing` kickoff, so an account created at any other hour met a silent Today card until the next midnight. `OnboardingController::store` now asks for it at the end of the wizard through [RequestTodaysBriefing::atSignup](../../app/Actions/AI/RequestTodaysBriefing.php#L29) ([OnboardingController.php:118](../../app/Http/Controllers/OnboardingController.php#L118)) — the same upsert the kickoff and the hourly `ai:catch-up` share, so an existing row is never duplicated and the demo account is served from the rule-based filler. The composer tolerates a zero-activity athlete (`hoursSince` is nullable), so the briefing is honest about a history that has not landed yet.

Which is why [KickoffRecapsJob](../../app/Jobs/AI/KickoffRecapsJob.php#L65) asks again: the same row, invalidated, once the backfill stamps `backfilled_at`. `BriefingMascotVoice` stamps no material fingerprint, so nothing else would re-open a Done row that read an empty history. That re-request is bounded to one per athlete per day, so a resync re-running the connect chain cannot re-bill the day's briefing. Until the first one lands, the Today card says so rather than staying blank — see [[dashboard]].

## See also

[[strava-connect]] · [[telegram-notifications]] · [[settings]] · [[landing]] — the public surface that carries the Past You promise before the wizard is ever reached
