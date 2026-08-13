---
title: Landing page + stranger signup
description: The public surface at /login that sells "you vs past you" before it asks for Strava access, and the honest failure paths around it.
tags: [feature, onboarding]
status: living
reviewed: 2026-08-13
code_refs:
  - app/Http/Controllers/Auth/LoginController.php
  - app/Http/Controllers/Auth/StravaAuthController.php
  - app/Support/DataUseStatement.php
  - app/Support/TrainingDisclaimer.php
  - app/Providers/AppServiceProvider.php
  - resources/js/pages/Auth/Login.tsx
  - resources/views/errors/429.blade.php
  - scripts/check-entry-chunks.mjs
---

# Landing page

`/login` is the only page a stranger sees, so it is the landing page, not a sign-in form. It carries the product premise in full before the Strava button appears: **every run is measured against your own comparable history**, which is [[past-you-engine]] stated in prose. No leaderboards and no cross-user read is a Strava platform requirement as much as a product line, so the copy states it as a promise rather than a feature.

## It explains before it asks

The page reads top to bottom as promise → mechanism → proof → cost → ask:

1. Hero: the positioning, a Temari-voice line, and the first `ConnectPanel`.
2. How the comparison works: the three matching rules, ending on the one that sells the honesty (under two fair pairings, the verdict is "not enough history yet" rather than an invented trend). All three paraphrase [[past-you-engine]]; if the matcher's rules change, this section is wrong.
3. What you get: a live [KartuMini](../../resources/js/components/card/KartuMini.tsx) rendered from a real polyline, not a mockup image.
4. Your data, then the training disclaimer.
5. A closing `ConnectPanel`.

## Copy comes from the server, never retyped

[LoginController](../../app/Http/Controllers/Auth/LoginController.php) hands down `dataUse` and `trainingDisclaimer` from [DataUseStatement](../../app/Support/DataUseStatement.php) and [TrainingDisclaimer](../../app/Support/TrainingDisclaimer.php) — the same constants `/settings`, `/plan` and the public documents read. A test in [StravaAuthTest](../../tests/Feature/Auth/StravaAuthTest.php) asserts prop equality against those constants, so a retype fails rather than silently drifting. Both sections render only when the prop is present, so the page degrades to promise + ask rather than to a half-stated legal claim.

The four legal links stay **plain `<a>` anchors**, not Inertia `<Link>`s: they are what a stranger reads before deciding to connect, so they must resolve even if the SPA runtime never boots. See [[legal-pages]].

## The Strava mark sits on neutral ground

`ConnectPanel` is the one component that renders the Strava brand mark, and it is deliberately a `surface-sunken` card with no gold or sky accent on it — Strava's brand guidelines want the mark left alone and given room, and a violation is an API-access revocation risk, not a taste note. The panel renders twice (hero and closing CTA) from one component, so the two can never drift apart.

## Failure paths are honest

- **OAuth denial or a failed token exchange** redirects back with `withErrors()`, surfaced by the `ErrorBanner` that [BareShell](../../resources/js/layouts/BareShell.tsx) mounts. Removing `bareLayout` from this page silently removes that banner.
- **The IP-keyed throttle.** `/auth/strava/redirect` and `/auth/strava/callback` sit behind `throttle:strava-oauth` (10/min per IP, [AppServiceProvider](../../app/Providers/AppServiceProvider.php)) because no session exists to key a limit by until the callback lands — see [[strava-connect]]. Tripping it now renders the branded [429 page](../../resources/views/errors/429.blade.php), which says the attempt was not forwarded to Strava and that the connect has to restart from the beginning. A bare framework 429 left the visitor unable to tell whether retrying was safe, which matters because a tripped callback has already burned its authorization code.

## Budget

This page is the only route an unauthenticated visitor loads, so [check-entry-chunks.mjs](../../scripts/check-entry-chunks.mjs) holds its cold first paint under a gzipped budget and `bareLayout` is kept framer-motion-free. The hero's route-trace and glow animations are therefore plain CSS keyframes in [app.css](../../resources/css/app.css), and `KartuMini` is behind `lazy()` because its rarity chrome imports framer-motion statically.

## See also

[[strava-connect]] · [[onboarding]] · [[legal-pages]] · [[past-you-engine]]
