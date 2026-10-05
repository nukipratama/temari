---
title: Admin cost and rate-limit alerts
description: The maintainer-facing Telegram alerts for LLM spend, the shared Strava read budget and never-seen exceptions — two evening digests plus three threshold pushes, each with its own dedupe window.
tags: [feature, notifications]
status: living
reviewed: 2026-09-16
code_refs:
  - app/Services/AI/MaintainerAlerter.php
  - app/Console/Commands/AI/SpendDigestCommand.php
  - app/Console/Commands/ExceptionDigestCommand.php
  - app/Support/NewExceptionLedger.php
  - app/Http/Controllers/ClientErrorController.php
  - bootstrap/app.php
  - app/Services/AI/AnalysisService.php
  - app/Services/Run/Ingest/SyncOrchestrator.php
  - routes/console.php
---

# Admin cost and rate-limit alerts

Everything here goes out through [MaintainerAlerter](../../app/Services/AI/MaintainerAlerter.php),
the one path that pushes to every `is_admin` user's Telegram chat and no-ops when no bot token is
configured. It bypasses `ChannelRouter` and the channel mutes on purpose
([[telegram-notifications]]): these are operational, not product.

## The five surfaces

| Alert | Fires from | Dedupe |
|---|---|---|
| Evening spend digest | [SpendDigestCommand](../../app/Console/Commands/AI/SpendDigestCommand.php#L17), scheduled daily at 21:00 in [routes/console.php](../../routes/console.php#L142) | None needed — the scheduler runs it once |
| Evening new-exception digest | [ExceptionDigestCommand](../../app/Console/Commands/ExceptionDigestCommand.php#L15), scheduled daily at 21:00 in [routes/console.php](../../routes/console.php#L145); silent on a day with nothing new | A fingerprint is listed once per 30-day seen window ([NewExceptionLedger](../../app/Support/NewExceptionLedger.php#L21)) |
| Per-athlete ceiling trip | [`NarrationGate::ceilingExceeded()`](../../app/Services/AI/NarrationGate.php#L256) | `Cache::add` on a date-and-athlete key: once per athlete per day, not once per gated dispatch |
| App-wide ceiling at 80% | same gate, on the *under*-ceiling branch | `Cache::add` on one global key, 1h cooldown |
| Strava 15-minute budget under 10% | [`SyncOrchestrator::logSync()`](../../app/Services/Run/Ingest/SyncOrchestrator.php#L231) | `Cache::add` on a **global** key naming the quarter-hour window: once per window, and the next window may warn again |

Every threshold and every dedupe key lives inside the alerter, so a call site hands it one number
and never decides whether that number is worth a push.

## Why the Strava key is global

Strava meters per **client application**, not per athlete
([[strava-circuit-breaker-rate-limit]]) — the same reason `StravaClient` keys its rate-limit
buckets globally rather than by `user_id`. A per-athlete dedupe key would fire one push per
syncing athlete for a single shared exhaustion, which is the loudest possible way to say one thing.
The budget read is `StravaClient::rateLimitRemaining()`, the live limiter, not the
`rate_limit_15min_remaining` column on the sync log: that column is only written on a successful
sync, and an errored sync is often exactly the moment the budget is gone.

## What the digest contains

Today's calls, tokens and estimated cost per athlete, heaviest first, plus the app-wide total and
the headroom left against each ceiling. Cost comes from `LlmCostCalculator` — the same source the
two ceilings are measured against ([[cost-ceiling-degrades-to-rule-based]],
[[app-wide-ceiling-above-the-per-athlete-one]]), so the digest can never disagree with the gate
about what a day cost. Rows whose athlete has been erased carry a null `user_id`; they count in the
app-wide total and get no per-athlete line.

The figures themselves only exist in the Telegram message. This is a public repo: nothing that
carries real spend or athlete identifiers belongs in it.

## The new-exception digest

Everything above is a known condition. An unexpected error used to be pull-only: the daily log,
stderr, and Pulse's Exceptions card, which trims after 7 days. Now every reported server exception
(the `report()` callback in [bootstrap/app.php](../../bootstrap/app.php#L78)) and every browser
error posted to [ClientErrorController](../../app/Http/Controllers/ClientErrorController.php#L35)
is fingerprinted:

- **Server:** the exception class plus the first `file:line` outside `vendor/`, so a library error (a `QueryException`, an HTTP client error) is told apart by the app code that reached it.
- **Browser:** a hash of the message plus the first stack frame. The frame's origin and query
  string are dropped and numeric path segments are masked.

The first sighting in 30 days queues the fingerprint. Repeats raise its count until the next
digest, skipping the count rather than waiting when the ledger lock is busy. At 21:00 [`MaintainerAlerter::exceptionDigest()`](../../app/Services/AI/MaintainerAlerter.php#L372)
sends one message: a line per fingerprint with its first-seen time and count, folded to
"and N more" past 25 lines so it stays under Telegram's message limit.

The message carries locations only: no exception message, no user id, no request URL. The queue is
capped at 100 fingerprints, so a flood of distinct browser errors (the endpoint is public and only
IP-throttled) cannot grow it without bound. Anything past the cap is picked up after the next
digest clears the queue.

## See also

[[telegram-notifications]] · [[cost-ceiling-degrades-to-rule-based]] ·
[[app-wide-ceiling-above-the-per-athlete-one]] · [[strava-circuit-breaker-rate-limit]] ·
[[llm-triggers]]
