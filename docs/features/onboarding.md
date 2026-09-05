---
title: Onboarding wizard
description: The three-step post-connect wizard and its DB-backed gate.
tags: [feature, onboarding]
status: living
reviewed: 2026-09-05
code_refs:
  - app/Http/Controllers/OnboardingController.php
  - app/Http/Middleware/EnsureOnboarded.php
  - app/Http/Requests/CompleteOnboardingRequest.php
  - app/Models/TrainingPreference.php
  - app/Models/User.php
  - app/Http/Controllers/Auth/StravaAuthController.php
  - app/Services/Run/Ingest/DetailHydrator.php
  - resources/js/pages/Onboarding/Index.tsx
  - resources/js/components/onboarding/StepProgress.tsx
  - resources/js/components/onboarding/IconChoiceCard.tsx
  - resources/js/components/onboarding/SessionsDial.tsx
  - resources/js/components/onboarding/DayPicker.tsx
  - resources/js/lib/raceGoal.ts
  - routes/web.php
---

# Onboarding

A minimal three-step wizard shown once, right after a user's *first* Strava connect.

## The gate

`users.onboarded_at` (nullable timestamp) is the source of truth — DB-backed, not session/client state, so an abandoned wizard resumes on a later visit or a different device. The migration backfills every pre-existing row to `now()` at deploy time, so only accounts created afterward ever see the wizard.

[EnsureOnboarded](../../app/Http/Middleware/EnsureOnboarded.php) (alias `onboarded`) gates the main authenticated route group in [web.php](../../routes/web.php); the wizard routes (`onboarding.show` / `onboarding.store`) and logout sit in a plain `auth`-only group so a user stuck mid-wizard can still sign out. `OnboardingController::show` itself redirects an already-onboarded user straight to `dashboard`, so direct navigation to `/onboarding` can never re-trigger it.

## Trigger

[StravaAuthController::callback](../../app/Http/Controllers/Auth/StravaAuthController.php) redirects to `onboarding.show` when `$isFreshConnection` is true (a brand-new `User` + `StravaConnection` row), overriding any stashed intended deep-link for that one redirect — see [[strava-connect]] for the rest of the OAuth flow. A returning user's reconnect never re-enters the wizard, and it never fires for the demo account (its seeded row is marked onboarded on creation in [DemoRunSeeder](../../database/seeders/Demo/DemoRunSeeder.php)).

## The wizard

[Onboarding/Index.tsx](../../resources/js/pages/Onboarding/Index.tsx) runs inside the normal authenticated shell (`appLayout`/`AppShell`), not the bare login shell — three steps, tracked by a persistent [StepProgress](../../resources/js/components/onboarding/StepProgress.tsx) bar (Welcome / Training / Race Goal):

1. **Strava-connect confirmation** — a `pose="glow"` Temari, and a panel naming exactly what just landed and what has not. The first sync writes every historical run in `summary` state; splits, HR zones, effort and the run's card come from a second, per-run fetch that [DetailHydrator](../../app/Services/Run/Ingest/DetailHydrator.php) only queues when the run is opened or picked as a Past You comparison (see [[run-ingest-pipeline]]). Saying so here is what stops a new account reading its empty card collection as a bug.
2. **Optional training preferences** — one question per screen (experience level, sessions per week, goal type, then run days) instead of one flat form, each answer choosable via [IconChoiceCard](../../resources/js/components/onboarding/IconChoiceCard.tsx) / [SessionsDial](../../resources/js/components/onboarding/SessionsDial.tsx) / [DayPicker](../../resources/js/components/onboarding/DayPicker.tsx) and auto-advancing on tap; a back chevron revisits a prior question without discarding its answer, and a per-question "Skip this" link leaves that one field blank while keeping the rest. This is the same `experience_level`/`sessions_per_week`/`goal_type`/`run_days`/`long_run_day` shape [TrainingPreference](../../app/Models/TrainingPreference.php) and `/settings` already use — the wizard is just another writer of the same row (`updateOrCreate` keyed on `user_id` in `OnboardingController::store`). The days question is only reachable once a sessions target exists (nothing to pick otherwise) and is skipped straight through to the race-goal step when sessions was itself left blank. The header "Skip for now" pill discards every partial pick made across all four questions and jumps straight to step 3, matching the race-goal step's own "Skip for now".
3. **Optional first race goal** — the same shape as `/race` (`race_date`/`distance_m`/`goal_time_sec`/`name`, validated by [CompleteOnboardingRequest](../../app/Http/Requests/CompleteOnboardingRequest.php) with `required_with` making the three core fields all-or-nothing), plus a decorative "required pace" ring computed client-side from the distance/time fields already entered (not a fitness assessment, purely a fill animation). "Skip for now" posts an empty payload regardless of unsaved input.

The goal form only offers submissions the server can accept. [raceGoal.ts](../../resources/js/lib/raceGoal.ts) mirrors the request's `after:today` and `between:300,259200` bounds into the date input's `min` and a disabled submit, and server-side field errors render beside the field that caused them. Before that, blanking the minutes field produced a `goal_time_sec` of 0 — a submit that could only ever 422, explained by nothing nearer than the global error banner. `/race` shares the same helper for the same reason ([[race-projection]]).

`OnboardingController::store` creates the `RaceGoal` (if goal fields were sent), upserts `TrainingPreference` (if any preference field was sent) and calls `User::markOnboarded()`, then redirects to `dashboard`.

## See also

[[strava-connect]] · [[landing]] — the public surface that carries the Past You promise before the wizard is ever reached
