---
title: The pause reason derives from the dispatch gate, it does not restate it
description: A monitor that re-derives the conditions it reports on will drift from them; pauseReason() and the auto-dispatch check now read one ordered list, with the two genuine asymmetries passed as parameters.
tags: [decision, ai]
status: accepted
reviewed: 2026-08-14
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/AzureConfigCircuitBreaker.php
  - app/Livewire/Pulse/AiPipelineHealth.php
  - app/Services/AI/MaintainerAlerter.php
---

# The pause reason derives from the dispatch gate, it does not restate it

**Status:** Accepted (decided 2026-08-14)

## Context

Two questions are asked about AI generation, and they were answered by two different pieces of code:

- **"May we dispatch?"** — a boolean, asked by every dispatch path and by `ai:self-heal`.
- **"Why not?"** — a string, asked by `/pulse` ([`pauseReason`](app/Livewire/Pulse/AiPipelineHealth.php#L48)) and by the maintainer Telegram alert ([`syncPauseState`](app/Console/Commands/AI/SelfHealCommand.php#L23)).

`pauseReason()` re-derived the stop conditions in its own hand-maintained `if` ladder rather than deriving them from the gate it reports on. Its docblock claimed the two were "checked in the same precedence". They were not, and the drift was already shipping: the ladder never checked `ai.auto_dispatch`.

The consequence is the worst shape a monitor can take. With `AI_AUTO_DISPATCH=false` — a documented toggle in `.env.example` — nothing dispatched, while `/pulse` rendered **healthy** and the alerter pushed nothing, because a `null` reason is indistinguishable from a healthy pipeline. The operator's two instruments both agreed the pipeline was fine while it was doing nothing at all. This is the same failure the epic hit repeatedly: a guard that passes while blind.

It shipped because the tests asserted `pauseReason()` for the reasons it *did* implement and never asserted the invariant that binds it to `generationPaused()`.

## Decision

**1. One ordered list, two callers.** `blockingReason()` ([`blockingReason()`](app/Services/AI/AnalysisService.php#L632)) returns the first condition stopping a dispatch, or `null`. `pauseReason()` ([`pauseReason()`](app/Services/AI/AnalysisService.php#L608)) *is* that list; `autoDispatchEnabled()` ([`autoDispatchEnabled()`](app/Services/AI/AnalysisService.php#L613)) and `dispatchAllowedIgnoringBudget()` ([`dispatchAllowedIgnoringBudget()`](app/Services/AI/AnalysisService.php#L618)) ask whether it returned `null`. Adding a stop condition changes one list and both answers follow, so the reported reason cannot drift from the decision again.

**2. The two real asymmetries are parameters, not duplicated code.** The lists differed for two legitimate reasons, and collapsing them naively would have broken both:

- **The budget is optional.** `costCeilingDegraded()` ([`costCeilingDegraded()`](app/Services/AI/AnalysisService.php#L668)) must know whether the ceiling is the *only* stop, per [[cost-ceiling-degrades-to-rule-based]], so it asks the list to leave the budget out. `withBudget: false`.
- **Reporting must not consume the breaker's probe.** The config breaker half-opens after a cooldown to let exactly one request through. `allowsRequest()` ([`allowsRequest()`](app/Services/AI/AzureConfigCircuitBreaker.php#L45)) performs that transition as a side effect; `isTripped()` ([`isTripped()`](app/Services/AI/AzureConfigCircuitBreaker.php#L71)) exists precisely so a reader can look without taking the probe. A caller about to dispatch passes `probeBreaker: true` and takes it; a caller only reporting passes `false`. Without this, **rendering `/pulse` would spend the recovery probe**, and a dashboard refresh would silently consume the breaker's one chance to notice a fixed key.

**3. Two new reasons become reportable.** `auto_dispatch` for the env switch, and `suppressed` for the `withoutDispatching()` scope. `suppressed` is unreachable from any reporting caller — the flag lives inside a closure in a seeding or degrade path — but it is in the list because the list's value is being total. The renderer handles an unrecognised reason generically rather than each case needing a UI arm.

**4. The invariant is tested directly.** A dataset test asserts that for *every* stop, `generationPaused()` is true **and** `pauseReason()` names it; a second test pins the half-open probe asymmetry by checking the breaker's state after each call. The first is the test whose absence let this ship.

## Consequences

- **Enables:** a stop condition can be added in one place; `/pulse` and the alert stream stay truthful by construction rather than by discipline.
- **Costs:** `blockingReason()` takes two boolean parameters, which is less readable than a bare condition list, and the `probeBreaker` ternary is the densest line in the file. That density is the point: the asymmetry is now stated once, visibly, instead of being implied by two lists that looked the same.
- **Gotchas:** any new consumer of `pauseReason()` must handle an unfamiliar reason string, because the list is expected to grow. Treat a non-`null` reason as "paused" first and only then try to render a specific label.

## See also

- [[cost-ceiling-degrades-to-rule-based]] — why the budget is separable from every other stop
- [[bounded-self-heal-and-dead-letter]] — the pause-and-resume model these conditions gate
- [[ai-pipeline]] — where the dispatch gate sits
