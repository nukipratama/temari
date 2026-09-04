---
title: Chained (connected) narration for continuity
description: Per-activity and weekly/monthly narratives read as one connected thread, each link feeding prev_narrative into the next.
tags: [decision, ai]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Jobs/AI/AnalyzeBaseJob.php
  - app/Jobs/AI/AnalyzeRowJob.php
  - app/Jobs/AI/AnalyzeActivityJob.php
  - app/Console/Commands/AI/SelfHealCommand.php
  - app/Models/AI/Analysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - database/migrations/2026_05_18_071059_create_ai_analyses_table.php
---

# Chained (connected) narration for continuity

**Status:** Accepted (documented 2026-06-20)


> **Fact update, 2026-09-04.** The decision and its reasoning stand unchanged. The `pr_context`
> narration named below no longer exists: it was billed on every ingest and rendered on no page, so
> the surface and its self-heal sweep were cut. Read every mention of it here as historical;
> `card_flavor` is now the only ingest-only, unchained narration the argument covers.

> **2026-09-03 — one path below has changed, the decision has not.** The operator console
> moved behind a single `/devtools` prefix: `/ai-usage` is now `/devtools/ai-usage`,
> `/pulse` is `/devtools/pulse` and `/horizon` is `/devtools/horizon`. The gate on them
> also now skips outside production. Everything this decision says about behaviour stands.

## Context

A wall of independently-generated narration blocks reads like a stranger writing each entry cold. We wanted Temari's per-activity speech and the weekly/monthly recaps to read as a *connected thread*: each link should know what the previous one said and pick up the story rather than restate it. The narrators carry a `prev_narrative` input for exactly this continuity, so each successor must wait for its predecessor to be `Done` and read that already-stored narrative.

## Decision

We decided that the chained kinds (WeeklyRecap, MonthlyRecap, and the per-activity group: PostRunSpeech + RunInsight) narrate **oldest first, one link at a time**, predecessor-Done-before-successor:

- A row's [`afterDone`](app/Jobs/AI/AnalyzeRowJob.php) hook (no-op by default on [AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php), which extends [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php)) kicks the next pending link once a link finishes. For the per-activity chain this is overridden at the group level: [AnalyzeActivityJob](app/Jobs/AI/AnalyzeActivityJob.php) walks to the next chronological activity (by `start_date_local`) whose group is still Pending via `requestActivityGroup(invalidate: false)`.
- A scheduled `ai:self-heal` ([SelfHealCommand](app/Console/Commands/AI/SelfHealCommand.php)) is the safety net: it re-kicks the earliest **stalled** link of every chain (weekly + monthly + per-activity, plus the standalone card/PR narration) per user, so a link stranded by a transient failure or a cost-ceiling pause resumes once `dailyCost()` resets. `invalidate:false` makes a capped dispatch a clean no-op (the chain pauses rather than injecting filler), and it early-exits entirely while generation is paused. Failed links are bounded by `Analysis::MAX_SELF_HEAL_ATTEMPTS`: once a link burns its retry budget it drops to the /ai-usage dead-letter instead of re-billing every hour.
- Retry is **chain-aware** in [AnalysisController::trigger](app/Http/Controllers/Api/AnalysisController.php): only the chain *head* (the latest narrated link, a Done row) may regenerate in place. Any other chained click — including a Done mid-history row — resumes the earliest unfilled link *forward* with `invalidate:false`, so re-narrating mid-history never desyncs the later blocks that quoted its old narrative.

## Consequences

- **Enables:** narration that reads as one continuous voice; a self-healing backfill that completes the chain without a parallel burst; and safe retries that never desync downstream blocks.
- **Costs:** narration is serialized and paced (staggered dispatch), so a long backfill fills gradually rather than all at once.
- **Gotchas:** chain de-dup needs a **non-null** uniqueness key. MySQL treats each `NULL` as distinct in a unique index, so per-activity/weekly rows (null `discriminator`) would escape the constraint and race into duplicates — which once wedged the chain. The fix is the stored generated column `discriminator_key` = `coalesce(discriminator, '')` in [the ai_analyses migration](database/migrations/2026_05_18_071059_create_ai_analyses_table.php), with the unique index keyed on it instead of the nullable `discriminator` ([Analysis](app/Models/AI/Analysis.php)).

## See also

- [[ai-pipeline]] — the narrator/analysis pipeline this continuity model sits in
- [[deferred-recap-windowing]] — why the recap links are staged Pending before the chain reaches them
- [[per-block-manual-retry]] — the failure model the chain-aware retry builds on
