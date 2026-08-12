---
title: Strava connection (OAuth, sync, webhook)
description: Connecting Strava, the manual "Sync now" button, and the live push webhook.
tags: [feature, strava]
status: living
reviewed: 2026-08-11
code_refs:
  - resources/js/pages/Auth/Login.tsx
  - app/Http/Controllers/Auth/StravaAuthController.php
  - app/Http/Controllers/Strava/SyncController.php
  - app/Http/Controllers/Strava/StravaWebhookController.php
  - app/Http/Controllers/Strava/ResyncActivityController.php
  - app/Http/Controllers/RunnerZonesController.php
  - resources/js/components/StravaSyncButton.tsx
  - resources/js/components/StravaSyncBadge.tsx
  - resources/js/components/StravaAction.tsx
  - resources/js/components/StravaPausedBanner.tsx
  - resources/js/components/FlashNotice.tsx
  - app/Services/Inertia/StravaProps.php
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
- On a *first-ever* connection it dispatches `SyncActivitiesJob` immediately so the dashboard isn't empty before the hourly poll, then redirects to the [[onboarding]] wizard instead of the dashboard; re-logins skip the backfill and land straight on `dashboard` (the per-user lock makes a redundant sync dispatch harmless anyway). That backfill is unbounded on purpose and still cheap: the walk pages 200 activity *summaries* per read and stores the athlete's whole history from them, so it costs a handful of Strava calls rather than two per run — see [[run-ingest-pipeline]].
- `logout()` clears the session — it does **not** revoke the Strava token.

Routes: `auth.strava.redirect` / `auth.strava.callback` in [web.php](../../routes/web.php).

## Manual sync ("Sync sekarang")

[StravaSyncButton](../../resources/js/components/StravaSyncButton.tsx) is the state-driven CTA on empty states. When `state === 'ready'` it `router.post('/strava/sync')`; when disconnected/revoked it shows the connect link instead; while a sync is in flight it renders nothing.

[SyncController](../../app/Http/Controllers/Strava/SyncController.php) (an `__invoke` single-action) just queues `SyncActivitiesJob` for the signed-in athlete and flashes a friendly message. A double-tap is safe — the orchestrator holds a per-user lock and the walk stops at the first already-known activity.

[StravaSyncBadge](../../resources/js/components/StravaSyncBadge.tsx) reflects status in the nav: a green dot + relative "synced" time when ready, a pulsing "Lagi sinkron" while syncing, an ember "Strava putus" when revoked.

Two more manual re-pulls exist beyond "Sync sekarang": [ResyncActivityController](../../app/Http/Controllers/Strava/ResyncActivityController.php) behind the run detail page's "Resync dari Strava", and `RunnerZonesController::resyncFromStrava` behind the Pengaturan zone "Sinkron ulang dari Strava" (which runs [SyncZonesJob](../../app/Jobs/Strava/SyncZonesJob.php) inline rather than queued).

## Kill-switch pause

The `/pulse` Strava kill-switch (`AppConfigKey::StravaEnabled`) is enforced downstream in [ActivityPipeline](../../app/Services/Run/Ingest/ActivityPipeline.php) and [SyncOrchestrator](../../app/Services/Run/Ingest/SyncOrchestrator.php), but downstream-only enforcement is invisible: the buttons above used to queue work and flash success for a pull that would never happen.

- **Shared state.** [StravaProps](../../app/Services/Inertia/StravaProps.php) shares a `stravaPaused` boolean, cached globally under `SharedPropCacheKey::StravaPaused` and busted by `SystemControl::toggleStrava()` so a flip lands on the next request. Only the pause *fact* crosses to the client, never the operator reason. `StravaSyncState` is deliberately untouched: other components branch on that union, and a fifth variant would force every one of them to handle it.
- **UI.** [StravaAction](../../resources/js/components/StravaAction.tsx) wraps each manual affordance and renders nothing while paused, so the control is *absent* rather than greyed out; [StravaPausedBanner](../../resources/js/components/StravaPausedBanner.tsx) carries the single calm explanation app-wide. Connect/reconnect links are **not** gated: OAuth still completes, and Strava is the only way to sign in.
- **Server.** All three re-pull controllers guard at the entry point and answer `back()->with('info', …)`, never a fake `success`. The downstream guards stay as belt and braces. That refusal is only honest if the user reads it, and until [FlashNotice](../../resources/js/components/FlashNotice.tsx) existed the redirect landed on a page with no flash renderer at all. It is mounted once in [AppShell](../../resources/js/layouts/AppShell.tsx) and covers every authenticated page — see [[frontend-architecture]].
- **Zone fetch.** `ActivityPipeline` / `SyncOrchestrator` never covered [ZoneFetcher](../../app/Services/Strava/ZoneFetcher.php), so the zone path still reached Strava with the switch off from two ungated callers: the on-connect `SyncZonesJob` dispatched by [StravaAuthController](../../app/Http/Controllers/Auth/StravaAuthController.php)`::callback`, and the monthly `strava:sync-zones` sweep. The switch is enforced inside `ZoneFetcher::fetch()` itself, returning its existing `null` "nothing to apply" result rather than at each caller. A fresh connect still connects and logs in; only the zone pull is suppressed, and since a `null` writes no `runner_profiles` row, nothing ever claims the zones synced.

## Webhook (live push)

[StravaWebhookController](../../app/Http/Controllers/Strava/StravaWebhookController.php) is unauthenticated by design — Strava calls it without a session.

- `verify()` (GET) answers the subscription handshake, echoing `hub.challenge` only when `hub.verify_token` matches our configured secret via `hash_equals`.
- `handle()` (POST) acks 200 fast and queues the work. Activity `create`/`update` → `SyncActivitiesJob` for that one activity. **Destructive events are treated as forgeable hints, not commands** (the body's `owner_id` is attacker-supplied): activity `delete` queues `CleanupDeletedActivityJob`, which only deletes after Strava confirms the activity truly 404s with the stored token; athlete `delete` / `updates.authorized === 'false'` queues [VerifyStravaRevocationJob](../../app/Jobs/Strava/VerifyStravaRevocationJob.php), which only `markRevoked()`s after `/athlete` returns a genuine 401. A live grant (2xx) means the event was forged/stale and is ignored. Unknown `owner_id` is a silent ack, never a leak. A `strava_webhook` Pulse heartbeat lets ops spot a delivery flatline.

The POST route is rate-limited (60/min) to blunt amplification. The circuit breaker / rate limiting downstream lives in [[strava-circuit-breaker-rate-limit]]; the edge runs behind Cloudflare per [[trust-all-proxies-cloudflare]].

## See also

[[run-ingest-pipeline]] · [[strava-circuit-breaker-rate-limit]] · [[trust-all-proxies-cloudflare]] · [[data-model]]
