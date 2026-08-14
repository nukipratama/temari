---
title: Demo triggers are served rule-based, never billed
description: The public demo keeps a working "Baca ulang", but the trigger endpoint fills its blocks from RuleBasedNarrationFiller instead of dispatching an LLM job, so an anonymous visitor can never spend Azure tokens.
tags: [decision, ai]
status: accepted
reviewed: 2026-07-28
code_refs:
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
  - routes/web.php
---

# Demo triggers are served rule-based, never billed

**Status:** Accepted (documented 2026-07-28)

> **One premise below is superseded (noted 2026-08-14) by [[cost-ceiling-degrades-to-rule-based]].** The Context lists "a manual trigger deliberately fires past the AI cost ceiling" among the reasons this decision was needed. It no longer does — `generationPaused()` folds the ceiling in and [AnalysisController::trigger](app/Http/Controllers/Api/AnalysisController.php#L24) refuses with a 409. The decision itself is unaffected: the demo branch still sits *ahead* of that guard, so a demo trigger is still served rule-based and still cannot bill.

## Context

[[demo-user-billing-exclusion]] held the demo account out of every recurring
scheduler but deliberately left the per-block "Baca ulang" / "Coba lagi" button
live, so a reviewer could upgrade one block to a real LLM narrative on demand.
That reasoning assumed a *reviewer*. Three facts together broke the assumption:

- the demo login is **public**, and `DEMO_LOGIN_ENABLED` is on in production —
  a one-click session behind no credential (see [config/demo.php](config/demo.php));
- a manual trigger **deliberately fires past the AI cost ceiling**, per
  [[idempotent-dispatch-cost-ceiling]] ("a human explicitly asked");
- the only remaining bound is the per-user limiter in
  [AppServiceProvider](app/Providers/AppServiceProvider.php#L87), which the whole
  internet shares as a single demo user — still thousands of billed generations
  a day.

So the manual trigger was an unauthenticated path to the Azure bill. Hard-blocking
the demo from the endpoint would have closed it, but at the cost of the reviewer
affordance the original ADR was protecting.

## Decision

We decided the demo's trigger **succeeds and is served from the deterministic
rule-based filler** instead of dispatching an LLM job. The button behaves
normally (200, a `done` row, fresh content); it simply never reaches Azure.

The seam is [`AnalysisService::requestRuleBased()`](app/Services/AI/AnalysisService.php#L108):
it reuses or stages the row via `requestDeferred()`, then marks it Done with
[RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php)
content inside `withoutDispatching()`. Running under that flag is what makes the
guarantee structural rather than incidental — `markDone()` skips both the
cooldown and the notification fan-out when dispatch is suppressed, so the demo
block stays instantly re-triggerable and sends nothing.

[AnalysisController::trigger](app/Http/Controllers/Api/AnalysisController.php#L24)
branches on [`AnalysisService::shouldServeRuleBased`](app/Services/AI/AnalysisService.php)
(`is_demo`) after the ownership, still-open-recap and cooldown guards,
and *before* the chain-resume and zone-recompute paths — both of those exist only
to shape a real narration. The non-demo path is untouched.

The route keeps only `throttle:analysis-trigger`
([routes/web.php](routes/web.php#L160)); `block-demo-telegram` is deliberately
**not** applied, since the demo is meant to succeed here.

## Consequences

- **Enables:** no unauthenticated path spends Azure tokens, and the demo keeps a
  live, working "Baca ulang" — strictly better than either extreme. This
  refines, rather than revokes, the affordance in [[demo-user-billing-exclusion]];
  that ADR's scheduler exclusions stand unchanged.
- **Costs:** a demo "Baca ulang" now yields rule-based prose rather than a real
  LLM narrative, so it no longer demonstrates live narration quality. Reviewing
  actual narrator output needs a non-demo account.
- **Gotchas:** the filler is **deterministic**, so re-pressing the button on the
  same row returns byte-identical text — only the `generated_at` timestamp moves.
  Accepted rather than randomised: the determinism is a contract the demo seeder
  relies on to converge across re-seeds. The guard is keyed on `is_demo`, not on
  the route, so any future trigger entry point inherits it automatically.

## See also

- [[demo-user-billing-exclusion]] — the scheduler-side half of the demo cost story
- [[idempotent-dispatch-cost-ceiling]] — why a manual trigger bypasses the ceiling
- [[per-block-manual-retry]] — the button this decision keeps alive
- [[ai-pipeline]] — the pipeline these triggers feed
