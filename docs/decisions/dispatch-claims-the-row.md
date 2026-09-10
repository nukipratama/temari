---
title: Dispatch claims the row, and only a person re-arms its budget
description: Queueing an analysis is one conditional UPDATE that two racing dispatchers cannot both win, and a system re-narration no longer refills the self-heal budget.
tags: [decision, ai]
status: accepted
reviewed: 2026-09-09
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Models/AI/Analysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Services/AI/PlanNarrationRequester.php
---

# Dispatch claims the row, and only a person re-arms its budget

**Status:** Accepted (documented 2026-09-09). Refines the dispatch mechanism of
[[idempotent-dispatch-cost-ceiling]] and the retry budget of [[bounded-self-heal-and-dead-letter]].

> **One claim below is superseded (noted 2026-09-10).** "The plan-day edit now declares its
> origin" and every other `User`-origin controller no longer call
> `app(NarrationOrigin::class)->set(AnalysisOrigin::User)` themselves —
> [SetDefaultNarrationOrigin](../../app/Http/Middleware/SetDefaultNarrationOrigin.php), appended
> to the `web` middleware group, now stamps `AnalysisOrigin::User` once for every authenticated
> web request, and `PlanController::update()` inherits it like the rest. The re-arming behaviour
> this decision describes is unchanged: an invalidation whose origin is `AnalysisOrigin::User`
> still re-arms the budget, the origin just arrives from the request default now instead of a
> per-controller line.

## Context

Two problems, both about a bill being paid twice.

1. **The dispatch check and the dispatch write were separate.** "May this row be queued?" read
   the row's status in PHP, and "queue it" wrote it back — a read-modify-write with no lock, no
   status predicate on the write, and no DB constraint standing behind it (`ai_analyses` is unique
   on row *identity* only, over the generated `discriminator_key` column). Two dispatchers reading
   the same `Pending` row — a UI "Try again" racing the hourly `ai:self-heal`, a Strava resync
   racing a retry — both saw a dispatchable row, both marked it `Queued`, and both enqueued a job.
   The `Done` early-exit inside the jobs only catches a retry that arrives *after* the first job
   has finished; two jobs queued a millisecond apart both bill Azure.

2. **Every invalidation refilled the retry budget.** Invalidating a `Done` row reset `attempts` to
   0 along with the status. `attempts` is what `MAX_SELF_HEAL_ATTEMPTS` bounds, so any repeatable
   event that re-narrates — a run ingest, the Monday fingerprint sweep — handed a chronically
   failing row a fresh budget of three real LLM executions. The bound was per invalidation, not
   per row, which is not a bound.

## Decision

- **Dispatch claims the row.** `AnalysisService::claimForDispatch()`
  ([AnalysisService](app/Services/AI/AnalysisService.php)) replaces the old
  `rowNeedsDispatch()` + `markQueued()` pair on both dispatch paths with a single conditional
  `UPDATE ... WHERE id = ? AND status IN ('pending', 'failed')`, and the job is enqueued only if
  that update affected the row. The predicate is the from-state set the old in-PHP check already
  used, so a `Queued`, `Processing` or `Done` row is still left alone and still enqueues nothing —
  the difference is that the question is now asked by the write itself, and the loser of a race
  gets `false` instead of a second job. The group path dispatches its one group job when any row
  was created or claimed by *this* call.
- **`markQueued()` stays unguarded** for its other caller: the analyze jobs' `markRequeued`, which
  re-queues a row it already owns and is not a race with anyone.
- **Only an invalidation the athlete asked for re-arms the budget**, read from the
  [NarrationOrigin](app/Services/AI/NarrationOrigin.php) each entry point already declares:
  `invalidateDoneRow()` resets `attempts` when the origin is `AnalysisOrigin::User`, and leaves it
  alone otherwise. No new parameter — origin is already "what started this", declared once per
  entry point precisely so a dispatch concern isn't threaded through every signature, and "did a
  person ask for this?" is that same question. So the per-block "Try again"/"Reread"
  ([AnalysisController::trigger](app/Http/Controllers/Api/AnalysisController.php)), a plan edit and
  a manual replan ([PlanController](app/Http/Controllers/PlanController.php)) re-arm, while the
  post-run ingest cascade and the Monday `plan:regenerate` sweep — both `Ingest`/`Scheduled` — do
  not. The per-user dead-letter re-arm on `/devtools/ai-usage` is unchanged and still the
  operator's reset.
- **The plan-day edit now declares its origin.** `PlanController::update()` set none, so an edit's
  narration metered as `Unknown`; it declares `User` like `regenerate()` next to it, which both
  fixes the attribution and is what puts the edit on the re-arming side.

## Consequences

- **Enables:** a concurrent dispatch costs one LLM call, not two; and `MAX_SELF_HEAL_ATTEMPTS` is
  finally a per-row bound, so a chronically failing block reaches its dead-letter instead of being
  refilled by the next ingest.
- **Costs:** a row whose content legitimately changed after burning its budget starts its new
  narration with `attempts` already spent, so self-heal has less room for it. A person asking for
  it (the trigger, a plan edit) still gets the full budget back, and the dead-letter surface is
  where an operator sees the rest.
- **Untouched:** the upsert half of [[idempotent-dispatch-cost-ceiling]], every cost gate, the
  cooldown and rate limit of [[per-block-manual-retry]], and the jobs' own `Done` early-exit —
  which still guards the case this decision does not: a retry arriving after a job finished.

## See also

- [[idempotent-dispatch-cost-ceiling]] — the dispatch idempotency this hardens.
- [[bounded-self-heal-and-dead-letter]] — the retry budget this makes per-row.
- [[ai-pipeline]] — where dispatch sits in the narrator pipeline.
