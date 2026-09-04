---
title: The spend ceiling degrades to rule-based, it does not pause
description: A hit daily budget serves deterministic Temari-voiced content instead of leaving blocks Pending; every other pause still pauses. Default ceiling $5/day.
tags: [decision, ai]
status: accepted
reviewed: 2026-08-14
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Jobs/AI/AnalyzeBaseJob.php
  - app/Services/AI/CostCeilingLedger.php
  - config/azure_openai.php
---

# The spend ceiling degrades to rule-based, it does not pause

**Status:** Accepted (decided 2026-08-14)

> **2026-09-03 — one path below has changed, the decision has not.** The operator console
> moved behind a single `/devtools` prefix: `/ai-usage` is now `/devtools/ai-usage`,
> `/pulse` is `/devtools/pulse` and `/horizon` is `/devtools/horizon`. The gate on them
> also now skips outside production. Everything this decision says about behaviour stands.

## Context

Public signup opens with **no invite gate and no per-user cost cap**, so the app-wide daily ceiling from [[idempotent-dispatch-cost-ceiling]] becomes the entire spend mechanism. How it *behaves* now matters more than its number.

Until this decision the ceiling **paused**: `autoDispatchEnabled()` went false and rows rested `Pending` until `ai:self-heal` resumed them. That is right for a temporary outage, and wrong for a budget that may bind most days once strangers arrive:

- a user who arrives after the ceiling trips sees empty blocks for the rest of the day, with no explanation;
- first-come-first-served means early users get everything and late users get nothing;
- waiting buys nothing — the budget resets on a clock, not on a fix.

Meanwhile the filler that already exists for the demo seed, [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php), is an **exhaustive `match` over every `AnalysisType`** ([`fillFor()`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L39)) and is data-driven where the subject's real numbers are available, so there is no coverage gap and no "generic template" tell.

## Decision

**1. The cost ceiling degrades.** Past the ceiling, a `Pending` block is filled from the rule-based filler and marked `Done` instead of resting `Pending` — at dispatch time in `AnalysisService::degradeToRuleBased()` (both the row and the group path), and again in `AnalyzeBaseJob::haltForPausedGeneration()` for a job that was already queued when the ceiling tripped ([`haltForPausedGeneration()`](app/Jobs/AI/AnalyzeBaseJob.php#L193)). Fills run under `withoutDispatching()`, so no job is queued, no cooldown starts, and no notification claims a narration that was never written.

Two statuses are excluded. A row that is already `Done` keeps the prose it was billed for. A **`Failed`** row stays `Failed`: it means something genuinely broke — a content filter, a malformed response, an exhausted retry budget — and the bounded self-heal plus the `/ai-usage` dead-letter exist to surface exactly that. Filling it would hide a real fault behind plausible content, and on a day the ceiling trips repeatedly that signal would be erased every day, which is the opposite of what an open-signup phase needs. The user still gets content on every other block; only the genuinely broken one stays honestly empty with its "Coba lagi".

**2. Only the cost ceiling degrades.** `autoDispatchEnabled()` is a conjunction of six conditions, and they are not the same kind of thing. It now splits into `dispatchAllowedIgnoringBudget()` (the `withoutDispatching` suppression, the `AiEnabled` kill switch, the `ai.auto_dispatch` env switch, a non-blank Azure URI + key, an untripped config breaker) and the budget check. `costCeilingDegraded()` is true only when *every* other condition passes and the budget is the sole stop.

The distinction is **fault versus policy**. An unconfigured or broken Azure, a flipped kill switch, a tripped config breaker: each is a fault or an explicit stop that a `Pending` row honestly represents, that a human or a cooldown will clear, and that `ai:self-heal` then resumes for free at full LLM quality. Filling those with the filler would spend the block's one chance at real narration on a problem that was about to be fixed, and would hide the fault behind content that looks fine. A hit budget is the opposite: it is a decision we made, nothing is broken, and the row will not get better narration by waiting — it will get the same filler content tomorrow, minus a day of the user seeing anything.

`generationPaused()` deliberately keeps returning true under the ceiling: manual triggers are still refused ([AnalysisController](app/Http/Controllers/Api/AnalysisController.php)), run questions are still refused, and `ai:self-heal` still early-exits, so a hit budget is not a bypass. The user-facing consequence of the degrade is that the blocks are full rather than empty, not that the button works.

**3. The default ceiling is $5.00/day** ([config/azure_openai.php](config/azure_openai.php)), on everywhere unless `AZURE_OPENAI_DAILY_COST_CEILING` overrides it — previously `null` (no ceiling) whenever the env var was unset, which is the wrong default for an open signup.

**4. A hit ceiling is legible on `/ai-usage`.** [CostCeilingLedger](app/Services/AI/CostCeilingLedger.php) records, per day, the first trip time and the number of blocks served rule-based because of it; [TokenUsageReport](app/Services/AI/TokenUsageReport.php) carries both into the existing `budget` block and `BudgetGauge` renders one dense line under the gauge. Cache-backed rather than a table: it answers an operator question about the current day, and the spend history it would duplicate already lives in `ai_token_usages`. `/pulse` still owns pipeline *health*; `/ai-usage` owns money.

## The numbers, and how much to trust them

Measured from production. These are **upper bounds**: the query did not pull cached tokens, and 43-62% of input arrives cached at a tenth of the rate ([config/azure_openai.php](config/azure_openai.php)).

| | |
|---|---|
| Steady state, one active user | **~$0.05/day** (busiest ordinary day $0.105, quiet day $0.015) |
| Prompt : completion token ratio | ~50:1 — input dominates |
| Worst day ever observed | **$6.21** (645 calls, 6.6M prompt tokens) |

The worst day was a re-narration under the old 365-day backfill cutoff; that cutoff drops to 84 days, which puts the same event at roughly $1-1.5.

So $5/day covers **~100 concurrently active users** at observed cost, or ~3 new-user backfills a day on top of normal traffic, and would have caught the worst day without ever binding on anything normal.

**Honest weakness: n = 1.** Every per-user figure above is extrapolated from a single athlete's usage. Real users will not distribute like one power user does, and the "~100 users" headline is the softest number here. The ceiling is a backstop sized from one sample, not a forecast — revisit it against real multi-user data rather than treating it as validated.

## Consequences

- **Enables:** every user keeps getting real Temari-voiced content on a capped day, in arrival order or not; the cap can be set aggressively low without the cost being "some users see nothing". Fault visibility is unaffected: a capped day still dead-letters what actually broke.
- **Costs:** narration quality silently drops for the rest of the day — the content is deterministic and data-driven, but it is not the LLM, and the user is not told which produced a given block. A user whose block genuinely failed still sees an empty state on a capped day, which is the price of keeping the `Failed` signal honest.
- **Gotchas:** the ledger is cache-backed, so flushing the cache loses the day's trip record (not the spend, which is in `ai_token_usages`). The ceiling is still checked *before* a job runs, so a tool-calling narration already in flight can overshoot it by one block ([[narration-agents-on-openai-php]] bounds that block).

## See also

- [[idempotent-dispatch-cost-ceiling]] — the ceiling this decision changes the behaviour of (its pause semantics are superseded here; its idempotency half still stands)
- [[bounded-self-heal-and-dead-letter]] — the pause-and-resume model that still governs every non-budget stop
- [[demo-triggers-served-rule-based]] — the other place the filler is served instead of the LLM
- [[ai-pipeline]] — where the dispatch gate sits in the pipeline
