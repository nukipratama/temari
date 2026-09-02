---
title: LLM triggers — what makes this app call a model, and when
description: Every path that reaches the LLM, organised by what starts it, what key it writes, what makes it re-bill, and what stops it — plus a runnable per-narrator spend query and the one thing that query cannot tell you.
tags: [architecture, ai]
status: living
reviewed: 2026-09-02
code_refs:
  - routes/console.php
  - app/Services/AI/StructuredChatCaller.php
  - app/Services/AI/AnalysisService.php
  - app/Services/AI/AnalysisType.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Http/Controllers/Api/RunQuestionController.php
  - app/Services/AI/SelfHealer.php
  - app/Services/AI/PlanNarrationRequester.php
  - app/Services/AI/BackfillAgeGate.php
  - app/Models/AI/TokenUsage.php
---

# LLM triggers — what makes this app call a model, and when

[[ai-pipeline]] explains the machinery a narrated block flows through. This note answers the
question that machinery does not: **who starts it.** Read it before changing anything that
dispatches narration, and before trying to explain a spend spike.

Every call funnels through one chokepoint — [`StructuredChatCaller`](../../app/Services/AI/StructuredChatCaller.php#L29).
If a code path does not reach it, it does not cost money.

> **`cadence()` on `AnalysisType` looks like this map and is not.** `OnDemand` covers both a
> scheduled command and a user button, and `PerActivity` fires from an ingest cascade the enum never
> mentions. Use the origins below.

## The four origins

### 1. Scheduled

Derived from [routes/console.php](../../routes/console.php), which is the only authority — a
scheduled command missing from this table is a bug in this table.

| when | command | what it dispatches |
|---|---|---|
| daily 00:01 | [`ai:daily-briefing`](../../routes/console.php#L36) | one `BriefingMascotVoice` per active non-demo user |
| Mon 00:01 | [`ai:weekly-recap`](../../routes/console.php#L47) | `WeeklyRecap`, oldest unfinished link first |
| Mon 00:05 | [`ai:weekly-profile`](../../routes/console.php#L53) | `ProfileVoice`, keyed by ISO week |
| **Mon 00:07** | [**`plan:regenerate`**](../../routes/console.php#L67) | **up to 9 rows per user — see below** |
| 1st 05:45 | [`ai:monthly-recap`](../../routes/console.php#L75) | `MonthlyRecap`, oldest first |
| daily 06:00 | [`ai:trend-read 30d`](../../routes/console.php#L82) | `TrendRead`, discriminator `30d` |
| every 3rd day 06:00 | [`ai:trend-read 90d`](../../routes/console.php#L83) | discriminator `90d` |
| Mon 06:00 | [`ai:trend-read 12mo`](../../routes/console.php#L84) | discriminator `12mo` |
| hourly | [`ai:self-heal`](../../routes/console.php#L92) | recovery only — see origin 4 |

**`plan:regenerate` is the one to know about.** The periodizer it runs is deterministic and free,
but the command then calls
[`requestForCurrentWeek()`](../../app/Services/AI/PlanNarrationRequester.php#L73) for every non-demo
user, dispatching **`PlanDayVoice` ×7 and `PlanWeekVoice` with `invalidate: true`** — re-billed every
Monday on purpose, because the periodizer has just rewritten what they describe — plus an idempotent
`PlanSeasonVoice`. That is up to nine rows per user per week, and it is the largest scheduled spend
in the app after the daily briefing.

Everything else on the schedule (`strava:sync`, `geo:backfill-locations`, `weather:*`,
`trend:snapshot-daily`, `plan:score-compliance`, `streak:*`, `demo:daily-refresh`) reaches no model.
`demo:daily-refresh` is worth naming because it looks like it should: it runs under
`AnalysisService::withoutDispatching()`, so the demo account's content is filled deterministically
and spends nothing. See [[demo-user-billing-exclusion]].

### 2. Ingest cascade

[`DispatchPostRunAnalysis::handle()`](../../app/Listeners/DispatchPostRunAnalysis.php#L40) is queued
on `ActivityIngested` and is where most per-run spend originates. In order: `PrContext` (one per
beaten personal record), `CardFlavor`, then the grouped `PostRunSpeech` + `RunInsight` pair, then
`BriefingMascotVoice` (invalidated only when the run is today's), then `ProfileVoice` keyed by the
current ISO week with `invalidate: false` so it never re-bills. `WeeklyRecap` and `MonthlyRecap` rows
are **staged `Pending` and not narrated here** — the scheduled commands above narrate them once the
window closes, which is why a pending recap row is not a backlog. See [[deferred-recap-windowing]].

### 3. User-initiated

- [`AnalysisController::trigger()`](../../app/Http/Controllers/Api/AnalysisController.php#L24) — the
  per-block "Reread". Gated in a fixed order: ownership, an open recap window, cooldown, demo,
  backfill age, paused generation, then chain resumption. Each gate is described under
  *What stops a call*.
- [`RunQuestionController::store()`](../../app/Http/Controllers/Api/RunQuestionController.php#L51) —
  the scoped run Q&A. **The one AI surface that is not an `Analysis` row**: one run holds many
  questions, which `(subject, type, discriminator)` cannot key, so it persists `RunQuestion` rows
  instead. It still goes through `StructuredChatCaller`, so persona, budget, retries and metering are
  unchanged. See [[scoped-run-qa-not-an-analysis-row]].
- **`PlanController::regenerate`** — the Plan page's own regenerate button runs the *same*
  `requestForCurrentWeek()` as the Monday command, so a user can trigger a full week of plan
  narration by hand. It is limited by its own 3600s cooldown inside `PlanNarrationRequester`, not by
  the per-block cooldown every other trigger uses.

### 4. Recovery

[`SelfHealer::run()`](../../app/Services/AI/SelfHealer.php#L60), hourly: reverts rows stuck in
flight, then resumes the earliest stalled link per user per family. **Every dispatch is
`invalidate: false`**, so recovery never re-bills content that already exists, and demo users are
excluded from every sweep. Failed rows are bounded by
[`MAX_SELF_HEAL_ATTEMPTS`](../../app/Models/AI/Analysis.php#L62) and then dead-letter to `/ai-usage`
for a manual re-arm. See [[bounded-self-heal-and-dead-letter]].

## The twelve narrated types

Every case in [`AnalysisType`](../../app/Services/AI/AnalysisType.php) is dispatched by at least one
path above — **none is orphaned**, which is worth stating because `W2` found one that was, still
billing every morning for a panel deleted three waves earlier.

| type | subject | discriminator | origin |
|---|---|---|---|
| `briefing_mascot_voice` | synthetic user+day | required `Y-m-d` | scheduled + ingest |
| `post_run_speech` | `Activity` | none | ingest (grouped) |
| `run_insight` | `Activity` | none | ingest (grouped) |
| `pr_context` | `PersonalRecord` | none | ingest |
| `card_flavor` | `RunCard` | none | ingest |
| `weekly_recap` | `WeeklySnapshot` | none | staged at ingest, narrated Mon |
| `monthly_recap` | synthetic user+month | required `Y-m` | staged at ingest, narrated 1st |
| `profile_voice` | synthetic user | required ISO week | scheduled + ingest |
| `trend_read` | synthetic user+range | required range | scheduled ×3 |
| `plan_day_voice` | synthetic user+day | required `Y-m-d` | `plan:regenerate`, Plan page |
| `plan_week_voice` | `PlanAdaptation` | none | `plan:regenerate`, Plan page |
| `plan_season_voice` | `Season` | none | `plan:regenerate`, Plan page |

## What stops a call

Seven mechanisms, and they do **not** behave alike. The first three are interchangeable; the fourth
is the one people misremember.

| stop | row ends up | costs | self-heal resumes it |
|---|---|---|---|
| `AiEnabled` off | `Pending` | no | yes |
| Azure unconfigured | `Pending` (dispatch skipped) | no | yes |
| config circuit breaker | `Pending` | no | yes |
| **daily cost ceiling** | **`Done`, rule-based** | no | **no — clears on the clock** |

All three pauses resolve through
[`blockingReason()`](../../app/Services/AI/AnalysisService.php#L632), and an in-flight job reverts
its rows via [`haltForPausedGeneration()`](../../app/Jobs/AI/AnalyzeBaseJob.php#L172) without burning
an attempt.

**The cost ceiling is the exception in three ways.** It does not pause: a `pending` row is filled
from the rule-based filler and marked `Done` by
[`degradeToRuleBased()`](../../app/Services/AI/AnalysisService.php#L686), so a capped day is not a
day of empty blocks. A `Failed` row is explicitly excluded and stays failed, keeping its dead-letter
visibility. And a *manual* trigger past the ceiling is refused with a 409 rather than degraded,
because [`generationPaused()`](../../app/Services/AI/AnalysisService.php#L597) asks with the budget
included while auto-dispatch asks without it. See [[cost-ceiling-degrades-to-rule-based]] and
[[cost-ceiling-answers-run-questions-rule-based]].

Three more limits:

- **Demo exclusion.** [`notDemo()`](../../app/Models/User.php#L85) filters the five AI kickoff
  commands and every `SelfHealer` sweep, and
  [`shouldServeRuleBased()`](../../app/Services/AI/AnalysisService.php#L548) serves a demo user's
  manual trigger from the filler *before* any pause check — so the public demo spends nothing while
  still feeling live. See [[demo-triggers-served-rule-based]].
- **The backfill age gate**, [84 days](../../config/ai.php#L43). The only limit that gates automatic
  dispatch *and* manual triggers: [`isTooOld()`](../../app/Services/AI/BackfillAgeGate.php#L37) for
  the ingest fan-out, [`blocksManualTrigger()`](../../app/Services/AI/BackfillAgeGate.php#L52) for
  the button. It is exhaustive per type — chained and recap types are exempt, because they resume a
  chain rather than narrate old material. See [[twelve-week-narration-cutoff]].
- **Cooldown and idempotency are two different defences for the same goal.** The
  [900s cooldown](../../app/Support/Cooldown.php#L32) stops a human clicking twice, at the
  controller, before a job exists. The `Done` check at the top of
  [`AnalyzeRowJob::handle()`](../../app/Jobs/AI/AnalyzeRowJob.php#L23) stops a UI trigger and a
  Horizon retry racing into a double bill. Plan narration adds a third, separate 3600s cooldown.

## What it costs

Spend is metered per call into `ai_token_usages` on the separate `analytics` connection, written by
[`RecordTokenUsageAction`](../../app/Actions/AI/RecordTokenUsageAction.php#L20) into
[`TokenUsage`](../../app/Models/AI/TokenUsage.php#L30). A write failure is swallowed and logged, so
metering never fails a call that already succeeded.

**This note deliberately carries no cost table.** `/ai-usage` renders spend live with kind and range
filters, and a frozen table here would duplicate a working page and then rot. Run the query instead —
the `(created_at, kind)` index makes it cheap:

```sql
SELECT kind,
       COUNT(*)               AS calls,
       SUM(total_tokens)      AS tokens,
       SUM(prompt_tokens)     AS prompt_tokens,
       SUM(completion_tokens) AS completion_tokens,
       SUM(cached_tokens)     AS cached_tokens,
       ROUND(AVG(latency_ms)) AS avg_ms
FROM ai_token_usages
WHERE created_at >= NOW() - INTERVAL 30 DAY
GROUP BY kind
ORDER BY tokens DESC;
```

```bash
./vendor/bin/sail artisan tinker --execute 'print_r(DB::connection("analytics")->select("<query>"));'
```

**`kind` is the narrator, not the origin.** A `run_insight` row cannot say whether it came from the
ingest cascade, a user's "Reread", or the hourly self-heal, so a single number is never one trigger's
cost when that narrator has several origins. Making origin queryable would take an origin column on
`ai_token_usages` plus a write-path change; that was offered and declined while the port was
finishing, and this paragraph is where that work would start.

## See also

- [[ai-pipeline]] — the machinery each dispatch flows through.
- [[ai-narration-internals]] — prompt construction and the rule-based filler.
- [[azure-openai-routing]] — which model each kind routes to.
- [[bounded-self-heal-and-dead-letter]] · [[cost-ceiling-degrades-to-rule-based]] ·
  [[twelve-week-narration-cutoff]] · [[demo-user-billing-exclusion]].
