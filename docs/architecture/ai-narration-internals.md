---
title: AI narration internals — context builders & the demo filler
description: How prompt signals are assembled (context builders) and how copy is produced without the LLM (demo seed + unconfigured env).
tags: [architecture, ai]
status: living
reviewed: 2026-10-09
code_refs:
  - app/Services/AI/Context/ActivityNarrationContext.php
  - app/Services/AI/Agent/AgentToolbox.php
  - app/Services/AI/Agent/Tools/ActivityTool.php
  - app/Services/AI/Narrators/RunInsightNarrator.php
  - app/Console/Commands/AI/NarrationEvalCommand.php
  - app/Console/Commands/AI/NarrationEvalChecks.php
  - app/Console/Commands/AI/NarrationEvalFixtures.php
  - app/Services/Run/Story/BriefingContext.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
  - app/Services/AI/RuleBased/RuleBasedRunInsights.php
  - app/Services/AI/AnalysisType.php
  - app/Services/AI/AnalysisService.php
  - database/seeders/Demo/DemoRunSeeder.php
  - app/Services/AI/NarrationEligibility.php
  - app/Jobs/AI/NarrateOnReturnJob.php
  - app/Models/AI/Analysis.php
  - app/Services/AI/AnalysisOrigin.php
  - resources/js/components/temari/AnalysisStatus.tsx
---

# AI narration internals — context builders & the demo filler

Two internals sit *under* the [[ai-pipeline]]: how a narrator's LLM prompt gets its **signals**, and how a row gets **content when there's no LLM call**. The pipeline note covers the row lifecycle, dispatch, idempotency, and retry; this note complements it and does not repeat it.

## Context builders — shared prompt signals

A narrator's prompt is two halves: a static system prompt plus a per-subject **context** object that the LLM reads to make the copy specific. The context-assembly logic is pulled out of the narrators into small readonly value objects so that (a) signals derived from the same raw data are computed in exactly one place, and (b) the per-narrator context array a narrator hands to the LLM stays byte-stable — the field extraction can't drift between narrators that share it, which keeps prompts deterministic and cacheable.

### ActivityNarrationContext (per-run signals)

[ActivityNarrationContext](app/Services/AI/Context/ActivityNarrationContext.php) is built once per narration call from an `ActivityDetail` ([`fromDetail`](app/Services/AI/Context/ActivityNarrationContext.php)) and collects the run-level signals more than one narrator needs: distance, decoupling, negative-split flag, time-in-zone percentages, and the weather (temp / rain). It also exposes the km conversion ([`distanceKm`](app/Services/AI/Context/ActivityNarrationContext.php)) so every consumer rounds distance the same way. The exact field list is the constructor — read it there, don't trust this prose.

It is shared by the run-insight, post-run-speech, and card-flavor narrators. Each narrator still adds its *own* keys (mood, PR flags, cadence, rarity, …) on top; the shared object only owns the cross-narrator signals so those stay identical across prompts. See [[vibe-and-mood]] for the per-narrator mood layer and [[cards-collection]] for card flavor.

### Agent tools — signals the model fetches instead of receiving

The per-activity narrators no longer take a pre-computed context. Every number reaches the model through a tool it chose to call — see each narrator's `toolbox()` ([RunInsightNarrator](app/Services/AI/Narrators/RunInsightNarrator.php), [PostRunSpeechNarrator](app/Services/AI/Narrators/PostRunSpeechNarrator.php), [CardFlavorNarrator](app/Services/AI/Narrators/CardFlavorNarrator.php)). The tools are thin readers over the same sources the context object used — several wrap `ActivityNarrationContext` — so the signals are the same ones; what changed is that a run with no heart rate or no elevation no longer pays prompt tokens for the nulls.

Each tool is bound to its subject at construction and declares an argument-free schema ([ActivityTool](app/Services/AI/Agent/Tools/ActivityTool.php)), which is how cross-user reads are prevented: there is no id to pass. The loop, its ceilings, and why the model gets an error payload rather than a failed block are in [[narration-agents-on-openai-php]].

**What still travels in the context** is whatever no tool could serve: a value the *call itself* carries rather than the database (post-run speech's `mood`), plus the continuity line, which stays in the prompt because the content-filter retry has to be able to strip it. A read the model would always call first, with no argument to choose, also travels in the context: the card's identity for the card flavor, and the season for the season voice. The tool class still builds that payload; the narrator calls its `handle()` instead of offering it.

A toolbox is built per call, so it can be shorter when the subject is thinner — a card whose activity has no detail row is offered no tools at all and writes from the identity in its context, rather than from tools that would answer null to everything. The same applies by ingest state: [RunQuestionNarrator](app/Services/AI/Narrators/RunQuestionNarrator.php) drops the splits, laps, zone and terrain reads on a `summary`-state run and keeps the run summary plus the history reads, since the stream pipeline has not run yet. See [[run-qa]].

Any tool that hands the model a raw duration or pace in seconds also carries a `_formatted` twin built with the same helper the UI calls, so a quoted figure can never drift from what the page beside it shows — the pattern [ProgressionSignalTool](app/Services/AI/Agent/Tools/ProgressionSignalTool.php) established for `delta_sec`/`delta_formatted`. `DurationFormatter::hms()` ([DurationFormatter.php](app/Services/Run/Metrics/DurationFormatter.php)) covers durations (`PersonalRecordsTool.php`, `RunSummaryTool.php`, `LapsTool.php`) and `PaceFormatter::format()` ([PaceFormatter.php](app/Services/Run/Metrics/PaceFormatter.php), the same call [pace.ts](resources/js/lib/pace.ts) mirrors) covers seconds-per-km (`TrainingPacesTool.php`, `RunSummaryTool.php`, `WeekTotalsTool.php`, `PlanContextTool.php`). Each tool's description names the formatted field as the only one to quote and keeps the raw seconds for judging size and direction.

### The briefing family

The four narrators that speak about a *day* rather than a run take the same shape. Their reads are bound to a user as of a date ([UserTool](app/Services/AI/Agent/Tools/UserTool.php)) rather than to an activity, and the per-activity narrators use the same classes for training load and the 28-day baseline: "the runner's load on the day of this run" is the same question as "the runner's load today", asked from a different day.

[WeekStateTool](app/Services/AI/Agent/Tools/WeekStateTool.php) is deliberately **one** tool returning all fifteen `BriefingContext` fields rather than several themed ones. Those fields are produced together by a single query pass, so splitting them would buy nothing but round trips.

The daily greeting keeps its `vibe` in the context, because the *caller* decides which vibe the greeting is for — a tool that recomputed it would be a second source of truth that could disagree. It gains `get_week_state`, so a "you haven't run in a while" greeting can finally tell three days from three weeks.

### The recaps

Weekly and monthly narration held its arithmetic *in the narrator*: month bounds, per-week distance buckets, the mood mix and the fitness arc. That computation moved wholesale into [MonthTotalsTool](app/Services/AI/Agent/Tools/MonthTotalsTool.php) and [WeekTotalsTool](app/Services/AI/Agent/Tools/WeekTotalsTool.php), which is why these narrators lost more lines than they gained.

The period is fixed at construction — a `WeeklySnapshot`, or a `Y-m` string — so a recap can only ever count the period it was asked about. Weekly and monthly send just the continuity line; the trend caption sends **nothing at all**, since the whole caption is a read.

### The profile narrators

Profile voice completes the set, and sends an **empty context** — unlike the recaps there was not even a continuity line to keep, since it is not chained. Their arithmetic moved with them: lifetime stats and the favourite-time bucket, the persona mood mix with its recent-vs-earlier split, and the progression signal were private methods on the narrators and are tools now. The persona read was a separately billed narrator of its own until it was merged into the profile voice, which now carries [PersonaMixTool](app/Services/AI/Agent/Tools/PersonaMixTool.php) beside its own three.

A narrator can also carry a contract the JSON schema cannot express. `StructuredChatCaller` takes an optional `validator` on [ChatCallOptions](app/Services/AI/ChatCallOptions.php): it runs on the decoded answer, and a complaint replays the conversation once with the answer and the complaint appended and tools forbidden, then throws if the rewrite fails too ([StructuredChatCaller](app/Services/AI/StructuredChatCaller.php)). The profile voice is the first user — its two evidence slots only bind the prose because a figure check enforces them.

Every narrator now reads rather than receives. What remains in any context is only ever one of two things: a value the *call* carries (post-run speech's `mood`, the daily greeting's `vibe`), or the continuity line.

**The post-run speech is the one narrator deliberately kept short of data.** It used to receive the three insight blocks as prose to synthesize. All four render side by side in the [[run-detail]] lens grid, so being handed the other three made it a fourth telling of the same run — and saying "don't repeat" did not hold, in its own prompt or by removing its splits and zone tools. It now owns a lens the others structurally cannot: the day around the run, and where the run sits against the athlete's own history. Mechanics belong to the other three.

The neighbour it collides with is not on that page at all. `get_week_state` serves both this narrator and the daily briefing, and the briefing *opens* on the week-over-week pair, so a runner who read home and then opened today's run met the same two figures twice in one session. Both keep the tool — the week genuinely does explain some runs — but the post-run prompt now ranks the run-scoped reads first and demotes the pair to a last resort that has to say what the week *changed* about this run ([PostRunSpeechNarrator](app/Services/AI/Narrators/PostRunSpeechNarrator.php)).

### BriefingContext (per-user-day signals)

[BriefingContext](app/Services/Run/Story/BriefingContext.php) is the dashboard briefing's personalisation layer, built per user as-of a moment ([`forUser`](app/Services/Run/Story/BriefingContext.php)) and serialised straight into the LLM user message ([`toArray`](app/Services/Run/Story/BriefingContext.php), with short keys to keep token cost down). It collects this-week / last-week run-count + km deltas, recovery hours, and form status, plus two computed heuristics:

- the **time-of-day bucket** (`early_morning` / `morning` / `midday` / `evening` / `night`) so a morning briefing reads differently from an evening one ([`bucketFor`](app/Services/Run/Story/BriefingContext.php));
- **consecutive weeks active** — a streak proxy reusing the `WeeklySnapshot` rows we already keep, since we don't track a day-level streak ([`countConsecutiveActiveWeeks`](app/Services/Run/Story/BriefingContext.php)).

The last-week half of that pair, and `volume_ramp_pct`, are never the full prior week: [`lastWeekToDate`](app/Services/Run/Story/BriefingContext.php) sums real activity through the same weekday `$asOf` falls on this week, queried straight off `ActivityDetail` rather than the `WeeklySnapshot` row, so a two-day-old week is compared against a two-day-old week rather than a full seven-day one. The figure is narration context only: [Readiness](app/Services/Run/Metrics/Readiness.php)'s volume guard compares actual km-to-date with the week's prescription instead ([[a-load-label-supports-a-concern-it-never-decides-one]]).

Recovery hours is "hours since the most recent activity start", sharper than days-since for a mid-day briefing — now computed by [RecoveryWindow::forUser](app/Services/Run/Story/RecoveryWindow.php) and passed in. `BriefingContext::forUser` is called from [WeekStateTool::handle](app/Services/AI/Agent/Tools/WeekStateTool.php), one of the agent tools [BriefingMascotVoiceNarrator](app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php) reads from; the rendered surface is the [[dashboard]] mascot-voice block.

### Narration eval — what the model writes back

The narrator tests fake the model, so they prove the payload and never the prose. `narration:eval` ([NarrationEvalCommand](app/Console/Commands/AI/NarrationEvalCommand.php)) is the manual check for the three kinds that have shipped inverted or leaked reads: `briefing_mascot_voice` (the post-run block), `run_insight` and `profile_voice`. **Run it before closing a PR that changes a narrator prompt, a validator or one of their tools**, from a checkout that has Azure credentials, and paste the table and spend into the PR. It is not in CI or the scheduler.

```
./vendor/bin/sail artisan narration:eval --max-calls=60 [--kind=run_insight ...]
```

- **Hard cap.** `--max-calls` is required, has no default and is refused above 60 ([cap()](app/Console/Commands/AI/NarrationEvalCommand.php)). The loop stops before the call that would pass it and lists the unrun fixtures. The cap counts narrator calls; each can make several model requests (tool turns, plus the one validator rewrite), which the spend line reports.
- **Never in production, never dispatching.** It refuses when `app()->isProduction()` ([handle()](app/Console/Commands/AI/NarrationEvalCommand.php)) and calls the narrators directly, so no job is pushed.
- **Real payloads, always rolled back.** It needs the seeded demo athlete (`demo:seed`). Each fixture builds its rows (planned day, run, graded intent) in a transaction that is rolled back on every path, including a thrown error ([evaluate()](app/Console/Commands/AI/NarrationEvalCommand.php)). Only the metering rows on the `analytics` connection stay, so the spend is in the ledger like any other call.
- **Fixtures.** [NarrationEvalFixtures](app/Console/Commands/AI/NarrationEvalFixtures.php) holds the post-run briefing on a `hit` and a `too_hard` day, three run insights (faster and slower than the athlete's own 28-day baseline, picked from the demo history, and a run stripped of heart rate) and the profile voice. A fixture the history cannot support is skipped and costs no call. Every fixture also carries the renamed load numbers: the load direction is read from the `get_training_load` evidence the model saw ([loadDirection()](app/Console/Commands/AI/NarrationEvalFixtures.php)).
- **Checks.** [NarrationEvalChecks](app/Console/Commands/AI/NarrationEvalChecks.php) runs on the written text: the narrator's own validators (a call that is rejected twice fails the `validators` column), `OutcomeLabels::complaint`, a raw-enum guard, a markdown guard, a numbers-in-evidence check (every figure must appear in the context or the tool outputs the model could read) and a direction check against the fixture's known answer. The direction check is a regex heuristic, so read a failing row's text before acting on it.

The command prints one row per fixture and a spend line (calls, requests, tokens, cost from the usage metering). Fix the narrator, never loosen a check to pass.

## The demo filler — copy without the LLM

**Every `AnalysisType` is narrated.** There is no longer a class of types that skips the model: the run-insight blocks were the last holdout, filled inline from threshold arithmetic even with Azure configured, and they now go through [RunInsightNarrator](app/Services/AI/Narrators/RunInsightNarrator.php) like the rest. A block that cannot be narrated stays honestly `Pending` or `Failed` rather than being quietly templated — see [[ai-pipeline]].

What remains is a **rule-based producer**: the demo's content, and a cheaper stand-in for real athletes wherever the LLM is not worth spending on (see *Beyond the demo*). It is never a fallback for a failure.

[RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) ([`fillFor`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php)) covers every `AnalysisType`, picking deterministically (seeded by subject id + discriminator) from Temari-voiced pools and weaving in the subject's real data where available. The run-insight types come from [RuleBasedRunInsights](app/Services/AI/RuleBased/RuleBasedRunInsights.php), which reads the run's own cadence, splits and zones so a seeded demo shows real numbers.

**`post_run_speech` is drawn from two slots, not one pool.** It is the only filler type the History feed renders once per run, so a visitor scrolls dozens of them side by side and a single pool of whole sentences repeats verbatim within one screen. The line is instead an opener carrying the distance ([`POST_RUN_OPENERS`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php)) plus an independent second beat ([`POST_RUN_CLOSERS`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php)), each selected under its own hash salt of the activity id ([`postRunSpeech`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php)), then the data-driven coda. 15 × 16 short lines give 240 pairings rather than 12 sentences, and no closer asserts anything about how the run went, so no pairing can contradict its opener or the coda. Selection stays a pure function of the activity id, so a given run reads the same forever.

That class is deliberately shallower than the narrator it stands in for: it answers only what a single `ActivityDetail` can, with no rolling pace average over the user's history and no VDOT-derived easy-pace nudge. It is a demo stand-in, not a second implementation to keep in sync.

No failure path falls back to the filler: a paused or failing block stays `Pending` / `Failed` instead. Besides the demo seed below and the real-athlete fills in *Beyond the demo*, it runs at the content-filter break in [AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php) / [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php), where a continuity-stripped retry that still trips Azure's output filter degrades to a benign line rather than dead-lettering. That benign line becomes the next `prev_narrative`, which is what breaks the poison loop.

### The demo seed path

The demo seeder stages and fills all Analysis rows under [`AnalysisService::withoutDispatching()`](app/Services/AI/AnalysisService.php), which suppresses every job dispatch ([DemoRunSeeder::seed](database/seeders/Demo/DemoRunSeeder.php)). Rows are staged `Pending` inside that closure and then flat-filled afterward by walking them through the filler ([`backfillWithFiller`](database/seeders/Demo/DemoRunSeeder.php)), so seeding spends zero LLM tokens. `trend_read` and `plan_season_voice` are filled the same way but bypass that walk, going straight through `AnalysisService::requestRuleBased()`/`PlanNarrationRequester::ensureDemoFilled()` instead (`F7`, since neither has a demo-reachable dispatch path otherwise). The "Reread" button stays live for the demo, but its trigger is filled through the same filler rather than dispatched, so no demo click reaches Azure — see [[demo-triggers-served-rule-based]]. The demo user is also held out of billing schedulers — see [[demo-user-billing-exclusion]].

### Beyond the demo

The same filler serves real athletes wherever the LLM is not worth it: a run past the backfill age cap or before the Strava connect ([NarrationEligibility](app/Services/AI/NarrationEligibility.php)), a day past the cost ceiling, and on an athlete's return from a gap away from the app, every per-run row and recap still `Pending` that is older than what [NarrateOnReturnJob](app/Jobs/AI/NarrateOnReturnJob.php) sends to the LLM. Every such fill skips a `Done` row. See [[narration-spends-only-on-active-athletes]].

`served_by` on [Analysis](app/Models/AI/Analysis.php) says which producer wrote a row's content, not why the rule-based filler was the one to answer. `rule_based_reason` on [Analysis](app/Models/AI/Analysis.php) records that, reusing [`AnalysisOrigin`](app/Services/AI/AnalysisOrigin.php) rather than a parallel enum: `Return` for the return job's own catch-up calls, `Capped` for the cost-ceiling degrade path ([`AnalysisService::degradeToRuleBased()`](app/Services/AI/AnalysisService.php)), and `ContentFilter` for the continuity-stripped-retry fallback in `AnalyzeRowJob`/`AnalyzeGroupJob` — each passed explicitly through [`AnalysisService::markDone()`](app/Services/AI/AnalysisService.php)'s `ruleBasedReason` parameter rather than read off the ambient `NarrationOrigin`, so a cost-ceiling degrade that happens to run mid-return-chain is never mistaken for an away-fill. `Demo` stays only on the demo rows that `ai:relabel-demo-narration` (removed 2026-10-08) restamped; nothing writes it any more. Backfill age cap and pre-connect fills, and every demo fill, still write null; [/devtools/narration](../features/narration-devtools.md)'s ledger folds a demo athlete's fills into `demo` regardless (derived from `is_demo`) and everything else null into `unattributed`. It clears to null the moment a row is re-served, by either producer. [`Analysis::toPayload()`](app/Models/AI/Analysis.php) surfaces it as `unread_while_away`, and [AnalysisStatus.tsx](resources/js/components/temari/AnalysisStatus.tsx) renders a quiet cue beside the "reread" control for exactly that case.

## See also

- [[ai-pipeline]] — the row lifecycle these internals plug into.
- [[narration-devtools]] / [[azure-openai-routing]] — token metering and per-narrator deployment routing.
- [[recaps]] / [[chained-narration]] — the weekly/monthly recap kinds the filler and chain advance both cover.
