---
title: Twelve-week narration cutoff
description: Runs, weeks and months older than 84 days are narrated by the deterministic filler instead of the LLM, and every manual trigger that could reach past the cutoff is gated too.
tags: [decision, ai]
status: accepted
reviewed: 2026-08-14
code_refs:
  - config/ai.php
  - app/Services/AI/BackfillAgeGate.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
---

# Twelve-week narration cutoff

**Status:** Accepted (documented 2026-08-14)

## Context

Narration depth on a new connection was capped at 365 days ([config/ai.php](config/ai.php)). With one user that cap was theoretical: the history was already narrated, and steady state costs about $0.05/user/day. It stopped being theoretical the first time a fresh Strava connect walked a full year of history — a single new-user backfill billed **$6.21 in one day**, roughly four months of that user's steady-state spend, spent before they had looked at anything.

Public signup turns that from an anecdote into the per-signup unit cost. The question is not whether to bound backfill depth but where the bound stops buying anything.

Two things made the old cap leakier than it looked:

1. **Depth was the only lever, and it was set to a year.** A year of narration is not a year of value: nobody opens the story of a run from three seasons ago, and the chained narrators only need enough predecessor to have something to refer back to.
2. **The cap only ever ran on the ingest path.** `AnalysisController::trigger()` — the per-block "Baca ulang" — had no age check at all. For the chained kinds that is structurally safe: [ChainResolver::isHeadRegenerate()](app/Services/AI/ChainResolver.php) admits only the true chain head, so clicking an old link resumes the chain forward instead of narrating itself. `card_flavor` and `pr_context` are **not** chained ([AnalysisType::isChained()](app/Services/AI/AnalysisType.php)), so a manual retry on a years-old run dispatched a real LLM job and re-billed exactly what the cutoff had routed to the filler.

## Decision

**The cutoff is 84 days** ([config/ai.php:43](config/ai.php#L43)), overridable via `AI_BACKFILL_MAX_AGE_DAYS`. Twelve weeks is a training block; beyond it a narrated run is history rather than context, and Temari has nothing to say about it that the numbers do not already say.

**One gate owns the rule.** [BackfillAgeGate](app/Services/AI/BackfillAgeGate.php) is the single reader of `ai.backfill_max_age_days` for both entry points: the ingest fan-out asks it per activity ([DispatchPostRunAnalysis.php:49](app/Listeners/DispatchPostRunAnalysis.php#L49)), and the manual trigger asks it per subject ([AnalysisController.php:61](app/Http/Controllers/Api/AnalysisController.php#L61)).

**`blocksManualTrigger()` is exhaustive over `AnalysisType`** ([BackfillAgeGate.php:34](app/Services/AI/BackfillAgeGate.php#L34)) — no `default` arm, so a new narrated block cannot be added without stating whether its manual trigger can reach material older than the cutoff. Three kinds block:

- `card_flavor` and `pr_context` — resolved to their run's `start_date_local` through the card / PR row.
- `briefing_mascot_voice` — its discriminator *is* the day it narrates, and the validation rule is an unbounded `date_format:Y-m-d`, so a hand-crafted POST could ask for a briefing about any date in history.

The rest do not block, each for a stated reason: the four chained kinds are already covered by the chain head rule, and `aku_profile_voice` / `briefing_featured_kartu_voice` narrate material that is current whatever its date (the profile voice reads a rolling window as of now and ignores its week key; the featured kartu is whichever card the dashboard is showing today, picked from the last 8 runs — which for a low-mileage runner legitimately reaches past 84 days).

**A blocked trigger is served, not refused.** It resolves through [AnalysisService::requestRuleBased()](app/Services/AI/AnalysisService.php#L107) with `refillDone: false`, so the click always produces content — no dead button, no empty state a retry cannot fill — while a row that already holds real, billed-for prose is never clobbered.

## Consequences

- **Enables:** a bounded worst case per signup. The deepest a new connection can bill is 12 weeks of history, and no click can extend it.
- **Costs:** narration on a run older than 12 weeks is deterministic rather than written. It reads as Temari but does not know the run; volume, pace and PR data are untouched, since the cutoff only governs prose.
- **`RuleBasedNarrationFiller` is a production surface, not a seed-only helper.** This decision is the second of three paths that reach it in production, alongside the Azure content-filter fallback in [AnalyzeRowJob.php:49](app/Jobs/AI/AnalyzeRowJob.php#L49) / [AnalyzeGroupJob.php:135](app/Jobs/AI/AnalyzeGroupJob.php#L135) and the public demo account's triggers. [[bounded-self-heal-and-dead-letter]] calls it "demo-seed-only", which was true when written and is not now.
- **Raising the value re-opens the cost.** It is the one number that scales with signups rather than with usage, which is why it carries a comment in `.env.example` rather than being a bare tunable.

## See also

- [[idempotent-dispatch-cost-ceiling]] — the app-wide daily ceiling this sits under; that one bounds the day, this one bounds the signup.
- [[chained-narration]] — why the chained kinds need no age gate of their own.
- [[bounded-self-heal-and-dead-letter]] — the retry budget a rule-based row never enters.
- [[summary-first-ingest]] — the ingest shape that makes a deep backfill cheap in Strava reads but expensive in narration.
