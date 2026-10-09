---
title: A block that failed during a pause gets one fresh attempt when the pause lifts
description: When a non-ceiling generation pause lifts, every active athlete's block that failed from one sweep before the pause began is re-dispatched once, one attempt short of the dead-letter limit.
tags: [decision, ai]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Ops/MaintainerAlerter.php
  - app/Services/AI/SelfHealer.php
  - app/Console/Commands/AI/SelfHealCommand.php
  - app/Support/Config/AppConfigKey.php
  - resources/js/components/temari/AnalysisStatus.tsx
---

# A block that failed during a pause gets one fresh attempt when the pause lifts

**Status:** Accepted (2026-10-02). Partly supersedes [[bounded-self-heal-and-dead-letter]]: a
dead-lettered block is no longer re-armed only by a person.

## Context

While generation is paused, a failed block hid its "try again" button and told the athlete Temari
would be back shortly, beside a banner promising the notes would catch up on their own. For a
dead-lettered block that was false. Self-heal had stopped retrying it on purpose, so it stayed failed
until a maintainer re-armed it. A block that fails during a pause usually fails because of the fault
that caused the pause, for example an auth error that trips the config breaker, so the retry budget
it burned says little about the block itself.

## Decision

- **"Resumed" is the hourly pause transition.** [`MaintainerAlerter::syncPauseState()`](app/Services/Ops/MaintainerAlerter.php#L173)
  already compares the current pause reason to the stored one. It now stamps
  `AppConfigKey::AiPauseStartedAt` when a pause begins, keeps that stamp while only the reason
  changes, and returns it on the one sweep that sees the reason clear to none. That makes the retry
  once per pause, not once per sweep. A pause shorter than one sweep is not detected.
- **The app-wide cost ceiling lifting is not a resume.** Under the ceiling a failed block is left
  failed by design ([[cost-ceiling-degrades-to-rule-based]]), so the ceiling resetting at midnight
  says nothing about the fault.
- **Which blocks.** Every `Failed` block of an active, non-demo athlete (`RecentlyActiveUsers`, the
  same scope as every self-heal sweep) whose row last changed no earlier than one hour, one sweep,
  before the pause began ([`SelfHealer::retryFailedDuringPause()`](app/Services/AI/SelfHealer.php#L86)).
  This covers every narration type, including the plan voices no sweep picks up.
- **One attempt.** Each block's `attempts` is set to `MAX_SELF_HEAL_ATTEMPTS - 1`, then it is
  re-dispatched with `invalidate: false`, spaced like the sweep. One more failure dead-letters it
  again, so it keeps its `/devtools/narration` visibility and fires the dead-letter alert again. The
  re-dispatch runs from [`SelfHealCommand`](app/Console/Commands/AI/SelfHealCommand.php#L27) before
  the ordinary sweep.
- **The copy while paused.** A failed block says it "will be written once Temari is back", with no
  button ([`AnalysisStatus`](resources/js/components/temari/AnalysisStatus.tsx#L337)). It says so only
  while the `aiPauseRetriesFailed` shared prop is true ([`AiProps`](app/Services/Inertia/AiProps.php#L38)):
  under the app-wide cost ceiling, whose lift retries nothing, a failed block keeps the plain failed
  copy. Once generation is back, the normal failed copy and its "try again" button return.

## Why one sweep before the pause

A config-breaker trip does not itself mark any row failed. The breaker only counts auth failures
from the Azure call ([`AgentLoop`](app/Services/AI/Agent/AgentLoop.php#L155)), and the analyze job
that hit them marks its own row failed. So the rows that tripped the breaker failed at or shortly
before the trip, and the trip is detected by the next hourly sweep. Stamping the start at detection
and looking back one sweep covers those rows.

## Consequences

- **Costs:** at most one extra billed call per block, per real pause. Blocks that failed long before
  the pause are left alone, and the per-call cost ceiling still guards each re-dispatch inside the
  job, so the bound from [[bounded-self-heal-and-dead-letter]] still holds.
- **Lowers some budgets:** a block that failed under budget is also set one attempt short of the
  limit, so it gets one more try rather than its remaining ones.
- **Not covered:** a pause that only lifts into the app-wide ceiling (for example the kill switch
  turned back on while the ceiling is tripped) ends as a ceiling lift and is not treated as a resume.
  Blocks of an inactive athlete are not retried, and they show the normal failed copy once
  generation is back.

## See also

- [[bounded-self-heal-and-dead-letter]], the bound this keeps.
- [[pause-reason-derives-from-the-dispatch-gate]], where the pause reason comes from.
- [[narration-spends-only-on-active-athletes]], the athlete scope.
