---
title: AI usage dashboard
description: The ops-gated token-usage and cost dashboard — by kind, user and deployment, with daily spend against a ceiling.
tags: [feature, ai]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/AiUsage.tsx
  - app/Http/Controllers/TokenUsageController.php
  - app/Services/AI/TokenUsageReport.php
  - resources/js/components/dashboard/KpiTile.tsx
  - docker/Caddyfile
  - routes/web.php
---

# AI usage dashboard

`/ai-usage` is the operator's view of what the LLM pipeline is costing. It is not part of the runner-facing app — it has no `auth` middleware so ops can open it without a Strava session, and is protected at the edge instead (see Access below).

## What it shows

[AiUsage.tsx](../../resources/js/pages/AiUsage.tsx) renders, for a chosen date window:

- **KPI tiles** via [KpiTile](../../resources/js/components/dashboard/KpiTile.tsx) — total tokens, estimated cost, prompt tokens (and prompt share), plus a fourth tile.
- A **budget gauge** comparing the window's spend against the configured daily ceiling.
- Breakdown tables: **by kind** (which narrator/analysis), **by user**, and **by deployment** (which Azure model deployment served the call).
- A **daily** series for the spend-over-time view.

A kind filter and from/to date controls re-query the same endpoint via `router`.

## Server side

[TokenUsageController::show](../../app/Http/Controllers/TokenUsageController.php) validates optional `from` / `to` (`Y-m-d`) and `kind`, defaulting the window to the start of the current month through now. It delegates to [TokenUsageReport::build](../../app/Services/AI/TokenUsageReport.php), which aggregates the metering rows and returns `totals`, `byKind`, `byUser`, `byDeployment`, `daily`, `availableKinds` and `budget` — all passed straight into the Inertia page.

The metering rows (`ai_token_usages`) live on the separate `analytics` connection, not the app database — see [[analytics-db]].

## Access (ops-gated)

The route in [web.php](../../routes/web.php) (`ai-usage`) carries no `auth` guard *by design*. In production the edge enforces basic auth: [docker/Caddyfile](../../docker/Caddyfile) matches `path /horizon* /pulse* /ai-usage*` and applies a `basicauth` block driven by `DEVTOOLS_BASIC_AUTH_USER` / `DEVTOOLS_BASIC_AUTH_PASS_HASH`. So the dashboard sits behind the same operator allowlist as Horizon and Pulse, separate from the Strava login that gates the runner app.

## See also

This dashboard *reports* on the spend; the controls that *bound* it — the idempotency guard and the daily cost ceiling that stops over-billing — are in [[idempotent-dispatch-cost-ceiling]]. For how analyses get produced in the first place, see [[ai-pipeline]]. Storage: [[analytics-db]].
