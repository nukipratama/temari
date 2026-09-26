---
title: Strava connection (OAuth, sync, webhook)
description: Connecting Strava, the manual "Sync now" button, and the live push webhook.
tags: [feature, strava]
status: living
reviewed: 2026-09-26
code_refs:
  - resources/js/pages/Auth/Login.tsx
  - app/Http/Controllers/Auth/StravaAuthController.php
  - app/Http/Controllers/Strava/SyncController.php
  - app/Http/Controllers/Strava/StravaWebhookController.php
  - app/Http/Controllers/RunnerZonesController.php
  - resources/js/components/StravaSyncButton.tsx
  - resources/js/components/StravaAction.tsx
  - resources/js/components/StravaPausedBanner.tsx
  - resources/js/components/FlashNotice.tsx
  - app/Services/Inertia/StravaProps.php
  - app/Services/Strava/StravaClient.php
  - app/Services/Strava/StravaGrantLedger.php
  - app/Services/Strava/StravaGrantReleaseService.php
  - app/Jobs/Strava/RetryOrphanedStravaGrantReleasesJob.php
  - app/Console/Commands/Strava/SlotsCommand.php
  - app/Models/StravaGrantEvent.php
  - app/Models/StravaGrantToken.php
  - app/Console/Commands/Strava/RemoveAthleteCommand.php
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

The login screen is the front door. [Login.tsx](../../resources/js/pages/Auth/Login.tsx) renders a Strava-branded button whose `href` is the server-issued `authStravaUrl`; it is a plain `<a>` full navigation, not an Inertia visit (the brand mark and "Connect with Strava" label are a deliberate product decision, see the comment in the page).

Socialite drives the handshake in [StravaAuthController](../../app/Http/Controllers/Auth/StravaAuthController.php):

- `redirect()` requests scopes `read` and `activity:read_all`.
- `callback()` reads the *granted* scopes from Strava's `scope` query param (not what we asked for), then `upsertUser()` creates-or-updates the `User` + [StravaConnection](../../app/Models/StravaConnection.php) keyed on `strava_athlete_id`. A partial grant still saves but logs `strava.scopes.partial`. During maintenance a returning athlete still signs in, but a new athlete is refused before either row is written and the new grant is immediately deauthorized so it does not occupy a Strava athlete slot.
- On a *first-ever* connection it dispatches `SyncActivitiesJob` immediately so the dashboard isn't empty before the hourly poll, then redirects to the [[onboarding]] wizard instead of the dashboard. That backfill is unbounded on purpose and still cheap: the walk pages 200 activity *summaries* per read and stores the athlete's whole history from them, so it costs a handful of Strava calls rather than two per run — see [[run-ingest-pipeline]]. The dispatch is a `Bus::chain` whose last link, [KickoffRecapsJob](../../app/Jobs/AI/KickoffRecapsJob.php), stamps `users.backfilled_at` — the connect-completed marker, since the chain's own position is unreadable from outside it — and, if onboarding has already written a plan, re-sizes it against the history that just landed before narrating the athlete's first week (see [[plan-periodizer]]). Rows that predate the column stay null: nothing observed their backfill land. Active re-logins skip activity sync; reconnecting a revoked grant queues one normal incremental sync, which walks back to the latest activity already stored. An existing connection's `credential_version` advances with each successful OAuth reconnect so a delayed revocation result from the old grant cannot deactivate the new one. A reactivated connection with `profile:read_all` also queues a zone sync.
- `logout()` clears the session — it does **not** revoke the Strava token.

Routes: `auth.strava.redirect` / `auth.strava.callback` in [web.php](../../routes/web.php), both behind `throttle:strava-oauth` (10/min per IP, defined in [AppServiceProvider](../../app/Providers/AppServiceProvider.php)). This is the account-creation path and it is open to anyone, so it cannot key off a user id the way `strava-sync` and `analysis-trigger` do; it stays IP-keyed even for a signed-in reconnect. Terms, privacy, AI-use and the training disclaimer are linked from the login page footer so a stranger can read them before connecting — see [[legal-pages]].

## Manual sync ("Sync now")

[StravaSyncButton](../../resources/js/components/StravaSyncButton.tsx) is the state-driven CTA on empty states. When `state === 'ready'` it `router.post('/strava/sync')`; when disconnected/revoked it shows the connect link instead; while a sync is in flight it renders nothing.

[SyncController](../../app/Http/Controllers/Strava/SyncController.php) (an `__invoke` single-action) just queues `SyncActivitiesJob` for the signed-in athlete and flashes a friendly message. A double-tap is safe — the orchestrator holds a per-user lock and the walk stops at the first already-known activity.

There is no longer a sync-status badge in the nav. `StravaSyncBadge` showed a relative "Strava synced" time there and was cut in `T2`, because the prototype's topbar draws only the wordmark, a bell and an avatar. Its one load-bearing state was `revoked`, which was a live reconnect link; that survives in two places — [ProfileHero](../../resources/js/components/profile/ProfileHero.tsx)'s action, gated on exactly that state and reachable from the topbar avatar, and `StravaSyncButton` on the feed's empty state.

One more manual re-pull exists beyond "Sync now": `RunnerZonesController::resyncFromStrava` behind the HR-zones disclosure's "Resync from Strava" on [[settings-hr-zones]] (which runs [SyncZonesJob](../../app/Jobs/Strava/SyncZonesJob.php) inline rather than queued). The run detail page had a third — `ResyncActivityController` behind its own "Resync from Strava" — cut in `PP3` (P28) along with its route; [ResyncActivityJob](../../app/Jobs/Strava/ResyncActivityJob.php) survives, still driven by the webhook path.

## Kill-switch pause

The `/devtools/pulse` Strava kill-switch (`AppConfigKey::StravaEnabled`) is enforced downstream in [ActivityPipeline](../../app/Services/Run/Ingest/ActivityPipeline.php) and [SyncOrchestrator](../../app/Services/Run/Ingest/SyncOrchestrator.php), but downstream-only enforcement is invisible: the buttons above used to queue work and flash success for a pull that would never happen.

- **Shared state.** [StravaProps](../../app/Services/Inertia/StravaProps.php) shares a `stravaPaused` boolean, cached globally under `SharedPropCacheKey::StravaPaused` and busted by `SystemControl::toggleStrava()` so a flip lands on the next request. Only the pause *fact* crosses to the client, never the operator reason. `StravaSyncState` is deliberately untouched: other components branch on that union, and a fifth variant would force every one of them to handle it.
- **UI.** [StravaAction](../../resources/js/components/StravaAction.tsx) wraps each manual affordance and renders nothing while paused, so the control is *absent* rather than greyed out; [StravaPausedBanner](../../resources/js/components/StravaPausedBanner.tsx) carries the single calm explanation app-wide. Connect/reconnect links are **not** gated: OAuth still completes, and Strava is the only way to sign in.
- **Server.** All three re-pull controllers guard at the entry point and answer `back()->with('info', …)`, never a fake `success`. The downstream guards stay as belt and braces. That refusal is only honest if the user reads it, and until [FlashNotice](../../resources/js/components/FlashNotice.tsx) existed the redirect landed on a page with no flash renderer at all. It is mounted once in [AppShell](../../resources/js/layouts/AppShell.tsx) and covers every authenticated page — see [[frontend-architecture]].
- **Zone fetch.** `ActivityPipeline` / `SyncOrchestrator` never covered [ZoneFetcher](../../app/Services/Strava/ZoneFetcher.php), so the zone path still reached Strava with the switch off from two ungated callers: the on-connect `SyncZonesJob` dispatched by [StravaAuthController](../../app/Http/Controllers/Auth/StravaAuthController.php)`::callback`, and the monthly `strava:sync-zones` sweep. The switch is enforced inside `ZoneFetcher::fetch()` itself, returning its existing `null` "nothing to apply" result rather than at each caller. A fresh connect still connects and logs in; only the zone pull is suppressed, and since a `null` writes no `runner_profiles` row, nothing ever claims the zones synced.

## Releasing the grant

The current Strava grant is kept in an append-only `strava_grant_events` history and a separate `strava_grant_tokens` row. The mirrored refresh token is encrypted and versioned with the connection; neither table has a user foreign key, so the athlete id and release obligation survive account deletion. Only an accepted release or Strava's `401` / `invalid_grant` response removes the token. Failed releases retain the latest rotated refresh token for another attempt.

Local revocation and Strava release are separate facts. Local revocation stops Temari reading and leaves the athlete occupying a Strava slot until Strava accepts the deauthorize request (or confirms the grant is already invalid). [UserEraser](../../app/Services/User/UserEraser.php) attempts release before deleting the account; if Strava is unreachable, the user is still deleted and the orphan token remains for retry. `RetryOrphanedStravaGrantReleasesJob` retries every grant without an active local connection daily, with no age cutoff, and excludes the demo user.

`strava:slots` lists current holders, release attempts, and the latest error. `--release=<athlete-id>` attempts one release and locally revokes an active connection while leaving its account intact. `--release-orphans` attempts all non-demo grants without an active connection. Both prompt before making the request unless `--force` is supplied. `strava:remove-athlete {user}` remains the operator path that releases a grant and permanently removes the account; it refuses the demo account. A new OAuth callback during maintenance records and immediately attempts release of the refused grant, while returning athletes keep their existing connection. Note the dated obligation in [[strava-data-compliance]] — `oauth/deauthorize` becomes `oauth/revoke` on 2027-06-01.

## Webhook (live push)

[StravaWebhookController](../../app/Http/Controllers/Strava/StravaWebhookController.php) is unauthenticated by design — Strava calls it without a session.

- `verify()` (GET) answers the subscription handshake, echoing `hub.challenge` only when `hub.verify_token` matches our configured secret via `hash_equals`.
- `handle()` (POST) acks 200 fast and queues the work. Activity `create`/`update` → `SyncActivitiesJob` for that one activity. **Destructive events are treated as forgeable hints, not commands** (the body's `owner_id` is attacker-supplied): activity `delete` queues `CleanupDeletedActivityJob`, which only deletes after Strava confirms the activity truly 404s with the stored token; athlete `delete` / `updates.authorized === 'false'` queues [VerifyStravaRevocationJob](../../app/Jobs/Strava/VerifyStravaRevocationJob.php), which only `markRevoked()`s after `/athlete` returns a genuine 401. A live grant (2xx) means the event was forged/stale and is ignored. Unknown `owner_id` is a silent ack, never a leak. A `strava_webhook` Pulse heartbeat lets ops spot a delivery flatline.

The POST route is rate-limited (60/min) to blunt amplification. The circuit breaker / rate limiting downstream lives in [[strava-circuit-breaker-rate-limit]]; the edge runs behind Cloudflare per [[trust-all-proxies-cloudflare]].

## See also

[[run-ingest-pipeline]] · [[strava-circuit-breaker-rate-limit]] · [[trust-all-proxies-cloudflare]] · [[data-model]]
