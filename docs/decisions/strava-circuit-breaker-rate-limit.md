---
title: Strava rate limiting is per-client (global), plus a circuit breaker
description: The local rate-limit guard is keyed globally per API app (never per user), and a circuit breaker fails fast during Strava outages.
tags: [decision, strava]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Services/Strava/StravaClient.php
  - app/Services/Strava/StravaCircuitBreaker.php
---

# Strava rate limiting is per-client (global), plus a circuit breaker

**Status:** Accepted (documented 2026-06-20)

## Context

Strava enforces its rate limit per **API application** (the OAuth client), not per athlete: Read limits of 200 / 15 min and 2000 / day, with Overall limits of 400 / 15 min and 4000 / day sitting above them. Every connected user's calls draw from that one shared budget. If our local guard keyed its buckets per `user_id`, N users would each get a full private allowance and could collectively blow the single shared limit, getting the whole app 429'd. Separately, a sustained Strava outage (5xx / timeouts) would otherwise be hammered on every sync cycle with no fast-fail.

## Decision

We decided the local rate-limit guard is **keyed globally** and a **circuit breaker** trips on transient outages, both in [StravaClient::get()](app/Services/Strava/StravaClient.php).

- **Rate-limit buckets are app-wide.** [`rateLimitKey()`](app/Services/Strava/StravaClient.php) returns `strava-api:15min` / `strava-api:daily` with no `user_id` in the key, so [`guardRateLimit()`](app/Services/Strava/StravaClient.php) throttles every athlete against one shared bucket sized to Strava's Read limits. `rateLimitRemaining(int $userId)` still takes a `$userId` for call-site compatibility but ignores it for keying — every caller sees the same headroom.
- **The refresh lock is per-connection.** `refreshIfExpired()` locks on `strava-refresh:{id}` so two workers can't both rotate the same refresh token; that key is intentionally per-connection, unlike the global rate buckets.
- **Failures route by cause.** A `401` throws `StravaConnectionRevokedException` (that connection's token is revoked) and leaves the breaker untouched; a `429` throws `StravaRateLimitedException` (back off, Strava is up); only `5xx` / `ConnectionException` call `recordFailure()`.
- **The breaker is global and durable.** [StravaCircuitBreaker](app/Services/Strava/StravaCircuitBreaker.php) counts transient failures in `app_config`; once the threshold is hit it `open()`s, blocks calls during the cooldown, then half-opens one probe. Only 5xx/timeouts count toward it — never 401 or 429.

## Consequences

- **Enables:** the shared Strava budget can't be overspent no matter how many users connect, and outages stop being hammered every cycle once the breaker opens.
- **Costs:** the global bucket means one heavy user's backfill can throttle everyone; there's no per-user fairness. The breaker's durable `app_config` state means a wedged-open breaker stays open across restarts until cooldown elapses.
- **Gotchas:** a Strava-side `429` is distinct from our local `guardRateLimit()` exhaustion — both surface as `StravaRateLimitedException` but only the local one is preventable by us. The `$userId` parameter on `rateLimitRemaining()` is a deliberate no-op; don't reintroduce it into the key.

## See also

- [[run-ingest-pipeline]] — where Strava reads feed run ingest
- [[deployment]] — the homelab stack the client runs on
