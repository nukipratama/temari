---
title: Strava connection (OAuth, sync, webhook)
description: Connecting Strava, the manual "Sync now" button, and the live push webhook.
tags: [feature, strava]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Auth/Login.tsx
  - app/Http/Controllers/Auth/StravaAuthController.php
  - app/Http/Controllers/Strava/SyncController.php
  - app/Http/Controllers/Strava/StravaWebhookController.php
  - resources/js/components/StravaSyncButton.tsx
  - resources/js/components/StravaSyncBadge.tsx
  - routes/web.php
---

# Strava connection

Strava is the only identity in the app — there is no email/password. Signing in *is* connecting Strava. This note covers the three user-facing moments: the OAuth connect, the manual re-pull, and the live webhook push. The mechanics that turn a fetched activity into a card live in [[run-ingest-pipeline]].

**Navigation:** Login page at `route('login')` → `/login` (pre-auth). OAuth callback: `auth.strava.callback`. Named routes: `login`, `auth.strava.redirect`, `auth.strava.callback`.

## System dependencies

- **Strava client** — the HTTP client, circuit breaker, and rate-limit guards in [[strava-client]].
- **Ingestion** — sync dispatches `SyncActivitiesJob`, which feeds the [[run-ingest-pipeline]].
- **Circuit breaker** — rate-limit resilience is detailed in [[strava-circuit-breaker-rate-limit]].
- **Edge** — webhook runs behind Cloudflare per [[trust-all-proxies-cloudflare]].

## Connect (OAuth)

The login screen is the front door. [Login.tsx](../../resources/js/pages/Auth/Login.tsx) renders a Strava-branded button whose `href` is the server-issued `authStravaUrl`; it is a plain `<a>` full navigation, not an Inertia visit (the brand mark and "Sambungkan dengan Strava" label are a deliberate product decision, see the comment in the page).

Socialite drives the handshake in [StravaAuthController](../../app/Http/Controllers/Auth/StravaAuthController.php):

- `redirect()` requests scopes `read` and `activity:read_all`.
- `callback()` reads the *granted* scopes from Strava's `scope` query param (not what we asked for), then `upsertUser()` creates-or-updates the `User` + [StravaConnection](../../app/Models/StravaConnection.php) keyed on `strava_athlete_id`. A partial grant still saves but logs `strava.scopes.partial`.
- On a *first-ever* connection it dispatches `SyncActivitiesJob` immediately so the dashboard isn't empty before the hourly poll; re-logins skip the backfill (the per-user lock makes a redundant dispatch harmless anyway).
- `logout()` clears the session — it does **not** revoke the Strava token.

Routes: `auth.strava.redirect` / `auth.strava.callback` in [web.php](../../routes/web.php).

## Manual sync ("Sync sekarang")

[StravaSyncButton](../../resources/js/components/StravaSyncButton.tsx) is the state-driven CTA on empty states. When `state === 'ready'` it `router.post('/strava/sync')`; when disconnected/revoked it shows the connect link instead; while a sync is in flight it renders nothing.

[SyncController](../../app/Http/Controllers/Strava/SyncController.php) (an `__invoke` single-action) just queues `SyncActivitiesJob` for the signed-in athlete and flashes a friendly message. A double-tap is safe — the orchestrator holds a per-user lock and the walk stops at the first already-known activity.

[StravaSyncBadge](../../resources/js/components/StravaSyncBadge.tsx) reflects status in the nav: a green dot + relative "synced" time when ready, a pulsing "Lagi sinkron" while syncing, an ember "Strava putus" when revoked.

## Webhook (live push)

[StravaWebhookController](../../app/Http/Controllers/Strava/StravaWebhookController.php) is unauthenticated by design — Strava calls it without a session.

- `verify()` (GET) answers the subscription handshake, echoing `hub.challenge` only when `hub.verify_token` matches our configured secret via `hash_equals`.
- `handle()` (POST) acks 200 fast and queues the work. Activity `create`/`update` → `SyncActivitiesJob` for that one activity; activity `delete` → cascade-deletes the local row; athlete `delete` or `updates.authorized === 'false'` → `markRevoked()`. Unknown `owner_id` is a silent ack, never a leak. A `strava_webhook` Pulse heartbeat lets ops spot a delivery flatline.

The POST route is rate-limited (60/min) to blunt amplification. The kill-switch / rate limiting downstream lives in [[strava-circuit-breaker-rate-limit]]; the edge runs behind Cloudflare per [[trust-all-proxies-cloudflare]].

## See also

[[run-ingest-pipeline]] · [[strava-circuit-breaker-rate-limit]] · [[trust-all-proxies-cloudflare]] · [[data-model]]
