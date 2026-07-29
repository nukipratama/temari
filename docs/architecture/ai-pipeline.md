---
title: AI narration pipeline
description: How AI copy flows from a narrator through a queued job into an Analysis row, with cadence, chaining, idempotency, cost ceiling, manual retry, and dead-lettering.
tags: [architecture, ai]
status: living
reviewed: 2026-07-29
code_refs:
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/ChainResolver.php
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
  - config/horizon.php
  - routes/console.php
---

# AI narration pipeline

Every piece of AI-written copy in the app is one row in the `ai_analyses` table, modelled by [Analysis](app/Models/AI/Analysis.php). A row is identified by `(subject_type, subject_id, analysis_type, discriminator)` and carries a `status`, the generated `content`, and bookkeeping (`attempts`, `queued_at`, `generated_at`, `error`). The pipeline's job is to move that row through its lifecycle and fill `content` exactly once per intended regeneration. See [[data-model]] for the table.

## The shape

```
narrator (LLM)             ->  queued Job  ->  AnalysisService marks the row  ->  Analysis row (Done)
```

- **Narrators** ([app/Services/AI/Narrators/](app/Services/AI/Narrators/RunInsightNarrator.php)) own the prompt + the LLM call for one kind of copy and return a string.
- **Jobs** ([app/Jobs/AI/](app/Jobs/AI/AnalyzeRowJob.php)) run on the queue, call the narrator, and settle the row.
- **[AnalysisService](app/Services/AI/AnalysisService.php)** is the only writer of row state. It decides whether to dispatch, marks `Queued`/`Processing`/`Done`/`Failed`, and applies all the cost guards.

## AnalysisType and cadence

The full catalogue of copy kinds lives in the [AnalysisType](app/Services/AI/AnalysisType.php) enum (don't hand-copy the cases; they change). Each case answers a few questions in one place:

- `cadence()` returns an [AnalysisCadence](app/Services/AI/AnalysisCadence.php): `PerActivity`, `Daily`, `Weekly`, `Monthly`, or `OnDemand`. Cadence governs how the post-ingest cascade dispatches the type — per-activity types fire on every ingest, windowed (daily/weekly/monthly) types are deferred to a scheduled command so a multi-run window isn't re-billed per run, and on-demand types only fire on an explicit user click.
- `jobClass()` maps the type to its concrete [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php) subclass.
- `subjectType()` maps it to a model class or a synthetic string subject (e.g. `briefing_user_day`, `monthly_recap_user_month`) — the subject for daily/weekly/monthly copy is a user+period token, not a row.
- `isChained()`, `isZoneDependent()` are flags consumed below.

Recurring recap windows are modelled by [RecapPeriod](app/Services/AI/RecapPeriod.php) — it resolves the current open period's boundaries (e.g. which ISO week is "this week", which calendar month is "this month") and is used by the scheduled commands and `AnalysisController::trigger()` to gate deferred dispatch (see [[deferred-recap-windowing]]).

## Group jobs vs row jobs

There are two job base classes, both extending [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php):

- **[AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php)** carries a single `analysisId`, generates one row's content, marks it `Done`. Most types use this.
- **[AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php)** carries `(subjectId, discriminator)` and narrates several rows of one subject together in one LLM pass. [AnalyzeActivityJob](app/Jobs/AI/AnalyzeActivityJob.php) is the only group: it writes the post-run speech plus the three run-insight blocks for one activity at once. Which types belong to a group is the single source of truth `AnalysisType::groupJobClass()`; `AnalyzeGroupJob::groupedTypes()` derives from it. Grouping matters for cost: the speech reuses the already-Done insight rows verbatim, so a speech-only re-run never re-bills the insights (`AnalyzeActivityJob::resolveInsights()`).

`AnalysisService::request()` routes to `dispatchGroup()` when the type has a group job, else `dispatchRow()`.

## Dispatch, idempotency, and the cost ceiling

`AnalysisService::request()` upserts the row (`firstOrCreate`) and only dispatches when `autoDispatchEnabled()` is true AND the row actually needs work. The guards (see [[idempotent-dispatch-cost-ceiling]]):

- **Idempotency at upsert** — a row is created `Queued` (or `Pending` when dispatch is off) only `wasRecentlyCreated`; an existing row is re-dispatched only when `rowNeedsDispatch()` (status `Pending` or `Failed`). A `Done` or `Queued` row is left alone, so a same-day re-run of the daily briefing dispatches only the still-missing types.
- **Idempotency at execution** — `AnalyzeRowJob::handle()` early-exits when the row is already `Done`; `AnalyzeGroupJob::handle()` filters out the Done rows. This makes a UI retry that races a developer's Horizon retry safe — the second run sees `Done` and stops, so the LLM is never double-billed.
- **`afterCommit()`** — `dispatchPending()` defers the enqueue until the surrounding DB transaction commits, so a job can't run before (or be orphaned by a rollback of) the row it targets.
- **Daily cost ceiling** — `autoDispatchEnabled()` consults `dailyCostCeilingExceeded()`. When `azure_openai.daily_cost_ceiling` is set and today's spend exceeds it, auto-dispatch is skipped (rows stay `Pending`) until midnight resets the daily cost. A null ceiling never gates. The `analytics` aggregate behind it is memoized per scope: `AnalysisService` is a `scoped` binding, so one HTTP request or one queue job reads the day's spend once however many rows it fans out over (an ingest asked ~6 times before). Only the cost read is memoized — the kill switch and the config breaker stay live, so a breaker reset still resumes generation mid-scope. Nothing bills inside that scope: dispatch only enqueues, and the gate that actually guards spend is `haltForPausedGeneration()` in the analyze job, which runs in a separate process with its own fresh read.
- **Per-block agent budget** — the ceiling above is read once, *before* the job is queued, so it cannot stop a tool-calling narration mid-run. `ai.agent.max_steps` / `ai.agent.max_tokens` in [config/ai.php](config/ai.php) bound one block instead; see [[narration-agents-on-openai-php]]. `max_steps` is only the **default**: it is sized for the widest toolbox (RunInsight's nine tools), and every extra turn re-bills the whole prompt prefix including the persona, so a narrator holding one or two tools tightens it through `ChatCallOptions::$maxSteps` ([ChatCallOptions](app/Services/AI/ChatCallOptions.php) → [AgentBudget::fromConfig](app/Services/AI/Agent/AgentBudget.php)). The floor for an override is `2 * (tools + 1)` — one pass is at most `tools + 1` turns since every tool is argument-free and worth calling once, and the content-filter retry replays that pass on the same budget, so anything tighter would have the retry answer with no readings at all.
- **Metering survives failure.** `StructuredChatCaller` writes the usage row from a `finally`, so a run that dies mid-loop still reports the turns it already burned. It used to record only after a successful decode, which meant a flaky row under-reported roughly `$tries` times its real spend — to the very ceiling above, which reads that table. A run with no completed turn writes nothing. Each row also carries `cached_tokens`, `reasoning_tokens` and `steps`: cached input bills at a discount when the deployment declares `cached_input_per_1m`, and reasoning bills as output, the pricier side on both deployments.

**Queue.** Every analyze job declares `$queue = 'ai'` on [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php) — not configurable, since there is one right answer. The `supervisor-ai` Horizon supervisor ([config/horizon.php](config/horizon.php)) serves it at a 300 s timeout against the 60 s the rest of the queue lives by, because a tool-calling run takes several Azure round trips. Production splits the container's 4 workers 2/2, so a narration backlog can never occupy every worker and stall Strava ingest behind it.

**Completion side effect (notifications).** `AnalysisService::markDone()` fans out an `AnalysisReadyNotification` for the notifiable types — a queued Laravel notification — gated on registered-type (`NotificationEligibility::isNotifiable()`) + not under `withoutDispatching` (so the demo seed never notifies); it resolves the owner and calls `$user->notify(...->afterCommit())`. The notification's `via()` owns the rest of the gating (demo / configured bot token / non-revoked connection / recency / master-switch opt-in), and the `TelegramChannel` holds the `notification_deliveries` unique `(analysis_id, channel)` claim, so a Horizon retry of an AI job (which re-runs `markDone`) never double-messages. A second channel (web push) fans out from the same `via()`. See [[telegram-notifications]].

## Chained narration

Some kinds are "connected" — each link reads the previous same-kind narrative and may only be narrated after its chronological predecessor is `Done`. `AnalysisType::isChained()` lists them (weekly recap, monthly recap, and the per-activity group). See [[chained-narration]].

Propagation is a hook fired after a row/group completes: `AnalyzeRowJob::afterDone()` (e.g. [AnalyzeWeeklyRecapJob](app/Jobs/AI/AnalyzeWeeklyRecapJob.php) dispatches the next Pending week) and the group-level `AnalyzeGroupJob::afterGroupDone()` (e.g. [AnalyzeActivityJob](app/Jobs/AI/AnalyzeActivityJob.php) dispatches the next chronological activity group). Both:

- dispatch the successor with `invalidate: false`, so under a tripped cost ceiling or AI-off env the dispatch is a clean no-op and the chain **pauses** (rows stay Pending) rather than injecting filler;
- are **best-effort** — any error is logged and swallowed so a chain-advance failure never flips an already-billed Done row back to Failed.

A stalled block is re-kicked hourly by the `ai:self-heal` command ([SelfHealCommand](app/Console/Commands/AI/SelfHealCommand.php)) in [routes/console.php](routes/console.php): it re-dispatches the earliest stalled block per user (the chains, plus the standalone card-flavor and PR-context narration) with `invalidate:false` so it never re-bills, early-exits while generation is paused, and bounds Failed retries by `Analysis::MAX_SELF_HEAL_ATTEMPTS` before dead-lettering (see below).

*Which* link either path targets is resolved in one place, [ChainResolver](app/Services/AI/ChainResolver.php) — but by two deliberately different predicates, and they are not interchangeable. A user click resumes the earliest **unfilled** link (no Done recap, whatever its attempt count); the hourly sweep only re-kicks the earliest **stalled** one (Pending or Failed *under* the retry budget, demo users excluded). That gap is the dead-letter: a block that burned its budget is re-armable by hand and never again by the automatic net.

## Deferred recaps (windowing)

The still-open current week/month never narrates on demand — its recap row is staged `Pending` (via `AnalysisService::requestDeferred()`) and filled only by the scheduled command once the period closes (`ai:weekly-recap` Monday 00:01, `ai:monthly-recap` on the 1st), in [routes/console.php](routes/console.php). `AnalysisController::trigger()` guards this with `isStillOpenRecapPeriod()`, returning the inert row unchanged. A Pending recap for the open window is therefore expected, not a backlog. See [[deferred-recap-windowing]].

## Manual (never auto) retry

Failed blocks are never auto-retried — that keeps LLM cost predictable. See [[per-block-manual-retry]].

- **Failure model** — [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php) sets `$tries = 3` with backoff. `settleFailure()` re-queues + releases a `TransientUpstreamException` (429/5xx/timeout, honoring `Retry-After` capped at 600s) while a try remains; a terminal `UnavailableException` (bad schema / malformed JSON) is swallowed so the worker stops; anything else is rethrown into `failed_jobs`. The `failed()` hook marks a row stuck in `Processing` (worker died) back to `Failed` so it becomes re-dispatchable.
- **One budget, not two** — `$tries` and `Analysis::MAX_SELF_HEAL_ATTEMPTS` are not independent ceilings that multiply. `attempts` bumps once per real run (`markProcessing`), so both draw from it: `settleFailure()` stops releasing once the row's budget is spent, and `haltForSpentRetryBudget()` ([AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php)) refuses a queue-driven re-entry — a rethrow being retried, or a run whose worker died mid-flight — that the budget can no longer pay for, settling the row `Failed` so it dead-letters rather than resting where no sweep can see it. Total billed LLM runs per block is therefore exactly `MAX_SELF_HEAL_ATTEMPTS`. Every *dispatch* marks its row `Queued` first, which is what separates a human "Coba lagi" (still runs) from the queue re-entering a row it already failed. A **paused** row never reaches `markProcessing`, so a pause of any length costs nothing and can never dead-letter a block.
- **Retry path** — a failed block shows a "Coba lagi" empty state; the user re-dispatches via `POST` to `AnalysisController::trigger()`. For chained kinds, a click does **not** narrate the clicked row in isolation — [ChainResolver](app/Services/AI/ChainResolver.php)`::earliestUnfilledLink()` resumes the earliest unfilled link forward (`invalidate: false`, no re-bill of Done siblings), and only a genuine chain **head** regenerate (`isHeadRegenerate()`) re-narrates that exact row with `invalidate: true`. A `cooldownRemaining()` (a 15-minute Redis-backed [Cooldown](app/Support/Cooldown.php) opened at `markDone`) suppresses rapid re-triggers. Developers can also retry from Horizon's failed-jobs tab.
- **Paused trigger** — while `generationPaused()` (the same signal behind the `aiPaused` shared prop), `trigger()` refuses with **409** carrying the current row payload, ahead of the chain-resume and zone-recompute branches: no row is staged, nothing is invalidated, no cooldown moves. The client hides the affordance already, so the 409 only catches a hand-crafted POST or a pause that flipped after page load; [useAnalysisTrigger](resources/js/hooks/useAnalysisTrigger.ts) treats it as benign (restores the server status, no error state) and reloads so `aiPaused` catches up. The **demo** branch sits ahead of the guard: a demo "Baca ulang" is a rule-based fill, not an LLM dispatch, so it keeps working while paused.

## Rule-based fallback (Azure unconfigured)

LLM calls go through an Azure OpenAI client configured by [config/azure_openai.php](config/azure_openai.php) (host + key + per-narrator deployment overrides; routing detail in [[azure-openai-routing]]). When `azure_openai.uri` or `azure_openai.api_key` is empty, `autoDispatchEnabled()` returns false and **no job is dispatched** — rows stay `Pending` (dev/demo without credentials).

Two distinct things still produce content without the LLM:

1. **Every type is narrated.** The run-insight blocks were the last types filled inline from arithmetic; they go through the model now, so there is no longer a no-queue, no-token path in `dispatchRow()`. **No block is ever templated on a real account**: when generation is paused (cost ceiling / AI off / Azure unset) a single-row LLM block stays honestly `Pending` (an existing Done keeps its real prose) and `ai:self-heal` fills it once generation resumes. A block that reaches the LLM and keeps failing is bounded by `Analysis::MAX_SELF_HEAL_ATTEMPTS`, then **dead-lettered** — surfaced per-user on `/ai-usage` ([TokenUsageController](app/Http/Controllers/TokenUsageController.php)) with a manual "Coba lagi" that re-arms the budget.
2. **Demo seed** — the demo seeder backfills every Analysis row through [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) under `AnalysisService::withoutDispatching()`, so seeding spends no tokens (the filler is demo-only). The "Baca ulang" button stays live for the demo, but its trigger is filled rule-based instead of dispatching, so it spends no tokens either — see [[demo-triggers-served-rule-based]].

## See also

- [[run-ingest-pipeline]] — what triggers the post-ingest cascade that calls `request()`.
- [[analytics-db]] — `ai_token_usages` metering that backs the daily cost ceiling.
- [[data-model]] — the `ai_analyses` table.
