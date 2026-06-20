---
title: Idempotent dispatch + daily cost ceiling
description: Schedulers are re-runnable without re-billing identical content, and a daily USD ceiling caps runaway auto-dispatch.
tags: [decision, ai]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Services/AI/AnalysisService.php
  - config/azure_openai.php
  - app/Services/AI/LlmCostCalculator.php
  - app/Models/AI/TokenUsage.php
---

# Idempotent dispatch + daily cost ceiling

**Status:** Accepted (documented 2026-06-20)

## Context

The hourly `strava:sync` fallback poll and the daily narration commands can re-touch the same subjects repeatedly. If dispatch re-billed identical content on every pass, a re-run would silently double-spend. And even with idempotent dispatch, a bug or a Strava backfill burst could still run the daily bill away from us. We needed dispatch to be safely re-runnable, plus a hard budget backstop.

## Decision

We decided dispatch is **idempotent** and **budget-gated**, both enforced in [AnalysisService](app/Services/AI/AnalysisService.php):

- **Rows are upserted, not duplicated.** `upsertRow()` / `upsertGroupRows()` use `firstOrCreate` keyed on `(subject_type, subject_id, analysis_type, discriminator)`.
- **Dispatch skips rows that don't need it.** Only a freshly created row, or one whose status is `Pending`/`Failed` (`rowNeedsDispatch()`), is queued. A re-run over already-`Done` or in-flight rows enqueues nothing. (Jobs additionally early-exit if the row is already `Done`, so a UI retry racing a Horizon retry can't double-bill.)
- **A daily USD ceiling caps auto-dispatch.** `autoDispatchEnabled()` is false once `dailyCostCeilingExceeded()` is true — it compares [LlmCostCalculator::dailyCost()](app/Services/AI/LlmCostCalculator.php) against `azure_openai.daily_cost_ceiling` ([config/azure_openai.php](config/azure_openai.php)) and logs `ai.daily_cost_ceiling_exceeded`. `dailyCost()` sums today's [TokenUsage](app/Models/AI/TokenUsage.php) rows on the `analytics` connection, grouped by deployment so each bills at its own rate.

A null ceiling means dispatch is never budget-gated. `autoDispatchEnabled()` also requires the AI feature flag, `ai.auto_dispatch`, and non-empty Azure URI + key — so an unconfigured environment stages rows as `Pending` without dispatching at all.

## Consequences

- **Enables:** safe re-runs of every scheduler (re-running a sync is a no-op for unchanged content), and a hard daily spend backstop independent of per-block guards.
- **Costs:** the ceiling is coarse — it stops *new* auto-dispatch once exceeded but doesn't cancel work already queued, so spend can overshoot the ceiling by the in-flight batch. `dailyCost()` is an estimate against a hand-maintained price map; an unpriced deployment counts as `$0`.
- **Gotchas:** the ceiling gates only **auto-dispatch**. A manual UI "Baca ulang"/"Coba lagi" still fires past the ceiling by design (a human explicitly asked). Cost reads cross the `analytics` connection, so the budget check depends on that schema being reachable.

## See also

- [[ai-pipeline]] — where dispatch sits in the narrator pipeline
- [[analytics-db]] — the separate connection holding `ai_token_usages`
- [[per-block-manual-retry]] — the retry-side cost guards (cooldown + rate limit)
