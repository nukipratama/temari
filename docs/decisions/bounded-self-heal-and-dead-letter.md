---
title: Bounded self-heal + dead-letter for failed AI blocks
description: LLM blocks stay honestly Pending when paused and self-heal for free; genuinely-failed blocks get a bounded auto-retry, then dead-letter for a per-user manual re-arm.
tags: [decision, ai]
status: accepted
reviewed: 2026-07-04
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Jobs/AI/AnalyzeBaseJob.php
  - app/Console/Commands/AI/SelfHealCommand.php
  - app/Models/AI/Analysis.php
  - app/Http/Controllers/TokenUsageController.php
---

# Bounded self-heal + dead-letter for failed AI blocks

**Status:** Accepted (documented 2026-07-04). Supersedes the "no self-healing" stance of [[per-block-manual-retry]].

> **One claim below has since gone stale (noted 2026-08-14).** "`RuleBasedNarrationFiller` is now demo-seed-only" was true when this was written; it is not now. Three production paths reach the filler on real accounts: the narration age cutoff ([[twelve-week-narration-cutoff]]), the Azure content-filter fallback in [AnalyzeRowJob.php:49](app/Jobs/AI/AnalyzeRowJob.php#L49) / [AnalyzeGroupJob.php:135](app/Jobs/AI/AnalyzeGroupJob.php#L135), and the public demo's triggers ([[demo-triggers-served-rule-based]]). The *decision* this note records still holds exactly as written: a **paused** block is still never templated, it rests honestly `Pending`. The filler is now reached where the app has decided not to bill at all, which is a different thing from being unable to bill right now.

## Context

[[per-block-manual-retry]] chose "failed blocks are never auto-retried, no self-healing" to keep LLM spend predictable. Two problems surfaced under that model:

1. **Silent rule-based downgrade on real accounts.** When generation was paused (daily cost ceiling hit, `AiEnabled` off, or Azure unconfigured), `dispatchRow()` filled single-row LLM blocks with `RuleBasedNarrationFiller` templates and marked them `Done` with no `is_demo` guard, indistinguishable from real prose, and the 15-minute cooldown then locked the block. So a real user could see a template *and* be unable to retry.
2. **Unbounded re-bill.** The chain resume sweep (then `ai:resume-chains`) already re-fired `Failed` recap links every hour with no give-up boundary, so a terminally-broken link re-billed the LLM indefinitely, exactly the "invisible bill" the prior ADR wanted to avoid.

We wanted honest empty states instead of fake templates, cost-free recovery once a cap clears, and a cost-*bounded* rescue for transient failures, with a clear stopping point.

## Decision

- **Paused generation stays honest, never templated.** `dispatchRow()` fills only genuinely rule-based types inline; a paused single-row LLM block rests `Pending` (an existing `Done` keeps its real prose) rather than being templated ([AnalysisService.php:194](app/Services/AI/AnalysisService.php#L194), [:204](app/Services/AI/AnalysisService.php#L204)). `RuleBasedNarrationFiller` is now demo-seed-only.
- **Execution-time cost guard.** The cap was only a dispatch-time gate, so a job dispatched just before the ceiling tripped would still bill. `AnalyzeBaseJob::haltForPausedGeneration()` reverts a job's rows to `Pending` before `markProcessing` when paused ([AnalyzeBaseJob.php:85](app/Jobs/AI/AnalyzeBaseJob.php#L85), via `AnalysisService::revertToPending()` [:395](app/Services/AI/AnalysisService.php#L395) and `generationPaused()` [:423](app/Services/AI/AnalysisService.php#L423)), so no `attempts` burn and no bill.
- **Bounded self-heal.** `ai:self-heal` (renamed from `ai:resume-chains`, [SelfHealCommand](app/Console/Commands/AI/SelfHealCommand.php)) re-kicks the earliest stalled block per user (the chains, plus the ingest-only card-flavor and PR-context narration) with `invalidate:false`, early-exits while paused, and is bounded by `Analysis::MAX_SELF_HEAL_ATTEMPTS` ([Analysis.php:57](app/Models/AI/Analysis.php#L57), `scopeStalled` [:101](app/Models/AI/Analysis.php#L101)). A paused re-dispatch is a free no-op; a `Pending` block is always under budget, so the budget only ever stops a `Failed` block that has burned its retries.
- **Dead-letter + per-user manual re-arm.** A `Failed` block past the budget is `scopeDeadLettered` ([Analysis.php:116](app/Models/AI/Analysis.php#L116)), surfaced grouped-per-user on `/ai-usage` with a single "Coba lagi semua" that resets `attempts` (re-arming the budget) and re-dispatches all of that user's stuck blocks ([TokenUsageController::retryFailed](app/Http/Controllers/TokenUsageController.php#L66)). That surface is session-less (edge basicauth), so the `viewAiUsage` gate can't apply.

## Consequences

- **Enables:** honest empty states on real accounts (no fake templates, no cooldown-lock on a template); free recovery once a cap clears; a cost-bounded rescue for transient upstream failures; and a per-user dead-letter so a human can re-arm what self-heal gave up on.
- **Costs:** a genuinely-broken block now costs up to `MAX_SELF_HEAL_ATTEMPTS` (3) LLM calls before dead-lettering, where the prior ADR spent zero until a human asked. This is the deliberate trade for automatic recovery.
- **Preserved from [[per-block-manual-retry]]:** the per-block "Coba lagi" / "Baca ulang" UI, the 15-minute cooldown, and the per-user rate limit all still stand. The user-facing briefing / run-insight / speech blocks are still manual-retry-first (the per-activity sweep is Pending-only; briefing is not swept).

## See also

- [[per-block-manual-retry]] — the superseded stance this refines.
- [[chained-narration]] — the chain self-heal this generalizes.
- [[idempotent-dispatch-cost-ceiling]] — the dispatch-side cost ceiling this guards at execution time too.
- [[ai-pipeline]] — the pipeline these blocks flow through.
