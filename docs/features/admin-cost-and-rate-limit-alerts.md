---
title: Admin cost and rate-limit alerts
description: The maintainer-facing Telegram alerts for LLM spend and the shared Strava read budget — an evening spend digest plus three threshold pushes, each with its own dedupe window.
tags: [feature, notifications]
status: living
reviewed: 2026-09-16
code_refs:
  - app/Services/AI/MaintainerAlerter.php
  - app/Console/Commands/AI/SpendDigestCommand.php
  - app/Services/AI/AnalysisService.php
  - app/Services/Run/Ingest/SyncOrchestrator.php
  - routes/console.php
---

# Admin cost and rate-limit alerts

Everything here goes out through [MaintainerAlerter](../../app/Services/AI/MaintainerAlerter.php),
the one path that pushes to every `is_admin` user's Telegram chat and no-ops when no bot token is
configured. It bypasses `ChannelRouter` and the channel mutes on purpose
([[telegram-notifications]]): these are operational, not product.

## The four surfaces

| Alert | Fires from | Dedupe |
|---|---|---|
| Evening spend digest | [SpendDigestCommand](../../app/Console/Commands/AI/SpendDigestCommand.php#L17), scheduled daily at 21:00 in [routes/console.php](../../routes/console.php#L142) | None needed — the scheduler runs it once |
| Per-athlete ceiling trip | [`AnalysisService::ceilingExceeded()`](../../app/Services/AI/AnalysisService.php#L913) | `Cache::add` on a date-and-athlete key: once per athlete per day, not once per gated dispatch |
| App-wide ceiling at 80% | same gate, on the *under*-ceiling branch | `Cache::add` on one global key, 1h cooldown |
| Strava 15-minute budget under 10% | [`SyncOrchestrator::logSync()`](../../app/Services/Run/Ingest/SyncOrchestrator.php#L196) | `Cache::add` on a **global** key naming the quarter-hour window: once per window, and the next window may warn again |

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

## See also

[[telegram-notifications]] · [[cost-ceiling-degrades-to-rule-based]] ·
[[app-wide-ceiling-above-the-per-athlete-one]] · [[strava-circuit-breaker-rate-limit]] ·
[[llm-triggers]]
