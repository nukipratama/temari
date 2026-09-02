---
title: A hit ceiling answers a run question, it never fails one
description: The spend ceiling degrades the Q&A surface to RuleBasedRunAnswer instead of returning 409 or marking the question Failed, and both paths count toward the day's degraded-fill total.
tags: [decision, ai]
status: accepted
reviewed: 2026-08-14
code_refs:
  - app/Http/Controllers/Api/RunQuestionController.php
  - app/Jobs/AI/AnswerRunQuestionJob.php
  - app/Services/AI/RunQuestion/RuleBasedRunAnswer.php
  - app/Services/AI/CostCeilingLedger.php
---

# A hit ceiling answers a run question, it never fails one

**Status:** Accepted (decided 2026-08-14)

## Context

[[cost-ceiling-degrades-to-rule-based]] established that a hit budget is policy, not fault: narration blocks are served from the deterministic filler rather than left empty, because waiting buys nothing when the stop resolves on a clock. That decision explicitly carved the Q&A surface out — "run questions are still refused" — on the reasoning that a hit budget should not become a bypass.

Refusal turned out to mean two different things, both wrong:

- **`RunQuestionController::store()` returned 409** `generation_paused`, so a user who asked a question on a capped day got an error while every narrated block on the same page was quietly full of content.
- **`AnswerRunQuestionJob` marked the question `Failed`** if the ceiling tripped between dispatch and run. `Failed` is terminal for a question — there is no self-heal budget behind it, [[scoped-run-qa-not-an-analysis-row]] having deliberately kept `run_questions` out of the Analysis row model — so a purely temporal condition produced a permanent dead end. It also read as a fault in a surface whose whole point is that faults are visible.

`RuleBasedRunAnswer` ([RuleBasedRunAnswer.php](app/Services/AI/RunQuestion/RuleBasedRunAnswer.php)) already existed and already served the public demo, so the deterministic answer was sitting there unused on exactly the days it was most needed.

## Decision

**1. The ceiling answers rather than refuses.** `store()` serves the deterministic answer when the ceiling is the only stop ([`costCeilingDegraded`](app/Http/Controllers/Api/RunQuestionController.php#L69)), immediately after the demo branch and before the pause check. 409 is now reserved for the stops that are real: kill switch, unconfigured Azure, tripped config breaker.

This is not a bypass. Nothing is billed — the answer is assembled from the run's own stored numbers, the same content the demo has always received, and no agent run is dispatched.

**2. The job degrades too** ([`costCeilingDegraded`](app/Jobs/AI/AnswerRunQuestionJob.php#L60)), for the question dispatched moments before the ceiling tripped. `Failed` is kept for genuine faults: a missing detail, a terminal upstream error, an exhausted retry budget. The activity and its detail are resolved before the pause checks, because the deterministic answer reads the same detail the narrator would have.

**3. The response shape does not change.** Both degrade paths return what the demo path has always returned: `201` with a `Done` question carrying its answer. No client contract appears, and the existing 409 handling stays correct for the stops that still produce it.

**4. Degraded fills count questions as well as blocks.** `CostCeilingLedger::recordDegradedFill()` ([`recordDegradedFill()`](app/Services/AI/CostCeilingLedger.php#L27)) is called from both new paths. The ledger answers "how much did the ceiling change today", and an answer served deterministically is exactly that. Counting only narration blocks would undercount hardest on the busiest days, which are the days the number is read.

## Consequences

- **Enables:** a capped day degrades uniformly. Every AI surface on the page behaves the same way, so the user meets one consistent quality drop instead of a page that is half full and half erroring.
- **Costs:** the user is not told which producer answered, matching the existing stance for narration blocks and carrying the same weakness — a deterministic answer to a free-text question falls back to the run's headline reading, which is a blunter miss than a deterministic *narration* is. A user asking something specific on a capped day gets something general back, with no signal why.
- **Gotchas:** `degradedFills` is now a mixed count of blocks and answers, so it is no longer a count of Analysis rows. The `/ai-usage` gauge still labels it "blocks" and needs the wording widened.

## See also

- [[cost-ceiling-degrades-to-rule-based]] — the parent decision; this supersedes its "run questions are still refused" carve-out, and its "a hit budget is policy, not fault" reasoning is what forces this
- [[scoped-run-qa-not-an-analysis-row]] — why a question has no self-heal budget to fall back on, which is what makes `Failed` terminal here
- [[demo-triggers-served-rule-based]] — the other place the deterministic answer is served
