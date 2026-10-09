---
title: Strava API client resilience
description: How the Strava client wrapper survives outages, rate limits, and revocations — circuit breaker, global rate buckets, per-connection token refresh, and how each upstream error routes.
tags: [architecture, strava]
status: living
reviewed: 2026-10-09
code_refs:
  - app/Services/Strava/StravaClient.php
  - app/Enums/StravaReadPriority.php
  - app/Services/Strava/StravaCircuitBreaker.php
  - app/Models/Analytics/StravaRead.php
  - database/migrations/analytics/2026_09_23_010000_create_strava_reads_table.php
  - app/Models/StravaConnection.php
  - app/Support/Config/AppConfigKey.php
  - app/Jobs/Strava/SyncActivitiesJob.php
  - app/Jobs/Strava/IngestActivityJob.php
  - app/Livewire/Pulse/SystemControl.php
---

# Strava API client resilience

[StravaClient](app/Services/Strava/StravaClient.php) is the single chokepoint for every read against the Strava REST API. This note is the operational **how** — what each guard does and how to diagnose it. The **why** (per-client vs per-user keying, the breaker rationale) lives in the ADR [[strava-circuit-breaker-rate-limit]]; the user-facing flows that drive these reads are [[strava-connect]] and [[run-ingest-pipeline]].

## The API host is configuration, not a constant

Every read is issued against [`StravaClient::apiBaseUrl()`](app/Services/Strava/StravaClient.php), which reads `services.strava.api_base_url` ([config/services.php](config/services.php)) with `STRAVA_API_BASE_URL` as the override. Strava's replacement host `https://api-v3.strava.com` **starts serving on 2027-01-04**, and no shutdown date has been announced for `https://www.strava.com/api/v3` — so the default stays on the host that answers today, and the cutover is an env change plus a redeploy rather than a code change. **Do not set the override early:** `api-v3.strava.com` is NXDOMAIN until it launches, so an early flip breaks every read at once. OAuth (`/oauth/token`) is not moving and stays a constant. The webhook `push_subscriptions` calls in [WebhookSubscribeCommand](app/Console/Commands/Strava/WebhookSubscribeCommand.php) derive from the same value, and the Pulse outgoing-request grouping in [config/pulse.php](config/pulse.php) matches both hosts so the "slow Strava call" row survives the switch.

## The request gauntlet

Every call goes through [`StravaClient::get()`](app/Services/Strava/StravaClient.php), which runs four guards in a fixed order before the HTTP call and one router after it:

1. **Breaker gate** — bail out fast if the circuit is [open](app/Services/Strava/StravaClient.php) (throws `StravaCircuitOpenException`, no HTTP call made).
2. **Token freshness** — [`refreshIfExpired()`](app/Services/Strava/StravaClient.php) rotates an expiring access token (see below).
3. **Rate-limit guard** — [`guardRateLimit()`](app/Services/Strava/StravaClient.php) throws before spending a request we don't have budget for.
4. **The HTTP call**, wrapped so a transport failure / timeout is caught and [counted against the breaker](app/Services/Strava/StravaClient.php).
5. **Status routing** on the response (next section).

Every Strava request (API read, token refresh, grant deauthorize) carries a 5 s connect timeout and a 15 s total timeout (`HTTP_CONNECT_TIMEOUT_SECONDS` / `HTTP_TIMEOUT_SECONDS` in [StravaClient](app/Services/Strava/StravaClient.php)) instead of Laravel's 30 s default. They are sized so the worst single ingest attempt, a 5 s refresh-lock wait plus a refresh plus the detail and streams reads, stays at 50 s, under the 60 s `supervisor-1` timeout in [config/horizon.php](config/horizon.php) that runs `IngestActivityJob` and `SyncActivitiesJob`. A `SyncActivitiesJob` attempt fits the same 50 s budget by walking at most two `/athlete/activities` pages ([`PAGES_PER_ATTEMPT`](app/Jobs/Strava/SyncActivitiesJob.php)) and chaining a continuation for the rest, see [[run-ingest-pipeline]]. A call that hits either timeout throws `ConnectionException` and is routed like any transport failure below. The 15 s cap was chosen from 7 days of prod Pulse data (2026-10-02): the slowest Strava API read was 8.9 s, and 4 reads exceeded 8 s; token refreshes peaked at 1.9 s, which the 5 s lock wait covers.

## Error routing — the load-bearing distinction

The whole design hinges on classifying *why* a call failed, because each cause wants a different reaction. [`get()`](app/Services/Strava/StravaClient.php) routes the response status:

| Upstream signal | Throws | Touches breaker? | Caller reaction |
| --- | --- | --- | --- |
| `401` | [`StravaConnectionRevokedException`](app/Services/Strava/Exceptions/StravaConnectionRevokedException.php) | no | revoke the connection |
| `429` | [`StravaRateLimitedException`](app/Services/Strava/Exceptions/StravaRateLimitedException.php) (seeded with `Retry-After`) | no | back off, Strava is up |
| `5xx` | re-throws after [`recordFailure()`](app/Services/Strava/StravaCircuitBreaker.php) | **yes** | back off, may open breaker |
| timeout / connection error | re-throws after [`recordFailure()`](app/Services/Strava/StravaCircuitBreaker.php) | **yes** | back off, may open breaker |
| `2xx` (or non-5xx 4xx like 404) | returns | clears via [`recordSuccess()`](app/Services/Strava/StravaCircuitBreaker.php) | proceed |

Only genuine *Strava-is-down* signals (5xx + timeouts) move the breaker. A `401` is one athlete's problem and a `429` means Strava is healthy but busy — neither should trip a global breaker. The two job consumers act on each exception: [SyncActivitiesJob](app/Jobs/Strava/SyncActivitiesJob.php) maps revocations to `markRevoked()`, releases on rate-limit/transient-refresh, and drops silently on an open breaker; [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php) routes both rate-limit and open-breaker through a `ThrottlesExceptions` middleware so a backoff doesn't burn its failure budget.

Each API-driven revoke carries the connection's `credential_version` captured before its request. `markRevoked()` claims the row only while that version still matches, while a successful OAuth reconnect clears `revoked_at` and increments the version. A delayed 401 from the previous grant therefore cannot revoke the replacement credentials. The webhook verification job carries the captured version in its queued payload too; legacy queued jobs without one are ignored.

## The circuit breaker

[StravaCircuitBreaker](app/Services/Strava/StravaCircuitBreaker.php) is a three-state machine whose state is **durable** in the `app_config` table (not cache), so it survives restarts and is shared across containers.

- **closed** → normal. [`recordFailure()`](app/Services/Strava/StravaCircuitBreaker.php) increments a counter; once it reaches the [threshold](app/Support/Config/AppConfigKey.php) the breaker [`open()`](app/Services/Strava/StravaCircuitBreaker.php)s and stamps `opened_at`.
- **open** → [`allowsRequest()`](app/Services/Strava/StravaCircuitBreaker.php) blocks every call until the [cooldown](app/Support/Config/AppConfigKey.php) elapses past `opened_at`, then flips to half-open to let exactly one probe through.
- **half-open** → the single probe decides: a success [`reset()`](app/Services/Strava/StravaCircuitBreaker.php)s to closed; a failure [re-opens and restarts the cooldown](app/Services/Strava/StravaCircuitBreaker.php).

Threshold and cooldown are tunable `app_config` keys with code defaults in [AppConfigKey](app/Support/Config/AppConfigKey.php); the three runtime keys (`state` / `failures` / `opened_at`) are breaker-managed, never hand-tuned.

**Concurrency:** state-mutating paths take a short `Cache::lock` through [`withLock()`](app/Services/Strava/StravaCircuitBreaker.php) and [`forgetState()`](app/Services/Strava/StravaCircuitBreaker.php) (drop the per-request memo, see [[data-model]] on `AppConfig`) so they re-read fresh counters under the lock. [`recordSuccess()`](app/Services/Strava/StravaCircuitBreaker.php) has a fast path that skips the lock and write entirely when already closed with zero failures — the common healthy case.

## Global rate-limit buckets

[`guardRateLimit()`](app/Services/Strava/StravaClient.php) checks two Laravel `RateLimiter` buckets (a 15-minute one and a daily one) before hitting both. The keys from [`rateLimitKey()`](app/Services/Strava/StravaClient.php) carry **no `user_id`** — the budget is app-wide because Strava meters per OAuth client, not per athlete (the [[strava-circuit-breaker-rate-limit]] ADR is the rationale). Exhaustion records a `strava_rate_limited` Pulse event and throws `StravaRateLimitedException`.

Each key names the Strava window it counts, so the local buckets reset when Strava's do rather than at the first read: `strava-api:15min:<UTC quarter-hour start>` (rolling at :00/:15/:30/:45 UTC) and `strava-api:daily:<UTC date>` (rolling at 00:00 UTC, 07:00 WIB). Every key expires at its window's end.

The local count alone can undercount: the buckets live on the evictable, non-persistent `redis-cache` (see [[deployment]]), and Strava also counts reads this app never made. So each response's `X-ReadRateLimit-Usage` is stored beside the bucket, under the window the request was sent in, expiring at that window's end ([`rememberReportedUsage()`](app/Services/Strava/StravaClient.php)). A bucket's counted usage is the higher of the local count and that reported usage ([`usage()`](app/Services/Strava/StravaClient.php)), and both the guard and the headroom readers use it. A value reported for an earlier window is ignored, because it sits under that window's key. After a `redis-cache` restart, both counts start from zero until the next Strava response reports the window's usage again.

### The live-ingest reserve

One pool, but **two ceilings against it**. Every read carries a [StravaReadPriority](app/Enums/StravaReadPriority.php), defaulting to `Live`; a `Background` read is refused once a bucket reaches [`backgroundCeilings()`](app/Services/Strava/StravaClient.php) — 75% of the 15-minute max (150), and the daily max less the flat `strava.live_read_floor` (2,000 - 400 = 1,600) — while `Live` may spend the whole 200 / 2,000. Since the counter is shared, background reads left today are that 1,600 minus whatever live ingest has already spent. Only browsing-driven hydration ([DetailHydrator](app/Services/Run/Ingest/DetailHydrator.php)) is `Background`; webhook push, fallback poll, ingest drain, resync and doctor all stay `Live`. The keys are untouched by this: the reserve is a threshold, not a second bucket. Rationale and the rejected alternatives are in [[live-ingest-read-reserve]], and the daily bucket's move from a percentage to a floor is in [[backfill-borrows-the-live-reserve]].

A refused background read is **deferred, not dropped** — [IngestActivityJob](app/Jobs/Strava/IngestActivityJob.php)'s `ThrottlesExceptions` releases it with backoff, on a [throttle key of its own tier](app/Enums/StravaReadPriority.php) so a backed-off browsing burst can't release live ingest jobs alongside it.

### Measuring actual reads

Every HTTP response from Strava is recorded in the analytics `strava_reads` table with its app-timezone response time, source, priority, safe endpoint category, status, and the `X-ReadRateLimit-Usage` 15-minute and daily counters when present. Rows contain no athlete or user ID. Locally refused requests and transport failures have no response and are not recorded. `analytics:prune` removes rows older than 90 days, using the same app-timezone cutoff as the other analytics tables.

The analytics `DATETIME` values use the app timezone (Asia/Jakarta). This MySQL query shifts them to UTC before filtering and bucketing, then reports the app's peak clock-aligned UTC 15-minute windows over the last 30 days by source and priority, alongside Strava's usage counters observed in those windows. Change `INTERVAL 30 DAY` to the period you want to inspect.

```sql
WITH reads_in_utc AS (
    SELECT
        TIMESTAMPADD(HOUR, -7, read_at) AS read_at_utc,
        source,
        priority,
        usage_15m,
        usage_daily
    FROM strava_reads
    WHERE read_at >= TIMESTAMPADD(HOUR, 7, UTC_TIMESTAMP() - INTERVAL 30 DAY)
),
reads_by_source AS (
    SELECT
        CONCAT(
            DATE_FORMAT(read_at_utc, '%Y-%m-%d %H:'),
            LPAD(FLOOR(MINUTE(read_at_utc) / 15) * 15, 2, '0'),
            ':00'
        ) AS window_start_utc,
        source,
        priority,
        COUNT(*) AS source_reads,
        MAX(usage_15m) AS observed_15min_usage,
        MAX(usage_daily) AS observed_daily_usage
    FROM reads_in_utc
    GROUP BY window_start_utc, source, priority
)
SELECT
    window_start_utc,
    source,
    priority,
    source_reads,
    SUM(source_reads) OVER (PARTITION BY window_start_utc) AS app_reads_in_window,
    MAX(observed_15min_usage) OVER (PARTITION BY window_start_utc) AS strava_15min_usage,
    MAX(observed_daily_usage) OVER (PARTITION BY window_start_utc) AS strava_daily_usage
FROM reads_by_source
ORDER BY app_reads_in_window DESC, window_start_utc DESC, source, priority;
```

Compare `app_reads_in_window` with `strava_15min_usage` to spot reads made outside this application or gaps in instrumentation. The Strava values are snapshots from individual responses, so they can include other client activity and need not equal this table's row count.

The ceilings are **this app's own Read allocation, 200 per 15 min and 2,000 per day**, read off its Strava API dashboard, with Overall limits of 400 / 4,000 sitting above them. Strava's public docs quote a lower 100 / 1,000 default for new applications; that is **not** this app's allocation, so don't lower the constants on a docs reading. The dashboard is the source of truth, and the [[strava-circuit-breaker-rate-limit]] ADR records the same pair.

> **Gotcha:** [`rateLimitRemaining()`](app/Services/Strava/StravaClient.php) takes no athlete: the buckets are app-wide, so every athlete sees the same shared headroom (and it reports the *raw* pool, not the lower background-visible headroom). Do not key it per user. Note the local guard's exhaustion and a real upstream `429` both surface as `StravaRateLimitedException`; only the local one is preventable by us.

## Per-connection token refresh

[`refreshIfExpired()`](app/Services/Strava/StravaClient.php) on the client (returning a refreshed [StravaConnection](app/Models/StravaConnection.php)) rotates an access token that's within the [refresh buffer](app/Services/Strava/StravaClient.php) of expiry. It takes a [`strava-refresh:{id}` lock](app/Services/Strava/StravaClient.php), then **re-reads inside the lock** before refreshing — because Strava rotates the `refresh_token` on every exchange, two concurrent workers refreshing the same connection would mutually invalidate each other's new token. This lock is intentionally **per-connection**, unlike the global rate buckets and global breaker.

Refresh failures classify just like reads: only a token-refresh `400` whose `errors[]` names the `RefreshToken` is a [permanent rejection](app/Services/Strava/StravaClient.php) (`StravaTokenRefreshFailedException` → revoke). Any other `400` — notably `Application` / `client_secret` from our own bad credentials — and `401` / `429` / `5xx` / connection errors during sync are transient (`StravaTokenRefreshTransientException` → release & back off), so a credential mistake cannot revoke every athlete at once; see [[strava-refresh-rejection-names-the-refresh-token]]. Revoking a healthy connection over a momentary blip would purge its un-ingested stubs — see [`markRevoked()`](app/Models/StravaConnection.php), which cascades-deletes that user's pending stubs.

## Diagnosing & resetting a wedged breaker

The breaker state lives in `app_config`, so a stuck-open breaker stays open across restarts until cooldown elapses (or the upstream recovers on the half-open probe). To inspect or force it:

- **Inspect** — the `/devtools/pulse` superadmin card renders the breaker [`snapshot()`](app/Livewire/Pulse/SystemControl.php) (state / failures / opened_at) alongside the ingest backlog; `open` shows as an alert, `half_open` as a warning.
- **Force-close** — the same card's [`resetBreaker()`](app/Livewire/Pulse/SystemControl.php) calls `reset()`, closing it and zeroing the counter immediately. Use it after confirming Strava has recovered rather than waiting out the cooldown. Access to `/devtools/pulse` is edge basic-auth in prod (see [[deployment]]).

## See also

[[strava-circuit-breaker-rate-limit]] · [[live-ingest-read-reserve]] · [[strava-connect]] · [[run-ingest-pipeline]] · [[data-model]] · [[deployment]]
