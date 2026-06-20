---
title: AI narration pipeline
description: How AI copy flows from a narrator through a queued job into an Analysis row, with cadence, chaining, idempotency, cost ceiling, manual retry, and the rule-based fallback.
tags: [architecture, ai]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/AnalysisType.php
  - app/Services/AI/AnalysisStatus.php
  - app/Services/AI/AnalysisCadence.php
  - app/Jobs/AI/AnalyzeBaseJob.php
  - app/Jobs/AI/AnalyzeGroupJob.php
  - app/Jobs/AI/AnalyzeRowJob.php
  - app/Jobs/AI/AnalyzeActivityJob.php
  - app/Jobs/AI/AnalyzeWeeklyRecapJob.php
  - app/Models/AI/Analysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - config/ai.php
  - config/azure_openai.php
  - routes/console.php
---

# AI narration pipeline

Every piece of AI-written copy in the app is one row in the `ai_analyses` table, modelled by [Analysis](app/Models/AI/Analysis.php). A row is identified by `(subject_type, subject_id, analysis_type, discriminator)` and carries a `status`, the generated `content`, and bookkeeping (`attempts`, `queued_at`, `generated_at`, `error`). The pipeline's job is to move that row through its lifecycle and fill `content` exactly once per intended regeneration. See [[data-model]] for the table.

## The shape

```
narrator (LLM/rule-based)  ->  queued Job  ->  AnalysisService marks the row  ->  Analysis row (Done)
```

- **Narrators** ([app/Services/AI/Narrators/](app/Services/AI/Narrators/RunInsightNarrator.php)) own the prompt + the LLM call for one kind of copy and return a string.
- **Jobs** ([app/Jobs/AI/](app/Jobs/AI/AnalyzeRowJob.php)) run on the queue, call the narrator, and settle the row.
- **[AnalysisService](app/Services/AI/AnalysisService.php)** is the only writer of row state. It decides whether to dispatch, marks `Queued`/`Processing`/`Done`/`Failed`, and applies all the cost guards.

## AnalysisType and cadence

The full catalogue of copy kinds lives in the [AnalysisType](app/Services/AI/AnalysisType.php) enum (don't hand-copy the cases; they change). Each case answers a few questions in one place:

- `cadence()` returns an [AnalysisCadence](app/Services/AI/AnalysisCadence.php): `PerActivity`, `Daily`, `Weekly`, `Monthly`, or `OnDemand`. Cadence governs how the post-ingest cascade dispatches the type — per-activity types fire on every ingest, windowed (daily/weekly/monthly) types are deferred to a scheduled command so a multi-run window isn't re-billed per run, and on-demand types only fire on an explicit user click.
- `jobClass()` maps the type to its concrete [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php) subclass.
- `subjectType()` maps it to a model class or a synthetic string subject (e.g. `briefing_user_day`, `monthly_recap_user_month`) — the subject for daily/weekly/monthly copy is a user+period token, not a row.
- `isRuleBased()`, `isChained()`, `isZoneDependent()` are flags consumed below.

## Group jobs vs row jobs

There are two job base classes, both extending [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php):

- **[AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php)** carries a single `analysisId`, generates one row's content, marks it `Done`. Most types use this.
- **[AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php)** carries `(subjectId, discriminator)` and narrates several rows of one subject together in one LLM pass — e.g. [AnalyzeActivityJob](app/Jobs/AI/AnalyzeActivityJob.php) writes the post-run speech plus the three run-insight blocks for one activity at once. Which types belong to a group is the single source of truth `AnalysisType::groupJobClass()`; `AnalyzeGroupJob::groupedTypes()` derives from it. Grouping matters for cost: the speech reuses the already-Done insight rows verbatim, so a speech-only re-run never re-bills the insights (`AnalyzeActivityJob::resolveInsights()`).

`AnalysisService::request()` routes to `dispatchGroup()` when the type has a group job, else `dispatchRow()`.

## Dispatch, idempotency, and the cost ceiling

`AnalysisService::request()` upserts the row (`firstOrCreate`) and only dispatches when `autoDispatchEnabled()` is true AND the row actually needs work. The guards (see [[idempotent-dispatch-cost-ceiling]]):

- **Idempotency at upsert** — a row is created `Queued` (or `Pending` when dispatch is off) only `wasRecentlyCreated`; an existing row is re-dispatched only when `rowNeedsDispatch()` (status `Pending` or `Failed`). A `Done` or `Queued` row is left alone, so a same-day re-run of the daily briefing dispatches only the still-missing types.
- **Idempotency at execution** — `AnalyzeRowJob::handle()` early-exits when the row is already `Done`; `AnalyzeGroupJob::handle()` filters out the Done rows. This makes a UI retry that races a developer's Horizon retry safe — the second run sees `Done` and stops, so the LLM is never double-billed.
- **`afterCommit()`** — `dispatchPending()` defers the enqueue until the surrounding DB transaction commits, so a job can't run before (or be orphaned by a rollback of) the row it targets.
- **Daily cost ceiling** — `autoDispatchEnabled()` consults `dailyCostCeilingExceeded()`. When `azure_openai.daily_cost_ceiling` is set and today's spend exceeds it, auto-dispatch is skipped (rows stay `Pending`) until midnight resets the daily cost. A null ceiling never gates.

## Chained narration

Some kinds are "connected" — each link reads the previous same-kind narrative and may only be narrated after its chronological predecessor is `Done`. `AnalysisType::isChained()` lists them (weekly recap, monthly recap, and the per-activity group). See [[chained-narration]].

Propagation is a hook fired after a row/group completes: `AnalyzeRowJob::afterDone()` (e.g. [AnalyzeWeeklyRecapJob](app/Jobs/AI/AnalyzeWeeklyRecapJob.php) dispatches the next Pending week) and the group-level `AnalyzeGroupJob::afterGroupDone()` (e.g. [AnalyzeActivityJob](app/Jobs/AI/AnalyzeActivityJob.php) dispatches the next chronological activity group). Both:

- dispatch the successor with `invalidate: false`, so under a tripped cost ceiling or AI-off env the dispatch is a clean no-op and the chain **pauses** (rows stay Pending) rather than injecting filler;
- are **best-effort** — any error is logged and swallowed so a chain-advance failure never flips an already-billed Done row back to Failed.

A stalled link is re-kicked hourly by the `ai:resume-chains` command in [routes/console.php](routes/console.php), which only re-dispatches Pending/Failed links and so never re-bills.

## Deferred recaps (windowing)

The still-open current week/month never narrates on demand — its recap row is staged `Pending` (via `AnalysisService::requestDeferred()`) and filled only by the scheduled command once the period closes (`ai:weekly-recap` Monday 00:01, `ai:monthly-recap` on the 1st), in [routes/console.php](routes/console.php). `AnalysisController::trigger()` guards this with `isStillOpenRecapPeriod()`, returning the inert row unchanged. A Pending recap for the open window is therefore expected, not a backlog. See [[deferred-recap-windowing]].

## Manual (never auto) retry

Failed blocks are never auto-retried — that keeps LLM cost predictable. See [[per-block-manual-retry]].

- **Failure model** — [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php) sets `$tries = 3` with backoff. `settleFailure()` re-queues + releases a `TransientUpstreamException` (429/5xx/timeout, honoring `Retry-After` capped at 600s) while a try remains; a terminal `UnavailableException` (bad schema / malformed JSON) is swallowed so the worker stops; anything else is rethrown into `failed_jobs`. The `failed()` hook marks a row stuck in `Processing` (worker died) back to `Failed` so it becomes re-dispatchable.
- **Retry path** — a failed block shows a "Coba lagi" empty state; the user re-dispatches via `POST` to `AnalysisController::trigger()`. For chained kinds, a click does **not** narrate the clicked row in isolation — `earliestUnfilledChainLink()` resumes the earliest unfilled link forward (`invalidate: false`, no re-bill of Done siblings), and only a genuine chain **head** regenerate (`isChainHeadRegenerate()`) re-narrates that exact row with `invalidate: true`. A `cooldownRemaining()` (`ai.cooldown_seconds`) suppresses rapid re-triggers. Developers can also retry from Horizon's failed-jobs tab.

## Rule-based fallback (Azure unconfigured)

LLM calls go through an Azure OpenAI client configured by [config/azure_openai.php](config/azure_openai.php) (host + key + per-narrator deployment overrides; routing detail in [[azure-openai-routing]]). When `azure_openai.uri` or `azure_openai.api_key` is empty, `autoDispatchEnabled()` returns false and **no job is dispatched** — rows stay `Pending` (dev/demo without credentials).

Two distinct things still produce content without the LLM:

1. **Rule-based types** — `AnalysisType::isRuleBased()` (the run-insight blocks + trend caption) are deterministic. In `dispatchRow()`, when the type is rule-based or auto-dispatch is off, the row is filled inline via `ruleBasedContent()` / `RuleBasedNarrationFiller` and marked Done — no queue, no tokens.
2. **Demo seed** — the demo seeder backfills every Analysis row through [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) under `AnalysisService::withoutDispatching()`, so seeding spends no tokens. The "Baca ulang" button stays live so a reviewer can trigger one real LLM call per block on demand.

## See also

- [[run-ingest-pipeline]] — what triggers the post-ingest cascade that calls `request()`.
- [[analytics-db]] — `ai_token_usages` metering that backs the daily cost ceiling.
- [[data-model]] — the `ai_analyses` table.
