---
title: Strava API client resilience
description: How the Strava client wrapper survives outages, rate limits, and revocations — circuit breaker, global rate buckets, per-connection token refresh, and how each upstream error routes.
tags: [architecture, strava]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/Strava/StravaClient.php
  - app/Services/Strava/StravaCircuitBreaker.php
  - app/Models/StravaConnection.php
  - app/Support/Config/AppConfigKey.php
  - app/Jobs/Strava/SyncActivitiesJob.php
  - app/Jobs/Strava/IngestActivityJob.php
  - app/Livewire/Pulse/SystemControl.php
---

# Strava API client resilience

[StravaClient](app/Services/Strava/StravaClient.php) is the single chokepoint for every read against the Strava REST API. This note is the operational **how** — what each guard does and how to diagnose it. The **why** (per-client vs per-user keying, the breaker rationale) lives in the ADR [[strava-circuit-breaker-rate-limit]]; the user-facing flows that drive these reads are [[strava-connect]] and [[run-ingest-pipeline]].

## The request gauntlet

Every call goes through [`StravaClient::get()`](app/Services/Strava/StravaClient.php#L50), which runs four guards in a fixed order before the HTTP call and one router after it:

1. **Breaker gate** — bail out fast if the circuit is [open](app/Services/Strava/StravaClient.php#L53) (throws `StravaCircuitOpenException`, no HTTP call made).
2. **Token freshness** — [`refreshIfExpired()`](app/Services/Strava/StravaClient.php#L59) rotates an expiring access token (see below).
3. **Rate-limit guard** — [`guardRateLimit()`](app/Services/Strava/StravaClient.php#L61) throws before spending a request we don't have budget for.
4. **The HTTP call**, wrapped so a transport failure / timeout is caught and [counted against the breaker](app/Services/Strava/StravaClient.php#L69).
5. **Status routing** on the response (next section).

## Error routing — the load-bearing distinction

The whole design hinges on classifying *why* a call failed, because each cause wants a different reaction. [`get()`](app/Services/Strava/StravaClient.php#L50) routes the response status:

| Upstream signal | Throws | Touches breaker? | Caller reaction |
| --- | --- | --- | --- |
| `401` | [`StravaConnectionRevokedException`](app/Services/Strava/StravaClient.php#L74) | no | revoke the connection |
| `429` | [`StravaRateLimitedException`](app/Services/Strava/StravaClient.php#L82) (seeded with `Retry-After`) | no | back off, Strava is up |
| `5xx` | re-throws after [`recordFailure()`](app/Services/Strava/StravaClient.php#L93) | **yes** | back off, may open breaker |
| timeout / connection error | re-throws after [`recordFailure()`](app/Services/Strava/StravaClient.php#L69) | **yes** | back off, may open breaker |
| `2xx` (or non-5xx 4xx like 404) | returns | clears via [`recordSuccess()`](app/Services/Strava/StravaClient.php#L101) | proceed |

Only genuine *Strava-is-down* signals (5xx + timeouts) move the breaker. A `401` is one athlete's problem and a `429` means Strava is healthy but busy — neither should trip a global breaker. The two job consumers act on each exception: [SyncActivitiesJob](app/Jobs/Strava/SyncActivitiesJob.php#L57) maps revocations to `markRevoked()`, releases on rate-limit/transient-refresh, and drops silently on an open breaker; [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php#L61) routes both rate-limit and open-breaker through a `ThrottlesExceptions` middleware so a backoff doesn't burn its failure budget.

## The circuit breaker

[StravaCircuitBreaker](app/Services/Strava/StravaCircuitBreaker.php) is a three-state machine whose state is **durable** in the `app_config` table (not cache), so it survives restarts and is shared across containers.

- **closed** → normal. [`recordFailure()`](app/Services/Strava/StravaCircuitBreaker.php#L80) increments a counter; once it reaches the [threshold](app/Support/Config/AppConfigKey.php#L16) the breaker [`open()`](app/Services/Strava/StravaCircuitBreaker.php#L125)s and stamps `opened_at`.
- **open** → [`allowsRequest()`](app/Services/Strava/StravaCircuitBreaker.php#L42) blocks every call until the [cooldown](app/Support/Config/AppConfigKey.php#L17) elapses past `opened_at`, then flips to half-open to let exactly one probe through.
- **half-open** → the single probe decides: a success [`reset()`](app/Services/Strava/StravaCircuitBreaker.php#L101)s to closed; a failure [re-opens and restarts the cooldown](app/Services/Strava/StravaCircuitBreaker.php#L86).

Threshold and cooldown are tunable `app_config` keys with code defaults in [AppConfigKey](app/Support/Config/AppConfigKey.php#L28); the three runtime keys (`state` / `failures` / `opened_at`) are breaker-managed, never hand-tuned.

**Concurrency:** state-mutating paths take a short [`Cache::lock`](app/Services/Strava/StravaCircuitBreaker.php#L149) and [`forgetState()`](app/Services/Strava/StravaCircuitBreaker.php#L142) (drop the per-request memo, see [[data-model]] on `AppConfig`) so they re-read fresh counters under the lock. [`recordSuccess()`](app/Services/Strava/StravaCircuitBreaker.php#L69) has a fast path that skips the lock and write entirely when already closed with zero failures — the common healthy case.

## Global rate-limit buckets

[`guardRateLimit()`](app/Services/Strava/StravaClient.php#L213) checks two Laravel `RateLimiter` buckets (a short 15-min window and a daily one) before hitting both. The keys from [`rateLimitKey()`](app/Services/Strava/StravaClient.php#L235) carry **no `user_id`** — the budget is app-wide because Strava meters per OAuth client, not per athlete (the [[strava-circuit-breaker-rate-limit]] ADR is the rationale). Exhaustion records a `strava_rate_limited` Pulse event and throws `StravaRateLimitedException`.

> **Gotcha:** [`rateLimitRemaining(int $userId)`](app/Services/Strava/StravaClient.php#L133) still takes a `$userId` for call-site compatibility but **ignores it** for keying — every athlete sees the same shared headroom. Do not reintroduce the id into the key. Note the local guard's exhaustion and a real upstream `429` both surface as `StravaRateLimitedException`; only the local one is preventable by us.

## Per-connection token refresh

[`refreshIfExpired()`](app/Services/Strava/StravaClient.php#L141) on the client (returning a refreshed [StravaConnection](app/Models/StravaConnection.php)) rotates an access token that's within the [refresh buffer](app/Services/Strava/StravaClient.php#L27) of expiry. It takes a [`strava-refresh:{id}` lock](app/Services/Strava/StravaClient.php#L150), then **re-reads inside the lock** before refreshing — because Strava rotates the `refresh_token` on every exchange, two concurrent workers refreshing the same connection would mutually invalidate each other's new token. This lock is intentionally **per-connection**, unlike the global rate buckets and global breaker.

Refresh failures classify just like reads: a [`400 invalid_grant`](app/Services/Strava/StravaClient.php#L189) is permanent deauthorization (`StravaTokenRefreshFailedException` → revoke), while `401` / `429` / `5xx` / connection errors are [transient](app/Services/Strava/StravaClient.php#L199) (`StravaTokenRefreshTransientException` → release & back off). Revoking a healthy connection over a momentary blip would purge its un-ingested stubs — see [`markRevoked()`](app/Models/StravaConnection.php#L67), which cascades-deletes that user's pending stubs.

## Diagnosing & resetting a wedged breaker

The breaker state lives in `app_config`, so a stuck-open breaker stays open across restarts until cooldown elapses (or the upstream recovers on the half-open probe). To inspect or force it:

- **Inspect** — the `/pulse` superadmin card renders the breaker [`snapshot()`](app/Livewire/Pulse/SystemControl.php#L54) (state / failures / opened_at) alongside the ingest backlog; `open` shows as an alert, `half_open` as a warning.
- **Force-close** — the same card's [`resetBreaker()`](app/Livewire/Pulse/SystemControl.php#L37) calls `reset()`, closing it and zeroing the counter immediately. Use it after confirming Strava has recovered rather than waiting out the cooldown. Access to `/pulse` is edge basic-auth in prod (see [[deployment]]).

## See also

[[strava-circuit-breaker-rate-limit]] · [[strava-connect]] · [[run-ingest-pipeline]] · [[data-model]] · [[deployment]]
