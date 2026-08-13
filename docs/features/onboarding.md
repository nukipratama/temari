---
title: Onboarding wizard + coach-mark mechanism
description: The two-step post-connect wizard, its DB-backed gate, and the reusable coach-mark anchoring mechanism.
tags: [feature, onboarding]
status: living
reviewed: 2026-08-12
code_refs:
  - app/Http/Controllers/OnboardingController.php
  - app/Http/Middleware/EnsureOnboarded.php
  - app/Http/Requests/CompleteOnboardingRequest.php
  - app/Models/User.php
  - app/Http/Controllers/Auth/StravaAuthController.php
  - resources/js/pages/Onboarding/Index.tsx
  - resources/js/hooks/useCoachMark.ts
  - resources/js/components/onboarding/CoachMark.tsx
  - routes/web.php
---

# Onboarding

A minimal two-step wizard shown once, right after a user's *first* Strava connect, plus a reusable coach-mark mechanism for later contextual hints elsewhere in the app.

## The gate

`users.onboarded_at` (nullable timestamp) is the source of truth — DB-backed, not session/client state, so an abandoned wizard resumes on a later visit or a different device. The migration backfills every pre-existing row to `now()` at deploy time, so only accounts created afterward ever see the wizard.

[EnsureOnboarded](../../app/Http/Middleware/EnsureOnboarded.php) (alias `onboarded`) gates the main authenticated route group in [web.php](../../routes/web.php); the wizard routes (`onboarding.show` / `onboarding.store`) and logout sit in a plain `auth`-only group so a user stuck mid-wizard can still sign out. `OnboardingController::show` itself redirects an already-onboarded user straight to `dashboard`, so direct navigation to `/onboarding` can never re-trigger it.

## Trigger

[StravaAuthController::callback](../../app/Http/Controllers/Auth/StravaAuthController.php) redirects to `onboarding.show` when `$isFreshConnection` is true (a brand-new `User` + `StravaConnection` row), overriding any stashed intended deep-link for that one redirect — see [[strava-connect]] for the rest of the OAuth flow. A returning user's reconnect never re-enters the wizard, and it never fires for the demo account (its seeded row is marked onboarded on creation in [DemoRunSeeder](../../database/seeders/Demo/DemoRunSeeder.php)).

## The wizard

[Onboarding/Index.tsx](../../resources/js/pages/Onboarding/Index.tsx) runs inside the normal authenticated shell (`appLayout`/`AppShell`), not the bare login shell — two steps, no persona/experience questions (deliberately out of scope):

1. **Strava-connect confirmation** — a `pose="glow"` Temari, confirms the connection and that history is backfilling.
2. **Optional first race goal** — the same shape as `/race` (`race_date`/`distance_m`/`goal_time_sec`/`name`, validated by [CompleteOnboardingRequest](../../app/Http/Requests/CompleteOnboardingRequest.php) with `required_with` making the three core fields all-or-nothing). "Skip for now" posts an empty payload regardless of unsaved input.

`OnboardingController::store` creates the `RaceGoal` (if goal fields were sent) and calls `User::markOnboarded()`, then redirects to `dashboard`.

## Coach-mark mechanism

[useCoachMark](../../resources/js/hooks/useCoachMark.ts) + [CoachMark](../../resources/js/components/onboarding/CoachMark.tsx) are a general-purpose "point at any DOM element, dismiss once, never show again for this user" primitive. Dismissal is localStorage-persisted, keyed per signed-in user id so a shared/demo browser can't leak one account's dismissals into another's. `CoachMark` takes an external `anchorRef` and portals a positioned callout next to it (`top`/`bottom`/`left`/`right`), closing on Escape/outside-click via the existing `usePopover` primitive.

### Where marks are mounted

One mark per page, at the anchor that teaches a genuinely non-obvious interaction — [Home.tsx:154](../../resources/js/pages/Home.tsx#L154) (a run mints a card), [Activities/Feed.tsx:147](../../resources/js/pages/Activities/Feed.tsx#L147) (the filter sheet), [Activities/Calendar.tsx:171](../../resources/js/pages/Activities/Calendar.tsx#L171) (a day opens its run), [Runs/Show.tsx:496](../../resources/js/pages/Runs/Show.tsx#L496) (the card shares as an image), [Plan.tsx:436](../../resources/js/pages/Plan.tsx#L436) (upcoming days are editable), [Collection/Cards.tsx:219](../../resources/js/pages/Collection/Cards.tsx#L219) (a card opens its run), and [Collection/Accessories.tsx:192](../../resources/js/pages/Collection/Accessories.tsx#L192) (equipping updates the preview). The remaining `data-coachmark` anchors stay unmounted on purpose: where the surrounding copy or a control's own label already explains the interaction, a callout is noise, and two marks competing on one page fight each other (an outside click on either dismisses the other).

**A mark must render after its anchor in tree order.** React attaches a parent's ref only once its children's layout effects have run, so a mark nested inside — or placed before — its anchor measures a null element on mount and silently never appears.

## See also

[[strava-connect]]
