---
title: Narration devtools
description: The ops-gated narration console — a status board of faults, today's spend against its ceiling, a daily cost chart stacked by narrator, per-athlete rows, the rule-based ledger, and the kind/deployment/origin breakdowns.
tags: [feature, ai]
status: living
reviewed: 2026-09-17
code_refs:
  - app/Livewire/Pulse/NotificationDeliveryHealth.php
  - app/Livewire/Pulse/SelfHealAttempts.php
  - resources/views/vendor/pulse/dashboard.blade.php
  - resources/js/pages/Narration/Overview.tsx
  - resources/js/pages/Narration/helpers.ts
  - resources/js/pages/Narration/types.ts
  - app/Http/Controllers/NarrationOverviewController.php
  - app/Services/AI/TokenUsageReport.php
  - app/Services/AI/CeilingOverride.php
  - app/Services/AI/AnalysisOrigin.php
  - resources/js/components/narration/UsageFilters.tsx
  - resources/js/components/narration/FaultStrip.tsx
  - resources/js/components/narration/TodayPanel.tsx
  - resources/js/components/narration/actions.tsx
  - app/Services/AI/CostCeilingLedger.php
  - resources/js/components/narration/CostChart.tsx
  - resources/js/components/narration/NarratorRanking.tsx
  - resources/js/components/narration/chartBands.ts
  - resources/js/components/narration/AthletesPanel.tsx
  - resources/js/components/narration/RuleBasedPanel.tsx
  - resources/js/components/narration/Sparkline.tsx
  - resources/js/components/narration/DeploymentTable.tsx
  - resources/js/components/narration/KindTable.tsx
  - resources/js/components/ui/DataTable.tsx
  - docker/Caddyfile
  - routes/web.php
---

# Narration devtools

`/devtools/narration` is the operator's view of what narration is costing, per athlete. It is not part of the runner-facing app — it has no `auth` middleware so ops can open it without a Strava session, and is gated by a separate devtools password instead (see Access below).

**Navigation:** `route('devtools.narration')` → `/devtools/narration`. Named route: `devtools.narration`. It was `/devtools/ai-usage` until 2026-09-10; that path is a permanent redirect, so old bookmarks and links keep working.

## System dependencies

- **Cost ceiling** — spend is bounded by [[idempotent-dispatch-cost-ceiling]] (dispatch-time) and the execution-time guard in `AnalyzeBaseJob`. Past the ceiling narration is served rule-based rather than paused, per [[cost-ceiling-degrades-to-rule-based]]. Two ceilings enforce that: the per-athlete slice, and the app-wide total above it that degrades everyone at once ([[app-wide-ceiling-above-the-per-athlete-one]]).
- **Analytics DB** — metering rows live on the separate `analytics` connection; see [[analytics-db]].
- **AI pipeline** — all analyses are produced by [[ai-pipeline]].

## What it shows

[Overview.tsx](../../resources/js/pages/Narration/Overview.tsx) is pure composition; each block below is its own component under [components/narration/](../../resources/js/components/narration/AthletesPanel.tsx), and the shared formatting and payload shapes live in [helpers.ts](../../resources/js/pages/Narration/helpers.ts) / [types.ts](../../resources/js/pages/Narration/types.ts). The page has two tabs over one shared range filter.

### The overview tab

Rebuilt as a status board (design round #929, direction A): faults first, then
today's number, then who and what drove it, then the rule-based ledger.

- A **fault region** ([FaultStrip](../../resources/js/components/narration/FaultStrip.tsx)): one tile per open fault — generation paused, an app-wide ceiling trip, athletes capped today, dead-lettered blocks, content-filter trips — each with the action that fixes it (the existing **recover all** / per-athlete **retry failed** buttons, factored into [actions.tsx](../../resources/js/components/narration/actions.tsx)). The whole region collapses to a single "nothing on fire" line when none of those are true, so the presence or absence of the block is the signal, not a chip to scan.
- **Today** ([TodayPanel](../../resources/js/components/narration/TodayPanel.tsx), which absorbed the old CeilingHeader): today's app-wide spend at display size against the enforced app-wide ceiling, plus four labelled references beside it — the ceiling, the median day over the last 30, yesterday, and the busiest day before today — with no percentage composites. The capped-athlete count, the pause reason and the recover action moved to the fault region above, since those are faults rather than a reading of today.
- **Who and what drove it**, side by side: the **daily cost chart** ([CostChart](../../resources/js/components/narration/CostChart.tsx), one bar per day stacked by the narrator kind that billed it, with an athlete filter that narrows the whole series to one athlete, and the same median drawn as a dashed reference line) beside a **ranked by-narrator list** ([NarratorRanking](../../resources/js/components/narration/NarratorRanking.tsx), cost *and* calls per kind, replacing the chart's own wrapping legend; both share their band colours via [chartBands.ts](../../resources/js/components/narration/chartBands.ts)). Below that, **athlete rows** ([AthletesPanel](../../resources/js/components/narration/AthletesPanel.tsx), one flex row per athlete rather than a table): name and tags, today against that athlete's own ceiling as a meter (honouring a today-only [CeilingOverride](../../app/Services/AI/CeilingOverride.php)), 7d, 30d, calls, and a 30-day [Sparkline](../../resources/js/components/narration/Sparkline.tsx). A deleted account collapses into a `<details>` rollup below the live rows instead of taking a full row. Flags and the dead-letter count are not columns here any more — a dead-lettered block is a fault (above), and flags are a quality signal (the breakdown tab).
- The **rule-based ledger** ([RuleBasedPanel](../../resources/js/components/narration/RuleBasedPanel.tsx)), app-wide: one row per reason a block was served rule-based instead of by the LLM — `demo`, `capped`, `return`, `dead_letter`, `content_filter`, `unattributed` — with its count and which athletes contributed it, plus two anchors (how much pre-dates `served_by`, how much the LLM wrote). `content_filter`, `dead_letter` and `unattributed` render in ember: they are the reasons that should not be there.
- The money columns and the sparkline are **fixed 30-day windows**, not the selected range, so "today against their ceiling" keeps meaning the same thing whatever range is chosen. The quality columns follow the range.
- The producer split has three buckets, not two: `served_by` is null for every row narrated before the column shipped ([[narration-analytics-are-joinable]]), so **unknown** is its own number rather than being counted as rule-based.

**Why a rule-based fill happened.** `ai_analyses.rule_based_reason` ([AnalysisOrigin](../../app/Services/AI/AnalysisOrigin.php), reused rather than a parallel enum) is written at every rule-based `markDone()` site this page reads from: the content-filter fallback in `AnalyzeRowJob`/`AnalyzeGroupJob` (`ContentFilter`), the cost-ceiling degrade path in `AnalysisService::degradeToRuleBased()` (`Capped`), `NarrateOnReturnJob`'s away-catch-up fills (`Return`), and `ai:relabel-demo-narration` (`Demo`). [TokenUsageReport::athletes()](../../app/Services/AI/TokenUsageReport.php) groups by it to build `served.reasons`; a demo athlete's rule-based fills always count as `demo` regardless of the stored column (derived from `is_demo`, since the demo account's narration is rule-based by design rather than by exception), and a null or not-yet-taxonomised value counts as `unattributed` — the same argument the pre-existing `unknown` bucket already makes for `served_by`. `dead_letter` is a defined reason with no writer yet; it renders (in ember, once real) the moment a future call site starts passing it.

### The breakdown tab

- **by kind** ([KindTable](../../resources/js/components/narration/KindTable.tsx), which narrator), **by deployment** ([DeploymentTable](../../resources/js/components/narration/DeploymentTable.tsx), which Azure model served the call) and **by origin** ([OriginTable](../../resources/js/components/narration/OriginTable.tsx), what started it). All share the generic [DataTable](../../resources/js/components/ui/DataTable.tsx) shell, which takes its empty state from the caller.
- Each **by kind** row carries an agent summary line under its name — `3.5 steps · 71% cache · 18% reasoning`. Every narrator is a tool-calling agent, so one row can span several model turns: without the step count an expensive block is indistinguishable from a chatty one. The line is **absent, not zeroed**, for kinds whose rows predate those columns, since zero would read as "never cached, never reasoned" rather than "never measured".

The kind, origin and from/to date controls ([UsageFilters](../../resources/js/components/narration/UsageFilters.tsx)) re-query the same endpoint via `router`, and stay visible on both tabs.

**The attention panels are not here.** Dead-lettered, failed-under-budget and stuck blocks are a per-athlete question, so they live on the athlete page rather than on the overview; what the overview keeps is the dead-letter *count* per athlete and the two actions that recover them.

## Server side

[NarrationOverviewController::show](../../app/Http/Controllers/NarrationOverviewController.php) validates the optional `range` / `from` / `to` / `kind` / `origin` / `athlete` filters and resolves the relative range token to concrete dates on every request, so a preset link never goes stale. It delegates to [TokenUsageReport](../../app/Services/AI/TokenUsageReport.php): `build()` for the totals and the breakdowns, `dailyCostByKind()` for the stacked series, and `athletes()` for the athlete rows. Cost is never stored — every figure is computed from the token columns against the per-deployment price map by [LlmCostCalculator](../../app/Services/AI/LlmCostCalculator.php), which is why each aggregate groups by `model` first and rolls up afterwards.

The metering rows (`ai_token_usages`) live on the separate `analytics` connection, not the app database — see [[analytics-db]]. Spend, narration rows and athlete identity therefore sit on different connections and are stitched in PHP rather than joined in SQL. Each usage row also carries the `analysis_id` it paid for and the run's `tool_calls` trace, and the operator actions on this page are audited to `devtools_actions` on the same connection — see [[narration-analytics-are-joinable]].

## Surface split with /devtools/pulse

This page owns **money**: spend per athlete, the ceilings, the per-model breakdowns, and the dead-letter re-arm. `/devtools/pulse` owns **health**: pipeline state, Strava, scheduler runs, the kill switches, notification delivery outcomes ([NotificationDeliveryHealth](../../app/Livewire/Pulse/NotificationDeliveryHealth.php)) and the per-block retry budget ([SelfHealAttempts](../../app/Livewire/Pulse/SelfHealAttempts.php)). The split is why a new operational signal goes to Pulse rather than growing this page, and why this page stays a first-party Inertia screen rather than becoming Livewire.

Pulse renders outside the Inertia shell, so its layout loads only the packaged `pulse.css`; [resources/views/vendor/pulse/dashboard.blade.php](../../resources/views/vendor/pulse/dashboard.blade.php) registers `app.css` through `Pulse::css()` so the first-party cards can use the semantic `--color-*` tokens. Pulse's own stylesheet is unlayered and therefore still outranks the Tailwind v4 layers on every class the two vocabularies share, which is what keeps the stock cards looking stock. Utilities that Tailwind v3 initialises on `*` (`border-color`, the `--tw-ring-*` group) cannot be overridden that way, so the first-party cards use tinted backgrounds rather than rings or borders. The same file resolves `localStorage.theme` from the app's own stored ground (falling back to `prefers-color-scheme` when nothing is stored), so Pulse's switcher tracks the app instead of the operator's OS independently.

## Access (ops-gated)

The route in [web.php](../../routes/web.php) (`devtools.narration`) is gated **in production** by HTTP Basic Auth against one shared devtools password ([EnsureDevtoolsAccess](../../app/Http/Middleware/EnsureDevtoolsAccess.php), `config('devtools.password')`), fully independent of the Strava/`is_admin` session. Outside production the gate is a deliberate no-op, so local and CI runs reach the console without a password; `APP_ENV` defaults to `production`, so the skip fails safe. The same middleware covers Horizon and Pulse (via their own `middleware` config), so all three dashboards sit behind one shared credential the browser caches per-origin — switching which Strava account is logged in doesn't affect it. A small landing page at `/devtools` links to all three. Every entry point (the `/devtools` route group, Horizon's and Pulse's own routes, and the Livewire update route Pulse's live cards POST through) is throttled at `60,1` (60 requests/minute/IP) to blunt password brute-forcing, generous enough to not trip Pulse's 5s card polling. The `viewHorizon`/`viewPulse` gates (checked internally by those packages) now always return true; they're a rubber stamp, since real enforcement happens in the middleware upstream. In production Cloudflare Access fronts the edge as well.

## See also

This dashboard *reports* on the spend; the controls that *bound* it — the idempotency guard and the daily cost ceiling that stops over-billing — are in [[idempotent-dispatch-cost-ceiling]]. For how analyses get produced in the first place, see [[ai-pipeline]]. Storage: [[analytics-db]].
