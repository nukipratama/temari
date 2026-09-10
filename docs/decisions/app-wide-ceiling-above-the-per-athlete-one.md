---
title: An app-wide daily ceiling sits above the per-athlete one
description: A configured total stop (default $5.00/day) bounds the whole app's LLM spend absolutely; the per-athlete slice keeps one athlete from spending everyone's budget underneath it.
tags: [decision, ai]
status: accepted
reviewed: 2026-09-10
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/MaintainerAlerter.php
  - app/Services/AI/TokenUsageReport.php
  - config/azure_openai.php
  - resources/js/components/aiusage/BudgetGauge.tsx
---

# An app-wide daily ceiling sits above the per-athlete one

**Status:** Accepted (decided 2026-09-10)

## Context

[[cost-ceiling-degrades-to-rule-based]] replaced a shared spend pool with a **per-athlete**
ceiling, because a shared pool let the heaviest athlete on a given day spend the whole budget
and silently degrade everybody else. That fixed the blast radius and removed the only absolute
bound on the bill: with `daily_cost_ceiling_per_user` as the sole limit, the worst-case day is
that figure **times the athlete count**, which grows with signups. `/devtools/ai-usage` reported
that product as a derived number precisely because nothing enforced it.

Signup is open. A burst of new athletes — each backfilling — multiplies the ceiling rather than
sharing it, and the first signal would be the Azure bill.

## Decision

**1. A second, app-wide ceiling.** [`daily_cost_ceiling_total`](config/azure_openai.php#L80), env
`AZURE_OPENAI_DAILY_COST_CEILING_TOTAL`, default **$5.00/day**, `null` to disable. It is today's
spend across every athlete, the same `ai_token_usages` sum the per-athlete check runs with one
predicate dropped.

**2. Both ceilings are one code path.** [`dailyCostCeilingExceeded()`](app/Services/AI/AnalysisService.php#L774)
asks the total first and the athlete's own slice second, both through the same
[`ceilingExceeded()`](app/Services/AI/AnalysisService.php#L872) helper. Sharing the path is the point: what "past the ceiling" *does* can never diverge between the two. So
everything [[cost-ceiling-degrades-to-rule-based]] decided holds unchanged for the total —
`Pending` blocks are filled from [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php)
and marked `Done`, a `Failed` row stays `Failed` with its dead-letter visibility, manual triggers
are refused with the same honest message, and the demo login is served rule-based regardless
([`shouldServeRuleBased()`](app/Services/AI/AnalysisService.php#L643)) so it is
neither affected by the ceiling nor able to trip it.

**3. The total *is* a global pause; the per-athlete slice still is not.** The total takes no
athlete argument, so it gates callers that hold none — `pauseReason()`, the /pulse status line,
`ai:self-heal`. That is the one semantic the 2026-09-07 change removed and this decision
restores, deliberately: an athlete exhausting their own slice is ordinary operation, the whole
app stopping is not.

**4. A trip pushes one maintainer alert.** [`totalCeilingReached()`](app/Services/AI/MaintainerAlerter.php#L191) names
the spend, the ceiling and how many athletes are degraded, behind a one-hour cooldown so a
ceiling that stays tripped alerts once per window rather than once per gated dispatch. The
per-athlete ceiling stays silent.

**5. Both are visible.** [TokenUsageReport](app/Services/AI/TokenUsageReport.php#L62) carries
`totalCeiling` beside the per-athlete figure and
[BudgetGauge](resources/js/components/aiusage/BudgetGauge.tsx#L72) renders a second tile against
the same spend.
`dailyCeiling` stays what it was — perUser x athletes, derived, *not* a limit — and now reads as
the figure the total binds before.

## Why $5.00

Unchanged from the sizing in [[cost-ceiling-degrades-to-rule-based]]: ~$0.05/athlete/day at
observed steady state, worst day ever observed $6.21 under a backfill cutoff since shortened. $5
is roughly 100 concurrently active athletes, and would have caught that worst day. It binds
before the derived product does from the sixth athlete onward (5 x $1.00), which is the intent —
the per-athlete slice shapes *who* degrades, the total decides *when*.

## Consequences

- **Enables:** the bill has an absolute daily bound again, independent of how many people sign
  up, without giving back the fairness the per-athlete slice bought.
- **Costs:** a busy legitimate day now degrades *everyone* to rule-based narration, which is the
  failure mode the per-athlete ceiling was introduced to avoid — accepted here because it is
  bounded by an explicit number an operator chose, and because the alert makes it loud rather
  than silent.
- **Gotchas:** the memo is per scope, so a single request or job asks the total once; a ceiling
  raised mid-day takes effect on the next scope. Both ceilings are checked *before* a job runs,
  so a narration already in flight can overshoot by one block.

## See also

- [[cost-ceiling-degrades-to-rule-based]] — what a hit ceiling does, unchanged and shared by both
- [[idempotent-dispatch-cost-ceiling]] — the original dispatch-time guard
- [[ai-usage]] — where both ceilings are reported
