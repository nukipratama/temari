---
title: Decisions — Map of Content
description: The architecture decision timeline (ADRs)
tags: [decision, moc]
status: living
reviewed: 2026-07-07
---

# Decisions (ADRs)

Architecturally significant decisions, each a dated point-in-time record. **ADRs are immutable**: a changed decision gets a *new* ADR, and the old one is marked `status: superseded` with `superseded_by:`. The truth is the whole timeline, not just the latest note.

Only decisions that clear the bar live here — costly to reverse, cross-cutting, or whose rationale isn't obvious from the code. Most day-to-day choices never get an ADR, by design.

## Pattern index

ADRs grouped by the problem they solve, for easier navigation than a flat timeline:

| Pattern | ADRs |
|---|---|
| **Cost guards** | [[idempotent-dispatch-cost-ceiling]] (dispatch-time + daily ceiling); [[bounded-self-heal-and-dead-letter]] (execution-time + bounded retry); [[per-block-manual-retry]] *(superseded)* |
| **Data isolation** | [[analytics-db-separate-connection]] (metering outlives app resets); [[date-cast-utc-shift]] (UTC off-by-one guard) |
| **Async / resilience** | [[chained-narration]] (connected narration threads); [[strava-circuit-breaker-rate-limit]] (per-client rate-limit guard); [[trust-all-proxies-cloudflare]] (proxy trust behind tunnel); [[deferred-recap-windowing]] (window-gated generation) |
| **AI routing** | [[azure-openai-routing]] (per-narrator-kind deployment selection); [[demo-user-billing-exclusion]] (demo user omitted from auto-billing) |
| **Ops / deploy** | [[fixed-session-cookie]] (stable cookie name); [[defer-config-cache]] (config cache timing); [[telegram-account-linking]] (signed deep-link token) |

## Timeline

_AI cost & flow_
- [[per-block-manual-retry]] — failed AI blocks never auto-retry; retry is manual, to keep LLM cost predictable *(superseded by [[bounded-self-heal-and-dead-letter]])*
- [[bounded-self-heal-and-dead-letter]] — paused blocks stay honestly Pending; failed blocks get a bounded auto-retry, then a per-user dead-letter
- [[idempotent-dispatch-cost-ceiling]] — re-runnable schedulers don't re-bill; a daily USD ceiling caps spend
- [[azure-openai-routing]] — per-narrator-kind Azure deployment selection via config/env
- [[chained-narration]] — connected narration threads via prev_narrative + afterDone + resume sweep
- [[deferred-recap-windowing]] — recap rows are Pending until the week/month window closes
- [[demo-user-billing-exclusion]] — demo user excluded from every auto-billing scheduler

_Data_
- [[analytics-db-separate-connection]] — metering on a separate connection that survives migrate:fresh
- [[date-cast-utc-shift]] — date columns cast `date:Y-m-d` to dodge a UTC off-by-one

_Infra & Strava_
- [[strava-circuit-breaker-rate-limit]] — Strava rate limit is per-client, so the guard key is global
- [[fixed-session-cookie]] — fixed cookie name + Redis prefixes, not APP_NAME-derived
- [[trust-all-proxies-cloudflare]] — trust all proxies behind the Cloudflare tunnel
- [[defer-config-cache]] — config:cache only at deploy time, never at build or in CI tests
- [[telegram-account-linking]] — link Telegram via a signed deep-link token; prod webhook, dev long-poll
