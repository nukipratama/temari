---
title: AI usage dashboard
description: The ops-gated token-usage and cost dashboard — by kind, user and deployment, with daily spend against a ceiling.
tags: [feature, ai]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/AiUsage.tsx
  - resources/js/pages/AiUsage/helpers.ts
  - resources/js/pages/AiUsage/types.ts
  - app/Http/Controllers/TokenUsageController.php
  - app/Services/AI/TokenUsageReport.php
  - resources/js/components/dashboard/KpiTile.tsx
  - resources/js/components/aiusage/UsageFilters.tsx
  - resources/js/components/aiusage/UsageKpis.tsx
  - resources/js/components/aiusage/BudgetGauge.tsx
  - resources/js/components/aiusage/DailyChart.tsx
  - resources/js/components/aiusage/AttentionArea.tsx
  - resources/js/components/aiusage/DeploymentTable.tsx
  - resources/js/components/aiusage/KindTable.tsx
  - resources/js/components/aiusage/UserTable.tsx
  - resources/js/components/ui/DataTable.tsx
  - docker/Caddyfile
  - routes/web.php
---

# AI usage dashboard

`/ai-usage` is the operator's view of what the LLM pipeline is costing. It is not part of the runner-facing app — it has no `auth` middleware so ops can open it without a Strava session, and is gated by a separate devtools password instead (see Access below).

**Navigation:** `route('ai-usage')` → `/ai-usage`. Named route: `ai-usage`.

## System dependencies

- **Cost ceiling** — spend is bounded by [[idempotent-dispatch-cost-ceiling]] (dispatch-time) and the execution-time guard in `AnalyzeBaseJob`.
- **Analytics DB** — metering rows live on the separate `analytics` connection; see [[analytics-db]].
- **AI pipeline** — all analyses are produced by [[ai-pipeline]].

## What it shows

[AiUsage.tsx](../../resources/js/pages/AiUsage.tsx) is pure composition; each block below is its own component under [components/aiusage/](../../resources/js/components/aiusage/AttentionArea.tsx), and the shared formatting and payload shapes live in [helpers.ts](../../resources/js/pages/AiUsage/helpers.ts) / [types.ts](../../resources/js/pages/AiUsage/types.ts). For a chosen date window it renders:

- **KPI tiles** via [UsageKpis](../../resources/js/components/aiusage/UsageKpis.tsx) over [KpiTile](../../resources/js/components/dashboard/KpiTile.tsx) — total tokens, estimated cost, prompt tokens (and prompt share), plus a fourth tile.
- A **budget gauge** ([BudgetGauge](../../resources/js/components/aiusage/BudgetGauge.tsx)) comparing the window's spend against the configured daily ceiling.
- Breakdown tables: **by kind** ([KindTable](../../resources/js/components/aiusage/KindTable.tsx), which narrator/analysis), **by user** ([UserTable](../../resources/js/components/aiusage/UserTable.tsx)), and **by deployment** ([DeploymentTable](../../resources/js/components/aiusage/DeploymentTable.tsx), which Azure model deployment served the call). All three share the generic [DataTable](../../resources/js/components/ui/DataTable.tsx) shell, which takes its empty state from the caller.
- Each **by kind** row carries an agent summary line under its name — `3.5 langkah · 71% cache · 18% reasoning`. Every narrator is a tool-calling agent, so one row can span several model turns: without the step count an expensive block is indistinguishable from a chatty one. The line is **absent, not zeroed**, for kinds whose rows predate those columns, since zero would read as "never cached, never reasoned" rather than "never measured".
- A **daily** series for the spend-over-time view ([DailyChart](../../resources/js/components/aiusage/DailyChart.tsx)).
- An **attention area** ([AttentionArea](../../resources/js/components/aiusage/AttentionArea.tsx), hidden when nothing is stuck) with a global one-shot **Pulihkan semua** recover action plus three per-user buckets so the "healthy" dashboard stops hiding silent rot: **Perlu perhatian** (dead-lettered, self-heal gave up), **Failed, belum menyerah** (Failed but still under the retry budget), and **Nyangkut** (Pending/Queued stuck past `Analysis::STALE_IN_FLIGHT_HOURS`, excluding window-gated open-period recap rows whose Pending is inert by design). The dead-letter and failed-under-budget buckets carry a per-user re-arm button; Nyangkut is recovered by the global action.

A kind filter and from/to date controls ([UsageFilters](../../resources/js/components/aiusage/UsageFilters.tsx)) re-query the same endpoint via `router`.

## Server side

[TokenUsageController::show](../../app/Http/Controllers/TokenUsageController.php) validates optional `from` / `to` (`Y-m-d`) and `kind`, defaulting the window to the start of the current month through now. It delegates to [TokenUsageReport::build](../../app/Services/AI/TokenUsageReport.php), which aggregates the metering rows and returns `totals`, `byKind`, `byUser`, `byDeployment`, `daily`, `availableKinds` and `budget` — all passed straight into the Inertia page.

The metering rows (`ai_token_usages`) live on the separate `analytics` connection, not the app database — see [[analytics-db]].

## Access (ops-gated)

The route in [web.php](../../routes/web.php) (`ai-usage`) is gated by HTTP Basic Auth against one shared devtools password ([EnsureDevtoolsAccess](../../app/Http/Middleware/EnsureDevtoolsAccess.php), `config('devtools.password')`), fully independent of the Strava/`is_admin` session. The same middleware covers Horizon and Pulse (via their own `middleware` config), so all three dashboards sit behind one shared credential the browser caches per-origin — switching which Strava account is logged in doesn't affect it. A small landing page at `/devtools` links to all three. The `viewHorizon`/`viewPulse`/`viewAiUsage` gates now always return true; they're a rubber stamp, since real enforcement happens in the middleware upstream. In production Cloudflare Access fronts the edge as well.

## See also

This dashboard *reports* on the spend; the controls that *bound* it — the idempotency guard and the daily cost ceiling that stops over-billing — are in [[idempotent-dispatch-cost-ceiling]]. For how analyses get produced in the first place, see [[ai-pipeline]]. Storage: [[analytics-db]].
